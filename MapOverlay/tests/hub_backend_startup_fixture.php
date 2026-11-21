<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once __DIR__ . '/live_progress_probe.php';

/** Experiment: public Offline backend flow, without any forged catalog/readiness. */
function BuildHubBackendStartupFixture(string $output): array
{
    BuildLiveProgressProbe($output);
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $asset = json_decode(file_get_contents($output . '/BP_SSRadarTest.json'), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset = $asset; $b->class = $asset->Exports[0]; $b->default = $asset->Exports[1];
    $b->asset->Exports = [$b->class, $b->default];
    $b->class->LoadedProperties = $b->class->FuncMap = $b->class->Children = $b->class->Data = $b->class->CreateBeforeSerializationDependencies = [];
    $b->default->Data = [];
    foreach (['SophiaGameInstance','GISKraken','PuzzleDatabase','PuzzleData','SophiaGameplayStatics'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    foreach (['Instance'=>'SophiaGameInstance','Kraken'=>'GISKraken','Database'=>'PuzzleDatabase'] as $field=>$type) { $b->objectField($field,$b->native[$type]); }
    $b->scalar('Int','BackendPolls',0); $b->scalar('Int','BackendStage',0);
    foreach (['ReceiveBeginPlay','PollBackend','FailBackend'] as $fn) { $b->reserve($fn); }
    $s=static fn(string$n):object=>FilterVariable(1,$n,true);
    $i=static fn(int$v):object=>FilterExpr('IntConst',['Value'=>$v]);
    $f=static fn(float$v):object=>FilterExpr('FloatConst',['Value'=>$v]);
    $bool=static fn(bool$v):object=>FilterExpr($v?'True':'False');
    $str=static fn(string$v):object=>FilterExpr('StringConst',['Value'=>$v]);
    $math=static fn(string$n,object...$args):object=>$b->call('KismetMathLibrary',$n,$args);
    $valid=static fn(object$o):object=>$b->call('KismetSystemLibrary','IsValid',[$o]);
    $set=static fn(string$n,object$v):object=>FilterExpr('Let',['Value'=>FilterPointer(1,$n),'Variable'=>$s($n),'Expression'=>$v]);
    $property=static fn(object$o,string$owner,string$n):object=>RadarContext($o,FilterVariable($b->native[$owner],$n,true),FilterPointer($b->native[$owner],$n));
    $unless=static fn(object$c,string$label):object=>FilterExpr('JumpIfNot',['CodeOffset'=>0,'__target'=>$label,'BooleanExpression'=>$c]);
    $fn=$b->functions['ReceiveBeginPlay'];
    $begin=[$set('BackendStage',$i(1)),
        $set('Instance',FilterExpr('DynamicCast',['ClassPtr'=>$b->native['SophiaGameInstance'],'TargetExpression'=>$b->call('GameplayStatics','GetGameInstance',[FilterExpr('Self')])])),
        $unless($valid($s('Instance')),'Fail'),
        $set('Kraken',FilterExpr('DynamicCast',['ClassPtr'=>$b->native['GISKraken'],'TargetExpression'=>$b->call('SubsystemBlueprintLibrary','GetGameInstanceSubsystem',[FilterExpr('Self'),FilterExpr('ObjectConst',['Value'=>$b->native['GISKraken']])])])),
        $unless($valid($s('Kraken')),'Fail'),
        FilterExpr('LetBool',['VariableExpression'=>$property($s('Instance'),'SophiaGameInstance','bOfflineMode'),'AssignmentExpression'=>$bool(true)]),
        $set('BackendStage',$i(2)),$b->method($s('Kraken'),'GISKraken','Init'),
        $set('BackendStage',$i(3)),$b->method($s('Instance'),'SophiaGameInstance','LoadGeneralKrakenData'),
        $set('BackendStage',$i(4)),
        FilterExpr('Let',['Value'=>FilterPointer($fn,'Timer'),'Variable'=>FilterVariable($fn,'Timer'),'Expression'=>$b->call('KismetSystemLibrary','K2_SetTimer',[FilterExpr('Self'),$str('PollBackend'),$f(0.25),$bool(true),$f(0),$f(0)])]),
        FilterExpr('Return',['ReturnExpression'=>FilterExpr('Nothing')]),['label'=>'Fail'],FilterCall($b->functions['FailBackend'],[],'LocalFinalFunction')];
    $b->define('ReceiveBeginPlay',[],['Timer'=>'Struct'],$begin);
    $receive=$b->asset->Exports[$fn-1];
    $receive->SuperStruct=$receive->SuperIndex=OverlayImport($b->asset,'ReceiveBeginPlay',$b->native['Actor'],'/Script/CoreUObject','Function');
    $receive->FunctionFlags='FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';$receive->SerializationBeforeSerializationDependencies=[$receive->SuperStruct];
    $fn=$b->functions['PollBackend'];$l=static fn(string$n):object=>FilterVariable($fn,$n);
    $let=static fn(string$n,object$v):object=>FilterExpr('Let',['Value'=>FilterPointer($fn,$n),'Variable'=>$l($n),'Expression'=>$v]);
    $poll=[$set('BackendPolls',$math('Add_IntInt',$s('BackendPolls'),$i(1))),
        $unless($math('Less_IntInt',$s('BackendPolls'),$i(120)),'Fail'),
        $set('Database',FilterExpr('DynamicCast',['ClassPtr'=>$b->native['PuzzleDatabase'],'TargetExpression'=>$b->call('SophiaGameplayStatics','GetPuzzleDatabase',[FilterExpr('Self')])])),
        $unless($valid($s('Database')),'Done'),$set('BackendStage',$i(5)),
        $unless($property($s('Database'),'PuzzleDatabase','bInitialized'),'Done'),$set('BackendStage',$i(6)),
        $unless($math('Greater_IntInt',$property($s('Database'),'PuzzleDatabase','groupLifespan'),$i(0)),'Done'),
        $let('Data',$b->call('SophiaGameplayStatics','GetPuzzleData',[FilterExpr('Self'),$i(11118)])),$unless($valid($l('Data')),'Done'),
        $set('BackendStage',$i(7)),$b->call('KismetSystemLibrary','K2_ClearTimer',[FilterExpr('Self'),$str('PollBackend')]),
        $b->call('GameplayStatics','OpenLevel',[FilterExpr('Self'),FilterExpr('NameConst',['Value'=>'/Game/ASophia/Maps/MainMap_BetaCampaign_Batched']),$bool(true),$str('')]),
        FilterExpr('Return',['ReturnExpression'=>FilterExpr('Nothing')]),['label'=>'Fail'],FilterCall($b->functions['FailBackend'],[],'LocalFinalFunction'),['label'=>'Done']];
    $b->define('PollBackend',[],['Data'=>'Object'],$poll);
    foreach($b->asset->Exports[$fn-1]->LoadedProperties as$p){if($p->Name==='Data'){$p->PropertyClass=$b->native['PuzzleData'];}}
    $b->native['KismetStringLibrary']=OverlayImport($b->asset,'KismetStringLibrary',$b->native['Engine'],'/Script/CoreUObject','Class');
    $options=$b->call('KismetStringLibrary','Concat_StrStr',[$str('?game=/Script/Engine.GameModeBase?backendStage='),$b->call('KismetStringLibrary','Conv_IntToString',[$s('BackendStage')])]);
    $b->define('FailBackend',[],[],[$b->call('KismetSystemLibrary','K2_ClearTimer',[FilterExpr('Self'),$str('PollBackend')]),
        $b->call('GameplayStatics','OpenLevel',[FilterExpr('Self'),FilterExpr('NameConst',['Value'=>'/Game/SaveStatsTests/SSRadarFail']),$bool(true),$options])]);
    file_put_contents($output.'/BP_SSRadarTest.json',json_encode($b->finish(),JSON_THROW_ON_ERROR));
    $manifest=['initializationExperiment'=>true,'publicCalls'=>['SophiaGameInstance.bOfflineMode=true','GISKraken.Init()','SophiaGameInstance.LoadGeneralKrakenData()'],
        'catalogSeeded'=>false,'readinessSeeded'=>false,'privateMemoryWrites'=>false,'waitSeconds'=>30,'successRequires'=>['native bInitialized','positive group lifespan','actual native11118 PuzzleData lookup'],
        'successTravel'=>'/Game/ASophia/Maps/MainMap_BetaCampaign_Batched','failureTravel'=>'/Game/SaveStatsTests/SSRadarFail?backendStage=N'];
    file_put_contents($output.'/backend-fixture.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));return$manifest;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){try{$args=getopt('',['output-dir:']);if(!isset($args['output-dir'])||!is_dir($args['output-dir'])){throw new InvalidArgumentException('Provide --output-dir DIR');}echo json_encode(BuildHubBackendStartupFixture(realpath($args['output-dir'])),JSON_THROW_ON_ERROR)."\n";}catch(Throwable$error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}}
