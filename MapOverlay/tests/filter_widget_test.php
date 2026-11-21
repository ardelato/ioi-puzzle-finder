<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/filter_widget.php';

function CheckFilter(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function RunFilterCli(array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/filter_widget.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    CheckFilter(is_resource($process), 'Cannot start filter CLI.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$directory = __DIR__ . '/.filter-test-' . bin2hex(random_bytes(5));
mkdir($directory);
try {
    $input = $directory . '/base.json';
    $snapshot = $directory . '/markers.json';
    $output = $directory . '/widget.json';
    $manifest = $directory . '/manifest.json';
    copy(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json'), $input);
    file_put_contents($snapshot, json_encode((object)[
        'schemaVersion' => 2, 'markers' => new stdClass(), 'spawnSites' => new stdClass(),
        'counts' => (object)['static' => (object)['remaining' => 3], 'hub' => (object)['remaining' => 5, 'unlocated' => 2], 'spawn' => (object)['sites' => 4]],
    ], JSON_THROW_ON_ERROR));
    $inputBytes = file_get_contents($input);
    $snapshotBytes = file_get_contents($snapshot);
    $arguments = ['--base-widget', $input, '--markers', $snapshot, '--output', $output];
    foreach ([$input, $snapshot, $output, $directory . '/./widget.json'] as $collision) {
        file_put_contents($output, 'previous widget');
        [$code, $stdout, $stderr] = RunFilterCli([...$arguments, '--manifest', $collision]);
        CheckFilter($code !== 0, 'CLI accepted a manifest/input-or-output alias.');
        CheckFilter(file_get_contents($input) === $inputBytes && file_get_contents($snapshot) === $snapshotBytes, 'Rejected manifest alias changed an input.');
        CheckFilter(file_get_contents($output) === 'previous widget', 'Rejected manifest alias changed existing output.');
    }
    if (PHP_OS_FAMILY === 'Windows') {
        [$code] = RunFilterCli([...$arguments, '--manifest', strtoupper($snapshot)]);
        CheckFilter($code !== 0 && file_get_contents($snapshot) === $snapshotBytes, 'Windows case alias was not rejected.');
    }
    [$code] = RunFilterCli([...$arguments, '--output', $output]);
    CheckFilter($code !== 0 && file_get_contents($output) === 'previous widget', 'Repeated option was accepted or changed output.');
    [$code] = RunFilterCli([...$arguments, '--manifest', $directory . '/missing/manifest.json']);
    CheckFilter($code !== 0 && file_get_contents($output) === 'previous widget', 'Missing manifest parent changed existing output.');
    [$code] = RunFilterCli([...$arguments, '--manifest', $directory]);
    CheckFilter($code !== 0 && file_get_contents($output) === 'previous widget', 'A directory manifest target was accepted.');

    [$code, $stdout, $stderr] = RunFilterCli([...$arguments, '--manifest', $manifest]);
    CheckFilter($code === 0 && $stderr === '', "Valid filter CLI failed: $stdout $stderr");
    $generated = json_decode(file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
    $details = json_decode(file_get_contents($manifest), false, 512, JSON_THROW_ON_ERROR);
    CheckFilter(count($details->functionNames) === 48 && count($details->checkboxNames) === 30, 'Filter manifest lost functions or controls.');
    CheckFilter($details->liveProgressFunction === 'SSOverlayRefreshProgress' && $details->liveProgressBudget === 64 && $details->liveProgressIntervalSeconds === 0.25, 'Live map progress must use bounded selected-type checks.');
    CheckFilter($details->initializationFunction === 'OnInitialized' && in_array('OnInitialized', $details->functionNames, true), 'Fresh widgets must reconcile marker visibility with their displayed defaults.');
    CheckFilter($details->selectionMode === 'single-or-none' && $details->buttonNames === ['SSOverlayButtonNone', 'SSOverlayButtonZoomOut', 'SSOverlayButtonZoomIn', 'SSOverlayButtonRadarSize'] && !in_array('SSOverlayTypesAll', $details->functionNames, true), 'Selector must provide single-type selection, clearing and radar view controls.');
    $exports = array_column($generated->Exports, null, 'ObjectName');
    CheckFilter($details->pentadRelicTag === 'SaveStats.PentadRelic' && $details->pentadVisibilityFunction === 'SSOverlayAllowsPentadActor'
        && $details->pentadFocusField === 'SSRadarFocusPuzzleId' && $details->pentadFocusRefreshFunction === 'SSRadarRefreshPentadFocus', 'Map must use the shared native-derived Pentad focus and explicit relic identity.');
    $pentadPredicate = array_search($exports['SSOverlayAllowsPentadActor'], $generated->Exports, true) + 1;
    foreach (['SSOverlayRefresh', 'SSOverlayRefreshProgress'] as $name) {
        CheckFilter(str_contains(json_encode($exports[$name]->ScriptBytecode, JSON_THROW_ON_ERROR), '"StackNode":' . $pentadPredicate . ','), 'Initial and periodic visibility must both apply the active Pentad relic predicate.');
    }
    $availabilityPredicate = array_search($exports['SSOverlayIsActorAvailable'], $generated->Exports, true) + 1;
    CheckFilter($details->hubAvailabilityZoneTag === 'SaveStats.HubZone.' && $details->hubAvailabilityQuery === 'SSRadarIsPuzzleAvailable', 'Map must use positively classified Hub identity and the shared availability cache.');
    foreach (['SSOverlayRefresh', 'SSOverlayRefreshProgress'] as $name) {
        $bytecode = json_encode($exports[$name]->ScriptBytecode, JSON_THROW_ON_ERROR);
        CheckFilter(str_contains($bytecode, '"StackNode":' . $availabilityPredicate . ','), 'Initial and bounded map refreshes must both use the shared availability result.');
        CheckFilter(str_contains($bytecode, '"VirtualFunctionName":"SSRadarRefreshAvailability"'), 'Map availability refresh must use the shared cache before visibility reads.');
    }
    CheckFilter(!str_contains(json_encode($exports['Tick']->ScriptBytecode, JSON_THROW_ON_ERROR), '"VirtualFunctionName":"SSRadarRefreshAvailability"'), 'Native availability refresh must stay inside full refresh or quarter-second progress work.');
    foreach (['SSOverlayRefresh', 'SSOverlayRefreshProgress'] as $name) {
        CheckFilter(str_contains(json_encode($exports[$name]->ScriptBytecode, JSON_THROW_ON_ERROR), '"VirtualFunctionName":"SSRadarRefreshPentadFocus"'), 'Map visibility reads must refresh native Pentad focus before using shared state.');
    }
    CheckFilter(!str_contains(json_encode($exports['Tick']->ScriptBytecode, JSON_THROW_ON_ERROR), '"VirtualFunctionName":"SSRadarRefreshPentadFocus"'), 'Map Tick must keep native focus refresh inside the existing quarter-second progress callback, rather than run native queries each frame.');
    foreach (['SSOverlayCheck_source_static', 'SSOverlayCheck_source_hub', 'SSOverlayChanged_source_static', 'SSOverlayChanged_source_hub', 'SSOverlayUnknownLocations', 'SSOverlaySnapshotHint'] as $removed) {
        CheckFilter(!isset($exports[$removed]), 'Combined exact puzzles must not expose source switches or verbose footers.');
    }
    $defaults = array_column($exports['Default__WBP_LargeMap_C']->Data, 'Value', 'Name');
    foreach ($details->typeKeys as $type) {
        CheckFilter($defaults['SSOverlayType_' . $type] === 0, 'Type must start unselected.');
        CheckFilter(isset($exports['SSOverlayCheck_type_' . $type . '_Icon']), 'Every type row must have an icon.');
    }
    CheckFilter($defaults['SSOverlaySelectedTag'] === 'None' && $defaults['SSOverlayReset'] === 1, 'Fresh widget must clear old marker visibility.');
    CheckFilter($defaults['SSOverlayRadarEnabled'] === 1 && $details->sharedSelectionOwner === '/Game/SaveStats/WBP_SSOverlayRadar', 'Radar must default enabled and own the shared per-world selection.');
    CheckFilter(isset($exports['SSOverlayCheck_radar_enabled'], $exports['SSOverlayChanged_radar_enabled']), 'Map must expose the gameplay radar switch.');
    CheckFilter($exports['OnInitialized']->ScriptBytecode[0]->StackNode === $details->radarSyncFunctionIndex
        && $exports['SSOverlayRefresh']->ScriptBytecode[0]->StackNode === $details->radarSyncFunctionIndex, 'Map must synchronize canonical radar options before initialization and interaction.');
    CheckFilter($defaults['SSOverlayRadarZoomIndex'] === 1 && $defaults['SSOverlayRadarSizeIndex'] === 1
        && $details->radarDefaultRangeMetres === 100 && $details->radarDefaultSizePercent === 125, 'Radar view defaults must be100m and125%.');
    CheckFilter($defaults['bHasScriptImplementedTick'] === 1 && isset($exports['Tick']) && $exports['Tick']->LoadedProperties[0]->ElementSize === 56, 'Cached maps must use the native Tick signature and enable scripted Tick to synchronize gameplay hotkeys.');
    foreach (['SSOverlayRadarZoomIn', 'SSOverlayRadarZoomOut', 'SSOverlayRadarCycleSize'] as $handler) {
        foreach ($exports[$handler]->ScriptBytecode as $expression) { CheckFilter(($expression->StackNode ?? null) !== $details->refreshFunctionIndex, 'View-only buttons must not refresh all map marker actors.'); }
    }
    $imports = $generated->Imports;
    foreach ($imports as $i => $import) {
        CheckFilter(!in_array($import->ObjectName, ['SSRadarSetSelection', 'SSRadarSetView', 'SSRadarChangeZoom', 'SSRadarCycleSize'], true), 'Custom radar calls must use virtual dispatch instead of unresolved imported UFunction pointers.');
        if ($import->ObjectName === 'GetOwningPlayer') { CheckFilter($imports[-1 - $import->OuterIndex]->ObjectName === 'Widget', 'GetOwningPlayer import must name its declaring UWidget class.'); }
    }
    $checkContextArguments = static function (mixed $value) use (&$checkContextArguments, $imports): void {
        if (is_object($value)) {
            if (($value->{'$type'} ?? '') === 'UAssetAPI.Kismet.Bytecode.Expressions.EX_Context, UAssetAPI') {
                $call = $value->ContextExpression;
                $method = $call->VirtualFunctionName ?? (isset($call->StackNode) && $call->StackNode < 0 ? $imports[-1 - $call->StackNode]->ObjectName : null);
                if (in_array($method, ['SetIsChecked', 'SSRadarSetSelection', 'SSRadarSetView'], true)) {
                    foreach ($call->Parameters as $argument) {
                        CheckFilter(!str_contains($argument->{'$type'}, 'EX_InstanceVariable'), 'Object-context call must capture map instance arguments in locals before changing its receiver.');
                    }
                }
            }
            foreach ((array)$value as $child) { $checkContextArguments($child); }
        } elseif (is_array($value)) { foreach ($value as $child) { $checkContextArguments($child); } }
    };
    $checkContextArguments($exports['OnInitialized']->ScriptBytecode);
    $checkContextArguments($exports['SSOverlayRefresh']->ScriptBytecode);
    $checkContextArguments($exports['SSOverlayPublishRadar']->ScriptBytecode);
    $panelLayout = $exports['SSOverlayCanvasSlot']->Data[0]->Value;
    CheckFilter(array_column($panelLayout[0]->Value, 'Value', 'Name') === ['Left' => 24, 'Top' => -130, 'Right' => 280, 'Bottom' => 430], 'Filter panel must use the compact lower-left placement.');
    CheckFilter($panelLayout[1]->Value[0]->Value[0]->Value->X === 0 && $panelLayout[1]->Value[0]->Value[0]->Value->Y === 1, 'Filter panel must anchor to the lower-left edge.');
    $panelProperties = array_column($exports['SSOverlayPanel']->Data, 'Value', 'Name');
    $headerProperties = array_column($exports['SSOverlayShowFilters']->Data, 'Value', 'Name');
    CheckFilter($panelProperties['Visibility'] === 'ESlateVisibility::Collapsed' && $headerProperties['CheckedState'] === 'ECheckBoxState::Unchecked', 'Filter panel must begin collapsed with the header unchecked.');
    $checkWheel = static function (object $widget): void {
        $exports = array_column($widget->Exports, null, 'ObjectName');
        $properties = array_column($exports['SSOverlayScroll']->Data, null, 'Name');
        $wheel = $properties['ConsumeMouseWheel'] ?? null;
        CheckFilter($wheel !== null && $wheel->{'$type'} === 'UAssetAPI.PropertyTypes.Objects.EnumPropertyData, UAssetAPI'
            && $wheel->EnumType === 'EConsumeMouseWheel' && $wheel->Value === 'EConsumeMouseWheel::Always', 'Filter list must consume wheel events even at either scroll limit.');
    };
    $checkWheel($generated);
    if (in_array('--native-roundtrip', $argv, true)) {
        $assetTool = OverlayTestAssetGui();
        $nativeAsset = $directory . '/widget.uasset';
        $nativeJson = $directory . '/roundtrip.json';
        foreach ([['fromjson', $output, $nativeAsset], ['tojson', $nativeAsset, $nativeJson, 'VER_UE4_27']] as $toolArguments) {
            $pipes = [];
            $process = proc_open([$assetTool, ...$toolArguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            CheckFilter(is_resource($process), 'Cannot start native asset round-trip check.');
            fclose($pipes[0]);
            $toolOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            CheckFilter(proc_close($process) === 0, 'Native asset round-trip failed: ' . $toolOutput);
        }
        $checkWheel(json_decode(file_get_contents($nativeJson), false, 512, JSON_THROW_ON_ERROR));
        echo "Native ScrollBox wheel-consumption round-trip passed.\n";
    }
    CheckFilter(file_get_contents($input) === $inputBytes && file_get_contents($snapshot) === $snapshotBytes, 'Valid generation changed an input.');

    $base = json_decode($inputBytes, false, 512, JSON_THROW_ON_ERROR);
    $document = json_decode($snapshotBytes, false, 512, JSON_THROW_ON_ERROR);
    $originalFunctions = [];
    foreach ($base->Exports as $export) {
        if (str_contains($export->{'$type'}, 'FunctionExport')) { $originalFunctions[$export->ObjectName] = json_encode($export->ScriptBytecode); }
    }
    [$filtered] = AddOverlayFilters($base, $document);
    foreach ($filtered->Exports as $export) {
        if (isset($originalFunctions[$export->ObjectName])) {
            CheckFilter(json_encode($export->ScriptBytecode) === $originalFunctions[$export->ObjectName], 'Original map function bytecode changed.');
        }
    }
    CheckFilter(json_encode($base) === json_encode(json_decode($inputBytes)), 'Generator mutated the base object.');
    $beforeManifest = file_get_contents($manifest);
    $repeatOutput = $directory . '/repeat.json';
    file_put_contents($repeatOutput, 'preserve repeated application output');
    [$code] = RunFilterCli(['--base-widget', $output, '--markers', $snapshot, '--output', $repeatOutput, '--manifest', $manifest]);
    CheckFilter($code !== 0 && file_get_contents($repeatOutput) === 'preserve repeated application output' && file_get_contents($manifest) === $beforeManifest, 'Repeated application changed an existing output.');

    if (PHP_OS_FAMILY === 'Windows') {
        // An OS-level sharing lock forces the SECOND replacement to fail after
        // the widget has committed, proving rollback preserves both old files.
        $powershell = getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        $lockCode = '$handle = [System.IO.File]::Open(' . "'" . str_replace("'", "''", $manifest) . "'" . ', [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read); try { [Console]::WriteLine("READY"); [Console]::ReadLine() | Out-Null } finally { $handle.Dispose() }';
        $lockPipes = [];
        $lock = proc_open([$powershell, '-NoLogo', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-Command', $lockCode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $lockPipes);
        CheckFilter(is_resource($lock), 'Cannot start output-sharing-lock fixture.');
        try {
            stream_set_timeout($lockPipes[1], 10);
            CheckFilter(trim((string)fgets($lockPipes[1])) === 'READY', 'Output-sharing-lock fixture did not become ready.');
            $beforeOutput = file_get_contents($output);
            [$code, $stdout, $stderr] = RunFilterCli([...$arguments, '--manifest', $manifest]);
            CheckFilter($code !== 0 && str_contains($stderr, 'Cannot replace output'), 'Locked manifest did not exercise the commit failure.');
            CheckFilter(file_get_contents($output) === $beforeOutput && file_get_contents($manifest) === $beforeManifest, 'Commit failure did not restore previous outputs.');
        } finally {
            fwrite($lockPipes[0], "\n");
            fclose($lockPipes[0]);
            foreach ([1, 2] as $pipe) { stream_get_contents($lockPipes[$pipe]); fclose($lockPipes[$pipe]); }
            proc_close($lock);
        }
    }
    CheckFilter(glob($directory . '/.filters-*') === [], 'CLI left temporary output or backup files.');
    echo "Filter widget checks passed: aliases, repeated options, input preservation, valid JSON, duplicate application, original bytecode, and failed-commit rollback.\n";
} finally {
    foreach (array_merge(glob($directory . '/*'), glob($directory . '/.filters-*')) as $path) {
        for ($attempt = 0; is_file($path) && $attempt < 10; $attempt++) { if (@unlink($path)) { break; } usleep(100000); }
    }
    rmdir($directory);
}
