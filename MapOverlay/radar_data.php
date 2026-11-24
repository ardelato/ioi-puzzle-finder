<?php

declare(strict_types=1);

require_once __DIR__ . '/types.php';
require_once __DIR__ . '/completion_metadata.php';

function RadarPosition(array|object $position): array
{
    $position = (array)$position;
    $result = [];
    foreach (['x', 'y', 'z'] as $axis) {
        $value = $position[$axis] ?? null;
        if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > 3.402823466e38) {
            throw new InvalidArgumentException('Radar coordinates must be finite float32 values.');
        }
        // Bin the same float32 coordinate that the cooked FVector/Float arrays
        // contain; rounding across a cell edge must not hide a boundary pin.
        $result[$axis] = unpack('g', pack('g', (float)$value))[1];
    }
    return $result;
}

/**
 * Flat arrays are intentional: cooked Blueprint can read them with Array_Get.
 * CellOffsets has one sentinel, so a complete row segment needs just two reads.
 */
function BuildRadarIndex(array $renderPoints, ?array $catalog = null, float $cellSizeCm = 20000, array $randomSpotHub = []): array
{
    if (!is_finite($cellSizeCm) || $cellSizeCm <= 0) {
        throw new InvalidArgumentException('Radar cell size must be positive and finite.');
    }
    $catalog ??= GetOverlayTypes();
    unset($catalog['spawnSite']);
    $typeKeys = array_keys($catalog);
    if ($typeKeys === []) { throw new InvalidArgumentException('Radar requires at least one puzzle type.'); }
    $typeIndices = array_flip($typeKeys);
    $points = [];
    $seen = [];
    $completions = [];
    $minX = $minY = PHP_INT_MAX;
    $maxX = $maxY = PHP_INT_MIN;
    foreach ($renderPoints as $raw) {
        $point = (array)$raw;
        $position = RadarPosition($point);
        $id = $point['actorName'] ?? null;
        if (!is_string($id) || $id === '' || isset($seen[$id])) {
            throw new InvalidArgumentException('Radar points require unique, nonempty actor names.');
        }
        $seen[$id] = true;
        $isSpawn = ($point['group'] ?? '') === 'spawn';
        $hubAvailabilityZone = $isSpawn ? 0 : ($point['hubAvailabilityZone'] ?? 0);
        if (!is_int($hubAvailabilityZone) || !in_array($hubAvailabilityZone, [0, 2, 3, 4, 5, 6], true)
            || ($hubAvailabilityZone > 0 && ($point['group'] ?? '') !== 'hub')) {
            throw new InvalidArgumentException('Radar Hub availability requires an exact Hub zone or zero.');
        }
        // Synthetic geometry fixtures may omit identity. Such points and possible
        // sites use zero so neither can be mistaken for a completed puzzle.
        $puzzleId = 0;
        if (!$isSpawn && array_key_exists('pid', $point)) {
            if (!is_int($point['pid']) || $point['pid'] <= 0 || $point['pid'] > 2147483647) {
                throw new InvalidArgumentException('Exact radar puzzle IDs must be positive int32 values.');
            }
            $puzzleId = $point['pid'];
        }
        if ($hubAvailabilityZone > 0 && $puzzleId <= 0) { throw new InvalidArgumentException('Hub availability requires an exact positive puzzle ID.'); }
        // Zone feeds per-zone counts; zero means unknown and is never counted.
        $zone = $point['zoneIndex'] ?? 0;
        if (!is_int($zone) || $zone < 0 || $zone > 15 || ($hubAvailabilityZone > 0 && $zone !== 0 && $zone !== $hubAvailabilityZone)) {
            throw new InvalidArgumentException('Radar zone must be an int from 0 through 15 that agrees with Hub availability.');
        }
        if ($hubAvailabilityZone > 0) { $zone = $hubAvailabilityZone; }
        $completion = null;
        if (array_key_exists('completion', $point)) {
            if ($isSpawn) { throw new InvalidArgumentException('Possible spawn sites must not have special completion descriptors.'); }
            $completion = ValidateOverlayCompletion($point['completion'], $puzzleId, (string)($point['ptype'] ?? ''), (string)($point['group'] ?? ''));
        }
        if ($puzzleId > 0) {
            if (array_key_exists($puzzleId, $completions) && $completions[$puzzleId] !== $completion) {
                throw new InvalidArgumentException('Radar points for one puzzle must have identical completion descriptors.');
            }
            $completions[$puzzleId] = $completion;
        }
        // Only explicit Hidden Pentad relic metadata can opt a point into an
        // active-puzzle focus. Zero leaves pillars and legacy fixtures in the
        // normal overview without inferring roles from actor names or order.
        $pentadRelicIndex = 0;
        $pentadLocalPosition = null;
        if (!$isSpawn && ($point['ptype'] ?? '') === 'seek5' && array_key_exists('pointIndex', $point)) {
            $locationIndex = $point['pointIndex'];
            if (!is_int($locationIndex) || $locationIndex < 0 || $locationIndex > 5
                || ($point['role'] ?? null) !== ($locationIndex === 0 ? 'pillar' : 'object')) {
                throw new InvalidArgumentException('Hidden Pentad locations require a pillar at index 0 and relics at indices 1–5.');
            }
            if ($puzzleId > 0) { $pentadRelicIndex = $locationIndex; }
            if ($pentadRelicIndex > 0 && array_key_exists('pentadLocalPosition', $point)) {
                if (!is_array($point['pentadLocalPosition']) && !is_object($point['pentadLocalPosition'])) {
                    throw new InvalidArgumentException('Pentad local position must be finite float32 coordinates.');
                }
                $pentadLocalPosition = RadarPosition($point['pentadLocalPosition']);
            }
        }
        // The marker export numbers sites; zero (older fixtures) can never be live.
        $siteNumber = 0;
        if ($isSpawn && array_key_exists('siteNumber', $point)) {
            $siteNumber = $point['siteNumber'];
            if (!is_int($siteNumber) || $siteNumber <= 0 || $siteNumber > 1000000) {
                throw new InvalidArgumentException('Random-spot site numbers must be positive ints.');
            }
        }
        $types = [];
        if ($isSpawn) {
            foreach (($point['tags'] ?? []) as $tag) {
                if (is_string($tag) && str_starts_with($tag, 'SaveStats.Type.')) {
                    $types[substr($tag, strlen('SaveStats.Type.'))] = true;
                }
            }
            if ($types === []) { throw new InvalidArgumentException('A possible site requires eligible puzzle types.'); }
        } else {
            $types[(string)($point['ptype'] ?? '')] = true;
        }
        $rawCellX = floor($position['x'] / $cellSizeCm);
        $rawCellY = floor($position['y'] / $cellSizeCm);
        foreach ([$rawCellX, $rawCellY] as $cell) {
            if (!is_finite($cell) || $cell < -2147483648 || $cell > 2147483647) {
                throw new InvalidArgumentException('Radar cell coordinates must fit native int32 values.');
            }
        }
        $cellX = (int)$rawCellX;
        $cellY = (int)$rawCellY;
        $minX = min($minX, $cellX);
        $minY = min($minY, $cellY);
        $maxX = max($maxX, $cellX);
        $maxY = max($maxY, $cellY);
        foreach (array_keys($types) as $type) {
            if (!isset($typeIndices[$type])) { throw new InvalidArgumentException('Unsupported radar type: ' . $type); }
            $points[] = ['type' => $typeIndices[$type], 'cellX' => $cellX, 'cellY' => $cellY,
                'position' => $position, 'isSpawn' => $isSpawn, 'id' => $id, 'puzzleId' => $puzzleId,
                'pentadRelicIndex' => $pentadRelicIndex, 'pentadLocalPosition' => $pentadLocalPosition,
                'hubAvailabilityZone' => $hubAvailabilityZone, 'zone' => $zone, 'siteNumber' => $siteNumber,
                'countable' => !$isSpawn && (($point['group'] ?? 'static') !== 'hub' || $hubAvailabilityZone > 0)];
        }
    }
    if ($points === []) { $minX = $minY = $maxX = $maxY = 0; }
    $width = $maxX - $minX + 1;
    $height = $maxY - $minY + 1;
    $cellsPerType = $width * $height;
    // Reject corrupt/extreme coordinates before allocating a dense table.
    if ($cellsPerType > 1000000 || $cellsPerType * count($typeKeys) > 1000000) {
        throw new InvalidArgumentException('Radar bounds exceed the dense index capacity.');
    }
    foreach ($points as &$point) {
        $point['cell'] = $point['type'] * $cellsPerType + ($point['cellY'] - $minY) * $width + $point['cellX'] - $minX;
    }
    unset($point);
    usort($points, static fn(array $a, array $b): int => ($a['cell'] <=> $b['cell']) ?: strcmp($a['id'], $b['id']));
    $offsets = array_fill(0, $cellsPerType * count($typeKeys) + 1, 0);
    $stableIds = array_keys($seen);
    sort($stableIds, SORT_STRING);
    $ranks = array_flip($stableIds);
    $positions = $isSpawn = $pointIds = $pointRanks = $puzzleIds = $pentadRelicIndices = $pentadLocalPositions = $hubAvailabilityZones = $zones = $countable = $siteNumbers = [];
    foreach ($points as $point) {
        $offsets[$point['cell'] + 1]++;
        $positions[] = $point['position'];
        $isSpawn[] = $point['isSpawn'] ? 1 : 0;
        $pointIds[] = $point['id'];
        $pointRanks[] = $ranks[$point['id']];
        $puzzleIds[] = $point['puzzleId'];
        $pentadRelicIndices[] = $point['pentadRelicIndex'];
        $pentadLocalPositions[] = $point['pentadLocalPosition'];
        $hubAvailabilityZones[] = $point['hubAvailabilityZone'];
        $zones[] = $point['zone'];
        $countable[] = $point['countable'] ? 1 : 0;
        $siteNumbers[] = $point['siteNumber'];
    }
    for ($i = 1, $length = count($offsets); $i < $length; $i++) { $offsets[$i] += $offsets[$i - 1]; }
    ksort($completions, SORT_NUMERIC);
    $specialCompletions = [];
    foreach ($completions as $pid => $completion) {
        if ($completion !== null) { $specialCompletions[] = ['pid' => $pid] + $completion; }
    }
    return ['schemaVersion' => 1, 'cellSizeCm' => $cellSizeCm,
        'originCellX' => $minX, 'originCellY' => $minY, 'width' => $width, 'height' => $height,
        'typeKeys' => $typeKeys, 'offsets' => $offsets, 'positions' => $positions,
        'isSpawn' => $isSpawn, 'pointIds' => $pointIds, 'pointRanks' => $pointRanks, 'puzzleIds' => $puzzleIds,
        'pentadRelicIndices' => $pentadRelicIndices, 'pentadLocalPositions' => $pentadLocalPositions,
        'hubAvailabilityZones' => $hubAvailabilityZones, 'zones' => $zones, 'countable' => $countable, 'specialCompletions' => $specialCompletions,
        'siteNumbers' => $siteNumbers] + BuildRadarLiveSites($points, $typeKeys, $randomSpotHub);
}

const RADAR_SITE_CELL_CM = 1000;
// Measured in game (2026-11-24): each of 486 spawned random-spot puzzles stood within
// 3.7 m of its site, and every other site allowing its type was 4.7 m or more away.
const RADAR_SITE_MATCH_CM = 400;

/**
 * Tables for live random-spot pins. Sites are indexed by site number (slot 0 unused);
 * a sorted cell-key list finds candidate sites near a spawned actor with Array_Find.
 */
function BuildRadarLiveSites(array $points, array $typeKeys, array $randomSpotHub): array
{
    $sites = [];
    foreach ($points as $point) {
        $number = $point['siteNumber'];
        if ($number <= 0) { continue; }
        if (isset($sites[$number]) && $sites[$number]['position'] !== $point['position']) {
            throw new InvalidArgumentException('One random-spot site number must name one position.');
        }
        $sites[$number] = ['position' => $point['position'], 'bits' => ($sites[$number]['bits'] ?? 0) | OverlayTypeBit($typeKeys[$point['type']])];
    }
    $count = $sites === [] ? 0 : max(array_keys($sites));
    $x = $y = $z = array_fill(0, $count + 1, 0.0);
    $bits = array_fill(0, $count + 1, 0);
    $cells = [];
    foreach ($sites as $number => $site) {
        $x[$number] = $site['position']['x']; $y[$number] = $site['position']['y']; $z[$number] = $site['position']['z'];
        $bits[$number] = $site['bits'];
        $cells[$number] = [(int)floor($site['position']['x'] / RADAR_SITE_CELL_CM), (int)floor($site['position']['y'] / RADAR_SITE_CELL_CM)];
    }
    $originX = $cells === [] ? 0 : min(array_column($cells, 0));
    $originY = $cells === [] ? 0 : min(array_column($cells, 1));
    $width = $cells === [] ? 1 : max(array_column($cells, 0)) - $originX + 1;
    $height = $cells === [] ? 1 : max(array_column($cells, 1)) - $originY + 1;
    if ($width * $height > 2147483647) { throw new InvalidArgumentException('Random-spot site bounds exceed int32 cell keys.'); }
    $lookup = [];
    foreach ($cells as $number => [$cx, $cy]) { $lookup[] = [($cx - $originX) * $height + ($cy - $originY), $number]; }
    sort($lookup);
    // Only puzzles whose type some site allows can ever be pinned.
    $siteTypes = [];
    foreach ($typeKeys as $type => $key) {
        foreach ($bits as $mask) { if (($mask & OverlayTypeBit($key)) !== 0) { $siteTypes[$key] = $type; break; } }
    }
    $live = [];
    foreach ($randomSpotHub as $row) {
        $pid = $row['pid'] ?? null;
        if (!is_int($pid) || $pid <= 0 || $pid > 2147483647) { throw new InvalidArgumentException('Random-spot Hub IDs must be positive int32 values.'); }
        if (isset($siteTypes[$row['ptype'] ?? ''])) { $live[$pid] = $siteTypes[$row['ptype']]; }
    }
    ksort($live, SORT_NUMERIC);
    return ['siteCellCm' => RADAR_SITE_CELL_CM, 'siteMatchCm' => RADAR_SITE_MATCH_CM,
        'siteOriginCellX' => $originX, 'siteOriginCellY' => $originY, 'siteWidth' => $width, 'siteHeight' => $height,
        'siteX' => $x, 'siteY' => $y, 'siteZ' => $z, 'siteTypeBits' => $bits,
        'siteLookupKeys' => array_column($lookup, 0), 'siteLookupNumbers' => array_column($lookup, 1),
        'liveIds' => array_keys($live), 'liveTypes' => array_values($live)];
}

/**
 * Reference for the runtime pass: each spawned random-spot puzzle claims the nearest
 * site that allows its type within RADAR_SITE_MATCH_CM. Returns siteNumber => [pid, type].
 */
function RadarMatchLiveSites(array $index, array $actors): array
{
    $occupants = [];
    $liveTypes = $index['liveIds'] === [] ? [] : array_combine($index['liveIds'], $index['liveTypes']);
    $keys = $index['siteLookupKeys'];
    foreach ($actors as $actor) {
        $pid = $actor['pid'];
        if (!isset($liveTypes[$pid])) { continue; }
        $type = $liveTypes[$pid];
        $bit = OverlayTypeBit($index['typeKeys'][$type]);
        $position = RadarPosition($actor);
        $best = 0;
        $bestDistance = RADAR_SITE_MATCH_CM * RADAR_SITE_MATCH_CM;
        $visited = [];
        foreach ([-1, 1] as $sx) {
            foreach ([-1, 1] as $sy) {
                $cx = (int)floor(($position['x'] + $sx * RADAR_SITE_MATCH_CM) / RADAR_SITE_CELL_CM) - $index['siteOriginCellX'];
                $cy = (int)floor(($position['y'] + $sy * RADAR_SITE_MATCH_CM) / RADAR_SITE_CELL_CM) - $index['siteOriginCellY'];
                if ($cx < 0 || $cy < 0 || $cx >= $index['siteWidth'] || $cy >= $index['siteHeight']) { continue; }
                $key = $cx * $index['siteHeight'] + $cy;
                if (isset($visited[$key])) { continue; }
                $visited[$key] = true;
                for ($j = array_search($key, $keys, true); $j !== false && $j < count($keys) && $keys[$j] === $key; $j++) {
                    $number = $index['siteLookupNumbers'][$j];
                    if (($index['siteTypeBits'][$number] & $bit) === 0) { continue; }
                    $dx = $index['siteX'][$number] - $position['x']; $dy = $index['siteY'][$number] - $position['y']; $dz = $index['siteZ'][$number] - $position['z'];
                    $distance = $dx * $dx + $dy * $dy + $dz * $dz;
                    if ($distance < $bestDistance) { $bestDistance = $distance; $best = $number; }
                }
            }
        }
        if ($best > 0) { $occupants[$best] = [$pid, $type]; }
    }
    ksort($occupants, SORT_NUMERIC);
    return $occupants;
}

/** A horizontal range of one cell width touches at most three contiguous rows. */
function RadarIndexRanges(array $index, array|object $player, string $selectedType, float $rangeCm = 20000): array
{
    $player = RadarPosition($player);
    if (!is_finite($rangeCm) || $rangeCm <= 0) { throw new InvalidArgumentException('Radar range must be positive and finite.'); }
    $type = array_search($selectedType, $index['typeKeys'], true);
    if ($type === false) { return []; }
    $width = $index['width'];
    $height = $index['height'];
    $cellSize = $index['cellSizeCm'];
    // Clamp before converting to int: a distant valid float32 player position
    // must return no cells, rather than overflow/wrap into a selected type.
    $rawMinX = floor(($player['x'] - $rangeCm) / $cellSize) - $index['originCellX'];
    $rawMaxX = floor(($player['x'] + $rangeCm) / $cellSize) - $index['originCellX'];
    $rawMinY = floor(($player['y'] - $rangeCm) / $cellSize) - $index['originCellY'];
    $rawMaxY = floor(($player['y'] + $rangeCm) / $cellSize) - $index['originCellY'];
    if ($rawMinX > $width - 1 || $rawMaxX < 0 || $rawMinY > $height - 1 || $rawMaxY < 0) { return []; }
    $minX = (int)max(0, $rawMinX);
    $maxX = (int)min($width - 1, $rawMaxX);
    $minY = (int)max(0, $rawMinY);
    $maxY = (int)min($height - 1, $rawMaxY);
    if ($minX > $maxX || $minY > $maxY) { return []; }
    $typeBase = $type * $width * $height;
    $ranges = [];
    for ($y = $minY; $y <= $maxY; $y++) {
        $first = $typeBase + $y * $width + $minX;
        $last = $typeBase + $y * $width + $maxX + 1;
        $start = $index['offsets'][$first];
        $end = $index['offsets'][$last];
        if ($start < $end) { $ranges[] = ['start' => $start, 'end' => $end]; }
    }
    return $ranges;
}

function BeginRadarQuery(array $index, array|object $player, string $selectedType, bool $includeSites = false, float $rangeCm = 20000, int $limit = 24, ?array $siteOccupants = null): array
{
    if ($limit < 1) { throw new InvalidArgumentException('Radar pin limit must be positive.'); }
    $player = RadarPosition($player);
    $ranges = RadarIndexRanges($index, $player, $selectedType, $rangeCm);
    return ['player' => $player, 'ranges' => $ranges, 'rangeCursor' => 0,
        'positionCursor' => $ranges[0]['start'] ?? 0, 'includeSites' => $includeSites, 'siteOccupants' => $siteOccupants,
        'type' => array_search($selectedType, $index['typeKeys'], true),
        'rangeSquared' => $rangeCm * $rangeCm, 'limit' => $limit, 'processed' => 0,
        'results' => [], 'done' => $ranges === []];
}

/**
 * Each call visits at most budget entries, including skipped possible sites.
 * Consumers discard the state and start a new query after selection changes.
 */
function StepRadarQuery(array $index, array &$state, int $budget = 128): bool
{
    if ($budget < 1) { throw new InvalidArgumentException('Radar query budget must be positive.'); }
    if ($state['done']) { return true; }
    for ($visited = 0; $visited < $budget; $visited++) {
        $i = $state['positionCursor']++;
        $state['processed']++;
        $point = $index['positions'][$i];
        $dx = $point['x'] - $state['player']['x'];
        $dy = $point['y'] - $state['player']['y'];
        $distanceSquared = $dx * $dx + $dy * $dy;
        // With live occupants supplied, a site shows only while a puzzle of the queried type stands on it.
        $siteVisible = !$index['isSpawn'][$i] || ($state['includeSites'] && ($state['siteOccupants'] === null
            || (($state['siteOccupants'][$index['siteNumbers'][$i] ?? 0][1] ?? -1) === $state['type'])));
        if ($siteVisible && $distanceSquared <= $state['rangeSquared']) {
            $state['results'][] = ['index' => $i, 'pointId' => $index['pointIds'][$i], 'pointRank' => $index['pointRanks'][$i],
                'position' => $point, 'isSpawn' => (bool)$index['isSpawn'][$i], 'distanceSquared' => $distanceSquared];
            usort($state['results'], static fn(array $a, array $b): int => ($a['distanceSquared'] <=> $b['distanceSquared']) ?: ($a['pointRank'] <=> $b['pointRank']));
            if (count($state['results']) > $state['limit']) { array_pop($state['results']); }
        }
        if ($state['positionCursor'] >= $state['ranges'][$state['rangeCursor']]['end']) {
            $state['rangeCursor']++;
            if ($state['rangeCursor'] >= count($state['ranges'])) { $state['done'] = true; break; }
            $state['positionCursor'] = $state['ranges'][$state['rangeCursor']]['start'];
        }
    }
    return $state['done'];
}

function QueryRadarIndex(array $index, array|object $player, string $selectedType, bool $includeSites = false, float $rangeCm = 20000, int $limit = 24, ?array $siteOccupants = null): array
{
    $state = BeginRadarQuery($index, $player, $selectedType, $includeSites, $rangeCm, $limit, $siteOccupants);
    while (!StepRadarQuery($index, $state)) { /* Reference implementation of budgeted runtime updates. */ }
    return $state['results'];
}

/** UE yaw zero faces +X; positive yaw turns toward +Y. Forward is screen-up. */
function ProjectRadarPoint(array|object $point, array|object $player, float $yawDegrees, float $rangeCm = 20000, float $radiusPixels = 96, float $heightThresholdCm = 1000): array
{
    $point = RadarPosition($point);
    $player = RadarPosition($player);
    foreach ([$yawDegrees, $rangeCm, $radiusPixels, $heightThresholdCm] as $number) {
        if (!is_finite($number)) { throw new InvalidArgumentException('Radar projection parameters must be finite.'); }
    }
    if ($rangeCm <= 0 || $radiusPixels <= 0 || $heightThresholdCm < 0) { throw new InvalidArgumentException('Invalid radar projection dimensions.'); }
    $dx = $point['x'] - $player['x'];
    $dy = $point['y'] - $player['y'];
    $dz = $point['z'] - $player['z'];
    $yaw = deg2rad($yawDegrees);
    return ['x' => (-$dx * sin($yaw) + $dy * cos($yaw)) * $radiusPixels / $rangeCm,
        'y' => -($dx * cos($yaw) + $dy * sin($yaw)) * $radiusPixels / $rangeCm,
        'height' => $dz > $heightThresholdCm ? 1 : ($dz < -$heightThresholdCm ? -1 : 0),
        'distanceMetres' => hypot($dx, $dy) / 100];
}

function RadarDataMain(array $argv): int
{
    $options = getopt('', ['render-manifest:', 'output:'], $next);
    if ($next !== count($argv) || !isset($options['render-manifest'], $options['output'])) {
        throw new InvalidArgumentException('Usage: php radar_data.php --render-manifest FILE --output FILE');
    }
    foreach ($options as $value) {
        if (!is_string($value) || $value === '') { throw new InvalidArgumentException('Options require one nonempty value.'); }
    }
    $input = realpath($options['render-manifest']);
    $parent = realpath(dirname($options['output']));
    if ($input === false || !is_file($input) || $parent === false || !is_dir($parent)) {
        throw new InvalidArgumentException('Input manifest and output parent directory must exist.');
    }
    $output = $parent . DIRECTORY_SEPARATOR . basename($options['output']);
    if (file_exists($output)) {
        if (!is_file($output)) { throw new InvalidArgumentException('Output must be a file.'); }
        $output = realpath($output);
    }
    $key = static fn(string $path): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path;
    if ($key($input) === $key($output)) { throw new InvalidArgumentException('Output must not replace the render manifest.'); }
    $manifest = json_decode(file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['schemaVersion'] ?? null) !== 1 || !is_array($manifest['renderPoints'] ?? null)) {
        throw new InvalidArgumentException('Expected a schemaVersion 1 render-point manifest.');
    }
    $index = BuildRadarIndex($manifest['renderPoints'], null, 20000, $manifest['randomSpotHub'] ?? []);
    require_once __DIR__ . '/markers.php';
    WriteMarkerJson($output, $index);
    printf("Indexed %d radar entries across %d puzzle types.\n", count($index['positions']), count($index['typeKeys']));
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(RadarDataMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
