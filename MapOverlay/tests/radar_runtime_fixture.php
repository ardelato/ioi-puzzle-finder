<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/radar_widget.php';
require_once __DIR__ . '/filter_runtime_fixture.php';

/** Exercise the production generator in a real native widget using known geometry. */
function BuildRadarRuntimeFixture(string $output, string $worldPath, bool $probeCreate = false, ?int $probeChecks = null): array
{
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $catalog = array_intersect_key(GetOverlayTypes(), array_flip(['hiddenCube', 'hiddenRing', 'logicGrid']));
    $point = static fn(string $id, string $type, float $x, float $y, float $z = 0, bool $site = false): array => [
        'actorName' => $id, 'ptype' => $site ? 'spawnSite' : $type, 'group' => $site ? 'spawn' : 'static',
        'x' => $x, 'y' => $y, 'z' => $z, 'tags' => ['SaveStats.Type.' . $type],
    ];
    $points = [$point('A', 'hiddenCube', 10000, 0, 1001), $point('B', 'hiddenCube', 0, 10000, -1001),
        $point('C', 'hiddenCube', 20000, 0, 1000), $point('D', 'hiddenCube', 20001, 0), $point('E', 'hiddenCube', -10000, 0, 0, true),
        $point('Control', 'logicGrid', 100, 0), $point('Range50', 'logicGrid', 5000, 0), $point('Range50Outside', 'logicGrid', 5000.5, 0),
        $point('Range400', 'logicGrid', 40000, 0), $point('Range400Outside', 'logicGrid', 40000.5, 0), $point('RangeWest', 'logicGrid', -40000, 0),
        $point('RangeSouth', 'logicGrid', 0, -40000), $point('RangeNorth', 'logicGrid', 0, 40000)];
    foreach (range(0, 299) as $n) { $points[] = $point(sprintf('Near%04d', $n), 'hiddenRing', 1000 + $n, 0); }
    foreach (range(0, 4999) as $n) { $points[] = $point(sprintf('Far%04d', $n), 'hiddenRing', 100000 + $n, 100000); }
    $index = BuildRadarIndex($points, $catalog);
    [$radar, $manifest] = BuildRadarWidget($base, $index);
    file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/WBP_SSOverlayRadar.json', json_encode($radar, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

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
    $radarPkg = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $radarPkg, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $setSelection = OverlayImport($b->asset, 'SSRadarSetSelection', $radarClass, '/Script/CoreUObject', 'Function');
    $update = OverlayImport($b->asset, 'SSRadarUpdate', $radarClass, '/Script/CoreUObject', 'Function');
    $view = OverlayImport($b->asset, 'SSRadarSetView', $radarClass, '/Script/CoreUObject', 'Function');
    $widgetTransform = OverlayImport($b->asset, 'WidgetTransform', $b->native['UMG'], '/Script/CoreUObject', 'ScriptStruct');
    $vector2 = OverlayImport($b->asset, 'Vector2D', $b->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct');
    $b->native['WidgetTransform'] = $widgetTransform;
    $b->native['Vector2D'] = $vector2;
    $fn = $b->reserve('ReceiveBeginPlay');
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $int = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $float = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $bool = static fn(bool $n): object => FilterExpr($n ? 'True' : 'False');
    $math = static fn(string $name, object ...$args): object => $b->call('KismetMathLibrary', $name, $args);
    $array = static fn(string $name, object ...$args): object => $b->call('KismetArrayLibrary', $name, $args);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $e]);
    $get = static fn(string $n): object => RadarContext($l('Radar'), FilterVariable($radarClass, $n, true), FilterPointer($radarClass, $n));
    $assertions = [];
    $assertPositions = [];
    $code = [];
    $assert = static function (object $condition, string $label) use (&$code, &$assertions, &$assertPositions): void {
        $assertions[] = $label;
        $code[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $condition]);
        $assertPositions[] = count($code);
    };
    $select = static function (string $tag, bool $sites = false, bool $enabled = true) use (&$code, $l, $setSelection, $bool): void {
        $code[] = RadarContext($l('Radar'), FilterCall($setSelection, [FilterExpr('NameConst', ['Value' => $tag]), $bool($sites), $bool($enabled)], 'FinalFunction'));
    };
    $step = static function (float $x = 0, float $y = 0, float $z = 0, float $yaw = 0, bool $hidden = false) use (&$code, $l, $update, $float, $bool): void {
        $code[] = RadarContext($l('Radar'), FilterCall($update, [$float($x), $float($y), $float($z), $float($yaw), $bool($hidden), $float(0.1)], 'FinalFunction'));
    };
    $count = static fn(): object => $array('Array_Length', $get('SSRadarDisplayIndices'));
    $intEqual = static fn(object $value, int $expected): object => $math('EqualEqual_IntInt', $value, $int($expected));
    $setView = static function (int $zoom, int $size = 1) use (&$code, $l, $view, $int): void {
        $code[] = RadarContext($l('Radar'), FilterCall($view, [$int($zoom), $int($size)], 'FinalFunction'));
    };
    $position = static function (int $pin, string $axis) use ($get, $b, $widgetTransform, $vector2, &$code, $let, $l): object {
        $transform = RadarContext($get('SSRadarPin' . $pin), FilterVariable($b->native['Widget'], 'RenderTransform', true), FilterPointer($b->native['Widget'], 'RenderTransform'));
        $code[] = $let('Transform', $transform);
        $code[] = $let('Translation', FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($widgetTransform, 'Translation'), 'StructExpression' => $l('Transform')]));
        return FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($vector2, $axis), 'StructExpression' => $l('Translation')]);
    };
    $at = static fn(int $pin, string $axis, float $expected): object => $math('NearlyEqual_FloatFloat', $position($pin, $axis), $float($expected), $float(0.001));
    $text = static function (int $pin) use ($b, $get, &$code, $let, $l, $fn): object {
        $code[] = $let('HeightText', $b->method($get('SSRadarHeight' . $pin), 'TextBlock', 'GetText', [], FilterPointer($fn, 'HeightText')));
        return $l('HeightText');
    };
    $height = static fn(int $pin, string $expected): object => $b->call('KismetTextLibrary', 'EqualEqual_TextText', [$text($pin), $b->call('KismetTextLibrary', 'Conv_StringToText', [FilterExpr('StringConst', ['Value' => $expected])])]);
    $visibility = static fn(): object => $b->method($get('SSRadarCanvas'), 'Widget', 'GetVisibility');
    $code[] = $let('Radar', FilterExpr('DynamicCast', ['ClassPtr' => $radarClass, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $radarClass]), FilterExpr('ObjectConst', ['Value' => 0])])]));
    $assert($b->call('KismetSystemLibrary', 'IsValid', [$l('Radar')]), 'actual native radar exists');
    $assert($intEqual($count(), 0), 'initially no points');
    $assert($math('EqualEqual_ByteByte', $visibility(), FilterExpr('ByteConst', ['Value' => 1])), 'initially collapsed');
    $assert($intEqual($get('SSRadarZoomIndex'), 1), 'default 100m zoom');
    $assert($intEqual($get('SSRadarSizeIndex'), 1), 'default 125 percent size');
    $select('None');
    $assert($intEqual($count(), 0), 'unchanged defaults setter is safe');
    $select('SaveStats.Type.hiddenCube'); $step();
    $assert($intEqual($count(), 2), 'default 100m includes exact boundary and excludes farther points');
    $assert($at(0, 'Y', -96), '100m forward boundary projection');
    $assert($at(1, 'X', 96), '100m right boundary projection');
    $select('None'); $setView(2);
    $select('SaveStats.Type.hiddenCube');
    $step();
    $assert($intEqual($count(), 3), 'only selected exact points inside inclusive horizontal radius');
    $select('SaveStats.Type.hiddenCube');
    $assert($intEqual($count(), 3), 'unchanged selected type preserves cached points');
    $assert($at(0, 'X', 0), 'forward screen x'); $assert($at(0, 'Y', -48), 'forward screen y');
    $assert($at(1, 'X', 48), 'right screen x'); $assert($at(1, 'Y', 0), 'right screen y');
    $assert($at(2, 'Y', -96), 'inclusive radius boundary');
    $assert($height(0, '^'), 'above 10m'); $assert($height(1, 'v'), 'below 10m'); $assert($height(2, ''), 'height deadband includes exact 10m');
    $step(0, 0, 0, 90);
    $assert($at(0, 'X', -48), 'yaw90 forward becomes left'); $assert($at(0, 'Y', 0), 'yaw90 forward horizontal');
    $assert($at(1, 'X', 0), 'yaw90 right becomes forward x'); $assert($at(1, 'Y', -48), 'yaw90 right becomes forward y');
    $step(100000, 100000, 0, 0, true);
    $assert($intEqual($get('SSRadarVisitedLastUpdate'), 0), 'menu halts candidate reads');
    $assert($math('EqualEqual_ByteByte', $visibility(), FilterExpr('ByteConst', ['Value' => 1])), 'menu hides radar');
    $step(100000, 100000);
    $assert($intEqual($count(), 0), 'teleport discards previous points');
    $step();
    $assert($intEqual($count(), 3), 'return across cells refreshes');
    $select('SaveStats.Type.hiddenCube', true); $step();
    $assert($intEqual($count(), 4), 'optional matching site included');
    $select('SaveStats.Type.hiddenCube', false, false); $step();
    $assert($intEqual($get('SSRadarVisitedLastUpdate'), 0), 'disabled radar stops work');
    $assert($intEqual($count(), 0), 'disabling clears cached points');
    $select('SaveStats.Type.hiddenRing'); $step();
    $assert($intEqual($get('SSRadarVisitedLastUpdate'), 128), 'first update visits budget');
    $assert($intEqual($count(), 0), 'partial query not committed');
    $step();
    $assert($intEqual($get('SSRadarVisitedLastUpdate'), 128), 'second update visits budget');
    $step();
    $assert($intEqual($get('SSRadarVisitedLastUpdate'), 44), 'final update ignores 5000 distant points');
    $assert($intEqual($count(), 24), 'fixed nearest 24 cap');
    $expectedFirst = array_search('Near0000', $index['pointIds'], true);
    $code[] = $array('Array_Get', $get('SSRadarDisplayIndices'), $int(0), $l('Point'));
    $assert($intEqual($l('Point'), $expectedFirst), 'nearest point first');
    $step(15000, 1000);
    $assert($intEqual($get('SSRadarVisitedLastUpdate'), 128), 'same-cell teleport starts a new bounded query');
    $assert($intEqual($count(), 0), 'same-cell teleport clears old display');
    $assert($math('NearlyEqual_FloatFloat', $get('SSRadarScanX'), $float(15000), $float(0.001)), 'same-cell query anchor replaced');
    $select('None');
    $assert($intEqual($count(), 0), 'clearing selection immediately clears points');
    $assert($math('EqualEqual_ByteByte', $visibility(), FilterExpr('ByteConst', ['Value' => 1])), 'clearing immediately hides radar');
    $assert($math('EqualEqual_BoolBool', $get('SSRadarScanActive'), $bool(false)), 'clearing invalidates unfinished query');
    $setView(0, 2); $select('SaveStats.Type.logicGrid'); $step();
    $assert($intEqual($count(), 2), '50m includes exact boundary and excludes just outside');
    $assert($at(1, 'Y', -96), '50m boundary projects to circle edge');
    $setView(3, 2);
    $assert($intEqual($count(), 0), 'zoom change discards old query immediately');
    $step();
    $assert($intEqual($count(), 7), '400m includes all cardinal boundaries and excludes outside');
    $assert($intEqual($math('Subtract_IntInt', $get('SSRadarMaxRow'), $get('SSRadarRow')), -1), '400m traverses every row to completion');
    $assert($math('NearlyEqual_FloatFloat', $get('SSRadarRadiusCm'), $float(40000), $float(0.01)), '400m runtime range');
    $setView(99, -99);
    $assert($intEqual($get('SSRadarZoomIndex'), 3), 'zoom clamps upper preset');
    $assert($intEqual($get('SSRadarSizeIndex'), 0), 'size clamps lower preset');
    $assert($intEqual($count(), 7), 'size change preserves committed candidates');
    $code[] = $let('Revision', $get('SSRadarRevision'));
    $setView(3, 0);
    $assert($math('EqualEqual_IntInt', $get('SSRadarRevision'), $l('Revision')), 'unchanged view does not bump revision');
    $select('SaveStats.Type.hiddenRing');
    foreach (range(0, 2) as $n) { $step($n * 4000, 0); }
    $assert($intEqual($count(), 24), '400m moving scan completes after ordinary 40m steps');
    $assert($math('LessEqual_IntInt', $get('SSRadarVisitedLastUpdate'), $int(128)), '400m movement stays within callback budget');
    $step(12000, 0);
    $assert($intEqual($count(), 24), 'ordinary movement refresh preserves previous display');
    $travel = static fn(string $n): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . $n]), $bool(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    if ($probeCreate) { $code = [$code[0]]; $assertions = ['probe native radar creation']; }
    if ($probeChecks !== null) { $code = array_slice($code, 0, $assertPositions[$probeChecks - 1]); $assertions = array_slice($assertions, 0, $probeChecks); }
    $code[] = $travel('SSRadarPass');
    $code[] = FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]);
    $code[] = ['label' => 'Fail']; $code[] = $travel('SSRadarFail');
    $b->define('ReceiveBeginPlay', [], ['Radar' => 'Object', 'Point' => 'Int', 'Revision' => 'Int', 'Transform' => 'Struct', 'Translation' => 'Struct', 'HeightText' => 'Text'], $code);
    $receive = $b->asset->Exports[2];
    $receive->SuperIndex = $receive->SuperStruct = OverlayImport($b->asset, 'ReceiveBeginPlay', $actor, '/Script/CoreUObject', 'Function');
    $receive->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $receive->SerializationBeforeSerializationDependencies = [$receive->SuperStruct];
    $b->name('/Game/SaveStatsTests/BP_SSRadarTest');
    $b->name('/Game/SaveStatsTests/SSRadarPass'); $b->name('/Game/SaveStatsTests/SSRadarFail');
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));

    $world = json_decode(file_get_contents($worldPath), false, 512, JSON_THROW_ON_ERROR);
    $level = $settings = $normal = null;
    foreach ($world->Exports as $n => $e) {
        if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.LevelExport, UAssetAPI') { $level = $e; $levelIndex = $n + 1; }
        if ($e->ObjectName === 'WorldSettings_0') { $settings = $e; $settingsIndex = $n + 1; }
        if ($e->{'$type'} === 'UAssetAPI.ExportTypes.NormalExport, UAssetAPI') { $normal ??= $e; }
    }
    if ($level === null || $settings === null) { throw new RuntimeException('Fixture requires a minimal world and settings.'); }
    $engine = OverlayImport($world, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    $mode = OverlayImport($world, 'GameModeBase', $engine, '/Script/CoreUObject', 'Class');
    $package = OverlayImport($world, '/Game/SaveStatsTests/BP_SSRadarTest', 0, '/Script/CoreUObject', 'Package');
    $driverClass = OverlayImport($world, 'BP_SSRadarTest_C', $package, '/Script/Engine', 'BlueprintGeneratedClass');
    $driverDefault = OverlayImport($world, 'Default__BP_SSRadarTest_C', $package, '/Game/SaveStatsTests/BP_SSRadarTest', 'BP_SSRadarTest_C');
    $driverIndex = count($world->Exports) + 1;
    $world->Exports[] = OverlayExport($normal, $driverIndex, 'SSRadarTestDriver', $levelIndex, $driverClass, $driverDefault, []);
    $world->Exports[array_key_last($world->Exports)]->Extras = '';
    $settings->Data = array_values(array_filter($settings->Data, static fn($p): bool => $p->Name !== 'DefaultGameMode'));
    $settings->Data[] = OverlayProperty('Object', 'DefaultGameMode', $mode);
    $settings->CreateBeforeSerializationDependencies[] = $mode;
    $level->Actors = [$settingsIndex, $driverIndex];
    $level->CreateBeforeSerializationDependencies = [$level->Model, $settingsIndex, $driverIndex];
    $world->NameMap = array_values(array_unique(array_merge($world->NameMap, ['Login', 'SSRadarPass', 'SSRadarFail', 'SSRadarTestDriver', 'DefaultGameMode', '/Game/ASophia/Maps/Login', '/Game/SaveStatsTests/SSRadarPass', '/Game/SaveStatsTests/SSRadarFail'], array_column($world->Imports, 'ObjectName'))));
    foreach (['Login' => '/Game/ASophia/Maps/Login', 'SSRadarPass' => '/Game/SaveStatsTests/SSRadarPass', 'SSRadarFail' => '/Game/SaveStatsTests/SSRadarFail'] as $name => $path) {
        $copy = unserialize(serialize($world));
        foreach ($copy->Exports as $e) {
            if ($e->{'$type'} === 'UAssetAPI.ExportTypes.WorldExport, UAssetAPI') { $e->ObjectName = $name; }
            if ($e->{'$type'} === 'UAssetAPI.ExportTypes.LevelExport, UAssetAPI') {
                $e->URL->Map = $path;
                if ($name !== 'Login') { $e->Actors = [$settingsIndex]; $e->CreateBeforeSerializationDependencies = [$level->Model, $settingsIndex]; }
            }
        }
        if ($name !== 'Login') { array_pop($copy->Exports); }
        FixtureSaveAsset($output . '/' . $name . '.json', $copy);
    }
    $result = ['assertions' => count($assertions), 'checks' => $assertions, 'indexedPoints' => count($index['positions']), 'passMap' => '/Game/SaveStatsTests/SSRadarPass', 'failMap' => '/Game/SaveStatsTests/SSRadarFail'];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'world:', 'probe-create', 'probe-checks:']);
        if (!isset($args['output-dir'], $args['world']) || !is_dir($args['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir DIR --world JSON'); }
        echo json_encode(BuildRadarRuntimeFixture(realpath($args['output-dir']), realpath($args['world']), isset($args['probe-create']), isset($args['probe-checks']) ? (int)$args['probe-checks'] : null), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
