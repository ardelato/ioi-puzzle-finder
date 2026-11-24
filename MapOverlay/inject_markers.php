<?php

declare(strict_types=1);

require_once __DIR__ . '/types.php';
require_once __DIR__ . '/completion_metadata.php';

function OverlayProperty(string $type, string $name, mixed $value): object
{
    return (object)['$type' => "UAssetAPI.PropertyTypes.Objects.{$type}PropertyData, UAssetAPI", 'Name' => $name, 'Value' => $value];
}

function OverlayVector(string $name, float $x, float $y, float $z): object
{
    return (object)[
        '$type' => 'UAssetAPI.PropertyTypes.Structs.StructPropertyData, UAssetAPI',
        'StructType' => 'Vector', 'SerializeNone' => true, 'Name' => $name,
        'Value' => [(object)[
            '$type' => 'UAssetAPI.PropertyTypes.Structs.VectorPropertyData, UAssetAPI', 'Name' => $name,
            'Value' => (object)['$type' => 'UAssetAPI.UnrealTypes.FVector, UAssetAPI', 'X' => $x, 'Y' => $y, 'Z' => $z],
        ]],
    ];
}

function OverlayText(string $name, string $text): object
{
    return (object)[
        '$type' => 'UAssetAPI.PropertyTypes.Objects.TextPropertyData, UAssetAPI',
        'Name' => $name, 'Flags' => 0, 'HistoryType' => 'Base', 'Namespace' => 'SaveStatsOverlay',
        'Value' => 'SS_' . sha1($text), 'CultureInvariantString' => $text,
    ];
}

function ValidateOverlayCoordinates(object $point, string $context): void
{
    foreach (['x', 'y', 'z'] as $axis) {
        $value = $point->$axis ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs((float)$value) > 3.402823466e38) {
            throw new RuntimeException("Invalid {$axis} coordinate for {$context}.");
        }
    }
}

/** Expand puzzle identities into the actual map pins, without inventing active spawn assignments. */
function ExpandOverlayMarkers(object $document, ?int $limit = null): array
{
    if (($document->schemaVersion ?? null) !== 2 || !is_object($document->markers ?? null) || !is_object($document->spawnSites ?? null)) {
        throw new RuntimeException('Expected schemaVersion 2 with markers and spawnSites objects.');
    }
    if ($limit !== null && $limit < 1) {
        throw new RuntimeException('Marker limit must be a positive integer.');
    }
    $types = GetOverlayTypes();
    $puzzles = (array)$document->markers;
    $sites = (array)$document->spawnSites;
    ksort($puzzles, SORT_NUMERIC);
    ksort($sites, SORT_STRING);
    $expanded = [];
    foreach ([$puzzles, $sites] as $collectionIndex => $records) {
        $ordinal = 0;
        // Sites are numbered from 1 in siteId order, before any smoke limit, so the
        // map tag and the radar's live-site tables always name the same place.
        $siteNumber = 0;
        foreach ($records as $key => $record) {
            $site = $collectionIndex === 1;
            if ($site) { $siteNumber++; }
            if (!is_object($record)) {
                throw new RuntimeException('Marker records must be objects.');
            }
            if ($site) {
                if (!is_string($record->siteId ?? null) || $record->siteId !== (string)$key || !preg_match('/^site-[a-f0-9]{16}$/D', $record->siteId) || ($record->group ?? null) !== 'spawn' || ($record->ptype ?? null) !== 'spawnSite') {
                    throw new RuntimeException('Invalid possible-spawn site identity or group.');
                }
                if (!is_array($record->allowedTypes ?? null)) {
                    throw new RuntimeException('Possible-spawn sites require an allowedTypes array.');
                }
                foreach ($record->allowedTypes as $allowedType) {
                    if (!is_string($allowedType) || !isset($types[$allowedType]) || $allowedType === 'spawnSite') {
                        throw new RuntimeException('Possible-spawn site has an unrecognized allowed puzzle type.');
                    }
                }
                $prefix = 'SaveStatsSite_' . substr($record->siteId, 5);
            } else {
                if (!is_int($record->pid ?? null) || $record->pid <= 0 || $record->pid > 2147483647 || (string)$record->pid !== (string)$key || !in_array($record->group ?? null, ['static', 'hub'], true)) {
                    throw new RuntimeException('Puzzle markers require a matching positive int32 pid and static/hub group.');
                }
                $prefix = 'SaveStatsMarker_' . $record->pid;
            }
            if (!is_string($record->ptype ?? null) || !isset($types[$record->ptype]) || ($record->iconKey ?? null) !== $record->ptype || !is_string($record->label ?? null) || trim($record->label) === '') {
                throw new RuntimeException("Marker {$key} has no recognized type, icon, or label.");
            }
            $completion = null;
            if (property_exists($record, 'completion')) {
                if ($site) { throw new RuntimeException('Possible spawn sites must not have special completion descriptors.'); }
                $completion = ValidateOverlayCompletion($record->completion, $record->pid, $record->ptype, $record->group);
            }
            $hubAvailabilityZone = $site ? 0 : ($record->hubAvailabilityZone ?? 0);
            if (!is_int($hubAvailabilityZone) || !in_array($hubAvailabilityZone, [0, 2, 3, 4, 5, 6], true)
                || ($hubAvailabilityZone > 0 && (($record->group ?? '') !== 'hub' || ($record->zoneIndex ?? null) !== $hubAvailabilityZone))) {
                throw new RuntimeException('Hub availability requires a matching exact Hub zone or zero.');
            }
            $zone = $record->zoneIndex ?? null;
            // SaveStats uses -1 (or omits the field) for an unknown zone.
            if ($zone !== null && (!is_int($zone) || $zone > 15)) { throw new RuntimeException("Marker {$key} has an invalid zone index."); }
            if ($zone !== null && $zone < 1) { $zone = null; }
            ValidateOverlayCoordinates($record, "marker {$key}");
            $geometry = $site ? 'point' : ($record->geometry ?? null);
            if (!in_array($geometry, ['point', 'pair', 'star', 'route'], true) || !is_array($record->locations ?? null) || empty($record->locations)) {
                throw new RuntimeException("Marker {$key} has no valid geometry/locations.");
            }
            $locations = $record->locations;
            if (($geometry === 'point' && count($locations) !== 1) || ($geometry === 'pair' && count($locations) !== 2) || ($geometry === 'star' && count($locations) !== 6)) {
                throw new RuntimeException("Marker {$key} location count does not match its geometry.");
            }
            $seen = [];
            foreach ($locations as $point) {
                if (!is_object($point) || !is_int($point->index ?? null) || $point->index < 0 || isset($seen[$point->index]) || !is_string($point->role ?? null) || $point->role === '' || !is_string($point->label ?? null) || ($point->label === '' && $geometry !== 'point')) {
                    throw new RuntimeException("Marker {$key} has invalid or duplicate location identities.");
                }
                $seen[$point->index] = true;
                ValidateOverlayCoordinates($point, "marker {$key} location {$point->index}");
            }
            if ($limit !== null && $ordinal++ >= $limit) {
                continue;
            }
            // Routes keep one anchor; their sampled course points are not separate map pins.
            if ($geometry === 'route') {
                $locations = [$locations[0]];
            }
            foreach ($locations as $point) {
                $pointLabel = $point->label !== '' ? $point->label : ($site ? 'Container' : 'Location');
                $label = $record->label . ' | ' . ($site ? 'Shown only while a random-spot puzzle is here' : ucfirst($record->group)) . ' | ' . $pointLabel;
                $renderPoint = (object)[
                    'actorName' => $prefix . '_' . $point->index,
                    'ptype' => $record->ptype, 'group' => $record->group, 'iconKey' => $record->iconKey,
                    'iconAsset' => $types[$record->ptype]['iconAsset'], 'label' => $label,
                    'pointIndex' => $point->index, 'role' => $point->role,
                    'hubAvailabilityZone' => $hubAvailabilityZone,
                    'x' => $point->x, 'y' => $point->y, 'z' => $point->z,
                    // Selection is explicit: loading the map must never render every puzzle.
                    'visibleByDefault' => false,
                    'tags' => array_merge(
                        ['SaveStatsOverlay', 'SaveStats.Source.' . $record->group],
                        $site ? ['SaveStats.Site.' . $siteNumber] : ['SaveStats.Puzzle.' . $record->pid],
                        $hubAvailabilityZone > 0 ? ['SaveStats.HubZone.' . $hubAvailabilityZone] : [],
                        !$site && $record->ptype === 'seek5' && $point->role === 'object' && $point->index >= 1 && $point->index <= 5 ? ['SaveStats.PentadRelic', 'SaveStats.PentadRelic.' . $point->index] : [],
                        array_map(fn(string $type): string => 'SaveStats.Type.' . $type, $site ? array_values(array_unique($record->allowedTypes)) : [$record->ptype]),
                        // The map queries one type in the selected zone, not every zone's pins.
                        $zone === null ? [] : array_map(fn(string $type): string => 'SaveStats.TypeZone.' . $type . '.' . $zone, $site ? array_values(array_unique($record->allowedTypes)) : [$record->ptype])
                    ),
                    'zoneIndex' => $zone ?? 0,
                ];
                if ($site) {
                    $renderPoint->siteId = $record->siteId;
                    $renderPoint->siteNumber = $siteNumber;
                } else {
                    $renderPoint->pid = $record->pid;
                    if ($completion !== null) { $renderPoint->completion = (object)$completion; }
                }
                if (!$site && $record->ptype === 'seek5' && $point->role === 'object' && $point->index >= 1 && $point->index <= 5 && isset($point->pentadLocalPosition)) {
                    if (!is_object($point->pentadLocalPosition)) { throw new RuntimeException('Pentad local position must be a coordinate object.'); }
                    ValidateOverlayCoordinates($point->pentadLocalPosition, "marker {$key} local relic {$point->index}");
                    $renderPoint->pentadLocalPosition = $point->pentadLocalPosition;
                }
                $expanded[] = $renderPoint;
            }
        }
    }
    return $expanded;
}

/**
 * Random-spot Hub puzzles: catalog IDs with no fixed location. The radar matches the
 * game's live spawned actors against these IDs, then pins the site each one stands on.
 */
function OverlayRandomSpotHubTable(object $document): array
{
    $types = GetOverlayTypes();
    $rows = [];
    foreach ((array)($document->unlocated ?? []) as $key => $record) {
        if (!is_object($record) || ($record->group ?? null) !== 'hub' || !is_string($record->ptype ?? null)) { continue; }
        $pid = $record->pid ?? null;
        if (!is_int($pid) || $pid <= 0 || $pid > 2147483647 || (string)$pid !== (string)$key) {
            throw new RuntimeException('Unlocated puzzles require a matching positive int32 pid.');
        }
        if (!isset($types[$record->ptype]) || $record->ptype === 'spawnSite') { continue; }
        $rows[$pid] = ['pid' => $pid, 'ptype' => $record->ptype];
    }
    ksort($rows, SORT_NUMERIC);
    return array_values($rows);
}

function OverlayImport(object $asset, string $name, int $outer, string $package, string $class): int
{
    foreach ($asset->Imports as $i => $entry) {
        if ($entry->ObjectName === $name && $entry->OuterIndex === $outer && $entry->ClassName === $class) {
            return -($i + 1);
        }
    }
    $index = -(count($asset->Imports) + 1);
    $asset->Imports[] = (object)[
        '$type' => 'UAssetAPI.Import, UAssetAPI', '$index' => $index, 'ObjectName' => $name,
        'OuterIndex' => $outer, 'ClassPackage' => $package, 'ClassName' => $class,
        'PackageName' => null, 'bImportOptional' => false,
    ];
    return $index;
}

function OverlayExport(object $template, int $index, string $name, int|string $outer, int $class, int $default, array $data): object
{
    $export = unserialize(serialize($template));
    $export->{'$index'} = $index;
    $export->ObjectName = $name;
    $export->ObjectFlags = 'RF_Transactional';
    $export->OuterIndex = $outer;
    $export->ClassIndex = $class;
    $export->SuperIndex = 0;
    $export->TemplateIndex = $default;
    $export->Data = $data;
    // Native tails belong to the source class. Copying Model_0's 106-byte tail
    // here made Actor serialization exceed the bytes the game actually reads.
    // Actor, SceneComponent and MarkerEmitter use no native tail in this game.
    $export->Extras = '';
    $export->ObjectGuid = null;
    $export->HasLeadingFourNullBytes = false;
    $export->SerializationControl = 'NoExtension';
    $export->Operation = 'None';
    $export->SerialSize = 0;
    $export->SerialOffset = 0;
    $export->ScriptSerializationStartOffset = 0;
    $export->ScriptSerializationEndOffset = 0;
    $export->SerializationBeforeSerializationDependencies = [];
    $export->CreateBeforeSerializationDependencies = [];
    $export->SerializationBeforeCreateDependencies = [$class, $default];
    $export->CreateBeforeCreateDependencies = [$outer];
    return $export;
}

/** Snapshot markers deliberately have no puzzle/quest IDs and never alter progression. */
function InjectOverlayMarkers(object $base, object $document, ?int $limit = null): object
{
    $markers = ExpandOverlayMarkers($document, $limit);
    if (!is_array($base->Exports ?? null) || !is_array($base->Imports ?? null) || !is_array($base->NameMap ?? null)) {
        throw new RuntimeException('Base map is not a decoded Unreal asset.');
    }
    $asset = unserialize(serialize($base));
    $level = null;
    $template = null;
    foreach ($asset->Exports as $i => $export) {
        if (str_starts_with($export->ObjectName ?? '', 'SaveStatsMarker_') || str_starts_with($export->ObjectName ?? '', 'SaveStatsSite_')) {
            throw new RuntimeException('Base map already contains SaveStats markers; use the original base map.');
        }
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.LevelExport, UAssetAPI' && $export->ObjectName === 'PersistentLevel') {
            $level = $export;
            $levelIndex = $export->{'$index'} ?? ($i + 1);
        }
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.NormalExport, UAssetAPI') {
            $template ??= $export;
        }
    }
    if ($level === null || $template === null || !is_array($level->Actors ?? null)) {
        throw new RuntimeException('Base map has no persistent level and normal export template.');
    }
    if (empty($markers)) {
        return $asset;
    }

    $engine = OverlayImport($asset, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    $game = OverlayImport($asset, '/Script/IslandsofInsight', 0, '/Script/CoreUObject', 'Package');
    $actorClass = OverlayImport($asset, 'Actor', $engine, '/Script/CoreUObject', 'Class');
    $actorDefault = OverlayImport($asset, 'Default__Actor', $engine, '/Script/Engine', 'Actor');
    $sceneClass = OverlayImport($asset, 'SceneComponent', $engine, '/Script/CoreUObject', 'Class');
    $sceneDefault = OverlayImport($asset, 'Default__SceneComponent', $engine, '/Script/Engine', 'SceneComponent');
    $markerClass = OverlayImport($asset, 'MarkerEmitter', $game, '/Script/CoreUObject', 'Class');
    $markerDefault = OverlayImport($asset, 'Default__MarkerEmitter', $game, '/Script/IslandsofInsight', 'MarkerEmitter');
    $textures = [];
    $addedNames = [];
    foreach ($markers as $marker) {
        if (!isset($textures[$marker->iconAsset])) {
            $package = '/Game/ASophia/UI/HUD/Markers/Textures/' . $marker->iconAsset;
            $texturePackage = OverlayImport($asset, $package, 0, '/Script/CoreUObject', 'Package');
            $textures[$marker->iconAsset] = OverlayImport($asset, $marker->iconAsset, $texturePackage, '/Script/Engine', 'Texture2D');
            $addedNames[] = $package;
            $addedNames[] = $marker->iconAsset;
        }
        array_push($addedNames, ...$marker->tags);
    }
    $creationMethod = OverlayProperty('Enum', 'CreationMethod', 'EComponentCreationMethod::Instance');
    $creationMethod->EnumType = 'EComponentCreationMethod';
    $creationMethod->InnerType = null;

    foreach ($markers as $marker) {
        $actorIndex = count($asset->Exports) + 1;
        $rootIndex = $actorIndex + 1;
        $emitterIndex = $actorIndex + 2;
        $instances = OverlayProperty('Array', 'InstanceComponents', [
            OverlayProperty('Object', '0', $rootIndex), OverlayProperty('Object', '1', $emitterIndex),
        ]);
        $instances->ArrayType = 'ObjectProperty';
        $tags = OverlayProperty('Array', 'Tags', array_map(fn(string $tag): object => OverlayProperty('Name', 'Tags', $tag), $marker->tags));
        $tags->ArrayType = 'NameProperty';
        $actor = OverlayExport($template, $actorIndex, $marker->actorName, $levelIndex, $actorClass, $actorDefault, [
            OverlayProperty('Object', 'RootComponent', $rootIndex), $instances, $tags,
        ]);
        $actor->CreateBeforeSerializationDependencies = [$rootIndex, $emitterIndex];
        $root = OverlayExport($template, $rootIndex, 'SaveStatsRoot', $actorIndex, $sceneClass, $sceneDefault, [
            OverlayVector('RelativeLocation', (float)$marker->x, (float)$marker->y, (float)$marker->z),
            clone $creationMethod,
        ]);
        $markerData = (object)[
            '$type' => 'UAssetAPI.PropertyTypes.Structs.StructPropertyData, UAssetAPI',
            'StructType' => 'LocationMarkerData', 'SerializeNone' => true, 'Name' => 'Marker',
            'Value' => [
                OverlayProperty('Object', 'worldTex', 0), OverlayProperty('Object', 'mapTex', $textures[$marker->iconAsset]),
                OverlayProperty('Bool', 'VisibleOnScreen', 0), OverlayProperty('Bool', 'VisibleOnMap', $marker->visibleByDefault ? 1 : 0),
                OverlayProperty('Bool', 'DrawNameOnMap', 0),
                OverlayProperty('Bool', 'HideCompletionIcon', 1), OverlayProperty('Bool', 'ShowInfoPanel', 1),
                OverlayText('MarkerName', $marker->label), OverlayText('FunctionText', ''),
                OverlayProperty('Bool', 'SupportsFastTravel', 0),
                OverlayProperty('Float', 'SizeMultiplier', 0.6), OverlayVector('MapWorldPositionOffset', 0, 0, 0),
            ],
        ];
        $emitter = OverlayExport($template, $emitterIndex, 'SaveStatsEmitter', $actorIndex, $markerClass, $markerDefault, [$markerData, clone $creationMethod]);
        $asset->Exports[] = $actor;
        $asset->Exports[] = $root;
        $asset->Exports[] = $emitter;
        $level->Actors[] = $actorIndex;
        $level->CreateBeforeSerializationDependencies[] = $actorIndex;
    }
    $asset->NameMap = array_values(array_unique(array_merge($asset->NameMap, $addedNames, [
        'Actor', 'Default__Actor', 'Default__SceneComponent', 'Default__MarkerEmitter', 'SceneComponent', 'MarkerEmitter',
        'RootComponent', 'InstanceComponents', 'CreationMethod', 'EComponentCreationMethod', 'EComponentCreationMethod::Instance',
        'RelativeLocation', 'Vector', 'Marker', 'LocationMarkerData', 'worldTex', 'mapTex', 'VisibleOnScreen', 'VisibleOnMap', 'DrawNameOnMap',
        'HideCompletionIcon', 'ShowInfoPanel', 'SizeMultiplier', 'MapWorldPositionOffset', 'SaveStatsRoot', 'SaveStatsEmitter',
        'Tags', 'NameProperty', 'MarkerName', 'FunctionText', 'SupportsFastTravel', 'Texture2D', 'None',
    ])));
    return $asset;
}

function InjectMarkersMain(array $argv): int
{
    $options = getopt('', ['markers:', 'base-map:', 'output:', 'render-manifest:', 'limit:', 'help'], $nextArgument);
    if (isset($options['help'])) {
        echo "Usage: php inject_markers.php --markers FILE.json --output MAP.json --base-map MAP.json [--render-manifest FILE.json] [--limit N]\n";
        return 0;
    }
    if ($nextArgument !== count($argv) || !isset($options['markers'], $options['base-map'], $options['output'])) {
        throw new RuntimeException('Provide --markers, --base-map and --output. Use --help for usage.');
    }
    $callerDirectory = getcwd();
    $absolute = static fn(string $path): string => preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) ? $path : $callerDirectory . DIRECTORY_SEPARATOR . $path;
    $input = $absolute($options['markers']);
    $basePath = $absolute($options['base-map']);
    $output = $absolute($options['output']);
    $manifestPath = isset($options['render-manifest']) ? $absolute($options['render-manifest']) : null;
    if (!is_file($input) || !is_file($basePath) || !is_dir(dirname($output))) {
        throw new RuntimeException('Input files and the output parent directory must exist.');
    }
    if (realpath($output) !== false && in_array(realpath($output), [realpath($input), realpath($basePath)], true)) {
        throw new RuntimeException('Output must not overwrite either input file.');
    }
    if ($manifestPath !== null) {
        if (!is_dir(dirname($manifestPath))) {
            throw new RuntimeException('Render manifest parent directory must exist.');
        }
        $canonical = static fn(string $path): string => strtolower(realpath(dirname($path)) . DIRECTORY_SEPARATOR . basename($path));
        if (in_array($canonical($manifestPath), array_map($canonical, [$input, $basePath, $output]), true)) {
            throw new RuntimeException('Render manifest must be separate from inputs and map output.');
        }
    }
    $limit = null;
    if (isset($options['limit'])) {
        $limit = filter_var($options['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            throw new RuntimeException('--limit must be a positive integer.');
        }
    }
    $document = json_decode(file_get_contents($input), false, 512, JSON_THROW_ON_ERROR);
    $base = json_decode(file_get_contents($basePath), false, 512, JSON_THROW_ON_ERROR);
    if (!is_object($base) || !is_object($document)) {
        throw new RuntimeException('Expected JSON objects for marker document and base map.');
    }
    $renderPoints = ExpandOverlayMarkers($document, $limit);
    $asset = InjectOverlayMarkers($base, $document, $limit);
    require_once __DIR__ . '/../lib/uasset.php';
    $temporary = tempnam(dirname($output), '.markers-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary output.');
    }
    $manifestTemporary = null;
    try {
        SaveCompressedDecodedUasset($temporary, $asset, ['bakeAllIndices' => true, 'verbose' => false]);
        json_decode(file_get_contents($temporary), false, 512, JSON_THROW_ON_ERROR);
        if ($manifestPath !== null) {
            $manifestTemporary = tempnam(dirname($manifestPath), '.render-');
            if ($manifestTemporary === false || file_put_contents($manifestTemporary, json_encode(
                (object)['schemaVersion' => 1, 'renderPoints' => $renderPoints, 'randomSpotHub' => OverlayRandomSpotHubTable($document)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )) === false) {
                throw new RuntimeException('Cannot write render manifest.');
            }
        }
        if (!rename($temporary, $output)) {
            throw new RuntimeException('Cannot replace output file.');
        }
        if ($manifestPath !== null && !rename($manifestTemporary, $manifestPath)) {
            throw new RuntimeException('Cannot replace render manifest.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        if (is_string($manifestTemporary) && is_file($manifestTemporary)) {
            unlink($manifestTemporary);
        }
    }
    $count = (count($asset->Exports) - count($base->Exports)) / 3;
    echo "Injected {$count} snapshot markers into {$output}\n";
    echo "Asset prototype: marker visibility and HUD suppression require in-game verification.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(InjectMarkersMain($argv));
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
