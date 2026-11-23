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
    CheckFilter(count($details->functionNames) === 57 && count($details->checkboxNames) === 30, 'Filter manifest lost functions or controls.');
    CheckFilter($details->liveProgressFunction === 'SSOverlayRefreshProgress' && $details->liveProgressBudget === 64 && $details->liveProgressIntervalSeconds === 0.25, 'Live map progress must use bounded selected-type checks.');
    CheckFilter($details->initializationFunction === 'OnInitialized' && in_array('OnInitialized', $details->functionNames, true), 'Fresh widgets must reconcile marker visibility with their displayed defaults.');
    CheckFilter($details->selectionMode === 'any-or-none' && $details->selectionMask === 'SSRadarSelectedMask' && $details->buttonNames === ['SSOverlayButtonNone', 'SSOverlayRadarRange0', 'SSOverlayRadarRange1', 'SSOverlayRadarRange2', 'SSOverlayRadarRange3', 'SSOverlayRadarSize0', 'SSOverlayRadarSize1', 'SSOverlayRadarSize2'] && !in_array('SSOverlayTypesAll', $details->functionNames, true), 'Selector must provide multi-type selection, clearing and radar view controls.');
    AssertNoContextCallArguments($generated, 'Map widget');
    $exports = array_column($generated->Exports, null, 'ObjectName');
    foreach ($details->typeBits as $type => $bit) {
        CheckFilter($bit === OverlayTypeBit($type), 'Map selection bits must match the shared catalog order.');
        $handler = json_encode($exports['SSOverlayChanged_type_' . $type]->ScriptBytecode, JSON_THROW_ON_ERROR);
        CheckFilter(!str_contains($handler, '"StackNode":' . (array_search($exports['SSOverlayClearTypes'], $generated->Exports, true) + 1) . ','), 'Selecting a type must not clear the other selected types.');
    }
    $zoneIndex = array_search($exports['SSOverlayRefreshZone'], $generated->Exports, true) + 1;
    foreach (['Tick', 'OnInitialized'] as $caller) {
        CheckFilter(str_contains(json_encode($exports[$caller]->ScriptBytecode, JSON_THROW_ON_ERROR), '"StackNode":' . $zoneIndex . ','), $caller . ' must follow the map selected zone.');
    }
    $zoneBytecode = json_encode($exports['SSOverlayRefreshZone']->ScriptBytecode, JSON_THROW_ON_ERROR);
    CheckFilter(str_contains($zoneBytecode, '"Path":["NameEnum"]') && str_contains($zoneBytecode, '"Path":["Map"]'), 'Zone must come from the native map selection.');
    CheckFilter($details->zoneTagPrefix === 'SaveStats.TypeZone.' && str_contains(json_encode($exports['SSOverlayRefresh']->ScriptBytecode, JSON_THROW_ON_ERROR), '"Value":"SaveStats.TypeZone.matchbox."'), 'Map pins must be queried per selected zone.');
    $countsIndex = array_search($exports['SSOverlayRefreshCounts'], $generated->Exports, true) + 1;
    CheckFilter(str_contains(json_encode($exports['Tick']->ScriptBytecode, JSON_THROW_ON_ERROR), '"StackNode":' . $countsIndex . ','), 'Map Tick must refresh per-zone counts.');
    $countsBytecode = json_encode($exports['SSOverlayRefreshCounts']->ScriptBytecode, JSON_THROW_ON_ERROR);
    CheckFilter(str_contains($countsBytecode, '"VirtualFunctionName":"SSRadarRequestCounts"') && str_contains($countsBytecode, '"Path":["SSRadarCountLeft"]') && str_contains($countsBytecode, '"Path":["SSRadarCountTotal"]'), 'Counts must come from the radar per-zone pass.');
    foreach ($details->typeKeys as $type) {
        CheckFilter(isset($exports['SSOverlayCount_' . $type]) && str_contains($countsBytecode, '"Path":["SSOverlayCount_' . $type . '"]'), 'Every type row must show its zone count.');
    }
    $publish = json_encode($exports['SSOverlayPublishRadar']->ScriptBytecode, JSON_THROW_ON_ERROR);
    CheckFilter(str_contains($publish, '"Value":' . OverlayTypeBit('matchbox') . '}') && str_contains($publish, 'RadarSelectedMask'), 'Radar must receive the combined type mask.');
    $refresh = json_encode($exports['SSOverlayRefresh']->ScriptBytecode, JSON_THROW_ON_ERROR);
    CheckFilter(substr_count($refresh, '"Value":"SaveStats.Type.') === count($details->typeKeys), 'Map refresh must query every selected type.');
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
    CheckFilter(!array_key_exists('SSOverlaySelectedTag', $defaults) && $defaults['SSOverlayReset'] === 1, 'Fresh widget must clear old marker visibility.');
    CheckFilter($defaults['SSOverlayRadarEnabled'] === 1 && $details->sharedSelectionOwner === '/Game/SaveStats/WBP_SSOverlayRadar', 'Radar must default enabled and own the shared per-world selection.');
    CheckFilter(isset($exports['SSOverlayCheck_radar_enabled'], $exports['SSOverlayChanged_radar_enabled']), 'Map must expose the gameplay radar switch.');
    CheckFilter($exports['OnInitialized']->ScriptBytecode[0]->StackNode === $details->radarSyncFunctionIndex
        && $exports['SSOverlayRefresh']->ScriptBytecode[0]->StackNode === $details->radarSyncFunctionIndex, 'Map must synchronize canonical radar options before initialization and interaction.');
    CheckFilter($defaults['SSOverlayRadarZoomIndex'] === 1 && $defaults['SSOverlayRadarSizeIndex'] === 1
        && $details->radarDefaultRangeMetres === 100 && $details->radarDefaultSizePercent === 125, 'Radar view defaults must be100m and125%.');
    CheckFilter($defaults['bHasScriptImplementedTick'] === 1 && isset($exports['Tick']) && $exports['Tick']->LoadedProperties[0]->ElementSize === 56, 'Cached maps must use the native Tick signature and enable scripted Tick to synchronize gameplay hotkeys.');
    foreach (['SSOverlaySetRadarRange0', 'SSOverlaySetRadarRange1', 'SSOverlaySetRadarRange2', 'SSOverlaySetRadarRange3', 'SSOverlaySetRadarSize0', 'SSOverlaySetRadarSize1', 'SSOverlaySetRadarSize2'] as $handler) {
        CheckFilter(str_contains(json_encode($exports[$handler]->ScriptBytecode, JSON_THROW_ON_ERROR), '"VirtualFunctionName":"SSRadarSetView"'), 'Radar choices must set the radar view directly.');
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
    $catalogNames = array_map(static fn(string $key): string => GetOverlayTypes()[$key]['name'], $details->typeKeys);
    $sortedNames = $catalogNames;
    usort($sortedNames, 'strcasecmp');
    CheckFilter($details->typeOrder === 'display name' && $catalogNames === $sortedNames, 'Puzzle rows must follow the on-screen names.');
    $legendIndex = array_search($exports['Legend_Panel'], $generated->Exports, true) + 1;
    $slotOf = static fn(object $widget): int => array_column($widget->Data, 'Value', 'Name')['Slot'];
    foreach (['SSOverlayPanel', 'SSOverlayRadarPanel', 'SSOverlayTabs'] as $hosted) {
        CheckFilter($generated->Exports[$slotOf($exports[$hosted]) - 1]->OuterIndex === $legendIndex, $hosted . ' must live inside the game legend box.');
    }
    CheckFilter($details->panelHost === 'Legend_Panel' && $details->tabNames === ['Legend', 'Puzzles', 'Radar'] && !isset($exports['SSOverlayShowFilters']) && !isset($exports['SSOverlayTogglePanel']), 'Map controls must be legend tabs, not a floating toggle.');
    $legendRootSlot = $generated->Exports[$slotOf($exports['Legend_Panel']) - 1];
    CheckFilter(array_column($legendRootSlot->Data, 'Value', 'Name')['ZOrder'] === 90 && $details->panelHostZOrder === 90, 'Legend tabs must sit above the quest tracker that overlaps them, below the fast-travel prompt.');
    $tickBytecode = json_encode($exports['Tick']->ScriptBytecode, JSON_THROW_ON_ERROR);
    foreach (['DisableInteraction', 'EnableInteraction', 'IsHovered'] as $method) {
        $import = array_search($method, array_column($generated->Imports, 'ObjectName'), true);
        CheckFilter($import !== false && str_contains($tickBytecode, '"StackNode":' . (-1 - $import) . ','), 'Hovering the legend box must pause the map cursor tracking underneath it.');
    }
    CheckFilter(str_contains($tickBytecode, '"Path":["WBP_SandboxExpanded"]'), 'The hover guard must not re-enable the map under the zone overview.');
    CheckFilter(str_contains($tickBytecode, '"VirtualFunctionName":"SSRadarCountStep"'), 'The map must drive the count pass: the radar timer stops while the world is paused.');
    $zoneRows = json_encode($exports['SSOverlayRefreshZone']->ScriptBytecode, JSON_THROW_ON_ERROR);
    CheckFilter(isset($exports['SSOverlaySearch']) && str_contains($zoneRows, '"Path":["SSOverlaySearch"]') && str_contains($zoneRows, '"Value":"Matchbox"'), 'Rows must follow the search box.');
    $lenImport = -1 - array_search('Len', array_column($generated->Imports, 'ObjectName'), true);
    CheckFilter(substr_count($zoneRows, '"StackNode":' . $lenImport . ',') === count($details->typeKeys), 'An empty search must list every row (UE4 Contains is false for an empty string).');
    CheckFilter(!str_contains(json_encode($exports['SSOverlayShowTabPuzzles']->ScriptBytecode, JSON_THROW_ON_ERROR), 'SetPosition'), 'Switching tabs must not resize the box.');
    CheckFilter(str_contains($zoneRows, '"Path":["DisplayIndex"]') && str_contains($zoneRows, '"Path":["SSOverlayZoneLabel"]'), 'The zone title must come from the selected zone entry.');
    CheckFilter(str_contains($zoneRows, '"Path":["SSOverlayView_hideDone"]') && isset($exports['SSOverlayCheck_view_hideDone']), 'Completed types must be hideable.');
    $subtract = -1 - array_search('Subtract_IntInt', array_column($generated->Imports, 'ObjectName'), true);
    CheckFilter(str_contains(json_encode($exports['SSOverlayRefreshCounts']->ScriptBytecode, JSON_THROW_ON_ERROR), '"StackNode":' . $subtract . ','), 'Counts must read solved / total like the game.');
    $panelRight = array_column(array_column($generated->Exports[$slotOf($exports['SSOverlayPanel']) - 1]->Data, 'Value', 'Name')['LayoutData'][0]->Value, 'Value', 'Name')['Right'];
    CheckFilter($panelRight > 0, 'Content must stay inside the legend panel so it keeps a gap from the screen edge.');
    foreach (['SSOverlayCheck_radar_enabled', 'SSOverlayCheck_source_spawn'] as $switch) {
        $switchStyle = array_column($exports[$switch]->Data, null, 'Name')['WidgetStyle'];
        CheckFilter($switchStyle->Value[0]->Value[1]->Value === array_search('SSToggle0', array_column($generated->Imports, 'ObjectName'), true) * -1 - 1, $switch . ' must look like a switch.');
    }
    $checkStyle = array_column($exports['SSOverlayCheck_type_matchbox']->Data, null, 'Name')['WidgetStyle'] ?? null;
    CheckFilter($checkStyle !== null && $checkStyle->StructType === 'CheckBoxStyle' && count($checkStyle->Value) === 6, 'Filter checkboxes must use the gold diamond style.');
    $legendSlots = array_column($exports['Legend_Panel']->Data, 'Value', 'Name')['Slots'];
    CheckFilter($generated->Exports[$legendSlots[0]->Value - 1]->ObjectName === 'SSOverlayBackdropSlot', 'The solid backdrop must draw beneath the game legend rows.');
    CheckFilter(str_contains(json_encode($exports['SSOverlayRefreshCounts']->ScriptBytecode, JSON_THROW_ON_ERROR), '"Path":["SSOverlayZoneHeader"]'), 'The Puzzles tab must show the detected zone.');
    $classProperties = array_column($exports['WBP_LargeMap_C']->LoadedProperties, 'Name');
    CheckFilter(in_array('VerticalBox_100', $classProperties, true) && array_column($exports['VerticalBox_100']->Data, 'Value', 'Name')['bIsVariable'] === 1, 'Legend tab must be able to hide the game legend list.');
    $bindings = [];
    foreach ($exports['ComponentDelegateBinding_0']->Data[0]->Value as $binding) {
        $values = array_column($binding->Value, 'Value', 'Name');
        $bindings[$values['ComponentPropertyName']] = $values['FunctionNameToBind'];
    }
    foreach ($details->tabNames as $tab) {
        CheckFilter(($bindings['SSOverlayTab' . $tab] ?? null) === 'SSOverlayShowTab' . $tab && in_array('SSOverlayShowTab' . $tab, $details->functionNames, true), 'Every legend tab must switch panels.');
        CheckFilter($exports['SSOverlayShowTab' . $tab]->ScriptBytecode[0]->StackNode === $details->radarSyncFunctionIndex, 'Tabs must refresh radar options before showing them.');
    }
    $panelProperties = array_column($exports['SSOverlayPanel']->Data, 'Value', 'Name');
    $radarProperties = array_column($exports['SSOverlayRadarPanel']->Data, 'Value', 'Name');
    CheckFilter($panelProperties['Visibility'] === 'ESlateVisibility::Collapsed' && $radarProperties['Visibility'] === 'ESlateVisibility::Collapsed', 'The map must open on the game legend tab.');
    $rowOwner = static fn(string $name): string => $generated->Exports[$generated->Exports[$slotOf($exports[$name]) - 1]->OuterIndex - 1]->ObjectName;
    CheckFilter($rowOwner('SSOverlayCheck_radar_enabled_Row') === 'SSOverlayRadarPanel' && $rowOwner('SSOverlayRadarRangeText') === 'SSOverlayRadarPanel'
        && $rowOwner('SSOverlayCheck_source_spawn_Row') === 'SSOverlayPuzzleLayout' && $rowOwner('SSOverlayButtonNone') === 'SSOverlayTypesHeader' && $rowOwner('SSOverlayScroll') === 'SSOverlayPuzzleLayout' && $rowOwner('SSOverlayCheck_type_matchbox') === 'SSOverlayList', 'Radar settings belong to the Radar tab, puzzle controls to the Puzzles tab.');
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
