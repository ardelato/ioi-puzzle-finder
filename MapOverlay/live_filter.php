<?php

declare(strict_types=1);

/** Add read-only live completion checks to the existing selected map actors. */
function AddOverlayLiveProgress(object $asset, object $class, object $default, int $owner, object $functionTemplate, object $intTemplate, object $boolTemplate, object $objectTemplate, object $arrayTemplate, array &$native): array
{
    $engine = OverlayImport($asset, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    $strings = OverlayImport($asset, 'KismetStringLibrary', $engine, '/Script/CoreUObject', 'Class');
    foreach (['Conv_NameToString', 'StartsWith', 'RightChop', 'Conv_StringToInt'] as $method) {
        $native[$method] = OverlayImport($asset, $method, $strings, '/Script/CoreUObject', 'Function');
    }
    foreach (['Not_PreBool', 'Greater_IntInt'] as $method) {
        $native[$method] = OverlayImport($asset, $method, $native['KismetMathLibrary'], '/Script/CoreUObject', 'Function');
    }
    $field = static function (string $type, string $name, string $flags = 'CPF_None') use ($asset, $intTemplate, $boolTemplate, $objectTemplate): object {
        $p = FilterField(match ($type) { 'Bool' => $boolTemplate, 'Object' => $objectTemplate, default => $intTemplate }, $name, $flags);
        if (!in_array($type, ['Bool', 'Object'], true)) {
            $p->SerializedType = $type . 'Property';
            $p->ElementSize = match ($type) { 'Name' => 8, 'Str' => 16, default => 4 };
        }
        $asset->NameMap[] = $name;
        $asset->NameMap[] = $p->SerializedType;
        return $p;
    };
    foreach (['SSOverlayProgressCursor', 'SSOverlayProgressVisited'] as $name) {
        $class->LoadedProperties[] = $field('Int', $name);
        $default->Data[] = OverlayProperty('Int', $name, 0);
    }
    $int = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $s = static fn(string $name): object => FilterVariable($owner, $name, true);
    $functions = [];
    $append = static function (string $name, array $locals, array $code) use ($asset, $class, $owner, $functionTemplate, &$functions): int {
        $index = count($asset->Exports) + 1;
        $function = FilterFunction($functionTemplate, $index, $owner, $name, $locals, FilterCompileCode($code));
        foreach ($locals as $property) {
            if (str_contains($property->PropertyFlags, 'CPF_ReturnParm')) { $function->FunctionFlags .= ', FUNC_HasOutParms'; break; }
        }
        $asset->Exports[] = $function;
        $class->FuncMap[] = [$name, $index];
        array_unshift($class->Children, $index);
        $functions[] = $name;
        return $index;
    };
    foreach (['PuzzleId' => 'SaveStats.Puzzle.', 'PentadRelicIndex' => 'SaveStats.PentadRelic.', 'HubAvailabilityZone' => 'SaveStats.HubZone.', 'SiteNumber' => 'SaveStats.Site.'] as $key => $prefix) {
        $fn = count($asset->Exports) + 1;
        $l = static fn(string $name): object => FilterVariable($fn, $name);
        $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
        $tags = FilterField($arrayTemplate, 'Tags');
        $tags->Inner = $field('Name', 'Tags');
        $actor = $field('Object', 'PuzzleActor', 'CPF_Parm');
        $actor->PropertyClass = $native['Actor'];
        $asset->NameMap[] = 'Tags';
        $native[$key] = $append('SSOverlay' . $key, [$actor, $field('Int', 'ReturnValue', 'CPF_Parm, CPF_OutParm, CPF_ReturnParm'), $tags, $field('Int', 'Index'), $field('Name', 'Tag'), $field('Str', 'TagText')], [
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'None', 'BooleanExpression' => FilterCall($native['IsValid'], [$l('PuzzleActor')])]),
        $let('Tags', FilterContext($l('PuzzleActor'), FilterVariable($native['Actor'], 'Tags', true), FilterPointer($fn, 'Tags'))),
        $let('Index', $int(0)), ['label' => 'Loop'],
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'None', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$l('Index'), FilterCall($native['Array_Length'], [$l('Tags')])])]),
        FilterCall($native['Array_Get'], [$l('Tags'), $l('Index'), $l('Tag')]),
        $let('TagText', FilterCall($native['Conv_NameToString'], [$l('Tag')])),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Next', 'BooleanExpression' => FilterCall($native['StartsWith'], [$l('TagText'), FilterExpr('StringConst', ['Value' => $prefix]), FilterExpr('ByteConst', ['Value' => 1])])]),
        FilterExpr('Return', ['ReturnExpression' => FilterCall($native['Conv_StringToInt'], [FilterCall($native['RightChop'], [$l('TagText'), $int(strlen($prefix))])])]),
        ['label' => 'Next'], $let('Index', FilterCall($native['Add_IntInt'], [$l('Index'), $int(1)])),
        FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Loop']), ['label' => 'None'],
        FilterExpr('Return', ['ReturnExpression' => $int(0)]), FilterExpr('EndOfScript'),
        ]);
    }
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $actor = $field('Object', 'PuzzleActor', 'CPF_Parm');
    $actor->PropertyClass = $native['Actor'];
    $native['IsActorSolved'] = $append('SSOverlayIsActorSolved', [$actor, $field('Bool', 'ReturnValue', 'CPF_Parm, CPF_OutParm, CPF_ReturnParm'), $field('Int', 'PuzzleId')], [
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Unsolved', 'BooleanExpression' => FilterCall($native['IsValid'], [$s('SSOverlayRadarState')])]),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'PuzzleId'), 'Variable' => $l('PuzzleId'), 'Expression' => FilterCall($native['PuzzleId'], [$l('PuzzleActor')], 'LocalFinalFunction')]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Unsolved', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('PuzzleId'), $int(0)])]),
        FilterExpr('Return', ['ReturnExpression' => FilterContext($s('SSOverlayRadarState'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => 'SSRadarIsSolved', 'Parameters' => [$l('PuzzleId')]]), FilterPointer($fn, 'ReturnValue'))]),
        ['label' => 'Unsolved'], FilterExpr('Return', ['ReturnExpression' => FilterExpr('False')]), FilterExpr('EndOfScript'),
    ]);
    $asset->NameMap[] = 'SSRadarIsSolved';
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $actor = $field('Object', 'PuzzleActor', 'CPF_Parm');
    $actor->PropertyClass = $native['Actor'];
    $native['IsActorAvailable'] = $append('SSOverlayIsActorAvailable', [$actor, $field('Bool', 'ReturnValue', 'CPF_Parm, CPF_OutParm, CPF_ReturnParm'), $field('Int', 'PuzzleId'), $field('Int', 'AvailabilityZone'), $field('Int', 'SiteNumber')], [
        // A random-spot site shows only while the radar has seen a live puzzle standing on it.
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'SiteNumber'), 'Variable' => $l('SiteNumber'), 'Expression' => FilterCall($native['SiteNumber'], [$l('PuzzleActor')], 'LocalFinalFunction')]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Ordinary', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('SiteNumber'), $int(0)])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Hidden', 'BooleanExpression' => FilterCall($native['IsValid'], [$s('SSOverlayRadarState')])]),
        FilterExpr('Return', ['ReturnExpression' => FilterContext($s('SSOverlayRadarState'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => 'SSRadarIsSiteLive', 'Parameters' => [$l('SiteNumber')]]), FilterPointer($fn, 'ReturnValue'))]),
        ['label' => 'Ordinary'],
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'AvailabilityZone'), 'Variable' => $l('AvailabilityZone'), 'Expression' => FilterCall($native['HubAvailabilityZone'], [$l('PuzzleActor')], 'LocalFinalFunction')]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Visible', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('AvailabilityZone'), $int(0)])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Visible', 'BooleanExpression' => FilterCall($native['IsValid'], [$s('SSOverlayRadarState')])]),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'PuzzleId'), 'Variable' => $l('PuzzleId'), 'Expression' => FilterCall($native['PuzzleId'], [$l('PuzzleActor')], 'LocalFinalFunction')]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Visible', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('PuzzleId'), $int(0)])]),
        FilterExpr('Return', ['ReturnExpression' => FilterContext($s('SSOverlayRadarState'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => 'SSRadarIsPuzzleAvailable', 'Parameters' => [$l('PuzzleId'), $l('AvailabilityZone')]]), FilterPointer($fn, 'ReturnValue'))]),
        ['label' => 'Visible'], FilterExpr('Return', ['ReturnExpression' => FilterExpr('True')]),
        ['label' => 'Hidden'], FilterExpr('Return', ['ReturnExpression' => FilterExpr('False')]), FilterExpr('EndOfScript'),
    ]);
    $asset->NameMap = array_merge($asset->NameMap, ['SSRadarIsPuzzleAvailable', 'SSRadarRefreshAvailability', 'SSRadarIsSiteLive']);
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $actor = $field('Object', 'PuzzleActor', 'CPF_Parm');
    $actor->PropertyClass = $native['Actor'];
    $native['AllowsPentadActor'] = $append('SSOverlayAllowsPentadActor', [$actor, $field('Bool', 'ReturnValue', 'CPF_Parm, CPF_OutParm, CPF_ReturnParm'), $field('Int', 'PuzzleId'), $field('Int', 'FocusId'), $field('Int', 'RelicIndex'), $field('Bool', 'TagResult')], [
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Hidden', 'BooleanExpression' => FilterCall($native['IsValid'], [$l('PuzzleActor')])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Ordinary', 'BooleanExpression' => FilterContext($l('PuzzleActor'), FilterCall($native['ActorHasTag'], [FilterExpr('NameConst', ['Value' => 'SaveStats.PentadRelic'])], 'FinalFunction'), FilterPointer($fn, 'TagResult'))]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Hidden', 'BooleanExpression' => FilterCall($native['IsValid'], [$s('SSOverlayRadarState')])]),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'FocusId'), 'Variable' => $l('FocusId'), 'Expression' => FilterContext($s('SSOverlayRadarState'), FilterVariable($native['RadarClass'], 'SSRadarFocusPuzzleId', true), FilterPointer($native['RadarClass'], 'SSRadarFocusPuzzleId'))]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Hidden', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('FocusId'), $int(0)])]),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'PuzzleId'), 'Variable' => $l('PuzzleId'), 'Expression' => FilterCall($native['PuzzleId'], [$l('PuzzleActor')], 'LocalFinalFunction')]),
        FilterExpr('Let', ['Value' => FilterPointer($fn, 'RelicIndex'), 'Variable' => $l('RelicIndex'), 'Expression' => FilterCall($native['PentadRelicIndex'], [$l('PuzzleActor')], 'LocalFinalFunction')]),
        FilterExpr('Return', ['ReturnExpression' => FilterContext($s('SSOverlayRadarState'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => 'SSRadarIsPentadRelicVisible', 'Parameters' => [$l('PuzzleId'), $l('RelicIndex')]]), FilterPointer($fn, 'ReturnValue'))]),
        ['label' => 'Ordinary'], FilterExpr('Return', ['ReturnExpression' => FilterExpr('True')]),
        ['label' => 'Hidden'], FilterExpr('Return', ['ReturnExpression' => FilterExpr('False')]), FilterExpr('EndOfScript'),
    ]);
    $asset->NameMap = array_merge($asset->NameMap, ['SaveStats.PentadRelic', 'SSRadarFocusPuzzleId', 'SSRadarRefreshPentadFocus', 'SSRadarIsPentadRelicVisible']);
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($owner, $name), 'Variable' => $s($name), 'Expression' => $value]);
    $actorCall = static fn(int $method, array $args, string $result): object => FilterContext($l('CurrentActor'), FilterCall($method, $args, 'FinalFunction'), FilterPointer($fn, $result));
    $tag = static fn(string $tag): object => $actorCall($native['ActorHasTag'], [FilterExpr('NameConst', ['Value' => $tag])], 'TagResult');
    $source = FilterCall($native['BooleanOR'], [FilterCall($native['BooleanOR'], [$tag('SaveStats.Source.static'), $tag('SaveStats.Source.hub')]),
        FilterCall($native['BooleanAND'], [$s('SSOverlaySource_spawn'), $tag('SaveStats.Source.spawn')])]);
    $target = FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($native['LocationMarkerData'], 'VisibleOnMap'),
        'StructExpression' => FilterContext($l('Emitter'), FilterVariable($native['MarkerEmitter'], 'Marker', true), FilterPointer($native['MarkerEmitter'], 'Marker'))]);
    $actor = $field('Object', 'CurrentActor'); $actor->PropertyClass = $native['Actor'];
    $emitter = $field('Object', 'Emitter'); $emitter->PropertyClass = $native['MarkerEmitter'];
    $native['LiveProgress'] = $append('SSOverlayRefreshProgress', [$actor, $emitter, $field('Int', 'Count'), $field('Bool', 'TagResult')], [
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'FocusReady', 'BooleanExpression' => FilterCall($native['IsValid'], [$s('SSOverlayRadarState')])]),
        FilterRadarMethod($owner, 'SSRadarRefreshPentadFocus'),
        FilterRadarMethod($owner, 'SSRadarRefreshAvailability'),
        ['label' => 'FocusReady'],
        $set('SSOverlayProgressVisited', $int(0)),
        $let('Count', FilterCall($native['Array_Length'], [$s('SSOverlayPreviousActors')])),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Done', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('Count'), $int(0)])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Reset', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$s('SSOverlayProgressCursor'), $l('Count')])]),
        FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Loop']), ['label' => 'Reset'], $set('SSOverlayProgressCursor', $int(0)), ['label' => 'Loop'],
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Done', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$s('SSOverlayProgressVisited'), $int(64)])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Done', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$s('SSOverlayProgressCursor'), $l('Count')])]),
        FilterCall($native['Array_Get'], [$s('SSOverlayPreviousActors'), $s('SSOverlayProgressCursor'), $l('CurrentActor')]),
        $set('SSOverlayProgressCursor', FilterCall($native['Add_IntInt'], [$s('SSOverlayProgressCursor'), $int(1)])),
        $set('SSOverlayProgressVisited', FilterCall($native['Add_IntInt'], [$s('SSOverlayProgressVisited'), $int(1)])),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Loop', 'BooleanExpression' => FilterCall($native['IsValid'], [$l('CurrentActor')])]),
        $let('Emitter', FilterExpr('DynamicCast', ['ClassPtr' => $native['MarkerEmitter'], 'TargetExpression' => $actorCall($native['GetComponentByClass'], [FilterExpr('ObjectConst', ['Value' => $native['MarkerEmitter']])], 'Emitter')])),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Loop', 'BooleanExpression' => FilterCall($native['IsValid'], [$l('Emitter')])]),
        FilterExpr('LetBool', ['VariableExpression' => $target, 'AssignmentExpression' => FilterCall($native['BooleanAND'], [$source, FilterCall($native['BooleanAND'], [FilterCall($native['Not_PreBool'], [FilterCall($native['IsActorSolved'], [$l('CurrentActor')], 'LocalFinalFunction')]), FilterCall($native['BooleanAND'], [FilterCall($native['AllowsPentadActor'], [$l('CurrentActor')], 'LocalFinalFunction'), FilterCall($native['IsActorAvailable'], [$l('CurrentActor')], 'LocalFinalFunction')])])])]),
        FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Loop']), ['label' => 'Done'], ...FilterReturn(),
    ]);
    return $functions;
}
