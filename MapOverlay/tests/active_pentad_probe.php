<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/radar_widget.php';

/** Isolated native authority probe; mutates only this test process's memory. */
function BuildActivePentadProbe(string $output, ?int $probeChecks = null): array
{
    $repo = dirname(__DIR__, 2);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset->Exports = [$b->class, $b->default];
    $actor = $b->native['Actor'];
    $bp = OverlayImport($b->asset, 'BlueprintGeneratedClass', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $bpDefault = OverlayImport($b->asset, 'Default__BlueprintGeneratedClass', $b->native['Engine'], '/Script/Engine', 'BlueprintGeneratedClass');
    $actorDefault = OverlayImport($b->asset, 'Default__Actor', $b->native['Engine'], '/Script/Engine', 'Actor');
    $b->class->ObjectName = 'BP_SSRadarTest_C';
    $b->class->ClassIndex = $bp;
    $b->class->TemplateIndex = $bpDefault;
    $b->class->ClassFlags = 'CLASS_Parsed, CLASS_ReplicationDataIsSetUp, CLASS_CompiledFromBlueprint';
    $b->class->SuperStruct = $b->class->SuperIndex = $actor;
    $b->class->LoadedProperties = $b->class->Data = [];
    $b->class->SerializationBeforeSerializationDependencies = [$actor];
    $b->class->CreateBeforeSerializationDependencies = [];
    $b->class->SerializationBeforeCreateDependencies = [$bp, $bpDefault];
    $b->class->CreateBeforeCreateDependencies = [$actor];
    $b->default->ObjectName = 'Default__BP_SSRadarTest_C';
    $b->default->TemplateIndex = $actorDefault;
    $b->default->Data = [];
    $b->default->SerializationBeforeCreateDependencies = [1, $actorDefault];
    foreach (['SubsystemBlueprintLibrary', 'BlueprintMapLibrary', 'Controller'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['Engine'], '/Script/CoreUObject', 'Class');
    }
    $b->native['GISProgression'] = OverlayImport($b->asset, 'GISProgression', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    foreach (['PlayerProgressionData', 'PuzzleSolveData'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    }
    $b->objectField('Progression', $b->native['GISProgression']);
    $b->objectField('Player', $b->native['SophiaCharacter']);
    $b->objectField('Controller', $b->native['Object']);
    foreach (['SolvedSeed' => [true, false], 'ResetSeed' => [true, true], 'UnsolvedSeed' => [false, false]] as $name => [$solved, $reset]) {
        $b->structField($name, 'PuzzleSolveData', 40, [OverlayProperty('Bool', 'bSolved', $solved ? 1 : 0), OverlayProperty('Bool', 'bReset', $reset ? 1 : 0)]);
    }
    foreach (['CharPuzzleComponent', 'Seek5', 'PuzzleBase', 'ClickableBase', 'Seek5HiddenObject'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    foreach (['PentadA' => 'Seek5', 'PentadB' => 'Seek5', 'PuzzleComponent' => 'CharPuzzleComponent'] as $name => $type) { $b->objectField($name, $b->native[$type]); }
    $b->scalar('Bool', 'Passed', false);
    foreach (['ReceiveBeginPlay', 'VerifyLive', 'Finish'] as $fn) { $b->reserve($fn); }
    $s = static fn(string $name): object => FilterVariable(1, $name, true);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer(1, $name), 'Variable' => $s($name), 'Expression' => $value]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $truth = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $vec = static fn(float $x, float $y, float $z): object => $math('MakeVector', $f($x), $f($y), $f($z));
    $transform = static fn(): object => $math('MakeTransform', $vec(3000, 4000, 5000), $math('MakeRotator', $f(0), $f(0), $f(0)), $vec(1, 1, 1));
    $timer = static function (string $owner, string $callback, float $delay) use ($b, $f, $truth): object {
        $fn = $b->functions[$owner];
        return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $f($delay), $truth(false), $f(0), $f(0)])]);
    };
    $begin = [$set('Controller', $b->call('GameplayStatics', 'GetPlayerController', [FilterExpr('Self'), $i(0)])),
        $set('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['SophiaCharacter']]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])])),
        $b->method($s('Player'), 'Actor', 'SetActorTickEnabled', [$truth(false)]),
        $set('Player', $b->call('GameplayStatics', 'FinishSpawningActor', [$s('Player'), $transform()])),
        $b->method($s('Controller'), 'Controller', 'Possess', [$s('Player')]),
        $set('Progression', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['GISProgression'], 'TargetExpression' => $b->call('SubsystemBlueprintLibrary', 'GetGameInstanceSubsystem', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['GISProgression']])])])),
        $timer('ReceiveBeginPlay', 'VerifyLive', 0.5)];
    array_pop($begin);
    foreach (['PentadA', 'PentadB'] as $name) {
        $begin[] = $set($name, FilterExpr('DynamicCast', ['ClassPtr' => $b->native['Seek5'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['Seek5']]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])]));
        $begin[] = $b->method($s($name), 'Actor', 'SetActorTickEnabled', [$truth(false)]);
        $begin[] = $set($name, $b->call('GameplayStatics', 'FinishSpawningActor', [$s($name), $transform()]));
    }
    $begin[] = $timer('ReceiveBeginPlay', 'VerifyLive', 0.5);
    $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct'], $begin);
    $receive = $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $receive->SuperStruct = $receive->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $actor, '/Script/CoreUObject', 'Function');
    $receive->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $receive->SerializationBeforeSerializationDependencies = [$receive->SuperStruct];
    $fn = $b->functions['VerifyLive'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $checks = [];
    $assert = static function (object $condition, string $name) use (&$checks): object { $checks[] = $name; return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $condition]); };
    $property = static fn(object $obj, string $owner, string $name): object => RadarContext($obj, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $assign = static fn(object $obj, string $owner, string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($b->native[$owner], $name), 'Variable' => $property($obj, $owner, $name), 'Expression' => $value]);
    $query = static fn(): object => $let('Current', $b->method($s('PuzzleComponent'), 'CharPuzzleComponent', 'GetCurrentSeek5', [], FilterPointer($fn, 'Current')));
    $state = static fn(): object => $let('State', $b->method($s('PentadA'), 'Seek5', 'GetState', [], FilterPointer($fn, 'State')));
    $worker = static fn(string $name, bool $add): object => $b->call('KismetArrayLibrary', $add ? 'Array_Insert' : 'Array_RemoveItem', $add ? [$property($s('PuzzleComponent'), 'CharPuzzleComponent', 'workedOnPuzzles'), $s($name), $i(0)] : [$property($s('PuzzleComponent'), 'CharPuzzleComponent', 'workedOnPuzzles'), $s($name)]);
    $eq = static fn(object $a, object $c): object => $math('EqualEqual_ObjectObject', $a, $c);
    $code = [$assert($valid($s('Player')), 'local SophiaCharacter exists'),
        $set('PuzzleComponent', $property($s('Player'), 'SophiaCharacter', 'CharPuzzleComponent')),
        $assert($valid($s('PuzzleComponent')), 'native CharPuzzleComponent exists'),
        $assert($valid($s('PentadA')), 'native Seek5 A spawned'), $assert($valid($s('PentadB')), 'native Seek5 B spawned'),
        $assign($s('PentadA'), 'PuzzleBase', 'KrakenId', $i(5594)), $assign($s('PentadB'), 'PuzzleBase', 'KrakenId', $i(5595)),
        $query(), $assert($math('Not_PreBool', $valid($l('Current'))), 'no current Pentad initially'),
        $worker('PentadA', true), $query(), $assert($eq($l('Current'), $s('PentadA')), 'authoritative worker-list insert exposes Pentad A immediately'),
        $assert($math('EqualEqual_IntInt', $property($l('Current'), 'PuzzleBase', 'KrakenId'), $i(5594)), 'current native Pentad carries catalog ID'),
        $assign($s('PentadA'), 'ClickableBase', 'interactingPlayer', $s('Player')), $state(),
        $assert($math('EqualEqual_ByteByte', $l('State'), FilterExpr('ByteConst', ['Value' => 1])), 'native GetState reports BeingSolved for active player'),
        $assert($math('Not_PreBool', $b->method($s('Player'), 'SophiaCharacter', 'IsInPuzzleMode')), 'free-exploration Pentad authority does not set puzzle mode'),
        $worker('PentadA', false), $worker('PentadB', true), $query(),
        $assert($eq($l('Current'), $s('PentadB')), 'authoritative worker-list switch switches active Pentad'),
        $worker('PentadB', false), $query(), $assert($math('Not_PreBool', $valid($l('Current'))), 'authoritative worker-list remove clears current Pentad'),
        $assign($s('PentadA'), 'ClickableBase', 'interactingPlayer', FilterExpr('ObjectConst', ['Value' => 0])), $state(),
        $assert($math('EqualEqual_ByteByte', $l('State'), FilterExpr('ByteConst', ['Value' => 0])), 'native GetState returns Inactive after player cleared')];
    if ($probeChecks !== null) {
        $seen = 0;
        foreach ($code as $n => $expression) {
            if (str_contains($expression->{'$type'}, '.EX_JumpIfNot') && ++$seen === $probeChecks) { $code = array_slice($code, 0, $n + 1); $checks = array_slice($checks, 0, $seen); break; }
        }
    }
    array_push($code, FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $truth(true)]), $timer('VerifyLive', 'Finish', 0.2), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer('VerifyLive', 'Finish', 0.2));
    $b->define('VerifyLive', [], ['Current' => 'Object', 'State' => 'Byte', 'Timer' => 'Struct'], $code);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
        if ($p->Name === 'State') { $p->ElementSize = 1; $p->Enum = 0; }
        if ($p->Name === 'Current') { $p->PropertyClass = $b->native['Seek5']; }
    }
    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')]), $truth(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    $b->define('Finish', [], [], [FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $s('Passed')]), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)]);
    foreach (['/Game/SaveStatsTests/BP_SSRadarTest', '/Game/SaveStatsTests/SSRadarPass', '/Game/SaveStatsTests/SSRadarFail'] as $name) { $b->name($name); }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    foreach (['Login', 'SSRadarPass', 'SSRadarFail'] as $name) { copy(OverlayTestDataPath('EngineFixtures/' . $name . '.json'), $output . '/' . $name . '.json'); }
    $manifest = ['assertions' => count($checks), 'checks' => $checks, 'nativeAuthority' => 'CharPuzzleComponent.workedOnPuzzles', 'nativeFunction' => 'GetCurrentSeek5', 'returnBytes' => 8, 'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $manifest;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['output-dir:', 'probe-checks:']);
        if (!isset($options['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir'); }
        echo json_encode(BuildActivePentadProbe(realpath($options['output-dir']), isset($options['probe-checks']) ? (int)$options['probe-checks'] : null), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}

