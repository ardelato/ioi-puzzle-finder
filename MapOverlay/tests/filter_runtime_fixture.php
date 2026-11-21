<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/filter_widget.php';

function RemapFilterFunction(mixed $value, array $indices): mixed
{
    if (is_object($value)) {
        foreach ($value as $key => $child) {
            if (in_array($key, ['ResolvedOwner', 'StackNode'], true) && is_int($child) && isset($indices[$child])) { $value->$key = $indices[$child]; }
            else { $value->$key = RemapFilterFunction($child, $indices); }
        }
    } elseif (is_array($value)) { foreach ($value as $key => $child) { $value[$key] = RemapFilterFunction($child, $indices); } }
    return $value;
}

function FixtureSaveAsset(string $path, object $asset): void
{
    require_once dirname(__DIR__, 2) . '/lib/uasset.php';
    SaveCompressedDecodedUasset($path, $asset, ['bakeAllIndices' => true, 'verbose' => false]);
}

function BuildFilterRuntimeFixture(string $outputDirectory, string $widgetPath, string $worldPath, bool $actualWidget = false, bool $withRadarHud = false): array
{
    if ($withRadarHud && !$actualWidget) { throw new RuntimeException('The radar HUD integration requires --actual-widget.'); }
    $widget = json_decode(file_get_contents($widgetPath), false, 512, JSON_THROW_ON_ERROR);
    $world = json_decode(file_get_contents($worldPath), false, 512, JSON_THROW_ON_ERROR);
    $actorTemplate = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/BP_RacingBalls.json')), false, 512, JSON_THROW_ON_ERROR);
    $oldClass = null;
    $oldDefault = null;
    $generatedFunctions = [];
    foreach ($widget->Exports as $index => $export) {
        if ($export->ObjectName === 'WBP_LargeMap_C') { $oldClass = $export; $oldClassIndex = $index + 1; }
        if ($export->ObjectName === 'Default__WBP_LargeMap_C') { $oldDefault = $export; }
        if (str_starts_with($export->ObjectName, 'SSOverlay') && $export->{'$type'} === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') { $generatedFunctions[$index + 1] = $export; }
    }
    if ($oldClass === null || $oldDefault === null || count($generatedFunctions) < 32) { throw new RuntimeException('Expected the completed generated filter widget.'); }
    $engine = OverlayImport($widget, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    $core = OverlayImport($widget, '/Script/CoreUObject', 0, '/Script/CoreUObject', 'Package');
    $game = OverlayImport($widget, '/Script/IslandsofInsight', 0, '/Script/CoreUObject', 'Package');
    $actorClass = OverlayImport($widget, 'Actor', $engine, '/Script/CoreUObject', 'Class');
    $actorDefault = OverlayImport($widget, 'Default__Actor', $engine, '/Script/Engine', 'Actor');
    $blueprintClass = OverlayImport($widget, 'BlueprintGeneratedClass', $engine, '/Script/CoreUObject', 'Class');
    $blueprintDefault = OverlayImport($widget, 'Default__BlueprintGeneratedClass', $engine, '/Script/Engine', 'BlueprintGeneratedClass');
    $objectClass = OverlayImport($widget, 'Object', $core, '/Script/CoreUObject', 'Class');
    $statics = OverlayImport($widget, 'GameplayStatics', $engine, '/Script/CoreUObject', 'Class');
    $openLevel = OverlayImport($widget, 'OpenLevel', $statics, '/Script/CoreUObject', 'Function');
    $getActor = OverlayImport($widget, 'GetActorOfClass', $statics, '/Script/CoreUObject', 'Function');
    $receiveBeginPlay = OverlayImport($widget, 'ReceiveBeginPlay', $actorClass, '/Script/CoreUObject', 'Function');
    $markerClass = OverlayImport($widget, 'MarkerEmitter', $game, '/Script/CoreUObject', 'Class');
    $markerStruct = OverlayImport($widget, 'LocationMarkerData', $game, '/Script/CoreUObject', 'ScriptStruct');
    $componentByClass = OverlayImport($widget, 'GetComponentByClass', $actorClass, '/Script/CoreUObject', 'Function');
    $math = OverlayImport($widget, 'KismetMathLibrary', $engine, '/Script/CoreUObject', 'Class');
    $boolEqual = OverlayImport($widget, 'EqualEqual_BoolBool', $math, '/Script/CoreUObject', 'Function');
    $system = OverlayImport($widget, 'KismetSystemLibrary', $engine, '/Script/CoreUObject', 'Class');
    $isValid = OverlayImport($widget, 'IsValid', $system, '/Script/CoreUObject', 'Function');
    $umg = OverlayImport($widget, '/Script/UMG', 0, '/Script/CoreUObject', 'Package');
    $widgetLibrary = OverlayImport($widget, 'WidgetBlueprintLibrary', $umg, '/Script/CoreUObject', 'Class');
    $createWidget = OverlayImport($widget, 'Create', $widgetLibrary, '/Script/CoreUObject', 'Function');
    $widgetPackage = OverlayImport($widget, '/Game/ASophia/UI/HUD/Map/WBP_LargeMap', 0, '/Script/CoreUObject', 'Package');
    $actualWidgetClass = OverlayImport($widget, 'WBP_LargeMap_C', $widgetPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $checkBoxClass = OverlayImport($widget, 'CheckBox', $umg, '/Script/CoreUObject', 'Class');
    $buttonClass = OverlayImport($widget, 'Button', $umg, '/Script/CoreUObject', 'Class');
    $widgetClass = OverlayImport($widget, 'Widget', $umg, '/Script/CoreUObject', 'Class');
    $userWidgetClass = OverlayImport($widget, 'UserWidget', $umg, '/Script/CoreUObject', 'Class');
    $radarPackage = OverlayImport($widget, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($widget, 'WBP_SSOverlayRadar_C', $radarPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $hudPackage = OverlayImport($widget, '/Game/ASophia/UI/HUD/WBP_GameHUD', 0, '/Script/CoreUObject', 'Package');
    $hudClass = OverlayImport($widget, 'WBP_GameHUD_C', $hudPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $getWidgets = OverlayImport($widget, 'GetAllWidgetsOfClass', $widgetLibrary, '/Script/CoreUObject', 'Function');
    $addToViewport = OverlayImport($widget, 'AddToViewport', $userWidgetClass, '/Script/CoreUObject', 'Function');
    $nameEqual = OverlayImport($widget, 'EqualEqual_NameName', $math, '/Script/CoreUObject', 'Function');
    $objectEqual = OverlayImport($widget, 'EqualEqual_ObjectObject', $math, '/Script/CoreUObject', 'Function');
    $setChecked = OverlayImport($widget, 'SetIsChecked', $checkBoxClass, '/Script/CoreUObject', 'Function');
    $isChecked = OverlayImport($widget, 'IsChecked', $checkBoxClass, '/Script/CoreUObject', 'Function');
    $getVisibility = OverlayImport($widget, 'GetVisibility', $widgetClass, '/Script/CoreUObject', 'Function');
    $byteEqual = OverlayImport($widget, 'EqualEqual_ByteByte', $math, '/Script/CoreUObject', 'Function');
    $checkboxSignature = OverlayImport($widget, 'OnCheckBoxComponentStateChanged__DelegateSignature', $umg, '/Script/CoreUObject', 'Function');
    $buttonSignature = OverlayImport($widget, 'OnButtonClickedEvent__DelegateSignature', $umg, '/Script/CoreUObject', 'Function');
    $indices = [$oldClassIndex => 1];
    foreach (array_keys($generatedFunctions) as $offset => $oldIndex) { $indices[$oldIndex] = $offset + 3; }
    $functions = [];
    $byName = [];
    foreach ($generatedFunctions as $oldIndex => $function) {
        $function = RemapFilterFunction(unserialize(serialize($function)), $indices);
        $function->{'$index'} = $indices[$oldIndex];
        $function->OuterIndex = 1;
        $function->CreateBeforeCreateDependencies = [1];
        $functions[] = $function;
        $byName[$function->ObjectName] = $function->{'$index'};
    }
    $templateClass = null;
    foreach ($actorTemplate->Exports as $export) { if ($export->ObjectName === 'BP_RacingBalls_C') { $templateClass = $export; } }
    $class = unserialize(serialize($templateClass));
    $class->{'$index'} = 1;
    $class->ObjectName = 'BP_SSFilterTest_C';
    $class->ClassIndex = $blueprintClass;
    $class->TemplateIndex = $blueprintDefault;
    $class->ClassWithin = $objectClass;
    $class->SuperIndex = $actorClass;
    $class->SuperStruct = $actorClass;
    $class->ClassDefaultObject = 2;
    $class->ClassGeneratedBy = 0;
    $class->LoadedProperties = array_values(array_filter($oldClass->LoadedProperties, static fn(object $field): bool => str_starts_with($field->Name, 'SSOverlay')));
    $class->FuncMap = [];
    $class->Children = [];
    $class->Data = [];
    $class->Extras = '';
    $class->SerializationBeforeSerializationDependencies = [$actorClass];
    $class->CreateBeforeSerializationDependencies = [];
    $class->SerializationBeforeCreateDependencies = [$blueprintClass, $blueprintDefault];
    $class->CreateBeforeCreateDependencies = [$actorClass];
    $default = OverlayExport($oldDefault, 2, 'Default__BP_SSFilterTest_C', 0, 1, $actorDefault,
        array_values(array_filter($oldDefault->Data, static fn(object $field): bool => str_starts_with($field->Name, 'SSOverlay'))));
    $default->ObjectFlags = 'RF_Public, RF_ClassDefaultObject, RF_ArchetypeObject';
    $default->Extras = '';
    $default->CreateBeforeCreateDependencies = [1];

    // A driver function executes the production handlers, then travels to an
    // explicit pass/fail map. Shipping PrintString may be compiled out.
    $beginIndex = count($functions) + 3;
    $boolTemplate = $class->LoadedProperties[0];
    $objectTemplate = null;
    foreach ($oldClass->LoadedProperties as $property) { if ($property->SerializedType === 'ObjectProperty') { $objectTemplate = $property; break; } }
    $emitterLocal = FilterField($objectTemplate, 'TestEmitter');
    $emitterLocal->PropertyClass = $markerClass;
    $testActorLocal = FilterField($objectTemplate, 'TestActor');
    $testActorLocal->PropertyClass = $actorClass;
    $tagResult = FilterField($boolTemplate, 'TestBoolean');
    $widgetLocal = FilterField($objectTemplate, 'TestWidget');
    $widgetLocal->PropertyClass = $actualWidgetClass;
    $hudLocal = FilterField($objectTemplate, 'TestHud');
    $hudLocal->PropertyClass = $hudClass;
    $radarLocal = FilterField($objectTemplate, 'TestRadar');
    $radarLocal->PropertyClass = $radarClass;
    $anyWidgetLocal = FilterField($objectTemplate, 'TestAnyWidget');
    $anyWidgetLocal->PropertyClass = $userWidgetClass;
    $local = static fn(string $name): object => FilterVariable($beginIndex, $name);
    $let = static fn(string $name, object $expression): object => FilterExpr('Let', ['Value' => FilterPointer($beginIndex, $name), 'Variable' => $local($name), 'Expression' => $expression]);
    $nullPointer = (object)['$type' => 'UAssetAPI.Kismet.Bytecode.KismetPropertyPointer, UAssetAPI', 'New' => (object)['$type' => 'UAssetAPI.UnrealTypes.FFieldPath, UAssetAPI', 'Path' => [], 'ResolvedOwner' => 0]];
    $tagActors = OverlayImport($widget, 'GetAllActorsWithTag', $statics, '/Script/CoreUObject', 'Function');
    $arrayLib = OverlayImport($widget, 'KismetArrayLibrary', $engine, '/Script/CoreUObject', 'Class');
    $arrayGet = OverlayImport($widget, 'Array_Get', $arrayLib, '/Script/CoreUObject', 'Function');
    $arrayTemplate = null;
    foreach ($functions as $function) { foreach ($function->LoadedProperties as $field) { if ($field->Name === 'Actors') { $arrayTemplate = $field; } } }
    $testActors = FilterField($arrayTemplate, 'TestActors');
    $testActors->Inner->Name = 'TestActors';
    $testWidgets = FilterField($arrayTemplate, 'TestWidgets');
    $testWidgets->Inner->Name = 'TestWidgets';
    $testWidgets->Inner->PropertyClass = $userWidgetClass;
    $assertions = [];
    $code = [];
    $calls = [];
    $assertCondition = static function (object $condition) use (&$code, &$assertions): void {
        $assertions[] = count($code);
        $code[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, 'BooleanExpression' => $condition]);
    };
    $getControl = static fn(string $name): object => FilterContext($local('TestWidget'), FilterVariable($actualWidgetClass, $name, true), FilterPointer($actualWidgetClass, $name));
    $getShared = static fn(string $name): object => FilterContext($local('TestRadar'), FilterVariable($radarClass, $name, true), FilterPointer($radarClass, $name));
    if ($withRadarHud) {
        $code[] = $let('TestHud', FilterExpr('DynamicCast', ['ClassPtr' => $hudClass, 'TargetExpression' => FilterCall($createWidget, [
            FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $hudClass]), FilterExpr('ObjectConst', ['Value' => 0]),
        ])]));
        $assertCondition(FilterCall($isValid, [$local('TestHud')]));
        $code[] = FilterContext($local('TestHud'), FilterCall($addToViewport, [FilterExpr('IntConst', ['Value' => 0])], 'FinalFunction'), $nullPointer);
        $code[] = FilterCall($getWidgets, [FilterExpr('Self'), $local('TestWidgets'), FilterExpr('ObjectConst', ['Value' => $radarClass]), FilterExpr('False')]);
        $code[] = FilterCall($arrayGet, [$local('TestWidgets'), FilterExpr('IntConst', ['Value' => 0]), $local('TestAnyWidget')]);
        $code[] = $let('TestRadar', FilterExpr('DynamicCast', ['ClassPtr' => $radarClass, 'TargetExpression' => $local('TestAnyWidget')]));
        $assertCondition(FilterCall($isValid, [$local('TestRadar')]));
    }
    if ($actualWidget) {
        $code[] = $let('TestWidget', FilterExpr('DynamicCast', ['ClassPtr' => $actualWidgetClass, 'TargetExpression' => FilterCall($createWidget, [
            FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $actualWidgetClass]), FilterExpr('ObjectConst', ['Value' => 0]),
        ])]));
        $assertCondition(FilterCall($isValid, [$local('TestWidget')]));
        $assertCondition(FilterCall($isValid, [$getControl('SSOverlayCheck_type_hiddenCube')]));
        $assertCondition(FilterCall($isValid, [$getControl('SSOverlayPanel')]));
        if ($withRadarHud) { $assertCondition(FilterCall($objectEqual, [$getControl('SSOverlayRadarState'), $local('TestRadar')])); }
    }
    $check = static function (string $tag, bool $expected) use (&$code, &$assertions, $local, $let, $tagActors, $arrayGet, $componentByClass, $markerClass, $markerStruct, $boolEqual, $beginIndex): void {
        $code[] = FilterCall($tagActors, [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => $tag]), $local('TestActors')]);
        $code[] = FilterCall($arrayGet, [$local('TestActors'), FilterExpr('IntConst', ['Value' => 0]), $local('TestActor')]);
        $code[] = $let('TestEmitter', FilterExpr('DynamicCast', ['ClassPtr' => $markerClass, 'TargetExpression' => FilterContext(
            $local('TestActor'), FilterCall($componentByClass, [FilterExpr('ObjectConst', ['Value' => $markerClass])], 'FinalFunction'), FilterPointer($beginIndex, 'TestEmitter'))]));
        $visible = FilterExpr('StructMemberContext', [
            'StructMemberExpression' => FilterPointer($markerStruct, 'VisibleOnMap'), 'StructExpression' => FilterVariable($markerClass, 'Marker', true),
        ]);
        $visible->StructExpression = FilterContext($local('TestEmitter'), $visible->StructExpression, FilterPointer($markerClass, 'Marker'));
        $assertions[] = count($code);
        $code[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, 'BooleanExpression' => FilterCall($boolEqual, [$visible, FilterExpr($expected ? 'True' : 'False')])]);
    };
    $externalFunctions = [];
    foreach (array_keys($byName) as $functionName) { $externalFunctions[$functionName] = OverlayImport($widget, $functionName, $actualWidgetClass, '/Script/CoreUObject', 'Function'); }
    $invoke = static function (string $name, ?bool $argument = null) use (&$code, &$calls, $byName, $actualWidget, $getControl, $setChecked, $nullPointer, $checkboxSignature, $buttonSignature, $checkBoxClass, $buttonClass, $externalFunctions, $local): void {
        if (!$actualWidget) {
            $code[] = FilterCall($byName[$name], $argument === null ? [] : [FilterExpr($argument ? 'True' : 'False')], 'LocalFinalFunction');
        } elseif (preg_match('/^SSOverlayChanged_(source|type|radar)_(.+)$/', $name, $match) || $name === 'SSOverlayTogglePanel') {
            $control = $getControl($name === 'SSOverlayTogglePanel' ? 'SSOverlayShowFilters' : 'SSOverlayCheck_' . $match[1] . '_' . $match[2]);
            $value = FilterExpr($argument ? 'True' : 'False');
            $code[] = FilterContext($control, FilterCall($setChecked, [$value], 'FinalFunction'), $nullPointer);
            $delegate = FilterContext($control, FilterVariable($checkBoxClass, 'OnCheckStateChanged', true), FilterPointer($checkBoxClass, 'OnCheckStateChanged'));
            $code[] = FilterExpr('CallMulticastDelegate', ['StackNode' => $checkboxSignature, 'Delegate' => $delegate, 'Parameters' => [$value]]);
        } elseif ($name === 'SSOverlayTypesNone') {
            $control = $getControl('SSOverlayButtonNone');
            $delegate = FilterContext($control, FilterVariable($buttonClass, 'OnClicked', true), FilterPointer($buttonClass, 'OnClicked'));
            $code[] = FilterExpr('CallMulticastDelegate', ['StackNode' => $buttonSignature, 'Delegate' => $delegate, 'Parameters' => []]);
        } else {
            $code[] = FilterContext($local('TestWidget'), FilterCall($externalFunctions[$name], [], 'FinalFunction'), $nullPointer);
        }
        $calls[] = $name;
    };
    $checkBox = static function (string $name, bool $expected) use ($actualWidget, $assertCondition, $boolEqual, $getControl, $isChecked, $beginIndex): void {
        if ($actualWidget) { $assertCondition(FilterCall($boolEqual, [FilterContext($getControl($name), FilterCall($isChecked, [], 'FinalFunction'), FilterPointer($beginIndex, 'TestBoolean')), FilterExpr($expected ? 'True' : 'False')])); }
    };
    $invoke('SSOverlayRefresh');
    foreach (['Static' => false, 'Hub' => false, 'HubCube' => false, 'Spawn' => false, 'Control' => true] as $tag => $expected) { $check('Test.' . $tag, $expected); }
    $checkBox('SSOverlayCheck_type_hiddenCube', false);
    $checkBox('SSOverlayShowFilters', false);
    if ($actualWidget) { $assertCondition(FilterCall($byteEqual, [FilterContext($getControl('SSOverlayPanel'), FilterCall($getVisibility, [], 'FinalFunction'), $nullPointer), FilterExpr('ByteConst', ['Value' => 1])])); }
    $invoke('SSOverlayChanged_type_hiddenCube', true);
    $check('Test.Static', true); $check('Test.HubCube', true); $check('Test.Hub', false); $check('Test.Spawn', false);
    $invoke('SSOverlayChanged_source_spawn', true);
    $check('Test.Spawn', true);
    if ($withRadarHud) {
        $assertCondition(FilterCall($nameEqual, [$getShared('SSRadarSelectedTag'), FilterExpr('NameConst', ['Value' => 'SaveStats.Type.hiddenCube'])]));
        $assertCondition($getShared('SSRadarIncludeSites'));
        $invoke('SSOverlayChanged_radar_enabled', false);
        $assertCondition(FilterCall($boolEqual, [$getShared('SSRadarEnabled'), FilterExpr('False')]));
        $check('Test.Static', true); $check('Test.HubCube', true);
    }
    $invoke('SSOverlayChanged_type_logicGrid', true);
    $checkBox('SSOverlayCheck_type_hiddenCube', false);
    $checkBox('SSOverlayCheck_type_logicGrid', true);
    $check('Test.Static', false); $check('Test.HubCube', false); $check('Test.Hub', true); $check('Test.Spawn', true);
    $invoke('SSOverlayChanged_type_fractalMatch', true);
    foreach (['Static', 'Hub', 'HubCube', 'Spawn'] as $tag) { $check('Test.' . $tag, false); }
    $invoke('SSOverlayChanged_type_logicGrid', true);
    $invoke('SSOverlayChanged_type_logicGrid', false);
    foreach (['Static', 'Hub', 'HubCube', 'Spawn'] as $tag) { $check('Test.' . $tag, false); }
    $invoke('SSOverlayChanged_source_spawn', false);
    $invoke('SSOverlayChanged_source_spawn', true);
    foreach (['Static', 'Hub', 'HubCube', 'Spawn'] as $tag) { $check('Test.' . $tag, false); }
    $invoke('SSOverlayChanged_type_logicGrid', true);
    $invoke('SSOverlayChanged_source_spawn', false);
    $check('Test.Hub', true); $check('Test.Spawn', false);
    $invoke('SSOverlayChanged_type_hiddenCube', true);
    $check('Test.Static', true); $check('Test.HubCube', true); $check('Test.Hub', false); $check('Test.Spawn', false);
    $invoke('SSOverlayTypesNone');
    $checkBox('SSOverlayCheck_type_hiddenCube', false);
    foreach (['Static', 'Hub', 'HubCube', 'Spawn'] as $tag) { $check('Test.' . $tag, false); }
    if ($actualWidget) {
        $invoke('SSOverlayTogglePanel', true);
        $assertCondition(FilterCall($byteEqual, [FilterContext($getControl('SSOverlayPanel'), FilterCall($getVisibility, [], 'FinalFunction'), $nullPointer), FilterExpr('ByteConst', ['Value' => 0])]));
        $invoke('SSOverlayTogglePanel', false);
        $assertCondition(FilterCall($byteEqual, [FilterContext($getControl('SSOverlayPanel'), FilterCall($getVisibility, [], 'FinalFunction'), $nullPointer), FilterExpr('ByteConst', ['Value' => 1])]));
    }
    $check('Test.Control', true);
    if ($actualWidget) {
        $invoke('SSOverlayChanged_source_spawn', true);
        $invoke('SSOverlayChanged_type_hiddenCube', true);
        $check('Test.Static', true); $check('Test.HubCube', true); $check('Test.Spawn', true);
        // A fresh widget must reconcile its displayed defaults with markers
        // changed by an older widget instance, without an explicit Refresh call.
        $code[] = $let('TestWidget', FilterExpr('DynamicCast', ['ClassPtr' => $actualWidgetClass, 'TargetExpression' => FilterCall($createWidget, [
            FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $actualWidgetClass]), FilterExpr('ObjectConst', ['Value' => 0]),
        ])]));
        $check('Test.Static', $withRadarHud); $check('Test.Hub', false); $check('Test.HubCube', $withRadarHud); $check('Test.Spawn', $withRadarHud); $check('Test.Control', true);
        $checkBox('SSOverlayCheck_type_hiddenCube', $withRadarHud);
        $checkBox('SSOverlayCheck_source_spawn', $withRadarHud);
        if ($withRadarHud) {
            $checkBox('SSOverlayCheck_radar_enabled', false);
            $assertCondition(FilterCall($objectEqual, [$getControl('SSOverlayRadarState'), $local('TestRadar')]));
            $invoke('SSOverlayTypesNone');
            $assertCondition(FilterCall($nameEqual, [$getShared('SSRadarSelectedTag'), FilterExpr('NameConst', ['Value' => 'None'])]));
            foreach (['Static', 'Hub', 'HubCube', 'Spawn'] as $tag) { $check('Test.' . $tag, false); }
        }
    }
    $travel = static fn(string $name): object => FilterCall($openLevel, [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . $name]), FilterExpr('True'), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    $pass = $travel('SSOverlayPass');
    $fail = $travel('SSOverlayFail');
    $beforeTail = array_sum(array_map('FilterExprSize', $code));
    $passSize = FilterExprSize($pass);
    $failOffset = $beforeTail + $passSize + 2;
    foreach ($assertions as $offset) { $code[$offset]->CodeOffset = $failOffset; }
    $code[] = $pass;
    $code[] = FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]);
    $code[] = $fail;
    array_push($code, ...FilterReturn());
    $begin = FilterFunction($functions[0], $beginIndex, 1, 'ReceiveBeginPlay', [$emitterLocal, $testActorLocal, $tagResult, $testActors, $widgetLocal, $hudLocal, $radarLocal, $anyWidgetLocal, $testWidgets], []);
    $begin->SuperIndex = $receiveBeginPlay;
    $begin->SuperStruct = $receiveBeginPlay;
    $begin->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $begin->ScriptBytecode = $code;
    $begin->ScriptBytecodeSize = $beforeTail + $passSize * 2 + 5;
    $functions[] = $begin;
    foreach ($functions as $function) { $class->Children[] = $function->{'$index'}; $class->FuncMap[] = [$function->ObjectName, $function->{'$index'}]; $class->CreateBeforeSerializationDependencies[] = $function->{'$index'}; }
    $widget->Exports = [$class, $default, ...$functions];
    $widget->NameMap = array_values(array_unique(array_merge($widget->NameMap, ['BP_SSFilterTest_C', 'Default__BP_SSFilterTest_C', '/Game/SaveStatsTests/BP_SSFilterTest', 'ReceiveBeginPlay', 'TestEmitter', 'TestActor', 'TestActors', 'TestBoolean',
        'Test.Static', 'Test.Hub', 'Test.HubCube', 'Test.Spawn', 'Test.Control', 'TestWidget', 'TestHud', 'TestRadar', 'TestAnyWidget', 'TestWidgets', '/Game/SaveStatsTests/SSOverlayPass', '/Game/SaveStatsTests/SSOverlayFail'], array_column($widget->Imports, 'ObjectName'))));
    FixtureSaveAsset($outputDirectory . '/BP_SSFilterTest.json', $widget);

    $point = (object)['index' => 0, 'role' => 'point', 'label' => 'Fixture', 'x' => 0.0, 'y' => 0.0, 'z' => 0.0];
    $record = static fn(int $pid, string $group, string $type): object => (object)['pid' => $pid, 'group' => $group, 'ptype' => $type, 'iconKey' => $type, 'label' => 'Fixture ' . $pid,
        'geometry' => 'point', 'x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'locations' => [$point]];
    $spawn = (object)['siteId' => 'site-0123456789abcdef', 'group' => 'spawn', 'ptype' => 'spawnSite', 'iconKey' => 'spawnSite', 'label' => 'Fixture site',
        'allowedTypes' => ['hiddenCube', 'logicGrid'], 'x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'locations' => [$point]];
    $world = InjectOverlayMarkers($world, (object)['schemaVersion' => 2, 'markers' => (object)['200' => $record(200, 'static', 'hiddenCube'), '201' => $record(201, 'hub', 'logicGrid'), '202' => $record(202, 'hub', 'hiddenCube')], 'spawnSites' => (object)[$spawn->siteId => $spawn]]);
    $markerIndices = [];
    foreach ($world->Exports as $i => $export) {
        if (str_starts_with($export->ObjectName, 'SaveStatsMarker_') || str_starts_with($export->ObjectName, 'SaveStatsSite_')) { $markerIndices[] = $i + 1; }
    }
    foreach ($markerIndices as $i => $index) {
        foreach ($world->Exports[$index - 1]->Data as $field) { if ($field->Name === 'Tags') { $field->Value[] = OverlayProperty('Name', '0', 'Test.' . ['Static', 'Hub', 'HubCube', 'Spawn'][$i]); } }
    }
    $controlIndex = count($world->Exports) + 1;
    $controlMap = [$markerIndices[0] => $controlIndex, $markerIndices[0] + 1 => $controlIndex + 1, $markerIndices[0] + 2 => $controlIndex + 2];
    foreach (range(0, 2) as $offset) {
        $copy = unserialize(serialize($world->Exports[$markerIndices[0] - 1 + $offset]));
        $copy->{'$index'} = $controlIndex + $offset;
        if ($offset === 0) { $copy->ObjectName = 'SSFilterControl'; }
        foreach (['OuterIndex'] as $key) { if (isset($controlMap[$copy->$key])) { $copy->$key = $controlMap[$copy->$key]; } }
        foreach (['CreateBeforeSerializationDependencies', 'CreateBeforeCreateDependencies'] as $key) { $copy->$key = array_map(static fn(int $id): int => $controlMap[$id] ?? $id, $copy->$key); }
        $remapData = static function (mixed $data) use (&$remapData, $controlMap): mixed {
            if (is_object($data)) {
                if (($data->{'$type'} ?? '') === 'UAssetAPI.PropertyTypes.Objects.ObjectPropertyData, UAssetAPI' && isset($controlMap[$data->Value ?? null])) { $data->Value = $controlMap[$data->Value]; }
                foreach ($data as $key => $value) { $data->$key = $remapData($value); }
            } elseif (is_array($data)) { foreach ($data as $key => $value) { $data[$key] = $remapData($value); } }
            return $data;
        };
        $copy->Data = $remapData($copy->Data);
        if ($offset === 2) { foreach ($copy->Data as $field) { if ($field->Name === 'Marker') { foreach ($field->Value as $member) { if ($member->Name === 'VisibleOnMap') { $member->Value = 1; } } } } }
        if ($offset === 0) { foreach ($copy->Data as $field) { if ($field->Name === 'Tags') { $field->Value = [OverlayProperty('Name', '0', 'Test.Control')]; } } }
        $world->Exports[] = $copy;
    }
    $worldEngine = OverlayImport($world, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    $mode = OverlayImport($world, 'GameModeBase', $worldEngine, '/Script/CoreUObject', 'Class');
    $testPackage = OverlayImport($world, '/Game/SaveStatsTests/BP_SSFilterTest', 0, '/Script/CoreUObject', 'Package');
    $testClass = OverlayImport($world, 'BP_SSFilterTest_C', $testPackage, '/Script/Engine', 'BlueprintGeneratedClass');
    $testDefault = OverlayImport($world, 'Default__BP_SSFilterTest_C', $testPackage, '/Game/SaveStatsTests/BP_SSFilterTest', 'BP_SSFilterTest_C');
    $level = null;
    $settings = null;
    $worldExport = null;
    foreach ($world->Exports as $i => $export) {
        if ($export->ObjectName === 'PersistentLevel') { $level = $export; $levelIndex = $i + 1; }
        if ($export->ObjectName === 'WorldSettings_0') { $settings = $export; $settingsIndex = $i + 1; }
        if ($export->ObjectName === 'RRMod_Objects') { $worldExport = $export; }
    }
    $driverIndex = count($world->Exports) + 1;
    $world->Exports[] = OverlayExport($world->Exports[$markerIndices[0] - 1], $driverIndex, 'SSOverlayTestDriver', $levelIndex, $testClass, $testDefault, []);
    $world->Exports[array_key_last($world->Exports)]->Extras = '';
    $settings->Data[] = OverlayProperty('Object', 'DefaultGameMode', $mode);
    $settings->CreateBeforeSerializationDependencies[] = $mode;
    foreach ($level->Data as $field) { if ($field->Name === 'WorldSettings') { $field->Value = $settingsIndex; } }
    $level->Actors = [$settingsIndex, ...$markerIndices, $controlIndex, $driverIndex];
    $level->CreateBeforeSerializationDependencies = array_merge([$level->Model], $level->Actors);
    $world->NameMap = array_values(array_unique(array_merge($world->NameMap, ['Login', 'SSFilterControl', 'SSOverlayTestDriver', 'DefaultGameMode',
        '/Game/ASophia/Maps/Login', 'SSOverlayPass', 'SSOverlayFail', '/Game/SaveStatsTests/SSOverlayPass', '/Game/SaveStatsTests/SSOverlayFail', 'Test.Static', 'Test.Hub', 'Test.HubCube', 'Test.Spawn', 'Test.Control'], array_column($world->Imports, 'ObjectName'))));
    foreach (['Login' => '/Game/ASophia/Maps/Login', 'SSOverlayPass' => '/Game/SaveStatsTests/SSOverlayPass', 'SSOverlayFail' => '/Game/SaveStatsTests/SSOverlayFail'] as $name => $package) {
        $copy = unserialize(serialize($world));
        foreach ($copy->Exports as $export) {
            if ($export->ObjectName === 'RRMod_Objects') { $export->ObjectName = $name; }
            if ($export->ObjectName === 'PersistentLevel') {
                $export->URL->Map = $package;
                if ($name !== 'Login') { $export->Actors = [$settingsIndex]; $export->CreateBeforeSerializationDependencies = [$level->Model, $settingsIndex]; }
            }
        }
        // An orphan Actor export can be registered by native level loading, so
        // exclude the driver export entirely from terminal worlds.
        if ($name !== 'Login') { array_pop($copy->Exports); }
        FixtureSaveAsset($outputDirectory . '/' . $name . '.json', $copy);
    }
    return ['assertions' => count($assertions), 'handlerCalls' => $calls, 'passMap' => '/Game/SaveStatsTests/SSOverlayPass', 'failMap' => '/Game/SaveStatsTests/SSOverlayFail',
        'scriptSize' => $begin->ScriptBytecodeSize, 'generatedFunctionCount' => count($generatedFunctions), 'actualWidget' => $actualWidget, 'withRadarHud' => $withRadarHud];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['widget:', 'world:', 'output-dir:', 'actual-widget', 'with-radar-hud']);
        if (!isset($args['widget'], $args['world'], $args['output-dir']) || !is_dir($args['output-dir'])) { throw new RuntimeException('Provide --widget JSON --world JSON --output-dir EXISTING_DIR.'); }
        $result = BuildFilterRuntimeFixture(realpath($args['output-dir']), realpath($args['widget']), realpath($args['world']), isset($args['actual-widget']), isset($args['with-radar-hud']));
        file_put_contents($args['output-dir'] . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
