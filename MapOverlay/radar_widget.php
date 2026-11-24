<?php

declare(strict_types=1);

require_once __DIR__ . '/filter_widget.php';
require_once __DIR__ . '/radar_data.php';
require_once __DIR__ . '/radar_special_completion.php';
require_once __DIR__ . '/radar_labyrinth.php';
require_once __DIR__ . '/radar_settings.php';
require_once __DIR__ . '/radar_live_sites.php';

/** Bytecode helpers kept separate from the established map generator. */
function RadarExprSize(object $e): int
{
    $token = explode(',', substr($e->{'$type'}, strrpos($e->{'$type'}, '.') + 1))[0];
    return match ($token) {
        'EX_FloatConst' => 5,
        'EX_RotationConst', 'EX_VectorConst' => 13,
        'EX_Nothing', 'EX_Self', 'EX_True', 'EX_False', 'EX_EndOfScript' => 1,
        'EX_IntConst', 'EX_Jump' => 5,
        'EX_ByteConst' => 2,
        'EX_NameConst', 'EX_InstanceDelegate' => 13,
        'EX_StringConst' => 2 + strlen($e->Value),
        'EX_ObjectConst', 'EX_LocalVariable', 'EX_LocalOutVariable', 'EX_InstanceVariable' => 9,
        'EX_CallMath', 'EX_FinalFunction', 'EX_LocalFinalFunction' => 10 + array_sum(array_map('RadarExprSize', $e->Parameters)),
        'EX_VirtualFunction' => 14 + array_sum(array_map('RadarExprSize', $e->Parameters)),
        'EX_CallMulticastDelegate' => 10 + RadarExprSize($e->Delegate) + array_sum(array_map('RadarExprSize', $e->Parameters)),
        'EX_Let' => 9 + RadarExprSize($e->Variable) + RadarExprSize($e->Expression),
        'EX_LetBool' => 1 + RadarExprSize($e->VariableExpression) + RadarExprSize($e->AssignmentExpression),
        'EX_JumpIfNot' => 5 + RadarExprSize($e->BooleanExpression),
        'EX_Context' => 13 + RadarExprSize($e->ObjectExpression) + RadarExprSize($e->ContextExpression),
        'EX_DynamicCast' => 9 + RadarExprSize($e->TargetExpression),
        'EX_StructMemberContext' => 9 + RadarExprSize($e->StructExpression),
        'EX_Return' => 1 + RadarExprSize($e->ReturnExpression),
        default => throw new RuntimeException('Unsupported radar expression ' . $token),
    };
}

function RadarCompile(array $parts): array
{
    $labels = [];
    $offset = 0;
    foreach ($parts as $part) {
        if (is_array($part)) {
            // A repeated label would silently retarget every jump to its last definition.
            if (isset($labels[$part['label']])) { throw new RuntimeException('Duplicate radar label ' . $part['label']); }
            $labels[$part['label']] = $offset;
        }
        else { $offset += RadarExprSize($part); }
    }
    $result = [];
    foreach ($parts as $part) {
        if (is_array($part)) { continue; }
        if (isset($part->__target)) {
            if (!isset($labels[$part->__target])) { throw new RuntimeException('Missing radar label ' . $part->__target); }
            $part->CodeOffset = $labels[$part->__target];
            unset($part->__target);
        }
        $result[] = $part;
    }
    return $result;
}

function RadarContext(object $object, object $expression, ?object $pointer = null): object
{
    $pointer ??= (object)['$type' => 'UAssetAPI.Kismet.Bytecode.KismetPropertyPointer, UAssetAPI', 'New' => (object)[
        '$type' => 'UAssetAPI.UnrealTypes.FFieldPath, UAssetAPI', 'Path' => [], 'ResolvedOwner' => 0,
    ]];
    return FilterExpr('Context', ['ObjectExpression' => $object, 'Offset' => RadarExprSize($expression),
        'RValuePointer' => $pointer, 'ContextExpression' => $expression]);
}

/** A small assembler for an independent UserWidget, with no map/HUD imports. */
final class RadarWidgetBuilder
{
    public object $asset;
    public object $class;
    public object $default;
    public array $templates = [];
    public array $native = [];
    public array $functions = [];
    public array $functionLocals = [];
    private object $normal;
    private object $functionTemplate;
    private array $classes = [];
    public int $tree = 3;
    public int $root = 4;

    public function __construct(object $base)
    {
        $byName = array_column($base->Exports, null, 'ObjectName');
        foreach ($base->Exports as $export) {
            foreach ($export->LoadedProperties ?? [] as $p) { $this->templates[$p->SerializedType] ??= $p; }
        }
        $this->templates['IntProperty'] = $base->Exports[0]->LoadedProperties[0];
        $this->asset = unserialize(serialize($base));
        $this->asset->Exports = [];
        $this->asset->Imports = [];
        $this->asset->DependsMap = [];
        $this->asset->SoftPackageReferenceList = [];
        $this->asset->NameMap = ['None', '/Game/SaveStats/WBP_SSOverlayRadar'];
        $this->asset->PackageGuid = '{8C96B11B-6257-4F1D-83AC-66943A2A1E60}';
        $this->functionTemplate = unserialize(serialize($byName['OnNextZoneClicked']));
        $this->normal = unserialize(serialize($byName['WidgetTree']));
        foreach (['CoreUObject', 'Engine', 'UMG', 'InputCore', 'IslandsofInsight'] as $p) {
            $this->native[$p] = OverlayImport($this->asset, '/Script/' . $p, 0, '/Script/CoreUObject', 'Package');
        }
        foreach (['Object' => 'CoreUObject', 'Function' => 'CoreUObject', 'UserWidget' => 'UMG', 'Widget' => 'UMG',
            'WidgetTree' => 'UMG', 'WidgetBlueprintGeneratedClass' => 'UMG', 'GameplayStatics' => 'Engine',
            'KismetMathLibrary' => 'Engine', 'KismetArrayLibrary' => 'Engine', 'KismetSystemLibrary' => 'Engine',
            'KismetTextLibrary' => 'Engine', 'InputSettings' => 'Engine', 'PlayerController' => 'Engine', 'Actor' => 'Engine', 'Pawn' => 'Engine',
            'SubsystemBlueprintLibrary' => 'Engine', 'GISProgression' => 'IslandsofInsight', 'SophiaHUD' => 'IslandsofInsight', 'SophiaCharacter' => 'IslandsofInsight'] as $c => $p) {
            $this->native[$c] = OverlayImport($this->asset, $c, $this->native[$p], '/Script/CoreUObject', 'Class');
        }
        foreach (['Vector', 'Rotator'] as $s) { $this->native[$s] = OverlayImport($this->asset, $s, $this->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct'); }
        $this->native['TimerHandle'] = OverlayImport($this->asset, 'TimerHandle', $this->native['Engine'], '/Script/CoreUObject', 'ScriptStruct');
        $this->native['InputActionKeyMapping'] = OverlayImport($this->asset, 'InputActionKeyMapping', $this->native['Engine'], '/Script/CoreUObject', 'ScriptStruct');
        $this->native['Key'] = OverlayImport($this->asset, 'Key', $this->native['InputCore'], '/Script/CoreUObject', 'ScriptStruct');
        $this->native['PuzzleSolveData'] = OverlayImport($this->asset, 'PuzzleSolveData', $this->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
        $class = unserialize(serialize($byName['WBP_LargeMap_C']));
        $class->{'$index'} = 1;
        $class->ObjectName = 'WBP_SSOverlayRadar_C';
        $class->ClassIndex = $this->native['WidgetBlueprintGeneratedClass'];
        $class->TemplateIndex = OverlayImport($this->asset, 'Default__WidgetBlueprintGeneratedClass', $this->native['UMG'], '/Script/UMG', 'WidgetBlueprintGeneratedClass');
        $class->ClassWithin = $this->native['Object'];
        $class->SuperStruct = $class->SuperIndex = $this->native['UserWidget'];
        $class->ClassDefaultObject = 2;
        $class->Interfaces = [];
        $class->FuncMap = $class->Children = $class->LoadedProperties = [];
        $class->Data = [OverlayProperty('Object', 'WidgetTree', 3), OverlayProperty('Bool', 'bClassRequiresNativeTick', 0)];
        $class->SerializationBeforeSerializationDependencies = [$this->native['UserWidget']];
        $class->CreateBeforeSerializationDependencies = [3];
        $class->SerializationBeforeCreateDependencies = [$class->ClassIndex, $class->TemplateIndex];
        $class->CreateBeforeCreateDependencies = [$this->native['UserWidget']];
        $class->Extras = '';
        $this->class = $class;
        $defaultNative = OverlayImport($this->asset, 'Default__UserWidget', $this->native['UMG'], '/Script/UMG', 'UserWidget');
        $this->default = OverlayExport($this->normal, 2, 'Default__WBP_SSOverlayRadar_C', 0, 1, $defaultNative, [
            FilterEnum('Visibility', 'ESlateVisibility', 'HitTestInvisible'), OverlayProperty('Bool', 'bIsFocusable', 0),
            OverlayProperty('Bool', 'bHasScriptImplementedTick', 0), OverlayProperty('Bool', 'bHasScriptImplementedPaint', 0),
        ]);
        $this->default->ObjectFlags = 'RF_Public, RF_ClassDefaultObject, RF_ArchetypeObject';
        $this->default->CreateBeforeCreateDependencies = [];
        $treeNative = OverlayImport($this->asset, 'Default__WidgetTree', $this->native['UMG'], '/Script/UMG', 'WidgetTree');
        $tree = OverlayExport($this->normal, 3, 'WidgetTree', 1, $this->native['WidgetTree'], $treeNative, [OverlayProperty('Object', 'RootWidget', 4)]);
        $tree->CreateBeforeSerializationDependencies = [4];
        $this->asset->Exports = [$class, $this->default, $tree];
        $this->widget('CanvasPanel', 'SSRadarCanvas', [FilterArray('Slots', []), FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed')]);
        $this->objectField('SSRadarCanvas', $this->classes['CanvasPanel'][0]);
        foreach (['Function', 'UserWidget', 'WidgetTree'] as $name) { $this->name($name); }
    }

    public function name(string $name): void { if ($name !== '') { $this->asset->NameMap[] = $name; $this->asset->NameMap[] = preg_replace('/_[0-9]+$/', '', $name); } }
    public function field(string $kind, string $name, string $flags = 'CPF_None'): object
    {
        $type = $kind . 'Property';
        $p = unserialize(serialize($this->templates[$type] ?? $this->templates['IntProperty']));
        $fieldClass = in_array($kind, ['Int', 'Float', 'Name', 'Text', 'Str'], true) ? 'Generic' : $kind;
        $p->{'$type'} = 'UAssetAPI.FieldTypes.F' . $fieldClass . 'Property, UAssetAPI';
        $p->SerializedType = $type;
        $p->Name = $name;
        $p->PropertyFlags = $flags;
        $p->Flags = 'RF_Public';
        $p->ElementSize = match ($kind) { 'Bool', 'Byte' => 1, 'Name', 'Object' => 8, 'Array', 'Str' => 16, 'Text' => 24, 'Struct' => 12, default => 4 };
        if ($kind === 'Byte') { $p->Enum = 0; }
        $this->name($name); $this->name($type);
        return $p;
    }
    public function scalar(string $kind, string $name, mixed $value): void
    {
        $this->class->LoadedProperties[] = $this->field($kind, $name, 'CPF_BlueprintVisible');
        $this->default->Data[] = OverlayProperty($kind, $name, $value);
    }
    public function structField(string $name, string $type, int $size, array $values): void
    {
        $p = $this->field('Struct', $name);
        $p->Struct = $this->native[$type];
        $p->ElementSize = $size;
        $this->class->LoadedProperties[] = $p;
        $this->default->Data[] = FilterStruct($name, $type, $values);
    }
    public function arrayField(string $kind, string $name, array $values): void
    {
        $p = $this->field('Array', $name);
        $p->Inner = $this->field($kind, $name);
        $this->class->LoadedProperties[] = $p;
        $v = OverlayProperty('Array', $name, array_map(static fn($x): object => OverlayProperty($kind, '0', $x), $values));
        $v->ArrayType = $kind . 'Property';
        $this->default->Data[] = $v;
    }
    public function objectField(string $name, int $nativeClass): void
    {
        $p = $this->field('Object', $name, 'CPF_BlueprintVisible, CPF_ExportObject, CPF_InstancedReference, CPF_RepSkip, CPF_PersistentInstance');
        $p->PropertyClass = $nativeClass;
        $this->class->LoadedProperties[] = $p;
    }
    public function widget(string $kind, string $name, array $data): int
    {
        if (!isset($this->classes[$kind])) {
            $this->classes[$kind] = [OverlayImport($this->asset, $kind, $this->native['UMG'], '/Script/CoreUObject', 'Class'),
                OverlayImport($this->asset, 'Default__' . $kind, $this->native['UMG'], '/Script/UMG', $kind)];
        }
        [$class, $default] = $this->classes[$kind];
        $index = count($this->asset->Exports) + 1;
        $export = OverlayExport($this->normal, $index, $name, $this->tree, $class, $default, $data);
        $export->Extras = '';
        $this->asset->Exports[] = $export;
        $this->name($name);
        return $index;
    }
    public function child(string $kind, string $name, array $data, float $x, float $y, float $w, float $h): int
    {
        $child = $this->widget($kind, $name, $data);
        $slot = $this->widget('CanvasPanelSlot', $name . 'Slot', [
            FilterStruct('LayoutData', 'AnchorData', [FilterStruct('Offsets', 'Margin', [OverlayProperty('Float', 'Left', $x), OverlayProperty('Float', 'Top', $y), OverlayProperty('Float', 'Right', $w), OverlayProperty('Float', 'Bottom', $h)]), FilterStruct('Anchors', 'Anchors', [FilterVector('Minimum', 0, 0), FilterVector('Maximum', 0, 0)]), FilterVector('Alignment', 0.5, 0.5)]),
            OverlayProperty('Object', 'Parent', $this->root), OverlayProperty('Object', 'Content', $child),
        ]);
        $this->asset->Exports[$slot - 1]->OuterIndex = $this->root;
        $this->asset->Exports[$child - 1]->Data[] = OverlayProperty('Object', 'Slot', $slot);
        $this->asset->Exports[$this->root - 1]->Data[0]->Value[] = OverlayProperty('Object', '0', $slot);
        $this->objectField($name, $this->classes[$kind][0]);
        return $child;
    }
    public function importMethod(string $owner, string $method): int
    {
        if (!isset($this->native[$owner])) { $this->native[$owner] = OverlayImport($this->asset, $owner, $this->native['UMG'], '/Script/CoreUObject', 'Class'); }
        return $this->native[$owner . '.' . $method] ??= OverlayImport($this->asset, $method, $this->native[$owner], '/Script/CoreUObject', 'Function');
    }
    public function call(string $owner, string $method, array $args = []): object { return FilterCall($this->importMethod($owner, $method), $args); }
    public function method(object $object, string $owner, string $method, array $args = [], ?object $pointer = null): object
    { return RadarContext($object, FilterCall($this->importMethod($owner, $method), $args, 'FinalFunction'), $pointer); }
    public function reserve(string $name): int
    {
        $i = count($this->asset->Exports) + 1;
        $this->functions[$name] = $i;
        $this->asset->Exports[] = (object)[];
        $this->name($name);
        return $i;
    }
    public function define(string $name, array $parameters, array $locals, array $parts, ?string $event = null, ?string $returnType = null): void
    {
        $i = $this->functions[$name];
        $properties = [];
        foreach ($parameters as $n => $kind) {
            $p = $this->field($kind, $n, 'CPF_Parm');
            if ($kind === 'Object') { $p->PropertyClass = $this->native['Object']; }
            if ($kind === 'Struct') {
                // Struct parameters are FKeys (rebinding); others would need their own mapping.
                if ($n !== 'InputKey') { throw new InvalidArgumentException('Unsupported radar struct parameter ' . $n); }
                $p->Struct = $this->native['Key'];
                $p->ElementSize = 32;
            }
            $properties[] = $p;
        }
        if ($returnType !== null) {
            if (!in_array($returnType, ['Bool', 'Int'], true)) { throw new InvalidArgumentException('Unsupported radar result type.'); }
            $properties[] = $this->field($returnType, 'ReturnValue', 'CPF_Parm, CPF_OutParm, CPF_ReturnParm');
        }
        foreach ($locals as $n => $kind) {
            $p = $this->field($kind, $n);
            if ($kind === 'Object') { $p->PropertyClass = $this->native['Object']; }
            if ($kind === 'Struct') {
                $struct = match (true) { $n === 'PlayerRotation' => 'Rotator', $n === 'Timer' => 'TimerHandle', $n === 'Transform' => 'WidgetTransform', $n === 'Translation' => 'Vector2D',
                    $n === 'InputMapping' => 'InputActionKeyMapping', $n === 'InputKey', $n === 'OldKey', $n === 'OtherKey', (bool)preg_match('/^(Default|Loaded)Key[A-Za-z]+$/', $n) => 'Key',
                    $n === 'PuzzleStatus' => 'PuzzleSolveData', $n === 'Ping' => 'LocationPingInfo', default => 'Vector' };
                $p->Struct = $this->native[$struct];
                $p->ElementSize = match ($struct) { 'TimerHandle', 'Vector2D' => 8, 'WidgetTransform' => 28, 'InputActionKeyMapping', 'LocationPingInfo' => 48, 'Key' => 32, 'PuzzleSolveData' => 40, default => 12 };
            }
            $properties[] = $p;
        }
        $end = match ($returnType) {
            'Bool' => [FilterExpr('Return', ['ReturnExpression' => FilterExpr('False')]), FilterExpr('EndOfScript')],
            'Int' => [FilterExpr('Return', ['ReturnExpression' => FilterExpr('IntConst', ['Value' => 0])]), FilterExpr('EndOfScript')],
            default => FilterReturn() };
        $code = RadarCompile([...$parts, ...$end]);
        $fn = FilterFunction($this->functionTemplate, $i, 1, $name, $properties, []);
        $fn->ClassIndex = $this->native['Function'];
        $fn->TemplateIndex = OverlayImport($this->asset, 'Default__Function', $this->native['CoreUObject'], '/Script/CoreUObject', 'Function');
        $fn->SerializationBeforeCreateDependencies = [$fn->ClassIndex, $fn->TemplateIndex];
        $fn->ScriptBytecode = $code;
        $fn->ScriptBytecodeSize = array_sum(array_map('RadarExprSize', $code));
        if ($returnType !== null) { $fn->FunctionFlags .= ', FUNC_HasOutParms'; }
        if ($event !== null) {
            $fn->SuperStruct = $fn->SuperIndex = $this->importMethod('UserWidget', $event);
            $fn->FunctionFlags = 'FUNC_Event, FUNC_Protected, FUNC_BlueprintEvent';
            $fn->SerializationBeforeSerializationDependencies = [$fn->SuperStruct];
        }
        $this->asset->Exports[$i - 1] = $fn;
        $this->class->FuncMap[] = [$name, $i];
        $this->class->Children[] = $i;
        $this->class->CreateBeforeSerializationDependencies[] = $i;
    }
    public function finish(): object
    {
        // Every reference in the cooked widget tree needs an EDL dependency.
        foreach ($this->asset->Exports as $export) {
            $this->name($export->ObjectName);
            $visit = function (mixed $value) use (&$visit, $export): void {
                if (is_object($value)) {
                    if (($value->{'$type'} ?? '') === 'UAssetAPI.PropertyTypes.Objects.ObjectPropertyData, UAssetAPI' && ($value->Value ?? 0) > 0) { $export->CreateBeforeSerializationDependencies[] = $value->Value; }
                    foreach ((array)$value as $child) { $visit($child); }
                } elseif (is_array($value)) { foreach ($value as $child) { $visit($child); } }
            };
            $visit($export->Data ?? []);
            $export->CreateBeforeSerializationDependencies = array_values(array_unique($export->CreateBeforeSerializationDependencies ?? []));
        }
        // The game crashes loading this widget with exactly 198 imports, whatever they are (main's radar
        // padded to 198 crashed too; 195-197 and 199-210 load). A spare copy of a package import avoids it.
        if (count($this->asset->Imports) === 198) {
            $package = current(array_filter($this->asset->Imports, fn($i) => $i->ClassName === 'Package'));
            $this->asset->Imports[] = (object)[...(array)$package, '$index' => -199];
        }
        foreach ($this->asset->Imports as $import) { $this->name($import->ObjectName); $this->name($import->ClassPackage); $this->name($import->ClassName); }
        $this->name('Engine');
        foreach (['IntProperty', 'FloatProperty', 'NameProperty', 'TextProperty', 'ObjectProperty', 'EnumProperty', 'StructProperty', 'BoolProperty', 'ArrayProperty'] as $type) { $this->name($type); }
        $walk = function (mixed $value) use (&$walk): void {
            if (is_object($value)) {
                foreach (['Name', 'StructType', 'EnumType', 'SerializedType', 'ArrayType', 'FunctionName', 'VirtualFunctionName', 'PropertyName'] as $k) { if (isset($value->$k) && is_string($value->$k)) { $this->name($value->$k); } }
                if (isset($value->Value) && is_string($value->Value) && preg_match('/(?:NameConst|NamePropertyData|EnumPropertyData)/', $value->{'$type'} ?? '')) { $this->name($value->Value); }
                if (isset($value->Path)) { foreach ($value->Path as $n) { $this->name($n); } }
                foreach ((array)$value as $child) { $walk($child); }
            } elseif (is_array($value)) { foreach ($value as $child) { $walk($child); } }
        };
        $walk($this->asset->Exports);
        $this->asset->NameMap = array_values(array_unique($this->asset->NameMap));
        $this->asset->DependsMap = array_fill(0, count($this->asset->Exports), []);
        return $this->asset;
    }
}

function BuildRadarWidget(object $base, array $index, array $labyrinths = []): array
{
    if (($index['schemaVersion'] ?? null) !== 1 || !isset($index['pointRanks'], $index['puzzleIds'], $index['pentadRelicIndices'], $index['hubAvailabilityZones'])
        || count($index['puzzleIds']) !== count($index['positions']) || count($index['pentadRelicIndices']) !== count($index['positions'])
        || count($index['hubAvailabilityZones']) !== count($index['positions'])) {
        throw new InvalidArgumentException('Expected radar index schema1 with stable ranks, parallel puzzle IDs, Pentad relic indices and Hub availability zones.');
    }
    $hubPuzzleIds = $hubTypeTags = $hubTypeKeys = $hubZones = $pointTypes = [];
    $hubPoints = 0;
    foreach ($index['typeKeys'] as $type => $key) {
        $first = $index['offsets'][$type * $index['width'] * $index['height']];
        $end = $index['offsets'][($type + 1) * $index['width'] * $index['height']];
        foreach (range($first, max($first, $end - 1)) as $point) {
            if ($point >= $end) { break; }
            $pointTypes[$point] = $type;
            $zone = $index['hubAvailabilityZones'][$point];
            if (!is_int($zone) || !in_array($zone, [0, 2, 3, 4, 5, 6], true)) { throw new InvalidArgumentException('Radar Hub availability zones must be zero or2 through6.'); }
            if ($zone > 0) { $hubPoints++; $hubPuzzleIds[$index['puzzleIds'][$point]] = true; $hubTypeTags['SaveStats.Type.' . $key] = true; $hubTypeKeys[$key] = true; $hubZones[$zone] = true; }
        }
    }
    ksort($pointTypes, SORT_NUMERIC);
    if (array_keys($pointTypes) !== array_keys($index['positions'])) { throw new InvalidArgumentException('Radar type offsets must cover every indexed point exactly once.'); }
    // Selection bits follow the full catalog order shared with the map, not this radar's subset order.
    $typeBits = array_map('OverlayTypeBit', $index['typeKeys']);
    $radarMask = array_reduce($typeBits, static fn(int $mask, int $bit): int => $mask | $bit, 0);
    $hubTypeKeys = array_keys($hubTypeKeys);
    $hubMask = array_reduce($hubTypeKeys, static fn(int $mask, string $key): int => $mask | OverlayTypeBit($key), 0);
    $seek5Type = array_search('seek5', $index['typeKeys'], true);
    $pentadParts = $pentadPoints = [];
    foreach ($index['pentadRelicIndices'] as $point => $relic) {
        if (!is_int($relic) || $relic < 0 || $relic > 5) { throw new InvalidArgumentException('Pentad relic indices must be integers from 0 through 5.'); }
        if ($relic > 0 && $index['puzzleIds'][$point] > 0) {
            $pentadParts[$index['puzzleIds'][$point]][] = $relic;
            $pentadPoints[$index['puzzleIds'][$point]][$relic] = $point;
        }
    }
    $pentadIds = [];
    foreach ($pentadParts as $pid => $parts) {
        sort($parts, SORT_NUMERIC);
        if ($parts === [1, 2, 3, 4, 5]) { $pentadIds[] = $pid; }
    }
    sort($pentadIds, SORT_NUMERIC);
    $pentadLocal = ['Known' => [], 'X' => [], 'Y' => [], 'Z' => []];
    foreach ($pentadIds as $pid) {
        foreach (range(1, 5) as $relic) {
            $position = $index['pentadLocalPositions'][$pentadPoints[$pid][$relic]] ?? null;
            $pentadLocal['Known'][] = $position === null ? 0 : 1;
            $position = $position === null ? ['x' => 0, 'y' => 0, 'z' => 0] : RadarPosition($position);
            foreach (['X', 'Y', 'Z'] as $axis) { $pentadLocal[$axis][] = $position[strtolower($axis)]; }
        }
    }
    $b = new RadarWidgetBuilder($base);
    foreach (['CharPuzzleComponent', 'Seek5', 'PuzzleBase', 'Seek5HiddenObject'] as $nativeClass) {
        $b->native[$nativeClass] = OverlayImport($b->asset, $nativeClass, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    $b->native['SceneComponent'] = OverlayImport($b->asset, 'SceneComponent', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    foreach (['SophiaGameplayStatics', 'PuzzleDatabase', 'PuzzleData'] as $nativeClass) { $b->native[$nativeClass] = OverlayImport($b->asset, $nativeClass, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class'); }
    foreach (['GameModeBase', 'KismetStringLibrary', 'Controller'] as $nativeClass) { $b->native[$nativeClass] = OverlayImport($b->asset, $nativeClass, $b->native['Engine'], '/Script/CoreUObject', 'Class'); }
    // Custom map markers are the local player's MapMarker location pings.
    foreach (['SophiaPlayerState', 'SocialSystemComponent'] as $nativeClass) { $b->native[$nativeClass] = OverlayImport($b->asset, $nativeClass, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class'); }
    $b->native['LocationPingInfo'] = OverlayImport($b->asset, 'LocationPingInfo', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    $markerSlots = 8;
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $state = static fn(string $n): object => FilterVariable(1, $n, true);
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $state($n), 'Expression' => $v]);
    $setBool = static fn(string $n, object $v): object => FilterExpr('LetBool', ['VariableExpression' => $state($n), 'AssignmentExpression' => $v]);
    $jump = static fn(string $label): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $label]);
    $unless = static fn(object $condition, string $label): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $label, 'BooleanExpression' => $condition]);
    $localCall = static fn(string $fn, array $args = []): object => FilterCall($b->functions[$fn], $args, 'LocalFinalFunction');
    $visible = static fn(string $name, int $value): object => $b->method($state($name), 'Widget', 'SetVisibility', [FilterExpr('ByteConst', ['Value' => $value])]);
    $bool = static fn(bool $value): object => FilterExpr($value ? 'True' : 'False');
    $b->scalar('Int', 'SSRadarSelectedMask', 0);
    foreach (['SSRadarIncludeSites' => false, 'SSRadarEnabled' => true, 'SSRadarDirty' => true, 'SSRadarScanActive' => false,
        'SSRadarProgressAvailable' => false, 'SSRadarMapProgressDirty' => true] as $n => $v) { $b->scalar('Bool', $n, $v ? 1 : 0); }
    foreach (range(1, 5) as $relic) { $b->scalar('Bool', 'SSRadarPentadFound' . $relic, 0); }
    $b->scalar('Bool', 'SSRadarAvailabilityReady', 0);
    $b->objectField('SSRadarAvailabilitySource', $b->native['PuzzleDatabase']);
    $b->objectField('SSRadarAvailabilityWorld', $b->native['GameModeBase']);
    $b->objectField('SSRadarAvailabilityPlayer', $b->native['SophiaCharacter']);
    foreach (['SSRadarAvailabilityZoneCursor' => 2, 'SSRadarAvailabilityTypeCursor' => 0, 'SSRadarAvailabilityLifespan' => 0, 'SSRadarAvailabilityQueriesLastRefresh' => 0, 'SSRadarAvailabilityQueriesTotal' => 0] as $n => $v) { $b->scalar('Int', $n, $v); }
    // The zone being collected, one Hub-capable type per refresh, and whether any part was unknown.
    $b->arrayField('Object', 'SSRadarAvailabilityWork', []);
    $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native['PuzzleData'];
    $b->scalar('Bool', 'SSRadarAvailabilityWorkUnknown', 0);
    foreach (range(2, 6) as $zone) {
        $b->scalar('Bool', 'SSRadarAvailabilityZoneReady' . $zone, 0);
        $b->arrayField('Object', 'SSRadarAvailableZone' . $zone, []);
        $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native['PuzzleData'];
    }
    $b->objectField('SSRadarPentadActor', $b->native['Seek5']);
    foreach (range(1, 5) as $relic) { $b->objectField('SSRadarPentadObject' . $relic, $b->native['Seek5HiddenObject']); }
    $b->objectField('SSRadarProgressSource', $b->native['GISProgression']);
    $b->objectField('SSRadarProgressPlayer', $b->native['SophiaCharacter']);
    foreach (['SSRadarScanType' => -1, 'SSRadarCellX' => 2147483647, 'SSRadarCellY' => 2147483647, 'SSRadarRow' => 0, 'SSRadarMinRow' => 0, 'SSRadarMaxRow' => -1,
        'SSRadarMinCol' => 0, 'SSRadarMaxCol' => -1, 'SSRadarCursor' => 0, 'SSRadarEnd' => 0, 'SSRadarVisitedLastUpdate' => 0, 'SSRadarVisitedTotal' => 0,
        'SSRadarZoomIndex' => 1, 'SSRadarSizeIndex' => 1, 'SSRadarRevision' => 0, 'SSRadarProgressQueriesTotal' => 0,
        'SSRadarFocusPuzzleId' => 0] as $n => $v) { $b->scalar('Int', $n, $v); }
    // Per-pin cache of the type whose icon is currently on the pin's brush (-1 = unknown).
    for ($pin = 0; $pin < 24; $pin++) { $b->scalar('Int', 'SSRadarPinType' . $pin, -1); }
    foreach (['SSRadarPlayerX', 'SSRadarPlayerY', 'SSRadarPlayerZ', 'SSRadarYaw', 'SSRadarScanX', 'SSRadarScanY', 'SSRadarWait'] as $n) { $b->scalar('Float', $n, 0); }
    $b->scalar('Float', 'SSRadarRadiusCm', 10000);
    $shortcutActions = RadarShortcutActions();
    $inputCallbacks = [];
    foreach ($shortcutActions as $action => [$actionName, $key]) {
        $inputCallbacks[$action] = 'SSRadarInput' . $action;
        $b->structField('SSRadarKey' . $action, 'InputActionKeyMapping', 48, [OverlayProperty('Name', 'ActionName', $actionName),
            OverlayProperty('Bool', 'bShift', 0), OverlayProperty('Bool', 'bCtrl', 0), OverlayProperty('Bool', 'bAlt', 0), OverlayProperty('Bool', 'bCmd', 0),
            FilterStruct('Key', 'Key', [OverlayProperty('Name', 'KeyName', $key)])]);
    }
    // Map-only option kept here so the radar is the single writer of the settings slot.
    $b->scalar('Bool', 'SSRadarHideDone', 0);
    // Rebinding reports the action that gave up its key (-1: none); load/save report their outcome.
    $b->scalar('Int', 'SSRadarRebindSwap', -1);
    $b->scalar('Bool', 'SSRadarSettingsLoaded', 0);
    $b->scalar('Bool', 'SSRadarSettingsSaved', 0);
    $settingsPackage = OverlayImport($b->asset, RADAR_SETTINGS_PACKAGE, 0, '/Script/CoreUObject', 'Package');
    $b->native['RadarSettings'] = OverlayImport($b->asset, RADAR_SETTINGS_CLASS, $settingsPackage, '/Script/Engine', 'BlueprintGeneratedClass');
    $b->native['KismetInputLibrary'] = OverlayImport($b->asset, 'KismetInputLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    foreach (['SSRadarOffsets' => $index['offsets'], 'SSRadarSpawn' => $index['isSpawn'], 'SSRadarRanks' => $index['pointRanks'], 'SSRadarPuzzleIds' => $index['puzzleIds'], 'SSRadarBestIndices' => [], 'SSRadarDisplayIndices' => []] as $n => $v) { $b->arrayField('Int', $n, $v); }
    $b->arrayField('Int', 'SSRadarPentadRelicIndices', $index['pentadRelicIndices']);
    $b->arrayField('Int', 'SSRadarHubAvailabilityZones', $index['hubAvailabilityZones']);
    $b->arrayField('Int', 'SSRadarPentadIds', $pentadIds);
    foreach ($pentadLocal as $axis => $values) { $b->arrayField($axis === 'Known' ? 'Int' : 'Float', 'SSRadarPentadLocal' . $axis, $values); }
    foreach (['x', 'y', 'z'] as $axis) { $b->arrayField('Float', 'SSRadar' . strtoupper($axis), array_column($index['positions'], $axis)); }
    $b->arrayField('Float', 'SSRadarBestDistances', []);
    $b->arrayField('Name', 'SSRadarTypeTags', array_map(static fn(string $key): string => 'SaveStats.Type.' . $key, $index['typeKeys']));
    $b->arrayField('Int', 'SSRadarPointTypes', array_values($pointTypes));
    $b->arrayField('Int', 'SSRadarTypeBits', $typeBits);
    AddRadarLiveSiteFields($b, $index);
    $texture = static function (string $name) use ($b): int {
        $package = OverlayImport($b->asset, '/Game/ASophia/UI/HUD/Markers/Textures/' . $name, 0, '/Script/CoreUObject', 'Package');
        return OverlayImport($b->asset, $name, $package, '/Script/Engine', 'Texture2D');
    };
    $catalog = GetOverlayTypes();
    $b->native['Texture2D'] = OverlayImport($b->asset, 'Texture2D', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->arrayField('Object', 'SSRadarTypeTextures', array_map(static fn(string $key): int => $texture($catalog[$key]['iconAsset']), $index['typeKeys']));
    $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native['Texture2D'];
    $brush = static fn(int $resource): object => FilterStruct('Brush', 'SlateBrush', [FilterVector('ImageSize', 216, 216), OverlayProperty('Object', 'ResourceObject', $resource)]);
    $b->child('Image', 'SSRadarRing', [$brush($texture('SSRadRing'))], 108, 108, 216, 216);
    $fontPkg = OverlayImport($b->asset, '/Game/ASophia/UI/Common/Font/lovato_demi_Font', 0, '/Script/CoreUObject', 'Package');
    $fontObj = OverlayImport($b->asset, 'lovato_demi_Font', $fontPkg, '/Script/Engine', 'Font');
    $font = static fn(int $size): object => FilterStruct('Font', 'SlateFontInfo', [OverlayProperty('Object', 'FontObject', $fontObj), OverlayProperty('Name', 'TypefaceFontName', 'Default'), OverlayProperty('Int', 'Size', $size)]);
    // Labyrinth walls sit above the ring and beneath the heading arrow and pins.
    AddRadarLabyrinthWidgets($b, $font, $labyrinths);
    $b->child('Image', 'SSRadarHeading', [$brush($texture('SSRadHead'))], 108, 108, 20, 20);
    $b->child('TextBlock', 'SSRadarCaption', [OverlayText('Text', 'Puzzle radar'), $font(12), FilterEnum('Justification', 'ETextJustify', 'Center')], 108, 232, 232, 24);
    $b->child('TextBlock', 'SSRadarRange', [OverlayText('Text', '100 m'), $font(10), FilterEnum('Justification', 'ETextJustify', 'Center')], 108, 252, 216, 18);
    for ($pin = 0; $pin < 24; $pin++) {
        $b->child('Image', 'SSRadarPin' . $pin, [FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed'), $brush($texture('SSIcon006'))], 108, 108, 20, 20);
        $b->child('TextBlock', 'SSRadarHeight' . $pin, [OverlayText('Text', ''), $font(11), FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed')], 120, 99, 14, 16);
    }
    // Map markers draw above pins: the game's own marker icon in range, a cyan rim arrow and distance beyond it.
    $linear = static fn(string $name, float $r, float $g, float $bl, float $a): object => FilterStruct($name, 'LinearColor', [(object)['$type' => 'UAssetAPI.PropertyTypes.Structs.LinearColorPropertyData, UAssetAPI', 'Name' => $name,
        'Value' => (object)['$type' => 'UAssetAPI.UnrealTypes.FLinearColor, UAssetAPI', 'R' => $r, 'G' => $g, 'B' => $bl, 'A' => $a]]]);
    for ($marker = 0; $marker < $markerSlots; $marker++) {
        $b->child('Image', 'SSRadarMarker' . $marker, [FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed'), $brush($texture('MapMarker'))], 108, 108, 18, 18);
        $b->child('Image', 'SSRadarMarkerArrow' . $marker, [FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed'), $brush($texture('SSRadHead')), $linear('ColorAndOpacity', 0.86, 0.88, 0.98, 1)], 108, 108, 14, 14);
        $b->child('TextBlock', 'SSRadarMarkerDistance' . $marker, [OverlayText('Text', ''), $font(10), FilterEnum('Justification', 'ETextJustify', 'Center'),
            FilterStruct('ColorAndOpacity', 'SlateColor', [$linear('SpecifiedColor', 0.75, 0.94, 0.97, 1)]), FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed')], 108, 108, 52, 16);
    }
    foreach (['SSRadarSetSelection', 'SSRadarSetView', 'SSRadarApplyView', 'SSRadarChangeZoom', 'SSRadarCycleSize', 'SSRadarToggleEnabled', 'SSRadarIsSolved', 'SSRadarAcknowledgeMapProgress',
        'SSRadarRefreshAvailability', 'SSRadarIsPuzzleAvailable', 'SSRadarRefreshSites', 'SSRadarSitePuzzle', 'SSRadarIsSiteLive', 'SSRadarRefreshPentadFocus', 'SSRadarIsPentadRelicVisible', 'SSRadarMatchesPentadFocus', 'SSRadarHandleShortcut', ...array_values($inputCallbacks),
        'SSRadarLoadSettings', 'SSRadarSaveSettings', 'SSRadarRegisterKeys', 'SSRadarRebind', 'SSRadarResetKeys', 'SSRadarSetHideDone', 'SSRadarApplyIcon', 'SSRadarStartScan', 'SSRadarScan', 'SSRadarRender', 'SSRadarRenderMarkers', 'SSRadarInitMaze', 'SSRadarRefreshMaze', 'SSRadarRenderMaze', 'SSRadarUpdate', 'SSRadarTick', 'OnInitialized', 'Construct', 'Destruct'] as $fn) { $b->reserve($fn); }
    $specialCompletion = AddRadarSpecialCompletion($b, $index['specialCompletions'] ?? []);
    $makeVec = static fn(object $x, object $y): object => $math('MakeVector2D', $x, $y);
    $labelText = static fn(string $n, string $text): object => $b->method($state($n), 'TextBlock', 'SetText', [$b->call('KismetTextLibrary', 'Conv_StringToText', [FilterExpr('StringConst', ['Value' => $text])])]);
    $clear = [$array('Array_Clear', $state('SSRadarDisplayIndices')), $array('Array_Clear', $state('SSRadarBestIndices')), $array('Array_Clear', $state('SSRadarBestDistances')),
        $setBool('SSRadarScanActive', $bool(false)), $setBool('SSRadarDirty', $bool(true)), $visible('SSRadarCanvas', 1)];
    $revise = static fn(): object => $set('SSRadarRevision', $math('Add_IntInt', $state('SSRadarRevision'), $i(1)));
    $b->define('SSRadarAcknowledgeMapProgress', [], [], [$setBool('SSRadarMapProgressDirty', $bool(false))]);
    $fn = $b->functions['SSRadarIsPentadRelicVisible'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $relicVisible = [$unless($math('Greater_IntInt', $state('SSRadarFocusPuzzleId'), $i(0)), 'Done'),
        $unless($math('EqualEqual_IntInt', $p('PuzzleId'), $state('SSRadarFocusPuzzleId')), 'Done')];
    foreach (range(1, 5) as $relic) {
        $relicVisible = [...$relicVisible, $unless($math('EqualEqual_IntInt', $p('RelicIndex'), $i($relic)), 'Relic' . ($relic + 1)),
            FilterExpr('Return', ['ReturnExpression' => $math('Not_PreBool', $state('SSRadarPentadFound' . $relic))]), ['label' => 'Relic' . ($relic + 1)]];
    }
    $b->define('SSRadarIsPentadRelicVisible', ['PuzzleId' => 'Int', 'RelicIndex' => 'Int'], [], [...$relicVisible, ['label' => 'Done']], null, 'Bool');
    $fn = $b->functions['SSRadarMatchesPentadFocus'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    // Pentad rules apply to Pentad points by their own type, whatever else is selected.
    $pentadPoint = $seek5Type === false ? [$jump('Other')] : [$array('Array_Get', $state('SSRadarPointTypes'), $p('Point'), $p('PointType')),
        $unless($math('EqualEqual_IntInt', $p('PointType'), $i($seek5Type)), 'Other')];
    $b->define('SSRadarMatchesPentadFocus', ['Point' => 'Int', 'PuzzleId' => 'Int'], ['RelicIndex' => 'Int', 'PointType' => 'Int'], [
        ...$pentadPoint,
        $array('Array_Get', $state('SSRadarPentadRelicIndices'), $p('Point'), $p('RelicIndex')),
        $unless($math('Greater_IntInt', $state('SSRadarFocusPuzzleId'), $i(0)), 'Overview'),
        FilterExpr('Return', ['ReturnExpression' => $localCall('SSRadarIsPentadRelicVisible', [$p('PuzzleId'), $p('RelicIndex')])]),
        ['label' => 'Overview'], FilterExpr('Return', ['ReturnExpression' => $math('EqualEqual_IntInt', $p('RelicIndex'), $i(0))]),
        ['label' => 'Other'], FilterExpr('Return', ['ReturnExpression' => $bool(true)]),
    ], null, 'Bool');
    $fn = $b->functions['SSRadarIsSolved'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $queryLet = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $p($n), 'Expression' => $v]);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $specialDispatch = $specialCompletion['descriptors'] === 0 ? [] : [
        $unless($math('BooleanAND', $math('GreaterEqual_IntInt', $p('PuzzleId'), $i($specialCompletion['minimumPid'])), $math('LessEqual_IntInt', $p('PuzzleId'), $i($specialCompletion['maximumPid']))), 'Ordinary'),
        $queryLet('Descriptor', $array('Array_Find', $state('SSRadarSpecialIds'), $p('PuzzleId'))),
        $unless($math('GreaterEqual_IntInt', $p('Descriptor'), $i(0)), 'Ordinary'),
        FilterExpr('Return', ['ReturnExpression' => $localCall('SSRadarIsSpecialSolved', [$p('Descriptor'), $p('Source'), $p('Player')])]), ['label' => 'Ordinary']];
    $b->define('SSRadarIsSolved', ['PuzzleId' => 'Int'], ['Source' => 'Object', 'Player' => 'Object', 'PuzzleStatus' => 'Struct', 'Descriptor' => 'Int'], [
        $unless($math('Greater_IntInt', $p('PuzzleId'), $i(0)), 'Done'),
        $queryLet('Source', $state('SSRadarProgressSource')),
        $unless($math('Not_PreBool', $valid($p('Source'))), 'Player'),
        $queryLet('Source', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['GISProgression'], 'TargetExpression' => $b->call('SubsystemBlueprintLibrary', 'GetGameInstanceSubsystem', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['GISProgression']])])])),
        $set('SSRadarProgressSource', $p('Source')),
        ['label' => 'Player'], $queryLet('Player', $state('SSRadarProgressPlayer')),
        $unless($math('Not_PreBool', $valid($p('Player'))), 'Available'),
        $queryLet('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'GetPlayerCharacter', [FilterExpr('Self'), $i(0)])])),
        $set('SSRadarProgressPlayer', $p('Player')),
        ['label' => 'Available'], $setBool('SSRadarProgressAvailable', $math('BooleanAND', $valid($p('Source')), $valid($p('Player')))),
        $unless($state('SSRadarProgressAvailable'), 'Done'),
        ...$specialDispatch,
        $set('SSRadarProgressQueriesTotal', $math('Add_IntInt', $state('SSRadarProgressQueriesTotal'), $i(1))),
        $queryLet('PuzzleStatus', $b->method($p('Source'), 'GISProgression', 'GetPuzzleSolveStatus', [$p('Player'), $p('PuzzleId'), FilterExpr('StringConst', ['Value' => '']), FilterExpr('StringConst', ['Value' => ''])], FilterPointer($fn, 'PuzzleStatus'))),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['PuzzleSolveData'], 'bSolved'), 'StructExpression' => $p('PuzzleStatus')])]),
        ['label' => 'Done'],
    ], null, 'Bool');
    $fn = $b->functions['SSRadarRefreshAvailability'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $availabilityLet = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $p($n), 'Expression' => $v]);
    $availabilityProperty = static fn(object $object, string $owner, string $name): object => RadarContext($object, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $database = static fn(): object => FilterExpr('DynamicCast', ['ClassPtr' => $b->native['PuzzleDatabase'], 'TargetExpression' => $b->call('SophiaGameplayStatics', 'GetPuzzleDatabase', [FilterExpr('Self')])]);
    $worldAnchor = static fn(): object => $b->call('GameplayStatics', 'GetGameMode', [FilterExpr('Self')]);
    $availabilityPlayer = static fn(): object => FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'GetPlayerCharacter', [FilterExpr('Self'), $i(0)])]);
    $availabilityInvalidate = [...$clear, $setBool('SSRadarMapProgressDirty', $bool(true)), $revise()];
    $restartZone = [$set('SSRadarAvailabilityTypeCursor', $i(0)), $array('Array_Clear', $state('SSRadarAvailabilityWork')), $setBool('SSRadarAvailabilityWorkUnknown', $bool(false))];
    $availabilityReset = [$set('SSRadarAvailabilityZoneCursor', $i(2)), ...$restartZone];
    foreach (range(2, 6) as $zone) { $availabilityReset = [...$availabilityReset, $setBool('SSRadarAvailabilityZoneReady' . $zone, $bool(false)), $array('Array_Clear', $state('SSRadarAvailableZone' . $zone))]; }
    // The cache covers every Hub-capable type, whatever is selected, so selecting a type
    // never starts from an empty (fail-visible) cache and briefly shows inactive Hub puzzles.
    $availabilityKeyChanged = $math('BooleanOR',
        $math('BooleanOR', $math('NotEqual_ObjectObject', $p('Player'), $state('SSRadarAvailabilityPlayer')), $math('BooleanOR', $math('NotEqual_ObjectObject', $p('Source'), $state('SSRadarAvailabilitySource')), $math('NotEqual_ObjectObject', $p('World'), $state('SSRadarAvailabilityWorld')))),
        $math('BooleanOR', $math('NotEqual_IntInt', $p('Lifespan'), $state('SSRadarAvailabilityLifespan')), $math('Not_PreBool', $math('EqualEqual_BoolBool', $p('Ready'), $state('SSRadarAvailabilityReady')))));
    $availabilityRefresh = [$set('SSRadarAvailabilityQueriesLastRefresh', $i(0)),
        $availabilityLet('Source', $database()), $availabilityLet('World', $worldAnchor()), $availabilityLet('Player', $availabilityPlayer()), $availabilityLet('Lifespan', $i(0)),
        FilterExpr('LetBool', ['VariableExpression' => $p('Ready'), 'AssignmentExpression' => $bool(false)]),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('Source')]), 'Key'),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('World')]), 'Key'),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('Player')]), 'Key'),
        ...($hubTypeKeys === [] ? [$jump('Key')] : []),
        $unless($availabilityProperty($p('Source'), 'PuzzleDatabase', 'bInitialized'), 'Key'),
        $availabilityLet('Lifespan', $availabilityProperty($p('Source'), 'PuzzleDatabase', 'groupLifespan')),
        $unless($math('Greater_IntInt', $p('Lifespan'), $i(0)), 'Key'),
        FilterExpr('LetBool', ['VariableExpression' => $p('Ready'), 'AssignmentExpression' => $bool(true)]),
        ['label' => 'Key'], $unless($availabilityKeyChanged, 'KeyReady'),
        $set('SSRadarAvailabilitySource', $p('Source')), $set('SSRadarAvailabilityWorld', $p('World')), $set('SSRadarAvailabilityPlayer', $p('Player')),
        $set('SSRadarAvailabilityLifespan', $p('Lifespan')), $setBool('SSRadarAvailabilityReady', $p('Ready')),
        ...$availabilityReset, ...$availabilityInvalidate,
        ['label' => 'KeyReady'], $unless($p('Ready'), 'Done'),
        // One native query per refresh: the next Hub-capable type in the current zone.
        $availabilityLet('ZoneByte', $math('Conv_IntToByte', $state('SSRadarAvailabilityZoneCursor')))];
    foreach ($hubTypeKeys as $hubType => $key) {
        $availabilityRefresh = [...$availabilityRefresh, $unless($math('EqualEqual_IntInt', $state('SSRadarAvailabilityTypeCursor'), $i($hubType)), 'Type' . ($hubType + 1)),
            $availabilityLet('Part', $b->method($p('Source'), 'PuzzleDatabase', 'GetAllActiveSandboxPuzzleDataOfTypeInZone', [FilterExpr('StringConst', ['Value' => $key]), $p('ZoneByte'), $bool(true)], FilterPointer($fn, 'Part'))),
            $jump('Queried'), ['label' => 'Type' . ($hubType + 1)]];
    }
    // A cursor out of range (never expected) restarts the zone without querying.
    $availabilityRefresh = [...$availabilityRefresh, ...$restartZone, $jump('Done'),
        ['label' => 'Queried'],
        $set('SSRadarAvailabilityQueriesLastRefresh', $i(1)), $set('SSRadarAvailabilityQueriesTotal', $math('Add_IntInt', $state('SSRadarAvailabilityQueriesTotal'), $i(1))),
        $unless($math('Not_PreBool', $array('Array_Contains', $p('Part'), FilterExpr('ObjectConst', ['Value' => 0]))), 'PartUnknown'),
        $array('Array_Append', $state('SSRadarAvailabilityWork'), $p('Part')), $jump('PartDone'),
        ['label' => 'PartUnknown'], $setBool('SSRadarAvailabilityWorkUnknown', $bool(true)),
        ['label' => 'PartDone'], $set('SSRadarAvailabilityTypeCursor', $math('Add_IntInt', $state('SSRadarAvailabilityTypeCursor'), $i(1))),
        $unless($math('GreaterEqual_IntInt', $state('SSRadarAvailabilityTypeCursor'), $i(count($hubTypeKeys))), 'Done'),
        // Every type of this zone is in: publish the zone's union and move on.
        $availabilityLet('Objects', $state('SSRadarAvailabilityWork')), FilterExpr('LetBool', ['VariableExpression' => $p('Unknown'), 'AssignmentExpression' => $state('SSRadarAvailabilityWorkUnknown')]), ...$restartZone];
    foreach (range(2, 6) as $zone) {
        $availabilityRefresh = [...$availabilityRefresh, $unless($math('EqualEqual_IntInt', $state('SSRadarAvailabilityZoneCursor'), $i($zone)), 'Zone' . ($zone + 1)),
            $unless($math('Not_PreBool', $p('Unknown')), 'Unknown' . $zone),
            $unless($math('BooleanAND', $state('SSRadarAvailabilityZoneReady' . $zone), $array('Array_Identical', $p('Objects'), $state('SSRadarAvailableZone' . $zone))), 'Changed' . $zone), $jump('Advance' . $zone),
            ['label' => 'Changed' . $zone], $set('SSRadarAvailableZone' . $zone, $p('Objects')), $setBool('SSRadarAvailabilityZoneReady' . $zone, $bool(true)), ...$availabilityInvalidate, $jump('Advance' . $zone),
            ['label' => 'Unknown' . $zone], $unless($state('SSRadarAvailabilityZoneReady' . $zone), 'Advance' . $zone),
            $setBool('SSRadarAvailabilityZoneReady' . $zone, $bool(false)), $array('Array_Clear', $state('SSRadarAvailableZone' . $zone)), ...$availabilityInvalidate,
            ['label' => 'Advance' . $zone], $set('SSRadarAvailabilityZoneCursor', $i($zone === 6 ? 2 : $zone + 1)), $jump('Done'), ['label' => 'Zone' . ($zone + 1)]];
    }
    $availabilityRefresh = [...$availabilityRefresh, $set('SSRadarAvailabilityZoneCursor', $i(2))];
    $b->define('SSRadarRefreshAvailability', [], ['Source' => 'Object', 'World' => 'Object', 'Player' => 'Object', 'Lifespan' => 'Int', 'Ready' => 'Bool', 'Unknown' => 'Bool', 'ZoneByte' => 'Byte', 'Objects' => 'Array', 'Part' => 'Array'], [...$availabilityRefresh, ['label' => 'Done']]);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        if ($property->Name === 'Source') { $property->PropertyClass = $b->native['PuzzleDatabase']; }
        if ($property->Name === 'World') { $property->PropertyClass = $b->native['GameModeBase']; }
        if ($property->Name === 'Player') { $property->PropertyClass = $b->native['SophiaCharacter']; }
        if (in_array($property->Name, ['Objects', 'Part'], true)) { $property->Inner = $b->field('Object', $property->Name); $property->Inner->PropertyClass = $b->native['PuzzleData']; }
    }
    $fn = $b->functions['SSRadarIsPuzzleAvailable'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $availabilityLet = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $p($n), 'Expression' => $v]);
    $availabilityQuery = [$unless($math('Greater_IntInt', $p('PuzzleId'), $i(0)), 'Visible'),
        $unless($math('BooleanAND', $math('GreaterEqual_IntInt', $p('AvailabilityZone'), $i(2)), $math('LessEqual_IntInt', $p('AvailabilityZone'), $i(6))), 'Visible'),
        $unless($state('SSRadarAvailabilityReady'), 'Visible'),
        $availabilityLet('Source', $database()), $availabilityLet('World', $worldAnchor()), $availabilityLet('Player', $availabilityPlayer()),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('Source')]), 'Visible'),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('World')]), 'Visible'),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('Player')]), 'Visible'),
        $unless($math('EqualEqual_ObjectObject', $p('Source'), $state('SSRadarAvailabilitySource')), 'Visible'),
        $unless($math('EqualEqual_ObjectObject', $p('World'), $state('SSRadarAvailabilityWorld')), 'Visible'),
        $unless($math('EqualEqual_ObjectObject', $p('Player'), $state('SSRadarAvailabilityPlayer')), 'Visible'),
        $unless($availabilityProperty($p('Source'), 'PuzzleDatabase', 'bInitialized'), 'Visible'),
        $unless($math('EqualEqual_IntInt', $availabilityProperty($p('Source'), 'PuzzleDatabase', 'groupLifespan'), $state('SSRadarAvailabilityLifespan')), 'Visible'),
        $availabilityLet('PuzzleData', $b->call('SophiaGameplayStatics', 'GetPuzzleData', [FilterExpr('Self'), $p('PuzzleId')])),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$p('PuzzleData')]), 'Visible')];
    foreach (range(2, 6) as $zone) {
        $availabilityQuery = [...$availabilityQuery, $unless($math('EqualEqual_IntInt', $p('AvailabilityZone'), $i($zone)), 'Zone' . ($zone + 1)),
            $unless($state('SSRadarAvailabilityZoneReady' . $zone), 'Visible'),
            FilterExpr('Return', ['ReturnExpression' => $array('Array_Contains', $state('SSRadarAvailableZone' . $zone), $p('PuzzleData'))]), ['label' => 'Zone' . ($zone + 1)]];
    }
    $b->define('SSRadarIsPuzzleAvailable', ['PuzzleId' => 'Int', 'AvailabilityZone' => 'Int'], ['Source' => 'Object', 'World' => 'Object', 'Player' => 'Object', 'PuzzleData' => 'Object'], [...$availabilityQuery, ['label' => 'Visible'], FilterExpr('Return', ['ReturnExpression' => $bool(true)])], null, 'Bool');
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        $type = ['Source' => 'PuzzleDatabase', 'World' => 'GameModeBase', 'Player' => 'SophiaCharacter', 'PuzzleData' => 'PuzzleData'][$property->Name] ?? null;
        if ($type !== null) { $property->PropertyClass = $b->native[$type]; }
    }
    $liveSites = AddRadarLiveSiteFunctions($b, $index, $availabilityInvalidate);
    $fn = $b->functions['SSRadarRefreshPentadFocus'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $focusLet = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $p($n), 'Expression' => $v]);
    $nativeProperty = static fn(object $object, string $owner, string $name): object => RadarContext($object, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $focusLocals = ['Player' => 'Object', 'PuzzleComponent' => 'Object', 'Pentad' => 'Object', 'PentadState' => 'Byte', 'PuzzleId' => 'Int', 'NewFocus' => 'Int',
        'NewActor' => 'Object', 'CatalogSlot' => 'Int', 'CatalogPoint' => 'Int', 'Known' => 'Int', 'ComponentIndex' => 'Int', 'Matches' => 'Int',
        'Component' => 'Object', 'ComponentPosition' => 'Struct', 'ExpectedX' => 'Float', 'ExpectedY' => 'Float', 'ExpectedZ' => 'Float'];
    $foundReset = $foundCommit = [];
    $focusChanged = $math('BooleanOR', $math('NotEqual_IntInt', $p('NewFocus'), $state('SSRadarFocusPuzzleId')), $math('NotEqual_ObjectObject', $p('NewActor'), $state('SSRadarPentadActor')));
    foreach (range(1, 5) as $relic) {
        $focusLocals['NewFound' . $relic] = 'Bool';
        $focusLocals['NewObject' . $relic] = 'Object';
        $foundReset[] = FilterExpr('LetBool', ['VariableExpression' => $p('NewFound' . $relic), 'AssignmentExpression' => $bool(false)]);
        $foundReset[] = $focusLet('NewObject' . $relic, FilterExpr('ObjectConst', ['Value' => 0]));
        $foundCommit[] = $setBool('SSRadarPentadFound' . $relic, $p('NewFound' . $relic));
        $foundCommit[] = $set('SSRadarPentadObject' . $relic, $p('NewObject' . $relic));
        $focusChanged = $math('BooleanOR', $focusChanged, $math('Not_PreBool', $math('EqualEqual_BoolBool', $p('NewFound' . $relic), $state('SSRadarPentadFound' . $relic))));
        $focusChanged = $math('BooleanOR', $focusChanged, $math('NotEqual_ObjectObject', $p('NewObject' . $relic), $state('SSRadarPentadObject' . $relic)));
    }
    $nativeObjects = static fn(): object => $nativeProperty($p('Pentad'), 'Seek5', 'HiddenObjects');
    $bindRelics = [$unless($math('EqualEqual_IntInt', $array('Array_Length', $nativeObjects()), $i(5)), 'MappingFailed'),
        $unless($math('BooleanAND', $math('EqualEqual_ObjectObject', $p('Pentad'), $state('SSRadarPentadActor')), $math('EqualEqual_IntInt', $p('NewFocus'), $state('SSRadarFocusPuzzleId'))), 'BindRelics')];
    foreach (range(1, 5) as $relic) {
        $bindRelics[] = $unless($valid($state('SSRadarPentadObject' . $relic)), 'BindRelics');
        $bindRelics[] = $unless($array('Array_Contains', $nativeObjects(), $state('SSRadarPentadObject' . $relic)), 'BindRelics');
    }
    foreach (range(1, 5) as $relic) { $bindRelics[] = $focusLet('NewObject' . $relic, $state('SSRadarPentadObject' . $relic)); }
    $bindRelics = [...$bindRelics, $jump('ReadFound'), ['label' => 'BindRelics']];
    foreach (range(1, 5) as $relic) {
        $bindRelics = [...$bindRelics,
            $focusLet('CatalogPoint', $math('Add_IntInt', $math('Multiply_IntInt', $p('CatalogSlot'), $i(5)), $i($relic - 1))),
            $array('Array_Get', $state('SSRadarPentadLocalKnown'), $p('CatalogPoint'), $p('Known')),
            $unless($math('Greater_IntInt', $p('Known'), $i(0)), 'MappingFailed')];
        foreach (['X', 'Y', 'Z'] as $axis) { $bindRelics[] = $array('Array_Get', $state('SSRadarPentadLocal' . $axis), $p('CatalogPoint'), $p('Expected' . $axis)); }
        $bindRelics = [...$bindRelics, $focusLet('Matches', $i(0)), $focusLet('ComponentIndex', $i(0)), ['label' => 'MatchLoop' . $relic],
            $unless($math('Less_IntInt', $p('ComponentIndex'), $i(5)), 'MatchDone' . $relic),
            $array('Array_Get', $nativeObjects(), $p('ComponentIndex'), $p('Component')),
            $focusLet('Component', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['Seek5HiddenObject'], 'TargetExpression' => $p('Component')])),
            $unless($valid($p('Component')), 'MappingFailed'),
            $focusLet('ComponentPosition', $nativeProperty($p('Component'), 'SceneComponent', 'RelativeLocation')),
            $unless($math('LessEqual_FloatFloat', $math('Vector_Distance', $p('ComponentPosition'), $math('MakeVector', $p('ExpectedX'), $p('ExpectedY'), $p('ExpectedZ'))), $f(0.05)), 'MatchNext' . $relic),
            $focusLet('Matches', $math('Add_IntInt', $p('Matches'), $i(1))), $focusLet('NewObject' . $relic, $p('Component')),
            ['label' => 'MatchNext' . $relic], $focusLet('ComponentIndex', $math('Add_IntInt', $p('ComponentIndex'), $i(1))), $jump('MatchLoop' . $relic),
            ['label' => 'MatchDone' . $relic], $unless($math('EqualEqual_IntInt', $p('Matches'), $i(1)), 'MappingFailed')];
        foreach (range(1, $relic) as $previous) {
            if ($previous < $relic) { $bindRelics[] = $unless($math('NotEqual_ObjectObject', $p('NewObject' . $previous), $p('NewObject' . $relic)), 'MappingFailed'); }
        }
    }
    $bindRelics = [...$bindRelics, $jump('ReadFound'), ['label' => 'MappingFailed']];
    foreach (range(1, 5) as $relic) { $bindRelics[] = $focusLet('NewObject' . $relic, FilterExpr('ObjectConst', ['Value' => 0])); }
    $bindRelics[] = ['label' => 'ReadFound'];
    foreach (range(1, 5) as $relic) {
        $bindRelics = [...$bindRelics, $unless($valid($p('NewObject' . $relic)), 'FoundNext' . $relic),
            FilterExpr('LetBool', ['VariableExpression' => $p('NewFound' . $relic), 'AssignmentExpression' => $nativeProperty($p('NewObject' . $relic), 'Seek5HiddenObject', 'isFound')]), ['label' => 'FoundNext' . $relic]];
    }
    // Read the local character's native worker list through its reflected getter.
    // Unknown/incomplete catalog entries and missing authority keep the overview.
    $b->define('SSRadarRefreshPentadFocus', [], $focusLocals, [
        $focusLet('NewFocus', $i(0)), $focusLet('NewActor', FilterExpr('ObjectConst', ['Value' => 0])), ...$foundReset,
        ...($seek5Type === false ? [$jump('Commit')] : [$unless($math('NotEqual_IntInt', $math('And_IntInt', $state('SSRadarSelectedMask'), $i(OverlayTypeBit('seek5'))), $i(0)), 'Commit')]),
        $focusLet('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'GetPlayerCharacter', [FilterExpr('Self'), $i(0)])])),
        $unless($valid($p('Player')), 'Commit'),
        $focusLet('PuzzleComponent', $nativeProperty($p('Player'), 'SophiaCharacter', 'CharPuzzleComponent')),
        $unless($valid($p('PuzzleComponent')), 'Commit'),
        $focusLet('Pentad', $b->method($p('PuzzleComponent'), 'CharPuzzleComponent', 'GetCurrentSeek5', [], FilterPointer($fn, 'Pentad'))),
        $unless($valid($p('Pentad')), 'Commit'),
        $focusLet('PentadState', $b->method($p('Pentad'), 'Seek5', 'GetState', [], FilterPointer($fn, 'PentadState'))),
        $unless($math('EqualEqual_ByteByte', $p('PentadState'), FilterExpr('ByteConst', ['Value' => 1])), 'Commit'),
        $focusLet('PuzzleId', $nativeProperty($p('Pentad'), 'PuzzleBase', 'KrakenId')),
        $focusLet('CatalogSlot', $array('Array_Find', $state('SSRadarPentadIds'), $p('PuzzleId'))),
        $unless($math('GreaterEqual_IntInt', $p('CatalogSlot'), $i(0)), 'Commit'),
        $unless($math('Not_PreBool', $localCall('SSRadarIsSolved', [$p('PuzzleId')])), 'Commit'),
        $unless($state('SSRadarProgressAvailable'), 'Commit'),
        $focusLet('NewFocus', $p('PuzzleId')), $focusLet('NewActor', $p('Pentad')), ...$bindRelics,
        ['label' => 'Commit'], $unless($focusChanged, 'Done'),
        $set('SSRadarFocusPuzzleId', $p('NewFocus')), $set('SSRadarPentadActor', $p('NewActor')), ...$foundCommit,
        $setBool('SSRadarMapProgressDirty', $bool(true)), ...$clear, $revise(), ['label' => 'Done'],
    ]);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        $type = ['Player' => 'SophiaCharacter', 'PuzzleComponent' => 'CharPuzzleComponent', 'Pentad' => 'Seek5', 'NewActor' => 'Seek5', 'Component' => 'Seek5HiddenObject'][$property->Name] ?? null;
        if (str_starts_with($property->Name, 'NewObject')) { $type = 'Seek5HiddenObject'; }
        if ($type !== null) { $property->PropertyClass = $b->native[$type]; }
    }
    $fn = $b->functions['SSRadarSetSelection'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $b->define('SSRadarSetSelection', ['SelectedMask' => 'Int', 'IncludeSites' => 'Bool', 'Enabled' => 'Bool'], [], [
        $unless($math('BooleanAND', $math('EqualEqual_IntInt', $state('SSRadarSelectedMask'), $p('SelectedMask')), $math('BooleanAND', $math('EqualEqual_BoolBool', $state('SSRadarIncludeSites'), $p('IncludeSites')), $math('EqualEqual_BoolBool', $state('SSRadarEnabled'), $p('Enabled')))), 'Changed'),
        FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Changed'], $set('SSRadarSelectedMask', $p('SelectedMask')), $setBool('SSRadarIncludeSites', $p('IncludeSites')), $setBool('SSRadarEnabled', $p('Enabled')),
        ...$clear, $revise(), ...array_map(static fn(int $pin): object => $set('SSRadarPinType' . $pin, $i(-1)), range(0, 23)), $localCall('SSRadarApplyIcon'),
        $localCall('SSRadarSaveSettings'),
    ]);
    $fn = $b->functions['SSRadarSetView'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $letView = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $p($n), 'Expression' => $v]);
    $b->define('SSRadarSetView', ['ZoomIndex' => 'Int', 'SizeIndex' => 'Int'], ['Zoom' => 'Int', 'Size' => 'Int'], [
        $letView('Zoom', $math('Max', $i(0), $math('Min', $i(3), $p('ZoomIndex')))), $letView('Size', $math('Max', $i(0), $math('Min', $i(2), $p('SizeIndex')))),
        $unless($math('BooleanOR', $math('NotEqual_IntInt', $p('Zoom'), $state('SSRadarZoomIndex')), $math('NotEqual_IntInt', $p('Size'), $state('SSRadarSizeIndex'))), 'Done'),
        $unless($math('NotEqual_IntInt', $p('Zoom'), $state('SSRadarZoomIndex')), 'Size'), ...unserialize(serialize($clear)),
        ['label' => 'Size'], $set('SSRadarZoomIndex', $p('Zoom')), $set('SSRadarSizeIndex', $p('Size')), $revise(), $localCall('SSRadarApplyView'), $localCall('SSRadarSaveSettings'), ['label' => 'Done'],
    ]);
    $view = [$b->method(FilterExpr('Self'), 'Widget', 'SetRenderTransformPivot', [$makeVec($f(0), $f(1))])];
    foreach ([5000, 10000, 20000, 40000] as $zoom => $radius) {
        $view = [...$view, $unless($math('EqualEqual_IntInt', $state('SSRadarZoomIndex'), $i($zoom)), 'Zoom' . ($zoom + 1)),
            $set('SSRadarRadiusCm', $f($radius)), $labelText('SSRadarRange', ($radius / 100) . ' m'), $jump('Scale'), ['label' => 'Zoom' . ($zoom + 1)]];
    }
    $view[] = ['label' => 'Scale'];
    foreach ([1, 1.25, 1.5] as $size => $scale) {
        $view = [...$view, $unless($math('EqualEqual_IntInt', $state('SSRadarSizeIndex'), $i($size)), 'Size' . ($size + 1)),
            $b->method(FilterExpr('Self'), 'Widget', 'SetRenderScale', [$makeVec($f($scale), $f($scale))]), $jump('Done'), ['label' => 'Size' . ($size + 1)]];
    }
    $view[] = ['label' => 'Done'];
    $b->define('SSRadarApplyView', [], [], $view);
    $fn = $b->functions['SSRadarChangeZoom'];
    $b->define('SSRadarChangeZoom', ['Delta' => 'Int'], [], [$localCall('SSRadarSetView', [$math('Add_IntInt', $state('SSRadarZoomIndex'), FilterVariable($fn, 'Delta')), $state('SSRadarSizeIndex')])]);
    $b->define('SSRadarCycleSize', [], [], [$unless($math('Less_IntInt', $state('SSRadarSizeIndex'), $i(2)), 'Wrap'),
        $localCall('SSRadarSetView', [$state('SSRadarZoomIndex'), $math('Add_IntInt', $state('SSRadarSizeIndex'), $i(1))]), $jump('Done'),
        ['label' => 'Wrap'], $localCall('SSRadarSetView', [$state('SSRadarZoomIndex'), $i(0)]), ['label' => 'Done']]);
    $b->define('SSRadarToggleEnabled', [], [], [$localCall('SSRadarSetSelection', [$state('SSRadarSelectedMask'), $state('SSRadarIncludeSites'), $math('Not_PreBool', $state('SSRadarEnabled'))])]);
    AddRadarSettingsFunctions($b, $shortcutActions);
    $fn = $b->functions['SSRadarHandleShortcut'];
    $player = FilterVariable($fn, 'Player');
    $inputLocal = static fn(string $name): object => FilterVariable($fn, $name);
    $inputLet = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $inputLocal($name), 'Expression' => $value]);
    $shortcut = [$inputLet('Controller', $b->call('GameplayStatics', 'GetPlayerController', [FilterExpr('Self'), $i(0)])),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$inputLocal('Controller')]), 'Done'),
        $inputLet('OwnerPlayer', $b->method(FilterExpr('Self'), 'Widget', 'GetOwningPlayer', [], FilterPointer($fn, 'OwnerPlayer'))),
        $unless($math('EqualEqual_ObjectObject', $inputLocal('OwnerPlayer'), $inputLocal('Controller')), 'Done'),
        $inputLet('OwnerWidget', $b->call('KismetSystemLibrary', 'GetOuterObject', [$b->call('KismetSystemLibrary', 'GetOuterObject', [FilterExpr('Self')])])),
        $inputLet('CurrentHud', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaHUD'], 'TargetExpression' => $b->method($inputLocal('Controller'), 'PlayerController', 'GetHUD', [], FilterPointer($fn, 'CurrentHud'))])),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$inputLocal('CurrentHud')]), 'Viewport'),
        $inputLet('ExpectedOwner', RadarContext($inputLocal('CurrentHud'), FilterVariable($b->native['SophiaHUD'], 'GameHUDBaseWidget', true), FilterPointer($b->native['SophiaHUD'], 'GameHUDBaseWidget'))),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$inputLocal('ExpectedOwner')]), 'Viewport'),
        $unless($math('EqualEqual_ObjectObject', $inputLocal('OwnerWidget'), $inputLocal('ExpectedOwner')), 'Done'), $jump('Player'),
        ['label' => 'Viewport'],
        $unless($b->method(FilterExpr('Self'), 'UserWidget', 'IsInViewport', [], FilterPointer($fn, 'MenuResult')), 'Nested'), $jump('Player'),
        ['label' => 'Nested'], $inputLet('OwnerWidget', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['UserWidget'], 'TargetExpression' => $inputLocal('OwnerWidget')])),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$inputLocal('OwnerWidget')]), 'Done'),
        $unless($b->method($inputLocal('OwnerWidget'), 'UserWidget', 'IsInViewport', [], FilterPointer($fn, 'MenuResult')), 'Done'),
        ['label' => 'Player'], $inputLet('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'GetPlayerCharacter', [FilterExpr('Self'), $i(0)])])),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$player]), 'Done')];
    foreach (['IsMapMenuVisible', 'IsPauseMenuVisible', 'IsInPuzzleMode', 'AreOtherInputWidgetsVisible'] as $predicate) {
        $args = $predicate === 'AreOtherInputWidgetsVisible' ? [FilterExpr('ObjectConst', ['Value' => 0])] : [];
        $shortcut[] = $unless($math('Not_PreBool', $b->method($player, 'SophiaCharacter', $predicate, $args, FilterPointer($fn, 'MenuResult'))), 'Done');
    }
    foreach ([['SSRadarToggleEnabled', []], ['SSRadarChangeZoom', [$i(-1)]], ['SSRadarChangeZoom', [$i(1)]], ['SSRadarCycleSize', []]] as $action => [$n, $args]) {
        $shortcut[] = $unless($math('EqualEqual_IntInt', FilterVariable($fn, 'Action'), $i($action)), 'Action' . ($action + 1));
        $shortcut[] = $localCall($n, $args);
        $shortcut[] = $jump('Done'); $shortcut[] = ['label' => 'Action' . ($action + 1)];
    }
    $shortcut[] = ['label' => 'Done'];
    $b->define('SSRadarHandleShortcut', ['Action' => 'Int'], ['Player' => 'Object', 'MenuResult' => 'Bool', 'Controller' => 'Object', 'OwnerPlayer' => 'Object', 'OwnerWidget' => 'Object', 'CurrentHud' => 'Object', 'ExpectedOwner' => 'Object'], $shortcut);
    foreach (array_values($inputCallbacks) as $action => $callback) { $b->define($callback, [], [], [$localCall('SSRadarHandleShortcut', [$i($action)])]); }
    // Caption only: pin icons follow each point's own type in SSRadarRender.
    $fn = $b->functions['SSRadarApplyIcon'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $e]);
    $parts = [$let('Count', $i(0)), $let('Last', $i(-1)), $let('Type', $i(0)),
        ['label' => 'CountLoop'], $unless($math('Less_IntInt', $l('Type'), $array('Array_Length', $state('SSRadarTypeBits'))), 'Counted'),
        $array('Array_Get', $state('SSRadarTypeBits'), $l('Type'), $l('Bit')),
        $unless($math('NotEqual_IntInt', $math('And_IntInt', $state('SSRadarSelectedMask'), $l('Bit')), $i(0)), 'NextType'),
        $let('Count', $math('Add_IntInt', $l('Count'), $i(1))), $let('Last', $l('Type')),
        ['label' => 'NextType'], $let('Type', $math('Add_IntInt', $l('Type'), $i(1))), $jump('CountLoop'),
        ['label' => 'Counted'], $unless($math('EqualEqual_IntInt', $l('Count'), $i(0)), 'One'),
        $labelText('SSRadarCaption', 'Puzzle radar'), $jump('Done'),
        ['label' => 'One'], $unless($math('EqualEqual_IntInt', $l('Count'), $i(1)), 'Many')];
    foreach ($index['typeKeys'] as $typeIndex => $key) {
        $parts = [...$parts, $unless($math('EqualEqual_IntInt', $l('Last'), $i($typeIndex)), 'Type' . ($typeIndex + 1)),
            $labelText('SSRadarCaption', $catalog[$key]['name']), $jump('Done'), ['label' => 'Type' . ($typeIndex + 1)]];
    }
    $parts = [...$parts, $jump('Done'),
        ['label' => 'Many'], $b->method($state('SSRadarCaption'), 'TextBlock', 'SetText', [$b->call('KismetTextLibrary', 'Conv_StringToText', [
            $b->call('KismetStringLibrary', 'Concat_StrStr', [$b->call('KismetStringLibrary', 'Conv_IntToString', [$l('Count')]), FilterExpr('StringConst', ['Value' => ' puzzle types'])])])]),
        ['label' => 'Done']];
    $b->define('SSRadarApplyIcon', [], ['Count' => 'Int', 'Last' => 'Int', 'Type' => 'Int', 'Bit' => 'Int'], $parts);
    $floorCell = static fn(object $position, int $origin): object => $math('Subtract_IntInt', $math('FFloor', $math('Divide_FloatFloat', $position, $f(20000))), $i($origin));
    $startParts = [$array('Array_Clear', $state('SSRadarBestIndices')), $array('Array_Clear', $state('SSRadarBestDistances')),
        $set('SSRadarScanX', $state('SSRadarPlayerX')), $set('SSRadarScanY', $state('SSRadarPlayerY')),
        $set('SSRadarMinCol', $math('Max', $floorCell($math('Subtract_FloatFloat', $state('SSRadarPlayerX'), $state('SSRadarRadiusCm')), $index['originCellX']), $i(0))),
        $set('SSRadarMaxCol', $math('Min', $floorCell($math('Add_FloatFloat', $state('SSRadarPlayerX'), $state('SSRadarRadiusCm')), $index['originCellX']), $i($index['width'] - 1))),
        $set('SSRadarMinRow', $math('Max', $floorCell($math('Subtract_FloatFloat', $state('SSRadarPlayerY'), $state('SSRadarRadiusCm')), $index['originCellY']), $i(0))),
        $set('SSRadarMaxRow', $math('Min', $floorCell($math('Add_FloatFloat', $state('SSRadarPlayerY'), $state('SSRadarRadiusCm')), $index['originCellY']), $i($index['height'] - 1))),
        $setBool('SSRadarDirty', $bool(false)), $setBool('SSRadarScanActive', $bool(true)),
        // No row is loaded yet: SSRadarScan advances to the first selected type, in type order.
        $set('SSRadarRow', $math('Add_IntInt', $state('SSRadarMaxRow'), $i(1))), $set('SSRadarCursor', $i(0)), $set('SSRadarEnd', $i(0)), $set('SSRadarScanType', $i(-1)),
        $unless($math('BooleanAND', $math('LessEqual_IntInt', $state('SSRadarMinCol'), $state('SSRadarMaxCol')), $math('LessEqual_IntInt', $state('SSRadarMinRow'), $state('SSRadarMaxRow'))), 'Empty'), $jump('Done'),
        // Empty bounds: no type is visited and the next SSRadarScan commits an empty result.
        ['label' => 'Empty'], $set('SSRadarScanType', $i(count($index['typeKeys']))), ['label' => 'Done']];
    $b->define('SSRadarStartScan', [], [], $startParts);
    $fn = $b->functions['SSRadarScan'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $e]);
    $scan = [$set('SSRadarVisitedLastUpdate', $i(0)), ['label' => 'Loop'],
        $unless($math('Less_IntInt', $state('SSRadarVisitedLastUpdate'), $i(128)), 'Done'),
        $unless($math('LessEqual_IntInt', $state('SSRadarRow'), $state('SSRadarMaxRow')), 'NextType'),
        $unless($math('Less_IntInt', $state('SSRadarCursor'), $state('SSRadarEnd')), 'NextRow'),
        $let('Point', $state('SSRadarCursor')), $set('SSRadarCursor', $math('Add_IntInt', $state('SSRadarCursor'), $i(1))),
        $set('SSRadarVisitedLastUpdate', $math('Add_IntInt', $state('SSRadarVisitedLastUpdate'), $i(1))),
        $set('SSRadarVisitedTotal', $math('Add_IntInt', $state('SSRadarVisitedTotal'), $i(1))),
        $array('Array_Get', $state('SSRadarSpawn'), $l('Point'), $l('Spawn')),
        $unless($math('BooleanOR', $state('SSRadarIncludeSites'), $math('EqualEqual_IntInt', $l('Spawn'), $i(0))), 'Loop'),
        $array('Array_Get', $state('SSRadarPuzzleIds'), $l('Point'), $l('PuzzleId')),
        // A random-spot site counts only while a live puzzle of this point's type stands on it.
        $unless($math('NotEqual_IntInt', $l('Spawn'), $i(0)), 'Fixed'),
        $let('PuzzleId', $localCall('SSRadarSitePuzzle', [$l('Point')])),
        $unless($math('Greater_IntInt', $l('PuzzleId'), $i(0)), 'Loop'),
        ['label' => 'Fixed'],
        $unless($localCall('SSRadarMatchesPentadFocus', [$l('Point'), $l('PuzzleId')]), 'Loop'),
        $array('Array_Get', $state('SSRadarHubAvailabilityZones'), $l('Point'), $l('AvailabilityZone')),
        $unless($localCall('SSRadarIsPuzzleAvailable', [$l('PuzzleId'), $l('AvailabilityZone')]), 'Loop'),
        $unless($math('Not_PreBool', $localCall('SSRadarIsSolved', [$l('PuzzleId')])), 'Loop'),
        $array('Array_Get', $state('SSRadarX'), $l('Point'), $l('X')), $array('Array_Get', $state('SSRadarY'), $l('Point'), $l('Y')),
        $let('DX', $math('Subtract_FloatFloat', $l('X'), $state('SSRadarScanX'))), $let('DY', $math('Subtract_FloatFloat', $l('Y'), $state('SSRadarScanY'))),
        $let('Distance', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $l('DX')), $math('Multiply_FloatFloat', $l('DY'), $l('DY')))),
        $unless($math('LessEqual_FloatFloat', $l('Distance'), $math('Multiply_FloatFloat', $state('SSRadarRadiusCm'), $state('SSRadarRadiusCm'))), 'Loop'),
        $array('Array_Get', $state('SSRadarRanks'), $l('Point'), $l('Rank')), $let('Insert', $i(0)),
        ['label' => 'Find'], $unless($math('Less_IntInt', $l('Insert'), $array('Array_Length', $state('SSRadarBestIndices'))), 'Insert'),
        $array('Array_Get', $state('SSRadarBestDistances'), $l('Insert'), $l('ExistingDistance')),
        $unless($math('Less_FloatFloat', $l('ExistingDistance'), $l('Distance')), 'CheckTie'), $jump('Advance'),
        ['label' => 'CheckTie'], $unless($math('EqualEqual_FloatFloat', $l('ExistingDistance'), $l('Distance')), 'Insert'),
        $array('Array_Get', $state('SSRadarBestIndices'), $l('Insert'), $l('ExistingPoint')), $array('Array_Get', $state('SSRadarRanks'), $l('ExistingPoint'), $l('ExistingRank')),
        // A possible site expanded into several selected types is one place: keep its first entry only.
        $unless($math('NotEqual_IntInt', $l('ExistingRank'), $l('Rank')), 'Loop'),
        $unless($math('Less_IntInt', $l('ExistingRank'), $l('Rank')), 'Insert'),
        ['label' => 'Advance'], $let('Insert', $math('Add_IntInt', $l('Insert'), $i(1))), $jump('Find'),
        ['label' => 'Insert'], $unless($math('Less_IntInt', $l('Insert'), $i(24)), 'Loop'),
        $array('Array_Insert', $state('SSRadarBestIndices'), $l('Point'), $l('Insert')), $array('Array_Insert', $state('SSRadarBestDistances'), $l('Distance'), $l('Insert')),
        $unless($math('Greater_IntInt', $array('Array_Length', $state('SSRadarBestIndices')), $i(24)), 'Loop'),
        $array('Array_Remove', $state('SSRadarBestIndices'), $i(24)), $array('Array_Remove', $state('SSRadarBestDistances'), $i(24)), $jump('Loop'),
        ['label' => 'NextRow'], $set('SSRadarRow', $math('Add_IntInt', $state('SSRadarRow'), $i(1))),
        $unless($math('LessEqual_IntInt', $state('SSRadarRow'), $state('SSRadarMaxRow')), 'NextType'), $jump('LoadRow'),
        // The current type is exhausted: continue with the next selected type over the same bounds.
        ['label' => 'NextType'], $set('SSRadarScanType', $math('Add_IntInt', $state('SSRadarScanType'), $i(1))),
        $unless($math('Less_IntInt', $state('SSRadarScanType'), $i(count($index['typeKeys']))), 'Commit'),
        $array('Array_Get', $state('SSRadarTypeBits'), $state('SSRadarScanType'), $l('Bit')),
        $unless($math('NotEqual_IntInt', $math('And_IntInt', $state('SSRadarSelectedMask'), $l('Bit')), $i(0)), 'NextType'),
        $set('SSRadarRow', $state('SSRadarMinRow')),
        ['label' => 'LoadRow'], $let('Cell', $math('Add_IntInt', $math('Multiply_IntInt', $state('SSRadarScanType'), $i($index['width'] * $index['height'])), $math('Add_IntInt', $math('Multiply_IntInt', $state('SSRadarRow'), $i($index['width'])), $state('SSRadarMinCol')))),
        $array('Array_Get', $state('SSRadarOffsets'), $l('Cell'), $l('Offset')), $set('SSRadarCursor', $l('Offset')),
        $array('Array_Get', $state('SSRadarOffsets'), $math('Add_IntInt', $l('Cell'), $math('Add_IntInt', $math('Subtract_IntInt', $state('SSRadarMaxCol'), $state('SSRadarMinCol')), $i(1))), $l('Offset')), $set('SSRadarEnd', $l('Offset')), $jump('Loop'),
        ['label' => 'Commit'], $set('SSRadarDisplayIndices', $state('SSRadarBestIndices')), $setBool('SSRadarScanActive', $bool(false)), $set('SSRadarWait', $f(0.5)), ['label' => 'Done']];
    $b->define('SSRadarScan', [], ['Point' => 'Int', 'PuzzleId' => 'Int', 'AvailabilityZone' => 'Int', 'Spawn' => 'Int', 'Rank' => 'Int', 'Insert' => 'Int', 'ExistingPoint' => 'Int', 'ExistingRank' => 'Int', 'Cell' => 'Int', 'Offset' => 'Int', 'Bit' => 'Int', 'X' => 'Float', 'Y' => 'Float', 'DX' => 'Float', 'DY' => 'Float', 'Distance' => 'Float', 'ExistingDistance' => 'Float'], $scan);
    $fn = $b->functions['SSRadarRender'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $e]);
    $render = [$let('Sin', $math('DegSin', $state('SSRadarYaw'))), $let('Cos', $math('DegCos', $state('SSRadarYaw')))];
    foreach (range(0, 23) as $pin) {
        $render = [...$render, $unless($math('Less_IntInt', $i($pin), $array('Array_Length', $state('SSRadarDisplayIndices'))), 'Hide' . $pin),
            $array('Array_Get', $state('SSRadarDisplayIndices'), $i($pin), $l('Point')),
            $array('Array_Get', $state('SSRadarPuzzleIds'), $l('Point'), $l('PuzzleId')),
            $array('Array_Get', $state('SSRadarSpawn'), $l('Point'), $l('Spawn')),
            $unless($math('NotEqual_IntInt', $l('Spawn'), $i(0)), 'Fixed' . $pin),
            $let('PuzzleId', $localCall('SSRadarSitePuzzle', [$l('Point')])),
            $unless($math('Greater_IntInt', $l('PuzzleId'), $i(0)), 'Solved' . $pin),
            ['label' => 'Fixed' . $pin],
            $unless($localCall('SSRadarMatchesPentadFocus', [$l('Point'), $l('PuzzleId')]), 'Solved' . $pin),
            $array('Array_Get', $state('SSRadarHubAvailabilityZones'), $l('Point'), $l('AvailabilityZone')),
            $unless($localCall('SSRadarIsPuzzleAvailable', [$l('PuzzleId'), $l('AvailabilityZone')]), 'Solved' . $pin),
            $unless($math('Not_PreBool', $localCall('SSRadarIsSolved', [$l('PuzzleId')])), 'Solved' . $pin),
            $array('Array_Get', $state('SSRadarX'), $l('Point'), $l('X')), $array('Array_Get', $state('SSRadarY'), $l('Point'), $l('Y')), $array('Array_Get', $state('SSRadarZ'), $l('Point'), $l('Z')),
            $let('DX', $math('Subtract_FloatFloat', $l('X'), $state('SSRadarPlayerX'))), $let('DY', $math('Subtract_FloatFloat', $l('Y'), $state('SSRadarPlayerY'))),
            $unless($math('LessEqual_FloatFloat', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $l('DX')), $math('Multiply_FloatFloat', $l('DY'), $l('DY'))), $math('Multiply_FloatFloat', $state('SSRadarViewRadiusCm'), $state('SSRadarViewRadiusCm'))), 'Hide' . $pin),
            $let('ScreenX', $math('Multiply_FloatFloat', $math('Subtract_FloatFloat', $math('Multiply_FloatFloat', $l('DY'), $l('Cos')), $math('Multiply_FloatFloat', $l('DX'), $l('Sin'))), $math('Divide_FloatFloat', $f(96), $state('SSRadarViewRadiusCm')))),
            $let('ScreenY', $math('Multiply_FloatFloat', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $l('Cos')), $math('Multiply_FloatFloat', $l('DY'), $l('Sin'))), $math('Divide_FloatFloat', $f(-96), $state('SSRadarViewRadiusCm')))),
            $b->method($state('SSRadarPin' . $pin), 'Widget', 'SetRenderTranslation', [$makeVec($l('ScreenX'), $l('ScreenY'))]),
            $b->method($state('SSRadarHeight' . $pin), 'Widget', 'SetRenderTranslation', [$makeVec($l('ScreenX'), $l('ScreenY'))]),
            $unless($math('EqualEqual_IntInt', $l('Spawn'), $i(0)), 'Site' . $pin),
            $b->method($state('SSRadarPin' . $pin), 'Image', 'SetColorAndOpacity', [$math('MakeColor', $f(1), $f(1), $f(1), $f(1))]), $jump('ColorDone' . $pin),
            ['label' => 'Site' . $pin], $b->method($state('SSRadarPin' . $pin), 'Image', 'SetColorAndOpacity', [$math('MakeColor', $f(0.35), $f(0.85), $f(1), $f(0.7))]), ['label' => 'ColorDone' . $pin],
            // Swap the pin's brush only when its point type differs from the cached one.
            $array('Array_Get', $state('SSRadarPointTypes'), $l('Point'), $l('PointType')),
            $unless($math('NotEqual_IntInt', $l('PointType'), $state('SSRadarPinType' . $pin)), 'IconDone' . $pin),
            $array('Array_Get', $state('SSRadarTypeTextures'), $l('PointType'), $l('Texture')),
            $b->method($state('SSRadarPin' . $pin), 'Image', 'SetBrushFromTexture', [$l('Texture'), $bool(false)]),
            $set('SSRadarPinType' . $pin, $l('PointType')), ['label' => 'IconDone' . $pin],
            $visible('SSRadarPin' . $pin, 3), $visible('SSRadarHeight' . $pin, 3),
            $unless($math('Greater_FloatFloat', $math('Subtract_FloatFloat', $l('Z'), $state('SSRadarPlayerZ')), $f(1000)), 'Below' . $pin), $labelText('SSRadarHeight' . $pin, '^'), $jump('Next' . $pin),
            ['label' => 'Below' . $pin], $unless($math('Less_FloatFloat', $math('Subtract_FloatFloat', $l('Z'), $state('SSRadarPlayerZ')), $f(-1000)), 'Level' . $pin), $labelText('SSRadarHeight' . $pin, 'v'), $jump('Next' . $pin),
            ['label' => 'Level' . $pin], $labelText('SSRadarHeight' . $pin, ''), $jump('Next' . $pin),
            ['label' => 'Solved' . $pin], $setBool('SSRadarDirty', $bool(true)), $setBool('SSRadarScanActive', $bool(false)),
            ['label' => 'Hide' . $pin], $visible('SSRadarPin' . $pin, 1), $visible('SSRadarHeight' . $pin, 1), ['label' => 'Next' . $pin]];
    }
    $b->define('SSRadarRender', [], ['Point' => 'Int', 'PuzzleId' => 'Int', 'AvailabilityZone' => 'Int', 'Spawn' => 'Int', 'PointType' => 'Int', 'Texture' => 'Object', 'X' => 'Float', 'Y' => 'Float', 'Z' => 'Float', 'DX' => 'Float', 'DY' => 'Float', 'ScreenX' => 'Float', 'ScreenY' => 'Float', 'Sin' => 'Float', 'Cos' => 'Float'], $render);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        if ($property->Name === 'Texture') { $property->PropertyClass = $b->native['Texture2D']; }
    }
    $labyrinth = AddRadarLabyrinthFunctions($b);
    // The local player's custom map markers: SocialSystemComponent.latestUpdatedLocationPings entries
    // whose PingType is ELocationPingType::MapMarker (0). Independent of the selected puzzle types.
    $fn = $b->functions['SSRadarRenderMarkers'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $e]);
    $member = static fn(string $local, string $struct, string $name): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $name), 'StructExpression' => $l($local)]);
    $pings = static fn(): object => $nativeProperty($l('Social'), 'SocialSystemComponent', 'latestUpdatedLocationPings');
    $str = static fn(string $s): object => FilterExpr('StringConst', ['Value' => $s]);
    $concat = static fn(object $a, object $c): object => $b->call('KismetStringLibrary', 'Concat_StrStr', [$a, $c]);
    $intText = static fn(object $n): object => $b->call('KismetStringLibrary', 'Conv_IntToString', [$n]);
    $setText = static fn(string $widget, object $string): object => $b->method($state($widget), 'TextBlock', 'SetText', [$b->call('KismetTextLibrary', 'Conv_StringToText', [$string])]);
    $markers = [$let('Count', $i(0)), $let('Index', $i(0)),
        $let('Sin', $math('DegSin', $state('SSRadarYaw'))), $let('Cos', $math('DegCos', $state('SSRadarYaw'))),
        $let('Controller', $b->call('GameplayStatics', 'GetPlayerController', [FilterExpr('Self'), $i(0)])),
        $unless($valid($l('Controller')), 'Slots'),
        $let('State', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaPlayerState'], 'TargetExpression' => $nativeProperty($l('Controller'), 'Controller', 'PlayerState')])),
        $unless($valid($l('State')), 'Slots'),
        $let('Social', $nativeProperty($l('State'), 'SophiaPlayerState', 'PingSystemComponent')),
        $unless($valid($l('Social')), 'Slots'),
        $let('Count', $array('Array_Length', $pings())),
        ['label' => 'Slots']];
    for ($marker = 0; $marker < $markerSlots; $marker++) {
        $icon = 'SSRadarMarker' . $marker; $arrow = 'SSRadarMarkerArrow' . $marker; $distance = 'SSRadarMarkerDistance' . $marker;
        $markers = [...$markers,
            // Each slot takes the next MapMarker ping; other ping types are skipped.
            ['label' => 'Find' . $marker], $unless($math('Less_IntInt', $l('Index'), $l('Count')), 'Hide' . $marker),
            $array('Array_Get', $pings(), $l('Index'), $l('Ping')), $let('Index', $math('Add_IntInt', $l('Index'), $i(1))),
            $unless($math('EqualEqual_ByteByte', $member('Ping', 'LocationPingInfo', 'PingType'), FilterExpr('ByteConst', ['Value' => 0])), 'Find' . $marker),
            $let('MarkerLoc', $member('Ping', 'LocationPingInfo', 'Loc')),
            $let('DX', $math('Subtract_FloatFloat', $member('MarkerLoc', 'Vector', 'X'), $state('SSRadarPlayerX'))),
            $let('DY', $math('Subtract_FloatFloat', $member('MarkerLoc', 'Vector', 'Y'), $state('SSRadarPlayerY'))),
            // Heading-up screen offsets in cm, +X right and +Y down, as the puzzle pins use.
            $let('RightCm', $math('Subtract_FloatFloat', $math('Multiply_FloatFloat', $l('DY'), $l('Cos')), $math('Multiply_FloatFloat', $l('DX'), $l('Sin')))),
            $let('DownCm', $math('Multiply_FloatFloat', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $l('Cos')), $math('Multiply_FloatFloat', $l('DY'), $l('Sin'))), $f(-1))),
            $let('Distance', $math('Sqrt', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('RightCm'), $l('RightCm')), $math('Multiply_FloatFloat', $l('DownCm'), $l('DownCm'))))),
            $unless($math('LessEqual_FloatFloat', $l('Distance'), $state('SSRadarViewRadiusCm')), 'Far' . $marker),
            $b->method($state($icon), 'Widget', 'SetRenderTranslation', [$makeVec(
                $math('Multiply_FloatFloat', $l('RightCm'), $math('Divide_FloatFloat', $f(96), $state('SSRadarViewRadiusCm'))),
                $math('Multiply_FloatFloat', $l('DownCm'), $math('Divide_FloatFloat', $f(96), $state('SSRadarViewRadiusCm'))))]),
            $visible($icon, 3), $visible($arrow, 1), $visible($distance, 1), $jump('Next' . $marker),
            // Beyond the range: an arrow on the rim pointing at the marker, its distance just inside.
            ['label' => 'Far' . $marker],
            $let('UnitX', $math('Divide_FloatFloat', $l('RightCm'), $l('Distance'))), $let('UnitY', $math('Divide_FloatFloat', $l('DownCm'), $l('Distance'))),
            $b->method($state($arrow), 'Widget', 'SetRenderTranslation', [$makeVec($math('Multiply_FloatFloat', $l('UnitX'), $f(98)), $math('Multiply_FloatFloat', $l('UnitY'), $f(98)))]),
            $b->method($state($arrow), 'Widget', 'SetRenderTransformAngle', [$math('DegAtan2', $l('UnitX'), $math('Multiply_FloatFloat', $l('UnitY'), $f(-1)))]),
            $b->method($state($distance), 'Widget', 'SetRenderTranslation', [$makeVec($math('Multiply_FloatFloat', $l('UnitX'), $f(74)), $math('Multiply_FloatFloat', $l('UnitY'), $f(74)))]),
            // The label gives straight-line distance, height included, as the game's own marker does;
            // position and range stay horizontal because the radar is a top-down view.
            $let('DZ', $math('Subtract_FloatFloat', $member('MarkerLoc', 'Vector', 'Z'), $state('SSRadarPlayerZ'))),
            $let('LabelCm', $math('Sqrt', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('Distance'), $l('Distance')), $math('Multiply_FloatFloat', $l('DZ'), $l('DZ'))))),
            $let('Meters', $math('Round', $math('Divide_FloatFloat', $l('LabelCm'), $f(100)))),
            $unless($math('Less_IntInt', $l('Meters'), $i(1000)), 'Km' . $marker),
            $setText($distance, $concat($intText($l('Meters')), $str(' m'))), $jump('Shown' . $marker),
            ['label' => 'Km' . $marker], $let('Tenths', $math('Round', $math('Divide_FloatFloat', $l('LabelCm'), $f(10000)))),
            $setText($distance, $concat($concat($intText($math('Divide_IntInt', $l('Tenths'), $i(10))), $str('.')), $concat($intText($math('Percent_IntInt', $l('Tenths'), $i(10))), $str(' km')))),
            ['label' => 'Shown' . $marker], $visible($icon, 1), $visible($arrow, 3), $visible($distance, 3), $jump('Next' . $marker),
            ['label' => 'Hide' . $marker], $visible($icon, 1), $visible($arrow, 1), $visible($distance, 1),
            ['label' => 'Next' . $marker]];
    }
    $b->define('SSRadarRenderMarkers', [], ['Controller' => 'Object', 'State' => 'Object', 'Social' => 'Object', 'Ping' => 'Struct', 'MarkerLoc' => 'Struct',
        'Count' => 'Int', 'Index' => 'Int', 'Meters' => 'Int', 'Tenths' => 'Int', 'Sin' => 'Float', 'Cos' => 'Float', 'DX' => 'Float', 'DY' => 'Float',
        'RightCm' => 'Float', 'DownCm' => 'Float', 'Distance' => 'Float', 'UnitX' => 'Float', 'UnitY' => 'Float', 'DZ' => 'Float', 'LabelCm' => 'Float'], $markers);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        $type = ['Controller' => 'PlayerController', 'State' => 'SophiaPlayerState', 'Social' => 'SocialSystemComponent'][$property->Name] ?? null;
        if ($type !== null) { $property->PropertyClass = $b->native[$type]; }
    }
    $fn = $b->functions['SSRadarUpdate'];
    $p = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $p($n), 'Expression' => $e]);
    $distanceSquared = static fn(object $x, object $y): object => $math('Add_FloatFloat', $math('Multiply_FloatFloat', $x, $x), $math('Multiply_FloatFloat', $y, $y));
    $update = [$set('SSRadarVisitedLastUpdate', $i(0)), $localCall('SSRadarRefreshPentadFocus'), $localCall('SSRadarRefreshAvailability'), $localCall('SSRadarRefreshSites'),
        $unless($math('BooleanAND', $state('SSRadarEnabled'), $math('Not_PreBool', $p('MenuHidden'))), 'Hidden'),
        FilterExpr('LetBool', ['VariableExpression' => $p('Teleported'), 'AssignmentExpression' => $math('Greater_FloatFloat', $distanceSquared($math('Subtract_FloatFloat', $p('PlayerX'), $state('SSRadarPlayerX')), $math('Subtract_FloatFloat', $p('PlayerY'), $state('SSRadarPlayerY'))), $f(25000000))]),
        $set('SSRadarPlayerX', $p('PlayerX')), $set('SSRadarPlayerY', $p('PlayerY')), $set('SSRadarPlayerZ', $p('PlayerZ')), $set('SSRadarYaw', $p('HeadingYaw')),
        // Inside a Crystal Labyrinth the radar fits and draws its current floor.
        $localCall('SSRadarRefreshMaze', [$p('PlayerX'), $p('PlayerY'), $p('PlayerZ')]),
        $set('SSRadarViewRadiusCm', $math('SelectFloat', $state('SSRadarMazeRadiusCm'), $state('SSRadarRadiusCm'), $state('SSRadarMazeActive'))),
        $localCall('SSRadarRenderMarkers'),
        // Without a selected type this radar indexes, it still shows: map markers, heading and range.
        $unless($math('NotEqual_IntInt', $math('And_IntInt', $state('SSRadarSelectedMask'), $i($radarMask)), $i(0)), 'NoTypes'),
        $let('CellX', $floorCell($p('PlayerX'), $index['originCellX'])), $let('CellY', $floorCell($p('PlayerY'), $index['originCellY'])),
        $unless($math('BooleanOR', $state('SSRadarDirty'), $p('Teleported')), 'Movement'),
        $array('Array_Clear', $state('SSRadarDisplayIndices')), $jump('Restart'),
        ['label' => 'Movement'], $unless($math('Not_PreBool', $state('SSRadarScanActive')), 'Unchanged'),
        $unless($math('BooleanOR',
            $math('BooleanOR', $math('NotEqual_IntInt', $p('CellX'), $state('SSRadarCellX')), $math('NotEqual_IntInt', $p('CellY'), $state('SSRadarCellY'))),
            $math('Greater_FloatFloat', $distanceSquared($math('Subtract_FloatFloat', $p('PlayerX'), $state('SSRadarScanX')), $math('Subtract_FloatFloat', $p('PlayerY'), $state('SSRadarScanY'))), $f(25000000))), 'Unchanged'),
        ['label' => 'Restart'], $set('SSRadarCellX', $p('CellX')), $set('SSRadarCellY', $p('CellY')), $localCall('SSRadarStartScan'),
        ['label' => 'Unchanged'], $visible('SSRadarCanvas', 3),
        $unless($state('SSRadarScanActive'), 'Wait'), $localCall('SSRadarScan'), $jump('Render'),
        ['label' => 'Wait'], $set('SSRadarWait', $math('Subtract_FloatFloat', $state('SSRadarWait'), $p('DeltaSeconds'))), $unless($math('LessEqual_FloatFloat', $state('SSRadarWait'), $f(0)), 'Render'), $localCall('SSRadarStartScan'), $localCall('SSRadarScan'),
        ['label' => 'Render'], $localCall('SSRadarRender'), $localCall('SSRadarRenderMaze'), $jump('Done'),
        // No indexed type is selected, so no puzzle pin may stay on screen; the labyrinth layer still draws.
        ['label' => 'NoTypes'], $array('Array_Clear', $state('SSRadarDisplayIndices')), $localCall('SSRadarRender'), $localCall('SSRadarRenderMaze'), $visible('SSRadarCanvas', 3), $jump('Done'),
        ['label' => 'Hidden'], $visible('SSRadarCanvas', 1), ['label' => 'Done']];
    $b->define('SSRadarUpdate', ['PlayerX' => 'Float', 'PlayerY' => 'Float', 'PlayerZ' => 'Float', 'HeadingYaw' => 'Float', 'MenuHidden' => 'Bool', 'DeltaSeconds' => 'Float'], ['CellX' => 'Int', 'CellY' => 'Int', 'Teleported' => 'Bool'], $update);
    // Per-zone counts for the map: fixed puzzles plus Hub puzzles the game currently has active.
    // Work runs only while the map keeps asking (SSRadarRequestCounts), in small per-tick slices.
    $countRows = [];
    foreach ($index['puzzleIds'] as $point => $pid) {
        $zone = $index['zones'][$point] ?? 0;
        if ($pid <= 0 || $zone <= 0 || $index['isSpawn'][$point] === 1 || ($index['countable'][$point] ?? 1) === 0) { continue; }
        $key = $index['typeKeys'][$pointTypes[$point]];
        $countRows[$zone . ':' . $key . ':' . $pid] = [$zone, OverlayCatalogSlot($key), $pid, $index['hubAvailabilityZones'][$point] > 0 ? 1 : 0];
    }
    usort($countRows, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
    $zoneStart = array_fill(0, 17, 0);
    foreach ($countRows as [$zone]) { $zoneStart[$zone + 1]++; }
    for ($z = 1; $z < 17; $z++) { $zoneStart[$z] += $zoneStart[$z - 1]; }
    $b->arrayField('Int', 'SSRadarCountIds', array_column($countRows, 2));
    $b->arrayField('Int', 'SSRadarCountSlots', array_column($countRows, 1));
    $b->arrayField('Int', 'SSRadarCountHub', array_column($countRows, 3));
    $b->arrayField('Int', 'SSRadarCountZoneStart', $zoneStart);
    foreach (['SSRadarCountZeros', 'SSRadarCountLeft', 'SSRadarCountTotal', 'SSRadarCountWorkLeft', 'SSRadarCountWorkTotal'] as $n) { $b->arrayField('Int', $n, array_fill(0, 31, 0)); }
    foreach (['SSRadarCountZone' => 0, 'SSRadarCountRevision' => 0, 'SSRadarCountRequestZone' => 0, 'SSRadarCountWanted' => 0, 'SSRadarCountPassZone' => 0, 'SSRadarCountCursor' => 0, 'SSRadarCountEnd' => 0] as $n => $v) { $b->scalar('Int', $n, $v); }
    $b->scalar('Bool', 'SSRadarCountHubReady', 0);
    $b->arrayField('Object', 'SSRadarCountActive', []);
    $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native['PuzzleData'];
    $b->reserve('SSRadarRequestCounts');
    $b->reserve('SSRadarCountStep');
    $fn = $b->functions['SSRadarRequestCounts'];
    $b->define('SSRadarRequestCounts', ['Zone' => 'Int'], [], [
        // A pass left idle while the map was closed restarts with fresh Hub data.
        $unless($math('LessEqual_IntInt', $state('SSRadarCountWanted'), $i(0)), 'Wanted'),
        $set('SSRadarCountPassZone', $i(0)), ['label' => 'Wanted'],
        $set('SSRadarCountWanted', $i(40)),
        $unless($math('NotEqual_IntInt', FilterVariable($fn, 'Zone'), $state('SSRadarCountRequestZone')), 'Done'),
        $set('SSRadarCountRequestZone', FilterVariable($fn, 'Zone')), $set('SSRadarCountPassZone', $i(0)), ['label' => 'Done'],
    ]);
    $fn = $b->functions['SSRadarCountStep'];
    $c = static fn(string $n): object => FilterVariable($fn, $n);
    $clet = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $c($n), 'Expression' => $v]);
    $nativeField = static fn(object $object, string $owner, string $name): object => RadarContext($object, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    // Array_Set reads its item through MostRecentPropertyAddress: pass a plain local, never an inline call.
    $bump = static fn(string $field): array => [
        $array('Array_Get', $state($field), $c('Slot'), $c('Value')),
        $clet('Value', $math('Add_IntInt', $c('Value'), $i(1))),
        $array('Array_Set', $state($field), $c('Slot'), $c('Value'), $bool(false))];
    $start = [
        $unless($math('BooleanAND', $math('Greater_IntInt', $state('SSRadarCountRequestZone'), $i(0)), $math('Less_IntInt', $state('SSRadarCountRequestZone'), $i(16))), 'Done'),
        $set('SSRadarCountPassZone', $state('SSRadarCountRequestZone')),
        $array('Array_Get', $state('SSRadarCountZoneStart'), $state('SSRadarCountPassZone'), $c('Value')), $set('SSRadarCountCursor', $c('Value')),
        $array('Array_Get', $state('SSRadarCountZoneStart'), $math('Add_IntInt', $state('SSRadarCountPassZone'), $i(1)), $c('Value')), $set('SSRadarCountEnd', $c('Value')),
        $set('SSRadarCountWorkLeft', $state('SSRadarCountZeros')), $set('SSRadarCountWorkTotal', $state('SSRadarCountZeros')),
        $array('Array_Clear', $state('SSRadarCountActive')), $setBool('SSRadarCountHubReady', $bool(false)),
        $clet('Source', $database()),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$c('Source')]), 'Done'),
        $unless($nativeField($c('Source'), 'PuzzleDatabase', 'bInitialized'), 'Done'),
        $unless($math('Greater_IntInt', $nativeField($c('Source'), 'PuzzleDatabase', 'groupLifespan'), $i(0)), 'Done'),
        $setBool('SSRadarCountHubReady', $bool(true)),
        $clet('ZoneByte', $math('Conv_IntToByte', $state('SSRadarCountPassZone')))];
    foreach ($hubTypeKeys as $key) {
        // Every Hub-capable type, selected or not: counts cover the whole zone.
        array_push($start,
            $clet('Part', $b->method($c('Source'), 'PuzzleDatabase', 'GetAllActiveSandboxPuzzleDataOfTypeInZone', [FilterExpr('StringConst', ['Value' => $key]), $c('ZoneByte'), $bool(true)], FilterPointer($fn, 'Part'))),
            $unless($math('Not_PreBool', $array('Array_Contains', $c('Part'), FilterExpr('ObjectConst', ['Value' => 0]))), 'HubUnknown'),
            $array('Array_Append', $state('SSRadarCountActive'), $c('Part')));
    }
    array_push($start, $jump('Done'), ['label' => 'HubUnknown'], $setBool('SSRadarCountHubReady', $bool(false)), $jump('Done'));
    $b->define('SSRadarCountStep', [], ['Source' => 'Object', 'Part' => 'Array', 'Data' => 'Object', 'ZoneByte' => 'Byte', 'Id' => 'Int', 'Slot' => 'Int', 'Hub' => 'Int', 'Value' => 'Int', 'Visited' => 'Int'], [
        $unless($math('Greater_IntInt', $state('SSRadarCountWanted'), $i(0)), 'Done'),
        $set('SSRadarCountWanted', $math('Subtract_IntInt', $state('SSRadarCountWanted'), $i(1))),
        $unless($math('EqualEqual_IntInt', $state('SSRadarCountPassZone'), $i(0)), 'Work'),
        ...$start,
        ['label' => 'Work'], $clet('Visited', $i(0)), ['label' => 'Loop'],
        $unless($math('Less_IntInt', $state('SSRadarCountCursor'), $state('SSRadarCountEnd')), 'Publish'),
        $unless($math('Less_IntInt', $c('Visited'), $i(96)), 'Done'),
        $array('Array_Get', $state('SSRadarCountIds'), $state('SSRadarCountCursor'), $c('Id')),
        $array('Array_Get', $state('SSRadarCountSlots'), $state('SSRadarCountCursor'), $c('Slot')),
        $array('Array_Get', $state('SSRadarCountHub'), $state('SSRadarCountCursor'), $c('Hub')),
        $set('SSRadarCountCursor', $math('Add_IntInt', $state('SSRadarCountCursor'), $i(1))), $clet('Visited', $math('Add_IntInt', $c('Visited'), $i(1))),
        $unless($math('Greater_IntInt', $c('Hub'), $i(0)), 'Count'),
        // Hub puzzles count only while the game has them active.
        $unless($state('SSRadarCountHubReady'), 'Loop'),
        $clet('Data', $b->call('SophiaGameplayStatics', 'GetPuzzleData', [FilterExpr('Self'), $c('Id')])),
        $unless($array('Array_Contains', $state('SSRadarCountActive'), $c('Data')), 'Loop'),
        ['label' => 'Count'], ...$bump('SSRadarCountWorkTotal'),
        $unless($math('Not_PreBool', $localCall('SSRadarIsSolved', [$c('Id')])), 'Loop'),
        ...$bump('SSRadarCountWorkLeft'), $jump('Loop'),
        ['label' => 'Publish'],
        $set('SSRadarCountLeft', $state('SSRadarCountWorkLeft')), $set('SSRadarCountTotal', $state('SSRadarCountWorkTotal')),
        $set('SSRadarCountZone', $state('SSRadarCountPassZone')), $set('SSRadarCountRevision', $math('Add_IntInt', $state('SSRadarCountRevision'), $i(1))),
        $set('SSRadarCountPassZone', $i(0)), ['label' => 'Done'],
    ]);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $property) {
        if ($property->Name === 'Source') { $property->PropertyClass = $b->native['PuzzleDatabase']; }
        if ($property->Name === 'Data') { $property->PropertyClass = $b->native['PuzzleData']; }
        if ($property->Name === 'Part') { $property->Inner = $b->field('Object', 'Part'); $property->Inner->PropertyClass = $b->native['PuzzleData']; }
    }
    $fn = $b->functions['SSRadarTick'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $e]);
    $component = static fn(string $local, string $struct, string $member): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $member), 'StructExpression' => $l($local)]);
    $tick = [$localCall('SSRadarCountStep'), $let('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'GetPlayerCharacter', [FilterExpr('Self'), $i(0)])])),
        $unless($b->call('KismetSystemLibrary', 'IsValid', [$l('Player')]), 'Invalid'),
        $unless($math('NotEqual_ObjectObject', $l('Player'), $state('SSRadarProgressPlayer')), 'SamePlayer'),
        $setBool('SSRadarDirty', $bool(true)), $setBool('SSRadarScanActive', $bool(false)),
        ['label' => 'SamePlayer'], $set('SSRadarProgressPlayer', $l('Player')),
        FilterExpr('LetBool', ['VariableExpression' => $l('MapVisible'), 'AssignmentExpression' => $b->method($l('Player'), 'SophiaCharacter', 'IsMapMenuVisible', [], FilterPointer($fn, 'MapVisible'))]),
        $unless($math('Not_PreBool', $l('MapVisible')), 'MapOpen'), $setBool('SSRadarMapProgressDirty', $bool(true)), ['label' => 'MapOpen'],
        $let('PlayerPosition', $b->method($l('Player'), 'Actor', 'K2_GetActorLocation', [], FilterPointer($fn, 'PlayerPosition'))),
        $let('PlayerRotation', $b->method($l('Player'), 'Pawn', 'GetControlRotation', [], FilterPointer($fn, 'PlayerRotation'))),
        $localCall('SSRadarUpdate', [$component('PlayerPosition', 'Vector', 'X'), $component('PlayerPosition', 'Vector', 'Y'), $component('PlayerPosition', 'Vector', 'Z'), $component('PlayerRotation', 'Rotator', 'Yaw'),
            $math('BooleanOR',
                $math('BooleanOR', $l('MapVisible'), $b->method($l('Player'), 'SophiaCharacter', 'IsPauseMenuVisible', [], FilterPointer($fn, 'MenuResult'))),
                $math('BooleanOR', $b->method($l('Player'), 'SophiaCharacter', 'IsInPuzzleMode', [], FilterPointer($fn, 'MenuResult')), $b->method($l('Player'), 'SophiaCharacter', 'AreOtherInputWidgetsVisible', [FilterExpr('ObjectConst', ['Value' => 0])], FilterPointer($fn, 'MenuResult')))), $f(0.1)]),
        $jump('Done'), ['label' => 'Invalid'], $set('SSRadarProgressPlayer', FilterExpr('ObjectConst', ['Value' => 0])), $setBool('SSRadarProgressAvailable', $bool(false)), $localCall('SSRadarRefreshPentadFocus'), $visible('SSRadarCanvas', 1), ['label' => 'Done']];
    $b->define('SSRadarTick', [], ['Player' => 'Object', 'PlayerPosition' => 'Struct', 'PlayerRotation' => 'Struct', 'MapVisible' => 'Bool', 'MenuResult' => 'Bool'], $tick);
    foreach (['OnInitialized', 'Construct'] as $event) {
        $timerFunction = $b->functions[$event];
        // Saved settings (types, view, keys) are restored once, before anything reads them.
        $events = [...($event === 'OnInitialized' ? [$localCall('SSRadarLoadSettings'), $localCall('SSRadarApplyIcon')] : []),
            $localCall('SSRadarApplyView'), FilterExpr('Let', ['Value' => FilterPointer($timerFunction, 'Timer'), 'Variable' => FilterVariable($timerFunction, 'Timer'),
            'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'SSRadarTick']), $f(0.1), $bool(true), $f(0), $f(0)])])];
        if ($event === 'Construct') {
            $events[] = $b->method(FilterExpr('Self'), 'UserWidget', 'StopListeningForAllInputActions');
            $events[] = $localCall('SSRadarRegisterKeys');
            foreach ($inputCallbacks as $action => $callback) {
                $events[] = $b->method(FilterExpr('Self'), 'UserWidget', 'ListenForInputAction', [FilterExpr('NameConst', ['Value' => $shortcutActions[$action][0]]), FilterExpr('ByteConst', ['Value' => 0]), $bool(false), FilterExpr('InstanceDelegate', ['FunctionName' => $callback])]);
            }
        }
        $b->define($event, [], ['Timer' => 'Struct'], $events, $event);
    }
    $b->define('Destruct', [], [], [$b->method(FilterExpr('Self'), 'UserWidget', 'StopListeningForAllInputActions'), $b->call('KismetSystemLibrary', 'K2_ClearTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])])], 'Destruct');
    return [$b->finish(), ['assetPackage' => '/Game/SaveStats/WBP_SSOverlayRadar', 'className' => 'WBP_SSOverlayRadar_C',
        'radiusCm' => 10000, 'radiusPresetsCm' => [5000, 10000, 20000, 40000], 'sizePresets' => [1, 1.25, 1.5], 'defaultSize' => 1.25,
        'shortcuts' => array_combine(array_map('lcfirst', array_keys($shortcutActions)), array_column($shortcutActions, 1)),
        'rebinding' => ['function' => 'SSRadarRebind(Action, InputKey)', 'reset' => 'SSRadarResetKeys', 'register' => 'SSRadarRegisterKeys', 'swapField' => 'SSRadarRebindSwap',
            'actions' => array_map(static fn(array $a): string => $a[0], array_values($shortcutActions)), 'clash' => 'the action that held the new key takes the old one',
            'registration' => 'every mapping of each radar action name is removed, then exactly one is added'],
        'settings' => ['package' => RADAR_SETTINGS_PACKAGE, 'class' => RADAR_SETTINGS_CLASS, 'slot' => RADAR_SETTINGS_SLOT, 'userIndex' => 0, 'fields' => array_keys(RadarSettingsFields()),
            'catalogKey' => RadarSettingsCatalogKey(), 'load' => 'SSRadarLoadSettings in OnInitialized (revision unchanged)', 'save' => 'SSRadarSaveSettings after every applied change',
            'savedBy' => ['SSRadarSetSelection', 'SSRadarSetView', 'SSRadarSetHideDone', 'SSRadarRebind', 'SSRadarResetKeys']],
        'heightDeadbandCm' => 1000, 'pinLimit' => 24, 'candidateBudget' => 128, 'updateIntervalSeconds' => 0.1,
        'candidateIntervalSeconds' => 0.5, 'displacementInvalidationCm' => 5000, 'indexedPoints' => count($index['positions']), 'gridWidth' => $index['width'], 'gridHeight' => $index['height'],
        'functionNames' => array_keys($b->functions), 'counts' => ['request' => 'SSRadarRequestCounts(Zone)', 'left' => 'SSRadarCountLeft', 'total' => 'SSRadarCountTotal', 'zone' => 'SSRadarCountZone', 'revision' => 'SSRadarCountRevision',
            'indexedBy' => 'catalog position', 'rows' => count($countRows), 'perTickBudget' => 96, 'requestLifetimeTicks' => 40, 'hubRule' => 'Hub puzzles count only while GetAllActiveSandboxPuzzleDataOfTypeInZone lists them'], 'selectionFunction' => 'SSRadarSetSelection', 'updateFunction' => 'SSRadarUpdate', 'initiallyHidden' => true,
        'selectionField' => 'SSRadarSelectedMask', 'selectionMode' => 'multiple via SSRadarSelectedMask', 'selectionParameters' => ['SelectedMask' => 'Int', 'IncludeSites' => 'Bool', 'Enabled' => 'Bool'],
        'typeBits' => array_combine($index['typeKeys'], $typeBits), 'typeMask' => $radarMask, 'typeBitsField' => 'SSRadarTypeBits', 'pointTypesField' => 'SSRadarPointTypes',
        'scanOrder' => 'selected types in radar type order over one bounds computation; one commit after the last selected type',
        'pinIcons' => ['texturesField' => 'SSRadarTypeTextures', 'cacheFields' => 'SSRadarPinType0..23', 'rule' => 'each pin shows its point type icon; brush set only when the cached type differs'],
        'caption' => ['none' => 'Puzzle radar', 'one' => 'type display name', 'many' => 'N puzzle types'],
        'mapMarkers' => ['renderFunction' => 'SSRadarRenderMarkers', 'slots' => $markerSlots,
            'authority' => 'PlayerController(0).PlayerState as SophiaPlayerState -> PingSystemComponent (SocialSystemComponent) -> latestUpdatedLocationPings',
            'entryStruct' => 'LocationPingInfo', 'typeField' => 'PingType', 'mapMarkerType' => 'ELocationPingType::MapMarker = 0', 'positionField' => 'Loc',
            'inRange' => 'game MapMarker icon at its position', 'outOfRange' => 'cyan SSRadHead arrow on the rim (98 px) with its distance inside (74 px)',
            'distanceText' => ['belowOneKm' => 'N m', 'fromOneKm' => 'N.N km', 'measure' => '3D straight-line from the player (height included); placement and range use horizontal distance'], 'independentOfSelection' => true,
            'visibleWithoutSelectedTypes' => 'always while enabled: markers, heading and range, no puzzle pins', 'runtimeVerified' => true],
        'liveCompletion' => ['queryFunction' => 'SSRadarIsSolved', 'authority' => 'GISProgression.GetPuzzleSolveStatus', 'solvedField' => 'PuzzleSolveData.bSolved', 'statusStructBytes' => 40,
            'unavailableReturnsSolved' => false, 'possibleSitePuzzleId' => 0, 'cachedOrdinaryStatusValues' => false, 'renderStatusChecksLimit' => 24,
            'mapProgressDirtyField' => 'SSRadarMapProgressDirty', 'mapAcknowledgeFunction' => 'SSRadarAcknowledgeMapProgress'],
        'specialCompletion' => $specialCompletion + ['loreAuthority' => 'GISProgression.GetEarnedLore; Find && earned value',
            'monolithAuthority' => 'parent PuzzleSolveData.serializedProgress.Found[index]', 'malformedProgressReturnsSolved' => false,
            'descriptorArrays' => ['SSRadarSpecialIds', 'SSRadarSpecialKinds', 'SSRadarSpecialKeys', 'SSRadarSpecialParentIds', 'SSRadarSpecialFragmentIndices']],
        'specialCompletionCoverage' => ['lorePuzzles' => $specialCompletion['lore'], 'monolithFragments' => $specialCompletion['monolithFragments'], 'saveKeyPuzzles' => $specialCompletion['saveKeyPuzzles'], 'total' => $specialCompletion['descriptors']],
        'saveKeyCompletion' => ['authority' => 'GISProgression.GetPuzzleSolveStatus(Player, KrakenID 0, LocalID "", hackID = full saved key)',
            'types' => ['rosary', 'matchbox'], 'keyArray' => 'SSRadarSpecialKeys', 'runtimeVerified' => true],
        'liveSites' => $liveSites,
        'hubAvailability' => ['queryFunction' => 'SSRadarIsPuzzleAvailable', 'refreshFunction' => 'SSRadarRefreshAvailability',
            'zoneArray' => 'SSRadarHubAvailabilityZones', 'eligiblePuzzleIds' => count($hubPuzzleIds), 'eligiblePoints' => $hubPoints,
            'eligibleTypeTags' => array_keys($hubTypeTags), 'eligibleTypeKeys' => $hubTypeKeys, 'eligibleTypeMask' => $hubMask, 'zones' => array_keys($hubZones),
            'authority' => 'PuzzleDatabase.GetAllActiveSandboxPuzzleDataOfTypeInZone', 'queryLimitPerRefresh' => 1,
            'nativeCallsPerZoneQuery' => 'one per Hub-capable type, selected or not, across successive refreshes; the zone is published once all are in', 'nativeCallLimitPerRefresh' => 1,
            'refreshZones' => [2, 3, 4, 5, 6], 'fullSweepRefreshCalls' => 5 * count($hubTypeKeys), 'sandboxProgressionRelevant' => true,
            'readinessFields' => ['PuzzleDatabase.bInitialized', 'PuzzleDatabase.groupLifespan'],
            'cacheIdentity' => ['local character', 'GameMode world anchor', 'database instance', 'group lifespan'], 'independentOfSelection' => true,
            'cursorFields' => ['SSRadarAvailabilityZoneCursor', 'SSRadarAvailabilityTypeCursor'], 'workFields' => ['SSRadarAvailabilityWork', 'SSRadarAvailabilityWorkUnknown'],
            'unreadyOrUnknownReturnsAvailable' => true, 'nullPuzzleDataReturnsAvailable' => true, 'emptyResultIsReadinessSignal' => false],
        'activePentad' => ['selectedType' => 'seek5', 'selectionRule' => 'seek5 bit set in SSRadarSelectedMask; relic rules apply to seek5 points only', 'refreshFunction' => 'SSRadarRefreshPentadFocus', 'focusField' => 'SSRadarFocusPuzzleId',
            'authority' => 'SophiaCharacter.CharPuzzleComponent.GetCurrentSeek5', 'stateFunction' => 'Seek5.GetState', 'activeState' => 1,
            'identityField' => 'PuzzleBase.KrakenId', 'cataloguedPentads' => count($pentadIds), 'requiresFiveDistinctRelics' => true,
            'unavailableFocusId' => 0, 'overviewHidesRelics' => true, 'collectedRelicsHiddenIndividually' => true,
            'relicVisibilityFunction' => 'SSRadarIsPentadRelicVisible', 'relicAuthority' => 'Seek5.HiddenObjects[].isFound',
            'relicMatchSpace' => 'serialized local translation', 'relicMatchToleranceCm' => 0.05,
            'relicMatchRequiresBijection' => true, 'relicComponentLimit' => 5, 'unknownRelicProgressRemainsVisible' => true],
        'labyrinth' => $labyrinth + ['handmadeLabyrinths' => count($labyrinths), 'handmadePuzzleIds' => array_column($labyrinths, 'pid'),
            'handmadeFloors' => array_sum(array_map(static fn(array $l): int => count($l['floors']), $labyrinths)),
            'handmadeWalls' => array_sum(array_map(static fn(array $l): int => array_sum(array_map(static fn(array $f): int => count($f['walls']), $l['floors'])), $labyrinths))],
        'inputTransparent' => true, 'menuMethods' => ['SophiaCharacter.IsMapMenuVisible', 'SophiaCharacter.IsPauseMenuVisible', 'SophiaCharacter.IsInPuzzleMode', 'SophiaCharacter.AreOtherInputWidgetsVisible']]];
}

function RadarWidgetMain(array $argv): int
{
    $options = getopt('', ['base-widget:', 'radar-data:', 'output:', 'manifest:', 'labyrinths:'], $next);
    if ($next !== count($argv) || !isset($options['base-widget'], $options['radar-data'], $options['output'])) { throw new InvalidArgumentException('Usage: php radar_widget.php --base-widget FILE --radar-data FILE --output FILE [--manifest FILE] [--labyrinths FILE]'); }
    $base = json_decode(file_get_contents($options['base-widget']), false, 512, JSON_THROW_ON_ERROR);
    $index = json_decode(file_get_contents($options['radar-data']), true, 512, JSON_THROW_ON_ERROR);
    $labyrinths = isset($options['labyrinths']) ? (json_decode(file_get_contents($options['labyrinths']), true, 512, JSON_THROW_ON_ERROR)['labyrinths'] ?? []) : [];
    [$asset, $manifest] = BuildRadarWidget($base, $index, $labyrinths);
    file_put_contents($options['output'], json_encode($asset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    if (isset($options['manifest'])) { file_put_contents($options['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"); }
    echo 'Generated radar with ' . count($asset->Exports) . " exports.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(RadarWidgetMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
