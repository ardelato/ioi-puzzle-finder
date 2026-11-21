<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/radar_hud.php';

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
echo "HUD radar embedding checks passed: native functions, lifecycle host, input transparency, placement, and duplicate rejection.\n";
