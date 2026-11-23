<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/radar_timer_fixture.php';

/** Actual HUD embedding/map delegates plus automatic local-pawn timer. */
function BuildRadarEmbeddedFixture(string $output, string $world, string $hudPath, string $mapPath, bool $staleHud = false, bool $geometry = false, bool $probeDiscovery = false, bool $replaceHud = false, bool $viewControls = false): array
{
    BuildRadarTimerFixture($output, $world);
    copy($hudPath, $output . '/WBP_GameHUD.json');
    copy($mapPath, $output . '/WBP_LargeMap.json');
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $driver = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset = $driver; $b->class = $driver->Exports[0]; $b->default = $driver->Exports[1];
    foreach ($b->class->FuncMap as [$name, $id]) { $b->functions[$name] = $id; }
    $b->reserve('FixtureFinish');
    if ($staleHud) { $b->reserve('BuildCurrentHUD'); }
    if ($replaceHud) { $b->reserve('VerifyReplacement'); $b->reserve('FixtureSuccess'); }
    if ($viewControls) { foreach (['ShowCachedMap', 'VerifyCachedMap', 'FinishMapControls', 'ContinueReplacement'] as $name) { $b->reserve($name); } }
    $radarPackage = OverlayImport($b->asset, '/Game/SaveStats/WBP_SSOverlayRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSOverlayRadar_C', $radarPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $hudPackage = OverlayImport($b->asset, '/Game/ASophia/UI/HUD/WBP_GameHUD', 0, '/Script/CoreUObject', 'Package');
    $hudClass = OverlayImport($b->asset, 'WBP_GameHUD_C', $hudPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $mapPackage = OverlayImport($b->asset, '/Game/ASophia/UI/HUD/Map/WBP_LargeMap', 0, '/Script/CoreUObject', 'Package');
    $mapClass = OverlayImport($b->asset, 'WBP_LargeMap_C', $mapPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    foreach (['TimerHud' => $hudClass, 'TimerStaleHud' => $hudClass, 'TimerPreviousHud' => $hudClass, 'TimerMap' => $mapClass] as $name => $class) { $b->objectField($name, $class); }
    $b->arrayField('Object', 'TimerCandidates', []);
    foreach ($b->class->LoadedProperties as $p) { if ($p->Name === 'TimerCandidates') { $p->Inner->PropertyClass = $b->native['UserWidget']; } }
    $b->native['CheckBox'] = OverlayImport($b->asset, 'CheckBox', $b->native['UMG'], '/Script/CoreUObject', 'Class');
    $checkSignature = OverlayImport($b->asset, 'OnCheckBoxComponentStateChanged__DelegateSignature', $b->native['UMG'], '/Script/CoreUObject', 'Function');
    $nativeGeometry = OverlayImport($b->asset, 'Geometry', OverlayImport($b->asset, '/Script/SlateCore', 0, '/Script/CoreUObject', 'Package'), '/Script/CoreUObject', 'ScriptStruct');
    $b->native['Vector2D'] = OverlayImport($b->asset, 'Vector2D', $b->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct');
    $b->native['SlateBlueprintLibrary'] = OverlayImport($b->asset, 'SlateBlueprintLibrary', $b->native['UMG'], '/Script/CoreUObject', 'Class');
    $s = static fn(string $name): object => FilterVariable(1, $name, true);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer(1, $name), 'Variable' => $s($name), 'Expression' => $value]);
    $int = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $float = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $n, object ...$args): object => $b->call('KismetMathLibrary', $n, $args);
    $array = static fn(string $n, object ...$args): object => $b->call('KismetArrayLibrary', $n, $args);
    $get = static fn(string $n): object => RadarContext($s('TimerRadar'), FilterVariable($radarClass, $n, true), FilterPointer($radarClass, $n));
    $mapGet = static fn(string $n): object => RadarContext($s('TimerMap'), FilterVariable($mapClass, $n, true), FilterPointer($mapClass, $n));
    $outer = static fn(object $o): object => $b->call('KismetSystemLibrary', 'GetOuterObject', [$o]);
    $create = static fn(int $class): object => FilterExpr('DynamicCast', ['ClassPtr' => $class, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $class]), $s('TimerController')])]);
    $checks = [];
    $assert = static function (object $condition, string $name) use (&$checks): object { $checks[] = $name; return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $condition]); };
    $travel = static fn(bool $pass): object => FilterCall($b->functions['FixtureFinish'], [$bool($pass)], 'LocalFinalFunction');
    $decode = static function (array $code) use ($travel): array {
        $result = [];
        foreach ($code as $e) {
            if (str_contains($e->{'$type'}, 'EX_JumpIfNot,')) { $e->__target = 'Fail'; }
            if (str_contains($e->{'$type'}, 'EX_CallMath,') && in_array(($e->Parameters[1]->Value ?? ''), ['/Game/SaveStatsTests/SSRadarFail', '/Game/SaveStatsTests/SSRadarPass'], true)) {
                $passed = $e->Parameters[1]->Value === '/Game/SaveStatsTests/SSRadarPass';
                if (!$passed) { $result[] = ['label' => 'Fail']; }
                $result[] = $travel($passed);
                continue;
            }
            if (str_contains($e->{'$type'}, 'EX_EndOfScript,')) { continue; }
            $result[] = $e;
        }
        return $result;
    };
    $setup = $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $original = $decode($setup->ScriptBytecode);
    $setupName = $staleHud ? 'BuildCurrentHUD' : 'ReceiveBeginPlay';
    $fn = $b->functions[$setupName];
    $local = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $local($n), 'Expression' => $e]);
    $embedded = [];
    if ($staleHud) {
        $embedded[] = $b->method($s('TimerStaleHud'), 'Widget', 'RemoveFromParent');
    }
    array_push($embedded, $set('TimerHud', $create($hudClass)), $b->method($s('TimerHud'), 'UserWidget', 'AddToViewport', [$int(0)]),
        $b->call('WidgetBlueprintLibrary', 'GetAllWidgetsOfClass', [FilterExpr('Self'), $s('TimerCandidates'), FilterExpr('ObjectConst', ['Value' => $radarClass]), $bool(false)]),
        $assert($math('EqualEqual_IntInt', $array('Array_Length', $s('TimerCandidates')), $int($staleHud ? 2 : 1)), 'radar candidate count matches HUD instances'), $let('CandidateIndex', $int(0)), ['label' => 'Find'],
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Missing', 'BooleanExpression' => $math('Less_IntInt', $local('CandidateIndex'), $array('Array_Length', $s('TimerCandidates')))]),
        $array('Array_Get', $s('TimerCandidates'), $local('CandidateIndex'), $local('Candidate')),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Next', 'BooleanExpression' => $math('EqualEqual_ObjectObject', $outer($outer($local('Candidate'))), $s('TimerHud'))]),
        $set('TimerRadar', FilterExpr('DynamicCast', ['ClassPtr' => $radarClass, 'TargetExpression' => $local('Candidate')])), FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Found']),
        ['label' => 'Next'], $let('CandidateIndex', $math('Add_IntInt', $local('CandidateIndex'), $int(1))), FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Find']),
        ['label' => 'Missing'], FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Fail']), ['label' => 'Found'],
        $set('TimerMap', $create($mapClass)), $assert($math('EqualEqual_ObjectObject', $mapGet('SSOverlayRadarState'), $s('TimerRadar')), 'map reference is the radar belonging to viewport HUD'));
    $findParts = [];
    $finding = false;
    foreach ($embedded as $e) {
        if (is_object($e) && ($e->StackNode ?? null) === $b->native['WidgetBlueprintLibrary.GetAllWidgetsOfClass']) { $finding = true; }
        if ($finding) { $findParts[] = $e; }
        if (is_array($e) && ($e['label'] ?? '') === 'Found') { break; }
    }
    // A replacement adds another detached instance, so only the ownership
    // search is reused; the initial exact candidate-count assertion is not.
    array_splice($findParts, 1, 1);
    // RadarCompile resolves/unsets labels on its input expression objects.
    // Preserve an independent assembly block before compiling the first use.
    $findParts = unserialize(serialize($findParts));
    $checkbox = static function (string $name, bool $checked = true) use ($b, $s, $mapGet, $bool, $checkSignature): array {
        $control = $mapGet($name);
        return [$b->method($control, 'CheckBox', 'SetIsChecked', [$bool($checked)]), FilterExpr('CallMulticastDelegate', ['StackNode' => $checkSignature,
            'Delegate' => RadarContext($control, FilterVariable($b->native['CheckBox'], 'OnCheckStateChanged', true), FilterPointer($b->native['CheckBox'], 'OnCheckStateChanged')), 'Parameters' => [$bool($checked)]])];
    };
    $embedded = [...$embedded, ...$checkbox('SSOverlayCheck_type_hiddenCube'), ...$checkbox('SSOverlayCheck_radar_enabled'),
        $assert($math('EqualEqual_IntInt', $get('SSRadarSelectedMask'), FilterExpr('IntConst', ['Value' => OverlayTypeBit('hiddenCube')])), 'actual map delegate selected rendered radar type'),
        $assert($get('SSRadarEnabled'), 'actual map checkbox enabled rendered radar')];
    if ($probeDiscovery) {
        // Stop immediately before the map-to-visible-owner identity assertion.
        $identity = null;
        foreach ($embedded as $n => $e) {
            if (is_object($e) && ($e->{'$type'} ?? '') === 'UAssetAPI.Kismet.Bytecode.Expressions.EX_JumpIfNot, UAssetAPI'
                && isset($e->BooleanExpression->Parameters[0]->ObjectExpression->Variable->New->Path)
                && $e->BooleanExpression->Parameters[0]->ObjectExpression->Variable->New->Path === ['TimerMap']) { $identity = $n; break; }
        }
        if ($identity === null) { throw new RuntimeException('Cannot locate owner identity probe boundary.'); }
        $embedded = [...array_slice($embedded, 0, $identity), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')])];
    }
    $tailParts = array_slice($original, 11);
    if ($staleHud) { $tailParts = RemapFilterFunction($tailParts, [$b->functions['ReceiveBeginPlay'] => $fn]); }
    $setupParts = [...($staleHud ? [] : array_slice($original, 0, 7)), ...$embedded, ...$tailParts];
    $b->define($setupName, [], ['Timer' => 'Struct', 'CandidateIndex' => 'Int', 'Candidate' => 'Object'], $setupParts);
    if ($staleHud) {
        $beginId = $b->functions['ReceiveBeginPlay'];
        $beginParts = [...array_slice($original, 0, 7), $set('TimerStaleHud', $create($hudClass)), $b->method($s('TimerStaleHud'), 'UserWidget', 'AddToViewport', [$int(0)]),
            FilterExpr('Let', ['Value' => FilterPointer($beginId, 'Timer'), 'Variable' => FilterVariable($beginId, 'Timer'),
                'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'BuildCurrentHUD']), $float(0.2), $bool(false), $float(0), $float(0)])]),
            FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
        $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct'], $beginParts);
    }
    $setup = $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $setup->SuperStruct = $setup->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $b->native['Actor'], '/Script/CoreUObject', 'Function');
    $setup->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $setup->SerializationBeforeSerializationDependencies = [$setup->SuperStruct];

    $verify = $b->asset->Exports[$b->functions['VerifyAutomatic'] - 1];
    $verifyOriginal = $decode($verify->ScriptBytecode);
    // Keep timer, pose, candidate and canvas checks. A nested UserWidget is not
    // top-level in the viewport; validate its owning HUD instead.
    $verifyOriginal[1] = $assert($b->method($s('TimerHud'), 'UserWidget', 'IsInViewport'), 'gameplay HUD is attached to viewport');
    $verifyParts = array_slice($verifyOriginal, 0, 8);
    foreach (['TimerHud' => $s('TimerHud'), 'Radar' => $s('TimerRadar'), 'Canvas' => $get('SSRadarCanvas'), 'Parent' => $b->method($s('TimerRadar'), 'Widget', 'GetParent')] as $name => $object) {
        $verifyParts[] = $assert($b->method($object, 'Widget', 'IsVisible'), $name . ' local visibility true');
        $verifyParts[] = $assert($math('Greater_FloatFloat', $b->method($object, 'Widget', 'GetRenderOpacity'), $float(0)), $name . ' render opacity positive');
    }
    $fn = $b->functions['VerifyAutomatic'];
    $local = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $local($n), 'Expression' => $e]);
    if ($geometry) {
        foreach (['HUD' => $s('TimerHud'), 'Radar' => $s('TimerRadar'), 'Canvas' => $get('SSRadarCanvas')] as $name => $object) {
            $verifyParts[] = $let('WidgetGeometry', $b->method($object, 'Widget', 'GetCachedGeometry', [], FilterPointer($fn, 'WidgetGeometry')));
            $verifyParts[] = $let('Translation', $b->call('SlateBlueprintLibrary', 'GetLocalSize', [$local('WidgetGeometry')]));
            foreach (['X', 'Y'] as $axis) {
                $component = FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['Vector2D'], $axis), 'StructExpression' => $local('Translation')]);
                $verifyParts[] = $assert($math('Greater_FloatFloat', $component, $float(0)), $name . ' cached geometry ' . $axis . ' positive');
            }
        }
    }
    // Request a regular UI screenshot, then leave the widget alive until a
    // later frame consumes the screenshot request.
    $verifyParts[] = $b->call('KismetSystemLibrary', 'ExecuteConsoleCommand', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'Shot SHOWUI']), $s('TimerController')]);
    $verifyParts[] = $let('Timer', $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'VerifyRestart']), $float(0.4), $bool(false), $float(0), $float(0)]));
    array_push($verifyParts, FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false));
    $b->define('VerifyAutomatic', [], ['Timer' => 'Struct', 'WidgetGeometry' => 'Struct', 'Translation' => 'Struct'], $verifyParts);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) { if ($p->Name === 'WidgetGeometry') { $p->Struct = $nativeGeometry; $p->ElementSize = 56; } }
    $b->define('VerifyRestart', [], [], [$travel(true)]);
    if ($replaceHud) {
        $restartName = $viewControls ? 'ContinueReplacement' : 'VerifyRestart';
        $fn = $b->functions[$restartName];
        $replacementFind = RemapFilterFunction(unserialize(serialize($findParts)), [$b->functions[$setupName] => $fn]);
        $restart = [$assert($b->call('KismetSystemLibrary', 'IsValid', [$mapGet('SSOverlayRadarState')]), 'cached radar remains a valid object before HUD replacement'),
            $set('TimerPreviousHud', $s('TimerHud')), $b->method($s('TimerHud'), 'Widget', 'RemoveFromParent'),
            $set('TimerHud', $create($hudClass)), $b->method($s('TimerHud'), 'UserWidget', 'AddToViewport', [$int(0)]), ...$replacementFind,
            $assert($math('NotEqual_ObjectObject', $mapGet('SSOverlayRadarState'), $s('TimerRadar')), 'existing map still caches the previous valid radar before Refresh'),
            ...$checkbox('SSOverlayCheck_source_spawn'),
            $assert($math('EqualEqual_ObjectObject', $mapGet('SSOverlayRadarState'), $s('TimerRadar')), 'Refresh rebinds existing map to replacement viewport HUD'),
            $assert($math('EqualEqual_IntInt', $get('SSRadarSelectedMask'), FilterExpr('IntConst', ['Value' => OverlayTypeBit('hiddenCube')])), 'rebind preserves selected type'),
            $assert($get('SSRadarIncludeSites'), 'rebind preserves possible-sites option'), $assert($get('SSRadarEnabled'), 'rebind preserves enabled option'),
            FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'VerifyReplacement']), $float(0.5), $bool(false), $float(0), $float(0)])]),
            FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
        if ($viewControls) {
            array_splice($restart, count($restart) - 5, 0, [
                $assert($math('EqualEqual_IntInt', $get('SSRadarZoomIndex'), $int(2)), 'HUD replacement preserves 200 m range'),
                $assert($math('EqualEqual_IntInt', $get('SSRadarSizeIndex'), $int(2)), 'HUD replacement preserves 150 percent size'),
            ]);
        }
        $b->define($restartName, [], ['Timer' => 'Struct', 'CandidateIndex' => 'Int', 'Candidate' => 'Object'], $restart);
        $fn = $b->functions['VerifyReplacement'];
        $replacement = [$assert($b->call('KismetSystemLibrary', 'K2_IsTimerActive', [$s('TimerRadar'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]), 'replacement radar native timer runs'),
            $assert($b->method($s('TimerHud'), 'UserWidget', 'IsInViewport'), 'replacement gameplay HUD is in viewport'),
            $assert($math('EqualEqual_ByteByte', $b->method($get('SSRadarCanvas'), 'Widget', 'GetVisibility'), FilterExpr('ByteConst', ['Value' => 3])), 'replacement radar automatically becomes visible'),
            $assert($math('NearlyEqual_FloatFloat', $get('SSRadarPlayerX'), $float(3000), $float(0.1)), 'replacement radar reads possessed character pose'),
            $b->call('KismetSystemLibrary', 'ExecuteConsoleCommand', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'Shot SHOWUI']), $s('TimerController')]),
            FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'FixtureSuccess']), $float(0.4), $bool(false), $float(0), $float(0)])]),
            FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
        $b->define('VerifyReplacement', [], ['Timer' => 'Struct'], $replacement);
        $b->define('FixtureSuccess', [], [], [$travel(true)]);
    }
    if ($viewControls) {
        $virtual = static fn(string $name, array $parameters = []): object => RadarContext($s('TimerRadar'), FilterExpr('VirtualFunction', ['VirtualFunctionName' => $name, 'Parameters' => $parameters]));
        $timer = static function (string $owner, string $callback, float $delay) use ($b, $float, $bool): object {
            $id = $b->functions[$owner];
            return FilterExpr('Let', ['Value' => FilterPointer($id, 'Timer'), 'Variable' => FilterVariable($id, 'Timer'),
                'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $float($delay), $bool(false), $float(0), $float(0)])]);
        };
        $end = static fn(): array => [FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)];
        $textBlock = OverlayImport($b->asset, 'TextBlock', $b->native['UMG'], '/Script/CoreUObject', 'Class');
        $b->native['TextBlock'] = $textBlock;
        $b->native['KismetTextLibrary'] = OverlayImport($b->asset, 'KismetTextLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
        $buttonClass = OverlayImport($b->asset, 'Button', $b->native['UMG'], '/Script/CoreUObject', 'Class');
        $buttonSignature = OverlayImport($b->asset, 'OnButtonClickedEvent__DelegateSignature', $b->native['UMG'], '/Script/CoreUObject', 'Function');
        $click = static function (string $name) use ($mapGet, $buttonClass, $buttonSignature): object {
            return FilterExpr('CallMulticastDelegate', ['StackNode' => $buttonSignature,
                'Delegate' => RadarContext($mapGet($name), FilterVariable($buttonClass, 'OnClicked', true), FilterPointer($buttonClass, 'OnClicked')), 'Parameters' => []]);
        };
        $cachedId = $b->functions['VerifyCachedMap'];
        $captureText = static fn(string $name): object => FilterExpr('Let', ['Value' => FilterPointer($cachedId, 'LabelText'), 'Variable' => FilterVariable($cachedId, 'LabelText'),
            'Expression' => $b->method($mapGet($name), 'TextBlock', 'GetText', [], FilterPointer($cachedId, 'LabelText'))]);
        $textEquals = static fn(string $expected): object => $b->call('KismetTextLibrary', 'EqualEqual_TextText', [
            FilterVariable($cachedId, 'LabelText'), $b->call('KismetTextLibrary', 'Conv_StringToText', [FilterExpr('StringConst', ['Value' => $expected])])]);
        $b->define('VerifyRestart', [], ['Timer' => 'Struct'], [
            $b->method($s('TimerMap'), 'UserWidget', 'AddToViewport', [$int(10)]),
            $click('SSOverlayTabPuzzles'),
            $b->method($s('TimerMap'), 'Widget', 'SetVisibility', [FilterExpr('ByteConst', ['Value' => 1])]),
            $virtual('SSRadarSetView', [$int(0), $int(2)]), $virtual('SSRadarToggleEnabled'),
            $assert($math('Not_PreBool', $get('SSRadarEnabled')), 'canonical shortcut toggle disables radar while cached map is collapsed'),
            $timer('VerifyRestart', 'ShowCachedMap', 0.2), ...$end(),
        ]);
        $b->define('ShowCachedMap', [], ['Timer' => 'Struct'], [
            $b->method($s('TimerMap'), 'Widget', 'SetVisibility', [FilterExpr('ByteConst', ['Value' => 0])]),
            $timer('ShowCachedMap', 'VerifyCachedMap', 0.5), ...$end(),
        ]);
        $cached = [
            $assert($math('EqualEqual_IntInt', $mapGet('SSOverlayRadarZoomIndex'), $int(0)), 'same cached map automatically reads 50 m keyboard state after becoming visible'),
            $assert($math('EqualEqual_IntInt', $mapGet('SSOverlayRadarSizeIndex'), $int(2)), 'same cached map automatically reads 150 percent keyboard state'),
            $assert($math('Not_PreBool', $b->method($mapGet('SSOverlayCheck_radar_enabled'), 'CheckBox', 'IsChecked')), 'same cached map automatically unchecks Show radar'),
            $captureText('SSOverlayRadarRangeText'), $assert($textEquals('Radar range: 50 m'), 'range label automatically reflects canonical radius'),
            $captureText('SSOverlayRadarSizeText'), $assert($textEquals('Size: 150%'), 'size label automatically reflects canonical size'),
            $b->call('KismetSystemLibrary', 'ExecuteConsoleCommand', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => 'Shot SHOWUI']), $s('TimerController')]),
            $click('SSOverlayRadarRange1'), $assert($math('EqualEqual_IntInt', $get('SSRadarZoomIndex'), $int(1)), 'real 100 m range choice selects 100 m'),
            $click('SSOverlayRadarRange0'), $assert($math('EqualEqual_IntInt', $get('SSRadarZoomIndex'), $int(0)), 'real 50 m range choice selects 50 m'),
            $click('SSOverlayRadarSize0'), $assert($math('EqualEqual_IntInt', $get('SSRadarSizeIndex'), $int(0)), 'real 100 percent size choice selects 100 percent'),
            ...$checkbox('SSOverlayCheck_type_matchbox'),
            $assert($math('EqualEqual_IntInt', $get('SSRadarSelectedMask'), FilterExpr('IntConst', ['Value' => OverlayTypeBit('hiddenCube') | OverlayTypeBit('matchbox')])), 'adding a type publishes the combined selection'),
            $assert($math('Not_PreBool', $get('SSRadarEnabled')), 'type change retains shortcut-disabled radar'),
            $assert($math('EqualEqual_IntInt', $get('SSRadarZoomIndex'), $int(0)), 'type change retains current radius'),
            $assert($math('EqualEqual_IntInt', $get('SSRadarSizeIndex'), $int(0)), 'type change retains current size'),
            ...$checkbox('SSOverlayCheck_type_hiddenCube'), ...$checkbox('SSOverlayCheck_radar_enabled'),
            $virtual('SSRadarSetView', [$int(2), $int(2)]),
            $timer('VerifyCachedMap', 'FinishMapControls', 0.4), ...$end(),
        ];
        $b->define('VerifyCachedMap', [], ['Timer' => 'Struct', 'LabelText' => 'Text'], $cached);
        $b->define('FinishMapControls', [], ['Timer' => 'Struct'], [
            $b->method($s('TimerMap'), 'Widget', 'SetVisibility', [FilterExpr('ByteConst', ['Value' => 1])]),
            $timer('FinishMapControls', 'ContinueReplacement', 0.2), ...$end(),
        ]);
        if (!$replaceHud) { $b->define('ContinueReplacement', [], [], [$travel(true)]); }
    }
    // Remove both active and detached test HUD trees and clear each nested
    // radar timer before level travel. This is test teardown, not a product fix.
    $fn = $b->functions['FixtureFinish'];
    $local = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $e): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $local($n), 'Expression' => $e]);
    $finish = [$let('Index', $int(0)), ['label' => 'Loop'], FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Owners', 'BooleanExpression' => $math('Less_IntInt', $local('Index'), $array('Array_Length', $s('TimerCandidates')))]),
        $array('Array_Get', $s('TimerCandidates'), $local('Index'), $local('Candidate')),
        $b->call('KismetSystemLibrary', 'K2_ClearTimer', [$local('Candidate'), FilterExpr('StringConst', ['Value' => 'SSRadarTick'])]),
        $b->method($local('Candidate'), 'Widget', 'RemoveFromParent'), $let('Index', $math('Add_IntInt', $local('Index'), $int(1))),
        FilterExpr('Jump', ['CodeOffset' => 0, '__target' => 'Loop']), ['label' => 'Owners']];
    foreach (['TimerMap', 'TimerHud', 'TimerStaleHud', 'TimerPreviousHud'] as $n => $name) {
        $finish[] = FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Owner' . $n, 'BooleanExpression' => $b->call('KismetSystemLibrary', 'IsValid', [$s($name)])]);
        $finish[] = $b->method($s($name), 'Widget', 'RemoveFromParent');
        $finish[] = ['label' => 'Owner' . $n];
    }
    $rawTravel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')]), $bool(true), FilterExpr('StringConst', ['Value' => '?game=/Script/Engine.GameModeBase'])]);
    array_push($finish, FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $local('Passed')]), $rawTravel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $rawTravel(false));
    $b->define('FixtureFinish', ['Passed' => 'Bool'], ['Index' => 'Int', 'Candidate' => 'Object'], $finish);
    // This harness replaces several functions from the timer-only driver.
    // Keep one class map/child entry per actual export after replacement.
    $b->class->FuncMap = $b->class->Children = [];
    foreach ($b->asset->Exports as $n => $e) {
        if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.FunctionExport, UAssetAPI') {
            $b->class->FuncMap[] = [$e->ObjectName, $n + 1];
            $b->class->Children[] = $n + 1;
        }
    }
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($b->finish(), JSON_THROW_ON_ERROR));
    $result = ['assertions' => $probeDiscovery ? 3 : 9 + count($checks), 'checks' => $checks, 'probeDiscovery' => $probeDiscovery, 'replaceHud' => $replaceHud, 'automaticTickOnly' => true, 'manualTickOrUpdateCalls' => 0, 'embeddedHud' => true, 'staleHud' => $staleHud,
        'geometryChecked' => $geometry, 'viewControlsChecked' => $viewControls, 'nativeScreenshotRequested' => true, 'nativePawnClass' => '/Script/IslandsofInsight.SophiaCharacter', 'passMap' => '/Game/SaveStatsTests/SSRadarPass', 'failMap' => '/Game/SaveStatsTests/SSRadarFail'];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['output-dir:', 'world:', 'hud-widget:', 'map-widget:', 'stale-hud', 'geometry', 'probe-discovery', 'replace-hud', 'view-controls']);
        foreach (['output-dir', 'world', 'hud-widget', 'map-widget'] as $name) { if (!isset($args[$name])) { throw new InvalidArgumentException('Provide --output-dir --world --hud-widget --map-widget'); } }
        echo json_encode(BuildRadarEmbeddedFixture(realpath($args['output-dir']), realpath($args['world']), realpath($args['hud-widget']), realpath($args['map-widget']), isset($args['stale-hud']), isset($args['geometry']), isset($args['probe-discovery']), isset($args['replace-hud']), isset($args['view-controls'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
