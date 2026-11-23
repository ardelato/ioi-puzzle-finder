<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/radar_runtime_fixture.php';

/** Add a test-only invisible child; preserve every existing HUD function/export. */
function AddHubAvailabilityProofToHud(object $base): object
{
    $asset = unserialize(serialize($base));
    $exports = array_column($asset->Exports, null, 'ObjectName');
    if (isset($exports['SSAvailabilityProof'])) { throw new RuntimeException('Proof child already exists.'); }
    $treeIndex = array_search($exports['WidgetTree'], $asset->Exports, true) + 1;
    $tree = $asset->Exports[$treeIndex - 1];
    $rootIndex = array_column($tree->Data, 'Value', 'Name')['RootWidget'];
    $root = $asset->Exports[$rootIndex - 1];
    $umg = OverlayImport($asset, '/Script/UMG', 0, '/Script/CoreUObject', 'Package');
    $package = OverlayImport($asset, '/Game/SaveStats/WBP_SSAvailabilityProof', 0, '/Script/CoreUObject', 'Package');
    $class = OverlayImport($asset, 'WBP_SSAvailabilityProof_C', $package, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $default = OverlayImport($asset, 'Default__WBP_SSAvailabilityProof_C', $package, '/Game/SaveStats/WBP_SSAvailabilityProof', 'WBP_SSAvailabilityProof_C');
    $slotClass = OverlayImport($asset, 'CanvasPanelSlot', $umg, '/Script/CoreUObject', 'Class');
    $slotDefault = OverlayImport($asset, 'Default__CanvasPanelSlot', $umg, '/Script/UMG', 'CanvasPanelSlot');
    $childIndex = count($asset->Exports) + 1; $slotIndex = $childIndex + 1;
    $child = OverlayExport($tree, $childIndex, 'SSAvailabilityProof', $treeIndex, $class, $default, [OverlayProperty('Object', 'Slot', $slotIndex), FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed')]);
    $child->Extras = ''; $child->CreateBeforeSerializationDependencies = [$slotIndex];
    $slot = OverlayExport($tree, $slotIndex, 'SSAvailabilityProofSlot', $rootIndex, $slotClass, $slotDefault, [
        FilterStruct('LayoutData', 'AnchorData', [FilterStruct('Offsets', 'Margin', [OverlayProperty('Float', 'Left', 0), OverlayProperty('Float', 'Top', 0), OverlayProperty('Float', 'Right', 0), OverlayProperty('Float', 'Bottom', 0)]),
            FilterStruct('Anchors', 'Anchors', [FilterVector('Minimum', 0, 0), FilterVector('Maximum', 0, 0)]), FilterVector('Alignment', 0, 0)]),
        OverlayProperty('Object', 'Parent', $rootIndex), OverlayProperty('Object', 'Content', $childIndex)]);
    $slot->Extras = ''; $slot->CreateBeforeSerializationDependencies = [$childIndex];
    $asset->Exports[] = $child; $asset->Exports[] = $slot;
    foreach ($root->Data as $field) { if ($field->Name === 'Slots') { $field->Value[] = OverlayProperty('Object', '0', $slotIndex); } }
    $root->CreateBeforeSerializationDependencies[] = $slotIndex; $tree->CreateBeforeSerializationDependencies[] = $childIndex;
    $asset->NameMap = array_values(array_unique([...$asset->NameMap, 'SSAvailabilityProof', 'SSAvailabilityProofSlot', 'ESlateVisibility::Collapsed',
        ...array_column($asset->Imports, 'ObjectName')]));
    return $asset;
}

/** Runs only after legitimate Offline Mode has initialized the real world. */
function BuildHubAvailabilityWorldFixture(string $output, string $build): array
{
    $repo = dirname(__DIR__, 2);
    $pak = $build . '/ZZ_IOIPuzzleRadar_P.pak';
    if (!is_file($pak)) { $pak = $build . '/ZZ_SaveStatsMap_P.pak'; }
    if (!is_file($pak)) { throw new RuntimeException('Missing production package in build directory.'); }
    $document = json_decode(file_get_contents($build . '/markers.json'), true, 512, JSON_THROW_ON_ERROR);
    $index = json_decode(file_get_contents($build . '/radar-data.json'), true, 512, JSON_THROW_ON_ERROR);
    $render = json_decode(file_get_contents($build . '/render-points.json'), true, 512, JSON_THROW_ON_ERROR)['renderPoints'];
    $byPid = []; $site = null; $static = null;
    foreach ($render as $point) {
        if ($point['group'] !== 'spawn' && $point['pointIndex'] === 0) { $byPid[$point['pid']] = $point; }
        if ($static === null && $point['group'] === 'static' && $point['ptype'] === 'seek5' && $point['pointIndex'] === 0) { $static = $point; }
        if ($site === null && $point['group'] === 'spawn') { $site = $point; }
    }
    foreach ([11118,11166,11144,11128,17201,17183] as $pid) { if (!isset($byPid[$pid])) { throw new RuntimeException('Missing actual production control ' . $pid); } }
    $other = array_values(array_filter($byPid, static fn(array $p): bool => $p['ptype'] === 'hiddenCube' && $p['hubAvailabilityZone'] > 0));
    $otherControls = [];
    for ($n = 0; $n < 8; $n++) { $p = $other[(int)floor($n * (count($other) - 1) / 7)]; $otherControls[$p['pid']] = $p; }
    $distant = array_values(array_filter($byPid, static fn(array $p): bool => $p['ptype'] === 'seek5' && $p['hubAvailabilityZone'] === 6));
    if ($static === null || $site === null || $distant === []) { throw new RuntimeException('Missing static/site/distant production controls.'); }
    $pointIndex = static function (array $point) use ($index): int {
        foreach ($index['pointIds'] as $n => $id) { if ($id === $point['actorName']) { return $n; } }
        throw new RuntimeException('Production point absent from radar index.');
    };
    $siteType = null;
    foreach ($index['typeKeys'] as $type => $key) {
        $first = $index['offsets'][$type * $index['width'] * $index['height']];
        $end = $index['offsets'][($type + 1) * $index['width'] * $index['height']];
        if ($pointIndex($site) >= $first && $pointIndex($site) < $end) { $siteType = $key; break; }
    }
    if ($siteType === null) { throw new RuntimeException('Missing native site type segment.'); }
    $hubTypes = json_decode(file_get_contents($build . '/radar.json'), true, 512, JSON_THROW_ON_ERROR)['hubAvailability']['eligibleTypeTags'];
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->class->ObjectName = 'WBP_SSAvailabilityProof_C'; $b->default->ObjectName = 'Default__WBP_SSAvailabilityProof_C';
    $b->asset->PackageGuid = '{FD9BA78D-A43B-4417-8D16-9FDFB9B60343}';
    $b->asset->NameMap[1] = '/Game/SaveStats/WBP_SSAvailabilityProof';
    foreach (['SophiaGameplayStatics','PuzzleDatabase','PuzzleData','MarkerEmitter'] as $name) { $b->native[$name] = OverlayImport($b->asset, $name, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class'); }
    $b->native['LocationMarkerData'] = OverlayImport($b->asset, 'LocationMarkerData', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    $b->native['KismetStringLibrary'] = OverlayImport($b->asset, 'KismetStringLibrary', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $classes = [];
    foreach (['Radar' => ['/Game/SaveStats/WBP_SSOverlayRadar','WBP_SSOverlayRadar_C'], 'Map' => ['/Game/ASophia/UI/HUD/Map/WBP_LargeMap','WBP_LargeMap_C']] as $key => [$pkg, $class]) {
        $package = OverlayImport($b->asset, $pkg, 0, '/Script/CoreUObject', 'Package');
        $classes[$key] = OverlayImport($b->asset, $class, $package, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
        $b->objectField($key, $classes[$key]);
    }
    foreach (['Source' => 'PuzzleDatabase','Player' => 'SophiaCharacter','Controller' => 'Object','CurrentHud' => 'SophiaHUD'] as $name => $type) { $b->objectField($name, $b->native[$type]); }
    foreach (['Waits','ReadyWaits','LastCheck','FailureCheck','ControlPid','ChecksPassed','PositiveControls','NegativeControls','OtherNegativeControls','VisibleControls'] as $name) { $b->scalar('Int', $name, 0); }
    foreach (['Passed','Finished','DistantChosen','Observed11118Available'] as $name) { $b->scalar('Bool', $name, 0); }
    $b->scalar('Str','ProofType','seek5');
    foreach (['Candidates' => 'UserWidget', 'Actors' => 'Actor'] as $name => $type) { $b->arrayField('Object', $name, []); $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native[$type]; }
    foreach (['OnInitialized','WaitForReady','RunProof','CheckControl','ProbeCheck','Finish'] as $fn) { $b->reserve($fn); }
    $s = static fn(string $n): object => FilterVariable(1, $n, true);
    $i = static fn(int $n): object => FilterExpr('IntConst',['Value'=>$n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst',['Value'=>$n]);
    $truth = static fn(bool $v): object => FilterExpr($v?'True':'False');
    $str = static fn(string $v): object => FilterExpr('StringConst',['Value'=>$v]);
    $name = static fn(string $v): object => FilterExpr('NameConst',['Value'=>$v]);
    $math = static fn(string $n, object ...$args): object => $b->call('KismetMathLibrary',$n,$args);
    $array = static fn(string $n, object ...$args): object => $b->call('KismetArrayLibrary',$n,$args);
    $valid = static fn(object $o): object => $b->call('KismetSystemLibrary','IsValid',[$o]);
    $property = static fn(object $o,int $owner,string $n): object => RadarContext($o,FilterVariable($owner,$n,true),FilterPointer($owner,$n));
    $get = static fn(string $n): object => $property($s('Radar'),$classes['Radar'],$n);
    $mapGet = static fn(string $n): object => $property($s('Map'),$classes['Map'],$n);
    $set = static fn(string $n,object $v): object => FilterExpr('Let',['Value'=>FilterPointer(1,$n),'Variable'=>$s($n),'Expression'=>$v]);
    $put = static fn(object $o,int $owner,string $n,object $v): object => FilterExpr('Let',['Value'=>FilterPointer($owner,$n),'Variable'=>$property($o,$owner,$n),'Expression'=>$v]);
    $call = static fn(string $o,string $n,array $args=[],?object $ptr=null): object => RadarContext($s($o),FilterExpr('VirtualFunction',['VirtualFunctionName'=>$n,'Parameters'=>$args]),$ptr);
    $jump = static fn(string $label): object => FilterExpr('Jump',['CodeOffset'=>0,'__target'=>$label]);
    $unless = static fn(object $condition,string $label): object => FilterExpr('JumpIfNot',['CodeOffset'=>0,'__target'=>$label,'BooleanExpression'=>$condition]);
    $clearTimer = $b->call('KismetSystemLibrary','K2_ClearTimer',[FilterExpr('Self'),$str('WaitForReady')]);
    $fn=$b->functions['OnInitialized'];
    $b->define('OnInitialized',[],['Timer'=>'Struct'],[FilterExpr('Let',['Value'=>FilterPointer($fn,'Timer'),'Variable'=>FilterVariable($fn,'Timer'),
        'Expression'=>$b->call('KismetSystemLibrary','K2_SetTimer',[FilterExpr('Self'),$str('WaitForReady'),$f(0.25),$truth(true),$f(0),$f(0)])])],'OnInitialized');
    $fn=$b->functions['ProbeCheck']; $p=static fn(string $n):object=>FilterVariable($fn,$n);
    $b->define('ProbeCheck',['Condition'=>'Bool','Number'=>'Int'],[],[$set('LastCheck',$p('Number')),$unless($p('Condition'),'Failed'),$set('ChecksPassed',$math('Add_IntInt',$s('ChecksPassed'),$i(1))),$jump('Done'),['label'=>'Failed'],
        $unless($math('EqualEqual_IntInt',$s('FailureCheck'),$i(0)),'Done'),$set('FailureCheck',$p('Number')),['label'=>'Done'],FilterExpr('Return',['ReturnExpression'=>$p('Condition')])],null,'Bool');
    $fn=$b->functions['WaitForReady']; $l=static fn(string $n):object=>FilterVariable($fn,$n);
    $let=static fn(string $n,object $v):object=>FilterExpr('Let',['Value'=>FilterPointer($fn,$n),'Variable'=>$l($n),'Expression'=>$v]);
    $wait=[$set('Waits',$math('Add_IntInt',$s('Waits'),$i(1))),$unless($math('Less_IntInt',$s('Waits'),$i(800)),'Timeout'),
        $set('Player',FilterExpr('DynamicCast',['ClassPtr'=>$b->native['SophiaCharacter'],'TargetExpression'=>$b->call('GameplayStatics','GetPlayerCharacter',[FilterExpr('Self'),$i(0)])])),
        $unless($valid($s('Player')),'Done'),$set('Source',FilterExpr('DynamicCast',['ClassPtr'=>$b->native['PuzzleDatabase'],'TargetExpression'=>$b->call('SophiaGameplayStatics','GetPuzzleDatabase',[FilterExpr('Self')])])),
        $unless($valid($s('Source')),'Done'),$unless($property($s('Source'),$b->native['PuzzleDatabase'],'bInitialized'),'Done'),
        $unless($math('Greater_IntInt',$property($s('Source'),$b->native['PuzzleDatabase'],'groupLifespan'),$i(0)),'Done'),
        $set('Controller',$b->call('GameplayStatics','GetPlayerController',[FilterExpr('Self'),$i(0)])),$unless($valid($s('Controller')),'Done'),
        $set('CurrentHud',FilterExpr('DynamicCast',['ClassPtr'=>$b->native['SophiaHUD'],'TargetExpression'=>$b->method($s('Controller'),'PlayerController','GetHUD',[],FilterPointer($fn,'Candidate'))])),
        $unless($valid($s('CurrentHud')),'Done'),
        $b->call('WidgetBlueprintLibrary','GetAllWidgetsOfClass',[FilterExpr('Self'),$s('Candidates'),FilterExpr('ObjectConst',['Value'=>$classes['Radar']]),$truth(false)]),$let('Index',$i(0)),['label'=>'Find'],
        $unless($math('BooleanAND',$math('Less_IntInt',$l('Index'),$array('Array_Length',$s('Candidates'))),$math('Less_IntInt',$l('Index'),$i(64))),'Done'),
        $array('Array_Get',$s('Candidates'),$l('Index'),$l('Candidate')),
        $unless($math('EqualEqual_ObjectObject',$b->call('KismetSystemLibrary','GetOuterObject',[$b->call('KismetSystemLibrary','GetOuterObject',[$l('Candidate')])]),$property($s('CurrentHud'),$b->native['SophiaHUD'],'GameHUDBaseWidget')),'Next'),
        $set('Radar',FilterExpr('DynamicCast',['ClassPtr'=>$classes['Radar'],'TargetExpression'=>$l('Candidate')])),$jump('Found'),['label'=>'Next'],$let('Index',$math('Add_IntInt',$l('Index'),$i(1))),$jump('Find'),['label'=>'Found'],
        $b->call('GameplayStatics','GetAllActorsWithTag',[FilterExpr('Self'),$name('SaveStats.Puzzle.11118'),$s('Actors')]),$unless($math('Greater_IntInt',$array('Array_Length',$s('Actors')),$i(0)),'Done'),
        $set('ReadyWaits',$math('Add_IntInt',$s('ReadyWaits'),$i(1))),$unless($math('GreaterEqual_IntInt',$s('ReadyWaits'),$i(12)),'Done'),$clearTimer,FilterCall($b->functions['RunProof'],[],'LocalFinalFunction'),$jump('Done'),
        ['label'=>'Timeout'],$set('LastCheck',$i(-1)),$set('FailureCheck',$i(-1)),$clearTimer,FilterCall($b->functions['Finish'],[],'LocalFinalFunction'),['label'=>'Done']];
    $b->define('WaitForReady',[],['Candidate'=>'Object','Index'=>'Int'],$wait);
    $checks=[];
    $assert=static function(object $condition,string $description)use(&$checks,$b,$i,$unless):object{$checks[]=$description;return $unless(FilterCall($b->functions['ProbeCheck'],[$condition,$i(count($checks))],'LocalFinalFunction'),'Fail');};
    $fn=$b->functions['CheckControl'];$p=static fn(string $n):object=>FilterVariable($fn,$n);
    $let=static fn(string $n,object $v):object=>FilterExpr('Let',['Value'=>FilterPointer($fn,$n),'Variable'=>$p($n),'Expression'=>$v]);
    $objects=$b->method($s('Source'),'PuzzleDatabase','GetAllActiveSandboxPuzzleDataOfTypeInZone',[$s('ProofType'),FilterExpr('ByteConst',['Value'=>2]),$truth(true)],FilterPointer($fn,'NativeObjects'));
    $control=[$set('ControlPid',$p('Pid')),$let('Data',$b->call('SophiaGameplayStatics','GetPuzzleData',[FilterExpr('Self'),$p('Pid')])),FilterExpr('LetBool',['VariableExpression'=>$p('Expected'),'AssignmentExpression'=>$truth(true)]),
        $unless($math('Greater_IntInt',$p('Zone'),$i(0)),'NativeReady'),$assert($valid($p('Data')),'actual production control resolves native PuzzleData')];
    foreach(range(2,6)as$zone){$control=[...$control,$unless($math('EqualEqual_IntInt',$p('Zone'),$i($zone)),'Zone'.($zone+1)),
        $let('NativeObjects',$b->method($s('Source'),'PuzzleDatabase','GetAllActiveSandboxPuzzleDataOfTypeInZone',[$s('ProofType'),FilterExpr('ByteConst',['Value'=>$zone]),$truth(true)],FilterPointer($fn,'NativeObjects'))),
        FilterExpr('LetBool',['VariableExpression'=>$p('Expected'),'AssignmentExpression'=>$array('Array_Contains',$p('NativeObjects'),$p('Data'))]),$jump('NativeReady'),['label'=>'Zone'.($zone+1)]];}
    $control=[...$control,['label'=>'NativeReady'],
        $assert($math('EqualEqual_BoolBool',$call('Radar','SSRadarIsPuzzleAvailable',[$p('Pid'),$p('Zone')],FilterPointer($fn,'Boolean')),$p('Expected')),'shared cache equals actual native current-assignment query'),
        $unless($p('Expected'),'Negative'),$set('PositiveControls',$math('Add_IntInt',$s('PositiveControls'),$i(1))),$jump('CountsDone'),['label'=>'Negative'],
        $set('NegativeControls',$math('Add_IntInt',$s('NegativeControls'),$i(1))),$unless($math('NotEqual_IntInt',$get('SSRadarSelectedMask'),$i(OverlayTypeBit('seek5'))),'CountsDone'),
        $set('OtherNegativeControls',$math('Add_IntInt',$s('OtherNegativeControls'),$i(1))),['label'=>'CountsDone'],
        $unless($math('EqualEqual_IntInt',$p('Pid'),$i(11118)),'Not11118'),FilterExpr('LetBool',['VariableExpression'=>$s('Observed11118Available'),'AssignmentExpression'=>$p('Expected')]),['label'=>'Not11118'],
        FilterExpr('LetBool',['VariableExpression'=>$p('ExpectedVisible'),'AssignmentExpression'=>$math('BooleanAND',$p('Expected'),$math('Not_PreBool',$call('Radar','SSRadarIsSolved',[$p('Pid')],FilterPointer($fn,'Boolean'))))]),
        $unless($p('ExpectedVisible'),'NotVisible'),$set('VisibleControls',$math('Add_IntInt',$s('VisibleControls'),$i(1))),['label'=>'NotVisible'],
        $let('Loops',$i(0)),['label'=>'Scan'],$call('Radar','SSRadarUpdate',[$p('X'),$p('Y'),$p('Z'),$f(0),$truth(false),$f(0.1)]),
        $assert($math('LessEqual_IntInt',$get('SSRadarVisitedLastUpdate'),$i(128)),'production scan preserves128-candidate callback limit'),
        $assert($math('LessEqual_IntInt',$get('SSRadarAvailabilityQueriesLastRefresh'),$i(1)),'production refresh enumerates at most one zone per callback'),
        $unless($get('SSRadarScanActive'),'ScanDone'),$let('Loops',$math('Add_IntInt',$p('Loops'),$i(1))),$assert($math('Less_IntInt',$p('Loops'),$i(128)),'stable authoritative cache lets dense production scans finish'),$jump('Scan'),['label'=>'ScanDone'],
        $assert($math('EqualEqual_BoolBool',$array('Array_Contains',$get('SSRadarDisplayIndices'),$p('Point')),$p('ExpectedVisible')),'real production radar point obeys native availability and completion'),
        $unless($math('Not_PreBool',$p('Expected')),'StaleDone'),$array('Array_Clear',$get('SSRadarDisplayIndices')),$array('Array_AddUnique',$get('SSRadarDisplayIndices'),$p('Point')),$call('Radar','SSRadarRender'),
        $assert($math('EqualEqual_ByteByte',$b->method($get('SSRadarPin0'),'Widget','GetVisibility'),FilterExpr('ByteConst',['Value'=>1])),'committed stale unavailable production pin is hidden immediately'),['label'=>'StaleDone']];
    $actorTag=$b->call('KismetStringLibrary','Conv_StringToName',[$b->call('KismetStringLibrary','Concat_StrStr',[$str('SaveStats.Puzzle.'),$b->call('KismetStringLibrary','Conv_IntToString',[$p('Pid')])])]);
    $control=[...$control,$unless($math('Greater_IntInt',$p('Pid'),$i(0)),'SiteActors'),
        $b->call('GameplayStatics','GetAllActorsWithTag',[FilterExpr('Self'),$actorTag,$s('Actors')]),$jump('ActorList'),['label'=>'SiteActors'],
        $b->call('GameplayStatics','GetAllActorsWithTag',[FilterExpr('Self'),$name('SaveStats.Source.spawn'),$s('Actors')]),['label'=>'ActorList'],$let('Index',$i(0)),['label'=>'FindActor'],
        $assert($math('Less_IntInt',$p('Index'),$array('Array_Length',$s('Actors'))),'exact production marker actor is present in actual world'),
        $array('Array_Get',$s('Actors'),$p('Index'),$p('Actor')),
        $unless($b->call('KismetStringLibrary','EqualEqual_StrStr',[$b->call('KismetSystemLibrary','GetObjectName',[$p('Actor')]),$p('PointName')]),'ActorNext'),$jump('ActorFound'),
        ['label'=>'ActorNext'],$let('Index',$math('Add_IntInt',$p('Index'),$i(1))),$jump('FindActor'),['label'=>'ActorFound'],
        $assert($math('EqualEqual_BoolBool',$call('Map','SSOverlayIsActorAvailable',[$p('Actor')],FilterPointer($fn,'Boolean')),$p('Expected')),'actual map actor HubZone/PID tags drive the same native availability result'),
        $let('Emitter',$b->method($p('Actor'),'Actor','GetComponentByClass',[FilterExpr('ObjectConst',['Value'=>$b->native['MarkerEmitter']])],FilterPointer($fn,'Emitter'))),$assert($valid($p('Emitter')),'actual production marker has native MarkerEmitter')];
    $visible=static fn():object=>FilterExpr('StructMemberContext',['StructMemberExpression'=>FilterPointer($b->native['LocationMarkerData'],'VisibleOnMap'),'StructExpression'=>$property($p('Emitter'),$b->native['MarkerEmitter'],'Marker')]);
    $control=[...$control,$assert($math('EqualEqual_BoolBool',$visible(),$p('ExpectedVisible')),'full map refresh applies actual native availability to real marker emitter'),
        FilterExpr('LetBool',['VariableExpression'=>$visible(),'AssignmentExpression'=>$math('Not_PreBool',$p('ExpectedVisible'))]),
        $put($s('Map'),$classes['Map'],'SSOverlayProgressCursor',$i(0)),$let('Loops',$i(0)),['label'=>'Progress'],
        $call('Map','SSOverlayRefreshProgress'),$assert($math('LessEqual_IntInt',$mapGet('SSOverlayProgressVisited'),$i(64)),'periodic map availability refresh preserves64-actor budget'),
        $unless($math('Not_PreBool',$math('EqualEqual_BoolBool',$visible(),$p('ExpectedVisible'))),'ProgressDone'),
        $let('Loops',$math('Add_IntInt',$p('Loops'),$i(1))),$assert($math('Less_IntInt',$p('Loops'),$i(512)),'periodic map refresh reaches actual production control marker'),$jump('Progress'),['label'=>'ProgressDone'],
        FilterExpr('Return',['ReturnExpression'=>$truth(true)]),['label'=>'Fail'],FilterExpr('Return',['ReturnExpression'=>$truth(false)])];
    $b->define('CheckControl',['Pid'=>'Int','Zone'=>'Int','Point'=>'Int','PointName'=>'Str','X'=>'Float','Y'=>'Float','Z'=>'Float'],['Data'=>'Object','NativeObjects'=>'Array','Expected'=>'Bool','ExpectedVisible'=>'Bool','Boolean'=>'Bool','Loops'=>'Int','Index'=>'Int','Actor'=>'Object','Emitter'=>'Object'],$control,null,'Bool');
    foreach($b->asset->Exports[$fn-1]->LoadedProperties as$p){if($p->Name==='Data'){$p->PropertyClass=$b->native['PuzzleData'];}if($p->Name==='Actor'){$p->PropertyClass=$b->native['Actor'];}if($p->Name==='Emitter'){$p->PropertyClass=$b->native['MarkerEmitter'];}if($p->Name==='NativeObjects'){$p->Inner=$b->field('Object','NativeObjects');$p->Inner->PropertyClass=$b->native['PuzzleData'];}}
    $fn=$b->functions['RunProof'];$l=static fn(string$n):object=>FilterVariable($fn,$n);
    $let=static fn(string$n,object$v):object=>FilterExpr('Let',['Value'=>FilterPointer($fn,$n),'Variable'=>$l($n),'Expression'=>$v]);
    $run=[$assert($valid($s('Source')),'actual initialized offline database exists'),$assert($property($s('Source'),$b->native['PuzzleDatabase'],'bInitialized'),'real offline initialization completed without seeded readiness'),
        $b->call('KismetSystemLibrary','K2_ClearTimer',[$s('Radar'),$str('SSRadarTick')]),
        $set('Map',FilterExpr('DynamicCast',['ClassPtr'=>$classes['Map'],'TargetExpression'=>$b->call('WidgetBlueprintLibrary','Create',[FilterExpr('Self'),FilterExpr('ObjectConst',['Value'=>$classes['Map']]),$s('Controller')])])),
        $assert($valid($s('Map')),'unchanged production full map created in real offline world'),$assert($math('EqualEqual_ObjectObject',$mapGet('SSOverlayRadarState'),$s('Radar')),'production map binds current actual gameplay radar')];
    $select=static function(string$type,bool$sites=false)use(&$run,$put,$property,$s,$classes,$name,$set,$str,$truth,$mapGet,$call,$assert,$get,$math,$i,$hubTypes):void{
        $run[]=$set('ProofType',$str($type));$run[]=$call('Map','SSOverlayClearTypes');$run[]=FilterExpr('LetBool',['VariableExpression'=>$property($s('Map'),$classes['Map'],'SSOverlayType_'.$type),'AssignmentExpression'=>FilterExpr('True')]);
        $run[]=FilterExpr('LetBool',['VariableExpression'=>$mapGet('SSOverlaySource_spawn'),'AssignmentExpression'=>$truth($sites)]);$run[]=$call('Map','SSOverlayRefresh');
        for($n=0;$n<5*count($hubTypes);$n++){$run[]=$call('Radar','SSRadarRefreshAvailability');$run[]=$assert($math('LessEqual_IntInt',$get('SSRadarAvailabilityQueriesLastRefresh'),$i(1)),'warmup performs one zone enumeration per refresh call');}
        if(in_array('SaveStats.Type.'.$type,$hubTypes,true)){foreach(range(2,6)as$zone){$run[]=$assert($get('SSRadarAvailabilityZoneReady'.$zone),'initialized zone'.$zone.' cached after one full type-by-zone sweep');}}
        $run[]=$call('Map','SSOverlayRefresh');$run[]=$call('Radar','SSRadarAcknowledgeMapProgress');
        for($n=0;$n<5*count($hubTypes);$n++){$run[]=$call('Radar','SSRadarRefreshAvailability');}
        $run[]=$assert($math('Not_PreBool',$get('SSRadarMapProgressDirty')),'unchanged full-sweep roster does not repeatedly invalidate map or dense scan');
    };
    $check=static function(array$point)use($b,$i,$str,$f,$pointIndex):object{return FilterCall($b->functions['CheckControl'],[$i($point['group']==='spawn'?0:$point['pid']),$i($point['hubAvailabilityZone']),$i($pointIndex($point)),$str($point['actorName']),$f($point['x']),$f($point['y']),$f($point['z'])],'LocalFinalFunction');};
    $select('seek5');
    foreach([11118,11166,11144,11128,17201,17183]as$pid){$run[]=$assert($check($byPid[$pid]),'real control'.$pid.' passes native/cache/radar/map checks');}
    $run[]=$let('NativeObjects',$b->method($s('Source'),'PuzzleDatabase','GetAllActiveSandboxPuzzleDataOfTypeInZone',[$str('seek5'),FilterExpr('ByteConst',['Value'=>6]),$truth(true)],FilterPointer($fn,'NativeObjects')));
    foreach($distant as$n=>$point){
        $run[]=$unless($math('Not_PreBool',$s('DistantChosen')),'DistantNext'.$n);
        $run[]=$let('Data',$b->call('SophiaGameplayStatics','GetPuzzleData',[FilterExpr('Self'),$i($point['pid'])]));
        $run[]=$unless($array('Array_Contains',$l('NativeObjects'),$l('Data')),'DistantNext'.$n);
        $run[]=$assert($check($point),'dynamic distant current-assignment control passes production radar/map');
        // Cache-policy seam only: the actual native database remains untouched.
        $run[]=$array('Array_Clear',$get('SSRadarAvailableZone6'));
        $run[]=$assert($get('SSRadarAvailabilityZoneReady6'),'widget-only seam retains ready state for deliberately empty cached zone');
        $run[]=$assert($math('Not_PreBool',$call('Radar','SSRadarIsPuzzleAvailable',[$i($point['pid']),$i(6)],FilterPointer($fn,'Boolean'))),'widget-only ready-empty cache confirms absence');
        $run[]=$let('NativeObjects',$b->method($s('Source'),'PuzzleDatabase','GetAllActiveSandboxPuzzleDataOfTypeInZone',[$str('seek5'),FilterExpr('ByteConst',['Value'=>6]),$truth(true)],FilterPointer($fn,'NativeObjects')));
        $run[]=$assert($array('Array_Contains',$l('NativeObjects'),$l('Data')),'widget-only empty-cache seam does not mutate real native assignment');
        $run[]=FilterExpr('LetBool',['VariableExpression'=>$get('SSRadarAvailabilityZoneReady6'),'AssignmentExpression'=>$truth(false)]);
        $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i($point['pid']),$i(6)],FilterPointer($fn,'Boolean')),'widget-only unready-empty cache remains unknown and visible');
        $run[]=$call('Radar','SSRadarAcknowledgeMapProgress');
        $run[]=FilterExpr('LetBool',['VariableExpression'=>$get('SSRadarDirty'),'AssignmentExpression'=>$truth(false)]);
        $run[]=FilterExpr('LetBool',['VariableExpression'=>$get('SSRadarScanActive'),'AssignmentExpression'=>$truth(true)]);
        for($refresh=0;$refresh<5*count($hubTypes);$refresh++){$run[]=$call('Radar','SSRadarRefreshAvailability');}
        $run[]=$assert($get('SSRadarAvailabilityZoneReady6'),'native full refresh cycle restores zone readiness after cache-only seam');
        $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i($point['pid']),$i(6)],FilterPointer($fn,'Boolean')),'native full refresh cycle restores actual available membership');
        $run[]=$assert($get('SSRadarDirty'),'restoring native membership invalidates cached candidate search');
        $run[]=$assert($math('Not_PreBool',$get('SSRadarScanActive')),'restoring native membership cancels unfinished stale scan');
        $run[]=$assert($get('SSRadarMapProgressDirty'),'restoring native membership marks actual map visibility dirty');
        $run[]=$assert($math('EqualEqual_IntInt',$array('Array_Length',$get('SSRadarDisplayIndices')),$i(0)),'restoring native membership discards stale committed pins');
        $run[]=FilterExpr('LetBool',['VariableExpression'=>$s('DistantChosen'),'AssignmentExpression'=>$truth(true)]);
        $run[]=['label'=>'DistantNext'.$n];
    }
    $run[]=$assert($s('DistantChosen'),'at least one real zone6 available control chosen dynamically from production catalog');
    $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i(2147483000),$i(2)],FilterPointer($fn,'Boolean')),'missing native PuzzleData remains unknown and visible');
    $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i(11118),$i(0)],FilterPointer($fn,'Boolean')),'static/unknown-zone policy bypasses negative availability');
    $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i(0),$i(2)],FilterPointer($fn,'Boolean')),'possible sitePID0 remains available');
    $run[]=$put($s('Radar'),$classes['Radar'],'SSRadarAvailabilitySource',FilterExpr('ObjectConst',['Value'=>0]));
    $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i(11118),$i(2)],FilterPointer($fn,'Boolean')),'stale/missing cached authority is unknown');$select('seek5');
    $run[]=FilterExpr('LetBool',['VariableExpression'=>$get('SSRadarAvailabilityReady'),'AssignmentExpression'=>$truth(false)]);
    $run[]=$assert($call('Radar','SSRadarIsPuzzleAvailable',[$i(11118),$i(2)],FilterPointer($fn,'Boolean')),'unready cache never proves absence');$select('seek5');
    $run[]=$assert($check($static),'real static production control remains discoverable');
    $select('hiddenCube');foreach($otherControls as$point){$run[]=$assert($check($point),'other-type real production control'.$point['pid'].' matches native assignment');}
    $select($siteType,true);$run[]=$assert($check($site),'actual optional possible site retains independent policy');
    $run[]=$assert($math('Greater_IntInt',$s('PositiveControls'),$i(0)),'real authoritative positive controls exercised');
    $run[]=$assert($math('Greater_IntInt',$s('NegativeControls'),$i(0)),'real authoritative negative controls exercised');
    $run[]=$assert($math('Greater_IntInt',$s('OtherNegativeControls'),$i(0)),'unavailable control from another puzzle type exercised');
    $run[]=$assert($math('Greater_IntInt',$s('VisibleControls'),$i(0)),'at least one available unsolved control remains visible');
    $run[]=FilterExpr('LetBool',['VariableExpression'=>$s('Passed'),'AssignmentExpression'=>$truth(true)]);$run[]=$jump('Done');$run[]=['label'=>'Fail'];$run[]=['label'=>'Done'];$run[]=FilterCall($b->functions['Finish'],[],'LocalFinalFunction');
    $b->define('RunProof',[],['Data'=>'Object','NativeObjects'=>'Array','Boolean'=>'Bool'],$run);
    foreach($b->asset->Exports[$fn-1]->LoadedProperties as$p){if($p->Name==='Data'){$p->PropertyClass=$b->native['PuzzleData'];}if($p->Name==='NativeObjects'){$p->Inner=$b->field('Object','NativeObjects');$p->Inner->PropertyClass=$b->native['PuzzleData'];}}
    $options=$b->call('KismetStringLibrary','Concat_StrStr',[$b->call('KismetStringLibrary','Concat_StrStr',[$b->call('KismetStringLibrary','Concat_StrStr',[$str('?game=/Script/Engine.GameModeBase?check='),$b->call('KismetStringLibrary','Conv_IntToString',[$s('FailureCheck')])]),$str('?pid=')]),$b->call('KismetStringLibrary','Conv_IntToString',[$s('ControlPid')])]);
    $travel=static fn(bool$pass):object=>$b->call('GameplayStatics','OpenLevel',[FilterExpr('Self'),$name('/Game/SaveStatsTests/'.($pass?'SSRadarPass':'SSRadarFail')),$truth(true),$options]);
    $b->define('Finish',[],[],[FilterExpr('LetBool',['VariableExpression'=>$s('Finished'),'AssignmentExpression'=>$truth(true)]),$clearTimer,$unless($s('Passed'),'Fail'),$travel(true),$jump('Done'),['label'=>'Fail'],$travel(false),['label'=>'Done']]);
    file_put_contents($output.'/WBP_SSAvailabilityProof.json',json_encode($b->finish(),JSON_THROW_ON_ERROR));
    FixtureSaveAsset($output.'/WBP_GameHUD.json',AddHubAvailabilityProofToHud(json_decode(file_get_contents($build.'/WBP_GameHUD.json'),false,512,JSON_THROW_ON_ERROR)));
    foreach(['SSRadarPass','SSRadarFail']as$world){copy(OverlayTestDataPath('EngineFixtures/'.$world.'.json'),$output.'/'.$world.'.json');}
    $result=['checks'=>count($checks),'checkDescriptions'=>$checks,'productionBuild'=>$build,'productionPakSha256'=>strtoupper(hash_file('sha256', $pak)),
        'realWorldStartupRequired'=>true,'initializationSeeded'=>false,'nativeAssignmentMutated'=>false,'testHudAdditionalExports'=>2,
        'separateWidgetCacheSeam'=>['readyEmpty'=>'confirmed unavailable','unreadyEmpty'=>'unknown visible','nativeRosterUnchanged'=>true,'restoredByNativeRefresh'=>true],
        'byteIdenticalProductionRadarAndMapRequired'=>true,'reportedControl'=>11118,'fixedControls'=>[11118,11166,11144,11128,17201,17183],
        'dynamicDistantCandidates'=>array_column($distant,'pid'),'otherTypeControls'=>array_keys($otherControls),'staticControl'=>$static['pid'],'siteControl'=>$site['actorName'],'siteType'=>$siteType,
        'passWorld'=>'/Game/SaveStatsTests/SSRadarPass','failWorld'=>'/Game/SaveStatsTests/SSRadarFail'];
    file_put_contents($output.'/fixture.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));return$result;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){try{$args=getopt('',['output-dir:','build-dir:']);if(!isset($args['output-dir'],$args['build-dir'])||!is_dir($args['output-dir'])){throw new InvalidArgumentException('Provide --output-dir DIR --build-dir BUILD');}echo json_encode(BuildHubAvailabilityWorldFixture(realpath($args['output-dir']),realpath($args['build-dir'])),JSON_THROW_ON_ERROR)."\n";}catch(Throwable$error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}}
