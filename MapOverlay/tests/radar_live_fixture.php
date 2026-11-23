<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/live_progress_probe.php';

/** Real native progression authority; all seeded status changes stay in test memory. */
function BuildRadarLiveFixture(string $output): array
{
    BuildLiveProgressProbe($output);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $point = static fn(string $name, string $type, int $pid, float $x, float $y, bool $site = false): array => [
        'actorName' => $name, 'ptype' => $site ? 'spawnSite' : $type, 'group' => $site ? 'spawn' : 'static',
        'pid' => $pid, 'x' => $x, 'y' => $y, 'z' => 0, 'tags' => ['SaveStats.Type.' . $type],
    ];
    $points = [$point('PairA', 'hiddenCube', 5594, 1000, 0), $point('PairB', 'hiddenCube', 5594, 0, 1000),
        $point('Other', 'hiddenCube', 5595, 3000, 0), $point('Site', 'hiddenCube', 0, 500, 500, true)];
    foreach (range(0, 299) as $n) { $points[] = $point(sprintf('Ring%04d', $n), 'hiddenRing', 6000 + $n, 1000 + $n, 0); }
    $index = BuildRadarIndex($points, array_intersect_key(GetOverlayTypes(), array_flip(['hiddenCube', 'hiddenRing'])));
    [$radar, $manifest] = BuildRadarWidget($base, $index);
    file_put_contents($output . '/WBP_SSOverlayRadar.json', json_encode($radar, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($driver->Exports as $n => $export) {
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->functions[$export->ObjectName] = $n + 1; }
    }
    foreach (['BlueprintMapLibrary', 'Controller'] as $type) { $b->native[$type] = OverlayImport($b->asset, $type, $b->native['Engine'], '/Script/CoreUObject', 'Class'); }
    $b->native['PlayerProgressionData'] = OverlayImport($b->asset, 'PlayerProgressionData', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    $pkg = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $pkg, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $b->objectField('Radar', $radarClass);
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $s($n), 'Expression' => $v]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $name = static fn(string $v): object => FilterExpr('NameConst', ['Value' => $v]);
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $get = static fn(string $n): object => RadarContext($s('Radar'), FilterVariable($radarClass, $n, true), FilterPointer($radarClass, $n));
    $invoke = static fn(string $fn, array $args = [], ?object $pointer = null): object => RadarContext($s('Radar'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $fn, 'Parameters' => $args]), $pointer);
    $query = static fn(int $pid): object => $invoke('SSRadarIsSolved', [$i($pid)]);
    $select = static fn(string $type, bool $sites = false, bool $enabled = true): object => $invoke('SSRadarSetSelection', [$i($type === 'None' ? 0 : OverlayTypeBit($type)), $bool($sites), $bool($enabled)]);
    $step = static fn(): object => $invoke('SSRadarUpdate', [$f(0), $f(0), $f(0), $f(0), $bool(false), $f(0.1)]);
    $count = static fn(): object => $array('Array_Length', $get('SSRadarDisplayIndices'));
    $equalInt = static fn(object $v, int $n): object => $math('EqualEqual_IntInt', $v, $i($n));
    $equalBool = static fn(object $v, bool $n): object => $math('EqualEqual_BoolBool', $v, $bool($n));
    $pinVisible = static fn(int $pin, int $visibility): object => $math('EqualEqual_ByteByte', $b->method($get('SSRadarPin' . $pin), 'Widget', 'GetVisibility'), FilterExpr('ByteConst', ['Value' => $visibility]));
    $data = RadarContext($s('Progression'), FilterVariable($b->native['GISProgression'], 'PlayerProgression', true), FilterPointer($b->native['GISProgression'], 'PlayerProgression'));
    $map = static fn(): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'), 'StructExpression' => unserialize(serialize($data))]);
    $seed = static fn(int $pid, string $status): object => $b->call('BlueprintMapLibrary', 'Map_Add', [$map(), $i($pid), $s($status)]);
    $checks = [];
    $assert = static function (object $value, string $description) use (&$checks): object {
        $checks[] = $description;
        return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $value]);
    };
    $timer = static function (string $owner, string $callback, float $delay) use ($b, $f, $bool): object {
        $fn = $b->functions[$owner];
        return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $f($delay), $bool(false), $f(0), $f(0)])]);
    };
    $finishTail = static fn(string $owner): array => [FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $bool(true)]), $timer($owner, 'Finish', 0.2),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer($owner, 'Finish', 0.2)];
    $vector = static fn(float $x, float $y, float $z): object => $math('MakeVector', $f($x), $f($y), $f($z));
    $transform = static fn(): object => $math('MakeTransform', $vector(0, 0, 0), $math('MakeRotator', $f(0), $f(0), $f(0)), $vector(1, 1, 1));
    $begin = [$set('Controller', $b->call('GameplayStatics', 'GetPlayerController', [FilterExpr('Self'), $i(0)])),
        $set('Radar', FilterExpr('DynamicCast', ['ClassPtr' => $radarClass, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $radarClass]), $s('Controller')])])),
        $assert($valid($s('Radar')), 'actual native radar created'),
        $assert($math('Not_PreBool', $query(5594)), 'missing player progress preserves puzzle visibility'),
        $assert($math('Not_PreBool', $get('SSRadarProgressAvailable')), 'missing player is reported unavailable'),
        $assert($math('Not_PreBool', $query(0)), 'possible site PID0 is never solved'),
        $assert($math('Not_PreBool', $query(-1)), 'negative PID is never solved'),
        $assert($equalInt($get('SSRadarProgressQueriesTotal'), 0), 'unavailable and PID0 queries make no native status calls'),
        $assert($get('SSRadarMapProgressDirty'), 'map progress initially dirty'), $invoke('SSRadarAcknowledgeMapProgress'),
        $assert($math('Not_PreBool', $get('SSRadarMapProgressDirty')), 'map acknowledge clears dirty flag'),
        $select('None', false, false),
        $set('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['SophiaCharacter']]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])])),
        $b->method($s('Player'), 'Actor', 'SetActorTickEnabled', [$bool(false)]), $set('Player', $b->call('GameplayStatics', 'FinishSpawningActor', [$s('Player'), $transform()])),
        $b->method($s('Controller'), 'Controller', 'Possess', [$s('Player')]),
        $set('Progression', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['GISProgression'], 'TargetExpression' => $b->call('SubsystemBlueprintLibrary', 'GetGameInstanceSubsystem', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['GISProgression']])])])),
        $b->method($s('Radar'), 'UserWidget', 'AddToViewport', [$i(0)]), $timer('ReceiveBeginPlay', 'VerifyLive', 0.5),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer('ReceiveBeginPlay', 'Finish', 0.5)];
    $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct'], $begin);
    $receive = $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $receive->SuperStruct = $receive->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $b->native['Actor'], '/Script/CoreUObject', 'Function');
    $receive->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $receive->SerializationBeforeSerializationDependencies = [$receive->SuperStruct];
    $fn = $b->functions['VerifyLive'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $code = [$assert($get('SSRadarMapProgressDirty'), 'real gameplay Tick marks map dirty even while radar disabled'),
        $assert($valid($s('Progression')), 'actual GISProgression exists'), $seed(5594, 'SolvedSeed'),
        $assert($query(5594), 'shared query works without selection and while disabled'),
        $assert($get('SSRadarProgressAvailable'), 'native progress source available'),
        $b->call('KismetSystemLibrary', 'K2_ClearTimer', [$s('Radar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]),
        $seed(5594, 'UnsolvedSeed'), $seed(5595, 'UnsolvedSeed'), $select('hiddenCube'), $step(),
        $assert($equalInt($count(), 3), 'all exact snapshot pair and other markers initially visible'),
        $seed(5594, 'SolvedSeed'), $step(), $assert($pinVisible(0, 1), 'completed first pair pin hides on next render'),
        $assert($pinVisible(1, 1), 'completed second pair pin hides on next render'), $assert($pinVisible(2, 3), 'uncompleted pin stays visible'),
        $assert($get('SSRadarDirty'), 'completion invalidates stale query'), $step(), $assert($equalInt($count(), 1), 'both solved pair locations excluded on refill'),
        $select('hiddenCube', true), $step(), $assert($equalInt($count(), 2), 'optional PID0 site included with exact remaining puzzle'),
        $seed(0, 'SolvedSeed'), $seed(5595, 'SolvedSeed'), $step(), $step(),
        $assert($equalInt($count(), 1), 'only site remains after all exact puzzles solved'), $assert($pinVisible(0, 3), 'possible site stays visible despite seeded PID0 status'),
        $seed(5594, 'UnsolvedSeed'), $assert($math('Not_PreBool', $query(5594)), 'query observes native clear without solved cache')];
    foreach (range(1, 8) as $_) { $code[] = $step(); }
    $code = [...$code, $assert($equalInt($count(), 3), 'cleared progress restores both pair markers'),
        $seed(5594, 'ResetSeed'), $assert($query(5594), 'solved plus reset still counts as completed'), $step(), $step(),
        $assert($equalInt($count(), 1), 'reset flag does not restore a completed puzzle')];
    foreach (range(6000, 6299) as $pid) { $code[] = $b->call('BlueprintMapLibrary', 'Map_Remove', [$map(), $i($pid)]); }
    $code = [...$code, $select('hiddenRing'), $step(), $step(), $step(), $assert($equalInt($count(), 24), 'dense live query commits nearest24'),
        $seed(6000, 'SolvedSeed'), $assert($query(6000), 'native authority recognizes dense candidate solve'), $step(),
        $assert($pinVisible(0, 1), 'dense completed committed pin hides before refill'), $assert($get('SSRadarDirty'), 'dense completion invalidates query'),
        $let('ReadsBefore', $get('SSRadarProgressQueriesTotal')), $step(),
        $assert($equalInt($get('SSRadarVisitedLastUpdate'), 128), 'live refill first callback budget128'),
        $assert($equalInt($count(), 0), 'live refill does not commit partial nearest set'),
        $assert($equalInt($math('Subtract_IntInt', $get('SSRadarProgressQueriesTotal'), $l('ReadsBefore')), 128), 'first refill does exactly128 native status reads'),
        $step(), $assert($equalInt($get('SSRadarVisitedLastUpdate'), 128), 'live refill second callback budget128'),
        $step(), $assert($equalInt($get('SSRadarVisitedLastUpdate'), 44), 'live refill finishes remaining44'), $assert($equalInt($count(), 24), 'dense refill completes rather than restarting forever'),
        $array('Array_Get', $get('SSRadarDisplayIndices'), $i(0), $l('FirstPoint')),
        $assert($equalInt($l('FirstPoint'), (int)array_search('Ring0001', $index['pointIds'], true)), 'completed nearest replaced by next unsolved point'),
        $select('None'), $seed(6000, 'UnsolvedSeed'), $select('hiddenRing'), $step(), $seed(6000, 'SolvedSeed'), $step(), $step(),
        $assert($pinVisible(0, 1), 'completion during in-flight query never paints stale solved pin'), $assert($get('SSRadarDirty'), 'in-flight stale completion schedules bounded refill'),
        $step(), $step(), $step(), $assert($equalInt($count(), 24), 'in-flight stale query recovers nearest24'),
        $b->method($s('Player'), 'Actor', 'K2_DestroyActor'), $assert($math('Not_PreBool', $query(5594)), 'lost player authority returns unsolved fallback'),
        $assert($math('Not_PreBool', $get('SSRadarProgressAvailable')), 'lost player authority is reported unavailable'),
        $select('hiddenCube', true), $step(), $assert($equalInt($count(), 4), 'unavailable progress preserves every snapshot marker'),
        ...$finishTail('VerifyLive')];
    $b->define('VerifyLive', [], ['Timer' => 'Struct', 'ReadsBefore' => 'Int', 'FirstPoint' => 'Int'], $code);
    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), $name('/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')), $bool(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    $b->define('Finish', [], [], [$b->method($s('Radar'), 'Widget', 'RemoveFromParent'),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $s('Passed')]), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)]);
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $export) {
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$export->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; }
    }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $result = ['assertions' => count($checks), 'checks' => $checks, 'nativeAuthority' => 'GISProgression.GetPuzzleSolveStatus', 'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'world:']);
        if (!isset($args['output-dir']) || !is_dir($args['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir DIR'); }
        echo json_encode(BuildRadarLiveFixture(realpath($args['output-dir'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
