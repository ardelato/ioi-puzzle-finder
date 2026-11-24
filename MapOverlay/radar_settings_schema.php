<?php

declare(strict_types=1);

require_once __DIR__ . '/types.php';

/**
 * Persistent radar settings: a tiny SaveGame subclass written to its own slot
 * (Saved/SaveGames/IOIPuzzleRadar.sav). It never touches the game's progress save.
 * Shared by the radar (reader and only writer) and the map (reader when no radar exists yet).
 */
const RADAR_SETTINGS_PACKAGE = '/Game/SaveStats/SSRadarSettings';
const RADAR_SETTINGS_CLASS = 'SSRadarSettings_C';
const RADAR_SETTINGS_SLOT = 'IOIPuzzleRadar';

/** Rebindable radar actions in shortcut order: field suffix => [input action, default key, map label]. */
function RadarShortcutActions(): array
{
    return [
        'Toggle' => ['SaveStatsRadarToggle', 'F8', 'Show / hide radar'],
        'ZoomIn' => ['SaveStatsRadarZoomIn', 'F3', 'Zoom in'],
        'ZoomOut' => ['SaveStatsRadarZoomOut', 'F4', 'Zoom out'],
        'Size' => ['SaveStatsRadarSize', 'F10', 'Cycle radar size'],
    ];
}

/** Saved fields and their defaults (the defaults are the radar's own). */
function RadarSettingsFields(): array
{
    $fields = ['Catalog' => ['Str', ''], 'SelectedMask' => ['Int', 0], 'IncludeSites' => ['Bool', false], 'HideDone' => ['Bool', false],
        'Enabled' => ['Bool', true], 'ZoomIndex' => ['Int', 1], 'SizeIndex' => ['Int', 1]];
    foreach (RadarShortcutActions() as $action => [, $key]) { $fields['Key' . $action] = ['Name', $key]; }
    return $fields;
}

/**
 * Selection bits are catalog positions; a saved mask is only trusted while the
 * catalog it was written with is unchanged.
 */
function RadarSettingsCatalogKey(): string
{
    return sprintf('%08x', crc32(implode(',', array_keys(GetOverlayTypes()))));
}
