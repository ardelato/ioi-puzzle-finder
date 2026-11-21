<?php

declare(strict_types=1);

require_once __DIR__ . '/filter_widget.php';

/** Embed the independent radar in the existing gameplay HUD lifecycle. */
function AddRadarToHud(object $base): array
{
    $asset = unserialize(serialize($base));
    $exports = [];
    foreach ($asset->Exports as $i => $export) { $exports[$export->ObjectName] = $i + 1; }
    foreach (['WBP_GameHUD_C', 'WidgetTree'] as $required) {
        if (!isset($exports[$required])) { throw new RuntimeException('HUD is missing ' . $required); }
    }
    if (isset($exports['SSOverlayRadar'])) { throw new RuntimeException('HUD already contains the radar.'); }
    $treeIndex = $exports['WidgetTree'];
    $tree = $asset->Exports[$treeIndex - 1];
    $rootIndex = null;
    foreach ($tree->Data as $field) { if ($field->Name === 'RootWidget') { $rootIndex = $field->Value; } }
    if (!is_int($rootIndex) || $rootIndex <= 0) { throw new RuntimeException('HUD has no widget root.'); }
    $root = $asset->Exports[$rootIndex - 1];
    if (($asset->Imports[-1 - $root->ClassIndex]->ObjectName ?? '') !== 'CanvasPanel') { throw new RuntimeException('HUD root must be a CanvasPanel.'); }
    $umg = OverlayImport($asset, '/Script/UMG', 0, '/Script/CoreUObject', 'Package');
    $package = OverlayImport($asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($asset, 'WBP_SSOverlayRadar_C', $package, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $radarDefault = OverlayImport($asset, 'Default__WBP_SSOverlayRadar_C', $package, '/Game/SaveStats/WBP_SSOverlayRadar', 'WBP_SSOverlayRadar_C');
    $slotClass = OverlayImport($asset, 'CanvasPanelSlot', $umg, '/Script/CoreUObject', 'Class');
    $slotDefault = OverlayImport($asset, 'Default__CanvasPanelSlot', $umg, '/Script/UMG', 'CanvasPanelSlot');
    $radarIndex = count($asset->Exports) + 1;
    $slotIndex = $radarIndex + 1;
    $radar = OverlayExport($tree, $radarIndex, 'SSOverlayRadar', $treeIndex, $radarClass, $radarDefault, [
        OverlayProperty('Object', 'Slot', $slotIndex), FilterEnum('Visibility', 'ESlateVisibility', 'HitTestInvisible'),
    ]);
    $radar->Extras = '';
    $radar->CreateBeforeSerializationDependencies = [$slotIndex];
    $slot = OverlayExport($tree, $slotIndex, 'SSOverlayRadarSlot', $rootIndex, $slotClass, $slotDefault, [
        FilterStruct('LayoutData', 'AnchorData', [FilterStruct('Offsets', 'Margin', [
            OverlayProperty('Float', 'Left', 24), OverlayProperty('Float', 'Top', -24), OverlayProperty('Float', 'Right', 232), OverlayProperty('Float', 'Bottom', 264),
        ]), FilterStruct('Anchors', 'Anchors', [FilterVector('Minimum', 0, 1), FilterVector('Maximum', 0, 1)]), FilterVector('Alignment', 0, 1)]),
        OverlayProperty('Int', 'ZOrder', 100), OverlayProperty('Object', 'Parent', $rootIndex), OverlayProperty('Object', 'Content', $radarIndex),
    ]);
    $slot->Extras = '';
    $slot->CreateBeforeSerializationDependencies = [$radarIndex];
    $asset->Exports[] = $radar;
    $asset->Exports[] = $slot;
    $slots = null;
    foreach ($root->Data as $field) { if ($field->Name === 'Slots') { $slots = $field; } }
    if ($slots === null) { throw new RuntimeException('HUD root has no slots.'); }
    $slots->Value[] = OverlayProperty('Object', '0', $slotIndex);
    $root->CreateBeforeSerializationDependencies[] = $slotIndex;
    $tree->CreateBeforeSerializationDependencies[] = $radarIndex;
    $asset->NameMap = array_values(array_unique(array_merge($asset->NameMap, [
        '/Game/SaveStats/WBP_SSOverlayRadar', 'WBP_SSOverlayRadar_C', 'Default__WBP_SSOverlayRadar_C', 'SSOverlayRadar', 'SSOverlayRadarSlot', 'ESlateVisibility::HitTestInvisible',
    ])));
    return [$asset, ['hudPackage' => '/Game/ASophia/UI/HUD/WBP_GameHUD', 'radarPackage' => '/Game/SaveStats/WBP_SSOverlayRadar',
        'radarWidget' => 'SSOverlayRadar', 'inputTransparent' => true, 'originalFunctionCount' => count(array_filter($base->Exports, static fn(object $e): bool => str_contains($e->{'$type'}, 'FunctionExport')))]];
}

function RadarHudMain(array $argv): int
{
    $options = getopt('', ['base-hud:', 'output:', 'manifest:'], $next);
    foreach ($options as $value) { if (!is_string($value) || $value === '') { throw new RuntimeException('Options require one nonempty value.'); } }
    if ($next !== count($argv) || !isset($options['base-hud'], $options['output'])) { throw new RuntimeException('Provide --base-hud JSON --output JSON [--manifest JSON].'); }
    $input = realpath($options['base-hud']);
    if ($input === false || !is_file($input)) { throw new RuntimeException('HUD baseline must exist.'); }
    $resolve = static function (string $path): string {
        $parent = realpath(dirname($path));
        if ($parent === false || (file_exists($path) && !is_file($path))) { throw new RuntimeException('Output parent must exist and output must be a file.'); }
        return $parent . DIRECTORY_SEPARATOR . basename($path);
    };
    $output = $resolve($options['output']);
    $manifestPath = isset($options['manifest']) ? $resolve($options['manifest']) : null;
    $key = static fn(string $path): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path;
    if ($key($output) === $key($input) || ($manifestPath !== null && in_array($key($manifestPath), [$key($input), $key($output)], true))) { throw new RuntimeException('Inputs and outputs must have different paths.'); }
    [$asset, $manifest] = AddRadarToHud(json_decode(file_get_contents($input), false, 512, JSON_THROW_ON_ERROR));
    $cwd = getcwd();
    $temporary = FilterWidgetTemporary($output);
    $temporaries = [$temporary];
    try {
        require_once __DIR__ . '/../lib/uasset.php';
        SaveCompressedDecodedUasset($temporary, $asset, ['bakeAllIndices' => true, 'verbose' => false]);
        $staged = [$output => $temporary];
        if ($manifestPath !== null) {
            $manifestTemporary = FilterWidgetTemporary($manifestPath);
            $temporaries[] = $manifestTemporary;
            file_put_contents($manifestTemporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            $staged[$manifestPath] = $manifestTemporary;
        }
        FilterWidgetCommitOutputs($staged);
    } finally {
        foreach ($temporaries as $path) { if (is_file($path)) { unlink($path); } }
    }
    echo "Embedded gameplay radar in native HUD.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(RadarHudMain($argv)); } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
