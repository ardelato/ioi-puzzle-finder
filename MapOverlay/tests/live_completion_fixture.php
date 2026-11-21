<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/live_progress_probe.php';
require_once __DIR__ . '/filter_runtime_fixture.php';

function BuildLiveCompletionFixture(string $output, string $mapPath, string $hudPath): array
{
    BuildLiveProgressProbe($output);
    $repo = dirname(__DIR__, 2);
    $point = static fn(int $index, float $x): object => (object)['index' => $index, 'role' => 'point', 'label' => 'Live fixture', 'x' => $x, 'y' => 4000.0, 'z' => 5000.0];
    $record = static fn(int $pid, array $locations): object => (object)['pid' => $pid, 'group' => 'static', 'ptype' => 'matchbox', 'iconKey' => 'matchbox',
        'label' => 'Live fixture ' . $pid, 'geometry' => count($locations) > 1 ? 'pair' : 'point', 'x' => $locations[0]->x, 'y' => 4000.0, 'z' => 5000.0, 'locations' => $locations];
    $records = ['5594' => $record(5594, [$point(0, 3100), $point(1, 3200)]), '5595' => $record(5595, [$point(0, 3300)]), '1000000001' => $record(1000000001, [$point(0, 3500)])];
    foreach (range(0, 129) as $n) { $records[(string)(7000 + $n)] = $record(7000 + $n, [$point(0, 100000 + $n)]); }
    $site = (object)['siteId' => 'site-0123456789abcdef', 'group' => 'spawn', 'ptype' => 'spawnSite', 'iconKey' => 'spawnSite', 'label' => 'Live possible site',
        'allowedTypes' => ['matchbox'], 'x' => 3400.0, 'y' => 4000.0, 'z' => 5000.0, 'locations' => [$point(0, 3400)]];
    $document = (object)['schemaVersion' => 2, 'markers' => (object)$records, 'spawnSites' => (object)[$site->siteId => $site]];
    $world = json_decode(file_get_contents($output . '/Login.json'), false, 512, JSON_THROW_ON_ERROR);
    // The minimal inherited world retains unused exports outside Level.Actors.
    // Keep their indices stable, but remove their overlay identity before adding
    // this fixture's marker actors.
    foreach ($world->Exports as $export) {
        if (!str_starts_with($export->ObjectName, 'SaveStatsMarker_') && !str_starts_with($export->ObjectName, 'SaveStatsSite_')) { continue; }
        $export->ObjectName = 'UnusedFixture_' . $export->ObjectName;
        foreach ($export->Data ?? [] as $property) { if ($property->Name === 'Tags') { $property->Value = []; } }
    }
    $world = InjectOverlayMarkers($world, $document);
    $names = ['SaveStatsMarker_5594_0' => 'PairA', 'SaveStatsMarker_5594_1' => 'PairB', 'SaveStatsMarker_5595_0' => 'Control', 'SaveStatsMarker_1000000001_0' => 'High', 'SaveStatsSite_site-0123456789abcdef_0' => 'Site'];
    $marked = [];
    foreach ($world->Exports as $e) {
        if (!str_starts_with($e->ObjectName, 'SaveStatsMarker_') && !str_starts_with($e->ObjectName, 'SaveStatsSite_')) { continue; }
        if (str_starts_with($e->ObjectName, 'SaveStatsSite_')) { $names[$e->ObjectName] = 'Site'; }
        foreach ($e->Data as $property) {
            if ($property->Name !== 'Tags') { continue; }
            $pidTags = []; $other = [];
            foreach ($property->Value as $tag) { if (str_starts_with($tag->Value, 'SaveStats.Puzzle.')) { $pidTags[] = $tag; } else { $other[] = $tag; } }
            $property->Value = [OverlayProperty('Name', '0', 'Test.Unrelated.First'), ...$other, ...$pidTags];
            if (isset($names[$e->ObjectName])) { $property->Value[] = OverlayProperty('Name', '0', 'Test.Live.' . $names[$e->ObjectName]); $marked[] = $names[$e->ObjectName]; }
        }
    }
    if (count($marked) !== 5) { throw new RuntimeException('Expected paired, control, high-ID and site marker actors; got ' . implode(',', $marked)); }
    $world->NameMap = array_values(array_unique(array_merge($world->NameMap, array_column($world->Exports, 'ObjectName'),
        ['Test.Unrelated.First', 'Test.Live.PairA', 'Test.Live.PairB', 'Test.Live.Control', 'Test.Live.High', 'Test.Live.Site'])));
    FixtureSaveAsset($output . '/Login.json', $world);
    $render = array_map(static fn(object $p): array => (array)$p, ExpandOverlayMarkers($document));
    $catalog = array_intersect_key(GetOverlayTypes(), ['matchbox' => true]);
    $index = BuildRadarIndex($render, $catalog);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    [$radar, $radarManifest] = BuildRadarWidget($base, $index);
    file_put_contents($output . '/WBP_SSOverlayRadar.json', json_encode($radar, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-manifest.json', json_encode($radarManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    copy($mapPath, $output . '/WBP_LargeMap.json'); copy($hudPath, $output . '/WBP_GameHUD.json');
    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base); $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($b->class->FuncMap as [$name, $id]) { $b->functions[$name] = $id; }
    $b->native['GISProgression'] = OverlayImport($b->asset, 'GISProgression', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    foreach (['PlayerProgressionData', 'PuzzleSolveData', 'LocationMarkerData'] as $name) { $b->native[$name] = OverlayImport($b->asset, $name, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct'); }
    $b->native['MarkerEmitter'] = OverlayImport($b->asset, 'MarkerEmitter', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    $b->native['BlueprintMapLibrary'] = OverlayImport($b->asset, 'BlueprintMapLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->native['CheckBox'] = OverlayImport($b->asset, 'CheckBox', $b->native['UMG'], '/Script/CoreUObject', 'Class');
    $checkSignature = OverlayImport($b->asset, 'OnCheckBoxComponentStateChanged__DelegateSignature', $b->native['UMG'], '/Script/CoreUObject', 'Function');
    $radarPackage = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $radarPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $mapPackage = OverlayImport($b->asset, '/Game/ASophia/UI/HUD/Map/WBP_LargeMap', 0, '/Script/CoreUObject', 'Package');
    $mapClass = OverlayImport($b->asset, 'WBP_LargeMap_C', $mapPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $hudPackage = OverlayImport($b->asset, '/Game/ASophia/UI/HUD/WBP_GameHUD', 0, '/Script/CoreUObject', 'Package');
    $hudClass = OverlayImport($b->asset, 'WBP_GameHUD_C', $hudPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    foreach (['Hud' => $hudClass, 'Map' => $mapClass, 'Radar' => $radarClass, 'PairA' => $b->native['Actor'], 'PairB' => $b->native['Actor'], 'Control' => $b->native['Actor'], 'High' => $b->native['Actor'], 'Site' => $b->native['Actor'], 'TailActor' => $b->native['Actor']] as $name => $type) { $b->objectField($name, $type); }
    $b->arrayField('Object', 'Candidates', []);
    foreach ($b->class->LoadedProperties as $field) { if ($field->Name === 'Candidates') { $field->Inner->PropertyClass = $b->native['Object']; } }
    $b->scalar('Int', 'TailPuzzleId', 0);
    $b->scalar('Int', 'LastCheck', 0);
    foreach (['ProbeCheck', 'CheckInitial', 'CheckSolved', 'CheckRadarDisabled', 'CheckRestored', 'CheckFinal'] as $name) { $b->reserve($name); }
    $s = static fn(string $name): object => FilterVariable(1, $name, true);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer(1, $name), 'Variable' => $s($name), 'Expression' => $value]);
    $int = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $float = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $name, object ...$args): object => $b->call('KismetMathLibrary', $name, $args);
    $array = static fn(string $name, object ...$args): object => $b->call('KismetArrayLibrary', $name, $args);
    $valid = static fn(object $value): object => $b->call('KismetSystemLibrary', 'IsValid', [$value]);
    $get = static fn(string $field): object => RadarContext($s('Radar'), FilterVariable($radarClass, $field, true), FilterPointer($radarClass, $field));
    $mapGet = static fn(string $field): object => RadarContext($s('Map'), FilterVariable($mapClass, $field, true), FilterPointer($mapClass, $field));
    $radarCall = static fn(string $name, array $args = [], ?object $pointer = null): object => RadarContext($s('Radar'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $name, 'Parameters' => $args]), $pointer);
    $mapCall = static fn(string $name, array $args = [], ?object $pointer = null): object => RadarContext($s('Map'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $name, 'Parameters' => $args]), $pointer);
    $data = RadarContext($s('Progression'), FilterVariable($b->native['GISProgression'], 'PlayerProgression', true), FilterPointer($b->native['GISProgression'], 'PlayerProgression'));
    $statusMap = static fn(): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'), 'StructExpression' => unserialize(serialize($data))]);
    $mutate = static fn(object $pid, string $seed): object => $b->call('BlueprintMapLibrary', 'Map_Add', [$statusMap(), $pid, $s($seed)]);
    $timer = static function (string $owner, string $callback, float $delay) use ($b, $float, $bool): object { $fn = $b->functions[$owner]; return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $float($delay), $bool(false), $float(0), $float(0)])]); };
    $end = static fn(string $owner): array => [FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer($owner, 'Finish', 0.2)];
    $checks = [];
    $probeFn = $b->functions['ProbeCheck'];
    $b->define('ProbeCheck', ['Condition' => 'Bool', 'Index' => 'Int'], [], [$set('LastCheck', FilterVariable($probeFn, 'Index')),
        FilterExpr('Return', ['ReturnExpression' => FilterVariable($probeFn, 'Condition')])], null, 'Bool');
    $assert = static function (object $condition, string $name) use (&$checks, $b, $int): object {
        $checks[] = $name;
        return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => FilterCall($b->functions['ProbeCheck'], [$condition, $int(count($checks))], 'LocalFinalFunction')]);
    };
    $checkbox = static function (string $field, bool $checked) use ($mapGet, $b, $checkSignature, $bool): array { $widget = $mapGet($field); return [$b->method($widget, 'CheckBox', 'SetIsChecked', [$bool($checked)]), FilterExpr('CallMulticastDelegate', ['StackNode' => $checkSignature, 'Delegate' => RadarContext($widget, FilterVariable($b->native['CheckBox'], 'OnCheckStateChanged', true), FilterPointer($b->native['CheckBox'], 'OnCheckStateChanged')), 'Parameters' => [$bool($checked)]])]; };
    $create = static fn(int $class): object => FilterExpr('DynamicCast', ['ClassPtr' => $class, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $class]), $s('Controller')])]);
    $fn = $b->functions['VerifyLive'];
    $setup = [$assert($valid($s('Progression')), 'native progression authority exists'),
        $set('Hud', $create($hudClass)), $b->method($s('Hud'), 'UserWidget', 'AddToViewport', [$int(0)]),
        $b->call('WidgetBlueprintLibrary', 'GetAllWidgetsOfClass', [FilterExpr('Self'), $s('Candidates'), FilterExpr('ObjectConst', ['Value' => $radarClass]), $bool(false)]),
        $array('Array_Get', $s('Candidates'), $int(0), $s('Radar')), $assert($valid($s('Radar')), 'actual embedded radar exists'),
        $set('Map', $create($mapClass)), $b->method($s('Map'), 'UserWidget', 'AddToViewport', [$int(10)]),
        $assert($math('EqualEqual_ObjectObject', $mapGet('SSOverlayRadarState'), $s('Radar')), 'actual map binds actual gameplay radar'),
        ...$checkbox('SSOverlayCheck_type_matchbox', true), ...$checkbox('SSOverlayCheck_source_spawn', true), ...$checkbox('SSOverlayCheck_radar_enabled', true)];
    foreach (['PairA', 'PairB', 'Control', 'High', 'Site'] as $field) {
        $setup[] = $b->call('GameplayStatics', 'GetAllActorsWithTag', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => 'Test.Live.' . $field]), $s('Candidates')]);
        $setup[] = $array('Array_Get', $s('Candidates'), $int(0), $s($field));
        $setup[] = $assert($valid($s($field)), $field . ' marker actor exists');
    }
    $setup[] = $timer('VerifyLive', 'CheckInitial', 0.8);
    $b->define('VerifyLive', [], ['Timer' => 'Struct'], [...$setup, ...$end('VerifyLive')]);
    $visible = static function (string $actor, int $owner, bool $expected) use ($b, $s, $bool, $math, $assert): array {
        $local = FilterVariable($owner, 'Emitter');
        $read = FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['LocationMarkerData'], 'VisibleOnMap'),
            'StructExpression' => RadarContext($local, FilterVariable($b->native['MarkerEmitter'], 'Marker', true), FilterPointer($b->native['MarkerEmitter'], 'Marker'))]);
        return [FilterExpr('Let', ['Value' => FilterPointer($owner, 'Emitter'), 'Variable' => $local, 'Expression' => $b->method($s($actor), 'Actor', 'GetComponentByClass', [FilterExpr('ObjectConst', ['Value' => $b->native['MarkerEmitter']])])]),
            $assert($b->call('KismetSystemLibrary', 'IsValid', [$local]), $actor . ' native emitter exists'), $assert($math('EqualEqual_BoolBool', $read, $bool($expected)), $actor . ' visibility=' . ($expected ? 'true' : 'false'))];
    };
    $count = static fn(): object => $array('Array_Length', $get('SSRadarDisplayIndices'));
    $fn = $b->functions['CheckInitial'];
    $pid = FilterVariable($fn, 'Pid'); $offset = FilterVariable($fn, 'Index');
    $initial = [$assert($math('EqualEqual_IntInt', $count(), $int(5)), 'initial radar has paired two points, control, high ID and site'),
        $assert($math('Greater_IntInt', $array('Array_Length', $mapGet('SSOverlayPreviousActors')), $int(128)), 'selected map actors exceed two64-marker batches'),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'Pid'), 'Variable' => $pid, 'Expression' => $mapCall('SSOverlayPuzzleId', [$s('High')], FilterPointer($fn, 'Pid'))]),
        $assert($math('EqualEqual_IntInt', $pid, $int(1000000001)), 'native PID parser finds high positive ID after unrelated tags')];
    foreach (['PairA', 'PairB', 'Control', 'High', 'Site'] as $name) { array_push($initial, ...$visible($name, $fn, true)); }
    array_push($initial, FilterExpr('Let', ['Value' => FilterPointer($fn, 'Index'), 'Variable' => $offset, 'Expression' => $math('Subtract_IntInt', $array('Array_Length', $mapGet('SSOverlayPreviousActors')), $int(1))]), ['label' => 'FindTail'],
        $array('Array_Get', $mapGet('SSOverlayPreviousActors'), $offset, $s('TailActor')),
        $set('TailPuzzleId', $mapCall('SSOverlayPuzzleId', [$s('TailActor')], FilterPointer(1, 'TailPuzzleId'))),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Earlier', 'BooleanExpression' => $math('BooleanAND',
            $math('GreaterEqual_IntInt', $s('TailPuzzleId'), $int(7000)), $math('Less_IntInt', $s('TailPuzzleId'), $int(7130)))]), FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Mutate']),
        ['label' => 'Earlier'], FilterExpr('Let', ['Value' => FilterPointer($fn, 'Index'), 'Variable' => $offset, 'Expression' => $math('Subtract_IntInt', $offset, $int(1))]),
        $assert($math('Greater_IntInt', $offset, $int(127)), 'positive tail marker remains beyond first128'), FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'FindTail']), ['label' => 'Mutate'],
        $mutate($int(5594), 'SolvedSeed'), $mutate($int(1000000001), 'SolvedSeed'), $mutate($s('TailPuzzleId'), 'SolvedSeed'), $timer('CheckInitial', 'CheckSolved', 1.25));
    $b->define('CheckInitial', [], ['Timer' => 'Struct', 'Emitter' => 'Object', 'Pid' => 'Int', 'Index' => 'Int'], [...$initial, ...$end('CheckInitial')]);
    $fn = $b->functions['CheckSolved']; $solved = [];
    foreach (['PairA', 'PairB', 'High', 'TailActor'] as $name) { array_push($solved, ...$visible($name, $fn, false)); }
    foreach (['Control', 'Site'] as $name) { array_push($solved, ...$visible($name, $fn, true)); }
    array_push($solved, $assert($math('EqualEqual_IntInt', $count(), $int(2)), 'actual timer removes all solved pair/high radar points without rebuild'),
        $assert($math('LessEqual_IntInt', $mapGet('SSOverlayProgressVisited'), $int(64)), 'map periodic work is bounded64 per callback'),
        $radarCall('SSRadarToggleEnabled'),
        // Attaching a map does not set the native character menu flag in this
        // bare test world. Pause only the radar timer and acknowledge dirty so
        // periodic map batching cannot be masked by per-frame reopen refreshes.
        $b->call('KismetSystemLibrary', 'K2_ClearTimer', [$s('Radar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]),
        $mapCall('SSOverlayRefresh'), $radarCall('SSRadarAcknowledgeMapProgress'),
        $assert($math('EqualEqual_IntInt', $mapGet('SSOverlayProgressCursor'), $int(0)), 'bounded-only phase starts at map cursor zero'),
        $mutate($int(5594), 'UnsolvedSeed'), $mutate($s('TailPuzzleId'), 'UnsolvedSeed'), $timer('CheckSolved', 'CheckRadarDisabled', 1.25));
    $b->define('CheckSolved', [], ['Timer' => 'Struct', 'Emitter' => 'Object'], [...$solved, ...$end('CheckSolved')]);
    $fn = $b->functions['CheckRadarDisabled']; $disabled = [$assert($math('Not_PreBool', $get('SSRadarEnabled')), 'radar disabled for map-only update check')];
    foreach (['PairA', 'PairB', 'Control', 'Site'] as $name) { array_push($disabled, ...$visible($name, $fn, true)); }
    array_push($disabled, ...$visible('High', $fn, false), ...$visible('TailActor', $fn, true));
    array_push($disabled, $assert($radarCall('SSRadarIsSolved', [$int(1000000001)], FilterPointer($fn, 'Boolean')), 'native progress getter remains authoritative while radar disabled'),
        $assert($math('Not_PreBool', $get('SSRadarMapProgressDirty')), 'radar timer paused so no full-refresh dirty path masks bounded map loop'),
        $assert($math('LessEqual_IntInt', $mapGet('SSOverlayProgressVisited'), $int(64)), 'tail actor beyond128 restores through bounded native map Tick'),
        $mutate($int(5595), 'SolvedSeed'), $timer('CheckRadarDisabled', 'CheckRestored', 1.25));
    $b->define('CheckRadarDisabled', [], ['Timer' => 'Struct', 'Emitter' => 'Object', 'Boolean' => 'Bool'], [...$disabled, ...$end('CheckRadarDisabled')]);
    $fn = $b->functions['CheckRestored']; $restored = [...$visible('Control', $fn, false), ...$visible('Site', $fn, true),
        $assert($math('LessEqual_IntInt', $mapGet('SSOverlayProgressVisited'), $int(64)), 'later map cursor rollover stays bounded'),
        $mutate($int(5595), 'UnsolvedSeed'), $mutate($int(1000000001), 'UnsolvedSeed'),
        $radarCall('SSRadarToggleEnabled'),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [$s('Radar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick']), $float(0.1), $bool(true), $float(0), $float(0)])]),
        $timer('CheckRestored', 'CheckFinal', 0.8)];
    $b->define('CheckRestored', [], ['Timer' => 'Struct', 'Emitter' => 'Object'], [...$restored, ...$end('CheckRestored')]);
    $fn = $b->functions['CheckFinal']; $final = [$assert($math('EqualEqual_IntInt', $count(), $int(5)), 're-enabled actual radar timer restores unsolved pair/control/high/site points')];
    foreach (['PairA', 'PairB', 'Control', 'High', 'Site'] as $name) { array_push($final, ...$visible($name, $fn, true)); }
    array_push($final,
        FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $bool(true)]),
        $b->method($s('Map'), 'Widget', 'RemoveFromParent'), $b->method($s('Hud'), 'Widget', 'RemoveFromParent'), $timer('CheckFinal', 'Finish', 0.2));
    $b->define('CheckFinal', [], ['Timer' => 'Struct', 'Emitter' => 'Object'], [...$final, ...$end('CheckFinal')]);
    $b->native['KismetStringLibrary'] = OverlayImport($b->asset, 'KismetStringLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->define('Finish', [], [], [FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $s('Passed')]),
        $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/SSRadarPass']), $bool(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'],
        $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/SSRadarFail']), $bool(true),
            $b->call('KismetStringLibrary', 'Concat_StrStr', [FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase?Check=']), $b->call('KismetStringLibrary', 'Conv_IntToString', [$s('LastCheck')])])])]);
    foreach ($b->asset->Exports as $export) { foreach ($export->LoadedProperties ?? [] as $field) { if ($field->Name === 'Emitter') { $field->PropertyClass = $b->native['MarkerEmitter']; } } }
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $e) { if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$e->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; } }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $manifest = ['assertions' => count($checks), 'checks' => $checks, 'actualNativeAuthority' => true, 'manualRadarTickCalls' => 0, 'manualMapProgressCalls' => 0, 'periodicMapBudget' => 64, 'boundedMapPhaseRadarTimerPaused' => true, 'nativeCharacterMapFlagSimulated' => false, 'mapActorCount' => count($render), 'radarInitialCount' => 5, 'sourceMap' => $mapPath, 'sourceHud' => $hudPath];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $manifest;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['output-dir:', 'map-widget:', 'hud-widget:']);
        foreach (['output-dir', 'map-widget', 'hud-widget'] as $required) { if (!isset($options[$required])) { throw new InvalidArgumentException('Provide --output-dir --map-widget --hud-widget'); } }
        echo json_encode(BuildLiveCompletionFixture(realpath($options['output-dir']), realpath($options['map-widget']), realpath($options['hud-widget'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
