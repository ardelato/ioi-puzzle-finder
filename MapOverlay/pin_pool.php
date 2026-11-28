<?php

declare(strict_types=1);

/**
 * Map pins as data, shown through emitters the map creates as it needs them.
 *
 * The game spends frame time on every MarkerEmitter that exists, hidden or not, and a level
 * full of pin actors costs load time, memory and world searches. So pins are parallel arrays
 * in the map widget, one index per pin, and the level holds no emitters at all, only one
 * empty host actor at the world origin (inject_markers.php). Showing a pin takes a free
 * emitter, or creates one on the host, and sets its icon, hover label and map offset from
 * the arrays; an emitter stays with its pin until the next full refresh frees them all.
 * Pins are grouped by type and zone, so a refresh walks only the selected types' pins in
 * the zone on screen.
 */

const OVERLAY_PIN_ZONES = 17;

/** Per-pin arrays and the type-and-zone grouping, from the render manifest's points. */
function OverlayPinData(array $renderPoints, array $typeKeys): array
{
    $slotOf = array_flip($typeKeys);
    $pins = ['x' => [], 'y' => [], 'z' => [], 'puzzle' => [], 'site' => [], 'hubZone' => [], 'relic' => [], 'source' => [], 'icon' => [], 'label' => []];
    $groups = array_fill(0, count($typeKeys) * OVERLAY_PIN_ZONES, []);
    foreach (array_values($renderPoints) as $pin => $point) {
        $point = (object)$point;
        $tags = $point->tags;
        $tagNumber = static function (string $prefix) use ($tags): int {
            foreach ($tags as $tag) {
                if (str_starts_with($tag, $prefix) && ctype_digit(substr($tag, strlen($prefix)))) { return (int)substr($tag, strlen($prefix)); }
            }
            return 0;
        };
        // Labels are baked as strings; the asset serializer rewrites '|' and mangles non-ASCII.
        if (str_contains($point->label, '|') || preg_match('/[^\x20-\x7e]/', $point->label)) {
            throw new RuntimeException("Pin label must be printable ASCII without '|': {$point->label}");
        }
        $pins['x'][] = (float)$point->x;
        $pins['y'][] = (float)$point->y;
        $pins['z'][] = (float)$point->z;
        $pins['puzzle'][] = $tagNumber('SaveStats.Puzzle.');
        $pins['site'][] = $tagNumber('SaveStats.Site.');
        $pins['hubZone'][] = $tagNumber('SaveStats.HubZone.');
        $pins['relic'][] = $tagNumber('SaveStats.PentadRelic.');
        $pins['source'][] = match (true) {
            in_array('SaveStats.Source.static', $tags, true) => 0, in_array('SaveStats.Source.hub', $tags, true) => 1,
            in_array('SaveStats.Source.spawn', $tags, true) => 2, default => 3,
        };
        $pins['icon'][] = OverlayPinIconNumber($point->iconAsset);
        $pins['label'][] = $point->label;
        // A pin joins its type's group in its zone; without a zone it is found only when the
        // map's zone is unknown.
        foreach ($tags as $tag) {
            if (!str_starts_with($tag, 'SaveStats.Type.')) { continue; }
            $type = substr($tag, strlen('SaveStats.Type.'));
            if (!isset($slotOf[$type])) { continue; }
            $zone = 0;
            foreach ($tags as $zoneTag) {
                if (str_starts_with($zoneTag, 'SaveStats.TypeZone.' . $type . '.')) { $zone = (int)substr($zoneTag, strlen('SaveStats.TypeZone.' . $type . '.')); }
            }
            if ($zone < 0 || $zone >= OVERLAY_PIN_ZONES) { throw new RuntimeException("Pin zone {$zone} is out of range."); }
            $groups[$slotOf[$type] * OVERLAY_PIN_ZONES + $zone][] = $pin;
        }
    }
    $members = [];
    $groupStart = [];
    foreach ($groups as $group) { $groupStart[] = count($members); array_push($members, ...$group); }
    $groupStart[] = count($members);
    return $pins + ['members' => $members, 'groupStart' => $groupStart];
}

/**
 * Adds the pin arrays and SSOverlayHidePins(), SSOverlaySetPin(Pin, Visible) and
 * SSOverlayPinVisible(Pin) to the map widget class; returns their export indices.
 */
function AddOverlayPinPool(object $asset, object $class, object $default, int $owner, object $functionTemplate, array $templates, array &$native, array $pinData): array
{
    $engine = OverlayImport($asset, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    foreach (['Array_Add', 'Array_Set'] as $method) {
        $native[$method] ??= OverlayImport($asset, $method, $native['KismetArrayLibrary'], '/Script/CoreUObject', 'Function');
    }
    foreach (['GreaterEqual_IntInt', 'MakeVector', 'Subtract_IntInt', 'Greater_IntInt', 'Not_PreBool'] as $method) {
        $native[$method] ??= OverlayImport($asset, $method, $native['KismetMathLibrary'], '/Script/CoreUObject', 'Function');
    }
    $native['Conv_StringToText'] ??= OverlayImport($asset, 'Conv_StringToText', $native['KismetTextLibrary'], '/Script/CoreUObject', 'Function');
    $native['Texture2D'] ??= OverlayImport($asset, 'Texture2D', $engine, '/Script/CoreUObject', 'Class');
    // Emitters are created on the host actor at run time.
    foreach (['AddComponentByClass', 'FinishAddComponent'] as $method) { $native[$method] ??= OverlayImport($asset, $method, $native['Actor'], '/Script/CoreUObject', 'Function'); }
    $native['Conv_VectorToTransform'] ??= OverlayImport($asset, 'Conv_VectorToTransform', $native['KismetMathLibrary'], '/Script/CoreUObject', 'Function');
    array_push($asset->NameMap, 'AddComponentByClass', 'FinishAddComponent', 'Conv_VectorToTransform',
        'VisibleOnScreen', 'DrawNameOnMap', 'HideCompletionIcon', 'ShowInfoPanel', 'SupportsFastTravel', 'SizeMultiplier');
    // Every type icon, indexed by its number (SSIcon012 is entry 12).
    $icons = [];
    foreach (GetOverlayTypes() as $info) {
        $number = OverlayPinIconNumber($info['iconAsset']);
        $package = OverlayImport($asset, '/Game/ASophia/UI/HUD/Markers/Textures/' . $info['iconAsset'], 0, '/Script/CoreUObject', 'Package');
        $icons[$number] = OverlayImport($asset, $info['iconAsset'], $package, '/Script/Engine', 'Texture2D');
        array_push($asset->NameMap, '/Game/ASophia/UI/HUD/Markers/Textures/' . $info['iconAsset'], $info['iconAsset']);
    }
    ksort($icons);
    if (array_keys($icons) !== range(0, count($icons) - 1)) { throw new RuntimeException('Pin icon numbers must run from 0 without gaps.'); }

    $field = static function (string $kind, string $name, string $flags = 'CPF_None', ?int $class = null) use ($asset, $templates): object {
        $template = match ($kind) { 'Bool' => $templates['BoolProperty'], 'Object', 'Array' => $templates[$kind . 'Property'], default => $templates['IntProperty'] };
        $p = FilterField($template, $name, $flags);
        if (in_array($kind, ['Str', 'Float'], true)) { $p->SerializedType = $kind . 'Property'; $p->ElementSize = ['Str' => 16, 'Float' => 4][$kind]; }
        if ($kind === 'Object') { $p->PropertyClass = $class; }
        $asset->NameMap[] = $name;
        $asset->NameMap[] = $p->SerializedType;
        return $p;
    };
    // A class array with baked values: Int, Float, Str or Object (with its class).
    $arrayField = static function (string $kind, string $name, array $values, ?int $innerClass = null) use ($asset, $class, $default, $field, $templates): void {
        $p = FilterField($templates['ArrayProperty'], $name);
        $p->Inner = $field($kind, $name, 'CPF_None', $innerClass);
        $class->LoadedProperties[] = $p;
        $asset->NameMap[] = $name;
        if ($values === []) { return; }
        $data = OverlayProperty('Array', $name, array_map(static fn(mixed $v): object => OverlayProperty($kind, '0', $v), $values));
        $data->ArrayType = $kind . 'Property';
        $default->Data[] = $data;
    };
    $s = static fn(string $name): object => FilterVariable($owner, $name, true);
    $setState = static fn(string $name, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($owner, $name), 'Variable' => $s($name), 'Expression' => $v]);
    $int = static fn(int $v): object => FilterExpr('IntConst', ['Value' => $v]);
    $jump = static fn(string $label): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $label]);
    $unless = static fn(object $condition, string $label): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $label, 'BooleanExpression' => $condition]);
    $call = static fn(string $fn, array $args): object => FilterCall($native[$fn], $args);
    $radar = static fn(string $fn, array $args, int $fnIndex): object => FilterContext($s('SSOverlayRadarState'),
        FilterExpr('VirtualFunction', ['VirtualFunctionName' => $fn, 'Parameters' => $args]), FilterPointer($fnIndex, 'ReturnValue'));

    // The pins, one index each, and their grouping by type and zone.
    foreach (['X' => 'x', 'Y' => 'y', 'Z' => 'z'] as $axis => $key) { $arrayField('Float', 'SSOverlayPin' . $axis, $pinData[$key]); }
    foreach (['Puzzle' => 'puzzle', 'Site' => 'site', 'HubZone' => 'hubZone', 'Relic' => 'relic', 'Source' => 'source', 'Icon' => 'icon'] as $name => $key) {
        $arrayField('Int', 'SSOverlayPin' . $name, $pinData[$key]);
    }
    $arrayField('Str', 'SSOverlayPinLabel', $pinData['label']);
    $arrayField('Int', 'SSOverlayPinMembers', $pinData['members']);
    $arrayField('Int', 'SSOverlayPinGroupStart', $pinData['groupStart']);
    // Per pin, its emitter's slot plus one (0 for none); the pin in each used slot; the emitters by slot.
    $arrayField('Int', 'SSOverlayPinSlot', array_fill(0, count($pinData['x']), 0));
    $arrayField('Int', 'SSOverlayPinOwners', []);
    $arrayField('Object', 'SSOverlayPinSlots', [], $native['MarkerEmitter']);
    $arrayField('Object', 'SSOverlayPinIcons', array_values($icons), $native['Texture2D']);
    $arrayField('Object', 'SSOverlayPinHosts', [], $native['Actor']);
    $class->LoadedProperties[] = $field('Int', 'SSOverlayPinUsed');
    $class->LoadedProperties[] = $field('Bool', 'SSOverlayPinsReady');
    $class->LoadedProperties[] = $field('Object', 'SSOverlayPinHost', 'CPF_None', $native['Actor']);

    $append = static function (string $name, array $locals, array $code) use ($asset, $class, $owner, $functionTemplate): int {
        $index = count($asset->Exports) + 1;
        $function = FilterFunction($functionTemplate, $index, $owner, $name, $locals, FilterCompileCode($code));
        foreach ($locals as $property) {
            if (str_contains($property->PropertyFlags, 'CPF_ReturnParm')) { $function->FunctionFlags .= ', FUNC_HasOutParms'; break; }
        }
        $asset->Exports[] = $function;
        $class->FuncMap[] = [$name, $index];
        array_unshift($class->Children, $index);
        $asset->NameMap[] = $name;
        return $index;
    };
    $markerOf = static fn(object $emitter): object => FilterContext($emitter, FilterVariable($native['MarkerEmitter'], 'Marker', true), FilterPointer($native['MarkerEmitter'], 'Marker'));
    $member = static fn(object $emitter, string $name): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($native['LocationMarkerData'], $name), 'StructExpression' => $markerOf($emitter)]);
    $setMember = static fn(object $emitter, string $name, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($native['LocationMarkerData'], $name), 'Variable' => $member($emitter, $name), 'Expression' => $v]);

    // SSOverlayHidePins: hide every used emitter and free them all.
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $hide = $append('SSOverlayHidePins', [$field('Int', 'Index'), $field('Int', 'Pin'), $field('Int', 'Zero'), $field('Object', 'Emitter', 'CPF_None', $native['MarkerEmitter'])], [
        $let('Zero', $int(0)), $let('Index', $int(0)), ['label' => 'Loop'],
        $unless($call('Less_IntInt', [$l('Index'), $s('SSOverlayPinUsed')]), 'Done'),
        $call('Array_Get', [$s('SSOverlayPinSlots'), $l('Index'), $l('Emitter')]),
        $unless($call('IsValid', [$l('Emitter')]), 'Freed'),
        FilterExpr('LetBool', ['VariableExpression' => $member($l('Emitter'), 'VisibleOnMap'), 'AssignmentExpression' => FilterExpr('False')]),
        ['label' => 'Freed'],
        // Array_Set reads its item through MostRecentPropertyAddress: pass plain locals only.
        $call('Array_Get', [$s('SSOverlayPinOwners'), $l('Index'), $l('Pin')]),
        $call('Array_Set', [$s('SSOverlayPinSlot'), $l('Pin'), $l('Zero'), FilterExpr('False')]),
        $let('Index', $call('Add_IntInt', [$l('Index'), $int(1)])), $jump('Loop'),
        ['label' => 'Done'], $call('Array_Clear', [$s('SSOverlayPinOwners')]), $setState('SSOverlayPinUsed', $int(0)),
        // A host that has gone (another level) is looked for again.
        $unless($call('Not_PreBool', [$call('IsValid', [$s('SSOverlayPinHost')])]), 'Hosted'),
        FilterExpr('LetBool', ['VariableExpression' => $s('SSOverlayPinsReady'), 'AssignmentExpression' => FilterExpr('False')]),
        ['label' => 'Hosted'], ...FilterReturn(),
    ]);

    // SSOverlayPinVisible: whether a pin shows, by its kind, progress, Pentad focus and availability.
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $get = static fn(string $array, string $local): object => $call('Array_Get', [$s('SSOverlayPin' . $array), $l('Pin'), $l($local)]);
    $return = static fn(object $v): object => FilterExpr('Return', ['ReturnExpression' => $v]);
    $visible = $append('SSOverlayPinVisible', [
        $field('Int', 'Pin', 'CPF_Parm'), $field('Bool', 'ReturnValue', 'CPF_Parm, CPF_OutParm, CPF_ReturnParm'),
        $field('Int', 'Source'), $field('Int', 'Site'), $field('Int', 'Puzzle'), $field('Int', 'Relic'), $field('Int', 'Zone'), $field('Int', 'FocusId'),
    ], [
        $get('Source', 'Source'),
        // Random-spot sites show only while the random-spot switch is on.
        $unless($call('Less_IntInt', [$l('Source'), $int(3)]), 'Hidden'),
        $unless($call('EqualEqual_IntInt', [$l('Source'), $int(2)]), 'Kind'),
        $unless($s('SSOverlaySource_spawn'), 'Hidden'),
        ['label' => 'Kind'], $unless($call('IsValid', [$s('SSOverlayRadarState')]), 'NoRadar'),
        // A site shows only while the compass has seen a live puzzle standing on it.
        $get('Site', 'Site'),
        $unless($call('Greater_IntInt', [$l('Site'), $int(0)]), 'Puzzle'),
        $return($radar('SSRadarIsSiteLive', [$l('Site')], $fn)),
        ['label' => 'Puzzle'], $get('Puzzle', 'Puzzle'),
        $unless($call('Greater_IntInt', [$l('Puzzle'), $int(0)]), 'Visible'),
        $unless($call('Not_PreBool', [$radar('SSRadarIsSolved', [$l('Puzzle')], $fn)]), 'Hidden'),
        // A Pentad's relics show only while that Pentad is the one being solved.
        $get('Relic', 'Relic'),
        $unless($call('Greater_IntInt', [$l('Relic'), $int(0)]), 'Hub'),
        $let('FocusId', FilterContext($s('SSOverlayRadarState'), FilterVariable($native['RadarClass'], 'SSRadarFocusPuzzleId', true), FilterPointer($native['RadarClass'], 'SSRadarFocusPuzzleId'))),
        $unless($call('Greater_IntInt', [$l('FocusId'), $int(0)]), 'Hidden'),
        $return($radar('SSRadarIsPentadRelicVisible', [$l('Puzzle'), $l('Relic')], $fn)),
        // A Hub puzzle shows only while the game has it out.
        ['label' => 'Hub'], $get('HubZone', 'Zone'),
        $unless($call('Greater_IntInt', [$l('Zone'), $int(0)]), 'Visible'),
        $return($radar('SSRadarIsPuzzleAvailable', [$l('Puzzle'), $l('Zone')], $fn)),
        // Without the compass nothing is known: sites and relics hide, the rest show.
        ['label' => 'NoRadar'], $get('Site', 'Site'), $unless($call('Greater_IntInt', [$l('Site'), $int(0)]), 'NoRadarRelic'), $jump('Hidden'),
        ['label' => 'NoRadarRelic'], $get('Relic', 'Relic'), $unless($call('Greater_IntInt', [$l('Relic'), $int(0)]), 'Visible'), $jump('Hidden'),
        ['label' => 'Visible'], $return(FilterExpr('True')),
        ['label' => 'Hidden'], $return(FilterExpr('False')), FilterExpr('EndOfScript'),
    ]);

    // SSOverlaySetPin: show or hide one pin, taking an emitter the first time it shows.
    $fn = count($asset->Exports) + 1;
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $get = static fn(string $array, string $local): object => $call('Array_Get', [$s('SSOverlayPin' . $array), $l('Pin'), $l($local)]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $origin = static fn(): object => $call('Conv_VectorToTransform', [$call('MakeVector', [FilterExpr('FloatConst', ['Value' => 0.0]), FilterExpr('FloatConst', ['Value' => 0.0]), FilterExpr('FloatConst', ['Value' => 0.0])])]);
    $set = $append('SSOverlaySetPin', [
        $field('Int', 'Pin', 'CPF_Parm'), $field('Bool', 'Visible', 'CPF_Parm'),
        $field('Int', 'Slot'), $field('Int', 'Icon'), $field('Object', 'Emitter', 'CPF_None', $native['MarkerEmitter']),
        $field('Object', 'Texture', 'CPF_None', $native['Texture2D']),
        $field('Str', 'Label'), $field('Float', 'X'), $field('Float', 'Y'), $field('Float', 'Z'),
    ], [
        $unless($call('BooleanAND', [$call('GreaterEqual_IntInt', [$l('Pin'), $int(0)]), $call('Less_IntInt', [$l('Pin'), $call('Array_Length', [$s('SSOverlayPinSlot')])])]), 'Done'),
        // The host actor is found once, the first time any pin is set.
        $unless($call('Not_PreBool', [$s('SSOverlayPinsReady')]), 'Ready'),
        FilterExpr('LetBool', ['VariableExpression' => $s('SSOverlayPinsReady'), 'AssignmentExpression' => FilterExpr('True')]),
        $call('GetAllActorsWithTag', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => OVERLAY_PIN_HOST]), $s('SSOverlayPinHosts')]),
        $unless($call('Greater_IntInt', [$call('Array_Length', [$s('SSOverlayPinHosts')]), $int(0)]), 'Ready'),
        $call('Array_Get', [$s('SSOverlayPinHosts'), $int(0), $s('SSOverlayPinHost')]),
        $call('Array_Clear', [$s('SSOverlayPinHosts')]),
        ['label' => 'Ready'],
        $get('Slot', 'Slot'),
        $unless($call('Greater_IntInt', [$l('Slot'), $int(0)]), 'Unplaced'),
        $call('Array_Get', [$s('SSOverlayPinSlots'), $call('Subtract_IntInt', [$l('Slot'), $int(1)]), $l('Emitter')]), $jump('Apply'),
        // A hidden pin without an emitter needs none; a shown one takes the next free emitter.
        ['label' => 'Unplaced'], $unless($l('Visible'), 'Done'),
        // With none free, one is created on the host: configured first, then registered, so the
        // game only ever sees it hidden, map-only, with its hover label on.
        $unless($call('GreaterEqual_IntInt', [$s('SSOverlayPinUsed'), $call('Array_Length', [$s('SSOverlayPinSlots')])]), 'HaveSlot'),
        $unless($call('IsValid', [$s('SSOverlayPinHost')]), 'Done'),
        $let('Emitter', FilterExpr('DynamicCast', ['ClassPtr' => $native['MarkerEmitter'], 'TargetExpression' => FilterContext($s('SSOverlayPinHost'),
            FilterCall($native['AddComponentByClass'], [FilterExpr('ObjectConst', ['Value' => $native['MarkerEmitter']]), $bool(false), $origin(), $bool(true)], 'FinalFunction'), FilterPointer($fn, 'Emitter'))])),
        $unless($call('IsValid', [$l('Emitter')]), 'Done'),
        ...array_map(static fn(array $f): object => FilterExpr('LetBool', ['VariableExpression' => $member($l('Emitter'), $f[0]), 'AssignmentExpression' => $bool($f[1])]),
            [['VisibleOnScreen', false], ['VisibleOnMap', false], ['DrawNameOnMap', false], ['HideCompletionIcon', true], ['ShowInfoPanel', true], ['SupportsFastTravel', false]]),
        $setMember($l('Emitter'), 'SizeMultiplier', FilterExpr('FloatConst', ['Value' => 0.6])),
        FilterContext($s('SSOverlayPinHost'), FilterCall($native['FinishAddComponent'], [$l('Emitter'), $bool(false), $origin()], 'FinalFunction'), FilterVoidPointer()),
        $call('Array_Add', [$s('SSOverlayPinSlots'), $l('Emitter')]),
        ['label' => 'HaveSlot'],
        $call('Array_Get', [$s('SSOverlayPinSlots'), $s('SSOverlayPinUsed'), $l('Emitter')]),
        $unless($call('IsValid', [$l('Emitter')]), 'Done'),
        $get('Icon', 'Icon'),
        $unless($call('BooleanAND', [$call('GreaterEqual_IntInt', [$l('Icon'), $int(0)]), $call('Less_IntInt', [$l('Icon'), $call('Array_Length', [$s('SSOverlayPinIcons')])])]), 'Labelled'),
        $call('Array_Get', [$s('SSOverlayPinIcons'), $l('Icon'), $l('Texture')]),
        $setMember($l('Emitter'), 'mapTex', $l('Texture')),
        ['label' => 'Labelled'], $get('Label', 'Label'),
        $setMember($l('Emitter'), 'MarkerName', $call('Conv_StringToText', [$l('Label')])),
        $get('X', 'X'), $get('Y', 'Y'), $get('Z', 'Z'),
        $setMember($l('Emitter'), 'MapWorldPositionOffset', $call('MakeVector', [$l('X'), $l('Y'), $l('Z')])),
        $call('Array_Add', [$s('SSOverlayPinOwners'), $l('Pin')]),
        $setState('SSOverlayPinUsed', $call('Add_IntInt', [$s('SSOverlayPinUsed'), $int(1)])),
        $let('Slot', $s('SSOverlayPinUsed')),
        $call('Array_Set', [$s('SSOverlayPinSlot'), $l('Pin'), $l('Slot'), FilterExpr('False')]),
        ['label' => 'Apply'],
        FilterExpr('LetBool', ['VariableExpression' => $member($l('Emitter'), 'VisibleOnMap'), 'AssignmentExpression' => $l('Visible')]),
        ['label' => 'Done'], ...FilterReturn(),
    ]);
    $asset->NameMap = array_merge($asset->NameMap, [OVERLAY_PIN_HOST, 'MapWorldPositionOffset', 'Marker', 'VisibleOnMap', 'mapTex', 'MarkerName',
        'Array_Add', 'Array_Set', 'MakeVector', 'SSRadarIsSiteLive', 'SSRadarIsSolved', 'SSRadarIsPentadRelicVisible', 'SSRadarIsPuzzleAvailable', 'SSRadarFocusPuzzleId']);
    return ['SetPin' => $set, 'HidePins' => $hide, 'PinVisible' => $visible];
}
