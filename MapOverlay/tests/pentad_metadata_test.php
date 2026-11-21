<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/pentad_metadata.php';
require_once dirname(__DIR__) . '/markers.php';
require_once dirname(__DIR__) . '/radar_widget.php';

function PentadMetadataCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function PentadTestDatabase(array $records): object
{
    $values = [];
    foreach ($records as $pid => $raw) { $values[] = [(object)['Value' => (int)$pid], (object)['Value' => json_encode($raw, JSON_THROW_ON_ERROR)]]; }
    return (object)['Exports' => [(object)['Data' => [(object)['Name' => 'krakenIDToWorldPuzzleData', 'Value' => $values]]]]];
}

try {
    $options = getopt('', ['savestats-dir:', 'puzzle-database-json:', 'catalog-markers:', 'output-dir:']);
    foreach (['savestats-dir', 'puzzle-database-json', 'catalog-markers'] as $required) {
        if (!isset($options[$required]) || realpath($options[$required]) === false) { throw new InvalidArgumentException('Provide --savestats-dir --puzzle-database-json --catalog-markers'); }
    }
    $repo = dirname(__DIR__, 2);
    $saveStatsDir = realpath($options['savestats-dir']);
    $nativeFile = realpath($options['puzzle-database-json']);
    $catalogFile = realpath($options['catalog-markers']);
    $catalog = json_decode(file_get_contents($catalogFile), true, 512, JSON_THROW_ON_ERROR);
    $native = json_decode(file_get_contents($nativeFile), false, 512, JSON_THROW_ON_ERROR);
    $cwd = getcwd(); $includes = get_include_path();
    global $config;
    $config = ['base_dir' => str_replace('\\', '/', $saveStatsDir) . '/', 'timezone_offset' => '', 'time_format' => 24];
    set_include_path(dirname(__DIR__) . '/bootstrap' . PATH_SEPARATOR . $saveStatsDir . PATH_SEPARATOR . $includes);
    chdir($saveStatsDir);
    try {
        require_once $saveStatsDir . '/include/file_io.php';
        require_once $saveStatsDir . '/include/pjson_parse.php';
        $puzzleMap = GetPuzzleMap();
    } finally { chdir($cwd); set_include_path($includes); }
    $static = []; $hub = []; $expectedRelics = 0;
    $records = PentadNativeRecords($native);
    foreach ($catalog['markers'] as $marker) {
        if ($marker['ptype'] !== 'seek5') { continue; }
        if ($marker['group'] === 'static') { $static[] = $marker['pid']; } else { $hub[] = $marker['pid']; }
        PentadMetadataCheck(count($puzzleMap[$marker['pid']]->coords) === 6
            && count($records[$marker['pid']]['DuplicatedObjectOfType-Seek5HiddenObject'] ?? []) === 5,
            'Each selected real Pentad must have one CSV pillar and five independently sourced native relics.');
        $expectedRelics += 5;
        PentadMetadataCheck(!isset($puzzleMap[$marker['pid']]->{'DuplicatedObjectOfType-Seek5HiddenObject'}), 'This regression must consume the actual CSV provider without raw transforms.');
    }
    $overlay = BuildOverlayData($puzzleMap, [], $static, $hub);
    $original = json_encode($overlay, JSON_THROW_ON_ERROR);
    $enriched = EnrichOverlayPentadMetadata($overlay, $native);
    $coverage = $enriched['pentadProgressCoverage'];
    $expectedPentads = count($static) + count($hub);
    PentadMetadataCheck($expectedPentads > 0 && $coverage['locatedPentads'] === $expectedPentads && $coverage['verifiedPentads'] === $expectedPentads
        && $coverage['locatedRelics'] === $expectedRelics && $coverage['verifiedRelics'] === $expectedRelics && $coverage['unresolved'] === [], 'Actual installed provider must verify every selected Pentad and its five native relic identities.');
    $expectedLegacyIds = array_values(array_intersect([4748, 4751, 4752, 17198], [...$static, ...$hub]));
    PentadMetadataCheck($coverage['legacyProjectionIds'] === $expectedLegacyIds, 'Selected known legacy catalog projections must remain explicit diagnostics.');
    PentadMetadataCheck(json_encode($overlay, JSON_THROW_ON_ERROR) === $original, 'Enrichment must not mutate the input catalog.');
    $strip = static function (array $data): array {
        $data = unserialize(serialize($data));
        unset($data['pentadProgressCoverage']);
        foreach ($data['markers'] as &$record) { foreach ($record['locations'] as &$location) { unset($location['pentadLocalPosition']); } unset($location); } unset($record);
        return $data;
    };
    PentadMetadataCheck(json_encode($strip($enriched), JSON_THROW_ON_ERROR) === $original, 'Adding local identity must preserve every existing marker world position and other field.');
    $known11118 = [[-1026.1875,348.171875,187.075195],[200.410156,601.71875,413.188965],[260.410156,1980.914062,330.702637],[429.6875,575.90625,240.92334],[186.484375,-247.984375,-114.346191]];
    foreach ($known11118 as $n => $expected) {
        PentadMetadataCheck(array_values($enriched['markers']->{'11118'}['locations'][$n + 1]['pentadLocalPosition']) === $expected, 'Real11118 must bind its independently corroborated installed local translation.');
    }
    $document = json_decode(json_encode(['schemaVersion' => 2] + $enriched, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $render = ExpandOverlayMarkers($document);
    $index = BuildRadarIndex($render);
    PentadMetadataCheck(count(array_filter($index['pentadLocalPositions'], static fn(?array $v): bool => $v !== null)) === $expectedRelics, 'The real provider→marker→render→radar pipeline must retain every verified local position.');
    $reordered = $records;
    foreach ($reordered as &$raw) { $raw['DuplicatedObjectOfType-Seek5HiddenObject'] = array_reverse($raw['DuplicatedObjectOfType-Seek5HiddenObject'], true); } unset($raw);
    PentadMetadataCheck(json_encode(EnrichOverlayPentadMetadata($overlay, PentadTestDatabase($reordered)), JSON_THROW_ON_ERROR) === json_encode($enriched, JSON_THROW_ON_ERROR), 'Native dictionary order must not determine correspondence to catalog relics.');
    $missing = EnrichOverlayPentadMetadata($overlay);
    PentadMetadataCheck($missing['pentadProgressCoverage']['verifiedRelics'] === 0 && count($missing['pentadProgressCoverage']['unresolved']) === $expectedPentads
        && $missing['pentadProgressCoverage']['unresolved'][0]['reason'] === 'missing_native_source', 'Missing source must report zero verification and a named reason rather than claim collected hiding works.');
    $duplicate = $records[11118];
    $keys = array_keys($duplicate['DuplicatedObjectOfType-Seek5HiddenObject']);
    $duplicate['DuplicatedObjectOfType-Seek5HiddenObject'][$keys[1]] = $duplicate['DuplicatedObjectOfType-Seek5HiddenObject'][$keys[0]];
    $ambiguous = EnrichOverlayPentadMetadata($overlay, PentadTestDatabase([11118 => $duplicate]));
    $unresolved = array_column($ambiguous['pentadProgressCoverage']['unresolved'], 'reason', 'pid');
    PentadMetadataCheck(($unresolved[11118] ?? '') === 'no_unique_spatial_correspondence' && $ambiguous['pentadProgressCoverage']['verifiedRelics'] === 0, 'Ambiguous or incomplete spatial correspondence must leave relic progress unknown.');
    $moved = $records[11118]; $moved['ActorTransform'] = '0,0,0|0,0,0|1,1,1';
    $unmatched = EnrichOverlayPentadMetadata($overlay, PentadTestDatabase([11118 => $moved]));
    PentadMetadataCheck($unmatched['pentadProgressCoverage']['verifiedRelics'] === 0, 'A matching PID alone must not bind distant or moved raw geometry.');
    $symmetric = $records[11118]; $symmetric['ActorTransform'] = '0,0,0|0,0,90|1,1,1';
    $symmetric['DuplicatedObjectOfType-Seek5HiddenObject'] = ['a' => '0,0,0|0,0,0|1,1,1', 'b' => '0,1,0|0,0,0|1,1,1',
        'c' => '0,-1,0|0,0,0|1,1,1', 'd' => '0,0,1|0,0,0|1,1,1', 'e' => '0,0,-1|0,0,0|1,1,1'];
    $symmetricMarker = $overlay['markers']->{'11118'};
    foreach ([[0,0,0],[0,0,-1],[0,0,1],[0,1,0],[0,-1,0]] as $n => $world) {
        [$symmetricMarker['locations'][$n + 1]['x'], $symmetricMarker['locations'][$n + 1]['y'], $symmetricMarker['locations'][$n + 1]['z']] = $world;
    }
    $conflict = EnrichOverlayPentadMetadata(['markers' => (object)['11118' => $symmetricMarker]], PentadTestDatabase([11118 => $symmetric]));
    PentadMetadataCheck($conflict['pentadProgressCoverage']['verifiedRelics'] === 0
        && $conflict['pentadProgressCoverage']['unresolved'][0]['reason'] === 'conflicting_projection_identity', 'Two valid projection modes that disagree on relic identity must fail visibly rather than choose one.');
    $corrupt = $records[11118]; $corrupt['KID'] = 11119;
    try { PentadNativeRecords(PentadTestDatabase([11118 => $corrupt])); throw new RuntimeException('Mismatched raw KID was accepted.'); }
    catch (InvalidArgumentException) { /* Expected identity rejection. */ }
    if (isset($options['output-dir'])) {
        $output = realpath($options['output-dir']);
        if ($output === false || !is_dir($output)) { throw new InvalidArgumentException('Output directory must already exist.'); }
        file_put_contents($output . '/markers.json', json_encode(['schemaVersion' => 2] + $enriched, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($output . '/render-points.json', json_encode(['schemaVersion' => 1, 'renderPoints' => $render], JSON_THROW_ON_ERROR));
        file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
        file_put_contents($output . '/coverage.json', json_encode($coverage, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    echo json_encode(['passed' => true, 'coverage' => $coverage, 'provider' => $saveStatsDir . '/include/pjson_parse.php', 'nativeSha256' => hash_file('sha256', $nativeFile), 'realInputsWithoutLocalMetadata' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
