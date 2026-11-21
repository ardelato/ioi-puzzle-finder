<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/radar_live_fixture.php';

/** Native special-query/parser/cache proof; only isolated process memory changes. */
function BuildSpecialCompletionRuntimeFixture(string $output): array
{
    BuildRadarLiveFixture($output);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $points = [];
    foreach ([41000 => 'Missing', 41001 => 'A comma title', 41002 => 'Belonging'] as $pid => $key) {
        $points[] = ['actorName' => 'Lore' . $pid, 'pid' => $pid, 'ptype' => 'loreFragment', 'group' => 'static', 'x' => 1000, 'y' => 0, 'z' => 0,
            'completion' => ['kind' => 'lore', 'key' => $key]];
    }
    foreach ([42200 => 0, 42201 => 1, 42299 => 99] as $pid => $part) {
        $points[] = ['actorName' => 'Fragment' . $pid, 'pid' => $pid, 'ptype' => 'monolithFragment', 'group' => 'static', 'x' => 1000 + $part, 'y' => 0, 'z' => 0,
            'completion' => ['kind' => 'monolith', 'parentPid' => 10770, 'fragmentIndex' => $part]];
    }
    $points[] = ['actorName' => 'Entrance10649', 'pid' => 10649, 'ptype' => 'levelRestrictionVolume', 'group' => 'static', 'x' => 500, 'y' => 0, 'z' => 0];
    $index = BuildRadarIndex($points, array_intersect_key(GetOverlayTypes(), array_flip(['loreFragment', 'monolithFragment', 'levelRestrictionVolume'])));
    [$radar, $radarManifest] = BuildRadarWidget($base, $index);
    file_put_contents($output . '/WBP_SSOverlayRadar.json', json_encode($radar, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-data.json', json_encode($index, JSON_THROW_ON_ERROR));
    file_put_contents($output . '/radar-manifest.json', json_encode($radarManifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $asset = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base); $b->asset = $asset; $b->class = $asset->Exports[0]; $b->default = $asset->Exports[1];
    foreach ($asset->Exports as $n => $e) { if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->functions[$e->ObjectName] = $n + 1; } }
    foreach ($asset->Imports as $n => $import) { if (in_array($import->ClassName, ['Class', 'ScriptStruct'], true)) { $b->native[$import->ObjectName] = -$n - 1; } }
    $b->native['KismetStringLibrary'] = OverlayImport($b->asset, 'KismetStringLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $package = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $package, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    foreach (['ParentValid' => '{"Found":[true,false]}', 'ParentMalformed' => '{"Found":[true,null]}', 'ParentInverse' => '{"Found":[false,true]}'] as $field => $progress) {
        $b->structField($field, 'PuzzleSolveData', 40, [OverlayProperty('Bool', 'bSolved', 0), OverlayProperty('Str', 'serializedProgress', $progress)]);
    }
    $b->scalar('Int', 'FailureCheck', 0);
    foreach (['VerifyMalformed', 'VerifyFinal', 'ProbeCheck'] as $function) { $b->reserve($function); }
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $s($n), 'Expression' => $v]);
    $i = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $f = static fn(float $v): object => FilterExpr('FloatConst', ['Value' => $v]);
    $str = static fn(string $v): object => FilterExpr('StringConst', ['Value' => $v]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $unless = static fn(object $v, string $target): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $target, 'BooleanExpression' => $v]);
    $jump = static fn(string $target): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $target]);
    $invoke = static fn(string $fn, array $args = []): object => RadarContext($s('Radar'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $fn, 'Parameters' => $args]));
    $query = static fn(int $pid): object => $invoke('SSRadarIsSolved', [$i($pid)]);
    $get = static fn(string $field): object => RadarContext($s('Radar'), FilterVariable($radarClass, $field, true), FilterPointer($radarClass, $field));
    $eq = static fn(object $v, int $n): object => $math('EqualEqual_IntInt', $v, $i($n));
    $fn = $b->functions['ProbeCheck']; $p = static fn(string $n): object => FilterVariable($fn, $n);
    $b->define('ProbeCheck', ['Condition' => 'Bool', 'Index' => 'Int'], [], [$unless($p('Condition'), 'Failed'), FilterExpr('Return', ['ReturnExpression' => $bool(true)]),
        ['label' => 'Failed'], $set('FailureCheck', $p('Index'))], null, 'Bool');
    $checks = [];
    $assert = static function (object $condition, string $description) use (&$checks, $b, $i, $unless): object {
        $checks[] = $description; return $unless(FilterCall($b->functions['ProbeCheck'], [$condition, $i(count($checks))], 'LocalFinalFunction'), 'Fail');
    };
    $timer = static function (string $owner, string $callback, float $delay) use ($b, $f, $bool, $str): object {
        $fn = $b->functions[$owner]; return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'),
            'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), $str($callback), $f($delay), $bool(false), $f(0), $f(0)])]);
    };
    $failure = static fn(string $owner): array => [FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer($owner, 'Finish', 0.2)];
    $data = RadarContext($s('Progression'), FilterVariable($b->native['GISProgression'], 'PlayerProgression', true), FilterPointer($b->native['GISProgression'], 'PlayerProgression'));
    $map = static fn(): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PlayerProgressionData'], 'krakenIDToSolvedStatus'), 'StructExpression' => unserialize(serialize($data))]);
    $seed = static fn(int $pid, string $field): object => $b->call('BlueprintMapLibrary', 'Map_Add', [$map(), $i($pid), $s($field)]);
    // Keep the already-proven actor/pawn construction and replace only its continuation.
    $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1] = $asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $fn = $b->functions['VerifyLive'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $code = [$b->call('KismetSystemLibrary', 'K2_ClearTimer', [$s('Radar'), $str('SSRadarTick')]),
        $let('LiveSource', $s('Progression')), $let('LivePlayer', $s('Player')), $let('Alternative', FilterExpr('Self'))];
    $validCases = ['{"Found":[true,false,true]}' => [1, 0, 1], " { \r\n\t\"Found\" : [ true , false , true ] } \n" => [1, 0, 1]];
    foreach ($validCases as $progress => $values) {
        $code[] = $invoke('SSRadarParseFoundProgress', [$str($progress)]); $code[] = $assert($get('SSRadarParsedFoundValid'), 'valid Found-only whitespace schema accepted');
        $code[] = $assert($eq($array('Array_Length', $get('SSRadarParsedFound')), count($values)), 'all Found entries retained');
        foreach ($values as $n => $value) { $code[] = $array('Array_Get', $get('SSRadarParsedFound'), $i($n), $l('Value')); $code[] = $assert($eq($l('Value'), $value), 'boolean Found element ' . $n . ' preserved'); }
    }
    $invalid = ['{"Fo und":[true]}', '{"Found":["t r u e"]}', '{"Found":[t r u e]}', '{"Found":[true],"Extra":0}', '{"Found":[true],"Found":[false]}',
        '{"Found":[true]}garbage', '{"Found":[true,]}', '{"Found":[true,null]}', '{"Found":[true,false,1]}', '{"found":[true]}', '{"Found":[true\\n]}',
        '{"Found:[true]}', '{"Found":null}', '{"Found":[true] } }', '{"Found":[true,,false]}', '{"Found":[tr ue]}', str_repeat(' ', 2049) . '{"Found":[true]}', '{"Found":[' . implode(',', array_fill(0, 257, 'true')) . ']}'];
    foreach ($invalid as $n => $progress) { $code[] = $invoke('SSRadarParseFoundProgress', [$str($progress)]); $code[] = $assert($math('Not_PreBool', $get('SSRadarParsedFoundValid')), 'malformed/unknown schema ' . $n . ' rejected before any completion'); }
    $code = [...$code, $seed(10770, 'ParentValid'), $seed(42201, 'SolvedSeed'), $let('Before', $get('SSRadarProgressQueriesTotal')),
        $assert($query(42200), 'fragment0 follows true parent Found despite parent bSolved false'), $assert($math('Not_PreBool', $query(42201)), 'fragment1 ignores fake-ID bSolved and follows false parent Found'),
        $assert($math('Not_PreBool', $query(42299)), 'out-of-range fragment remains visible'), $assert($query(42200), 'same-frame parent query remains consistent'),
        $assert($eq($math('Subtract_IntInt', $get('SSRadarProgressQueriesTotal'), $l('Before')), 1), 'same-frame fragments share one native parent read'),
        $seed(10770, 'ParentMalformed'), $assert($query(42200), 'same-frame cache intentionally retains previous frame-batch result'),
        $invoke('SSRadarPrepareSpecialCache', [$l('LiveSource'), $l('LivePlayer')]),
        $b->call('BlueprintMapLibrary', 'Map_Add', [$l('Lore'), $str('Belonging'), $bool(true)]), $b->call('BlueprintMapLibrary', 'Map_Add', [$l('Lore'), $str('Missing'), $bool(false)]),
        $b->call('BlueprintMapLibrary', 'Map_Add', [$l('Lore'), $str('A, comma title'), $bool(true)]), $invoke('SSRadarFillLoreCache', [$l('Lore')]),
        $assert($query(41002), 'native map consumer includes earned lore'), $assert($math('Not_PreBool', $query(41000)), 'native map consumer excludes present false lore'),
        $assert($query(41001), 'native lore cache normalizes commas'),
        $let('Cursor', $i(0)), ['label' => 'Oversize'], $unless($math('Less_IntInt', $l('Cursor'), $i(1025)), 'OversizeDone'),
        $let('Key', $b->call('KismetStringLibrary', 'Concat_StrStr', [$str('Oversize'), $b->call('KismetStringLibrary', 'Conv_IntToString', [$l('Cursor')])])),
        $b->call('BlueprintMapLibrary', 'Map_Add', [$l('OversizeLore'), $l('Key'), $bool(true)]), $let('Cursor', $math('Add_IntInt', $l('Cursor'), $i(1))), $jump('Oversize'),
        ['label' => 'OversizeDone'], $invoke('SSRadarFillLoreCache', [$l('OversizeLore')]), $assert($get('SSRadarLoreReady'), 'oversize lore attempt is cached'),
        $assert($eq($array('Array_Length', $get('SSRadarEarnedLoreKeys')), 0), 'oversize lore fails visible and discards old earned cache'),
        $let('BeforeLore', $get('SSRadarLoreQueriesTotal')), $assert($math('Not_PreBool', $query(41002)), 'oversize lore does not retain stale earned proof'),
        $assert($math('Not_PreBool', $query(41001)), 'second oversize query remains visible'),
        $assert($eq($math('Subtract_IntInt', $get('SSRadarLoreQueriesTotal'), $l('BeforeLore')), 0), 'oversize attempt does not retry native getter per candidate'),
        $timer('VerifyLive', 'VerifyMalformed', 0.2), ...$failure('VerifyLive')];
    $b->define('VerifyLive', [], ['Timer' => 'Struct', 'LiveSource' => 'Object', 'LivePlayer' => 'Object', 'Alternative' => 'Object', 'Value' => 'Int', 'Before' => 'Int', 'BeforeLore' => 'Int',
        'Lore' => 'Map', 'OversizeLore' => 'Map', 'Cursor' => 'Int', 'Key' => 'Str'], $code);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) { if (in_array($p->Name, ['Lore', 'OversizeLore'], true)) { $p->ElementSize = 80; $p->KeyProp = $b->field('Str', $p->Name); $p->ValueProp = $b->field('Bool', $p->Name . '_Value'); } }
    $fn = $b->functions['VerifyMalformed']; $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $code = [$let('LiveSource', $s('Progression')), $let('LivePlayer', $s('Player')), $let('Alternative', FilterExpr('Self')), $let('BeforeLore', $get('SSRadarLoreQueriesTotal')),
        $assert($math('Not_PreBool', $query(42200)), 'next-frame malformed parent progress preserves visibility'), $assert($math('Not_PreBool', $query(42201)), 'all malformed parent fragments remain visible'),
        $assert($math('Not_PreBool', $query(41002)), 'next frame refreshes actual native unearned lore'), $assert($math('Not_PreBool', $query(41000)), 'same-frame unearned lore remains false'),
        $assert($eq($math('Subtract_IntInt', $get('SSRadarLoreQueriesTotal'), $l('BeforeLore')), 1), 'actual lore getter called once for frame batch'),
        $seed(10770, 'ParentInverse'), $assert($math('Not_PreBool', $query(42201)), 'malformed parent result cached through same frame'),
        $invoke('SSRadarPrepareSpecialCache', [$l('Alternative'), $l('LivePlayer')]), $let('Before', $get('SSRadarProgressQueriesTotal')), $assert($query(42201), 'source identity switch discards malformed cached result'),
        $assert($eq($math('Subtract_IntInt', $get('SSRadarProgressQueriesTotal'), $l('Before')), 1), 'source switch performs fresh native read'),
        $invoke('SSRadarPrepareSpecialCache', [$l('LiveSource'), $l('Alternative')]), $let('Before', $get('SSRadarProgressQueriesTotal')), $assert($query(42201), 'player identity switch discards cached result'),
        $assert($eq($math('Subtract_IntInt', $get('SSRadarProgressQueriesTotal'), $l('Before')), 1), 'player switch performs fresh native read'),
        $seed(10770, 'ParentValid'), $timer('VerifyMalformed', 'VerifyFinal', 0.2), ...$failure('VerifyMalformed')];
    $b->define('VerifyMalformed', [], ['Timer' => 'Struct', 'LiveSource' => 'Object', 'LivePlayer' => 'Object', 'Alternative' => 'Object', 'Before' => 'Int', 'BeforeLore' => 'Int'], $code);
    $code = [$assert($query(42200), 'next-frame valid parent recovers after malformed state'), $assert($math('Not_PreBool', $query(42201)), 'recovered parent retains distinct false fragment'),
        $seed(10649, 'SolvedSeed'), $assert($query(10649), 'ordinary entrance still uses generic native solved status'),
        $b->method($s('Player'), 'Actor', 'K2_DestroyActor'), $assert($math('Not_PreBool', $query(42200)), 'unavailable authority never reuses cached fragment completion'),
        $assert($math('Not_PreBool', $query(41002)), 'unavailable authority preserves lore visibility'),
        FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $bool(true)]), $timer('VerifyFinal', 'Finish', 0.2), ...$failure('VerifyFinal')];
    $b->define('VerifyFinal', [], ['Timer' => 'Struct'], $code);
    $options = $b->call('KismetStringLibrary', 'Concat_StrStr', [$str('?game=/Script/Engine.GameModeBase?check='), $b->call('KismetStringLibrary', 'Conv_IntToString', [$s('FailureCheck')])]);
    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')]), $bool(true), $options]);
    $b->define('Finish', [], [], [$b->method($s('Radar'), 'Widget', 'RemoveFromParent'), $unless($s('Passed'), 'Fail'), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)]);
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $e) { if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $b->class->FuncMap[] = [$e->ObjectName, $n + 1]; $b->class->Children[] = $n + 1; } }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $manifest = ['checks' => $checks, 'assertions' => count($checks), 'parserNativeVM' => true, 'parentAuthority' => 'GISProgression.GetPuzzleSolveStatus',
        'loreConsumerUnitMaps' => true, 'loreActualGetterFrameRefresh' => true, 'specialCacheFrameBatches' => true, 'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    return $manifest;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { $args = getopt('', ['output-dir:', 'world:']); echo json_encode(BuildSpecialCompletionRuntimeFixture(realpath($args['output-dir'])), JSON_THROW_ON_ERROR) . "\n"; }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
