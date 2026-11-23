<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/completion_metadata.php';
require_once dirname(__DIR__) . '/markers.php';
require_once dirname(__DIR__) . '/inject_markers.php';
require_once dirname(__DIR__) . '/radar_data.php';
require_once dirname(__DIR__) . '/radar_special_completion.php';

function CompletionCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function CompletionReject(callable $operation, string $message): void
{
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException($message);
}

$point = (object)['x' => 1.0, 'y' => 2.0, 'z' => 3.0];
$entry = static fn(int $pid, string $type, array $extra = []): object => (object)([
    'pid' => $pid, 'ptype' => $type, 'family' => 'static', 'zoneIndex' => 4, 'coords' => [$point],
] + $extra);
$metadata = [$entry(41000, 'loreFragment', ['saveName' => 'A, Forgotten Name']),
    $entry(42400, 'monolithFragment', ['parent' => '10681']), $entry(42401, 'monolithFragment', ['parent' => 10681]),
    $entry(10681, 'obelisk'), $entry(17267, 'levelRestrictionVolume')];
$raw = BuildOverlayData($metadata, [], [41000, 42400, 42401, 17267], []);
$before = json_encode($raw, JSON_THROW_ON_ERROR);
$enriched = EnrichOverlayCompletionMetadata($raw, $metadata);
CompletionCheck(json_encode($raw, JSON_THROW_ON_ERROR) === $before, 'Completion enrichment must preserve its input.');
CompletionCheck($enriched['specialCompletionCoverage'] === ['lorePuzzles' => 1, 'monolithFragments' => 2, 'saveKeyPuzzles' => 0, 'total' => 3], 'Special coverage must count source identities.');
CompletionCheck($enriched['markers']->{'41000'}['completion'] === ['kind' => 'lore', 'key' => 'A Forgotten Name'], 'Lore keys must use SaveStats comma normalization.');
CompletionCheck($enriched['markers']->{'42400'}['completion'] === ['kind' => 'monolith', 'parentPid' => 10681, 'fragmentIndex' => 0]
    && $enriched['markers']->{'42401'}['completion']['fragmentIndex'] === 1, 'Fragment IDs must bind the explicit parent and zero-based Found index.');
CompletionCheck(!isset($enriched['markers']->{'17267'}['completion']), 'Entrance unlocks must retain ordinary native PID completion.');
$stripped = unserialize(serialize($enriched)); unset($stripped['specialCompletionCoverage']);
foreach ($stripped['markers'] as &$record) { unset($record['completion']); } unset($record);
CompletionCheck(json_encode($stripped, JSON_THROW_ON_ERROR) === $before, 'Completion descriptors must preserve coordinates, roles, counts and other metadata.');
$document = json_decode(json_encode(['schemaVersion' => 2] + $enriched, JSON_THROW_ON_ERROR));
$points = ExpandOverlayMarkers($document);
$index = BuildRadarIndex($points);
$expected = [['pid' => 41000, 'kind' => 'lore', 'key' => 'A Forgotten Name'],
    ['pid' => 42400, 'kind' => 'monolith', 'parentPid' => 10681, 'fragmentIndex' => 0],
    ['pid' => 42401, 'kind' => 'monolith', 'parentPid' => 10681, 'fragmentIndex' => 1]];
CompletionCheck($index['specialCompletions'] === $expected && BuildRadarIndex(array_reverse($points)) === $index, 'Special lookup must be unique, PID sorted and independent of spatial/source order.');
$duplicate = clone $points[1]; $duplicate->actorName = 'second-location';
CompletionCheck(BuildRadarIndex([...$points, $duplicate])['specialCompletions'] === $expected, 'Multiple locations must share one special completion lookup.');
$conflict = clone $points[1]; $conflict->actorName = 'conflicting-location'; $conflict->completion = (object)['kind' => 'lore', 'key' => 'Other'];
CompletionReject(fn() => BuildRadarIndex([...$points, $conflict]), 'Conflicting point descriptors were accepted.');
$missing = clone $points[1]; $missing->actorName = 'missing-descriptor'; unset($missing->completion);
CompletionReject(fn() => BuildRadarIndex([...$points, $missing]), 'Inconsistent missing completion descriptor was accepted.');
foreach ([['kind' => 'lore', 'key' => ''], ['kind' => 'lore', 'key' => 'comma,key'], ['kind' => 'lore', 'key' => 'key', 'extra' => true],
    ['kind' => 'unknown', 'key' => 'key'], null] as $descriptor) {
    CompletionReject(fn() => ValidateOverlayCompletion($descriptor, 41000, 'loreFragment', 'static'), 'Malformed lore descriptor was accepted.');
}
foreach ([['kind' => 'monolith', 'parentPid' => 10681, 'fragmentIndex' => -1], ['kind' => 'monolith', 'parentPid' => 10681, 'fragmentIndex' => 1],
    ['kind' => 'monolith', 'parentPid' => '10681', 'fragmentIndex' => 0]] as $descriptor) {
    CompletionReject(fn() => ValidateOverlayCompletion($descriptor, 42400, 'monolithFragment', 'static'), 'Invalid parent or fragment index was accepted.');
}
$badMetadata = unserialize(serialize($metadata)); unset($badMetadata[0]->saveName);
CompletionReject(fn() => EnrichOverlayCompletionMetadata($raw, $badMetadata), 'Missing lore metadata was accepted.');
$badMetadata = unserialize(serialize($metadata)); $badMetadata[1]->parent = 41000;
CompletionReject(fn() => EnrichOverlayCompletionMetadata($raw, $badMetadata), 'A non-obelisk fragment parent was accepted.');
$badMetadata = unserialize(serialize($metadata)); $badMetadata[3]->zoneIndex = 3;
CompletionReject(fn() => EnrichOverlayCompletionMetadata($raw, $badMetadata), 'A parent from another zone was accepted.');
// Save-key completion: Skydrops/Mystery matchboxes identified by a saved "<...>--<Class> <ObjectPath>" key.
$saveKey = 'rosary--tester--ABCDEF--1699621635--BP_ManualRosary_C /Game/Maps/Test_Puzzles.Test_Puzzles:PersistentLevel.BP_ManualRosary20';
CompletionCheck(IsSaveKey($saveKey), 'A real-shaped save key must be accepted.');
foreach (['no-separator', 'a--b', "a--BP_X_C /Game/Map\u{e9}.Map:PersistentLevel.X", 'a--BP_X_C /Other/Map.Map:PersistentLevel.X', str_repeat('x', 500) . '--BP_X_C /Game/M.M:P.X', null] as $badKey) {
    CompletionCheck(!IsSaveKey($badKey), 'Malformed, oversized or non-ASCII save keys must be rejected: ' . var_export($badKey, true));
}
$keyed = [$entry(40035, 'rosary', ['saveName' => $saveKey]), $entry(25248, 'rosary'),
    $entry(40102, 'matchbox', ['saveName' => 'BP_MatchboxExternal_C--tester--X--1--BP_MatchboxExternal_C /Game/Maps/T.T:PersistentLevel.BP_MatchboxExternal3']),
    $entry(40200, 'logicGrid', ['saveName' => 'not a puzzle key'])];
$keyed[2]->family = 'mystery';
$keyedData = EnrichOverlayCompletionMetadata(BuildOverlayData($keyed, [], [40035, 25248, 40102, 40200], []), $keyed);
CompletionCheck($keyedData['markers']->{'40035'}['completion'] === ['kind' => 'saveKey', 'key' => $saveKey]
    && $keyedData['markers']->{'40102'}['completion']['kind'] === 'saveKey' && $keyedData['markers']->{'40102'}['geometry'] === 'point',
    'Skydrops and single-anchor Mystery matchboxes with a save key must get save-key completion.');
CompletionCheck(!isset($keyedData['markers']->{'25248'}['completion']) && !isset($keyedData['markers']->{'40200'}['completion']),
    'Puzzles with a native puzzle ID, or other types with a saveName, keep ordinary completion.');
CompletionCheck($keyedData['specialCompletionCoverage'] === ['lorePuzzles' => 0, 'monolithFragments' => 0, 'saveKeyPuzzles' => 2, 'total' => 2], 'Save-key coverage must be counted.');
$keyedIndex = BuildRadarIndex(ExpandOverlayMarkers(json_decode(json_encode(['schemaVersion' => 2] + $keyedData, JSON_THROW_ON_ERROR))));
$native = RadarSpecialDescriptors($keyedIndex['specialCompletions']);
CompletionCheck($native[0] === ['pid' => 40035, 'kind' => 3, 'key' => $saveKey, 'parentPid' => 0, 'fragmentIndex' => -1],
    'Native save-key descriptors must carry the full saved key.');
foreach ([['kind' => 'saveKey', 'key' => 'bad'], ['kind' => 'saveKey', 'key' => $saveKey, 'extra' => 1], ['kind' => 'saveKey']] as $descriptor) {
    CompletionReject(fn() => ValidateOverlayCompletion($descriptor, 40035, 'rosary', 'static'), 'A malformed save-key descriptor was accepted.');
}
CompletionReject(fn() => ValidateOverlayCompletion(['kind' => 'saveKey', 'key' => $saveKey], 40035, 'logicGrid', 'static'), 'Save-key completion was accepted for an unsupported type.');
CompletionReject(fn() => RadarSpecialDescriptors([['pid' => 40035, 'kind' => 'saveKey', 'key' => 'bad']]), 'The radar accepted a malformed save key.');
$site = (object)['actorName' => 'site', 'ptype' => 'spawnSite', 'group' => 'spawn', 'x' => 0, 'y' => 0, 'z' => 0,
    'tags' => ['SaveStats.Type.loreFragment'], 'completion' => (object)['kind' => 'lore', 'key' => 'Key']];
CompletionReject(fn() => BuildRadarIndex([$site]), 'A possible site gained a special completion identity.');

$options = getopt('', ['catalog-markers:']);
if (isset($options['catalog-markers'])) {
    $catalog = json_decode(file_get_contents($options['catalog-markers']), false, 512, JSON_THROW_ON_ERROR);
    $lore = $fragments = $keys = 0;
    foreach ($catalog->markers as $record) {
        if ($record->ptype === 'loreFragment') { $lore++; CompletionCheck(($record->completion->kind ?? '') === 'lore', 'Real lore descriptor missing.'); }
        elseif ($record->ptype === 'monolithFragment') { $fragments++; CompletionCheck(($record->completion->kind ?? '') === 'monolith', 'Real fragment descriptor missing.'); }
        elseif (($record->completion->kind ?? '') === 'saveKey') { $keys++; CompletionCheck(in_array($record->ptype, ['rosary', 'matchbox'], true), 'Save-key descriptor on an unsupported type.'); }
        else { CompletionCheck(!property_exists($record, 'completion'), 'An ordinary puzzle gained a special completion descriptor.'); }
    }
    $render = ExpandOverlayMarkers($catalog); $actual = BuildRadarIndex($render);
    CompletionCheck(count($actual['specialCompletions']) === $lore + $fragments + $keys, 'Real marker expansion/radar index lost special descriptors.');
    if (($catalog->source->catalogMode ?? '') === 'universal') {
        CompletionCheck($lore === 98 && $fragments === 59 && $keys === 75 && count($actual['specialCompletions']) === 232, 'Full universal catalog must cover all98 lore, 59 fragments and 75 save-key puzzles.');
    }
    echo "Actual special completion pipeline passed ($lore lore; $fragments fragments; $keys save-key).\n";
}
echo "Special completion metadata, descriptor validation and radar lookup checks passed.\n";
