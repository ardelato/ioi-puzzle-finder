<?php

declare(strict_types=1);

function UniversalCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function UniversalRun(array $arguments): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/export_markers.php', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to launch exporter.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function UniversalRemoveFixture(string $directory): void
{
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isDir()) { UniversalRemoveFixture($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($directory);
}

$directory = __DIR__ . '/.universal-test-' . bin2hex(random_bytes(5));
mkdir($directory);
try {
    $provider = $directory . '/metadata';
    mkdir($provider . '/include', 0777, true);
    mkdir($provider . '/media/data', 0777, true);
    // A metadata-only provider deliberately has no savefile.php or uesave tool.
    // The real-provider integration below separately checks actual eligibility.
    file_put_contents($provider . '/include/file_io.php', '<?php function LoadCsv($path) { return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }');
    file_put_contents($provider . '/include/stats.php', <<<'PHP'
<?php
function GetPuzzleMap() { return json_decode(file_get_contents('media/data/megatable_v3.csv'), false, 512, JSON_THROW_ON_ERROR); }
function GetAllStaticPids() { return [1, 9]; }
function GetHubProfile() { return json_decode(file_get_contents('media/data/profileHub.json'), true, 512, JSON_THROW_ON_ERROR); }
function ExtractAllPids($profile) { return $profile['all']; }
PHP);
    $point = (object)['x' => 1.0, 'y' => 2.0, 'z' => 3.0];
    $entry = static fn(int $pid, string $group, string $type, array $coords): object =>
        (object)['pid' => $pid, 'family' => $group, 'ptype' => $type, 'coords' => $coords, 'zoneIndex' => 2];
    file_put_contents($provider . '/media/data/megatable_v3.csv', json_encode([
        $entry(1, 'static', 'logicGrid', [$point]), $entry(9, 'static', 'matchbox', [$point, $point]),
        $entry(2, 'hub', 'hiddenCube', [$point]), $entry(3, 'hub', 'logicGrid', []),
        $entry(10, 'hub', 'seek5', array_fill(0, 6, $point)), $entry(99, 'cluster', 'hiddenCube', [$point]),
        $entry(21, 'mystery', 'logicGrid', [$point]),
        (object)((array)$entry(40, 'mystery', 'rosary', [$point]) + ['saveName' => 'BP_MegaRosary_C--tester--ABC--1--BP_MegaRosary_C /Game/Maps/T.T:PersistentLevel.BP_MegaRosary_C_3']),
    ], JSON_THROW_ON_ERROR));
    file_put_contents($provider . '/media/data/profileHub.json', '{"all":[2,3,10]}');
    file_put_contents($provider . '/media/data/spawns.csv', json_encode([
        ['containerType' => 'Rune', 'zoneIndex' => 2, 'cluster' => '', 'x' => 10, 'y' => 20, 'z' => 30, 'localID' => 'fixture', 'ptypeList' => 'LogicGrid'],
    ], JSON_THROW_ON_ERROR));
    $output = $directory . '/universal.json';
    $arguments = ['--savestats-dir', $provider, '--catalog-mode', 'universal', '--output', $output];
    file_put_contents($provider . '/config.txt', 'save_path="missing-player-a.sav"');
    [$code, $stdout, $stderr] = UniversalRun($arguments);
    UniversalCheck($code === 0 && $stderr === '', "Metadata-only export failed: $stdout $stderr");
    $bytes = file_get_contents($output);
    $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    UniversalCheck($document['source']['catalogMode'] === 'universal' && $document['schemaVersion'] === 2, 'Universal mode must retain schema 2 and identify its scope.');
    UniversalCheck(array_keys($document['markers']) === [1, 2, 9, 10, 21, 40] && array_keys($document['unlocated']) === [3], 'Universal must preserve all eligible IDs and Mysteries, without adding unrelated metadata families.');
    UniversalCheck($document['counts']['static']['remaining'] === 4 && $document['counts']['hub']['remaining'] === 3, 'Universal counts must cover the full eligibility lists.');
    UniversalCheck($document['markers'][21]['group'] === 'static' && $document['markers'][21]['label'] === 'Logic Grid #21 (Mystery)'
        && $document['mysteryCoverage'] === ['included' => [21, 40]],
        'Universal must include Mysteries as labelled fixed-location puzzles.');
    UniversalCheck(count($document['markers'][9]['locations']) === 2 && $document['markers'][9]['locations'][1]['role'] === 'box'
        && count($document['markers'][10]['locations']) === 6 && $document['markers'][10]['locations'][0]['role'] === 'pillar'
        && $document['markers'][10]['locations'][5]['role'] === 'object', 'Universal must preserve matchbox/Pentad geometry and roles.');
    UniversalCheck($document['unlocated'][3]['reason'] === 'dynamic_location_not_in_metadata' && count($document['spawnSites']) === 1
        && !isset(array_values($document['spawnSites'])[0]['pid']), 'Universal must keep unknown Hub positions separate from optional possible sites.');
    UniversalCheck(!isset($document['source']['saveName'], $document['source']['saveSha256'], $document['source']['savedAtUtc']), 'Universal source must not include personal save provenance.');
    UniversalCheck(array_keys($document['source']) === ['catalogMode', 'metadataSha256', 'spawnMetadataSha256', 'hubProfileSha256', 'puzzleDatabaseSha256', 'scope'], 'Universal source must contain only catalog provenance.');
    // Malformed personal config would warn if parsed. A poisonous save parser
    // would throw if included. Neither may influence a universal catalog.
    file_put_contents($provider . '/config.txt', 'save_path="unterminated');
    file_put_contents($provider . '/include/savefile.php', '<?php throw new RuntimeException("Personal save parser was loaded");');
    [$code, $stdout, $stderr] = UniversalRun($arguments);
    UniversalCheck($code === 0 && $stderr === '' && file_get_contents($output) === $bytes, "Personal config/parser affected universal output: $stdout $stderr");
    foreach ([['--save', 'private-a.sav'], ['--decoded-save', 'private-b.json'], ['--catalog-mode', 'snapshot'], ['--catalog-mode', 'universal'],
        ['--catalog-mode', 'invalid'], ['--catalog-mode', ''], ['unexpected-positional']] as $extra) {
        [$code] = UniversalRun([...$arguments, ...$extra]);
        UniversalCheck($code !== 0 && file_get_contents($output) === $bytes, 'Invalid/repeated mode or personal save input must fail without changing output.');
    }
    foreach (['invalid', '', 'Universal'] as $mode) {
        [$code] = UniversalRun(['--savestats-dir', $provider, '--catalog-mode', $mode, '--output', $output]);
        UniversalCheck($code !== 0 && file_get_contents($output) === $bytes, 'Invalid catalog modes must be rejected.');
    }
    echo "Universal metadata-only/no-save tests passed.\n";

    $options = getopt('', ['savestats-dir:', 'puzzle-database-json:']);
    if (isset($options['savestats-dir'])) {
        $realOutput = $directory . '/real-universal.json';
        $realArguments = ['--savestats-dir', $options['savestats-dir'], '--catalog-mode', 'universal', '--output', $realOutput];
        if (isset($options['puzzle-database-json'])) { array_push($realArguments, '--puzzle-database-json', $options['puzzle-database-json']); }
        [$code, $stdout, $stderr] = UniversalRun($realArguments);
        UniversalCheck($code === 0 && $stderr === '', "Actual universal metadata export failed: $stdout $stderr");
        $universal = json_decode(file_get_contents($realOutput), true, 512, JSON_THROW_ON_ERROR);
        UniversalCheck($universal['counts']['static'] === ['remaining' => 2818, 'located' => 2818, 'unlocated' => 0, 'renderPoints' => 3066]
            && $universal['mysteryCoverage']['included'] === [1401, 1404, 3626, 14885, 14962, 16687, 19972, 20055, 20056, 20058, 20059, 20067, 20069, 20071, 20073, 21664, 21715, 40100, 40101, 40102, 40103]
            && $universal['counts']['hub'] === ['remaining' => 16203, 'located' => 9719, 'unlocated' => 6484, 'renderPoints' => 12579]
            && $universal['counts']['spawn']['sites'] === 3375, 'Actual universal eligibility/render counts changed.');
        UniversalCheck(isset($universal['markers'][5594], $universal['markers'][5595]), 'Universal must include puzzle 5594, which the solved snapshot fixture omits.');
        UniversalCheck($universal['specialCompletionCoverage'] === ['lorePuzzles' => 98, 'monolithFragments' => 59, 'total' => 157],
            'Universal must retain completion identities for every lore and monolith fragment.');
        UniversalCheck(!isset($universal['source']['saveName'], $universal['source']['saveSha256'], $universal['source']['savedAtUtc']), 'Actual universal export leaked personal save provenance.');
        if (isset($options['puzzle-database-json'])) {
            UniversalCheck($universal['pentadProgressCoverage']['verifiedPentads'] === 293 && $universal['pentadProgressCoverage']['verifiedRelics'] === 1465
                && $universal['pentadProgressCoverage']['unresolved'] === [], 'All universal Pentad identities must remain verified by authoritative native metadata.');
            UniversalCheck($universal['hubAvailabilityCoverage']['eligibleHubPuzzles'] === 9719 && $universal['hubAvailabilityCoverage']['unknownHubPuzzles'] === 0,
                'All located universal Hub puzzles must retain corroborated availability scope.');
        }
        $firstHash = hash_file('sha256', $realOutput);
        [$code, $stdout, $stderr] = UniversalRun($realArguments);
        UniversalCheck($code === 0 && $stderr === '' && hash_file('sha256', $realOutput) === $firstHash,
            'Repeated actual universal exports must be deterministic.');
        echo "Actual universal catalog integration passed (12,537 located puzzles including 21 Mysteries; 19,020 total render points).\n";
    }
} finally { UniversalRemoveFixture($directory); }
