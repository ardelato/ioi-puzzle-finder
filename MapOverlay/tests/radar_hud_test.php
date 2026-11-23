<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/radar_hud.php';
require_once dirname(__DIR__) . '/radar_widget.php';

function CheckRadarHud(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_GameHUD.json')), false, 512, JSON_THROW_ON_ERROR);
$before = json_encode($base, JSON_THROW_ON_ERROR);
[$hud, $manifest] = AddRadarToHud($base);
CheckRadarHud(json_encode($base, JSON_THROW_ON_ERROR) === $before, 'Embedding mutated the HUD baseline.');
CheckRadarHud(count($hud->Exports) === count($base->Exports) + 2, 'Embedding must add only the radar widget and its canvas slot.');
$exports = array_column($hud->Exports, null, 'ObjectName');
foreach ($base->Exports as $original) {
    $current = $exports[$original->ObjectName];
    if (str_contains($original->{'$type'}, 'FunctionExport')) {
        CheckRadarHud(json_encode($current) === json_encode($original), 'An original HUD function changed.');
    }
}
$properties = array_column($exports['SSOverlayRadar']->Data, 'Value', 'Name');
CheckRadarHud($properties['Visibility'] === 'ESlateVisibility::HitTestInvisible', 'Radar must allow input through itself and all children.');
$layout = $exports['SSOverlayRadarSlot']->Data[0]->Value;
CheckRadarHud(array_column($layout[0]->Value, 'Value', 'Name') === ['Left' => 24, 'Top' => -24, 'Right' => 232, 'Bottom' => 264], 'Radar must occupy the compact bottom-left gameplay placement.');
CheckRadarHud($manifest['hudPackage'] === '/Game/ASophia/UI/HUD/WBP_GameHUD' && $manifest['originalFunctionCount'] === 9, 'Unexpected native HUD baseline.');
$radarClass = $hud->Imports[-1 - $exports['SSOverlayRadar']->ClassIndex];
CheckRadarHud($radarClass->ObjectName === 'WBP_SSOverlayRadar_C' && $hud->Imports[-1 - $radarClass->OuterIndex]->ObjectName === '/Game/SaveStats/WBP_SSOverlayRadar', 'HUD must embed the dedicated radar widget class.');
$duplicateRejected = false;
try { AddRadarToHud($hud); } catch (RuntimeException $error) { $duplicateRejected = true; }
CheckRadarHud($duplicateRejected, 'Duplicate radar embedding was accepted.');

// The radar widget's multi-type selection contract shared with the map.
$radarBase = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
$radarPoint = static fn(string $id, string $type, float $x, float $y, bool $site = false): array => [
    'actorName' => $id, 'ptype' => $site ? 'spawnSite' : $type, 'group' => $site ? 'spawn' : 'static',
    'x' => $x, 'y' => $y, 'z' => 0, 'tags' => $site ? ['SaveStats.Type.hiddenCube', 'SaveStats.Type.matchbox'] : ['SaveStats.Type.' . $type],
];
$radarHubPoint = static fn(string $id, string $type, int $pid, int $zone): array => ['actorName' => $id, 'ptype' => $type, 'group' => 'hub', 'pid' => $pid,
    'hubAvailabilityZone' => $zone, 'x' => 2000, 'y' => 2000, 'z' => 0, 'tags' => ['SaveStats.Type.' . $type]];
$radarIndex = BuildRadarIndex([$radarPoint('A', 'hiddenCube', 1000, 0), $radarPoint('B', 'matchbox', 0, 1000), $radarPoint('C', 'seek5', 500, 500),
    $radarPoint('D', 'matchbox', 50000, 50000), $radarPoint('Site', 'hiddenCube', -1000, 0, true),
    $radarHubPoint('HubCube', 'hiddenCube', 9001, 2), $radarHubPoint('HubMatch', 'matchbox', 9002, 3),
    ['pid' => 101, 'zoneIndex' => 2] + $radarPoint('Z1', 'hiddenCube', 3000, 0), ['pid' => 102, 'zoneIndex' => 2] + $radarPoint('Z2', 'matchbox', 3000, 100), ['pid' => 102, 'zoneIndex' => 2] + $radarPoint('Z2b', 'matchbox', 3000, 200)], array_intersect_key(GetOverlayTypes(), array_flip(['hiddenCube', 'matchbox', 'seek5'])));
[$radar, $radarManifest] = BuildRadarWidget($radarBase, $radarIndex);
$radarClassExport = $radar->Exports[0];
$radarFields = array_column($radarClassExport->LoadedProperties, null, 'Name');
$radarDefaults = array_column($radar->Exports[1]->Data, null, 'Name');
$radarFunctions = [];
foreach ($radar->Exports as $export) { if (str_contains($export->{'$type'} ?? '', 'FunctionExport')) { $radarFunctions[$export->ObjectName] = $export; } }
CheckRadarHud(!isset($radarFields['SSRadarSelectedTag']) && !isset($radarDefaults['SSRadarSelectedTag']) && !str_contains(json_encode($radar, JSON_THROW_ON_ERROR), 'SSRadarSelectedTag'), 'Radar must not keep the single-type SSRadarSelectedTag state.');
CheckRadarHud(isset($radarFields['SSRadarSelectedMask']) && $radarFields['SSRadarSelectedMask']->SerializedType === 'IntProperty'
    && $radarDefaults['SSRadarSelectedMask']->Value === 0 && str_contains($radarDefaults['SSRadarSelectedMask']->{'$type'}, 'IntPropertyData'), 'Radar selection must be an Int SSRadarSelectedMask defaulting to 0.');
$setSelectionParameters = $radarFunctions['SSRadarSetSelection']->LoadedProperties;
CheckRadarHud($setSelectionParameters[0]->Name === 'SelectedMask' && $setSelectionParameters[0]->SerializedType === 'IntProperty' && str_contains($setSelectionParameters[0]->PropertyFlags, 'CPF_Parm')
    && array_column(array_slice($setSelectionParameters, 0, 3), 'Name') === ['SelectedMask', 'IncludeSites', 'Enabled'], 'SSRadarSetSelection must take (Int SelectedMask, Bool IncludeSites, Bool Enabled).');
CheckRadarHud(array_column($radarDefaults['SSRadarTypeBits']->Value, 'Value') === array_map('OverlayTypeBit', $radarIndex['typeKeys']) && $radarDefaults['SSRadarTypeBits']->ArrayType === 'IntProperty', 'SSRadarTypeBits must hold the full-catalog OverlayTypeBit of every radar type.');
CheckRadarHud($radarManifest['typeBits'] === array_combine($radarIndex['typeKeys'], array_map('OverlayTypeBit', $radarIndex['typeKeys'])) && $radarManifest['selectionField'] === 'SSRadarSelectedMask', 'Radar manifest must publish the selection mask contract.');
$expectedPointTypes = [];
foreach ($radarIndex['typeKeys'] as $t => $unused) {
    $cells = $radarIndex['width'] * $radarIndex['height'];
    for ($n = $radarIndex['offsets'][$t * $cells]; $n < $radarIndex['offsets'][($t + 1) * $cells]; $n++) { $expectedPointTypes[] = $t; }
}
CheckRadarHud(array_column($radarDefaults['SSRadarPointTypes']->Value, 'Value') === $expectedPointTypes && count($expectedPointTypes) === count($radarIndex['positions']), 'SSRadarPointTypes must give every point its radar type index.');
$textureClass = $radar->Imports[-1 - $radarFields['SSRadarTypeTextures']->Inner->PropertyClass];
CheckRadarHud($textureClass->ObjectName === 'Texture2D' && $textureClass->ClassName === 'Class', 'SSRadarTypeTextures must be a Texture2D object array.');
$textureNames = array_map(static fn(object $value): string => $radar->Imports[-1 - $value->Value]->ObjectName, $radarDefaults['SSRadarTypeTextures']->Value);
CheckRadarHud($textureNames === array_map(static fn(string $key): string => GetOverlayTypes()[$key]['iconAsset'], $radarIndex['typeKeys']), 'SSRadarTypeTextures must hold each type icon in radar type order.');
$scanCode = json_encode([$radarFunctions['SSRadarScan']->ScriptBytecode, $radarFunctions['SSRadarStartScan']->ScriptBytecode], JSON_THROW_ON_ERROR);
CheckRadarHud(str_contains($scanCode, '"SSRadarTypeBits"') && str_contains($scanCode, '"SSRadarSelectedMask"') && str_contains($scanCode, '"SSRadarScanType"') && str_contains($scanCode, '"SSRadarMinRow"'), 'Radar scan must walk every type selected in SSRadarSelectedMask.');
CheckRadarHud(str_contains(json_encode($radarFunctions['SSRadarRender']->ScriptBytecode, JSON_THROW_ON_ERROR), '"SSRadarTypeTextures"') && isset($radarFields['SSRadarPinType23']) && $radarDefaults['SSRadarPinType0']->Value === -1, 'Radar pins must take icons from their own point type with a per-pin cache.');
// Hub availability covers every Hub-capable type, so a selection change never empties it
// (an empty cache is fail-visible and flashed inactive Hub puzzles on the map).
CheckRadarHud(!isset($radarFields['SSRadarHubTypeTags']) && !isset($radarFields['SSRadarAvailabilityMask']) && !isset($radarFields['SSRadarAvailabilityTag'])
    && isset($radarFields['SSRadarAvailabilityTypeCursor'], $radarFields['SSRadarAvailabilityWork'], $radarFields['SSRadarAvailabilityWorkUnknown']), 'Hub availability must not be keyed on the selection.');
foreach (['SSRadarRefreshAvailability', 'SSRadarIsPuzzleAvailable'] as $availabilityFunction) {
    CheckRadarHud(!str_contains(json_encode($radarFunctions[$availabilityFunction]->ScriptBytecode, JSON_THROW_ON_ERROR), '"Path":["SSRadarSelectedMask"]'), "{$availabilityFunction} must not depend on the selected types.");
}
// Visit every imported native call in some bytecode as (name, call node).
$eachCall = static function (mixed $code, callable $visit) use ($radar): void {
    $walk = static function (mixed $value) use (&$walk, $visit, $radar): void {
        if (is_object($value)) {
            if (isset($value->StackNode) && is_int($value->StackNode) && $value->StackNode < 0) { $visit($radar->Imports[-1 - $value->StackNode]->ObjectName, $value); }
            foreach ((array)$value as $child) { $walk($child); }
        } elseif (is_array($value)) { foreach ($value as $child) { $walk($child); } }
    };
    $walk($code);
};
$availabilityCalls = 0;
$eachCall($radarFunctions['SSRadarRefreshAvailability']->ScriptBytecode, static function (string $name, object $call) use (&$availabilityCalls): void {
    if ($name !== 'GetAllActiveSandboxPuzzleDataOfTypeInZone') { return; }
    $availabilityCalls++;
    CheckRadarHud(str_contains($call->Parameters[1]->{'$type'}, 'EX_LocalVariable') && $call->Parameters[1]->Variable->New->Path === ['ZoneByte'], 'Each Hub query must take the current zone cursor.');
});
$refreshCode = json_encode($radarFunctions['SSRadarRefreshAvailability']->ScriptBytecode, JSON_THROW_ON_ERROR);
CheckRadarHud($radarManifest['hubAvailability']['eligibleTypeMask'] === (OverlayTypeBit('hiddenCube') | OverlayTypeBit('matchbox'))
    && substr_count($refreshCode, '"Value":"hiddenCube"') === 1 && substr_count($refreshCode, '"Value":"matchbox"') === 1 && !str_contains($refreshCode, '"Value":"seek5"')
    && $availabilityCalls === 2 && $radarManifest['hubAvailability']['fullSweepRefreshCalls'] === 10 && $radarManifest['hubAvailability']['nativeCallLimitPerRefresh'] === 1,
    'Hub refresh must query one Hub-capable type per call, cycling every type through every zone.');
$values = static fn(string $name): array => array_map(static fn(object $v): int => $v->Value, $radarDefaults[$name]->Value);
CheckRadarHud($values('SSRadarCountIds') === [101, 9001, 102, 9002] && $values('SSRadarCountSlots') === [OverlayCatalogSlot('hiddenCube'), OverlayCatalogSlot('hiddenCube'), OverlayCatalogSlot('matchbox'), OverlayCatalogSlot('matchbox')]
    && $values('SSRadarCountHub') === [0, 1, 0, 1], 'Zone counts must list each puzzle once (pairs share an ID), by zone and type, flagging Hub puzzles.');
CheckRadarHud($values('SSRadarCountZoneStart') === [0, 0, 0, 3, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4] && count($values('SSRadarCountLeft')) === 31, 'Count rows need per-zone ranges and catalog-indexed results.');
$radarExports = array_column($radar->Exports, null, 'ObjectName');
AssertNoContextCallArguments($radar, 'Radar widget');
$countStep = array_search($radarExports['SSRadarCountStep'], $radar->Exports, true) + 1;
CheckRadarHud($radarExports['SSRadarTick']->ScriptBytecode[0]->StackNode === $countStep, 'The radar tick must advance the bounded count pass.');
$stepBytecode = json_encode($radarExports['SSRadarCountStep']->ScriptBytecode, JSON_THROW_ON_ERROR);
CheckRadarHud(str_contains($stepBytecode, '"Path":["SSRadarCountActive"]'), 'Count step must gate Hub puzzles on the active list.');
$arraySetCalls = 0;
$findArraySet = static function (mixed $value) use (&$findArraySet, &$arraySetCalls, $radar): void {
    if (is_object($value)) {
        if (isset($value->StackNode) && is_int($value->StackNode) && $value->StackNode < 0 && $radar->Imports[-1 - $value->StackNode]->ObjectName === 'Array_Set') {
            $arraySetCalls++;
            CheckRadarHud(str_contains($value->Parameters[2]->{'$type'}, 'EX_LocalVariable'), 'Array_Set must receive a plain local item; inline calls write the stale value in engine.');
        }
        foreach ((array)$value as $child) { $findArraySet($child); }
    } elseif (is_array($value)) { foreach ($value as $child) { $findArraySet($child); } }
};
$findArraySet($radarExports['SSRadarCountStep']->ScriptBytecode);
CheckRadarHud($arraySetCalls === 2, 'Count step must increment both left and total.');
CheckRadarHud(array_column($radarExports['SSRadarRequestCounts']->LoadedProperties, 'Name') === ['Zone'] && $radarManifest['counts']['request'] === 'SSRadarRequestCounts(Zone)', 'The map requests counts by zone.');
echo "HUD radar embedding checks passed: native functions, lifecycle host, input transparency, placement, duplicate rejection, and the multi-type selection mask.\n";
