<?php

declare(strict_types=1);

require_once __DIR__ . '/radar_widget.php';
require_once dirname(__DIR__) . '/lib/uasset.php';

/**
 * Build-time bake of handmade Crystal Labyrinth layouts.
 *
 * Handmade labyrinths (BP_HandmadeMirrorMaze) carry no cell graph; their walls are level geometry:
 * 500 x 500 x 10 'cube' panels (glass: M_MirrorMaze_Glass_01, solid: MI_MirrorMaze_Wall_*) and
 * 500 x 500 floor tiles, laid on a 500-unit grid in the maze actor's frame. Each maze actor's
 * BoundingVolumes (box components) enclose exactly its own pieces, so they clip one maze from its
 * neighbours. The game streams the levels in a headless run; a driver actor dumps the maze actors,
 * every cube instance and every jump pad into the pass-map URL, which the engine log records.
 * Jump pads are the way up: a pad gets '^' on its floor and, when the floor above is open over it,
 * that hole gets 'v' (the shaft you came up through), as Hub labyrinths mark their vertical links.
 */
const LABYRINTH_BAKE_VERSION = 2;
const LABYRINTH_BAKE_CELL = 500.0;
const LABYRINTH_BAKE_DONE_MAP = '/Game/SaveStatsBake/SSLabyrinthBakeDone';
const LABYRINTH_BAKE_DRIVER = '/Game/SaveStatsBake/BP_SSLabyrinthBake';
const LABYRINTH_BAKE_LEVELS = ['pyramid' => [
    '/Game/ASophia/Maps/MainMapSubmaps/RainForest/Dungeons/Rainforest_PyramidMirrorMaze',
    '/Game/ASophia/Maps/MainMapSubmaps/RainForest/Dungeons/Rainforest_PyramidMirrorMaze_Puzzles',
]];

/** Write the driver class and the Login (driver) and done maps as UAssetAPI JSON. */
function BuildLabyrinthBakeAssets(object $baseWidget, object $baselineWorld, array $levels, string $outputDir): array
{
    $b = new RadarWidgetBuilder($baseWidget);
    $b->asset->Exports = [$b->class, $b->default];
    $actor = $b->native['Actor'];
    $bp = OverlayImport($b->asset, 'BlueprintGeneratedClass', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $bpDefault = OverlayImport($b->asset, 'Default__BlueprintGeneratedClass', $b->native['Engine'], '/Script/Engine', 'BlueprintGeneratedClass');
    $actorDefault = OverlayImport($b->asset, 'Default__Actor', $b->native['Engine'], '/Script/Engine', 'Actor');
    $b->class->ObjectName = 'BP_SSLabyrinthBake_C';
    $b->class->ClassIndex = $bp;
    $b->class->TemplateIndex = $bpDefault;
    $b->class->ClassFlags = 'CLASS_Parsed, CLASS_ReplicationDataIsSetUp, CLASS_CompiledFromBlueprint';
    $b->class->SuperStruct = $b->class->SuperIndex = $actor;
    $b->class->LoadedProperties = $b->class->Data = [];
    $b->class->SerializationBeforeSerializationDependencies = [$actor];
    $b->class->CreateBeforeSerializationDependencies = [];
    $b->class->SerializationBeforeCreateDependencies = [$bp, $bpDefault];
    $b->class->CreateBeforeCreateDependencies = [$actor];
    $b->default->ObjectName = 'Default__BP_SSLabyrinthBake_C';
    $b->default->TemplateIndex = $actorDefault;
    $b->default->Data = [];
    $b->default->SerializationBeforeCreateDependencies = [1, $actorDefault];
    foreach (['KismetStringLibrary', 'InstancedStaticMeshComponent', 'LevelStreamingDynamic', 'LevelStreaming', 'StaticMeshComponent', 'ActorComponent', 'SceneComponent', 'BoxComponent', 'PrimitiveComponent'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['Engine'], '/Script/CoreUObject', 'Class');
    }
    $b->native['MirrorMazePuzzle'] = OverlayImport($b->asset, 'MirrorMazePuzzle', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    $b->native['Transform'] = OverlayImport($b->asset, 'Transform', $b->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct');
    $b->objectField('Maze', $b->native['MirrorMazePuzzle']);
    $b->scalar('Str', 'Out', '?game=/Script/Engine.GameModeBase?bake=');
    foreach (['Tries' => 0, 'Entries' => 0, 'Pads' => 0] as $n => $v) { $b->scalar('Int', $n, $v); }
    $b->scalar('Bool', 'Settled', false);
    foreach (['Found', 'Actors', 'Comps'] as $n) { $b->arrayField('Object', $n, []); }
    foreach ($b->class->LoadedProperties as $p) {
        if (in_array($p->Name, ['Found', 'Actors'], true)) { $p->Inner->PropertyClass = $actor; }
        if ($p->Name === 'Comps') { $p->Inner->PropertyClass = $b->native['ActorComponent']; }
    }
    foreach (array_keys($levels) as $k) { $b->objectField('Stream' . $k, $b->native['LevelStreamingDynamic']); }
    foreach (['ReceiveBeginPlay', 'DumpMazes', 'DumpCubes', 'Verify', 'Finish'] as $fn) { $b->reserve($fn); }

    $s = static fn(string $name): object => FilterVariable(1, $name, true);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer(1, $name), 'Variable' => $s($name), 'Expression' => $value]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $str = static fn(string $v): object => FilterExpr('StringConst', ['Value' => $v]);
    $truth = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $text = static fn(string $fn, object ...$args): object => $b->call('KismetStringLibrary', $fn, $args);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $vec = static fn(float $x, float $y, float $z): object => $math('MakeVector', $f($x), $f($y), $f($z));
    $property = static fn(object $obj, string $owner, string $name): object => RadarContext($obj, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $member = static fn(string $struct, string $name, object $of): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $name), 'StructExpression' => $of]);
    $jump = static fn(string $target): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $target]);
    $unless = static fn(object $condition, string $target): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $target, 'BooleanExpression' => $condition]);
    $return = static fn(): object => FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]);
    $timer = static function (string $owner, string $callback, float $delay) use ($b, $f, $truth): object {
        $fn = $b->functions[$owner];
        return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $f($delay), $truth(false), $f(0), $f(0)])]);
    };
    $append = static function (object ...$parts) use ($set, $s, $text): object {
        $tail = array_pop($parts);
        while ($parts !== []) { $tail = $text('Concat_StrStr', array_pop($parts), $tail); }
        return $set('Out', $text('Concat_StrStr', $s('Out'), $tail));
    };
    $int = static fn(object $v): object => $text('Conv_IntToString', $v);
    $round = static fn(object $v): object => $int($math('Round', $v));
    $vector = static fn(object $v): array => [$round($member('Vector', 'X', $v)), $str(','), $round($member('Vector', 'Y', $v)), $str(','), $round($member('Vector', 'Z', $v))];

    // Maze records: ;M name,x,y,z,yaw  then one ;B x,y,z,yaw,ex,ey,ez per bounding volume.
    $fn = $b->functions['DumpMazes'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $volumes = $property($s('Maze'), 'MirrorMazePuzzle', 'BoundingVolumes');
    $b->define('DumpMazes', [], ['I' => 'Int', 'K' => 'Int', 'Count' => 'Int', 'Item' => 'Object', 'Box' => 'Object', 'Loc' => 'Struct', 'Rot' => 'Struct', 'Ext' => 'Struct'], [
        $b->call('GameplayStatics', 'GetAllActorsOfClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['MirrorMazePuzzle']]), $s('Found')]),
        $append($str(';mazes='), $int($array('Array_Length', $s('Found')))),
        $let('I', $i(0)), ['label' => 'MazeLoop'],
        $unless($math('Less_IntInt', $l('I'), $array('Array_Length', $s('Found'))), 'MazeDone'),
        $array('Array_Get', $s('Found'), $l('I'), $l('Item')),
        $set('Maze', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['MirrorMazePuzzle'], 'TargetExpression' => $l('Item')])),
        $unless($valid($s('Maze')), 'MazeNext'),
        $let('Loc', $b->method($s('Maze'), 'Actor', 'K2_GetActorLocation', [], FilterPointer($fn, 'Loc'))),
        $let('Rot', $b->method($s('Maze'), 'Actor', 'K2_GetActorRotation', [], FilterPointer($fn, 'Rot'))),
        $append($str(';M'), $b->call('KismetSystemLibrary', 'GetObjectName', [$s('Maze')]), $str(','), ...[...$vector($l('Loc')), $str(','), $round($member('Rotator', 'Yaw', $l('Rot')))]),
        $let('Count', $array('Array_Length', $volumes)),
        $let('K', $i(0)), ['label' => 'BoxLoop'],
        $unless($math('Less_IntInt', $l('K'), $l('Count')), 'MazeNext'),
        $array('Array_Get', unserialize(serialize($volumes)), $l('K'), $l('Item')),
        $let('Box', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['BoxComponent'], 'TargetExpression' => $l('Item')])),
        $unless($valid($l('Box')), 'BoxNext'),
        $let('Loc', $b->method($l('Box'), 'SceneComponent', 'K2_GetComponentLocation', [], FilterPointer($fn, 'Loc'))),
        $let('Rot', $b->method($l('Box'), 'SceneComponent', 'K2_GetComponentRotation', [], FilterPointer($fn, 'Rot'))),
        $let('Ext', $b->method($l('Box'), 'BoxComponent', 'GetScaledBoxExtent', [], FilterPointer($fn, 'Ext'))),
        $append($str(';B'), ...[...$vector($l('Loc')), $str(','), $round($member('Rotator', 'Yaw', $l('Rot'))), $str(','), ...$vector($l('Ext'))]),
        ['label' => 'BoxNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('BoxLoop'),
        ['label' => 'MazeNext'], $let('I', $math('Add_IntInt', $l('I'), $i(1))), $jump('MazeLoop'), ['label' => 'MazeDone'],
    ]);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
        if ($p->Name === 'Item') { $p->PropertyClass = $actor; }
        if ($p->Name === 'Box') { $p->PropertyClass = $b->native['BoxComponent']; }
        if ($p->Name === 'Rot') { $p->Struct = $b->native['Rotator']; }
    }

    // Cube pieces: ;C mesh^material per component, then ;I origin,xaxis,yaxis,zaxis (100-unit axes) per instance.
    $fn = $b->functions['DumpCubes'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $axis = static fn(float $x, float $y, float $z): array => [
        $let('Ext', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec($x, $y, $z)), $l('Origin'))), $append($str(','), ...$vector($l('Ext')))];
    $b->define('DumpCubes', [], ['NA' => 'Int', 'A' => 'Int', 'NC' => 'Int', 'C' => 'Int', 'K' => 'Int', 'Count' => 'Int', 'Ok' => 'Bool', 'Act' => 'Object', 'Item' => 'Object', 'Comp' => 'Object', 'Ism' => 'Object',
        'Mesh' => 'Object', 'Mat' => 'Object', 'Name' => 'Str', 'MatName' => 'Str', 'Xf' => 'Struct', 'Origin' => 'Struct', 'Ext' => 'Struct'], [
        $b->call('GameplayStatics', 'GetAllActorsOfClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $actor]), $s('Actors')]),
        $let('NA', $array('Array_Length', $s('Actors'))),
        $let('A', $i(0)), ['label' => 'ActorLoop'],
        $unless($math('Less_IntInt', $l('A'), $l('NA')), 'ActorDone'),
        $array('Array_Get', $s('Actors'), $l('A'), $l('Act')),
        $set('Comps', $b->method($l('Act'), 'Actor', 'K2_GetComponentsByClass', [FilterExpr('ObjectConst', ['Value' => $b->native['StaticMeshComponent']])], FilterPointer(1, 'Comps'))),
        $let('NC', $array('Array_Length', $s('Comps'))),
        $let('C', $i(0)), ['label' => 'CompLoop'],
        $unless($math('Less_IntInt', $l('C'), $l('NC')), 'CompDone'),
        $array('Array_Get', $s('Comps'), $l('C'), $l('Item')),
        $let('Comp', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['StaticMeshComponent'], 'TargetExpression' => $l('Item')])),
        $unless($valid($l('Comp')), 'CompNext'),
        $let('Mesh', $property($l('Comp'), 'StaticMeshComponent', 'StaticMesh')),
        $unless($valid($l('Mesh')), 'CompNext'),
        $let('Name', $b->call('KismetSystemLibrary', 'GetObjectName', [$l('Mesh')])),
        // Jump pads: position only.
        $unless($b->call('KismetStringLibrary', 'EqualEqual_StriStri', [$l('Name'), $str('SM_JumpPad_Base')]), 'NotPad'),
        $let('Origin', $b->method($l('Comp'), 'SceneComponent', 'K2_GetComponentLocation', [], FilterPointer($fn, 'Origin'))),
        $append($str(';P'), ...$vector($l('Origin'))), $set('Pads', $math('Add_IntInt', $s('Pads'), $i(1))), $jump('CompNext'),
        ['label' => 'NotPad'],
        // The maze pieces use the engine's plain 'cube' mesh; everything else is scenery.
        $unless($b->call('KismetStringLibrary', 'EqualEqual_StriStri', [$l('Name'), $str('cube')]), 'CompNext'),
        $let('MatName', $str('')),
        $let('Mat', $b->method($l('Comp'), 'PrimitiveComponent', 'GetMaterial', [$i(0)], FilterPointer($fn, 'Mat'))),
        $unless($valid($l('Mat')), 'Named'),
        $let('MatName', $b->call('KismetSystemLibrary', 'GetObjectName', [$l('Mat')])),
        ['label' => 'Named'],
        $append($str(';C'), $l('Name'), $str('^'), $l('MatName')),
        $let('Count', $i(1)),
        $let('Ism', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['InstancedStaticMeshComponent'], 'TargetExpression' => $l('Comp')])),
        $unless($valid($l('Ism')), 'Counted'),
        $let('Count', $b->method($l('Ism'), 'InstancedStaticMeshComponent', 'GetInstanceCount', [], FilterPointer($fn, 'Count'))),
        ['label' => 'Counted'],
        $let('K', $i(0)), ['label' => 'InstLoop'],
        $unless($math('Less_IntInt', $l('K'), $l('Count')), 'CompNext'),
        $unless($valid($l('Ism')), 'Single'),
        $let('Ok', $b->method($l('Ism'), 'InstancedStaticMeshComponent', 'GetInstanceTransform', [$l('K'), $l('Xf'), $truth(true)], FilterPointer($fn, 'Ok'))),
        $jump('HaveXf'), ['label' => 'Single'],
        $let('Xf', $b->method($l('Comp'), 'SceneComponent', 'K2_GetComponentToWorld', [], FilterPointer($fn, 'Xf'))),
        ['label' => 'HaveXf'],
        $let('Origin', $math('TransformLocation', $l('Xf'), $vec(0, 0, 0))), $append($str(';I'), ...$vector($l('Origin'))),
        ...$axis(100, 0, 0), ...$axis(0, 100, 0), ...$axis(0, 0, 100),
        $set('Entries', $math('Add_IntInt', $s('Entries'), $i(1))),
        $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('InstLoop'),
        ['label' => 'CompNext'], $let('C', $math('Add_IntInt', $l('C'), $i(1))), $jump('CompLoop'),
        ['label' => 'CompDone'], $let('A', $math('Add_IntInt', $l('A'), $i(1))), $jump('ActorLoop'),
        ['label' => 'ActorDone'], $append($str(';cubes='), $int($s('Entries')), $str(';pads='), $int($s('Pads'))),
    ]);
    $classes = ['Act' => $actor, 'Item' => $b->native['ActorComponent'], 'Comp' => $b->native['StaticMeshComponent'], 'Ism' => $b->native['InstancedStaticMeshComponent']];
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
        if (isset($classes[$p->Name])) { $p->PropertyClass = $classes[$p->Name]; }
        if ($p->Name === 'Xf') { $p->Struct = $b->native['Transform']; $p->ElementSize = 48; }
    }

    $bf = $b->functions['ReceiveBeginPlay'];
    $begin = [];
    foreach ($levels as $k => $path) {
        array_push($begin,
            $set('Stream' . $k, $b->call('LevelStreamingDynamic', 'LoadLevelInstance', [FilterExpr('Self'), $str($path), $vec(0, 0, 0), $math('MakeRotator', $f(0), $f(0), $f(0)), FilterVariable($bf, 'Loaded')])),
            $append($str('level' . $k . '='), $int($math('Conv_BoolToInt', FilterVariable($bf, 'Loaded'))), $str(';')));
    }
    $begin[] = $timer('ReceiveBeginPlay', 'Verify', 2.0);
    $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct', 'Loaded' => 'Bool'], $begin);
    $receive = $b->asset->Exports[$bf - 1];
    $receive->SuperStruct = $receive->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $actor, '/Script/CoreUObject', 'Function');
    $receive->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $receive->SerializationBeforeSerializationDependencies = [$receive->SuperStruct];

    $fn = $b->functions['Verify'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $ready = [$let('Ready', $truth(true))];
    foreach (array_keys($levels) as $k) {
        array_push($ready, $unless($valid($s('Stream' . $k)), 'NotReady'),
            $let('Visible', $b->method($s('Stream' . $k), 'LevelStreaming', 'IsLevelVisible', [], FilterPointer($fn, 'Visible'))),
            $let('Ready', $math('BooleanAND', $l('Ready'), $l('Visible'))));
    }
    $b->define('Verify', [], ['Ready' => 'Bool', 'Visible' => 'Bool', 'Timer' => 'Struct'], [
        // Streaming is asynchronous: poll until every level is visible, then one more round for BeginPlay.
        ...$ready, $jump('Checked'), ['label' => 'NotReady'], $let('Ready', $truth(false)), ['label' => 'Checked'],
        $unless($math('BooleanAND', $math('Not_PreBool', $l('Ready')), $math('Less_IntInt', $s('Tries'), $i(90))), 'Present'),
        $set('Tries', $math('Add_IntInt', $s('Tries'), $i(1))), $timer('Verify', 'Verify', 2.0), $return(),
        ['label' => 'Present'],
        $unless($math('Not_PreBool', $s('Settled')), 'Settled'),
        FilterExpr('LetBool', ['VariableExpression' => $s('Settled'), 'AssignmentExpression' => $truth(true)]), $timer('Verify', 'Verify', 3.0), $return(),
        ['label' => 'Settled'],
        $append($str('ready='), $int($math('Conv_BoolToInt', $l('Ready'))), $str(';tries='), $int($s('Tries'))),
        FilterCall($b->functions['DumpMazes'], [], 'LocalFinalFunction'),
        FilterCall($b->functions['DumpCubes'], [], 'LocalFinalFunction'),
        $append($str(';end=1')),
        $timer('Verify', 'Finish', 0.2),
    ]);
    $b->define('Finish', [], [], [$b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => LABYRINTH_BAKE_DONE_MAP]), $truth(true), $s('Out')])]);
    foreach ([LABYRINTH_BAKE_DRIVER, LABYRINTH_BAKE_DONE_MAP, '/Game/ASophia/Maps/Login'] as $name) { $b->name($name); }
    $driver = $b->finish();
    file_put_contents($outputDir . '/BP_SSLabyrinthBake.json', json_encode($driver, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    // Maps: the base mod's own RRMod_Objects world with only its settings, plus the driver on Login.
    $world = unserialize(serialize($baselineWorld));
    $engine = OverlayImport($world, '/Script/Engine', 0, '/Script/CoreUObject', 'Package');
    $mode = OverlayImport($world, 'GameModeBase', $engine, '/Script/CoreUObject', 'Class');
    $package = OverlayImport($world, LABYRINTH_BAKE_DRIVER, 0, '/Script/CoreUObject', 'Package');
    $driverClass = OverlayImport($world, 'BP_SSLabyrinthBake_C', $package, '/Script/Engine', 'BlueprintGeneratedClass');
    $driverDefault = OverlayImport($world, 'Default__BP_SSLabyrinthBake_C', $package, LABYRINTH_BAKE_DRIVER, 'BP_SSLabyrinthBake_C');
    $level = $settings = $template = null;
    foreach ($world->Exports as $n => $e) {
        if ($e->ObjectName === 'PersistentLevel') { $level = $e; $levelIndex = $n + 1; }
        if ($e->ObjectName === 'WorldSettings_0') { $settings = $e; $settingsIndex = $n + 1; }
        if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.NormalExport, UAssetAPI') { $template ??= $e; }
    }
    if ($level === null || $settings === null || $template === null) { throw new RuntimeException('Labyrinth bake needs the base RRMod_Objects world with a level and world settings.'); }
    $driverIndex = count($world->Exports) + 1;
    $world->Exports[] = OverlayExport($template, $driverIndex, 'SSLabyrinthBakeDriver', $levelIndex, $driverClass, $driverDefault, []);
    $world->Exports[array_key_last($world->Exports)]->Extras = '';
    $settings->Data = array_values(array_filter($settings->Data, static fn($p): bool => $p->Name !== 'DefaultGameMode'));
    $settings->Data[] = OverlayProperty('Object', 'DefaultGameMode', $mode);
    $settings->CreateBeforeSerializationDependencies[] = $mode;
    $world->NameMap = array_values(array_unique(array_merge($world->NameMap, ['Login', 'SSLabyrinthBakeDone', 'SSLabyrinthBakeDriver', 'DefaultGameMode',
        '/Game/ASophia/Maps/Login', LABYRINTH_BAKE_DONE_MAP, LABYRINTH_BAKE_DRIVER, 'BP_SSLabyrinthBake_C', 'Default__BP_SSLabyrinthBake_C', 'GameModeBase'])));
    foreach (['Login' => '/Game/ASophia/Maps/Login', 'SSLabyrinthBakeDone' => LABYRINTH_BAKE_DONE_MAP] as $name => $path) {
        $copy = unserialize(serialize($world));
        foreach ($copy->Exports as $e) {
            if (($e->{'$type'} ?? '') === 'UAssetAPI.ExportTypes.WorldExport, UAssetAPI' || $e->ObjectName === 'RRMod_Objects') { $e->ObjectName = $name; }
            if ($e->ObjectName === 'PersistentLevel') {
                $e->URL->Map = $path;
                $e->Actors = $name === 'Login' ? [$settingsIndex, $driverIndex] : [$settingsIndex];
                $e->CreateBeforeSerializationDependencies = [$level->Model, ...$e->Actors];
            }
        }
        // An orphan actor export can still be registered by level loading, so drop it from the done map.
        if ($name !== 'Login') { array_pop($copy->Exports); }
        SaveCompressedDecodedUasset($outputDir . '/' . $name . '.json', $copy, ['bakeAllIndices' => true, 'verbose' => false]);
    }
    AssertNoContextCallArguments($driver, 'labyrinth bake driver');
    return ['driver' => 'BP_SSLabyrinthBake', 'maps' => ['Login', 'SSLabyrinthBakeDone'], 'levels' => $levels, 'doneMap' => LABYRINTH_BAKE_DONE_MAP,
        'pak' => ['BP_SSLabyrinthBake' => 'SaveStatsBake/BP_SSLabyrinthBake.uasset', 'Login' => 'ASophia/Maps/Login.umap', 'SSLabyrinthBakeDone' => 'SaveStatsBake/SSLabyrinthBakeDone.umap']];
}

/** Read the driver's dump back from the engine log's LoadMap line. */
function ParseLabyrinthBakeLog(string $log): array
{
    if (!preg_match('/LoadMap: ' . preg_quote(LABYRINTH_BAKE_DONE_MAP, '/') . '\S*\?bake=(\S*)/', $log, $m)) { throw new RuntimeException('The labyrinth bake run did not reach its done map.'); }
    $mazes = $pieces = $pads = [];
    $material = $mesh = null;
    $fields = [];
    foreach (explode(';', $m[1]) as $token) {
        if ($token === '') { continue; }
        $tag = $token[0];
        if ($tag === 'M' && preg_match('/^M([^,]+),(-?\d+),(-?\d+),(-?\d+),(-?\d+)$/', $token, $x)) {
            $mazes[] = ['name' => $x[1], 'x' => (int)$x[2], 'y' => (int)$x[3], 'z' => (int)$x[4], 'yaw' => (int)$x[5], 'boxes' => []];
        } elseif ($tag === 'B' && preg_match('/^B' . str_repeat('(-?\d+),', 6) . '(-?\d+)$/', $token, $x) && $mazes !== []) {
            $v = array_map('intval', array_slice($x, 1));
            $mazes[array_key_last($mazes)]['boxes'][] = ['x' => $v[0], 'y' => $v[1], 'z' => $v[2], 'yaw' => $v[3], 'ex' => $v[4], 'ey' => $v[5], 'ez' => $v[6]];
        } elseif ($tag === 'C' && preg_match('/^C([^\^]*)\^(.*)$/', $token, $x)) {
            [$mesh, $material] = [$x[1], $x[2]];
        } elseif ($tag === 'I' && preg_match('/^I' . str_repeat('(-?\d+),', 11) . '(-?\d+)$/', $token, $x)) {
            $v = array_map('intval', array_slice($x, 1));
            $pieces[] = ['mesh' => $mesh, 'material' => $material, 'o' => array_slice($v, 0, 3), 'x' => array_slice($v, 3, 3), 'y' => array_slice($v, 6, 3), 'z' => array_slice($v, 9, 3)];
        } elseif ($tag === 'P' && preg_match('/^P(-?\d+),(-?\d+),(-?\d+)$/', $token, $x)) {
            $pads[] = array_map('intval', array_slice($x, 1));
        } elseif (preg_match('/^([a-z0-9]+)=(.*)$/', $token, $x)) {
            $fields[$x[1]] = $x[2];
        }
    }
    if (($fields['end'] ?? '') !== '1' || ($fields['ready'] ?? '') !== '1') { throw new RuntimeException('The labyrinth bake dump is incomplete (levels not ready or output cut short).'); }
    if ((int)($fields['cubes'] ?? -1) !== count($pieces) || (int)($fields['pads'] ?? -1) !== count($pads)) { throw new RuntimeException('The labyrinth bake dump lost pieces in transport.'); }
    return ['mazes' => $mazes, 'pieces' => $pieces, 'pads' => $pads];
}

/** Point inside an oriented bounding volume, with a tolerance in each axis. */
function LabyrinthInBox(array $p, array $box, array $margin = [40, 40, 60]): bool
{
    $a = deg2rad(-$box['yaw']);
    $dx = $p[0] - $box['x']; $dy = $p[1] - $box['y'];
    $lx = $dx * cos($a) - $dy * sin($a); $ly = $dx * sin($a) + $dy * cos($a);
    return abs($lx) <= $box['ex'] + $margin[0] && abs($ly) <= $box['ey'] + $margin[1] && abs($p[2] - $box['z']) <= $box['ez'] + $margin[2];
}

/**
 * Turn a dump into per-maze floor layouts in world space. Floors are clustered piece heights with at
 * least six walls (shafts and exit platforms are not floors). Holes are grid cells inside one of the
 * maze's volumes at walking height that have no floor tile; a maze's lowest floor never has holes.
 */
function BuildLabyrinthLayouts(array $dump, array $markers = []): array
{
    $cell = LABYRINTH_BAKE_CELL;
    $layouts = [];
    foreach ($dump['mazes'] as $maze) {
        if ($maze['boxes'] === []) { continue; }
        $yaw = deg2rad($maze['yaw']);
        [$c, $s] = [cos(-$yaw), sin(-$yaw)];
        $local = static fn(array $p): array => [($p[0] - $maze['x']) * $c - ($p[1] - $maze['y']) * $s, ($p[0] - $maze['x']) * $s + ($p[1] - $maze['y']) * $c, $p[2] - $maze['z']];
        $turn = static fn(array $v): array => [$v[0] * $c - $v[1] * $s, $v[0] * $s + $v[1] * $c, $v[2]];
        $world = static fn(float $x, float $y): array => [$maze['x'] + $x * cos($yaw) - $y * sin($yaw), $maze['y'] + $x * sin($yaw) + $y * cos($yaw)];
        $walls = $tiles = [];
        foreach ($dump['pieces'] as $piece) {
            if (strcasecmp((string)$piece['mesh'], 'cube') !== 0) { continue; }
            $inside = false;
            foreach ($maze['boxes'] as $box) { if (LabyrinthInBox($piece['o'], $box)) { $inside = true; break; } }
            if (!$inside) { continue; }
            $axes = [$piece['x'], $piece['y'], $piece['z']];
            $lengths = array_map(static fn(array $v): float => sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2), $axes);
            $vertical = 0; $best = -1.0;
            foreach ($axes as $k => $v) { $r = $lengths[$k] > 0 ? abs($v[2]) / $lengths[$k] : 0; if ($r > $best) { $best = $r; $vertical = $k; } }
            $horizontal = array_values(array_diff([0, 1, 2], [$vertical]));
            $o = $local($piece['o']);
            if ($lengths[$vertical] < 50) {
                $a1 = $turn($axes[$horizontal[0]]); $a2 = $turn($axes[$horizontal[1]]);
                $tiles[] = ['o' => $o, 'hx' => (abs($a1[0]) + abs($a2[0])) / 2, 'hy' => (abs($a1[1]) + abs($a2[1])) / 2];
                continue;
            }
            $long = $lengths[$horizontal[0]] >= $lengths[$horizontal[1]] ? $horizontal[0] : $horizontal[1];
            if ($lengths[$long] < 300) { continue; }
            $d = $turn($axes[$long]);
            $walls[] = ['a' => [$o[0] - $d[0] / 2, $o[1] - $d[1] / 2], 'b' => [$o[0] + $d[0] / 2, $o[1] + $d[1] / 2], 'bottom' => $o[2] - $lengths[$vertical] / 2,
                'kind' => stripos((string)$piece['material'], 'Glass') !== false ? 0 : 1];
        }
        if ($walls === []) { continue; }
        // Grid offset: wall endpoints lie on cell corners.
        $mode = static function (array $values) use ($cell): float {
            $counts = [];
            foreach ($values as $v) { $k = (int)round(fmod(fmod($v, $cell) + $cell, $cell)); $counts[$k] = ($counts[$k] ?? 0) + 1; }
            arsort($counts);
            return (float)array_key_first($counts);
        };
        $gx = $mode(array_merge(...array_map(static fn(array $w): array => [$w['a'][0], $w['b'][0]], $walls)));
        $gy = $mode(array_merge(...array_map(static fn(array $w): array => [$w['a'][1], $w['b'][1]], $walls)));
        // Heights: cluster wall bottoms and tile tops within 150 units.
        $heights = array_merge(array_column($walls, 'bottom'), array_map(static fn(array $t): float => $t['o'][2], $tiles));
        sort($heights);
        $groups = [];
        foreach ($heights as $h) {
            if ($groups !== [] && $h - end($groups)[count(end($groups)) - 1] <= 150) { $groups[array_key_last($groups)][] = $h; } else { $groups[] = [$h]; }
        }
        $levels = array_map(static fn(array $g): float => array_sum($g) / count($g), $groups);
        $nearest = static function (float $z) use ($levels): int {
            $best = 0;
            foreach ($levels as $k => $level) { if (abs($level - $z) < abs($levels[$best] - $z)) { $best = $k; } }
            return $best;
        };
        $byLevel = array_fill(0, count($levels), ['walls' => [], 'tiles' => []]);
        foreach ($walls as $w) { $byLevel[$nearest($w['bottom'])]['walls'][] = $w; }
        foreach ($tiles as $t) {
            $k = $nearest($t['o'][2]);
            for ($x = (int)floor(($t['o'][0] - $t['hx'] - $gx) / $cell + 0.5); $x < (int)ceil(($t['o'][0] + $t['hx'] - $gx) / $cell - 0.5); $x++) {
                for ($y = (int)floor(($t['o'][1] - $t['hy'] - $gy) / $cell + 0.5); $y < (int)ceil(($t['o'][1] + $t['hy'] - $gy) / $cell - 0.5); $y++) { $byLevel[$k]['tiles']["$x,$y"] = true; }
            }
        }
        $floors = [];
        foreach ($levels as $k => $level) {
            if (count($byLevel[$k]['walls']) < 6) { continue; }
            $floor = ['z' => round($maze['z'] + $level, 1), 'walls' => [], 'holes' => [], 'marks' => []];
            $cells = [];
            foreach ($byLevel[$k]['walls'] as $w) {
                [$ax, $ay] = $world($w['a'][0], $w['a'][1]); [$bx, $by] = $world($w['b'][0], $w['b'][1]);
                $floor['walls'][] = [round($ax, 1), round($ay, 1), round($bx, 1), round($by, 1), $w['kind']];
                foreach ([$w['a'], $w['b']] as $p) { $cells[] = [(int)floor(($p[0] - $gx) / $cell), (int)floor(($p[1] - $gy) / $cell)]; }
            }
            if ($floors !== []) {
                $xs = array_column($cells, 0); $ys = array_column($cells, 1);
                for ($x = min($xs) - 2; $x <= max($xs) + 2; $x++) {
                    for ($y = min($ys) - 2; $y <= max($ys) + 2; $y++) {
                        if (isset($byLevel[$k]['tiles']["$x,$y"])) { continue; }
                        [$wx, $wy] = $world($gx + ($x + 0.5) * $cell, $gy + ($y + 0.5) * $cell);
                        foreach ($maze['boxes'] as $box) {
                            if (LabyrinthInBox([$wx, $wy, $maze['z'] + $level + 250], $box, [0, 0, 0])) { $floor['holes'][] = [round($wx, 1), round($wy, 1)]; break; }
                        }
                    }
                }
            }
            $floors[] = $floor;
        }
        if ($floors === []) { continue; }
        // Jump pads inside this maze: '^' on the floor they stand on; 'v' on the hole right above, if any.
        foreach ($dump['pads'] ?? [] as $pad) {
            $inside = false;
            foreach ($maze['boxes'] as $box) { if (LabyrinthInBox($pad, $box)) { $inside = true; break; } }
            if (!$inside) { continue; }
            $on = null;
            foreach ($floors as $k => $floor) { if ($floor['z'] <= $pad[2] + 60) { $on = $k; } }
            if ($on === null) { continue; }
            $floors[$on]['marks'][] = [(float)$pad[0], (float)$pad[1], 1];
            if (isset($floors[$on + 1])) {
                foreach ($floors[$on + 1]['holes'] as [$hx, $hy]) {
                    if (hypot($hx - $pad[0], $hy - $pad[1]) <= $cell / 2) { $floors[$on + 1]['marks'][] = [$hx, $hy, 2]; break; }
                }
            }
        }
        // Identify the catalogue puzzle by position (handmade actors sit exactly on their records).
        $pid = 0;
        foreach ($markers as $id => $marker) {
            if (($marker['ptype'] ?? '') === 'mirrorMaze' && abs($marker['x'] - $maze['x']) < 5 && abs($marker['y'] - $maze['y']) < 5 && abs($marker['z'] - $maze['z']) < 5) { $pid = (int)$id; }
        }
        $layouts[] = ['pid' => $pid, 'name' => $maze['name'], 'x' => $maze['x'], 'y' => $maze['y'], 'z' => $maze['z'], 'yaw' => $maze['yaw'], 'cell' => $cell,
            'boxes' => $maze['boxes'], 'floors' => $floors];
    }
    usort($layouts, static fn(array $a, array $b): int => [$a['pid'], $a['name']] <=> [$b['pid'], $b['name']]);
    return $layouts;
}

function LabyrinthBakeMain(array $argv): int
{
    $options = getopt('', ['mode:', 'base-widget:', 'baseline-world:', 'output-dir:', 'set:', 'log:', 'markers:', 'output:']);
    $command = $options['mode'] ?? '';
    if ($command === 'assets') {
        foreach (['base-widget', 'baseline-world', 'output-dir'] as $o) { if (!isset($options[$o])) { throw new InvalidArgumentException("assets requires --$o"); } }
        $levels = LABYRINTH_BAKE_LEVELS[$options['set'] ?? 'pyramid'] ?? throw new InvalidArgumentException('Unknown labyrinth set.');
        $result = BuildLabyrinthBakeAssets(json_decode(file_get_contents($options['base-widget']), false, 512, JSON_THROW_ON_ERROR),
            json_decode(file_get_contents($options['baseline-world']), false, 512, JSON_THROW_ON_ERROR), $levels, $options['output-dir']);
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }
    if ($command === 'layouts') {
        foreach (['log', 'output'] as $o) { if (!isset($options[$o])) { throw new InvalidArgumentException("layouts requires --$o"); } }
        $markers = isset($options['markers']) ? (json_decode(file_get_contents($options['markers']), true, 512, JSON_THROW_ON_ERROR)['markers'] ?? []) : [];
        $layouts = BuildLabyrinthLayouts(ParseLabyrinthBakeLog((string)file_get_contents($options['log'])), $markers);
        file_put_contents($options['output'], json_encode(['version' => LABYRINTH_BAKE_VERSION, 'labyrinths' => $layouts], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        echo json_encode(['labyrinths' => count($layouts), 'floors' => array_sum(array_map(static fn(array $l): int => count($l['floors']), $layouts)),
            'walls' => array_sum(array_map(static fn(array $l): int => array_sum(array_map(static fn(array $f): int => count($f['walls']), $l['floors'])), $layouts))], JSON_THROW_ON_ERROR) . "\n";
        return 0;
    }
    throw new InvalidArgumentException('Usage: php labyrinth_bake.php --mode assets --base-widget F --baseline-world F --output-dir D [--set pyramid] | --mode layouts --log F --output F [--markers F]');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(LabyrinthBakeMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
