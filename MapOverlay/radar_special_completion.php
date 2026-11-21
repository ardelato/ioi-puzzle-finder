<?php

declare(strict_types=1);

/** Special identities are explicit catalog metadata, never inferred from fake PID arithmetic. */
function RadarSpecialDescriptors(array $records): array
{
    $result = [];
    foreach ($records as $record) {
        $record = (array)$record;
        $pid = $record['pid'] ?? null;
        if (!is_int($pid) || $pid <= 0 || isset($result[$pid])) { throw new InvalidArgumentException('Special completion IDs must be unique positive integers.'); }
        if (($record['kind'] ?? '') === 'lore') {
            $key = $record['key'] ?? null;
            if (!is_string($key) || $key === '' || strlen($key) > 512 || str_contains($key, ',') || str_contains($key, "\0")) {
                throw new InvalidArgumentException('Lore completion requires its normalized save key.');
            }
            $result[$pid] = ['pid' => $pid, 'kind' => 1, 'key' => $key, 'parentPid' => 0, 'fragmentIndex' => -1];
        } elseif (($record['kind'] ?? '') === 'monolith') {
            $parent = $record['parentPid'] ?? null; $index = $record['fragmentIndex'] ?? null;
            if (!is_int($parent) || $parent <= 0 || !is_int($index) || $index < 0 || $index >= 256) {
                throw new InvalidArgumentException('Monolith completion requires a parent puzzle and bounded zero-based fragment index.');
            }
            $result[$pid] = ['pid' => $pid, 'kind' => 2, 'key' => '', 'parentPid' => $parent, 'fragmentIndex' => $index];
        } else { throw new InvalidArgumentException('Unknown special completion descriptor.'); }
    }
    ksort($result, SORT_NUMERIC);
    return array_values($result);
}

/** Compile native read-only special authorities and a bounded Found-only parser. */
function AddRadarSpecialCompletion(RadarWidgetBuilder $b, array $descriptors): array
{
    $descriptors = RadarSpecialDescriptors($descriptors);
    foreach (['BlueprintMapLibrary', 'KismetStringLibrary'] as $class) {
        $b->native[$class] = OverlayImport($b->asset, $class, $b->native['Engine'], '/Script/CoreUObject', 'Class');
    }
    foreach (['Ids' => 'pid', 'Kinds' => 'kind', 'ParentIds' => 'parentPid', 'FragmentIndices' => 'fragmentIndex'] as $field => $column) {
        $b->arrayField('Int', 'SSRadarSpecial' . $field, array_column($descriptors, $column));
    }
    $b->arrayField('Str', 'SSRadarSpecialKeys', array_column($descriptors, 'key'));
    foreach (['SSRadarSpecialCacheReady', 'SSRadarLoreReady', 'SSRadarParsedFoundValid'] as $field) { $b->scalar('Bool', $field, 0); }
    $b->scalar('Float', 'SSRadarSpecialCacheTime', 0);
    $b->scalar('Int', 'SSRadarLoreQueriesTotal', 0);
    foreach (['SSRadarSpecialCacheSource', 'SSRadarSpecialCachePlayer'] as $field) { $b->objectField($field, $b->native['Object']); }
    $b->arrayField('Str', 'SSRadarEarnedLoreKeys', []);
    foreach (['SSRadarCachedParentIds', 'SSRadarCachedParentOffsets', 'SSRadarCachedParentCounts', 'SSRadarCachedParentFound', 'SSRadarParsedFound'] as $field) { $b->arrayField('Int', $field, []); }
    foreach (['SSRadarPrepareSpecialCache', 'SSRadarParseFoundProgress', 'SSRadarCacheMonolith', 'SSRadarFillLoreCache', 'SSRadarIsSpecialSolved'] as $function) { $b->reserve($function); }
    $state = static fn(string $n): object => FilterVariable(1, $n, true);
    $i = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $str = static fn(string $v): object => FilterExpr('StringConst', ['Value' => $v]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $text = static fn(string $fn, object ...$args): object => $b->call('KismetStringLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $state($n), 'Expression' => $v]);
    $setBool = static fn(string $n, object $v): object => FilterExpr('LetBool', ['VariableExpression' => $state($n), 'AssignmentExpression' => $v]);
    $unless = static fn(object $v, string $target): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $target, 'BooleanExpression' => $v]);
    $jump = static fn(string $target): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $target]);
    $call = static fn(string $fn, array $args = []): object => FilterCall($b->functions[$fn], $args, 'LocalFinalFunction');
    $ret = static fn(object $v): object => FilterExpr('Return', ['ReturnExpression' => $v]);

    $fn = $b->functions['SSRadarPrepareSpecialCache'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $cache = [$let('Time', $b->call('GameplayStatics', 'GetRealTimeSeconds', [FilterExpr('Self')])),
        $unless($math('BooleanAND', $state('SSRadarSpecialCacheReady'), $math('BooleanAND',
            $math('EqualEqual_FloatFloat', $l('Time'), $state('SSRadarSpecialCacheTime')), $math('BooleanAND',
                $math('EqualEqual_ObjectObject', $l('Source'), $state('SSRadarSpecialCacheSource')), $math('EqualEqual_ObjectObject', $l('Player'), $state('SSRadarSpecialCachePlayer'))))), 'Reset'), $jump('Done'),
        ['label' => 'Reset'], $set('SSRadarSpecialCacheTime', $l('Time')), $set('SSRadarSpecialCacheSource', $l('Source')), $set('SSRadarSpecialCachePlayer', $l('Player')),
        $setBool('SSRadarSpecialCacheReady', $bool(true)), $setBool('SSRadarLoreReady', $bool(false))];
    foreach (['SSRadarEarnedLoreKeys', 'SSRadarCachedParentIds', 'SSRadarCachedParentOffsets', 'SSRadarCachedParentCounts', 'SSRadarCachedParentFound'] as $field) { $cache[] = $array('Array_Clear', $state($field)); }
    $b->define('SSRadarPrepareSpecialCache', ['Source' => 'Object', 'Player' => 'Object'], ['Time' => 'Float'], [...$cache, ['label' => 'Done']]);

    $fn = $b->functions['SSRadarParseFoundProgress'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $letBool = static fn(string $n, object $v): object => FilterExpr('LetBool', ['VariableExpression' => $l($n), 'AssignmentExpression' => $v]);
    $eq = static fn(object $a, string $v): object => $text('EqualEqual_StrStr', $a, $str($v));
    $isLetter = static fn(object $code): object => $math('BooleanOR',
        $math('BooleanAND', $math('GreaterEqual_IntInt', $code, $i(65)), $math('LessEqual_IntInt', $code, $i(90))),
        $math('BooleanAND', $math('GreaterEqual_IntInt', $code, $i(97)), $math('LessEqual_IntInt', $code, $i(122))));
    $whitespace = $math('BooleanOR', $math('BooleanOR', $eq($l('Character'), ' '), $eq($l('Character'), "\t")), $math('BooleanOR', $eq($l('Character'), "\r"), $eq($l('Character'), "\n")));
    $parse = [$setBool('SSRadarParsedFoundValid', $bool(false)), $array('Array_Clear', $state('SSRadarParsedFound')), $let('Length', $text('Len', $l('Progress'))),
        $unless($math('BooleanAND', $math('GreaterEqual_IntInt', $l('Length'), $i(12)), $math('LessEqual_IntInt', $l('Length'), $i(2048))), 'Done'),
        $let('Normalized', $str('')), $let('Position', $i(0)), $let('PreviousCode', $i(0)), $letBool('InString', $bool(false)), $letBool('Gap', $bool(false)),
        ['label' => 'Character'], $unless($math('Less_IntInt', $l('Position'), $l('Length')), 'Schema'),
        $let('Character', $text('GetSubstring', $l('Progress'), $l('Position'), $i(1))), $unless($math('Not_PreBool', $eq($l('Character'), '\\')), 'Done'),
        $unless($eq($l('Character'), '"'), 'Whitespace'), $letBool('InString', $math('Not_PreBool', $l('InString'))),
        ['label' => 'Whitespace'], $unless($math('BooleanAND', $math('Not_PreBool', $l('InString')), $whitespace), 'Append'), $letBool('Gap', $bool(true)), $jump('NextCharacter'),
        ['label' => 'Append'], $let('Code', $text('GetCharacterAsNumber', $l('Character'), $i(0))),
        $unless($math('Not_PreBool', $math('BooleanAND', $l('Gap'), $math('BooleanAND', $math('Not_PreBool', $l('InString')), $math('BooleanAND', $isLetter($l('PreviousCode')), $isLetter($l('Code')))))), 'Done'),
        $letBool('Gap', $bool(false)), $let('PreviousCode', $l('Code')), $let('Normalized', $text('Concat_StrStr', $l('Normalized'), $l('Character'))),
        ['label' => 'NextCharacter'], $let('Position', $math('Add_IntInt', $l('Position'), $i(1))), $jump('Character'),
        ['label' => 'Schema'], $unless($math('Not_PreBool', $l('InString')), 'Done'),
        $unless($eq($text('Left', $l('Normalized'), $i(10)), '{"Found":['), 'Done'), $unless($eq($text('Right', $l('Normalized'), $i(2)), ']}'), 'Done'),
        $let('Body', $text('GetSubstring', $l('Normalized'), $i(10), $math('Subtract_IntInt', $text('Len', $l('Normalized')), $i(12)))),
        $let('Tokens', $text('ParseIntoArray', $l('Body'), $str(','), $bool(false))),
        $unless($math('LessEqual_IntInt', $array('Array_Length', $l('Tokens')), $i(256)), 'Done'), $let('Position', $i(0)),
        ['label' => 'Token'], $unless($math('Less_IntInt', $l('Position'), $array('Array_Length', $l('Tokens'))), 'Valid'),
        $array('Array_Get', $l('Tokens'), $l('Position'), $l('Character')), $unless($eq($l('Character'), 'true'), 'False'),
        $array('Array_Add', $state('SSRadarParsedFound'), $i(1)), $jump('NextToken'),
        ['label' => 'False'], $unless($eq($l('Character'), 'false'), 'Done'), $array('Array_Add', $state('SSRadarParsedFound'), $i(0)),
        ['label' => 'NextToken'], $let('Position', $math('Add_IntInt', $l('Position'), $i(1))), $jump('Token'),
        ['label' => 'Valid'], $setBool('SSRadarParsedFoundValid', $bool(true)), ['label' => 'Done']];
    $b->define('SSRadarParseFoundProgress', ['Progress' => 'Str'], ['Normalized' => 'Str', 'Body' => 'Str', 'Character' => 'Str', 'Tokens' => 'Array', 'Position' => 'Int', 'Length' => 'Int', 'Code' => 'Int', 'PreviousCode' => 'Int', 'InString' => 'Bool', 'Gap' => 'Bool'], $parse);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) { if ($property->Name === 'Tokens') { $property->Inner = $b->field('Str', 'Tokens'); } }

    $fn = $b->functions['SSRadarCacheMonolith'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $cacheParent = [$set('SSRadarProgressQueriesTotal', $math('Add_IntInt', $state('SSRadarProgressQueriesTotal'), $i(1))),
        $let('PuzzleStatus', $b->method($l('Source'), 'GISProgression', 'GetPuzzleSolveStatus', [$l('Player'), $l('ParentId'), $str(''), $str('')], FilterPointer($fn, 'PuzzleStatus'))),
        $let('Progress', FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PuzzleSolveData'], 'serializedProgress'), 'StructExpression' => $l('PuzzleStatus')])),
        $call('SSRadarParseFoundProgress', [$l('Progress')]),
        $array('Array_Add', $state('SSRadarCachedParentIds'), $l('ParentId')), $array('Array_Add', $state('SSRadarCachedParentOffsets'), $array('Array_Length', $state('SSRadarCachedParentFound'))),
        $unless($state('SSRadarParsedFoundValid'), 'Invalid'),
        $array('Array_Add', $state('SSRadarCachedParentCounts'), $array('Array_Length', $state('SSRadarParsedFound'))),
        $array('Array_Append', $state('SSRadarCachedParentFound'), $state('SSRadarParsedFound')), $jump('Done'),
        ['label' => 'Invalid'], $array('Array_Add', $state('SSRadarCachedParentCounts'), $i(-1)), ['label' => 'Done']];
    $b->define('SSRadarCacheMonolith', ['Source' => 'Object', 'Player' => 'Object', 'ParentId' => 'Int'], ['PuzzleStatus' => 'Struct', 'Progress' => 'Str'], $cacheParent);

    $fn = $b->functions['SSRadarFillLoreCache'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $fillLore = [$setBool('SSRadarLoreReady', $bool(true)), $array('Array_Clear', $state('SSRadarEarnedLoreKeys')),
        $b->call('BlueprintMapLibrary', 'Map_Keys', [$l('Lore'), $l('Keys')]),
        $unless($math('LessEqual_IntInt', $array('Array_Length', $l('Keys')), $i(1024)), 'Done'),
        $let('Cursor', $i(0)), ['label' => 'Key'], $unless($math('Less_IntInt', $l('Cursor'), $array('Array_Length', $l('Keys'))), 'Done'),
        $array('Array_Get', $l('Keys'), $l('Cursor'), $l('Key')),
        FilterExpr('LetBool', ['VariableExpression' => $l('Present'), 'AssignmentExpression' => $b->call('BlueprintMapLibrary', 'Map_Find', [$l('Lore'), $l('Key'), $l('Earned')])]),
        $unless($math('BooleanAND', $l('Present'), $l('Earned')), 'Next'),
        $let('Key', $text('Replace', $l('Key'), $str(','), $str(''), FilterExpr('ByteConst', ['Value' => 0]))), $array('Array_AddUnique', $state('SSRadarEarnedLoreKeys'), $l('Key')),
        ['label' => 'Next'], $let('Cursor', $math('Add_IntInt', $l('Cursor'), $i(1))), $jump('Key'), ['label' => 'Done']];
    $b->define('SSRadarFillLoreCache', ['Lore' => 'Map'], ['Keys' => 'Array', 'Key' => 'Str', 'Cursor' => 'Int', 'Present' => 'Bool', 'Earned' => 'Bool'], $fillLore);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        if ($property->Name === 'Keys') { $property->Inner = $b->field('Str', 'Keys'); }
        if ($property->Name === 'Lore') { $property->ElementSize = 80; $property->KeyProp = $b->field('Str', 'Lore'); $property->ValueProp = $b->field('Bool', 'Lore_Value'); }
    }

    $fn = $b->functions['SSRadarIsSpecialSolved'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $special = [$call('SSRadarPrepareSpecialCache', [$l('Source'), $l('Player')]),
        $array('Array_Get', $state('SSRadarSpecialKinds'), $l('Descriptor'), $l('Kind')),
        $unless($math('EqualEqual_IntInt', $l('Kind'), $i(1)), 'Monolith'),
        $unless($math('Not_PreBool', $state('SSRadarLoreReady')), 'LoreReady'),
        $setBool('SSRadarLoreReady', $bool(true)),
        $set('SSRadarLoreQueriesTotal', $math('Add_IntInt', $state('SSRadarLoreQueriesTotal'), $i(1))),
        $let('Lore', $b->method($l('Source'), 'GISProgression', 'GetEarnedLore', [$l('Player')], FilterPointer($fn, 'Lore'))),
        $call('SSRadarFillLoreCache', [$l('Lore')]),
        ['label' => 'LoreReady'], $array('Array_Get', $state('SSRadarSpecialKeys'), $l('Descriptor'), $l('Key')), $ret($array('Array_Contains', $state('SSRadarEarnedLoreKeys'), $l('Key'))),
        ['label' => 'Monolith'], $unless($math('EqualEqual_IntInt', $l('Kind'), $i(2)), 'Done'),
        $array('Array_Get', $state('SSRadarSpecialParentIds'), $l('Descriptor'), $l('ParentId')), $array('Array_Get', $state('SSRadarSpecialFragmentIndices'), $l('Descriptor'), $l('FragmentIndex')),
        $let('CacheIndex', $array('Array_Find', $state('SSRadarCachedParentIds'), $l('ParentId'))),
        $unless($math('Less_IntInt', $l('CacheIndex'), $i(0)), 'ParentReady'),
        $call('SSRadarCacheMonolith', [$l('Source'), $l('Player'), $l('ParentId')]), $let('CacheIndex', $math('Subtract_IntInt', $array('Array_Length', $state('SSRadarCachedParentIds')), $i(1))),
        ['label' => 'ParentReady'], $array('Array_Get', $state('SSRadarCachedParentCounts'), $l('CacheIndex'), $l('Count')),
        $unless($math('BooleanAND', $math('GreaterEqual_IntInt', $l('FragmentIndex'), $i(0)), $math('Less_IntInt', $l('FragmentIndex'), $l('Count'))), 'Done'),
        $array('Array_Get', $state('SSRadarCachedParentOffsets'), $l('CacheIndex'), $l('Offset')),
        $array('Array_Get', $state('SSRadarCachedParentFound'), $math('Add_IntInt', $l('Offset'), $l('FragmentIndex')), $l('Found')),
        $ret($math('EqualEqual_IntInt', $l('Found'), $i(1))), ['label' => 'Done']];
    $b->define('SSRadarIsSpecialSolved', ['Descriptor' => 'Int', 'Source' => 'Object', 'Player' => 'Object'], ['Kind' => 'Int', 'Lore' => 'Map', 'Key' => 'Str', 'ParentId' => 'Int', 'FragmentIndex' => 'Int', 'CacheIndex' => 'Int', 'Count' => 'Int', 'Offset' => 'Int', 'Found' => 'Int'], $special, null, 'Bool');
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        if ($property->Name === 'Keys') { $property->Inner = $b->field('Str', 'Keys'); }
        if ($property->Name === 'Lore') { $property->ElementSize = 80; $property->KeyProp = $b->field('Str', 'Lore'); $property->ValueProp = $b->field('Bool', 'Lore_Value'); }
    }
    return ['descriptors' => count($descriptors), 'lore' => count(array_filter($descriptors, static fn(array $d): bool => $d['kind'] === 1)),
        'monolithFragments' => count(array_filter($descriptors, static fn(array $d): bool => $d['kind'] === 2)), 'minimumPid' => $descriptors[0]['pid'] ?? 0,
        'maximumPid' => $descriptors[array_key_last($descriptors)]['pid'] ?? 0, 'cache' => 'source,player,world-real-time frame', 'jsonInputLimit' => 2048, 'foundLimit' => 256];
}
