<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/live_completion_fixture.php';

/** Real production metadata/native widgets; native mutations stay in test memory. */
function BuildProductionPentadMetadataFixture(string $output, string $build, string $nativeJson, ?int $probeChecks = null): array
{
    $repo = dirname(__DIR__, 2);
    $ids = [11118, 4748, 4751, 4752, 17198];
    $document = json_decode(file_get_contents($build . '/markers.json'), false, 512, JSON_THROW_ON_ERROR);
    $index = json_decode(file_get_contents($build . '/radar-data.json'), true, 512, JSON_THROW_ON_ERROR);
    $native = json_decode(file_get_contents($nativeJson), true, 512, JSON_THROW_ON_ERROR);
    $raw = [];
    foreach ($native['Exports'] as $export) {
        foreach ($export['Data'] ?? [] as $field) {
            if ($field['Name'] !== 'krakenIDToWorldPuzzleData') { continue; }
            foreach ($field['Value'] as [$key, $value]) {
                if (in_array((int)$key['Value'], $ids, true)) { $raw[(int)$key['Value']] = json_decode($value['Value'], true, 512, JSON_THROW_ON_ERROR); }
            }
        }
    }
    unset($native);
    $records = []; $points = [];
    foreach ($ids as $pid) {
        $record = $document->markers->{(string)$pid} ?? null;
        if ($record === null || ($raw[$pid]['KID'] ?? null) !== $pid || ($raw[$pid]['PuzzleType'] ?? '') !== 'seek5') { throw new RuntimeException('Missing real production/native Pentad ' . $pid); }
        $records[(string)$pid] = $record;
        foreach ($record->locations as $location) {
            $part = $location->index;
            if ($part === 0) { continue; }
            if (!isset($location->pentadLocalPosition)) { throw new RuntimeException("Production Pentad $pid relic $part has unknown local metadata."); }
            $matches = [];
            foreach ($index['puzzleIds'] as $n => $pointPid) {
                if ($pointPid === $pid && $index['pentadRelicIndices'][$n] === $part) { $matches[] = $n; }
            }
            if (count($matches) !== 1 || $index['pentadLocalPositions'][$matches[0]] === null) { throw new RuntimeException("Production index identity missing/ambiguous for $pid/$part."); }
            $point = $matches[0];
            foreach (['x', 'y', 'z'] as $axis) {
                if (abs($index['pentadLocalPositions'][$point][$axis] - $location->pentadLocalPosition->$axis) > 0.01) { throw new RuntimeException('Marker/index local metadata drift.'); }
            }
            $points[$pid][$part] = ['point' => $point, 'location' => $location];
        }
        if (count($points[$pid] ?? []) !== 5) { throw new RuntimeException('Expected five real production relics.'); }
    }

    BuildLiveCompletionFixture($output, $build . '/WBP_LargeMap.json', $build . '/WBP_GameHUD.json');
    // Replace the inherited synthetic radar with the exact full-production input.
    foreach (['WBP_SSOverlayRadar.json', 'radar-data.json', 'markers.json'] as $file) { copy($build . '/' . $file, $output . '/' . $file); }
    $world = json_decode(file_get_contents($output . '/Login.json'), false, 512, JSON_THROW_ON_ERROR);
    foreach ($world->Exports as $export) {
        if (!str_starts_with($export->ObjectName, 'SaveStatsMarker_') && !str_starts_with($export->ObjectName, 'SaveStatsSite_')) { continue; }
        $export->ObjectName = 'UnusedProductionMetadata_' . $export->ObjectName;
        foreach ($export->Data ?? [] as $field) { if ($field->Name === 'Tags') { $field->Value = []; } }
    }
    $world = InjectOverlayMarkers($world, (object)['schemaVersion' => 2, 'markers' => (object)$records, 'spawnSites' => (object)[]]);
    foreach ($world->Exports as $export) {
        if (!preg_match('/^SaveStatsMarker_(11118|4748|4751|4752|17198)_([0-5])$/', $export->ObjectName, $match)) { continue; }
        $tag = 'Test.ProductionPentad.' . $match[1] . '.' . $match[2];
        foreach ($export->Data as $field) { if ($field->Name === 'Tags') { $field->Value[] = OverlayProperty('Name', '0', $tag); } }
        $world->NameMap[] = $tag;
    }
    FixtureSaveAsset($output . '/Login.json', $world);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base); $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($b->class->FuncMap as [$name, $fn]) { $b->functions[$name] = $fn; }
    foreach (['Seek5', 'Seek5HiddenObject', 'PuzzleBase', 'ClickableBase', 'CharPuzzleComponent', 'SceneComponent', 'MarkerEmitter'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native[$type === 'SceneComponent' ? 'Engine' : 'IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    foreach (['LocationMarkerData', 'PlayerProgressionData'] as $type) { $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct'); }
    $b->native['BlueprintMapLibrary'] = OverlayImport($b->asset, 'BlueprintMapLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $pkg = OverlayImport($b->asset, '/Game/ASophia/Puzzle/Seek5/BP_Seek5', 0, '/Script/CoreUObject', 'Package');
    $pentadClass = OverlayImport($b->asset, 'BP_Seek5_C', $pkg, '/Script/Engine', 'BlueprintGeneratedClass');
    $classes = [];
    foreach (['Radar' => ['/Game/SaveStats/WBP_SSOverlayRadar', 'WBP_SSOverlayRadar_C'], 'Map' => ['/Game/ASophia/UI/HUD/Map/WBP_LargeMap', 'WBP_LargeMap_C'], 'Hud' => ['/Game/ASophia/UI/HUD/WBP_GameHUD', 'WBP_GameHUD_C']] as $key => [$path, $name]) {
        $pkg = OverlayImport($b->asset, $path, 0, '/Script/CoreUObject', 'Package');
        $classes[$key] = OverlayImport($b->asset, $name, $pkg, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    }
    foreach (['CurrentPentad' => $b->native['Seek5'], 'PuzzleComponent' => $b->native['CharPuzzleComponent'], 'TestMarker' => $b->native['Actor']] as $name => $class) { $b->objectField($name, $class); }
    $fn = $b->functions['VerifyLive'];
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $truth = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $n, object ...$args): object => $b->call('KismetMathLibrary', $n, $args);
    $array = static fn(string $n, object ...$args): object => $b->call('KismetArrayLibrary', $n, $args);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $property = static fn(object $obj, int $owner, string $n): object => RadarContext($obj, FilterVariable($owner, $n, true), FilterPointer($owner, $n));
    $put = static fn(object $obj, int $owner, string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($owner, $n), 'Variable' => $property($obj, $owner, $n), 'Expression' => $v]);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $s($n), 'Expression' => $v]);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $get = static fn(string $n): object => $property($s('Radar'), $classes['Radar'], $n);
    $call = static fn(string $obj, string $n, array $args = [], ?object $pointer = null): object => RadarContext($s($obj), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $n, 'Parameters' => $args]), $pointer);
    $vec = static fn(float $x, float $y, float $z): object => $math('MakeVector', $f($x), $f($y), $f($z));
    $transform = static fn(): object => $math('MakeTransform', $vec(0, 0, 0), $math('MakeRotator', $f(0), $f(0), $f(0)), $vec(1, 1, 1));
    $checks = []; $ends = []; $code = [];
    $assert = static function (object $condition, string $message) use (&$checks, &$ends, &$code, $b, $i): void {
        $checks[] = $message;
        $code[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => FilterCall($b->functions['ProbeCheck'], [$condition, $i(count($checks))], 'LocalFinalFunction')]);
        $ends[] = count($code);
    };
    $create = static fn(int $class): object => FilterExpr('DynamicCast', ['ClassPtr' => $class, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $class]), $s('Controller')])]);
    $code[] = $set('Hud', $create($classes['Hud'])); $code[] = $b->method($s('Hud'), 'UserWidget', 'AddToViewport', [$i(0)]);
    $code[] = $b->call('WidgetBlueprintLibrary', 'GetAllWidgetsOfClass', [FilterExpr('Self'), $s('Candidates'), FilterExpr('ObjectConst', ['Value' => $classes['Radar']]), $truth(false)]);
    $code[] = $array('Array_Get', $s('Candidates'), $i(0), $s('Radar'));
    $assert($valid($s('Radar')), 'exact full-production radar created through actual HUD');
    $code[] = $b->call('KismetSystemLibrary', 'K2_ClearTimer', [$s('Radar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]);
    $code[] = $set('Map', $create($classes['Map'])); $code[] = $b->method($s('Map'), 'UserWidget', 'AddToViewport', [$i(10)]);
    $assert($math('EqualEqual_ObjectObject', $property($s('Map'), $classes['Map'], 'SSOverlayRadarState'), $s('Radar')), 'exact production map binds exact production radar');
    $code[] = $put($s('Map'), $classes['Map'], 'SSOverlaySelectedTag', FilterExpr('NameConst', ['Value' => 'SaveStats.Type.seek5']));
    $code[] = $set('PuzzleComponent', $property($s('Player'), $b->native['SophiaCharacter'], 'CharPuzzleComponent'));
    $workers = static fn(): object => $property($s('PuzzleComponent'), $b->native['CharPuzzleComponent'], 'workedOnPuzzles');
    $progressData = static fn(): object => $property($s('Progression'), $b->native['GISProgression'], 'PlayerProgression');
    $progressMap = static fn(): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'), 'StructExpression' => $progressData()]);
    $objects = static fn(): object => $property($s('CurrentPentad'), $b->native['Seek5'], 'HiddenObjects');
    $update = static fn(object $location): object => $call('Radar', 'SSRadarUpdate', [$f($location->x), $f($location->y), $f($location->z), $f(0), $truth(false), $f(0.1)]);
    $scan = static function (object $location) use (&$code, $update, $call): void {
        $code[] = $update($location); $code[] = $call('Radar', 'SSRadarStartScan');
        foreach (range(1, 24) as $_) { $code[] = $call('Radar', 'SSRadarScan'); }
        $code[] = $call('Radar', 'SSRadarRender');
    };
    $mapVisible = static function (bool $expected, string $message) use (&$code, $b, $s, $l, $fn, $property, $assert, $math, $truth): void {
        $code[] = FilterExpr('Let', ['Value' => FilterPointer($fn, 'Emitter'), 'Variable' => $l('Emitter'), 'Expression' => $b->method($s('TestMarker'), 'Actor', 'GetComponentByClass', [FilterExpr('ObjectConst', ['Value' => $b->native['MarkerEmitter']])])]);
        $read = FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['LocationMarkerData'], 'VisibleOnMap'), 'StructExpression' => $property($l('Emitter'), $b->native['MarkerEmitter'], 'Marker')]);
        $assert($math('EqualEqual_BoolBool', $read, $truth($expected)), $message);
    };
    foreach ($ids as $pid) {
        $code[] = $array('Array_Clear', $workers());
        $code[] = $b->call('BlueprintMapLibrary', 'Map_Add', [$progressMap(), $i($pid), $s('UnsolvedSeed')]);
        $code[] = $set('CurrentPentad', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['Seek5'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $pentadClass]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])]));
        $code[] = $b->method($s('CurrentPentad'), 'Actor', 'SetActorTickEnabled', [$truth(false)]);
        $code[] = $set('CurrentPentad', $b->call('GameplayStatics', 'FinishSpawningActor', [$s('CurrentPentad'), $transform()]));
        $assert($valid($s('CurrentPentad')), "actual cooked Pentad $pid exists");
        $code[] = $put($s('CurrentPentad'), $b->native['PuzzleBase'], 'puzzleData', FilterExpr('StringConst', ['Value' => json_encode($raw[$pid], JSON_THROW_ON_ERROR)]));
        $code[] = $b->method($s('CurrentPentad'), 'PuzzleBase', 'OnRep_PuzzleData');
        $assert($math('EqualEqual_IntInt', $array('Array_Length', $objects()), $i(5)), "$pid actual authoritative record deserializes five native components");
        $code[] = $array('Array_Swap', $objects(), $i(0), $i(4));
        $code[] = $put($s('CurrentPentad'), $b->native['PuzzleBase'], 'KrakenId', $i($pid));
        $code[] = $put($s('CurrentPentad'), $b->native['ClickableBase'], 'interactingPlayer', $s('Player'));
        $code[] = $array('Array_AddUnique', $workers(), $s('CurrentPentad'));
        $code[] = $call('Radar', 'SSRadarSetSelection', [FilterExpr('NameConst', ['Value' => 'SaveStats.Type.seek5']), $truth(false), $truth(true)]);
        $code[] = $update($points[$pid][1]['location']);
        $assert($math('EqualEqual_IntInt', $get('SSRadarFocusPuzzleId'), $i($pid)), "$pid real native authority activates production focus");
        foreach ($points[$pid] as $part => ['point' => $point, 'location' => $location]) {
            $local = $location->pentadLocalPosition;
            $object = $get('SSRadarPentadObject' . $part);
            $assert($valid($object), "$pid/$part production metadata binds a valid reordered native component");
            $code[] = $let('Position', $property($object, $b->native['SceneComponent'], 'RelativeLocation'));
            $assert($math('LessEqual_FloatFloat', $math('Vector_Distance', $l('Position'), $vec($local->x, $local->y, $local->z)), $f(0.1)), "$pid/$part exact exported local identity equals actual native location");
            $code[] = $b->call('GameplayStatics', 'GetAllActorsWithTag', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => 'Test.ProductionPentad.' . $pid . '.' . $part]), $s('Candidates')]);
            $code[] = $array('Array_Get', $s('Candidates'), $i(0), $s('TestMarker'));
            $assert($valid($s('TestMarker')), "$pid/$part copied production marker record exists on map");
            $scan($location); $code[] = $call('Map', 'SSOverlayRefresh');
            $assert($array('Array_Contains', $get('SSRadarDisplayIndices'), $i($point)), "$pid/$part actual production radar point is initially present");
            $mapVisible(true, "$pid/$part actual production map initially shows relic");
            $code[] = FilterExpr('LetBool', ['VariableExpression' => $property($object, $b->native['Seek5HiddenObject'], 'isFound'), 'AssignmentExpression' => $truth(true)]);
            $scan($location); $code[] = $call('Map', 'SSOverlayRefresh');
            $assert($get('SSRadarPentadFound' . $part), "$pid/$part native found flag reaches exact production cache");
            $assert($math('Not_PreBool', $array('Array_Contains', $get('SSRadarDisplayIndices'), $i($point))), "$pid/$part collected exact production radar point disappears");
            $mapVisible(false, "$pid/$part collected actual production map relic disappears");
            $assert($math('EqualEqual_IntInt', $b->method($s('CurrentPentad'), 'Seek5', 'GetNumberCompleted'), $i(1)), "$pid/$part independent native count proves only one relic collected");
            foreach (range(1, 5) as $other) {
                if ($other !== $part) { $assert($math('Not_PreBool', $get('SSRadarPentadFound' . $other)), "$pid/$part leaves distinct relic $other unfound"); }
            }
            $code[] = FilterExpr('LetBool', ['VariableExpression' => $property($object, $b->native['Seek5HiddenObject'], 'isFound'), 'AssignmentExpression' => $truth(false)]);
            $scan($location); $code[] = $call('Map', 'SSOverlayRefresh');
            $assert($array('Array_Contains', $get('SSRadarDisplayIndices'), $i($point)), "$pid/$part native reset restores exact production radar point");
            $mapVisible(true, "$pid/$part native reset restores production map relic");
        }
        $code[] = $array('Array_Clear', $workers());
        $code[] = $put($s('CurrentPentad'), $b->native['ClickableBase'], 'interactingPlayer', FilterExpr('ObjectConst', ['Value' => 0]));
        $code[] = $b->method($s('CurrentPentad'), 'Actor', 'K2_DestroyActor');
    }
    if ($probeChecks !== null) { $code = array_slice($code, 0, $ends[$probeChecks - 1]); $checks = array_slice($checks, 0, $probeChecks); }
    $timer = static fn(): object => $let('Timer', $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'Finish']), $f(0.2), $truth(false), $f(0), $f(0)]));
    $code = [...$code, FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $truth(true)]), $timer(), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer()];
    $b->define('VerifyLive', [], ['Position' => 'Struct', 'Emitter' => 'Object', 'Timer' => 'Struct'], $code);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $field) { if ($field->Name === 'Emitter') { $field->PropertyClass = $b->native['MarkerEmitter']; } }
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $export) { if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$export->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; } }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $manifest = ['assertions' => count($checks), 'checks' => $checks, 'puzzleIds' => $ids, 'productionBuild' => $build,
        'productionMarkersSha256' => hash_file('sha256', $build . '/markers.json'), 'productionRadarDataSha256' => hash_file('sha256', $build . '/radar-data.json'),
        'nativeDatabaseSha256' => hash_file('sha256', $nativeJson), 'sourceLocalsInjectedByFixture' => false,
        'productionNativeWidgetsMustBeCopiedByteIdentically' => true, 'markerRecordsCopiedWithoutCoordinateOrLocalChanges' => true,
        'markerNativeExportsByteIdentical' => false, 'nativeAuthoritySeededOnlyInIsolatedMemory' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $manifest;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['output-dir:', 'build-dir:', 'puzzle-database-json:', 'probe-checks:']);
        foreach (['output-dir', 'build-dir', 'puzzle-database-json'] as $key) { if (!isset($options[$key])) { throw new InvalidArgumentException('Provide --output-dir --build-dir --puzzle-database-json'); } }
        echo json_encode(BuildProductionPentadMetadataFixture(realpath($options['output-dir']), realpath($options['build-dir']), realpath($options['puzzle-database-json']), isset($options['probe-checks']) ? (int)$options['probe-checks'] : null), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
