<?php

declare(strict_types=1);

require_once __DIR__ . '/tests/paths.php';

require_once __DIR__ . '/inject_markers.php';

function CheckOverlay(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ExpectOverlayFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException($message);
}

$types = GetOverlayTypes();
$nativeTypes = array_filter($types, fn(array $type): bool => $type['encyclopediaIconPackage'] !== null);
CheckOverlay(count($nativeTypes) === 24, 'Every native encyclopedia puzzle must have a filter icon.');
CheckOverlay(array_keys(array_diff_key($types, $nativeTypes)) === ['levelRestrictionVolume', 'loreFragment', 'monolithFragment', 'spawnSite'], 'Special entries must retain their generated marker-icon fallback.');
foreach ($nativeTypes as $type) {
    CheckOverlay(basename($type['encyclopediaIconPackage']) === $type['encyclopediaIconName'], 'Native texture object name differs from its package leaf.');
}
CheckOverlay($types['matchbox']['encyclopediaIconName'] === 'Matchvox', 'Matchbox must reference the native encyclopedia texture spelling.');
CheckOverlay($types['lockpick']['encyclopediaIconName'] === 'LockPick', 'Normalized lockpick type must reference the native Phasic Dial texture.');
CheckOverlay($types['hiddenCube']['iconAsset'] === 'SSIcon006', 'Native filter art must not shift existing map marker texture names.');

$base = json_decode(file_get_contents(OverlayTestDataPath('OutputJsons/Maps/RRMod_Objects.json')), false, 512, JSON_THROW_ON_ERROR);
$original = json_encode($base);
function OverlayTestRecord(int $pid, string $type, string $group, string $geometry, int $points, float $x = 0, float $y = 0, float $z = 0): object
{
    $locations = [];
    for ($i = 0; $i < $points; $i++) {
        $locations[] = (object)['index' => $i, 'role' => $i === 0 ? 'anchor' : 'object', 'label' => 'Point ' . ($i + 1), 'x' => $x + $i, 'y' => $y, 'z' => $z];
    }
    return (object)['pid' => $pid, 'ptype' => $type, 'iconKey' => $type, 'group' => $group, 'label' => GetOverlayTypes()[$type]['name'] . ' #' . $pid,
        'geometry' => $geometry, 'x' => $x, 'y' => $y, 'z' => $z, 'locations' => $locations];
}
$document = (object)['schemaVersion' => 2, 'markers' => (object)[
    '200' => OverlayTestRecord(200, 'hiddenCube', 'hub', 'point', 1, 500, -200, 30),
    '100' => OverlayTestRecord(100, 'hiddenRing', 'static', 'point', 1, -12.5, 0, 50.5),
], 'spawnSites' => new stdClass()];
$result = InjectOverlayMarkers($base, $document);
CheckOverlay(json_encode($base) === $original, 'Injection mutated the base input.');
CheckOverlay(count($result->Exports) === count($base->Exports) + 6, 'Expected actor, root and emitter per marker.');
foreach ($base->Exports as $i => $export) {
    if (($export->{'$type'} ?? '') !== 'UAssetAPI.ExportTypes.LevelExport, UAssetAPI') {
        CheckOverlay(json_encode($result->Exports[$i]) === json_encode($export), 'Existing world content changed.');
    }
}
$start = count($base->Exports);
$originalLevel = array_values(array_filter($base->Exports, fn(object $e): bool => ($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.LevelExport, UAssetAPI'))[0];
$newLevel = array_values(array_filter($result->Exports, fn(object $e): bool => ($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.LevelExport, UAssetAPI'))[0];
CheckOverlay(array_slice($newLevel->Actors, 0, count($originalLevel->Actors)) === $originalLevel->Actors, 'Original level actor references changed.');
CheckOverlay(array_slice($newLevel->Actors, -2) === [$start + 1, $start + 4], 'New marker actors were not registered with the level.');
$actor = $result->Exports[$start];
$root = $result->Exports[$start + 1];
$emitter = $result->Exports[$start + 2];
foreach (array_slice($result->Exports, $start) as $newExport) {
    $tail = $newExport->Extras;
    $tail = is_string($tail) ? $tail : ($tail->{'$value'} ?? null);
    CheckOverlay($tail === '', 'New native marker exports inherited an unrelated class serialization tail.');
    CheckOverlay(($newExport->ObjectGuid ?? null) === null && !($newExport->HasLeadingFourNullBytes ?? false), 'New marker export inherited object-specific serialization metadata.');
}
CheckOverlay($actor->ObjectName === 'SaveStatsMarker_100_0', 'Output marker order is not deterministic.');
CheckOverlay($actor->Data[0]->Value === $start + 2, 'Root reference is incorrect.');
CheckOverlay(count($actor->Data[1]->Value) === 2, 'Actor does not own both components.');
CheckOverlay($root->OuterIndex === $start + 1 && $emitter->OuterIndex === $start + 1, 'Component owner is incorrect.');
$location = $root->Data[0]->Value[0]->Value;
CheckOverlay($location->X === -12.5 && $location->Y === 0.0 && $location->Z === 50.5, 'World coordinates changed.');
$fields = array_column($emitter->Data[0]->Value, 'Value', 'Name');
CheckOverlay($fields['worldTex'] === 0 && $fields['mapTex'] < 0, 'Map-only texture settings are incorrect.');
CheckOverlay($fields['VisibleOnScreen'] === 0, 'On-screen HUD markers must be disabled.');
CheckOverlay($fields['VisibleOnMap'] === 0 && $fields['ShowInfoPanel'] === 1 && $fields['DrawNameOnMap'] === 0, 'Markers must start hidden while retaining hover-only presentation settings.');
CheckOverlay($fields['SupportsFastTravel'] === 0, 'Snapshot marker must not support fast travel.');
$textFields = array_column($emitter->Data[0]->Value, null, 'Name');
CheckOverlay($textFields['MarkerName']->{'$type'} === 'UAssetAPI.PropertyTypes.Objects.TextPropertyData, UAssetAPI', 'Hover name must serialize as FText.');
CheckOverlay(str_contains($textFields['MarkerName']->CultureInvariantString, '#100 | Static | Point 1'), 'Hover name lacks puzzle, source, or point label.');
CheckOverlay($textFields['FunctionText']->CultureInvariantString === '', 'Snapshot marker should not advertise a click action.');
$actorFields = array_column($actor->Data, null, 'Name');
CheckOverlay(array_column($actorFields['Tags']->Value, 'Value') === ['SaveStatsOverlay', 'SaveStats.Source.static', 'SaveStats.Puzzle.100', 'SaveStats.Type.hiddenRing'], 'Actor filter and live-completion identity tags are incorrect.');
$textureImport = $result->Imports[-$fields['mapTex'] - 1];
CheckOverlay($textureImport->ClassName === 'Texture2D' && $textureImport->ObjectName === GetOverlayTypes()['hiddenRing']['iconAsset'], 'Marker uses the wrong type icon.');
CheckOverlay(!str_contains(json_encode(array_slice($result->Exports, $start)), 'KrakenId'), 'Markers must not impersonate puzzles.');
$one = InjectOverlayMarkers($base, $document, 1);
CheckOverlay(count($one->Exports) === $start + 3, '--limit does not restrict injection.');
$empty = InjectOverlayMarkers($base, (object)['schemaVersion' => 2, 'markers' => new stdClass(), 'spawnSites' => new stdClass()]);
CheckOverlay(json_encode($empty) === $original, 'Empty snapshot should preserve the map exactly.');
ExpectOverlayFailure(fn() => InjectOverlayMarkers($result, $document), 'Duplicate injection was accepted.');
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, $document, 0), 'Zero limit was accepted.');
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, (object)['schemaVersion' => 99, 'markers' => new stdClass()]), 'Unknown schema was accepted.');
$bad = unserialize(serialize($document));
$bad->markers->{'100'}->x = '12';
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, $bad), 'String coordinate was accepted.');
$bad->markers->{'100'}->x = INF;
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, $bad), 'Infinite coordinate was accepted.');
$bad->markers->{'100'}->x = 1e40;
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, $bad), 'Coordinate overflowing UE4 float32 was accepted.');
$bad = unserialize(serialize($document));
$bad->markers->{'100'}->pid = 101;
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, $bad), 'Mismatched marker ID was accepted.');
$bad = unserialize(serialize($document));
unset($bad->markers->{'100'});
$bad->markers->{'2147483648'} = OverlayTestRecord(2147483648, 'hiddenCube', 'static', 'point', 1);
ExpectOverlayFailure(fn() => InjectOverlayMarkers($base, $bad), 'Puzzle ID overflowing native int32 was accepted.');

$multi = unserialize(serialize($document));
$multi->markers->{'300'} = OverlayTestRecord(300, 'matchbox', 'hub', 'pair', 2);
$multi->markers->{'400'} = OverlayTestRecord(400, 'seek5', 'hub', 'star', 6);
$multi->markers->{'400'}->locations[0]->role = 'pillar';
$multi->markers->{'500'} = OverlayTestRecord(500, 'racingBallCourse', 'hub', 'route', 3);
$site = (object)['siteId' => 'site-aaaaaaaaaaaaaaaa', 'ptype' => 'spawnSite', 'iconKey' => 'spawnSite', 'group' => 'spawn',
    'label' => 'Possible floor slab', 'allowedTypes' => ['logicGrid', 'match3'], 'x' => 100, 'y' => 200, 'z' => 300,
    'locations' => [(object)['index' => 0, 'role' => 'container', 'label' => 'Possible spawn location', 'x' => 100, 'y' => 200, 'z' => 300]]];
$multi->spawnSites->{$site->siteId} = $site;
$points = ExpandOverlayMarkers($multi);
CheckOverlay(count($points) === 12, 'Pair/star/route locations expanded incorrectly.');
CheckOverlay(count(array_filter($points, fn(object $p): bool => $p->visibleByDefault)) === 0, 'Loading must not enable any exact or possible-spawn markers before an explicit type selection.');
CheckOverlay(count(array_filter($points, fn(object $p): bool => ($p->pid ?? 0) === 300)) === 2, 'Matchbox must render both endpoints.');
CheckOverlay(count(array_filter($points, fn(object $p): bool => ($p->pid ?? 0) === 400)) === 6, 'Pentad must render pillar and all five objects.');
CheckOverlay(count(array_filter($points, fn(object $p): bool => ($p->pid ?? 0) === 500)) === 1, 'Course route must render one anchor.');
foreach ($points as $point) {
    $identityTags = array_values(array_filter($point->tags, static fn(string $tag): bool => str_starts_with($tag, 'SaveStats.Puzzle.')));
    CheckOverlay($identityTags === (isset($point->pid) ? ['SaveStats.Puzzle.' . $point->pid] : []), 'Every exact point needs its shared PID tag, and possible sites must have none.');
    $relicTags = array_values(array_filter($point->tags, static fn(string $tag): bool => str_starts_with($tag, 'SaveStats.PentadRelic')));
    CheckOverlay($relicTags === (($point->pid ?? 0) === 400 && $point->pointIndex > 0 ? ['SaveStats.PentadRelic', 'SaveStats.PentadRelic.' . $point->pointIndex] : []), 'Only explicit Pentad relic locations may receive the active-only map tags.');
}
$wrongRole = unserialize(serialize($multi));
$wrongRole->markers->{'400'}->locations[1]->role = 'pillar';
$wrongRolePoints = array_column(ExpandOverlayMarkers($wrongRole), null, 'actorName');
CheckOverlay(!in_array('SaveStats.PentadRelic', $wrongRolePoints['SaveStatsMarker_400_1']->tags, true), 'A non-object role must not become an active relic solely from its point index.');
$pointsRoundtrip = json_decode(json_encode($points, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
CheckOverlay(array_map(static fn(object $point): array => [$point->pid ?? 0, $point->tags], $pointsRoundtrip) === array_map(static fn(object $point): array => [$point->pid ?? 0, $point->tags], $points), 'Render manifest JSON roundtrip changed PID identities or tags.');
$spawnPoint = $points[count($points) - 1];
CheckOverlay($spawnPoint->actorName === 'SaveStatsSite_aaaaaaaaaaaaaaaa_0' && !$spawnPoint->visibleByDefault, 'Possible spawn actor identity/default visibility is incorrect.');
CheckOverlay($spawnPoint->tags === ['SaveStatsOverlay', 'SaveStats.Source.spawn', 'SaveStats.Site.1', 'SaveStats.Type.logicGrid', 'SaveStats.Type.match3'], 'Random-spot site tags must carry the site number and the allowed puzzle types.');
CheckOverlay(!isset($spawnPoint->pid) && $spawnPoint->siteNumber === 1 && str_contains($spawnPoint->label, 'Shown only while a random-spot puzzle is here'), 'A random-spot site must not claim a puzzle; the radar fills it in live.');
$second = unserialize(serialize($site));
$second->siteId = 'site-0000000000000000';
$twoSites = unserialize(serialize($multi));
$twoSites->spawnSites->{$second->siteId} = $second;
$siteNumbers = array_column(array_filter(ExpandOverlayMarkers($twoSites), static fn(object $p): bool => isset($p->siteNumber)), 'siteNumber', 'siteId');
CheckOverlay($siteNumbers === ['site-0000000000000000' => 1, 'site-aaaaaaaaaaaaaaaa' => 2], 'Site numbers must follow siteId order, from 1.');
$limitedSites = array_column(array_filter(ExpandOverlayMarkers($twoSites, 1), static fn(object $p): bool => isset($p->siteNumber)), 'siteNumber', 'siteId');
CheckOverlay($limitedSites === ['site-0000000000000000' => 1], 'A smoke limit must keep the numbers of the sites it keeps.');
$unlocatedDocument = unserialize(serialize($multi));
$unlocatedDocument->unlocated = (object)['12' => (object)['pid' => 12, 'ptype' => 'logicGrid', 'group' => 'hub'], '7' => (object)['pid' => 7, 'ptype' => 'match3', 'group' => 'hub'],
    '9' => (object)['pid' => 9, 'ptype' => 'logicGrid', 'group' => 'static'], '15' => (object)['pid' => 15, 'group' => 'hub', 'reason' => 'missing_metadata']];
CheckOverlay(OverlayRandomSpotHubTable($unlocatedDocument) === [['pid' => 7, 'ptype' => 'match3'], ['pid' => 12, 'ptype' => 'logicGrid']], 'The random-spot table lists unlocated Hub puzzles of known type, sorted by ID.');
CheckOverlay(count(ExpandOverlayMarkers($multi, 1)) === 2, 'Smoke limit must bound puzzle records and spawn sites separately.');
$ordinary = unserialize(serialize($document));
$ordinary->markers->{'100'}->locations[0]->label = '';
CheckOverlay(str_ends_with(ExpandOverlayMarkers($ordinary)[0]->label, '| Location'), 'Single-point hover should supply a useful location label.');
$multiResult = InjectOverlayMarkers($base, $multi);
foreach (array_slice($multiResult->Exports, count($base->Exports)) as $newExport) {
    if (!str_starts_with($newExport->ObjectName, 'SaveStatsMarker_300_') && !str_starts_with($newExport->ObjectName, 'SaveStatsMarker_400_')) { continue; }
    $expectedPid = str_starts_with($newExport->ObjectName, 'SaveStatsMarker_300_') ? 300 : 400;
    $nativeFields = array_column($newExport->Data, null, 'Name');
    CheckOverlay(in_array('SaveStats.Puzzle.' . $expectedPid, array_column($nativeFields['Tags']->Value, 'Value'), true), 'Injected paired/multipart actor lost the shared PID tag.');
}
$spawnEmitter = $multiResult->Exports[count($multiResult->Exports) - 1];
$spawnFields = array_column($spawnEmitter->Data[0]->Value, 'Value', 'Name');
CheckOverlay($spawnFields['VisibleOnMap'] === 0, 'Possible spawn sites must start disabled.');
if (in_array('--native-roundtrip', $argv, true)) {
    $roundtripDir = sys_get_temp_dir() . '/overlay-pids-' . bin2hex(random_bytes(6));
    CheckOverlay(mkdir($roundtripDir), 'Could not create native identity-check directory.');
    try {
        $sourceJson = $roundtripDir . '/markers.json';
        $documentJson = $roundtripDir . '/source-markers.json';
        $nativeMap = $roundtripDir . '/markers.umap';
        $roundtripJson = $roundtripDir . '/roundtrip.json';
        file_put_contents($documentJson, json_encode($multi, JSON_THROW_ON_ERROR));
        $converter = OverlayTestAssetGui();
        // Use the real CLI to bake generated FNames before native conversion.
        foreach ([[PHP_BINARY, __DIR__ . '/inject_markers.php', '--markers', $documentJson, '--base-map', OverlayTestDataPath('OutputJsons/Maps/RRMod_Objects.json'), '--output', $sourceJson],
            [$converter, 'fromjson', $sourceJson, $nativeMap], [$converter, 'tojson', $nativeMap, $roundtripJson, 'VER_UE4_27']] as $arguments) {
            $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            CheckOverlay(is_resource($process), 'Could not start native asset converter.');
            fclose($pipes[0]);
            $outputText = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            CheckOverlay(proc_close($process) === 0, 'Native identity roundtrip failed: ' . $outputText);
        }
        $nativeRoundtrip = json_decode(file_get_contents($roundtripJson), false, 512, JSON_THROW_ON_ERROR);
        $roundtripExports = array_column($nativeRoundtrip->Exports, null, 'ObjectName');
        foreach (array_slice($multiResult->Exports, count($base->Exports)) as $generated) {
            if (!str_starts_with($generated->ObjectName, 'SaveStatsMarker_') && !str_starts_with($generated->ObjectName, 'SaveStatsSite_')) { continue; }
            $before = array_column($generated->Data, null, 'Name');
            $after = array_column($roundtripExports[$generated->ObjectName]->Data, null, 'Name');
            CheckOverlay(array_column($before['Tags']->Value, 'Value') === array_column($after['Tags']->Value, 'Value'), 'Native roundtrip changed a puzzle or possible-site identity tag.');
        }
    } finally {
        foreach (glob($roundtripDir . '/*') as $file) { unlink($file); }
        rmdir($roundtripDir);
    }
}
$bad = unserialize(serialize($multi));
$bad->markers->{'300'}->locations[1]->index = 0;
ExpectOverlayFailure(fn() => ExpandOverlayMarkers($bad), 'Duplicate point indices were accepted.');
$bad = unserialize(serialize($multi));
$bad->markers->{'400'}->locations[5]->z = NAN;
ExpectOverlayFailure(fn() => ExpandOverlayMarkers($bad), 'Non-finite endpoint coordinate was accepted.');
$bad = unserialize(serialize($multi));
$bad->markers->{'300'}->locations = [$bad->markers->{'300'}->locations[0]];
ExpectOverlayFailure(fn() => ExpandOverlayMarkers($bad), 'Incomplete matchbox pair was accepted.');

$input = tempnam(sys_get_temp_dir(), 'overlay-input-');
$output = tempnam(sys_get_temp_dir(), 'overlay-output-');
try {
    file_put_contents($input, '{"schemaVersion":99,"markers":{}}');
    file_put_contents($output, 'preserve existing output');
    $process = proc_open([
        PHP_BINARY, __DIR__ . '/inject_markers.php', '--markers', $input,
        '--base-map', OverlayTestDataPath('OutputJsons/Maps/RRMod_Objects.json'), '--output', $output,
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    CheckOverlay(is_resource($process), 'Could not start CLI validation test.');
    foreach ($pipes as $pipe) {
        stream_get_contents($pipe);
        fclose($pipe);
    }
    CheckOverlay(proc_close($process) !== 0, 'CLI accepted an invalid marker document.');
    CheckOverlay(file_get_contents($output) === 'preserve existing output', 'Invalid input replaced the existing output.');
} finally {
    unlink($input);
    unlink($output);
}
echo "Marker injection checks passed.\n";
