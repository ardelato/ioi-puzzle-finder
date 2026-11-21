<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/hub_availability_world_fixture.php';

/** Full production world plus isolated in-memory progress at universal scale. */
function BuildUniversalCatalogRuntimeFixture(string $output, string $build, string $personalBuild): array
{
    $manifest = BuildHubAvailabilityWorldFixture($output, $build);
    $catalog = json_decode(file_get_contents($build . '/markers.json'), true, 512, JSON_THROW_ON_ERROR);
    $personal = json_decode(file_get_contents($personalBuild . '/markers.json'), true, 512, JSON_THROW_ON_ERROR);
    $index = json_decode(file_get_contents($build . '/radar-data.json'), true, 512, JSON_THROW_ON_ERROR);
    $points = json_decode(file_get_contents($build . '/render-points.json'), true, 512, JSON_THROW_ON_ERROR)['renderPoints'];
    if (($catalog['source']['catalogMode'] ?? '') !== 'universal') { throw new RuntimeException('Universal production input required.'); }
    // Fake lore/fragment IDs use different native authority and have a separate
    // copied-save proof. Injecting fake IDs into krakenIDToSolvedStatus would
    // manufacture a path that real saves never populate.
    $ids = [];
    foreach (['markers', 'unlocated'] as $collection) {
        foreach ($catalog[$collection] as $pid => $record) {
            if (!in_array($record['ptype'], ['loreFragment', 'monolithFragment'], true)) { $ids[] = (int)$pid; }
        }
    }
    sort($ids, SORT_NUMERIC);
    $controls = [];
    foreach ($points as $point) {
        if ($point['group'] !== 'static' || $point['pointIndex'] !== 0 || in_array($point['ptype'], ['seek5', 'loreFragment', 'monolithFragment'], true)
            || isset($personal['markers'][$point['pid']]) || isset($controls[$point['ptype']])) { continue; }
        $point['radarIndex'] = array_search($point['actorName'], $index['pointIds'], true);
        if ($point['radarIndex'] === false) { throw new RuntimeException('Control missing from production index.'); }
        $controls[$point['ptype']] = $point;
        if (count($controls) === 4) { break; }
    }
    if (count(array_filter($controls, static fn(array $p): bool => $p['pid'] % 2 !== 0)) === 0) {
        foreach ($points as $point) {
            if ($point['group'] !== 'static' || $point['pointIndex'] !== 0 || $point['pid'] % 2 === 0
                || in_array($point['ptype'], ['seek5', 'loreFragment', 'monolithFragment'], true) || isset($personal['markers'][$point['pid']])) { continue; }
            $point['radarIndex'] = array_search($point['actorName'], $index['pointIds'], true);
            if ($point['radarIndex'] === false) { throw new RuntimeException('Odd control missing from production index.'); }
            $controls[$point['ptype'] . '.odd'] = $point; break;
        }
    }
    if (count($controls) < 2) { throw new RuntimeException('Need at least two static types omitted from personal catalog.'); }
    if (count(array_filter($controls, static fn(array $p): bool => $p['pid'] % 2 !== 0)) === 0) { throw new RuntimeException('Need an odd omitted control that remains visible in mixed progress.'); }
    $repo = dirname(__DIR__, 2);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $asset = json_decode(file_get_contents($output . '/WBP_SSAvailabilityProof.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base); $b->asset = $asset; $b->class = $asset->Exports[0]; $b->default = $asset->Exports[1];
    foreach ($b->class->FuncMap as [$name, $fn]) { $b->functions[$name] = $fn; }
    foreach ($asset->Imports as $n => $import) {
        if ($import->ClassName === 'Class' || $import->ClassName === 'ScriptStruct') { $b->native[$import->ObjectName] = -$n - 1; }
    }
    $classes = [];
    foreach ($asset->Imports as $n => $import) {
        if ($import->ObjectName === 'WBP_SSOverlayRadar_C') { $classes['Radar'] = -$n - 1; }
        if ($import->ObjectName === 'WBP_LargeMap_C') { $classes['Map'] = -$n - 1; }
    }
    foreach (['GISProgression' => 'Class', 'PlayerProgressionData' => 'ScriptStruct', 'PuzzleSolveData' => 'ScriptStruct'] as $type => $kind) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', $kind);
    }
    $b->native['BlueprintMapLibrary'] = OverlayImport($b->asset, 'BlueprintMapLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->objectField('Progression', $b->native['GISProgression']);
    $b->arrayField('Int', 'UniversalIds', $ids);
    foreach (['UniversalStage', 'UniversalChecked'] as $field) { $b->scalar('Int', $field, 0); }
    foreach (['SolvedSeed' => true, 'UnsolvedSeed' => false] as $field => $value) {
        $b->structField($field, 'PuzzleSolveData', 40, [OverlayProperty('Bool', 'bSolved', $value ? 1 : 0), OverlayProperty('Bool', 'bReset', 0)]);
    }
    foreach (['UniversalProof', 'UniversalProgressStage'] as $function) { $b->reserve($function); }
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $i = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $f = static fn(float $v): object => FilterExpr('FloatConst', ['Value' => $v]);
    $truth = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $str = static fn(string $v): object => FilterExpr('StringConst', ['Value' => $v]);
    $name = static fn(string $v): object => FilterExpr('NameConst', ['Value' => $v]);
    $math = static fn(string $n, object ...$v): object => $b->call('KismetMathLibrary', $n, $v);
    $array = static fn(string $n, object ...$v): object => $b->call('KismetArrayLibrary', $n, $v);
    $property = static fn(object $o, int $owner, string $n): object => RadarContext($o, FilterVariable($owner, $n, true), FilterPointer($owner, $n));
    $get = static fn(string $n): object => $property($s('Radar'), $classes['Radar'], $n);
    $mapGet = static fn(string $n): object => $property($s('Map'), $classes['Map'], $n);
    $put = static fn(object $o, int $owner, string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($owner, $n), 'Variable' => $property($o, $owner, $n), 'Expression' => $v]);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $s($n), 'Expression' => $v]);
    $call = static fn(string $o, string $n, array $v = [], ?object $ptr = null): object => RadarContext($s($o), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $n, 'Parameters' => $v]), $ptr);
    $jump = static fn(string $label): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $label]);
    $unless = static fn(object $condition, string $label): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $label, 'BooleanExpression' => $condition]);
    $checks = $manifest['checkDescriptions'];
    $assert = static function(object $condition, string $description) use (&$checks, $b, $i, $unless): object {
        $checks[] = $description;
        return $unless(FilterCall($b->functions['ProbeCheck'], [$condition, $i(count($checks))], 'LocalFinalFunction'), 'Fail');
    };
    $progress = static fn(): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'),
        'StructExpression' => $property($s('Progression'), $b->native['GISProgression'], 'PlayerProgression')]);
    $fn = $b->functions['UniversalProgressStage'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $stage = [$let('Index', $i(0)), ['label' => 'Seed'],
        $unless($math('Less_IntInt', $l('Index'), $array('Array_Length', $s('UniversalIds'))), 'SeedDone'),
        $array('Array_Get', $s('UniversalIds'), $l('Index'), $l('Pid')),
        FilterExpr('LetBool', ['VariableExpression' => $l('Expected'), 'AssignmentExpression' => $math('BooleanOR',
            $math('EqualEqual_IntInt', $s('UniversalStage'), $i(2)), $math('BooleanAND', $math('EqualEqual_IntInt', $s('UniversalStage'), $i(1)), $math('EqualEqual_IntInt', $math('Percent_IntInt', $l('Pid'), $i(2)), $i(0))))]),
        $unless($l('Expected'), 'Unsolved'), $b->call('BlueprintMapLibrary', 'Map_Add', [$progress(), $l('Pid'), $s('SolvedSeed')]), $jump('Query'),
        ['label' => 'Unsolved'], $b->call('BlueprintMapLibrary', 'Map_Add', [$progress(), $l('Pid'), $s('UnsolvedSeed')]), ['label' => 'Query'],
        $assert($math('EqualEqual_BoolBool', $call('Radar', 'SSRadarIsSolved', [$l('Pid')], FilterPointer($fn, 'Boolean')), $l('Expected')), 'every ordinary universal eligible ID reads current native progress at each fresh/mixed/complete stage'),
        $set('UniversalChecked', $math('Add_IntInt', $s('UniversalChecked'), $i(1))), $let('Index', $math('Add_IntInt', $l('Index'), $i(1))), $jump('Seed'), ['label' => 'SeedDone']];
    foreach ($controls as $point) {
        $expected = static fn() => $math('BooleanOR', $math('EqualEqual_IntInt', $s('UniversalStage'), $i(2)),
            $math('BooleanAND', $math('EqualEqual_IntInt', $s('UniversalStage'), $i(1)), $truth($point['pid'] % 2 === 0)));
        $stage[] = $set('ProofType', $str($point['ptype']));
        $stage[] = $put($s('Map'), $classes['Map'], 'SSOverlaySelectedTag', $name('SaveStats.Type.' . $point['ptype']));
        $stage[] = $call('Map', 'SSOverlayRefresh');
        $stage[] = $assert(FilterCall($b->functions['CheckControl'], [$i($point['pid']), $i(0), $i($point['radarIndex']), $str($point['actorName']), $f($point['x']), $f($point['y']), $f($point['z'])], 'LocalFinalFunction'),
            'omitted personal ID ' . $point['pid'] . ' obeys actual map/radar/native completion in every stage');
        $stage[] = $assert($math('EqualEqual_BoolBool', $call('Radar', 'SSRadarIsSolved', [$i($point['pid'])], FilterPointer($fn, 'Boolean')), $expected()), 'independent expected parity for omitted personal control ' . $point['pid']);
    }
    $stage = [...$stage, FilterExpr('Return', ['ReturnExpression' => $truth(true)]), ['label' => 'Fail'], FilterExpr('Return', ['ReturnExpression' => $truth(false)])];
    $b->define('UniversalProgressStage', [], ['Index' => 'Int', 'Pid' => 'Int', 'Expected' => 'Bool', 'Boolean' => 'Bool'], $stage, null, 'Bool');
    $copiedExpectations = json_decode(file_get_contents(OverlayTestDataPath('EngineFixtures/special-expectations.json')), true, 512, JSON_THROW_ON_ERROR);
    $fn = $b->functions['UniversalProof'];
    $code = [$set('Progression', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['GISProgression'], 'TargetExpression' => $b->call('SubsystemBlueprintLibrary', 'GetGameInstanceSubsystem', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['GISProgression']])])])),
        $assert($b->call('KismetSystemLibrary', 'IsValid', [$s('Progression')]), 'actual GISProgression exists'),
        $assert($math('EqualEqual_IntInt', $array('Array_Length', $get('SSRadarPuzzleIds')), $i(count($index['pointIds']))), 'exact universal production radar retains full point array capacity'),
        $assert($math('EqualEqual_IntInt', $array('Array_Length', $get('SSRadarOffsets')), $i(count($index['offsets']))), 'exact universal production radar retains full spatial index capacity')];
    foreach (['SSRadarSpecialIds', 'SSRadarSpecialKinds', 'SSRadarSpecialKeys', 'SSRadarSpecialParentIds', 'SSRadarSpecialFragmentIndices'] as $field) {
        $code[] = $assert($math('EqualEqual_IntInt', $array('Array_Length', $get($field)), $i(157)), 'native universal descriptor capacity retains all157 records: ' . $field);
    }
    $code[] = $assert($math('EqualEqual_IntInt', $array('Array_Length', $get('SSRadarPentadIds')), $i(293)), 'native universal complete-Pentad array retains293 IDs');
    $code[] = $array('Array_Get', $get('SSRadarOffsets'), $i(count($index['offsets']) - 1), FilterVariable($fn, 'NativeLastOffset'));
    $code[] = $assert($math('EqualEqual_IntInt', FilterVariable($fn, 'NativeLastOffset'), $i(count($index['pointIds']))), 'native last spatial offset equals full point count without truncation');
    foreach ($copiedExpectations['allLore'] as $lore) {
        $code[] = $set('ControlPid', $i($lore['pid']));
        $code[] = $assert($math('EqualEqual_BoolBool', $call('Radar', 'SSRadarIsSolved', [$i($lore['pid'])], FilterPointer($fn, 'Boolean')), $truth($lore['expectedKeyPresent'])), 'actual copied-save production lore dispatch agrees with raw unlock oracle: ' . $lore['pid']);
    }
    foreach ($copiedExpectations['allFragments'] as $fragment) {
        $code[] = $set('ControlPid', $i($fragment['pid']));
        $code[] = $assert($math('EqualEqual_BoolBool', $call('Radar', 'SSRadarIsSolved', [$i($fragment['pid'])], FilterPointer($fn, 'Boolean')), $truth($fragment['expectedSolved'])), 'actual copied-save production fragment dispatch agrees with parent Found index oracle: ' . $fragment['pid']);
    }
    foreach (range(0, 2) as $stageId) {
        $code[] = $set('UniversalStage', $i($stageId));
        $code[] = $assert(FilterCall($b->functions['UniversalProgressStage'], [], 'LocalFinalFunction'), ['fresh', 'mixed', 'complete'][$stageId] . ' in-memory native progress stage passes');
    }
    $code[] = $assert($math('EqualEqual_IntInt', $s('UniversalChecked'), $i(count($ids) * 3)), 'all ordinary universal eligible IDs queried in all three stages');
    $code = [...$code, FilterExpr('Return', ['ReturnExpression' => $truth(true)]), ['label' => 'Fail'], FilterExpr('Return', ['ReturnExpression' => $truth(false)])];
    $b->define('UniversalProof', [], ['NativeLastOffset' => 'Int', 'Boolean' => 'Bool'], $code, null, 'Bool');
    // The original Hub assertions run first; universal progress changes run only after they pass.
    $options = $b->call('KismetStringLibrary', 'Concat_StrStr', [$b->call('KismetStringLibrary', 'Concat_StrStr', [$b->call('KismetStringLibrary', 'Concat_StrStr', [$str('?game=/Script/Engine.GameModeBase?check='), $b->call('KismetStringLibrary', 'Conv_IntToString', [$s('FailureCheck')])]), $str('?pid=')]), $b->call('KismetStringLibrary', 'Conv_IntToString', [$s('ControlPid')])]);
    $travel = static fn(bool $passed): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), $name('/Game/SaveStatsTests/' . ($passed ? 'SSRadarPass' : 'SSRadarFail')), $truth(true), $options]);
    $b->define('Finish', [], [], [$unless($s('Passed'), 'Fail'),
        FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => FilterCall($b->functions['UniversalProof'], [], 'LocalFinalFunction')]),
        $unless($s('Passed'), 'Fail'), $travel(true), $jump('Done'), ['label' => 'Fail'], $travel(false), ['label' => 'Done']]);
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $export) {
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$export->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; }
    }
    file_put_contents($output . '/WBP_SSAvailabilityProof.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $manifest['checkDescriptions'] = $checks; $manifest['checks'] = count($checks);
    $manifest['universal'] = ['ordinaryProgressIds' => count($ids), 'separateSpecialAuthorityRequired' => ['loreFragment', 'monolithFragment'], 'pointCount' => count($index['pointIds']), 'offsetCount' => count($index['offsets']),
        'nativeProgressQueries' => count($ids) * 3, 'stages' => ['fresh', 'mixed parity', 'complete'], 'omittedPersonalControls' => array_values($controls),
        'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false, 'fullProductionWorld' => true];
    $manifest['actualSpecialDispatch'] = ['copiedSaveSha256' => $copiedExpectations['copiedSaveSha256'], 'loreCompared' => count($copiedExpectations['allLore']), 'fragmentsCompared' => count($copiedExpectations['allFragments']), 'beforeAnyFixtureProgressMutation' => true];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $manifest;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'build-dir:', 'personal-build-dir:']);
        foreach (['output-dir', 'build-dir', 'personal-build-dir'] as $key) { if (!isset($args[$key]) || !is_dir($args[$key])) { throw new InvalidArgumentException('Provide --output-dir DIR --build-dir BUILD --personal-build-dir OLD_BUILD'); } }
        echo json_encode(BuildUniversalCatalogRuntimeFixture(realpath($args['output-dir']), realpath($args['build-dir']), realpath($args['personal-build-dir'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
