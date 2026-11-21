<?php

declare(strict_types=1);

require_once __DIR__ . '/types.php';

function OverlayPosition(mixed $coord): ?array
{
    $position = [];
    foreach (['x', 'y', 'z'] as $axis) {
        $value = is_object($coord) ? ($coord->$axis ?? null) : (is_array($coord) ? ($coord[$axis] ?? null) : null);
        if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > 3.402823466e38) {
            return null;
        }
        $position[$axis] = (float)$value;
    }
    return $position;
}

function OverlayLocation(int $index, string $role, string $label, array $position): array
{
    return ['index' => $index, 'role' => $role, 'label' => $label] + $position;
}

/** Preserve serialized actor-local translation independently of map projection. */
function OverlayLocalTranslation(mixed $transform): ?array
{
    if (!is_string($transform)) { return null; }
    $axes = explode(',', explode('|', $transform, 2)[0]);
    if (count($axes) !== 3) { return null; }
    return OverlayPosition(array_combine(['x', 'y', 'z'], $axes));
}

/** Keep every remaining ID, including dynamic puzzles whose locations are unknown. */
function BuildOverlayData(array $puzzleMap, array $solvedPids, array $staticRemainingPids, array $hubRemainingPids, array $spawnRows = []): array
{
    $catalog = GetOverlayTypes();
    $map = [];
    foreach ($puzzleMap as $entry) {
        $pid = filter_var($entry->pid ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) {
            continue;
        }
        if (isset($map[$pid])) {
            throw new RuntimeException("Duplicate puzzle ID: $pid");
        }
        $map[$pid] = $entry;
    }
    $solved = array_fill_keys($solvedPids, true);
    $markers = [];
    $unlocated = [];
    $counts = [];
    foreach (['static' => $staticRemainingPids, 'hub' => $hubRemainingPids] as $group => $ids) {
        $counts[$group] = ['remaining' => 0, 'located' => 0, 'unlocated' => 0, 'renderPoints' => 0];
        $ids = array_unique(array_map('intval', $ids));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $pid) {
            if ($pid <= 0 || isset($solved[$pid])) {
                continue;
            }
            if (isset($markers[$pid]) || isset($unlocated[$pid])) {
                throw new RuntimeException("Puzzle ID belongs to multiple source groups: $pid");
            }
            $counts[$group]['remaining']++;
            $entry = $map[$pid] ?? null;
            if ($entry === null) {
                $unlocated[$pid] = ['pid' => $pid, 'group' => $group, 'reason' => 'missing_metadata'];
                $counts[$group]['unlocated']++;
                continue;
            }
            $ptype = $entry->ptype ?? '';
            if (!isset($catalog[$ptype]) || $ptype === 'spawnSite') {
                throw new RuntimeException("Unsupported puzzle type for ID $pid: $ptype");
            }
            $record = [
                'pid' => $pid, 'ptype' => $ptype, 'category' => (string)($entry->category ?? ''),
                'zoneIndex' => (int)($entry->zoneIndex ?? -1), 'family' => (string)($entry->family ?? ''),
                'group' => $group, 'label' => $catalog[$ptype]['name'] . ' #' . $pid, 'iconKey' => $ptype,
            ];
            $coordinates = $entry->coords ?? [];
            $reason = !is_array($coordinates) ? 'invalid_coordinates' : ($coordinates === [] ? ($group === 'hub' ? 'dynamic_location_not_in_metadata' : 'missing_coordinates') : null);
            $positions = [];
            if ($reason === null) {
                foreach ($coordinates as $coord) {
                    $position = OverlayPosition($coord);
                    if ($position === null) {
                        $reason = 'invalid_coordinates';
                        break;
                    }
                    $positions[] = $position;
                }
                if (($ptype === 'matchbox' && count($positions) !== 2) || ($ptype === 'seek5' && count($positions) !== 6)) {
                    $reason = 'invalid_location_count';
                }
            }
            if ($reason !== null) {
                $unlocated[$pid] = $record + ['reason' => $reason];
                $counts[$group]['unlocated']++;
                continue;
            }
            $geometry = 'point';
            $locations = [];
            if ($ptype === 'matchbox') {
                $geometry = 'pair';
                foreach ($positions as $index => $position) {
                    $locations[] = OverlayLocation($index, 'box', 'Box ' . ($index + 1) . ' of 2', $position);
                }
            } elseif ($ptype === 'seek5') {
                $geometry = 'star';
                $localTransforms = array_values((array)($entry->{'DuplicatedObjectOfType-Seek5HiddenObject'} ?? []));
                foreach ($positions as $index => $position) {
                    $location = OverlayLocation($index, $index === 0 ? 'pillar' : 'object', $index === 0 ? 'Pillar' : 'Object ' . $index . ' of 5', $position);
                    $localPosition = $index > 0 && count($localTransforms) === 5 ? OverlayLocalTranslation($localTransforms[$index - 1]) : null;
                    if ($localPosition !== null) { $location['pentadLocalPosition'] = $localPosition; }
                    $locations[] = $location;
                }
            } elseif ($ptype === 'racingBallCourse' || $ptype === 'racingRingCourse') {
                $geometry = 'route';
                $isGlide = $ptype === 'racingRingCourse';
                $locations[] = OverlayLocation(0, $isGlide ? 'start' : 'courseAnchor', $isGlide ? 'Starting platform' : 'Course anchor', $positions[0]);
                $record['routePoints'] = $positions;
            } else {
                $isViewpoint = $ptype === 'viewfinder';
                $locations[] = OverlayLocation(0, $isViewpoint ? 'viewpoint' : 'point', $isViewpoint ? 'Viewpoint; pickup location varies' : '', $positions[0]);
            }
            $markers[$pid] = $record + $positions[0] + ['geometry' => $geometry, 'locations' => $locations];
            $counts[$group]['located']++;
            $counts[$group]['renderPoints'] += count($locations);
        }
    }
    ksort($markers, SORT_NUMERIC);
    ksort($unlocated, SORT_NUMERIC);
    $spawnSites = BuildOverlaySpawnSites($spawnRows, $unlocated);
    $counts['spawn'] = ['sites' => count((array)$spawnSites), 'renderPoints' => count((array)$spawnSites), 'visibleByDefault' => false];
    return ['markers' => (object)$markers, 'unlocated' => (object)$unlocated, 'spawnSites' => $spawnSites, 'counts' => $counts];
}

/** Possible hub containers, never an assignment of a puzzle ID to an active spawn. */
function BuildOverlaySpawnSites(array $rows, array $unlocated): object
{
    $catalog = GetOverlayTypes();
    $normalize = static fn(string $value): string => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));
    $aliases = [];
    foreach ($catalog as $ptype => $type) {
        $aliases[$normalize($ptype)] = $ptype;
        $aliases[$normalize($type['name'])] = $ptype;
    }
    $needed = [];
    foreach ($unlocated as $puzzle) {
        if (($puzzle['group'] ?? '') === 'hub' && isset($puzzle['ptype'], $puzzle['zoneIndex'])) {
            $needed[$puzzle['zoneIndex']][$puzzle['ptype']] = true;
        }
    }
    $sites = [];
    foreach ($rows as $row) {
        $row = (array)$row;
        $zone = filter_var($row['zoneIndex'] ?? null, FILTER_VALIDATE_INT);
        $container = trim((string)($row['containerType'] ?? ''));
        if ($zone === false || !isset($needed[$zone]) || trim((string)($row['cluster'] ?? '')) !== '' || str_starts_with(strtolower($container), 'cluster')) {
            continue;
        }
        $position = OverlayPosition($row);
        if ($position === null) {
            continue;
        }
        $allowed = [];
        foreach (explode('|', (string)($row['ptypeList'] ?? '')) as $rawType) {
            $ptype = $aliases[$normalize($rawType)] ?? null;
            if ($ptype !== null && isset($needed[$zone][$ptype])) {
                $allowed[$ptype] = true;
            }
        }
        $allowed = array_keys($allowed);
        sort($allowed, SORT_STRING);
        if ($allowed === []) {
            continue;
        }
        $siteId = 'site-' . substr(hash('sha256', json_encode([$container, (string)($row['localID'] ?? ''), $zone, $position], JSON_THROW_ON_ERROR)), 0, 16);
        $allowed = array_values(array_unique(array_merge($sites[$siteId]['allowedTypes'] ?? [], $allowed)));
        sort($allowed, SORT_STRING);
        $label = 'Possible ' . implode(' / ', array_map(static fn(string $ptype): string => $catalog[$ptype]['name'], $allowed)) . ' spawn site; current puzzle unknown';
        $sites[$siteId] = [
            'siteId' => $siteId, 'ptype' => 'spawnSite', 'group' => 'spawn', 'iconKey' => 'spawnSite',
            'label' => $label, 'zoneIndex' => $zone, 'containerType' => $container, 'allowedTypes' => $allowed,
            'geometry' => 'point', 'visibleByDefault' => false,
            'locations' => [OverlayLocation(0, 'possibleSpawn', '', $position)],
        ] + $position;
    }
    ksort($sites, SORT_STRING);
    return (object)$sites;
}

/** Compatibility helper for callers that only need the located marker collection. */
function BuildFixedMarkers(array $puzzleMap, array $solvedPids): object
{
    $groups = ['static' => [], 'hub' => []];
    foreach ($puzzleMap as $entry) {
        if (isset($groups[$entry->family ?? ''])) {
            $groups[$entry->family][] = $entry->pid ?? 0;
        }
    }
    return BuildOverlayData($puzzleMap, $solvedPids, $groups['static'], $groups['hub'])['markers'];
}

function WriteMarkerJson(string $outputPath, array $document): void
{
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    $directory = dirname($outputPath);
    if (!is_dir($directory)) {
        throw new RuntimeException("Output directory does not exist: $directory");
    }
    $temporary = tempnam($directory, '.markers-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create temporary output.');
    }
    try {
        if (file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('Unable to write complete marker output.');
        }
        if (!rename($temporary, $outputPath)) {
            throw new RuntimeException("Unable to replace marker output: $outputPath");
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
