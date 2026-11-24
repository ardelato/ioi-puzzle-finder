<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/radar_data.php';

function RadarCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function RadarTestPoint(string $id, float $x, float $y, float $z = 0, string $type = 'hiddenCube', bool $site = false, array $allowed = []): array
{
    return ['actorName' => $id, 'x' => $x, 'y' => $y, 'z' => $z, 'ptype' => $site ? 'spawnSite' : $type,
        'group' => $site ? 'spawn' : 'static',
        'tags' => array_map(static fn(string $key): string => 'SaveStats.Type.' . $key, $allowed)];
}

$catalog = ['hiddenCube' => [], 'matchbox' => [], 'logicGrid' => []];
$points = [
    RadarTestPoint('origin', 0, 0), RadarTestPoint('negative', -1, -1),
    RadarTestPoint('edge', 20000, 0), RadarTestPoint('outside', 20001, 0),
    RadarTestPoint('diagonalOutside', 15000, 15000), RadarTestPoint('otherType', 1, 0, 0, 'matchbox'),
    RadarTestPoint('site', 500, 0, 0, 'spawnSite', true, ['hiddenCube', 'matchbox', 'hiddenCube']),
    RadarTestPoint('wrongSite', 10, 0, 0, 'spawnSite', true, ['logicGrid']),
    RadarTestPoint('high', 0, 0, 50000),
];
$index = BuildRadarIndex($points, $catalog);
RadarCheck(count($index['positions']) === 10, 'Eligible sites must expand once for each distinct allowed type.');
RadarCheck($index['originCellX'] === -1 && $index['originCellY'] === -1, 'Negative coordinates must use floor, not truncation.');
RadarCheck(count($index['offsets']) === count($catalog) * $index['width'] * $index['height'] + 1, 'Offsets need the end sentinel.');
RadarCheck($index['offsets'][count($index['offsets']) - 1] === count($index['positions']), 'Sentinel must equal flat point count.');
RadarCheck(count($index['pointRanks']) === count($index['positions']), 'Every expanded point must retain its lexical ID rank.');
RadarCheck(count($index['puzzleIds']) === count($index['positions']) && array_unique($index['puzzleIds']) === [0], 'Legacy geometry fixtures without puzzle identity must use zero for every expanded entry.');
RadarCheck(count($index['pentadRelicIndices']) === count($index['positions']) && array_unique($index['pentadRelicIndices']) === [0], 'Non-Pentad points and legacy fixtures must have no active Pentad relic role.');
RadarCheck(count($index['pentadLocalPositions']) === count($index['positions']) && array_unique($index['pentadLocalPositions']) === [null], 'Missing local metadata must remain explicitly unknown for collected-relic matching.');
RadarCheck(count($index['hubAvailabilityZones']) === count($index['positions']) && array_unique($index['hubAvailabilityZones']) === [0], 'Legacy points and possible sites must default to ordinary availability.');
$idRanks = [];
foreach ($index['pointIds'] as $i => $id) {
    RadarCheck(!isset($idRanks[$id]) || $idRanks[$id] === $index['pointRanks'][$i], 'Expanded eligible-site entries must share one tie rank.');
    $idRanks[$id] = $index['pointRanks'][$i];
}
ksort($idRanks, SORT_STRING);
RadarCheck(array_values($idRanks) === range(0, count($points) - 1), 'Native integer ranks must preserve lexical ID tie order.');
$origin = ['x' => 0, 'y' => 0, 'z' => 0];
RadarCheck(array_column(QueryRadarIndex($index, $origin, 'hiddenCube'), 'pointId') === ['high', 'origin', 'negative', 'edge'], 'Horizontal circle must include its boundary, preserve vertical points, and exclude other types and optional sites.');
RadarCheck(array_column(QueryRadarIndex($index, $origin, 'hiddenCube', true, 20000, 3), 'pointId') === ['high', 'origin', 'negative'], 'Nearest cap must use horizontal distance and a stable tie order.');
RadarCheck(array_column(QueryRadarIndex($index, $origin, 'matchbox', true), 'pointId') === ['otherType', 'site'], 'A multi-type site must appear for another eligible type.');
RadarCheck(QueryRadarIndex($index, $origin, 'None', true) === [], 'No selected type must yield no pins.');
RadarCheck(QueryRadarIndex($index, ['x' => 10000000, 'y' => -10000000, 'z' => 0], 'hiddenCube', true) === [], 'Out-of-bounds rows must not wrap into another type.');
RadarCheck(QueryRadarIndex($index, ['x' => 3e38, 'y' => -3e38, 'z' => 0], 'hiddenCube', true) === [], 'Distant float32 coordinates must not overflow into the index.');
$rounded = BuildRadarIndex([RadarTestPoint('roundedCellEdge', 19999.9999, 0)], $catalog);
RadarCheck($rounded['positions'][0]['x'] === 20000.0 && array_column(QueryRadarIndex($rounded, ['x' => 40000, 'y' => 0, 'z' => 0], 'hiddenCube'), 'pointId') === ['roundedCellEdge'], 'Native float32 rounding must happen before spatial cell assignment.');
$state = BeginRadarQuery($index, $origin, 'hiddenCube', true);
while (!$state['done']) {
    $before = $state['processed'];
    StepRadarQuery($index, $state, 2);
    RadarCheck($state['processed'] - $before <= 2, 'A callback must respect its candidate budget.');
}
RadarCheck($state['results'] === QueryRadarIndex($index, $origin, 'hiddenCube', true), 'Budgeted and complete queries must agree.');

// Identity follows the point through type/cell sorting, rather than the input
// position. Paired endpoints share one PID; multi-type sites never gain one.
$identifiedPoints = [
    RadarTestPoint('pair-B', 21000, 0, 0, 'matchbox') + ['pid' => 42],
    RadarTestPoint('site-with-no-assignment', 50, 0, 0, 'spawnSite', true, ['hiddenCube', 'matchbox']) + ['pid' => 42],
    RadarTestPoint('cube', 0, 0) + ['pid' => 31415],
    RadarTestPoint('pair-A', -5, 0, 0, 'matchbox') + ['pid' => 42],
    RadarTestPoint('largest-native-pid', 10, 0, 0, 'logicGrid') + ['pid' => 2147483647],
];
$identified = BuildRadarIndex($identifiedPoints, $catalog);
$expectedPids = ['pair-A' => 42, 'pair-B' => 42, 'cube' => 31415, 'site-with-no-assignment' => 0, 'largest-native-pid' => 2147483647];
foreach ($identified['pointIds'] as $i => $id) {
    RadarCheck($identified['puzzleIds'][$i] === $expectedPids[$id], 'Cell/type ordering detached a point from its puzzle identity.');
}
RadarCheck(count(array_filter($identified['puzzleIds'], static fn(int $pid): bool => $pid === 42)) === 2, 'Both pair endpoints must share one exact PID without assigning it to a possible site.');
RadarCheck(BuildRadarIndex(array_reverse($identifiedPoints), $catalog) === $identified, 'Puzzle identities must not depend on source row order.');
$identifiedRoundtrip = json_decode(json_encode($identified, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
RadarCheck($identifiedRoundtrip['puzzleIds'] === $identified['puzzleIds'], 'Radar JSON roundtrip changed exact puzzle IDs or zero sentinels.');

// Exercise the real marker expansion: two Pentads share neither ID nor relic
// role, even when their six locations reorder across the spatial index.
require_once dirname(__DIR__) . '/markers.php';
require_once dirname(__DIR__) . '/inject_markers.php';
$pentadPuzzles = [];
foreach ([71, 72] as $pid) {
    $coords = [];
    foreach (range(0, 5) as $part) { $coords[] = (object)['x' => ($part - 3) * 20000 + $pid, 'y' => ($pid - 71) * 20000, 'z' => $part * 100]; }
    $localTransforms = [];
    foreach (range(1, 5) as $part) { $localTransforms['local-key-' . (6 - $part)] = (-1.125 * $part) . ',' . ($part + 0.5) . ',' . (-$pid) . '|0,90,0|2,3,4'; }
    $pentadPuzzles[] = (object)['pid' => $pid, 'ptype' => 'seek5', 'coords' => $coords, 'DuplicatedObjectOfType-Seek5HiddenObject' => (object)$localTransforms];
}
$pentadData = BuildOverlayData($pentadPuzzles, [], [72, 71], []);
$pentadPoints = ExpandOverlayMarkers(json_decode(json_encode(['schemaVersion' => 2] + $pentadData, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
$pentadCatalog = ['hiddenCube' => [], 'seek5' => []];
$pentadPoints[] = RadarTestPoint('untyped-pentad-role', 0, 0, 0, 'seek5') + ['pid' => 73];
$pentadPoints[] = RadarTestPoint('cube-with-part-metadata', 0, 0) + ['pid' => 74, 'pointIndex' => 1, 'role' => 'object'];
$pentadPoints[] = RadarTestPoint('possible-pentad-site', 0, 0, 0, 'spawnSite', true, ['seek5']) + ['pid' => 71, 'pointIndex' => 1, 'role' => 'object'];
$pentads = BuildRadarIndex($pentadPoints, $pentadCatalog);
RadarCheck(count($pentads['pentadRelicIndices']) === count($pentads['positions']), 'Pentad relic metadata must parallel every expanded radar entry.');
$expectedParts = [];
foreach ([71, 72] as $pid) {
    foreach (range(0, 5) as $part) { $expectedParts['SaveStatsMarker_' . $pid . '_' . $part] = [$pid, $part]; }
}
$expectedParts += ['untyped-pentad-role' => [73, 0], 'cube-with-part-metadata' => [74, 0], 'possible-pentad-site' => [0, 0]];
foreach ($pentads['pointIds'] as $i => $id) {
    RadarCheck([$pentads['puzzleIds'][$i], $pentads['pentadRelicIndices'][$i]] === $expectedParts[$id], 'Spatial sorting changed a Pentad location identity or admitted a non-relic.');
    [$pid, $part] = $expectedParts[$id];
    $expectedLocal = isset($pentadData['markers']->$pid) && $part > 0 ? ['x' => -1.125 * $part, 'y' => $part + 0.5, 'z' => (float)-$pid] : null;
    RadarCheck($pentads['pentadLocalPositions'][$i] === $expectedLocal, 'Pentad local translation must preserve raw dictionary value order and follow its relic through spatial sorting.');
    if ($expectedLocal !== null) {
        RadarCheck($pentads['positions'][$i] === ['x' => (float)(($part - 3) * 20000 + $pid), 'y' => (float)(($pid - 71) * 20000), 'z' => (float)($part * 100)], 'Local metadata extraction must preserve the existing world map positions.');
    }
}
foreach ([71, 72] as $pid) {
    $relicIds = [];
    foreach ($pentads['pointIds'] as $i => $id) {
        if ($pentads['puzzleIds'][$i] === $pid && $pentads['pentadRelicIndices'][$i] > 0) { $relicIds[] = $id; }
    }
    sort($relicIds, SORT_STRING);
    RadarCheck($relicIds === array_map(static fn(int $part): string => 'SaveStatsMarker_' . $pid . '_' . $part, range(1, 5)), 'An active Pentad must identify exactly its five relics and exclude its pillar and every other Pentad.');
}
RadarCheck(BuildRadarIndex(array_reverse($pentadPoints), $pentadCatalog) === $pentads, 'Pentad relic metadata must not depend on source order.');
$pentadRoundtrip = json_decode(json_encode($pentads, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
RadarCheck($pentadRoundtrip['pentadRelicIndices'] === $pentads['pentadRelicIndices'], 'Radar JSON roundtrip changed Pentad location indices.');
RadarCheck($pentadRoundtrip['pentadLocalPositions'] == $pentads['pentadLocalPositions'], 'Radar JSON roundtrip changed Pentad local translations or unknown sentinels.');
RadarCheck(count(QueryRadarIndex($pentads, $origin, 'seek5', false, 100000)) === 13, 'Adding focus metadata must preserve the normal Pentad overview including pillars.');
foreach ([[null, 'pillar'], [-1, 'object'], [6, 'object'], ['1', 'object'], [1.0, 'object'], [0, 'object'], [1, 'pillar'], [1, null]] as [$part, $role]) {
    try { BuildRadarIndex([RadarTestPoint('bad-part', 0, 0, 0, 'seek5') + ['pid' => 71, 'pointIndex' => $part, 'role' => $role]], $pentadCatalog); throw new RuntimeException('Invalid Pentad location metadata was accepted.'); }
    catch (InvalidArgumentException) { /* Expected validation failure. */ }
}
foreach ([null, 'bad', ['x' => INF, 'y' => 0, 'z' => 0], ['x' => 3.5e38, 'y' => 0, 'z' => 0], ['x' => 0, 'z' => 0]] as $badLocal) {
    try { BuildRadarIndex([RadarTestPoint('bad-local', 0, 0, 0, 'seek5') + ['pid' => 71, 'pointIndex' => 1, 'role' => 'object', 'pentadLocalPosition' => $badLocal]], $pentadCatalog); throw new RuntimeException('Invalid Pentad local coordinates were accepted.'); }
    catch (InvalidArgumentException) { /* Expected validation failure. */ }
}
foreach ([null, 'bad', '1,2|0,0,0|1,1,1', 'INF,2,3|0,0,0|1,1,1'] as $badTransform) {
    RadarCheck(OverlayLocalTranslation($badTransform) === null, 'Missing or invalid raw local transforms must remain unknown rather than invent a found-relic mapping.');
}

foreach ([[20000, 0, 0, 0, -96], [0, 20000, 0, 96, 0], [-20000, 0, 0, 0, 96], [0, -20000, 0, -96, 0], [0, 20000, 90, 0, -96]] as [$x, $y, $yaw, $screenX, $screenY]) {
    $projected = ProjectRadarPoint(['x' => $x, 'y' => $y, 'z' => 0], $origin, $yaw);
    RadarCheck(abs($projected['x'] - $screenX) < 0.00001 && abs($projected['y'] - $screenY) < 0.00001, 'Cardinal direction projection is incorrect.');
}
foreach ([-1001 => -1, -1000 => 0, 0 => 0, 1000 => 0, 1001 => 1] as $height => $expected) {
    RadarCheck(ProjectRadarPoint(['x' => 0, 'y' => 0, 'z' => $height], $origin, 0)['height'] === $expected, 'Above/below deadband boundary is incorrect.');
}

// Compare the spatial index with an independent all-points search, including a
// distant dense cluster that must not consume the local callback budget.
$many = [];
for ($i = 0; $i < 1500; $i++) {
    $many[] = RadarTestPoint(sprintf('near-%04d', $i), ($i % 41 - 20) * 1100, (intdiv($i, 41) - 18) * 1300, 0, $i % 3 ? 'hiddenCube' : 'matchbox');
    $many[] = RadarTestPoint(sprintf('far-%04d', $i), 250000 + $i, 180000 + $i);
}
$large = BuildRadarIndex($many, $catalog);
foreach ([[-15000, -15000], [-1, 0], [0, 0], [19999, 20000], [21000, -5000]] as [$px, $py]) {
    $expected = [];
    foreach ($many as $point) {
        $distance = ($point['x'] - $px) ** 2 + ($point['y'] - $py) ** 2;
        if ($point['ptype'] === 'hiddenCube' && $distance <= 400000000) { $expected[] = ['id' => $point['actorName'], 'd' => $distance]; }
    }
    usort($expected, static fn(array $a, array $b): int => ($a['d'] <=> $b['d']) ?: strcmp($a['id'], $b['id']));
    $query = BeginRadarQuery($large, ['x' => $px, 'y' => $py, 'z' => 0], 'hiddenCube');
    while (!StepRadarQuery($large, $query)) { /* Strict per-step bound exercised above. */ }
    RadarCheck(array_column($query['results'], 'pointId') === array_column(array_slice($expected, 0, 24), 'id'), 'Spatial nearest query differs from independent full scan.');
    RadarCheck($query['processed'] < 1500, 'Distant points must not be visited by a local query.');
}
$empty = BuildRadarIndex([], $catalog);
RadarCheck(QueryRadarIndex($empty, $origin, 'hiddenCube') === [], 'Empty snapshots must yield no pins.');
foreach ([null, 0, -1, 42.0, '42', 2147483648] as $badPid) {
    try { BuildRadarIndex([RadarTestPoint('bad-pid', 0, 0) + ['pid' => $badPid]], $catalog); throw new RuntimeException('Invalid exact puzzle ID was accepted.'); }
    catch (InvalidArgumentException) { /* Expected validation failure. */ }
}
foreach ([RadarTestPoint('bad', INF, 0), RadarTestPoint('bad', 3e38, 0), RadarTestPoint('bad', 0, 0, 0, 'unknown')] as $bad) {
    try { BuildRadarIndex([$bad], $catalog); throw new RuntimeException('Invalid radar point was accepted.'); }
    catch (InvalidArgumentException) { /* Expected validation failure. */ }
}
$zoned = BuildRadarIndex([RadarTestPoint('zone-a', 0, 0) + ['pid' => 501, 'zoneIndex' => 3], RadarTestPoint('zone-unknown', 100, 0) + ['pid' => 502]], $catalog);
RadarCheck($zoned['zones'] === [3, 0], 'Radar points must carry their zone for per-zone counts; unknown stays zero.');
try { BuildRadarIndex([RadarTestPoint('zone-bad', 0, 0) + ['zoneIndex' => 16]], $catalog); throw new RuntimeException('Out-of-range zone was accepted.'); }
catch (InvalidArgumentException) {}
// Live random-spot sites: tables, nearest-site matching, and the occupancy rule.
$site = static fn(string $id, int $number, float $x, float $y, array $allowed, float $z = 0): array => RadarTestPoint($id, $x, $y, $z, 'spawnSite', true, $allowed) + ['siteNumber' => $number];
$liveIndex = BuildRadarIndex([
    $site('rune-a', 1, 0, 0, ['logicGrid', 'hiddenCube']), $site('rune-b', 2, 102, 0, ['logicGrid']),
    $site('far', 3, -25000, 999, ['matchbox']), RadarTestPoint('fixed', 50, 50) + ['pid' => 9],
], $catalog, 20000, [['pid' => 700, 'ptype' => 'logicGrid'], ['pid' => 701, 'ptype' => 'matchbox'], ['pid' => 702, 'ptype' => 'hiddenCube'], ['pid' => 703, 'ptype' => 'seek5']]);
RadarCheck($liveIndex['siteTypeBits'] === [0, OverlayTypeBit('logicGrid') | OverlayTypeBit('hiddenCube'), OverlayTypeBit('logicGrid'), OverlayTypeBit('matchbox')], 'Site type masks are indexed by site number with slot 0 unused.');
RadarCheck($liveIndex['liveIds'] === [700, 701, 702] && $liveIndex['liveTypes'] === [2, 1, 0], 'Only random-spot IDs of a type some site allows are looked up, sorted, with radar type indices.');
$sortedKeys = $liveIndex['siteLookupKeys'];
sort($sortedKeys);
RadarCheck($sortedKeys === $liveIndex['siteLookupKeys'] && count($sortedKeys) === 3, 'Lookup keys must be sorted for the runtime Array_Find walk.');
RadarCheck(count($liveIndex['siteNumbers']) === count($liveIndex['positions']) && max($liveIndex['siteNumbers']) === 3 && in_array(0, $liveIndex['siteNumbers'], true), 'Every point carries its site number; fixed puzzles carry zero.');
$occupants = RadarMatchLiveSites($liveIndex, [
    ['pid' => 700, 'x' => 100, 'y' => 1, 'z' => 0],        // logic grid: nearest is rune-b (2 cm), not rune-a (100 cm)
    ['pid' => 702, 'x' => 260, 'y' => 0, 'z' => 0],        // hidden cube: rune-b does not allow it, rune-a is 260 cm away
    ['pid' => 701, 'x' => -25000, 'y' => 999, 'z' => 401], // 401 cm above its site: too far
    ['pid' => 9, 'x' => 0, 'y' => 0, 'z' => 0],            // fixed puzzle: never claims a site
    ['pid' => 703, 'x' => 0, 'y' => 0, 'z' => 0],          // a type no site allows
]);
RadarCheck($occupants === [1 => [702, 0], 2 => [700, 2]], 'Each live puzzle claims the nearest site allowing its type within 4 m.');
$edge = BuildRadarIndex([$site('edge-site', 1, 999, 999, ['logicGrid'])], $catalog, 20000, [['pid' => 5, 'ptype' => 'logicGrid']]);
RadarCheck(RadarMatchLiveSites($edge, [['pid' => 5, 'x' => 1001, 'y' => 1001, 'z' => 0]]) === [1 => [5, 2]], 'Matching must look across a cell edge.');
$liveQuery = static fn(?array $occ, string $type, bool $sites = true): array => array_column(QueryRadarIndex($liveIndex, ['x' => 0, 'y' => 0, 'z' => 0], $type, $sites, 20000, 24, $occ), 'pointId');
RadarCheck($liveQuery(null, 'logicGrid') === ['rune-a', 'rune-b'], 'Without live data the reference query keeps its old possible-site behaviour.');
RadarCheck($liveQuery($occupants, 'logicGrid') === ['rune-b'], 'With live data a site shows only for the type standing on it.');
RadarCheck($liveQuery($occupants, 'hiddenCube') === ['rune-a', 'fixed'], 'A multi-type site shows under the occupant type only.');
RadarCheck($liveQuery([], 'logicGrid') === [], 'An empty live pass hides every site.');
RadarCheck($liveQuery($occupants, 'logicGrid', false) === [], 'Sites stay off while the sites toggle is off.');
try { BuildRadarIndex([$site('bad-number', 0, 0, 0, ['logicGrid'])], $catalog); throw new RuntimeException('Site number zero was accepted.'); }
catch (InvalidArgumentException) {}
try { BuildRadarIndex([$site('one', 1, 0, 0, ['logicGrid']), $site('two', 1, 5, 0, ['logicGrid'])], $catalog); throw new RuntimeException('One site number at two positions was accepted.'); }
catch (InvalidArgumentException) {}
echo "Radar spatial index, budget, range, site, live-site, nearest and projection checks passed.\n";
