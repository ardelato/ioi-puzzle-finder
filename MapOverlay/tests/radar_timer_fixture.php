<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/radar_runtime_fixture.php';

/** Automatic timer/real local pawn test: never calls SSRadarTick or Update. */
function BuildRadarTimerFixture(string $output, string $world, bool $timerOnly = false): array
{
    BuildRadarRuntimeFixture($output, $world);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset = $driver;
    $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    $b->asset->Exports = [$b->class, $b->default];
    $b->class->LoadedProperties = $b->class->FuncMap = $b->class->Children = $b->class->CreateBeforeSerializationDependencies = [];
    $radarPackage = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $radarPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $setter = OverlayImport($b->asset, 'SSRadarSetSelection', $radarClass, '/Script/CoreUObject', 'Function');
    $b->native['Controller'] = OverlayImport($b->asset, 'Controller', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->objectField('TimerRadar', $radarClass);
    $b->objectField('TimerPawn', $b->native['SophiaCharacter']);
    $b->objectField('TimerController', $b->native['Object']);
    foreach (['ReceiveBeginPlay', 'VerifyAutomatic', 'VerifyRemoved', 'VerifyRestart'] as $name) { $b->reserve($name); }
    $state = static fn(string $name): object => FilterVariable(1, $name, true);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer(1, $name), 'Variable' => $state($name), 'Expression' => $value]);
    $int = static fn(int $value): object => FilterExpr('IntConst', ['Value' => $value]);
    $float = static fn(float $value): object => FilterExpr('FloatConst', ['Value' => $value]);
    $bool = static fn(bool $value): object => FilterExpr($value ? 'True' : 'False');
    $math = static fn(string $fn, object ...$values): object => $b->call('KismetMathLibrary', $fn, $values);
    $valid = static fn(object $value): object => $b->call('KismetSystemLibrary', 'IsValid', [$value]);
    $get = static fn(string $name): object => RadarContext($state('TimerRadar'), FilterVariable($radarClass, $name, true), FilterPointer($radarClass, $name));
    $vector = static fn(float $x, float $y, float $z): object => $math('MakeVector', $float($x), $float($y), $float($z));
    $rotation = static fn(): object => $math('MakeRotator', $float(0), $float(0), $float(0));
    $transform = static fn(): object => $math('MakeTransform', $vector(3000, 4000, 5000), $rotation(), $vector(1, 1, 1));
    $timer = static function (string $ownerFunction, string $callback, float $delay) use ($b, $float, $bool): object {
        $fn = $b->functions[$ownerFunction];
        return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'),
            'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $float($delay), $bool(false), $float(0), $float(0)])]);
    };
    $active = static fn(): object => $b->call('KismetSystemLibrary', 'K2_IsTimerActive', [$state('TimerRadar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]);
    $viewport = static fn(): object => $b->method($state('TimerRadar'), 'UserWidget', 'IsInViewport');
    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')]), $bool(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    $checks = [];
    $assert = static function (object $condition, string $check) use (&$checks): object { $checks[] = $check; return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $condition]); };
    $tail = static fn(): array => [FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
    $code = [$set('TimerController', $b->call('GameplayStatics', 'GetPlayerController', [FilterExpr('Self'), $int(0)])), $assert($valid($state('TimerController')), 'local controller exists'),
        $set('TimerPawn', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['SophiaCharacter']]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])])),
        $assert($valid($state('TimerPawn')), 'native SophiaCharacter spawned'), $b->method($state('TimerPawn'), 'Actor', 'SetActorTickEnabled', [$bool(false)]),
        $set('TimerPawn', $b->call('GameplayStatics', 'FinishSpawningActor', [$state('TimerPawn'), $transform()])),
        $b->method($state('TimerController'), 'Controller', 'Possess', [$state('TimerPawn')]),
        $set('TimerRadar', FilterExpr('DynamicCast', ['ClassPtr' => $radarClass, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $radarClass]), $state('TimerController')])])),
        $assert($valid($state('TimerRadar')), 'actual radar created with local player'), $b->method($state('TimerRadar'), 'UserWidget', 'AddToViewport', [$int(0)]),
        RadarContext($state('TimerRadar'), FilterCall($setter, [FilterExpr('IntConst', ['Value' => OverlayTypeBit('hiddenCube')]), $bool(false), $bool(true)], 'FinalFunction')),
        $timer('ReceiveBeginPlay', 'VerifyAutomatic', 0.5), ...$tail()];
    if ($timerOnly) {
        $code = [$code[0], $code[1], ...array_slice($code, 7)];
        array_splice($code, 6, 0, [
            $b->method($get('SSRadarCanvas'), 'Widget', 'SetVisibility', [FilterExpr('ByteConst', ['Value' => 3])]),
            $assert($math('EqualEqual_ByteByte', $b->method($get('SSRadarCanvas'), 'Widget', 'GetVisibility'), FilterExpr('ByteConst', ['Value' => 3])), 'canvas forced visible before automatic timer'),
        ]);
    }
    $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct'], $code);
    $begin = $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $begin->SuperStruct = $begin->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $b->native['Actor'], '/Script/CoreUObject', 'Function');
    $begin->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $begin->SerializationBeforeSerializationDependencies = [$begin->SuperStruct];
    $menu = static fn(string $name): object => $b->method($state('TimerPawn'), 'SophiaCharacter', $name);
    $pose = static fn(string $axis, float $expected): object => $math('NearlyEqual_FloatFloat', $get('SSRadarPlayer' . $axis), $float($expected), $float(0.1));
    $visible = static fn(): object => $b->method($get('SSRadarCanvas'), 'Widget', 'GetVisibility');
    $code = [$assert($active(), 'native timer is active automatically'), $assert($viewport(), 'radar is attached to viewport'),
        $assert($math('Not_PreBool', $menu('IsMapMenuVisible')), 'native map menu false'), $assert($math('Not_PreBool', $menu('IsPauseMenuVisible')), 'native pause menu false'),
        $assert($pose('X', 3000), 'timer acquired real possessed character X'), $assert($pose('Y', 4000), 'timer acquired real possessed character Y'),
        $assert($math('Greater_IntInt', $get('SSRadarVisitedTotal'), $int(0)), 'timer processed candidates without manual Update'),
        $assert($math('EqualEqual_ByteByte', $visible(), FilterExpr('ByteConst', ['Value' => 3])), 'automatic timer makes radar canvas visible'),
        $b->method($state('TimerRadar'), 'Widget', 'RemoveFromParent'), $timer('VerifyAutomatic', 'VerifyRemoved', 0.2), ...$tail()];
    if ($timerOnly) { $code = [$code[0], $code[1], $assert($math('EqualEqual_ByteByte', $visible(), FilterExpr('ByteConst', ['Value' => 1])), 'automatic invalid-player Tick collapses forced canvas'), $travel(true), ...$tail()]; }
    $b->define('VerifyAutomatic', [], ['Timer' => 'Struct'], $code);
    $code = [$assert($math('Not_PreBool', $viewport()), 'removed radar leaves viewport'), $assert($math('Not_PreBool', $active()), 'Destruct stops native timer'),
        $b->method($state('TimerRadar'), 'UserWidget', 'AddToViewport', [$int(0)]),
        FilterExpr('LetBool', ['VariableExpression' => FilterVariable($b->functions['VerifyRemoved'], 'Teleported'), 'AssignmentExpression' => $b->method($state('TimerPawn'), 'Actor', 'K2_TeleportTo', [$vector(9000, 8000, 5000), $rotation()])]),
        $timer('VerifyRemoved', 'VerifyRestart', 0.4), ...$tail()];
    $b->define('VerifyRemoved', [], ['Timer' => 'Struct', 'Teleported' => 'Bool'], $code);
    $code = [$assert($active(), 'reattached Construct restarts native timer'), $assert($viewport(), 'reattached radar is in viewport'),
        $assert($pose('X', 9000), 'restarted timer acquires moved character X'), $assert($pose('Y', 8000), 'restarted timer acquires moved character Y'),
        $assert($math('EqualEqual_ByteByte', $visible(), FilterExpr('ByteConst', ['Value' => 3])), 'radar stays visible after reattachment'),
        $travel(true), ...$tail()];
    $b->define('VerifyRestart', [], [], $code);
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    if ($timerOnly) { $checks = ['local controller exists', 'actual radar created with local player', 'canvas forced visible before automatic timer', 'native timer is active automatically', 'radar is attached to viewport', 'automatic invalid-player Tick collapses forced canvas']; }
    $result = ['assertions' => count($checks), 'checks' => $checks, 'automaticTickOnly' => true, 'timerOnly' => $timerOnly, 'manualTickOrUpdateCalls' => 0, 'nativePawnClass' => $timerOnly ? null : '/Script/IslandsofInsight.SophiaCharacter', 'passMap' => '/Game/SaveStatsTests/SSRadarPass', 'failMap' => '/Game/SaveStatsTests/SSRadarFail'];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'world:', 'timer-only']);
        if (!isset($args['output-dir'], $args['world']) || !is_dir($args['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir DIR --world JSON'); }
        echo json_encode(BuildRadarTimerFixture(realpath($args['output-dir']), realpath($args['world']), isset($args['timer-only'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
