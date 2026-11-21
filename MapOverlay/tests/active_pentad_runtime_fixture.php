<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/radar_live_fixture.php';

/** Test geometry intentionally mixes two complete Pentads with incomplete data. */
function ActivePentadFixturePoints(): array
{
    $points = [];
    foreach ([41001 => 'A', 41002 => 'B'] as $pid => $prefix) {
        foreach (range(0, 5) as $part) {
            $points[] = ['actorName' => $prefix . $part, 'ptype' => 'seek5', 'group' => 'static', 'pid' => $pid,
                'pointIndex' => $part, 'role' => $part === 0 ? 'pillar' : 'object',
                'x' => ($prefix === 'A' ? 1000 : 2000) + $part * 100, 'y' => 0, 'z' => 0, 'tags' => ['SaveStats.Type.seek5'],
                ...($part === 0 ? [] : ['pentadLocalPosition' => ['x' => ($prefix === 'A' ? 1000 : 2000) + $part * 100, 'y' => 0, 'z' => 0]])];
        }
    }
    foreach ([1, 2, 3, 4, 4] as $n => $part) {
        $points[] = ['actorName' => 'Incomplete' . $n, 'ptype' => 'seek5', 'group' => 'static', 'pid' => 41003,
            'pointIndex' => $part, 'role' => 'object', 'x' => 3000 + $n * 100, 'y' => 0, 'z' => 0, 'tags' => ['SaveStats.Type.seek5']];
    }
    $points[] = ['actorName' => 'Site', 'ptype' => 'spawnSite', 'group' => 'spawn',
        'x' => 10, 'y' => 0, 'z' => 0, 'tags' => ['SaveStats.Type.seek5']];
    $points[] = ['actorName' => 'Cube', 'ptype' => 'hiddenCube', 'group' => 'static', 'pid' => 42001,
        'x' => 100, 'y' => 0, 'z' => 0, 'tags' => ['SaveStats.Type.hiddenCube']];
    foreach (range(0, 299) as $n) {
        $points[] = ['actorName' => sprintf('Dense%03d', $n), 'ptype' => 'seek5', 'group' => 'static', 'pid' => 43000 + $n,
            'pointIndex' => 0, 'role' => 'pillar',
            'x' => 7000 + $n, 'y' => 0, 'z' => 0, 'tags' => ['SaveStats.Type.seek5']];
    }
    return $points;
}

function BuildActivePentadRuntimeFixture(string $output, ?int $probeChecks = null): array
{
    BuildRadarLiveFixture($output);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $index = BuildRadarIndex(ActivePentadFixturePoints(), array_intersect_key(GetOverlayTypes(), array_flip(['seek5', 'hiddenCube'])));
    [$radar, $manifest] = BuildRadarWidget($base, $index);
    file_put_contents($output . '/WBP_SSOverlayRadar.json', json_encode($radar, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($b->class->FuncMap as [$name, $fn]) { $b->functions[$name] = $fn; }
    $pkg = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $pkg, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    foreach (['CharPuzzleComponent', 'Seek5', 'PuzzleBase', 'ClickableBase'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    $b->native['BlueprintMapLibrary'] = OverlayImport($b->asset, 'BlueprintMapLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->native['PlayerProgressionData'] = OverlayImport($b->asset, 'PlayerProgressionData', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    $b->native['Seek5HiddenObject'] = OverlayImport($b->asset, 'Seek5HiddenObject', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    $b->native['SceneComponent'] = OverlayImport($b->asset, 'SceneComponent', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $pentadPackage = OverlayImport($b->asset, '/Game/ASophia/Puzzle/Seek5/BP_Seek5', 0, '/Script/CoreUObject', 'Package');
    $pentadBlueprint = OverlayImport($b->asset, 'BP_Seek5_C', $pentadPackage, '/Script/Engine', 'BlueprintGeneratedClass');
    foreach (['PentadA' => 'Seek5', 'PentadB' => 'Seek5', 'PuzzleComponent' => 'CharPuzzleComponent'] as $field => $type) { $b->objectField($field, $b->native[$type]); }
    $fn = $b->functions['VerifyLive'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $name = static fn(string $v): object => FilterExpr('NameConst', ['Value' => $v]);
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $get = static fn(string $n): object => RadarContext($s('Radar'), FilterVariable($radarClass, $n, true), FilterPointer($radarClass, $n));
    $put = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($radarClass, $n), 'Variable' => $get($n), 'Expression' => $v]);
    $putBool = static fn(string $n, bool $v): object => FilterExpr('LetBool', ['VariableExpression' => $get($n), 'AssignmentExpression' => $bool($v)]);
    $invoke = static fn(string $fn, array $args = [], ?object $pointer = null): object => RadarContext($s('Radar'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $fn, 'Parameters' => $args]), $pointer);
    $step = static fn(bool $hidden = false): object => $invoke('SSRadarUpdate', [$f(0), $f(0), $f(0), $f(0), $bool($hidden), $f(0.1)]);
    $select = static fn(string $type, bool $sites = false, bool $enabled = true): object => $invoke('SSRadarSetSelection', [$name('SaveStats.Type.' . $type), $bool($sites), $bool($enabled)]);
    $count = static fn(): object => $array('Array_Length', $get('SSRadarDisplayIndices'));
    $eq = static fn(object $v, int $n): object => $math('EqualEqual_IntInt', $v, $i($n));
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $visible = static fn(int $pin, int $visibility): object => $math('EqualEqual_ByteByte', $b->method($get('SSRadarPin' . $pin), 'Widget', 'GetVisibility'), FilterExpr('ByteConst', ['Value' => $visibility]));
    $checks = []; $positions = []; $code = [];
    $assert = static function (object $value, string $description) use (&$checks, &$positions, &$code): void {
        $checks[] = $description;
        $code[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $value]);
        $positions[] = count($code);
    };
    $scan = static function (int $steps = 3) use (&$code, $invoke): void {
        $code[] = $invoke('SSRadarStartScan');
        foreach (range(1, $steps) as $_) { $code[] = $invoke('SSRadarScan'); }
        $code[] = $invoke('SSRadarRender');
    };
    $expectPentad = static function (string $prefix) use (&$code, $array, $get, $i, $l, $assert, $eq, $index): void {
        foreach (range(1, 5) as $part) {
            $code[] = $array('Array_Get', $get('SSRadarDisplayIndices'), $i($part - 1), $l('Point'));
            $assert($eq($l('Point'), (int)array_search($prefix . $part, $index['pointIds'], true)), $prefix . ' relic ' . $part . ' is the expected exact point');
        }
    };
    $expectOverview = static function (array $names, string $context) use (&$code, $array, $get, $i, $l, $assert, $eq, $index): void {
        foreach ($names as $pin => $name) {
            $code[] = $array('Array_Get', $get('SSRadarDisplayIndices'), $i($pin), $l('Point'));
            $assert($eq($l('Point'), (int)array_search($name, $index['pointIds'], true)), $context . ' pin ' . $pin . ' is ' . $name);
        }
    };
    $overviewNames = ['Site', 'A0', 'B0', ...array_map(static fn(int $n): string => sprintf('Dense%03d', $n), range(0, 20))];
    $code[] = $b->call('KismetSystemLibrary', 'K2_ClearTimer', [$s('Radar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]);
    $assert($eq($array('Array_Length', $get('SSRadarPentadIds')), 2), 'only two complete Pentads qualify for focus');
    $assert($array('Array_Contains', $get('SSRadarPentadIds'), $i(41001)), 'first complete Pentad qualifies');
    $assert($array('Array_Contains', $get('SSRadarPentadIds'), $i(41002)), 'second complete Pentad qualifies');
    $assert($math('Not_PreBool', $array('Array_Contains', $get('SSRadarPentadIds'), $i(41003))), 'five entries with a repeated relic and missing fifth do not qualify');
    $code[] = $select('seek5', true); $code[] = $step(); $code[] = $step(); $code[] = $step();
    $assert($eq($count(), 24), 'inactive overview retains nearest24 pillars and sites');
    $expectOverview($overviewNames, 'inactive pillar overview');
    // Direct state writes below isolate the scanner/render gate from native
    // lifecycle discovery; no production setter is added for these checks.
    $code[] = $put('SSRadarFocusPuzzleId', $i(41001));
    $scan();
    $assert($eq($count(), 5), 'focused scanner retains exactly five relics');
    $expectPentad('A');
    $assert($visible(5, 1), 'pillar and other Pentad pins are absent from focused display');
    $code[] = $putBool('SSRadarPentadFound1', true); $code[] = $invoke('SSRadarRender');
    $assert($visible(0, 1), 'found cache hides collected committed relic before replacement scan');
    $assert($visible(1, 3), 'found cache preserves uncollected committed relic');
    $assert($math('Not_PreBool', $invoke('SSRadarIsPentadRelicVisible', [$i(41001), $i(1)])), 'shared cache lookup hides collected matching relic');
    $assert($invoke('SSRadarIsPentadRelicVisible', [$i(41001), $i(2)]), 'shared cache lookup preserves uncollected matching relic');
    $assert($math('Not_PreBool', $invoke('SSRadarIsPentadRelicVisible', [$i(41002), $i(2)])), 'shared cache lookup rejects another Pentad');
    $assert($math('Not_PreBool', $invoke('SSRadarIsPentadRelicVisible', [$i(41001), $i(0)])), 'shared cache lookup rejects pillar index');
    $assert($math('Not_PreBool', $invoke('SSRadarIsPentadRelicVisible', [$i(41001), $i(6)])), 'shared cache lookup rejects invalid relic index');
    $scan(); $assert($eq($count(), 4), 'scanner removes exactly collected active relic');
    foreach (range(2, 5) as $part) {
        $code[] = $array('Array_Get', $get('SSRadarDisplayIndices'), $i($part - 2), $l('Point'));
        $assert($eq($l('Point'), (int)array_search('A' . $part, $index['pointIds'], true)), 'uncollected A relic ' . $part . ' retains correct identity');
    }
    $code[] = $putBool('SSRadarPentadFound1', false); $scan();
    $assert($eq($count(), 5), 'cleared found proof restores active relic');
    $code[] = $put('SSRadarFocusPuzzleId', $i(41002)); $code[] = $invoke('SSRadarRender');
    foreach (range(0, 4) as $pin) { $assert($visible($pin, 1), 'stale first-Pentad committed pin ' . $pin . ' cannot paint after switch'); }
    $scan(); $assert($eq($count(), 5), 'switched scanner retains exactly five second-Pentad relics'); $expectPentad('B');
    $code[] = $put('SSRadarFocusPuzzleId', $i(0)); $code[] = $invoke('SSRadarRender');
    foreach (range(0, 4) as $pin) { $assert($visible($pin, 1), 'stale active relic ' . $pin . ' cannot paint after focus clears'); }
    $scan();
    $assert($eq($count(), 24), 'cleared focus restores pillar overview');
    $expectOverview(array_slice($overviewNames, 0, 3), 'cleared pillar overview');
    $code[] = $select('hiddenCube'); $code[] = $put('SSRadarFocusPuzzleId', $i(41001)); $scan();
    $assert($eq($count(), 1), 'other puzzle filter ignores stale Pentad focus');
    $code[] = $put('SSRadarFocusPuzzleId', $i(0));
    $property = static fn(object $obj, string $owner, string $field): object => RadarContext($obj, FilterVariable($b->native[$owner], $field, true), FilterPointer($b->native[$owner], $field));
    $assign = static fn(object $obj, string $owner, string $field, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($b->native[$owner], $field), 'Variable' => $property($obj, $owner, $field), 'Expression' => $v]);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $s($n), 'Expression' => $v]);
    $valid = static fn(object $obj): object => $b->call('KismetSystemLibrary', 'IsValid', [$obj]);
    $vector = static fn(float $x, float $y, float $z): object => $math('MakeVector', $f($x), $f($y), $f($z));
    $transform = static fn(): object => $math('MakeTransform', $vector(0, 0, 0), $math('MakeRotator', $f(0), $f(0), $f(0)), $vector(1, 1, 1));
    $reference = json_decode(file_get_contents(OverlayTestDataPath('ModMaker/media/data/Puzzles.json')), true, 512, JSON_THROW_ON_ERROR);
    $template = null;
    foreach ($reference['puzzles'] as $puzzle) { if (($puzzle['puzzleType'] ?? '') === 'seek5') { $template = json_decode($puzzle['serialized'], true, 512, JSON_THROW_ON_ERROR); break; } }
    if ($template === null) { throw new RuntimeException('Missing native seek5 serialization reference.'); }
    foreach (['PentadA' => 41001, 'PentadB' => 41002] as $field => $pid) {
        $code[] = $set($field, FilterExpr('DynamicCast', ['ClassPtr' => $b->native['Seek5'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $pentadBlueprint]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])]));
        $code[] = $b->method($s($field), 'Actor', 'SetActorTickEnabled', [$bool(false)]);
        $code[] = $set($field, $b->call('GameplayStatics', 'FinishSpawningActor', [$s($field), $transform()]));
        $assert($valid($s($field)), 'real native ' . $field . ' exists');
        $transforms = [];
        foreach (range(1, 5) as $part) { $transforms[$part === 1 ? 'OriginalTransform' : 'DuplicateTransform-' . ($part - 2)] = (($pid === 41001 ? 1000 : 2000) + $part * 100) . '.000000,0.000000,0.000000|0.000000,0.000000,0.000000|1.000000,1.000000,1.000000'; }
        $serialized = array_replace($template, ['PuzzleClass' => 'BP_Seek5_C', 'PuzzleType' => 'seek5', 'SpawnBehaviour' => 0, 'KID' => $pid,
            'ActorTransform' => '0.000000,0.000000,0.000000|0.000000,0.000000,0.000000|1.000000,1.000000,1.000000',
            'BoundaryTransform' => '0.000000,0.000000,0.000000|0.000000,0.000000,0.000000|1.000000,1.000000,1.000000',
            'CentralPillarTransform' => '0.000000,0.000000,50.000000|0.000000,0.000000,0.000000|1.000000,1.000000,1.000000',
            'DuplicatedObjectOfType-Seek5HiddenObject' => (object)$transforms]);
        $code[] = $assign($s($field), 'PuzzleBase', 'puzzleData', FilterExpr('StringConst', ['Value' => json_encode($serialized, JSON_THROW_ON_ERROR)]));
        $code[] = $b->method($s($field), 'PuzzleBase', 'OnRep_PuzzleData');
        $assert($eq($array('Array_Length', $property($s($field), 'Seek5', 'HiddenObjects')), 5), $field . ' deserialized actual five hidden components');
        $code[] = $array('Array_Swap', $property($s($field), 'Seek5', 'HiddenObjects'), $i(0), $i(4));
        $code[] = $assign($s($field), 'PuzzleBase', 'KrakenId', $i($pid));
    }
    $code[] = $set('PuzzleComponent', $property($s('Player'), 'SophiaCharacter', 'CharPuzzleComponent'));
    $assert($valid($s('PuzzleComponent')), 'real local character puzzle component exists');
    $workers = static fn(): object => $property($s('PuzzleComponent'), 'CharPuzzleComponent', 'workedOnPuzzles');
    $activate = static function (?string $field) use (&$code, $array, $workers, $assign, $s, $i): void {
        $code[] = $array('Array_Clear', $workers());
        foreach (['PentadA', 'PentadB'] as $name) {
            $code[] = $assign($s($name), 'ClickableBase', 'interactingPlayer', $name === $field ? $s('Player') : FilterExpr('ObjectConst', ['Value' => 0]));
        }
        if ($field !== null) { $code[] = $array('Array_AddUnique', $workers(), $s($field)); }
    };
    $data = static fn(): object => $property($s('Progression'), 'GISProgression', 'PlayerProgression');
    $progress = static fn(): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'), 'StructExpression' => $data()]);
    $seed = static fn(int $pid, string $status): object => $b->call('BlueprintMapLibrary', 'Map_Add', [$progress(), $i($pid), $s($status)]);
    $settle = static function (int $steps = 3) use (&$code, $step): void { foreach (range(1, $steps) as $_) { $code[] = $step(); } };
    $code[] = $seed(41001, 'UnsolvedSeed'); $code[] = $seed(41002, 'UnsolvedSeed');
    $activate(null); $code[] = $select('seek5', true); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'native no-active state selects overview');
    $assert($eq($count(), 24), 'native no-active state retains pillar overview24');
    $code[] = $invoke('SSRadarAcknowledgeMapProgress');
    $assert($math('Not_PreBool', $get('SSRadarMapProgressDirty')), 'map acknowledges current inactive overview');
    $activate('PentadA'); $code[] = $step();
    $assert($eq($get('SSRadarFocusPuzzleId'), 41001), 'native current Seek5 ID activates focus A');
    $assert($get('SSRadarMapProgressDirty'), 'focus change invalidates map progress visibility');
    $assert($eq($get('SSRadarVisitedLastUpdate'), 128), 'native focus refill obeys128 candidate limit');
    $assert($eq($count(), 0), 'activation discards old committed overview before partial scan');
    $activate('PentadB'); $code[] = $step();
    $assert($eq($get('SSRadarFocusPuzzleId'), 41002), 'switch during partial scan immediately selects focus B');
    $assert($eq($count(), 0), 'switch discards unfinished A scan and old display');
    $settle(2); $assert($eq($count(), 5), 'switched native focus settles exactly five relics'); $expectPentad('B');
    $activate('PentadA'); $settle(); $assert($eq($count(), 5), 'switch with committed pins settles focus A'); $expectPentad('A');
    $objects = static fn(): object => $property($s('PentadA'), 'Seek5', 'HiddenObjects');
    $collect = static fn(int $part, bool $found): object => FilterExpr('LetBool', ['VariableExpression' => $property($get('SSRadarPentadObject' . $part), 'Seek5HiddenObject', 'isFound'), 'AssignmentExpression' => $bool($found)]);
    $expectParts = static function (array $parts, string $context) use (&$code, $array, $get, $i, $l, $assert, $eq, $index): void {
        foreach (array_values($parts) as $pin => $part) {
            $code[] = $array('Array_Get', $get('SSRadarDisplayIndices'), $i($pin), $l('Point'));
            $assert($eq($l('Point'), (int)array_search('A' . $part, $index['pointIds'], true)), $context . ' pin ' . $pin . ' is A' . $part);
        }
    };
    foreach (range(1, 5) as $part) { $assert($valid($get('SSRadarPentadObject' . $part)), 'native unordered component mapped to relic ' . $part); }
    $code[] = $let('SavedRelic', $get('SSRadarPentadObject1')); $code[] = $let('SavedSecond', $get('SSRadarPentadObject2'));
    $code[] = $invoke('SSRadarAcknowledgeMapProgress'); $code[] = $collect(1, true); $code[] = $step();
    $assert($get('SSRadarPentadFound1'), 'actual native isFound updates cached relic1');
    $assert($math('Not_PreBool', $invoke('SSRadarIsPentadRelicVisible', [$i(41001), $i(1)])), 'actual native collected relic disappears from shared map lookup');
    $assert($get('SSRadarMapProgressDirty'), 'actual native collected state marks map visibility dirty');
    $assert($eq($count(), 0), 'collection invalidates old committed display before bounded refill');
    $assert($eq($get('SSRadarVisitedLastUpdate'), 128), 'collected relic refill remains bounded128');
    $settle(2); $assert($eq($count(), 4), 'one native collected relic leaves four points'); $expectParts([2,3,4,5], 'one collected');
    $code[] = $collect(3, true); $settle();
    $assert($eq($b->method($s('PentadA'), 'Seek5', 'GetNumberCompleted'), 2), 'independent native completion count sees two found components');
    $assert($eq($count(), 3), 'two native collected relics leave three points'); $expectParts([2,4,5], 'two collected');
    $code[] = $assign($l('SavedRelic'), 'SceneComponent', 'RelativeLocation', $vector(99000, 88000, 77000)); $code[] = $step();
    $assert($math('EqualEqual_ObjectObject', $get('SSRadarPentadObject1'), $l('SavedRelic')), 'component identity remains cached while collection animation moves its location');
    $assert($get('SSRadarPentadFound1'), 'animated native component retains positive collected proof');
    $code[] = $array('Array_Swap', $objects(), $i(0), $i(4)); $code[] = $step();
    $assert($math('EqualEqual_ObjectObject', $get('SSRadarPentadObject1'), $l('SavedRelic')), 'native component array reorder preserves identity mapping');
    $code[] = $assign($l('SavedRelic'), 'SceneComponent', 'RelativeLocation', $vector(1100, 0, 0));
    $code[] = $collect(1, false); $code[] = $collect(3, false); $settle();
    $assert($eq($count(), 5), 'cleared native collected flags restore five active relics');
    $code[] = $collect(1, true); $settle();
    $code[] = $array('Array_RemoveItem', $objects(), $l('SavedRelic')); $settle();
    $assert($math('Not_PreBool', $valid($get('SSRadarPentadObject1'))), 'incomplete native membership drops old cached mapping');
    $assert($eq($count(), 5), 'missing native mapping preserves all active relics visible');
    $code[] = $array('Array_AddUnique', $objects(), $l('SavedRelic')); $settle();
    $assert($eq($count(), 4), 'restored native membership rebuilds mapping and collected proof');
    $code[] = $assign($l('SavedSecond'), 'SceneComponent', 'RelativeLocation', $vector(1100, 0, 0));
    $code[] = $put('SSRadarPentadActor', FilterExpr('ObjectConst', ['Value' => 0])); $settle();
    $assert($eq($count(), 5), 'ambiguous native local positions preserve every active relic visible');
    $assert($math('Not_PreBool', $get('SSRadarPentadFound1')), 'ambiguous mapping cannot retain stale collected flag');
    $code[] = $assign($l('SavedSecond'), 'SceneComponent', 'RelativeLocation', $vector(1200, 0, 0)); $settle();
    $assert($eq($count(), 4), 'unambiguous native positions restore positive collected proof');
    $code[] = $array('Array_Remove', $get('SSRadarPentadLocalKnown'), $i(0));
    $code[] = $array('Array_Insert', $get('SSRadarPentadLocalKnown'), $i(0), $i(0));
    $code[] = $put('SSRadarPentadActor', FilterExpr('ObjectConst', ['Value' => 0])); $settle();
    $assert($eq($count(), 5), 'missing serialized local metadata preserves all active relics visible');
    $assert($math('Not_PreBool', $get('SSRadarPentadFound1')), 'missing metadata cannot retain positive collected proof');
    $code[] = $array('Array_Remove', $get('SSRadarPentadLocalKnown'), $i(0));
    $code[] = $array('Array_Insert', $get('SSRadarPentadLocalKnown'), $i(1), $i(0)); $settle();
    $assert($eq($count(), 4), 'restored metadata recovers authoritative collected proof');
    $code[] = $assign($s('PentadB'), 'PuzzleBase', 'KrakenId', $i(41001)); $activate('PentadB'); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 41001), 'replacement native actor can retain the same puzzle ID');
    $assert($eq($count(), 5), 'replacement actor with unknown local mapping cannot inherit old found flags');
    $assert($math('Not_PreBool', $valid($get('SSRadarPentadObject1'))), 'replacement actor drops cached component references');
    $code[] = $assign($s('PentadB'), 'PuzzleBase', 'KrakenId', $i(41002)); $activate('PentadA'); $settle();
    $assert($eq($count(), 4), 'returning original actor rebinds original collected proof');
    $code[] = $assign($s('PentadA'), 'PuzzleBase', 'KrakenId', $i(41002)); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 41002), 'same native actor reused with another ID changes focus');
    $assert($eq($count(), 5), 'same actor new ID cannot reuse old component mapping');
    $code[] = $assign($s('PentadA'), 'PuzzleBase', 'KrakenId', $i(41001)); $settle();
    $assert($eq($count(), 4), 'restored ID rebinds correct native component identities');
    $code[] = $collect(1, false); $settle();
    $code[] = $select('seek5', true, false); $code[] = $collect(2, true); $code[] = $invoke('SSRadarTick');
    $assert($get('SSRadarPentadFound2'), 'disabled radar still polls native collected progress for map');
    $assert($eq($get('SSRadarVisitedLastUpdate'), 0), 'disabled collected refresh performs no candidate scan');
    $code[] = $select('seek5', true); $settle(); $assert($eq($count(), 4), 're-enabled radar retains collected cache'); $expectParts([1,3,4,5], 'disabled collection');
    $code[] = $collect(2, false); $settle(); $code[] = $collect(4, true); $code[] = $step(true);
    $assert($get('SSRadarPentadFound4'), 'menu-hidden refresh still polls native collected progress');
    $assert($eq($get('SSRadarVisitedLastUpdate'), 0), 'menu-hidden collected refresh performs no candidate scan');
    $settle(); $assert($eq($count(), 4), 'leaving menu preserves updated collected state'); $expectParts([1,2,3,5], 'menu collection');
    $code[] = $collect(4, false); $settle();
    $code[] = $seed(41001, 'SolvedSeed'); $code[] = $step();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'completion clears focus while current native pointer lingers');
    $assert($eq($count(), 0), 'completion clears committed focus before overview refill');
    $settle(2); $assert($eq($count(), 24), 'completion restores unsolved pillar overview');
    $expectOverview(['Site', 'B0', ...array_map(static fn(int $n): string => sprintf('Dense%03d', $n), range(0, 21))], 'completed pillar overview');
    $code[] = $seed(41001, 'UnsolvedSeed'); $activate('PentadB'); $settle();
    $activate(null); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'native leave signal clears focus');
    $assert($eq($count(), 24), 'native leave restores pillar overview');
    $expectOverview(array_slice($overviewNames, 0, 3), 'native leave pillar overview');
    $activate('PentadA'); $code[] = $assign($s('PentadA'), 'PuzzleBase', 'KrakenId', $i(999999)); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'unknown active puzzle ID leaves overview visible');
    $assert($eq($count(), 24), 'unknown active puzzle does not blank overview');
    $code[] = $assign($s('PentadA'), 'PuzzleBase', 'KrakenId', $i(41003)); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'incomplete active puzzle catalog leaves overview visible');
    $code[] = $assign($s('PentadA'), 'PuzzleBase', 'KrakenId', $i(41001)); $settle();
    $code[] = $assign($s('PentadA'), 'ClickableBase', 'interactingPlayer', FilterExpr('ObjectConst', ['Value' => 0])); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'inactive native state clears focus despite stale worker-list entry');
    $activate('PentadA'); $settle(); $code[] = $select('seek5', true, false); $activate('PentadB'); $code[] = $step();
    $assert($eq($get('SSRadarFocusPuzzleId'), 41002), 'disabled radar still tracks changed native focus');
    $assert($eq($get('SSRadarVisitedLastUpdate'), 0), 'disabled radar performs no candidate scan');
    $code[] = $select('seek5', true); $settle(); $assert($eq($count(), 5), 're-enabled radar resumes current Pentad'); $expectPentad('B');
    $activate('PentadA'); $code[] = $step(true);
    $assert($eq($get('SSRadarFocusPuzzleId'), 41001), 'menu-hidden update still tracks changed native focus');
    $assert($eq($get('SSRadarVisitedLastUpdate'), 0), 'menu-hidden update performs no candidate scan');
    $settle(); $assert($eq($count(), 5), 'leaving menu resumes current Pentad'); $expectPentad('A');
    $code[] = $select('hiddenCube'); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'other filter clears effective focus while Pentad remains active');
    $assert($eq($count(), 1), 'other filter shows its ordinary puzzle while Pentad active');
    $code[] = $select('seek5', true); $settle();
    $assert($eq($get('SSRadarFocusPuzzleId'), 41001), 'returning to Pentad filter reacquires native focus');
    $code[] = $invoke('SSRadarSetView', [$i(0), $i(1)]); $settle();
    $assert($eq($count(), 5), 'focus preserves existing50m zoom and excludes distant distractors');
    $activate('PentadB'); $code[] = $invoke('SSRadarTick');
    $assert($eq($get('SSRadarFocusPuzzleId'), 41002), 'real Tick discovers changed active Pentad through native read path');
    $assert($math('EqualEqual_ByteByte', $b->method($get('SSRadarCanvas'), 'Widget', 'GetVisibility'), FilterExpr('ByteConst', ['Value' => 3])), 'native active Pentad remains visible through all existing menu gates');
    $settle(); $assert($eq($count(), 5), 'real Tick native switch settles current five relics'); $expectPentad('B');
    $code[] = $b->method($s('Player'), 'Actor', 'K2_DestroyActor'); $code[] = $invoke('SSRadarTick');
    $assert($eq($get('SSRadarFocusPuzzleId'), 0), 'lost native player clears stale focus on Tick');
    $code[] = $step(); $code[] = $step(); $code[] = $step();
    $assert($eq($count(), 3), 'lost player authority restores only two pillars and site within50m');
    $expectOverview(array_slice($overviewNames, 0, 3), 'unavailable authority pillar overview');
    if ($probeChecks !== null) { $code = array_slice($code, 0, $positions[$probeChecks - 1]); $checks = array_slice($checks, 0, $probeChecks); }
    $timer = static fn(): object => $let('Timer', $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'Finish']), $f(0.2), $bool(false), $f(0), $f(0)]));
    $code = [...$code, FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $bool(true)]), $timer(),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer()];
    $b->define('VerifyLive', [], ['Point' => 'Int', 'PuzzleId' => 'Int', 'SavedRelic' => 'Object', 'SavedSecond' => 'Object', 'Timer' => 'Struct'], $code);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) { if (in_array($p->Name, ['SavedRelic', 'SavedSecond'], true)) { $p->PropertyClass = $b->native['Seek5HiddenObject']; } }
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $export) {
        if (($export->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$export->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; }
    }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $result = ['assertions' => count($checks), 'checks' => $checks, 'nativeAuthority' => 'CharPuzzleComponent.GetCurrentSeek5 / Seek5.GetState / PuzzleBase.KrakenId / Seek5.HiddenObjects[].isFound',
        'workerListSeededInMemory' => true, 'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'world:', 'probe-checks:']);
        if (!isset($args['output-dir']) || !is_dir($args['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir DIR'); }
        echo json_encode(BuildActivePentadRuntimeFixture(realpath($args['output-dir']), isset($args['probe-checks']) ? (int)$args['probe-checks'] : null), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
