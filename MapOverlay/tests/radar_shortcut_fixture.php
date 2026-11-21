<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/radar_timer_fixture.php';

/** Native action registration/key identity and production callback dispatcher. */
function BuildRadarShortcutFixture(string $output, string $world, ?int $probeChecks = null): array
{
    $baseline = BuildRadarTimerFixture($output, $world);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($driver->Exports as $n => $export) {
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->functions[$export->ObjectName] = $n + 1; }
    }
    $b->native['KismetInputLibrary'] = OverlayImport($b->asset, 'KismetInputLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $radarPkg = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $radarPkg, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $s = static fn(string $name): object => FilterVariable(1, $name, true);
    $int = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $float = static fn(float $v): object => FilterExpr('FloatConst', ['Value' => $v]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $name = static fn(string $v): object => FilterExpr('NameConst', ['Value' => $v]);
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $get = static fn(string $n): object => RadarContext($s('TimerRadar'), FilterVariable($radarClass, $n, true), FilterPointer($radarClass, $n));
    $call = static fn(string $function): object => RadarContext($s('TimerRadar'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $function, 'Parameters' => []]));
    $listen = static fn(string $action): object => $b->method($s('TimerRadar'), 'UserWidget', 'IsListeningForInputAction', [$name('SaveStatsRadar' . $action)]);
    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), $name('/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')), $bool(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    $checks = [];
    $assert = static function (object $value, string $label) use (&$checks): object { $checks[] = $label; return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $value]); };
    $equalInt = static fn(string $field, int $expected): object => $math('EqualEqual_IntInt', $get($field), $int($expected));
    $equalBool = static fn(string $field, bool $expected): object => $math('EqualEqual_BoolBool', $get($field), $bool($expected));
    $fn = $b->functions['VerifyAutomatic'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $code = [$assert($b->call('KismetSystemLibrary', 'K2_IsTimerActive', [$s('TimerRadar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]), 'automatic production timer active'),
        $assert($math('NearlyEqual_FloatFloat', $get('SSRadarPlayerX'), $float(3000), $float(0.1)), 'automatic timer sees real possessed pawn'),
        $let('Settings', $b->call('InputSettings', 'GetInputSettings'))];
    foreach (['Toggle' => 'F8', 'ZoomIn' => 'F3', 'ZoomOut' => 'F4', 'Size' => 'F10'] as $action => $key) {
        $code[] = $assert($listen($action), $key . ' action listener registered');
        $code[] = $array('Array_Clear', $l('Mappings'));
        $code[] = $b->method($l('Settings'), 'InputSettings', 'GetActionMappingByName', [$name('SaveStatsRadar' . $action), $l('Mappings')]);
        $code[] = $assert($math('EqualEqual_IntInt', $array('Array_Length', $l('Mappings')), $int(1)), $key . ' has one native action mapping');
        $code[] = $array('Array_Get', $l('Mappings'), $int(0), $l('InputMapping'));
        $code[] = $let('InputKey', FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['InputActionKeyMapping'], 'Key'), 'StructExpression' => $l('InputMapping')]));
        $code[] = $let('KeyText', $b->call('KismetInputLibrary', 'Key_GetDisplayName', [$l('InputKey')]));
        $code[] = $assert($b->call('KismetTextLibrary', 'EqualEqual_TextText', [$l('KeyText'), $b->call('KismetTextLibrary', 'Conv_StringToText', [FilterExpr('StringConst', ['Value' => $key])])]), $key . ' native FKey identity');
    }
    $code = [...$code, $call('SSRadarInputToggle'), $assert($equalBool('SSRadarEnabled', false), 'F8 callback disables radar'),
        $call('SSRadarInputToggle'), $assert($equalBool('SSRadarEnabled', true), 'F8 callback enables while disabled'),
        $call('SSRadarInputZoomIn'), $assert($equalInt('SSRadarZoomIndex', 0), 'F3 callback reaches 50m'),
        $call('SSRadarInputZoomIn'), $assert($equalInt('SSRadarZoomIndex', 0), 'F3 clamps at 50m'),
        $call('SSRadarInputZoomOut'), $call('SSRadarInputZoomOut'), $call('SSRadarInputZoomOut'), $call('SSRadarInputZoomOut'),
        $assert($equalInt('SSRadarZoomIndex', 3), 'F4 callback reaches and clamps at 400m'),
        $call('SSRadarInputSize'), $assert($equalInt('SSRadarSizeIndex', 2), 'F10 callback reaches 150 percent'),
        $call('SSRadarInputSize'), $assert($equalInt('SSRadarSizeIndex', 0), 'F10 callback wraps to 100 percent'),
        $call('SSRadarInputSize'), $assert($equalInt('SSRadarSizeIndex', 1), 'F10 callback returns to 125 percent'),
        $assert($math('EqualEqual_NameName', $get('SSRadarSelectedTag'), $name('SaveStats.Type.hiddenCube')), 'shortcuts preserve selected puzzle'),
        $b->method($s('TimerRadar'), 'Widget', 'RemoveFromParent'),
        $assert($math('Not_PreBool', $listen('Toggle')), 'Destruct unregisters action listener'),
        $call('SSRadarInputToggle'), $assert($equalBool('SSRadarEnabled', true), 'detached stale radar rejects shortcut callback'),
        $b->method($s('TimerRadar'), 'UserWidget', 'AddToViewport', [$int(0)]),
        $assert($listen('Toggle'), 'Construct restores native listener'),
        $call('SSRadarInputToggle'), $assert($equalBool('SSRadarEnabled', false), 'reattached callback works'),
        $call('SSRadarInputToggle'),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => $l('Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'VerifyRestart']), $float(0.4), $bool(false), $float(0), $float(0)])]),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
    if ($probeChecks !== null) {
        $found = 0;
        foreach ($code as $n => $expression) {
            if (is_object($expression) && str_contains($expression->{'$type'}, '.EX_JumpIfNot') && ++$found === $probeChecks) {
                $code = [...array_slice($code, 0, $n + 1), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
                break;
            }
        }
    }
    $b->define('VerifyAutomatic', [], ['Settings' => 'Object', 'Mappings' => 'Array', 'InputMapping' => 'Struct', 'InputKey' => 'Struct', 'KeyText' => 'Text', 'Timer' => 'Struct'], $code);
    $function = $b->asset->Exports[$fn - 1];
    foreach ($function->LoadedProperties as $property) {
        if ($property->Name === 'Mappings') { $property->Inner = $b->field('Struct', 'Mappings'); $property->Inner->Struct = $b->native['InputActionKeyMapping']; $property->Inner->ElementSize = 48; }
    }
    $b->define('VerifyRestart', [], [], [$assert($listen('Toggle'), 'reattached listener survives timer callback'),
        $assert($math('EqualEqual_ByteByte', $b->method($get('SSRadarCanvas'), 'Widget', 'GetVisibility'), FilterExpr('ByteConst', ['Value' => 3])), 'view remains visible after shortcut changes'),
        $b->method($s('TimerRadar'), 'Widget', 'RemoveFromParent'), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)]);
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $export) {
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$export->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; }
    }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $result = ['assertions' => $probeChecks === null ? count($checks) + 3 : $probeChecks + 3, 'checks' => $probeChecks === null ? $checks : array_slice($checks, 0, $probeChecks), 'nativeActionRegistration' => true, 'nativeFKeyIdentity' => true, 'productionCallbackDispatcher' => true, 'physicalKeyboardInjection' => false];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'world:', 'probe-checks:']);
        if (!isset($args['output-dir'], $args['world']) || !is_dir($args['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir DIR --world JSON'); }
        echo json_encode(BuildRadarShortcutFixture(realpath($args['output-dir']), realpath($args['world']), isset($args['probe-checks']) ? (int)$args['probe-checks'] : null), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
