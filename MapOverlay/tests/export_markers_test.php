<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/markers.php';

function Check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function Entry(int $pid, string $family = 'static', string $ptype = 'logicGrid', array $coords = []): object
{
    return (object)[
        'pid' => (string)$pid, 'family' => $family, 'ptype' => $ptype,
        'zoneIndex' => '2', 'category' => 'LogicGrid', 'coords' => $coords,
    ];
}

function RunExporter(array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, dirname(__DIR__) . '/export_markers.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch exporter.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$point = (object)['x' => 10.5, 'y' => -20, 'z' => 300];
$second = (object)['x' => 999, 'y' => 999, 'z' => 999];
Check(OverlayPosition((object)['x' => 1e40, 'y' => 0, 'z' => 0]) === null, 'Coordinates outside UE4 float32 range must be rejected before export.');
$puzzles = [
    Entry(4, 'static', 'logicGrid', [$point]),
    Entry(2, 'hub', 'hiddenCube', [$point, $second]),
    Entry(1, 'static', 'logicGrid', [$point]),
    Entry(3, 'hub', 'logicGrid'),
    Entry(5, 'cluster', 'hiddenCube', [$point]),
    Entry(6),
    Entry(7, 'static', 'logicGrid', [(object)['x' => INF, 'y' => 0, 'z' => 0]]),
    Entry(8, 'static', 'logicGrid', [(object)['x' => 0, 'y' => 0]]),
    Entry(9, 'static', 'matchbox', [$point, $second]),
    Entry(10, 'hub', 'seek5', [$point, $second, $point, $second, $point, $second]),
    Entry(11, 'hub', 'racingRingCourse', [$point, $second, $point]),
    Entry(12, 'hub', 'racingBallCourse', [$point, $second]),
    Entry(13, 'hub', 'viewfinder', [$point]),
    Entry(14, 'hub', 'logicGrid', [$point]),
    Entry(16, 'static', 'matchbox', [$point]),
    Entry(17, 'static', 'logicGrid', [$point]),
];
$spawn = ['containerType' => 'Rune', 'zoneIndex' => '2', 'cluster' => '', 'x' => '100', 'y' => '-200', 'z' => '300', 'localID' => 'candidate-rune', 'ptypeList' => 'LogicGrid|logicGrid|memoryGrid'];
$data = BuildOverlayData($puzzles, [1, 1], [1, 4, 6, 7, 8, 9, 16, 17], [2, 3, 10, 11, 12, 13, 14, 15], [
    $spawn, $spawn,
    array_replace($spawn, ['zoneIndex' => '3']),
    array_replace($spawn, ['cluster' => 'Cong']),
    array_replace($spawn, ['containerType' => 'ClusterRune']),
    array_replace($spawn, ['ptypeList' => 'nonsense']),
    array_replace($spawn, ['x' => INF]),
]);
$markers = (array)$data['markers'];
$unlocated = (array)$data['unlocated'];
Check(array_keys($markers) === [2, 4, 9, 10, 11, 12, 13, 14, 17], 'Every located static/hub ID should remain, sorted by ID.');
Check(isset($markers[4], $markers[17]), 'Distinct puzzles at a shared tutorial location must not be deduplicated.');
Check($markers[2]['x'] === 10.5 && count($markers[2]['locations']) === 1, 'Ordinary puzzles must use one anchor.');
Check(count($markers[9]['locations']) === 2 && $markers[9]['locations'][1]['x'] === 999.0, 'Both matchbox endpoints must render.');
Check($markers[9]['locations'][1]['label'] === 'Box 2 of 2', 'Pair labels must distinguish endpoints.');
Check(count($markers[10]['locations']) === 6 && $markers[10]['locations'][0]['role'] === 'pillar' && $markers[10]['locations'][5]['role'] === 'object', 'Pentads must retain pillar and five objects.');
Check($markers[11]['geometry'] === 'route' && count($markers[11]['locations']) === 1 && count($markers[11]['routePoints']) === 3, 'Routes must retain points while rendering one anchor.');
Check($markers[11]['locations'][0]['role'] === 'start' && $markers[12]['locations'][0]['role'] === 'courseAnchor', 'Flow Orbs anchors must not claim to be the start.');
Check($markers[13]['locations'][0]['role'] === 'viewpoint' && str_contains($markers[13]['locations'][0]['label'], 'pickup location varies'), 'Sightseers must identify viewpoint semantics.');
Check($markers[2]['group'] === 'hub' && $markers[9]['group'] === 'static' && $markers[9]['iconKey'] === 'matchbox', 'Source and type identity must remain separate.');
Check($unlocated[3]['reason'] === 'dynamic_location_not_in_metadata' && $unlocated[6]['reason'] === 'missing_coordinates', 'Missing coordinate reasons must distinguish dynamic and static.');
Check($unlocated[7]['reason'] === 'invalid_coordinates' && $unlocated[16]['reason'] === 'invalid_location_count' && $unlocated[15]['reason'] === 'missing_metadata', 'Invalid/missing metadata must stay explicitly counted.');
Check($data['counts']['static'] === ['remaining' => 7, 'located' => 3, 'unlocated' => 4, 'renderPoints' => 4], 'Static counts must separate puzzle IDs from render points.');
Check($data['counts']['hub'] === ['remaining' => 8, 'located' => 6, 'unlocated' => 2, 'renderPoints' => 11], 'Hub counts must include dynamic puzzles without inventing positions.');
$sites = array_values((array)$data['spawnSites']);
Check(count($sites) === 1 && $sites[0]['allowedTypes'] === ['logicGrid'], 'Candidate sites must match a remaining unlocated hub type in the same zone.');
Check(!isset($sites[0]['pid']) && $sites[0]['visibleByDefault'] === false && str_contains($sites[0]['label'], 'current puzzle unknown'), 'Spawn sites must not impersonate active puzzle assignments.');
Check(preg_match('/^site-[a-f0-9]{16}$/', $sites[0]['siteId']) === 1, 'Spawn IDs must have stable hash syntax.');
Check((array)BuildOverlaySpawnSites([$spawn], $unlocated) === (array)$data['spawnSites'], 'Duplicate spawn rows must not change site identity.');
Check(
    (array)BuildOverlaySpawnSites([$spawn, array_replace($spawn, ['ptypeList' => 'MusicGrid'])], [
        ['group' => 'hub', 'ptype' => 'logicGrid', 'zoneIndex' => 2],
        ['group' => 'hub', 'ptype' => 'musicGrid', 'zoneIndex' => 2],
    ]) === (array)BuildOverlaySpawnSites([array_replace($spawn, ['ptypeList' => 'MusicGrid']), $spawn], [
        ['group' => 'hub', 'ptype' => 'logicGrid', 'zoneIndex' => 2],
        ['group' => 'hub', 'ptype' => 'musicGrid', 'zoneIndex' => 2],
    ]),
    'Duplicate container records must merge allowed types independently of row order.'
);
Check(count((array)BuildOverlaySpawnSites([$spawn], [])) === 0, 'No possible sites are needed when every hub puzzle is located.');
$types = GetOverlayTypes();
Check(count($types) === 28 && $types['hiddenCube']['iconFile'] === 'ptype_scaled/hiddenCube.png' && $types['loreFragment']['iconFile'] === null, 'Type catalog must expose exact image paths and explicit fallbacks.');
Check(count(array_unique(array_column($types, 'iconAsset'))) === 28, 'Icon asset identities must be unique.');
foreach ($types as $type) {
    Check(preg_match('/^SSIcon[0-9]{3}$/', $type['iconAsset']) === 1, 'Icon assets must use fixed nine-character names.');
}
$mysteryMap = [
    Entry(30, 'mystery', 'logicGrid', [$point]), Entry(21, 'mystery', 'logicGrid', [$point]),
    (object)((array)Entry(40, 'mystery', 'rosary', [$point]) + ['saveName' => 'BP_MegaRosary_C--fixture']),
    Entry(22, 'static', 'logicGrid', [$point]), Entry(23, 'internal', 'dungeon', [$point]),
];
Check(GetOverlayMysteryPids($mysteryMap) === [21, 30, 40], 'Every Mystery, and only Mysteries, must be included, sorted by ID.');
$mysteryData = BuildOverlayData($mysteryMap, [30], [22, ...GetOverlayMysteryPids($mysteryMap)], []);
Check(array_keys((array)$mysteryData['markers']) === [21, 22, 40], 'Solved Mysteries must be skipped like any other static puzzle.');
Check($mysteryData['markers']->{21}['label'] === 'Logic Grid #21 (Mystery)' && $mysteryData['markers']->{21}['family'] === 'mystery'
    && $mysteryData['markers']->{21}['group'] === 'static' && $mysteryData['markers']->{22}['label'] === 'Logic Grid #22',
    'Mysteries must join the fixed-location group and be labelled; other puzzles keep their labels.');
Check(count((array)BuildFixedMarkers([], [])) === 0, 'An empty marker set must remain an object.');
try {
    BuildFixedMarkers([Entry(1, 'static', 'logicGrid', [$point]), Entry(1, 'static', 'logicGrid', [$point])], []);
    throw new RuntimeException('Duplicate eligible puzzle IDs were accepted.');
} catch (RuntimeException $error) {
    Check(str_contains($error->getMessage(), 'Duplicate puzzle ID'), 'Unexpected duplicate-ID failure.');
}

$testDirectory = __DIR__ . '/.export-test-' . bin2hex(random_bytes(5));
mkdir($testDirectory);
try {
    $output = $testDirectory . '/markers.json';
    file_put_contents($output, 'previous output');
    try {
        WriteMarkerJson($output, ['bad' => INF]);
        throw new RuntimeException('Invalid JSON number was accepted.');
    } catch (JsonException $error) {
        Check(file_get_contents($output) === 'previous output', 'An encoding failure clobbered existing output.');
    }
    WriteMarkerJson($output, ['markers' => (object)[]]);
    Check(file_get_contents($output) === "{\"markers\":{}}\n", 'Empty marker export must encode as an object.');

    $options = getopt('', ['savestats-dir:', 'puzzle-database-json:']);
    if (isset($options['savestats-dir'])) {
        $saveStats = realpath($options['savestats-dir']);
        Check($saveStats !== false, 'SaveStats directory does not exist.');
        $beforeEntries = scandir($saveStats);
        $metadataBefore = hash_file('sha256', $saveStats . '/media/data/megatable_v3.csv');
        $record = function (int $pid, bool $solved): object {
            return (object)['Struct' => (object)[
                'PuzzleId_0' => (object)['Int' => (object)['value' => $pid]],
                'bSolved_0' => (object)['Bool' => (object)['value' => $solved]],
                'BestScore_0' => (object)['Int' => (object)['value' => 0]],
                'LastSolveTimestamp_0' => (object)['Int' => (object)['value' => 0]],
                'LeaderboardTime_0' => (object)['Float' => (object)['value' => 0.0]],
                'MiscStatus_0' => (object)['Str' => (object)['value' => '']],
            ]];
        };
        $save = (object)['root' => (object)[
            'save_game_type' => '/Script/IslandsofInsight.SophiaSaveGame',
            'properties' => (object)[
                'PuzzleStatuses_0' => (object)['Array' => (object)['value' => (object)['Struct' => (object)['value' => [$record(5594, true), $record(5595, false)]]]]],
                'Unlockables_0' => (object)['Array' => (object)['value' => (object)['Struct' => (object)['value' => []]]]],
            ],
        ]];
        $fixture = $testDirectory . '/snapshot.json';
        file_put_contents($fixture, json_encode($save, JSON_THROW_ON_ERROR));
        $fixtureBefore = hash_file('sha256', $fixture);
        $arguments = ['--savestats-dir', $saveStats, '--decoded-save', $fixture, '--output', $output];
        [$code, $stdout, $stderr] = RunExporter($arguments);
        Check($code === 0, "CLI integration failed: $stdout $stderr");
        Check($stderr === '', "CLI emitted warnings: $stderr");
        $document = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        Check($document['schemaVersion'] === 2, 'Exporter must emit schema version 2.');
        Check(!isset($document['markers'][5594]), 'Solved puzzle 5594 was exported.');
        Check(isset($document['markers'][5595]), 'Explicitly unsolved puzzle 5595 was excluded.');
        Check($document['markers'][5595]['x'] === -32698.21, 'Export does not use the known course anchor coordinate.');
        Check($document['counts']['static']['remaining'] === 2818 && $document['counts']['static']['located'] === 2818, 'Static export does not match SaveStats 1.3.1 fixed-family fixture total plus 21 Mysteries.');
        Check($document['counts']['hub']['remaining'] === 16202 && $document['counts']['hub']['located'] === 9718 && $document['counts']['hub']['unlocated'] === 6484, 'Hub export must preserve all remaining profile IDs, including unlocated puzzles.');
        Check(count($document['unlocated']) === 6484 && $document['counts']['spawn']['sites'] > 0 && $document['counts']['spawn']['visibleByDefault'] === false, 'Real metadata must expose unlocated IDs and optional possible sites.');
        Check($document['source']['saveSha256'] === $fixtureBefore, 'Snapshot hash does not match input.');
        Check(hash_file('sha256', $fixture) === $fixtureBefore, 'Exporter changed the save input.');
        $firstExport = file_get_contents($output);
        Check($document['source']['catalogMode'] === 'snapshot', 'The existing CLI must default to snapshot mode.');
        [$code, $stdout, $stderr] = RunExporter([...$arguments, '--catalog-mode', 'snapshot']);
        Check($code === 0 && $stderr === '' && file_get_contents($output) === $firstExport, 'Explicit snapshot mode must preserve the default snapshot output.');
        Check($document['pentadProgressCoverage']['verifiedRelics'] === 0 && $document['source']['puzzleDatabaseSha256'] === null, 'An exporter without native metadata must report unsupported collected-relic identity.');
        if (isset($options['puzzle-database-json'])) {
            $nativeSource = realpath($options['puzzle-database-json']);
            Check($nativeSource !== false, 'Native metadata input does not exist.');
            $nativeHash = hash_file('sha256', $nativeSource);
            [$code, $stdout, $stderr] = RunExporter([...$arguments, '--puzzle-database-json', $nativeSource]);
            Check($code === 0 && $stderr === '', "Native metadata CLI integration failed: $stdout $stderr");
            $supported = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            Check($supported['source']['puzzleDatabaseSha256'] === $nativeHash, 'Exporter did not bind the exact authoritative native source.');
            Check($supported['pentadProgressCoverage']['verifiedRelics'] > 0
                && $supported['pentadProgressCoverage']['verifiedRelics'] === $supported['pentadProgressCoverage']['locatedRelics']
                && $supported['pentadProgressCoverage']['unresolved'] === [], 'Real exporter CLI must verify all located Pentad relics from the actual CSV provider.');
            Check($supported['hubAvailabilityCoverage']['eligibleHubPuzzles'] === $supported['hubAvailabilityCoverage']['locatedHubPuzzles']
                && $supported['hubAvailabilityCoverage']['unknownHubPuzzles'] === 0, 'Actual fixed Hub markers must carry corroborated native World selection scope.');
            Check($supported['markers'][11118]['locations'][1]['pentadLocalPosition'] === ['x' => -1026.1875, 'y' => 348.171875, 'z' => 187.075195], 'Real11118 metadata was not enriched by the exporter CLI.');
            [$code] = RunExporter([...array_replace($arguments, [5 => $nativeSource]), '--puzzle-database-json', $nativeSource]);
            Check($code !== 0 && hash_file('sha256', $nativeSource) === $nativeHash, 'Exporter must not overwrite its native database source.');
            [$code] = RunExporter([...$arguments, '--puzzle-database-json', $fixture]);
            Check($code !== 0 && hash_file('sha256', $nativeSource) === $nativeHash, 'Malformed native metadata must be rejected without changing its source.');
            [$code, $stdout, $stderr] = RunExporter($arguments);
            Check($code === 0 && file_get_contents($output) === $firstExport, 'Optional native enrichment changed the base export contract.');
            echo 'Native metadata CLI integration passed (' . $supported['pentadProgressCoverage']['verifiedRelics'] . " verified relics).\n";
        }
        [$code, $stdout, $stderr] = RunExporter($arguments);
        Check($code === 0 && file_get_contents($output) === $firstExport, 'Identical snapshots must produce deterministic output.');
        file_put_contents($fixture, '{}');
        [$code, $stdout, $stderr] = RunExporter($arguments);
        Check($code !== 0 && file_get_contents($output) === $firstExport, 'Invalid save input clobbered existing output.');
        [$code, $stdout, $stderr] = RunExporter(['--savestats-dir', $saveStats, '--save', $fixture, '--output', $output]);
        Check($code !== 0 && file_get_contents($output) === $firstExport, 'A decoder failure clobbered existing output.');
        Check(count(scandir($testDirectory)) === 4, 'A failed export left temporary save files behind.');
        [$code, $stdout, $stderr] = RunExporter(['--savestats-dir', $saveStats, '--decoded-save', $fixture, '--output', $fixture]);
        Check($code !== 0 && file_get_contents($fixture) === '{}', 'Exporter allowed output to overwrite its save input.');
        [$code, $stdout, $stderr] = RunExporter(['--savestats-dir', $saveStats, '--decoded-save', $fixture, '--output', $saveStats . '/marker-test.json']);
        Check($code !== 0 && !file_exists($saveStats . '/marker-test.json'), 'Exporter allowed output into SaveStats.');
        Check(scandir($saveStats) === $beforeEntries, 'Exporter created files in SaveStats.');
        Check(hash_file('sha256', $saveStats . '/media/data/megatable_v3.csv') === $metadataBefore, 'Exporter modified SaveStats metadata.');
        echo 'CLI snapshot integration passed (' . count($document['markers']) . " markers).\n";
    }
    echo "Marker export tests passed.\n";
} finally {
    foreach (glob($testDirectory . '/*') as $path) {
        unlink($path);
    }
    rmdir($testDirectory);
}
