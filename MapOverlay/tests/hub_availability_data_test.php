<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/hub_availability_data.php';
require_once dirname(__DIR__) . '/markers.php';
require_once dirname(__DIR__) . '/inject_markers.php';
require_once dirname(__DIR__) . '/radar_data.php';

function HubDataCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function HubTestNative(array $records, array $zones): object
{
    $world = []; $relevance = [];
    foreach ($records as $pid => $raw) { $world[] = [(object)['Value' => $pid], (object)['Value' => json_encode($raw, JSON_THROW_ON_ERROR)]]; }
    foreach ($zones as $name => $value) { $relevance[] = [(object)['Value' => $name], (object)['Value' => $value]]; }
    return (object)['Exports' => [(object)['Data' => [(object)['Name' => 'krakenIDToWorldPuzzleData', 'Value' => $world],
        (object)['Name' => 'zoneToSandboxProgressionRelevant', 'Value' => $relevance]]]]];
}

$rows = [];
foreach (range(1, 10) as $pid) {
    $rows[] = (object)['pid' => $pid, 'ptype' => 'hiddenCube', 'zoneIndex' => $pid === 7 ? 3 : 2,
        'coords' => [(object)['x' => (float)($pid * 100), 'y' => 0.0, 'z' => 0.0]]];
}
$overlay = BuildOverlayData($rows, [], [8], [1, 2, 3, 4, 5, 6, 7, 9, 10]);
$raw = [];
foreach (range(1, 10) as $pid) { $raw[$pid] = ['KID' => $pid, 'PuzzleType' => 'hiddenCube', 'Zone' => $pid === 7 ? 3 : 2, 'SpawnBehaviour' => 2, 'Disabled' => false]; }
$raw[2]['SpawnBehaviour'] = 0; $raw[3]['Disabled'] = true; $raw[4]['PuzzleType'] = 'hiddenRing'; $raw[5]['Zone'] = 3; unset($raw[6]);
$raw[9]['KID'] = 10; unset($raw[10]['Disabled']);
$input = json_encode($overlay, JSON_THROW_ON_ERROR);
$result = EnrichOverlayHubAvailability($overlay, HubTestNative($raw, ['EMainMapZoneName::Egypt' => true, 'EMainMapZoneName::Central' => false]));
HubDataCheck(json_encode($overlay, JSON_THROW_ON_ERROR) === $input, 'Hub classification must preserve its input data.');
HubDataCheck($result['markers']->{'1'}['hubAvailabilityZone'] === 2 && $result['markers']->{'8'}['hubAvailabilityZone'] === 0, 'Only a corroborated dynamic World Hub record may receive a nonzero zone.');
foreach ([2, 3, 4, 5, 6, 7, 9, 10] as $pid) { HubDataCheck($result['markers']->$pid['hubAvailabilityZone'] === 0, 'Unsupported native identity or selection scope must remain fail-visible.'); }
$unknown = array_column($result['hubAvailabilityCoverage']['unknown'], 'reason', 'pid');
HubDataCheck($unknown === [2 => 'not_dynamic_world_spawn', 3 => 'native_record_disabled_or_unknown', 4 => 'native_type_mismatch', 5 => 'native_zone_mismatch',
    6 => 'not_a_native_world_record', 7 => 'native_zone_relevance_unknown_or_false', 9 => 'native_identity_mismatch', 10 => 'native_record_disabled_or_unknown'], 'Unknown cases need explicit auditable classification reasons.');
$render = ExpandOverlayMarkers(json_decode(json_encode(['schemaVersion' => 2] + $result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
foreach ($render as $point) { HubDataCheck(in_array('SaveStats.HubZone.2', $point->tags, true) === ($point->pid === 1), 'Only verified Hub points may carry the native-availability zone tag.'); }
$index = BuildRadarIndex($render);
foreach ($index['pointIds'] as $n => $name) { HubDataCheck($index['hubAvailabilityZones'][$n] === ($name === 'SaveStatsMarker_1_0' ? 2 : 0), 'Availability zone must remain aligned after spatial/type sorting.'); }
$missing = EnrichOverlayHubAvailability($overlay);
HubDataCheck($missing['hubAvailabilityCoverage']['eligibleHubPuzzles'] === 0 && $missing['hubAvailabilityCoverage']['unknownHubPuzzles'] === 9, 'Missing native data must report unsupported Hub availability without hiding catalog entries.');

$options = getopt('', ['catalog-markers:', 'puzzle-database-json:']);
if (isset($options['catalog-markers'], $options['puzzle-database-json'])) {
    $catalog = json_decode(file_get_contents($options['catalog-markers']), true, 512, JSON_THROW_ON_ERROR);
    $catalog['markers'] = (object)$catalog['markers'];
    $before = $catalog;
    $native = json_decode(file_get_contents($options['puzzle-database-json']), false, 512, JSON_THROW_ON_ERROR);
    $expectedHub = array_filter((array)$catalog['markers'], static fn(array $record): bool => $record['group'] === 'hub');
    $expectedHubLocations = array_sum(array_map(static fn(array $record): int => count($record['locations']), $expectedHub));
    $classified = EnrichOverlayHubAvailability($catalog, $native);
    $coverage = $classified['hubAvailabilityCoverage'];
    HubDataCheck(count($expectedHub) > 0 && $coverage['locatedHubPuzzles'] === count($expectedHub) && $coverage['eligibleHubPuzzles'] === count($expectedHub)
        && $coverage['eligibleHubLocations'] === $expectedHubLocations && $coverage['unknownHubPuzzles'] === 0, 'Actual production catalog must classify every selected exact Hub identity and location from authoritative native World data.');
    HubDataCheck($classified['markers']->{'11118'}['hubAvailabilityZone'] === 2, 'The reported real11118 Hub pillar must use the corroborated native Verdant Glen selection scope.');
    foreach ($classified['markers'] as $pid => $record) {
        $original = $before['markers']->$pid;
        HubDataCheck($record['hubAvailabilityZone'] === ($original['group'] === 'hub' ? $original['zoneIndex'] : 0), 'Every real Hub marker must use its corroborated native zone; static markers must retain ordinary visibility.');
        unset($record['hubAvailabilityZone'], $original['hubAvailabilityZone']);
        HubDataCheck($record === $original, 'Availability metadata must preserve existing world coordinates and collected-relic bindings.');
    }
    $points = ExpandOverlayMarkers(json_decode(json_encode($classified, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
    $nativeIndex = BuildRadarIndex($points);
    HubDataCheck(count(array_filter($nativeIndex['hubAvailabilityZones'], static fn(int $zone): bool => $zone > 0)) === $coverage['eligibleHubLocations'], 'Real marker expansion and radar index must preserve every classified Hub location.');
    foreach ($points as $point) {
        if ($point->group === 'spawn' || $point->group === 'static') { HubDataCheck($point->hubAvailabilityZone === 0, 'Static puzzles and possible sites must retain ordinary visibility.'); }
    }
    echo json_encode($coverage, JSON_THROW_ON_ERROR), "\n";
}
echo "Hub availability metadata, scope, tags, sorting and fail-visible checks passed.\n";
