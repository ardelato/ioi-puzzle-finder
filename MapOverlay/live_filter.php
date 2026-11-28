<?php

declare(strict_types=1);

/** Add read-only live completion checks to the pins the last refresh walked. */
function AddOverlayLiveProgress(object $asset, object $class, object $default, int $owner, object $functionTemplate, object $intTemplate, array &$native): array
{
    $native['Greater_IntInt'] = OverlayImport($asset, 'Greater_IntInt', $native['KismetMathLibrary'], '/Script/CoreUObject', 'Function');
    $field = static function (string $name) use ($asset, $intTemplate): object {
        $p = FilterField($intTemplate, $name);
        $asset->NameMap[] = $name;
        $asset->NameMap[] = $p->SerializedType;
        return $p;
    };
    foreach (['SSOverlayProgressCursor', 'SSOverlayProgressVisited'] as $name) {
        $class->LoadedProperties[] = $field($name);
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
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($owner, $name), 'Variable' => $s($name), 'Expression' => $value]);
    // A few of the refresh's pins per call: hides newly solved ones and shows newly available ones.
    $native['LiveProgress'] = $append('SSOverlayRefreshProgress', [$field('Pin'), $field('Count')], [
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'FocusReady', 'BooleanExpression' => FilterCall($native['IsValid'], [$s('SSOverlayRadarState')])]),
        FilterRadarMethod($owner, 'SSRadarRefreshPentadFocus'),
        FilterRadarMethod($owner, 'SSRadarRefreshAvailability'),
        ['label' => 'FocusReady'],
        $set('SSOverlayProgressVisited', $int(0)),
        $let('Count', FilterCall($native['Array_Length'], [$s('SSOverlayPreviousPins')])),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Done', 'BooleanExpression' => FilterCall($native['Greater_IntInt'], [$l('Count'), $int(0)])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Reset', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$s('SSOverlayProgressCursor'), $l('Count')])]),
        FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Loop']), ['label' => 'Reset'], $set('SSOverlayProgressCursor', $int(0)), ['label' => 'Loop'],
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Done', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$s('SSOverlayProgressVisited'), $int(64)])]),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Done', 'BooleanExpression' => FilterCall($native['Less_IntInt'], [$s('SSOverlayProgressCursor'), $l('Count')])]),
        FilterCall($native['Array_Get'], [$s('SSOverlayPreviousPins'), $s('SSOverlayProgressCursor'), $l('Pin')]),
        $set('SSOverlayProgressCursor', FilterCall($native['Add_IntInt'], [$s('SSOverlayProgressCursor'), $int(1)])),
        $set('SSOverlayProgressVisited', FilterCall($native['Add_IntInt'], [$s('SSOverlayProgressVisited'), $int(1)])),
        FilterCall($native['SetPin'], [$l('Pin'), FilterCall($native['PinVisible'], [$l('Pin')], 'LocalFinalFunction')], 'LocalFinalFunction'),
        FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Loop']), ['label' => 'Done'], ...FilterReturn(),
    ]);
    $asset->NameMap = array_merge($asset->NameMap, ['SSRadarRefreshAvailability', 'SSRadarRefreshPentadFocus', 'SSOverlayPreviousPins']);
    return $functions;
}
