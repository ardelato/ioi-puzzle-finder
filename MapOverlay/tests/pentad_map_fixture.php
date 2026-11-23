<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/live_completion_fixture.php';

/** Actual map/HUD assets, native active-Pentad state, isolated in-memory progress. */
function BuildPentadMapFixture(string $output, string $mapPath, string $hudPath): array
{
    BuildLiveCompletionFixture($output, $mapPath, $hudPath);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $records = [];
    foreach ([41001 => 'A', 41002 => 'B'] as $pid => $prefix) {
        $locations = [];
        foreach (range(0, 5) as $part) {
            $locations[] = (object)['index' => $part, 'role' => $part === 0 ? 'pillar' : 'object', 'label' => $prefix . $part,
                'x' => 3000.0 + $part * 100, 'y' => 4000.0, 'z' => 5000.0];
            if ($part > 0) { $locations[count($locations) - 1]->pentadLocalPosition = (object)['x' => (float)(100 + ($part - 1) * 300), 'y' => (float)(200 + ($part - 1) * 300), 'z' => (float)(300 + ($part - 1) * 300)]; }
        }
        $records[(string)$pid] = (object)['pid' => $pid, 'ptype' => 'seek5', 'iconKey' => 'seek5', 'group' => 'static', 'geometry' => 'star',
            'label' => 'Test Pentad ' . $prefix, 'x' => 3000.0, 'y' => 4000.0, 'z' => 5000.0, 'locations' => $locations];
    }
    foreach (range(0, 129) as $n) {
        $records[(string)(43000 + $n)] = (object)['pid' => 43000 + $n, 'ptype' => 'seek5', 'iconKey' => 'seek5', 'group' => 'static', 'geometry' => 'point',
            'label' => 'Distant pillar', 'x' => 100000.0 + $n, 'y' => 4000.0, 'z' => 5000.0,
            'locations' => [(object)['index' => 0, 'role' => 'pillar', 'label' => 'Pillar', 'x' => 100000.0 + $n, 'y' => 4000.0, 'z' => 5000.0]]];
    }
    $records['42001'] = (object)['pid' => 42001, 'ptype' => 'hiddenCube', 'iconKey' => 'hiddenCube', 'group' => 'static', 'geometry' => 'point',
        'label' => 'Other filter', 'x' => 3100.0, 'y' => 4000.0, 'z' => 5000.0,
        'locations' => [(object)['index' => 0, 'role' => 'point', 'label' => 'Cube', 'x' => 3100.0, 'y' => 4000.0, 'z' => 5000.0]]];
    $site = (object)['siteId' => 'site-0123456789abcdef', 'ptype' => 'spawnSite', 'iconKey' => 'spawnSite', 'group' => 'spawn', 'allowedTypes' => ['seek5'],
        'label' => 'Possible Pentad site', 'x' => 3000.0, 'y' => 4000.0, 'z' => 5000.0,
        'locations' => [(object)['index' => 0, 'role' => 'container', 'label' => 'Possible site', 'x' => 3000.0, 'y' => 4000.0, 'z' => 5000.0]]];
    $document = (object)['schemaVersion' => 2, 'markers' => (object)$records, 'spawnSites' => (object)[$site->siteId => $site]];
    $world = json_decode(file_get_contents($output . '/Login.json'), false, 512, JSON_THROW_ON_ERROR);
    foreach ($world->Exports as $export) {
        if (!str_starts_with($export->ObjectName, 'SaveStatsMarker_') && !str_starts_with($export->ObjectName, 'SaveStatsSite_')) { continue; }
        $export->ObjectName = 'UnusedPentadFixture_' . $export->ObjectName;
        foreach ($export->Data ?? [] as $field) { if ($field->Name === 'Tags') { $field->Value = []; } }
    }
    $world = InjectOverlayMarkers($world, $document);
    $actorNames = ['SaveStatsMarker_42001_0' => 'Cube', 'SaveStatsSite_0123456789abcdef_0' => 'PossibleSite'];
    foreach ([41001 => 'A', 41002 => 'B'] as $pid => $prefix) {
        foreach (range(0, 5) as $part) { $actorNames['SaveStatsMarker_' . $pid . '_' . $part] = $prefix . $part; }
    }
    foreach ($world->Exports as $export) {
        if (!isset($actorNames[$export->ObjectName])) { continue; }
        foreach ($export->Data as $field) {
            if ($field->Name === 'Tags') { $field->Value[] = OverlayProperty('Name', '0', 'Test.PentadMap.' . $actorNames[$export->ObjectName]); }
        }
        $world->NameMap[] = 'Test.PentadMap.' . $actorNames[$export->ObjectName];
    }
    FixtureSaveAsset($output . '/Login.json', $world);
    $index = BuildRadarIndex(ExpandOverlayMarkers($document), array_intersect_key(GetOverlayTypes(), array_flip(['seek5', 'hiddenCube'])));
    [$radar, $radarManifest] = BuildRadarWidget($base, $index);
    file_put_contents($output . '/WBP_SSOverlayRadar.json', json_encode($radar, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-manifest.json', json_encode($radarManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base); $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($b->class->FuncMap as [$name, $id]) { $b->functions[$name] = $id; }
    foreach (['Seek5', 'Seek5HiddenObject', 'PuzzleBase', 'ClickableBase', 'CharPuzzleComponent', 'MarkerEmitter'] as $name) {
        $b->native[$name] = OverlayImport($b->asset, $name, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    foreach (['LocationMarkerData', 'PlayerProgressionData'] as $name) { $b->native[$name] = OverlayImport($b->asset, $name, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct'); }
    $b->native['BlueprintMapLibrary'] = OverlayImport($b->asset, 'BlueprintMapLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $pentadPackage = OverlayImport($b->asset, '/Game/ASophia/Puzzle/Seek5/BP_Seek5', 0, '/Script/CoreUObject', 'Package');
    $pentadBlueprint = OverlayImport($b->asset, 'BP_Seek5_C', $pentadPackage, '/Script/Engine', 'BlueprintGeneratedClass');
    $classes = [];
    foreach (['Radar' => ['/Game/SaveStats/WBP_SSOverlayRadar', 'WBP_SSOverlayRadar_C'], 'Map' => ['/Game/ASophia/UI/HUD/Map/WBP_LargeMap', 'WBP_LargeMap_C'], 'Hud' => ['/Game/ASophia/UI/HUD/WBP_GameHUD', 'WBP_GameHUD_C']] as $key => [$path, $name]) {
        $pkg = OverlayImport($b->asset, $path, 0, '/Script/CoreUObject', 'Package');
        $classes[$key] = OverlayImport($b->asset, $name, $pkg, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    }
    foreach (array_values($actorNames) as $name) { $b->objectField($name, $b->native['Actor']); }
    foreach (['PentadA' => 'Seek5', 'PentadB' => 'Seek5', 'PuzzleComponent' => 'CharPuzzleComponent'] as $name => $type) { $b->objectField($name, $b->native[$type]); }
    $fn = $b->functions['VerifyLive'];
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $i = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $f = static fn(float $v): object => FilterExpr('FloatConst', ['Value' => $v]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $n, object ...$args): object => $b->call('KismetMathLibrary', $n, $args);
    $array = static fn(string $n, object ...$args): object => $b->call('KismetArrayLibrary', $n, $args);
    $valid = static fn(object $obj): object => $b->call('KismetSystemLibrary', 'IsValid', [$obj]);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $s($n), 'Expression' => $v]);
    $property = static fn(object $obj, int $owner, string $n): object => RadarContext($obj, FilterVariable($owner, $n, true), FilterPointer($owner, $n));
    $put = static fn(object $obj, int $owner, string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($owner, $n), 'Variable' => $property($obj, $owner, $n), 'Expression' => $v]);
    $get = static fn(string $n): object => $property($s('Radar'), $classes['Radar'], $n);
    $mapGet = static fn(string $n): object => $property($s('Map'), $classes['Map'], $n);
    $call = static fn(string $object, string $n, array $args = [], ?object $ptr = null): object => RadarContext($s($object), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $n, 'Parameters' => $args]), $ptr);
    $checks = []; $code = [];
    $assert = static function (object $condition, string $message) use (&$code, &$checks, $b, $i): void {
        $checks[] = $message;
        $code[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => FilterCall($b->functions['ProbeCheck'], [$condition, $i(count($checks))], 'LocalFinalFunction')]);
    };
    $create = static fn(int $class): object => FilterExpr('DynamicCast', ['ClassPtr' => $class, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $class]), $s('Controller')])]);
    $code[] = $set('Hud', $create($classes['Hud']));
    $code[] = $b->method($s('Hud'), 'UserWidget', 'AddToViewport', [$i(0)]);
    $code[] = $b->call('WidgetBlueprintLibrary', 'GetAllWidgetsOfClass', [FilterExpr('Self'), $s('Candidates'), FilterExpr('ObjectConst', ['Value' => $classes['Radar']]), $bool(false)]);
    $code[] = $array('Array_Get', $s('Candidates'), $i(0), $s('Radar'));
    $assert($valid($s('Radar')), 'actual embedded radar exists');
    $code[] = $b->call('KismetSystemLibrary', 'K2_ClearTimer', [$s('Radar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]);
    $code[] = $set('Map', $create($classes['Map'])); $code[] = $b->method($s('Map'), 'UserWidget', 'AddToViewport', [$i(10)]);
    $assert($math('EqualEqual_ObjectObject', $mapGet('SSOverlayRadarState'), $s('Radar')), 'actual map binds actual gameplay radar');
    foreach (array_values($actorNames) as $name) {
        $code[] = $b->call('GameplayStatics', 'GetAllActorsWithTag', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => 'Test.PentadMap.' . $name]), $s('Candidates')]);
        $code[] = $array('Array_Get', $s('Candidates'), $i(0), $s($name));
        $assert($valid($s($name)), 'actual marker ' . $name . ' exists');
    }
    $visible = static function (string $name, bool $expected, string $context) use (&$code, $b, $s, $l, $fn, $property, $assert, $math, $bool): void {
        $code[] = FilterExpr('Let', ['Value' => FilterPointer($fn, 'Emitter'), 'Variable' => $l('Emitter'), 'Expression' => $b->method($s($name), 'Actor', 'GetComponentByClass', [FilterExpr('ObjectConst', ['Value' => $b->native['MarkerEmitter']])])]);
        $read = FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['LocationMarkerData'], 'VisibleOnMap'), 'StructExpression' => $property($l('Emitter'), $b->native['MarkerEmitter'], 'Marker')]);
        $assert($math('EqualEqual_BoolBool', $read, $bool($expected)), $context . ' ' . $name . ' visible=' . ($expected ? 'true' : 'false'));
    };
    $expect = static function (?string $active, string $context, bool $bSolved = false) use ($visible): void {
        $visible('A0', true, $context); $visible('B0', !$bSolved, $context);
        foreach (['A', 'B'] as $prefix) { foreach (range(1, 5) as $part) { $visible($prefix . $part, $active === $prefix && !($prefix === 'B' && $bSolved), $context); } }
    };
    $select = static function (string $type) use (&$code, $property, $s, $classes, $call): void {
        $code[] = $call('Map', 'SSOverlayClearTypes');
        $code[] = FilterExpr('LetBool', ['VariableExpression' => $property($s('Map'), $classes['Map'], 'SSOverlayType_' . $type), 'AssignmentExpression' => FilterExpr('True')]);
        $code[] = $call('Map', 'SSOverlayRefresh');
    };
    $select('seek5'); $expect(null, 'inactive overview');
    $visible('PossibleSite', false, 'possible sites disabled');
    $code[] = FilterExpr('LetBool', ['VariableExpression' => $mapGet('SSOverlaySource_spawn'), 'AssignmentExpression' => $bool(true)]);
    $code[] = $call('Map', 'SSOverlayRefresh'); $visible('PossibleSite', true, 'possible sites enabled');
    $assert($math('Greater_IntInt', $array('Array_Length', $mapGet('SSOverlayPreviousActors')), $i(128)), 'selected map exceeds two64-marker batches');
    $code[] = $put($s('Map'), $classes['Map'], 'SSOverlayRadarState', FilterExpr('ObjectConst', ['Value' => 0]));
    $assert($math('Not_PreBool', $call('Map', 'SSOverlayAllowsPentadActor', [$s('A1')], FilterPointer($fn, 'Boolean'))), 'missing shared radar hides relic');
    $assert($call('Map', 'SSOverlayAllowsPentadActor', [$s('A0')], FilterPointer($fn, 'Boolean')), 'missing shared radar preserves ordinary pillar predicate');
    $code[] = $put($s('Map'), $classes['Map'], 'SSOverlayRadarState', $s('Radar'));
    $vector = static fn(): object => $math('MakeVector', $f(0), $f(0), $f(0));
    $transform = static fn(): object => $math('MakeTransform', $vector(), $math('MakeRotator', $f(0), $f(0), $f(0)), $math('MakeVector', $f(1), $f(1), $f(1)));
    $raw = (object)['PuzzleClass' => 'BP_Seek5_C', 'PuzzleType' => 'seek5', 'SpawnBehaviour' => 0, 'ActorTransform' => '3000,4000,5000|17,63,11|2,3,4',
        'BoundaryTransform' => '0,0,0|0,0,0|1,1,1', 'CentralPillarTransform' => '0,0,50|0,0,0|1,1,1',
        'DuplicatedObjectOfType-Seek5HiddenObject' => (object)['OriginalTransform' => '100,200,300|0,0,0|1,1,1', 'DuplicateTransform-0' => '400,500,600|0,0,0|1,1,1',
            'DuplicateTransform-1' => '700,800,900|0,0,0|1,1,1', 'DuplicateTransform-2' => '1000,1100,1200|0,0,0|1,1,1', 'DuplicateTransform-3' => '1300,1400,1500|0,0,0|1,1,1']];
    $reference = json_decode(file_get_contents(OverlayTestDataPath('ModMaker/media/data/Puzzles.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach ($reference['puzzles'] as $puzzle) { if (($puzzle['puzzleType'] ?? '') === 'seek5') { $raw = (object)array_replace((array)json_decode($puzzle['serialized'], false, 512, JSON_THROW_ON_ERROR), (array)$raw); break; } }
    foreach (['PentadA' => 41001, 'PentadB' => 41002] as $field => $pid) {
        $code[] = $set($field, FilterExpr('DynamicCast', ['ClassPtr' => $b->native['Seek5'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $pentadBlueprint]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])]));
        $code[] = $b->method($s($field), 'Actor', 'SetActorTickEnabled', [$bool(false)]);
        $code[] = $set($field, $b->call('GameplayStatics', 'FinishSpawningActor', [$s($field), $transform()]));
        $assert($valid($s($field)), 'native ' . $field . ' exists');
        $raw->KID = $pid;
        $code[] = $put($s($field), $b->native['PuzzleBase'], 'puzzleData', FilterExpr('StringConst', ['Value' => json_encode($raw, JSON_THROW_ON_ERROR)]));
        $code[] = $b->method($s($field), 'PuzzleBase', 'OnRep_PuzzleData');
        $objects = $property($s($field), $b->native['Seek5'], 'HiddenObjects');
        $assert($math('EqualEqual_IntInt', $array('Array_Length', $objects), $i(5)), 'native ' . $field . ' deserializes five hidden components');
        $code[] = $array('Array_Swap', $objects, $i(0), $i(4));
        $code[] = $put($s($field), $b->native['PuzzleBase'], 'KrakenId', $i($pid));
    }
    $code[] = $set('PuzzleComponent', $property($s('Player'), $b->native['SophiaCharacter'], 'CharPuzzleComponent'));
    $assert($valid($s('PuzzleComponent')), 'native player puzzle component exists');
    $activate = static function (?string $which) use (&$code, $array, $property, $put, $s, $b): void {
        $workers = $property($s('PuzzleComponent'), $b->native['CharPuzzleComponent'], 'workedOnPuzzles');
        $code[] = $array('Array_Clear', $workers);
        foreach (['PentadA', 'PentadB'] as $field) { $code[] = $put($s($field), $b->native['ClickableBase'], 'interactingPlayer', $field === $which ? $s('Player') : FilterExpr('ObjectConst', ['Value' => 0])); }
        if ($which !== null) { $code[] = $array('Array_AddUnique', $workers, $s($which)); }
    };
    $seed = static fn(int $pid, string $status): object => $b->call('BlueprintMapLibrary', 'Map_Add', [FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'), 'StructExpression' => $property($s('Progression'), $b->native['GISProgression'], 'PlayerProgression')]), $i($pid), $s($status)]);
    $code[] = $seed(41001, 'UnsolvedSeed'); $code[] = $seed(41002, 'UnsolvedSeed');
    $activate('PentadA'); $code[] = $call('Map', 'SSOverlayRefresh');
    $assert($math('EqualEqual_IntInt', $get('SSRadarFocusPuzzleId'), $i(41001)), 'full map refresh discovers active native Pentad without radar Tick');
    $expect('A', 'active A full refresh');
    $visible('PossibleSite', true, 'active Pentad preserves explicitly enabled possible sites on full map');
    $setFound = static function (int $part, bool $found) use (&$code, $get, $property, $b, $bool, $valid, $assert): void {
        $component = $get('SSRadarPentadObject' . $part);
        $assert($valid($component), 'local-coordinate cache matched native relic ' . $part);
        $code[] = FilterExpr('LetBool', ['VariableExpression' => $property($component, $b->native['Seek5HiddenObject'], 'isFound'), 'AssignmentExpression' => $bool($found)]);
    };
    $setFound(1, true);
    foreach (range(1, 3) as $_) { $code[] = $call('Map', 'SSOverlayRefreshProgress'); $assert($math('LessEqual_IntInt', $mapGet('SSOverlayProgressVisited'), $i(64)), 'found-relic progress callback remains bounded64'); }
    $visible('A1', false, 'collected A relic bounded refresh'); $visible('A2', true, 'collected A relic bounded refresh'); $visible('A0', true, 'collected A relic bounded refresh');
    $setFound(1, false);
    foreach (range(1, 3) as $_) { $code[] = $call('Map', 'SSOverlayRefreshProgress'); }
    $expect('A', 'reset collected native flag restores relic');
    $setFound(2, true); $code[] = $call('Map', 'SSOverlayRefresh');
    $visible('A2', false, 'collected A relic full refresh'); $visible('A1', true, 'collected A relic full refresh');
    $setFound(2, false); $code[] = $call('Map', 'SSOverlayRefresh');
    $tick = static fn(float $delta): object => $call('Map', 'Tick', [$l('Geometry'), $f($delta)]);
    $code[] = $call('Radar', 'SSRadarAcknowledgeMapProgress');
    $setFound(3, true); $code[] = $tick(0.25);
    $assert($get('SSRadarMapProgressDirty'), 'quarter-second map Tick discovers newly collected native relic and marks shared visibility dirty');
    $code[] = $tick(0.01);
    $assert($math('Not_PreBool', $get('SSRadarMapProgressDirty')), 'next map Tick consumes dirty full refresh and acknowledges once');
    $visible('A3', false, 'native Tick collected relic hide');
    $code[] = $tick(0.25);
    $assert($math('EqualEqual_IntInt', $mapGet('SSOverlayProgressCursor'), $i(64)), 'stable map Tick advances first bounded64 after dirty acknowledgement');
    $code[] = $tick(0.25);
    $assert($math('EqualEqual_IntInt', $mapGet('SSOverlayProgressCursor'), $i(128)), 'stable map Tick advances second bounded64 instead of repeating full refresh');
    $assert($math('Not_PreBool', $get('SSRadarMapProgressDirty')), 'stable found cache does not create a perpetual full-refresh loop');
    $setFound(3, false); $code[] = $tick(0.25); $code[] = $tick(0.01);
    $visible('A3', true, 'native Tick reset found flag restores relic');
    $code[] = FilterExpr('LetBool', ['VariableExpression' => $mapGet('SSOverlaySource_spawn'), 'AssignmentExpression' => $bool(false)]);
    foreach (range(1, 3) as $_) { $code[] = $call('Map', 'SSOverlayRefreshProgress'); }
    $visible('PossibleSite', false, 'bounded progress preserves disabled possible-site source');
    $code[] = $call('Radar', 'SSRadarToggleEnabled'); $activate('PentadB');
    foreach (range(1, 3) as $_) { $code[] = $call('Map', 'SSOverlayRefreshProgress'); $assert($math('LessEqual_IntInt', $mapGet('SSOverlayProgressVisited'), $i(64)), 'switch progress callback remains bounded64'); }
    $assert($math('Not_PreBool', $get('SSRadarEnabled')), 'radar stays disabled during map-only active switch');
    $expect('B', 'active B bounded refresh');
    $code[] = $seed(41002, 'SolvedSeed'); $code[] = $call('Map', 'SSOverlayRefresh'); $expect(null, 'completed B full refresh', true);
    $code[] = $seed(41002, 'UnsolvedSeed'); $activate(null);
    foreach (range(1, 3) as $_) { $code[] = $call('Map', 'SSOverlayRefreshProgress'); }
    $expect(null, 'leave bounded refresh');
    $select('hiddenCube'); $visible('Cube', true, 'other filter'); $visible('A0', false, 'other filter'); $visible('A1', false, 'other filter');
    $select('seek5'); $expect(null, 'return to inactive Pentad filter');
    $activate('PentadA'); $code[] = $b->method($s('Map'), 'Widget', 'RemoveFromParent');
    $code[] = $set('Map', $create($classes['Map'])); $code[] = $b->method($s('Map'), 'UserWidget', 'AddToViewport', [$i(10)]);
    $expect('A', 'fresh reopen reconciles current native focus');
    $code[] = FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $bool(true)]);
    $timer = static fn(): object => FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => $l('Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'Finish']), $f(0.2), $bool(false), $f(0), $f(0)])]);
    $code = [...$code, $timer(), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer()];
    $b->define('VerifyLive', [], ['Timer' => 'Struct', 'Geometry' => 'Struct', 'Emitter' => 'Object', 'Boolean' => 'Bool'], $code);
    $slateCore = OverlayImport($b->asset, '/Script/SlateCore', 0, '/Script/CoreUObject', 'Package');
    $geometry = OverlayImport($b->asset, 'Geometry', $slateCore, '/Script/CoreUObject', 'ScriptStruct');
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $field) { if ($field->Name === 'Geometry') { $field->Struct = $geometry; $field->ElementSize = 56; } }
    foreach ($b->asset->Exports as $export) { foreach ($export->LoadedProperties ?? [] as $field) { if ($field->Name === 'Emitter') { $field->PropertyClass = $b->native['MarkerEmitter']; } } }
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $export) { if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$export->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; } }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $manifest = ['assertions' => count($checks), 'checks' => $checks, 'actualProductionMap' => $mapPath, 'actualProductionHud' => $hudPath,
        'nativeActiveAuthority' => true, 'periodicBudget' => 64, 'isolatedMemoryOnly' => true, 'nativeCollectionComponents' => true,
        'nativeCollectionSlotsReordered' => true, 'nonunitParentTransform' => true];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $manifest;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['output-dir:', 'map-widget:', 'hud-widget:']);
        foreach (['output-dir', 'map-widget', 'hud-widget'] as $key) { if (!isset($options[$key])) { throw new InvalidArgumentException('Provide --output-dir --map-widget --hud-widget'); } }
        echo json_encode(BuildPentadMapFixture(realpath($options['output-dir']), realpath($options['map-widget']), realpath($options['hud-widget'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
