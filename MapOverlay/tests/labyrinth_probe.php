<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/radar_widget.php';

/**
 * Isolated Crystal Labyrinth layout probe; mutates only this test process's memory.
 *
 * Spawns the game's own procedural BP_MirrorMaze with fixed parameters, then reads
 * the reflected AMirrorMazePuzzle.generatedMaze cells (FMirrorMazeCell) and samples
 * of the wall/ceiling/trap-door instanced meshes. Shipping builds compile out
 * PrintString, so the readings travel in the pass/fail map URL options, which the
 * engine log records verbatim on its "LoadMap:" line.
 */
function BuildLabyrinthProbe(string $output, int $seed = 98217, int $cells = 3, int $samples = 4, ?string $level = null, float $wait = 8.0, int $maxCells = 400, bool $handmade = false, bool $inventoryMode = false, int $maxEntries = 1500, array $include = [], array $exclude = [], bool $instances = false): array
{
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $b = new RadarWidgetBuilder($base);
    $b->asset->Exports = [$b->class, $b->default];
    $actor = $b->native['Actor'];
    $bp = OverlayImport($b->asset, 'BlueprintGeneratedClass', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $bpDefault = OverlayImport($b->asset, 'Default__BlueprintGeneratedClass', $b->native['Engine'], '/Script/Engine', 'BlueprintGeneratedClass');
    $actorDefault = OverlayImport($b->asset, 'Default__Actor', $b->native['Engine'], '/Script/Engine', 'Actor');
    $b->class->ObjectName = 'BP_SSRadarTest_C';
    $b->class->ClassIndex = $bp;
    $b->class->TemplateIndex = $bpDefault;
    $b->class->ClassFlags = 'CLASS_Parsed, CLASS_ReplicationDataIsSetUp, CLASS_CompiledFromBlueprint';
    $b->class->SuperStruct = $b->class->SuperIndex = $actor;
    $b->class->LoadedProperties = $b->class->Data = [];
    $b->class->SerializationBeforeSerializationDependencies = [$actor];
    $b->class->CreateBeforeSerializationDependencies = [];
    $b->class->SerializationBeforeCreateDependencies = [$bp, $bpDefault];
    $b->class->CreateBeforeCreateDependencies = [$actor];
    $b->default->ObjectName = 'Default__BP_SSRadarTest_C';
    $b->default->TemplateIndex = $actorDefault;
    $b->default->Data = [];
    $b->default->SerializationBeforeCreateDependencies = [1, $actorDefault];

    foreach (['KismetStringLibrary', 'InstancedStaticMeshComponent', 'LevelStreamingDynamic', 'LevelStreaming', 'StaticMeshComponent', 'ActorComponent', 'SceneComponent', 'BoxComponent', 'PrimitiveComponent'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['Engine'], '/Script/CoreUObject', 'Class');
    }
    $b->native['MirrorMazePuzzle'] = OverlayImport($b->asset, 'MirrorMazePuzzle', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    $b->native['MirrorMazeCell'] = OverlayImport($b->asset, 'MirrorMazeCell', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    foreach (['IntVector', 'Transform'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $b->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct');
    }
    $mazePackage = OverlayImport($b->asset, '/Game/ASophia/Puzzle/MirrorMaze/' . ($handmade ? 'BP_HandmadeMirrorMaze' : 'BP_MirrorMaze'), 0, '/Script/CoreUObject', 'Package');
    $mazeClass = OverlayImport($b->asset, ($handmade ? 'BP_HandmadeMirrorMaze_C' : 'BP_MirrorMaze_C'), $mazePackage, '/Script/Engine', 'BlueprintGeneratedClass');

    $b->objectField('Maze', $b->native['MirrorMazePuzzle']);
    $b->scalar('Str', 'Out', '?game=/Script/Engine.GameModeBase?lab=' . ($level === null ? 'spawn' : ''));
    $b->scalar('Bool', 'Passed', false);
    $b->scalar('Bool', 'Regenerate', $level === null && !$handmade);
    $b->scalar('Int', 'TotalCells', 0);
    $b->scalar('Int', 'Tries', 0);
    $b->scalar('Bool', 'Settled', false);
    $b->arrayField('Object', 'Found', []);
    $b->arrayField('Object', 'Actors', []);
    $b->arrayField('Object', 'Comps', []);
    $b->scalar('Int', 'Entries', 0);
    $levels = $level === null ? [] : explode(',', $level);
    foreach (array_keys($levels) as $k) { $b->objectField('Stream' . $k, $b->native['LevelStreamingDynamic']); }
    foreach ($b->class->LoadedProperties as $p) {
        if (in_array($p->Name, ['Found', 'Actors'], true)) { $p->Inner->PropertyClass = $actor; }
        if ($p->Name === 'Comps') { $p->Inner->PropertyClass = $b->native['ActorComponent']; }
    }
    foreach (['ReceiveBeginPlay', 'DumpMaze', 'Inventory', 'VerifyLive', 'Finish'] as $fn) { $b->reserve($fn); }

    $s = static fn(string $name): object => FilterVariable(1, $name, true);
    $set = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer(1, $name), 'Variable' => $s($name), 'Expression' => $value]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $str = static fn(string $v): object => FilterExpr('StringConst', ['Value' => $v]);
    $truth = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $text = static fn(string $fn, object ...$args): object => $b->call('KismetStringLibrary', $fn, $args);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $vec = static fn(float $x, float $y, float $z): object => $math('MakeVector', $f($x), $f($y), $f($z));
    $transform = static fn(): object => $math('MakeTransform', $vec(1000, 2000, 3000), $math('MakeRotator', $f(0), $f(0), $f(0)), $vec(1, 1, 1));
    $property = static fn(object $obj, string $owner, string $name): object => RadarContext($obj, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $assign = static fn(object $obj, string $owner, string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($b->native[$owner], $name), 'Variable' => $property($obj, $owner, $name), 'Expression' => $value]);
    $member = static fn(string $struct, string $name, object $of): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $name), 'StructExpression' => $of]);
    $timer = static function (string $owner, string $callback, float $delay) use ($b, $f, $truth): object {
        $fn = $b->functions[$owner];
        return FilterExpr('Let', ['Value' => FilterPointer($fn, 'Timer'), 'Variable' => FilterVariable($fn, 'Timer'), 'Expression' => $b->call('KismetSystemLibrary', 'K2_SetTimer', [FilterExpr('Self'), FilterExpr('StringConst', ['Value' => $callback]), $f($delay), $truth(false), $f(0), $f(0)])]);
    };
    // Out = Out + a + b + ...; every piece must already be a string expression.
    $append = static function (object ...$parts) use ($set, $s, $text): object {
        $tail = array_pop($parts);
        while ($parts !== []) { $tail = $text('Concat_StrStr', array_pop($parts), $tail); }
        return $set('Out', $text('Concat_StrStr', $s('Out'), $tail));
    };
    $int = static fn(object $v): object => $text('Conv_IntToString', $v);
    $round = static fn(object $v): object => $int($math('Round', $v));

    $jumpTo = static fn(string $target): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $target]);
    $unlessTo = static fn(object $condition, string $target): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $target, 'BooleanExpression' => $condition]);

    // DumpMaze appends one maze record for instance variable Maze: summary, mesh samples, then every cell.
    $fn = $b->functions['DumpMaze'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $mazeProp = static fn(string $name): object => $property($s('Maze'), 'MirrorMazePuzzle', $name);
    $vector = static fn(object $v): array => [$round($member('Vector', 'X', $v)), $str(','), $round($member('Vector', 'Y', $v)), $str(','), $round($member('Vector', 'Z', $v))];
    $coordList = static function (string $field, string $label) use ($member, $l, $let, $b, $i, $math, $int, $str, $append, $unlessTo, $jumpTo): array {
        $list = $member('MirrorMazeCell', $field, $l('Cell'));
        return [$append($str($label)), $let('J', $i(0)), ['label' => $label . 'Loop'],
            $unlessTo($math('Less_IntInt', $l('J'), $b->call('KismetArrayLibrary', 'Array_Length', [$list])), $label . 'Done'),
            $b->call('KismetArrayLibrary', 'Array_Get', [unserialize(serialize($list)), $l('J'), $l('Coord')]),
            $append($int($member('IntVector', 'X', $l('Coord'))), $str('.'), $int($member('IntVector', 'Y', $l('Coord'))), $str('.'), $int($member('IntVector', 'Z', $l('Coord'))), $str('/')),
            $let('J', $math('Add_IntInt', $l('J'), $i(1))), $jumpTo($label . 'Loop'), ['label' => $label . 'Done']];
    };
    $meshes = ['clearWallInstancedStaticMesh' => 'cw', 'opaqueWallInstancedStaticMesh' => 'ow', 'ceilingInstancedStaticMesh' => 'ce', 'trapDoorInstancedStaticMesh' => 'td', 'cylinderInstancedStaticMesh' => 'cy'];
    $meshCode = [];
    foreach ($meshes as $prop => $tag) {
        array_push($meshCode,
            $let('Comp', $mazeProp($prop)), $append($str(';' . $tag . '=')),
            $unlessTo($valid($l('Comp')), $tag . 'Done'),
            $let('Count', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceCount', [], FilterPointer($fn, 'Count'))),
            $append($int($l('Count'))),
            $let('K', $i(0)), ['label' => $tag . 'Loop'],
            $unlessTo($math('BooleanAND', $math('Less_IntInt', $l('K'), $l('Count')), $math('Less_IntInt', $l('K'), $i($samples))), $tag . 'Done'),
            $let('Ok', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceTransform', [$l('K'), $l('Xf'), $truth(true)], FilterPointer($fn, 'Ok'))),
            // UE4.27 KismetMathLibrary has no TransformVector; axis = TransformLocation(axis) - origin, scaled by 100.
            $let('Origin', $math('TransformLocation', $l('Xf'), $vec(0, 0, 0))), $append($str('|'), ...$vector($l('Origin'))),
            $let('Loc', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec(100, 0, 0)), $l('Origin'))), $append($str('x'), ...$vector($l('Loc'))),
            $let('Loc', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec(0, 100, 0)), $l('Origin'))), $append($str('y'), ...$vector($l('Loc'))),
            $let('Loc', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec(0, 0, 100)), $l('Origin'))), $append($str('z'), ...$vector($l('Loc'))),
            $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jumpTo($tag . 'Loop'), ['label' => $tag . 'Done']);
    }
    $dims = static fn(string $axis): object => $int($member('IntVector', $axis, $mazeProp('numMazeCellsXYZ')));
    $dump = [
        $let('Name', $b->call('KismetSystemLibrary', 'GetObjectName', [$s('Maze')])),
        $let('N', $b->call('KismetArrayLibrary', 'Array_Length', [$mazeProp('generatedMaze')])),
        $append($str('~maze='), $l('Name'), $str(';gen0='), $int($l('N'))),
        // Generation normally waits for the puzzle system to feed params; force it once if requested and empty.
        $unlessTo($math('BooleanAND', $s('Regenerate'), $math('EqualEqual_IntInt', $l('N'), $i(0))), 'Generated'),
        $b->method($s('Maze'), 'MirrorMazePuzzle', 'RegenerateMaze', []),
        $let('N', $b->call('KismetArrayLibrary', 'Array_Length', [$mazeProp('generatedMaze')])),
        ['label' => 'Generated'],
        $append($str(';gen1='), $int($l('N'))),
        $append($str(';type='), $int($math('Conv_ByteToInt', $mazeProp('generationType'))), $str(';goal='), $int($mazeProp('totalGoalDistance')), $str(';seed='), $int($mazeProp('randSeed'))),
        $append($str(';dims='), $dims('X'), $str(','), $dims('Y'), $str(','), $dims('Z')),
        $let('Count', $b->call('KismetArrayLibrary', 'Array_Length', [$mazeProp('BoundingVolumes')])),
        $append($str(';bounds='), $int($l('Count')), $str(';box=')),
        // Each bounding volume: centre, yaw and scaled half extents, '|' separated.
        $let('K', $i(0)), ['label' => 'BoxLoop'],
        $unlessTo($math('Less_IntInt', $l('K'), $l('Count')), 'BoxDone'),
        $b->call('KismetArrayLibrary', 'Array_Get', [$mazeProp('BoundingVolumes'), $l('K'), $l('Box')]),
        $let('Box', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['BoxComponent'], 'TargetExpression' => $l('Box')])),
        $unlessTo($valid($l('Box')), 'BoxNext'),
        $let('Loc', $b->method($l('Box'), 'SceneComponent', 'K2_GetComponentLocation', [], FilterPointer($fn, 'Loc'))),
        $let('Rot', $b->method($l('Box'), 'SceneComponent', 'K2_GetComponentRotation', [], FilterPointer($fn, 'Rot'))),
        $let('Ext', $b->method($l('Box'), 'BoxComponent', 'GetScaledBoxExtent', [], FilterPointer($fn, 'Ext'))),
        $append(...[...$vector($l('Loc')), $str(','), $round($member('Rotator', 'Yaw', $l('Rot'))), $str(','), ...$vector($l('Ext')), $str('|')]),
        ['label' => 'BoxNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jumpTo('BoxLoop'), ['label' => 'BoxDone'],
        $append($str(';cell='), ...$vector($mazeProp('cellSize'))),
        $let('Loc', $b->method($s('Maze'), 'Actor', 'K2_GetActorLocation', [], FilterPointer($fn, 'Loc'))),
        $append($str(';at='), ...$vector($l('Loc'))),
        $let('Rot', $b->method($s('Maze'), 'Actor', 'K2_GetActorRotation', [], FilterPointer($fn, 'Rot'))),
        $append($str(';yaw='), $round($member('Rotator', 'Yaw', $l('Rot')))),
        $let('Loc', $b->method($s('Maze'), 'Actor', 'GetActorScale3D', [], FilterPointer($fn, 'Loc'))),
        $let('Loc', $math('Multiply_VectorFloat', $l('Loc'), $f(100))),
        $append($str(';scale100='), ...$vector($l('Loc'))),
        ...$meshCode,
        $let('I', $i(0)), ['label' => 'CellLoop'],
        $unlessTo($math('BooleanAND', $math('Less_IntInt', $l('I'), $l('N')), $math('Less_IntInt', $l('I'), $i($maxCells))), 'CellDone'),
        $b->call('KismetArrayLibrary', 'Array_Get', [$mazeProp('generatedMaze'), $l('I'), $l('Cell')]),
        $append($str(';c'), $int($member('MirrorMazeCell', 'posX', $l('Cell'))), $str(','), $int($member('MirrorMazeCell', 'posY', $l('Cell'))), $str(','), $int($member('MirrorMazeCell', 'floor', $l('Cell'))),
            $str(',t'), $int($math('Conv_BoolToInt', $member('MirrorMazeCell', 'hasTrapDoor', $l('Cell')))), $str(',u'), $int($member('MirrorMazeCell', 'cellsConnectedAbove', $l('Cell')))),
        ...$coordList('adjacentCells', ':a'),
        ...$coordList('opaqueCells', ':o'),
        $let('I', $math('Add_IntInt', $l('I'), $i(1))), $jumpTo('CellLoop'), ['label' => 'CellDone'],
        $set('TotalCells', $math('Add_IntInt', $s('TotalCells'), $l('N'))),
    ];
    $locals = ['Name' => 'Str', 'N' => 'Int', 'I' => 'Int', 'J' => 'Int', 'K' => 'Int', 'Count' => 'Int', 'Ok' => 'Bool', 'Comp' => 'Object', 'Box' => 'Object', 'Cell' => 'Struct', 'Coord' => 'Struct', 'Xf' => 'Struct', 'Loc' => 'Struct', 'Origin' => 'Struct', 'Rot' => 'Struct', 'Ext' => 'Struct'];
    $b->define('DumpMaze', [], $locals, $dump);
    $structs = ['Cell' => ['MirrorMazeCell', 0x40], 'Coord' => ['IntVector', 12], 'Xf' => ['Transform', 48], 'Rot' => ['Rotator', 12]];
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
        if (isset($structs[$p->Name])) { [$type, $size] = $structs[$p->Name]; $p->Struct = $b->native[$type]; $p->ElementSize = $size; }
        if ($p->Name === 'Comp') { $p->PropertyClass = $b->native['InstancedStaticMeshComponent']; }
    }


    // Inventory lists every static-mesh component in the world: mesh name, instance count, world bounds.
    $fn = $b->functions['Inventory'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $vector = static fn(object $v): array => [$round($member('Vector', 'X', $v)), $str(','), $round($member('Vector', 'Y', $v)), $str(','), $round($member('Vector', 'Z', $v))];
    $inventory = [
        $b->call('GameplayStatics', 'GetAllActorsOfClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $actor]), $s('Actors')]),
        $let('NA', $b->call('KismetArrayLibrary', 'Array_Length', [$s('Actors')])),
        $append($str(';actors='), $int($l('NA'))),
        $let('A', $i(0)), ['label' => 'ActorLoop'],
        $unlessTo($math('Less_IntInt', $l('A'), $l('NA')), 'ActorDone'),
        $b->call('KismetArrayLibrary', 'Array_Get', [$s('Actors'), $l('A'), $l('Act')]),
        $set('Comps', $b->method($l('Act'), 'Actor', 'K2_GetComponentsByClass', [FilterExpr('ObjectConst', ['Value' => $b->native['StaticMeshComponent']])], FilterPointer(1, 'Comps'))),
        $let('NC', $b->call('KismetArrayLibrary', 'Array_Length', [$s('Comps')])),
        $let('C', $i(0)), ['label' => 'CompLoop'],
        $unlessTo($math('BooleanAND', $math('Less_IntInt', $l('C'), $l('NC')), $math('Less_IntInt', $s('Entries'), $i($maxEntries))), 'CompDone'),
        $b->call('KismetArrayLibrary', 'Array_Get', [$s('Comps'), $l('C'), $l('Item')]),
        $let('Comp', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['StaticMeshComponent'], 'TargetExpression' => $l('Item')])),
        $unlessTo($valid($l('Comp')), 'CompNext'),
        $let('Mesh', $property($l('Comp'), 'StaticMeshComponent', 'StaticMesh')),
        $unlessTo($valid($l('Mesh')), 'CompNext'),
        $let('Name', $b->call('KismetSystemLibrary', 'GetObjectName', [$l('Mesh')])),
        // Optional mesh-name filters: skip any excluded token; with includes, require one of them.
        ...array_map(static fn(string $t): object => $unlessTo($math('Not_PreBool', $text('Contains', $l('Name'), $str($t), $truth(false), $truth(false))), 'CompNext'), $exclude),
        ...($include === [] ? [] : [$let('Keep', $truth(false)),
            ...array_map(static fn(string $t): object => $let('Keep', $math('BooleanOR', $l('Keep'), $text('Contains', $l('Name'), $str($t), $truth(false), $truth(false)))), $include),
            $unlessTo($l('Keep'), 'CompNext')]),
        $let('Count', $i(1)),
        $let('Ism', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['InstancedStaticMeshComponent'], 'TargetExpression' => $l('Comp')])),
        $unlessTo($valid($l('Ism')), 'Counted'),
        $let('Count', $b->method($l('Ism'), 'InstancedStaticMeshComponent', 'GetInstanceCount', [], FilterPointer($fn, 'Count'))),
        ['label' => 'Counted'],
        $b->call('KismetSystemLibrary', 'GetComponentBounds', [$l('Comp'), $l('Origin'), $l('Ext'), $l('Radius')]),
        $let('Mat', $b->method($l('Comp'), 'PrimitiveComponent', 'GetMaterial', [$i(0)], FilterPointer($fn, 'Mat'))),
        $let('MatName', $str('')),
        $unlessTo($valid($l('Mat')), 'NoMaterial'),
        $let('MatName', $b->call('KismetSystemLibrary', 'GetObjectName', [$l('Mat')])),
        ['label' => 'NoMaterial'],
        $append($str(';m'), $l('Name'), $str(':'), $int($l('Count')), $str('@'), ...[...$vector($l('Origin')), $str('~'), ...$vector($l('Ext')), $str('^'), $l('MatName')]),
        $set('Entries', $math('Add_IntInt', $s('Entries'), $i(1))),
        // With --instances, follow each component with its per-instance world transforms (origin, then 100-unit X/Y/Z axes).
        ...(!$instances ? [] : [
            $let('K', $i(0)), ['label' => 'InstLoop'],
            $unlessTo($math('Less_IntInt', $l('K'), $l('Count')), 'CompNext'),
            $unlessTo($valid($l('Ism')), 'Single'),
            $let('Ok', $b->method($l('Ism'), 'InstancedStaticMeshComponent', 'GetInstanceTransform', [$l('K'), $l('Xf'), $truth(true)], FilterPointer($fn, 'Ok'))),
            $jumpTo('HaveXf'), ['label' => 'Single'],
            $let('Xf', $b->method($l('Comp'), 'SceneComponent', 'K2_GetComponentToWorld', [], FilterPointer($fn, 'Xf'))),
            ['label' => 'HaveXf'],
            $let('Origin', $math('TransformLocation', $l('Xf'), $vec(0, 0, 0))), $append($str(';i'), ...$vector($l('Origin'))),
            $let('Ext', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec(100, 0, 0)), $l('Origin'))), $append($str('x'), ...$vector($l('Ext'))),
            $let('Ext', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec(0, 100, 0)), $l('Origin'))), $append($str('y'), ...$vector($l('Ext'))),
            $let('Ext', $math('Subtract_VectorVector', $math('TransformLocation', $l('Xf'), $vec(0, 0, 100)), $l('Origin'))), $append($str('z'), ...$vector($l('Ext'))),
            $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jumpTo('InstLoop'),
        ]),
        ['label' => 'CompNext'],
        $let('C', $math('Add_IntInt', $l('C'), $i(1))), $jumpTo('CompLoop'), ['label' => 'CompDone'],
        $let('A', $math('Add_IntInt', $l('A'), $i(1))), $jumpTo('ActorLoop'), ['label' => 'ActorDone'],
        $append($str(';entries='), $int($s('Entries'))),
    ];
    $b->define('Inventory', [], ['NA' => 'Int', 'A' => 'Int', 'NC' => 'Int', 'C' => 'Int', 'Count' => 'Int', 'Act' => 'Object', 'Item' => 'Object', 'Comp' => 'Object', 'Ism' => 'Object', 'Mesh' => 'Object', 'Name' => 'Str', 'Keep' => 'Bool', 'Mat' => 'Object', 'MatName' => 'Str', 'K' => 'Int', 'Ok' => 'Bool', 'Xf' => 'Struct', 'Origin' => 'Struct', 'Ext' => 'Struct', 'Radius' => 'Float'], $inventory);
    $classes = ['Act' => $actor, 'Item' => $b->native['ActorComponent'], 'Comp' => $b->native['StaticMeshComponent'], 'Ism' => $b->native['InstancedStaticMeshComponent']];
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
        if (isset($classes[$p->Name])) { $p->PropertyClass = $classes[$p->Name]; }
        if ($p->Name === 'Xf') { $p->Struct = $b->native['Transform']; $p->ElementSize = 48; }
    }

    // Procedural mode spawns BP_MirrorMaze with fixed params (set before FinishSpawning, so a
    // BeginPlay-time generation would already use them). Level mode streams a real submap in place.
    $bf = $b->functions['ReceiveBeginPlay'];
    if ($level === null) {
        $begin = [
            $set('Maze', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['MirrorMazePuzzle'], 'TargetExpression' => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $mazeClass]), $transform(), FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')])])),
            $unlessTo($valid($s('Maze')), 'Spawned'),
            $assign($s('Maze'), 'MirrorMazePuzzle', 'randSeed', $i($seed)),
            $assign($s('Maze'), 'MirrorMazePuzzle', 'numMazeCellsXYZ', $math('Conv_IntToIntVector', $i($cells))),
            $assign($s('Maze'), 'MirrorMazePuzzle', 'opaqueWallPercentChance', $f(0.3)),
            $assign($s('Maze'), 'MirrorMazePuzzle', 'verticalPathsChance', $f(0.3)),
            $assign($s('Maze'), 'MirrorMazePuzzle', 'trapDoorChance', $f(0.3)),
            $set('Maze', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['MirrorMazePuzzle'], 'TargetExpression' => $b->call('GameplayStatics', 'FinishSpawningActor', [$s('Maze'), $transform()])])),
            ['label' => 'Spawned'],
            $timer('ReceiveBeginPlay', 'VerifyLive', 1.0),
        ];
    } else {
        $begin = [];
        foreach ($levels as $k => $path) {
            array_push($begin,
                $set('Stream' . $k, $b->call('LevelStreamingDynamic', 'LoadLevelInstance', [FilterExpr('Self'), $str($path), $vec(0, 0, 0), $math('MakeRotator', $f(0), $f(0), $f(0)), FilterVariable($bf, 'Loaded')])),
                $append($str('level' . $k . '='), $int($math('Conv_BoolToInt', FilterVariable($bf, 'Loaded'))), $str(';')));
        }
        $begin[] = $timer('ReceiveBeginPlay', 'VerifyLive', $wait);
    }
    $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct', 'Streamed' => 'Object', 'Loaded' => 'Bool'], $begin);
    $receive = $b->asset->Exports[$bf - 1];
    $receive->SuperStruct = $receive->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $actor, '/Script/CoreUObject', 'Function');
    $receive->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $receive->SerializationBeforeSerializationDependencies = [$receive->SuperStruct];

    $fn = $b->functions['VerifyLive'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $checks = [];
    $assert = static function (object $condition, string $name) use (&$checks, $unlessTo): object { $checks[] = $name; return $unlessTo($condition, 'Fail'); };
    $callDump = static fn(): object => FilterCall($b->functions['DumpMaze'], [], 'LocalFinalFunction');
    if ($level === null) {
        $code = [
            $assert($valid($s('Maze')), 'BP_MirrorMaze spawned as native MirrorMazePuzzle'),
            $callDump(),
            $assert($math('Greater_IntInt', $s('TotalCells'), $i(0)), 'generatedMaze holds cells'),
        ];
    } else {
        $code = [
            // Streaming is asynchronous: poll until every streamed level is visible, then allow one more round for BeginPlay.
            $let('Ready', $truth(true)),
            ...array_merge([], ...array_map(static fn(int $k): array => [
                $unlessTo($valid($s('Stream' . $k)), 'NotReady'),
                $let('Visible', $b->method($s('Stream' . $k), 'LevelStreaming', 'IsLevelVisible', [], FilterPointer($fn, 'Visible'))),
                $let('Ready', $math('BooleanAND', $l('Ready'), $l('Visible'))),
            ], array_keys($levels))),
            $jumpTo('ReadyChecked'), ['label' => 'NotReady'], $let('Ready', $truth(false)), ['label' => 'ReadyChecked'],
            $b->call('GameplayStatics', 'GetAllActorsOfClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['MirrorMazePuzzle']]), $s('Found')]),
            $let('Count', $b->call('KismetArrayLibrary', 'Array_Length', [$s('Found')])),
            $unlessTo($math('BooleanAND', $math('Not_PreBool', $l('Ready')), $math('Less_IntInt', $s('Tries'), $i(60))), 'Present'),
            $set('Tries', $math('Add_IntInt', $s('Tries'), $i(1))),
            $timer('VerifyLive', 'VerifyLive', 2.0), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]),
            ['label' => 'Present'],
            $unlessTo($math('Not_PreBool', $s('Settled')), 'Settled'),
            FilterExpr('LetBool', ['VariableExpression' => $s('Settled'), 'AssignmentExpression' => $truth(true)]),
            $timer('VerifyLive', 'VerifyLive', 3.0), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]),
            ['label' => 'Settled'],
            $append($str('tries='), $int($s('Tries')), $str(';ready='), $int($math('Conv_BoolToInt', $l('Ready'))), $str(';found='), $int($l('Count'))),
            $let('I', $i(0)), ['label' => 'MazeLoop'],
            $unlessTo($math('Less_IntInt', $l('I'), $l('Count')), 'MazeDone'),
            $b->call('KismetArrayLibrary', 'Array_Get', [$s('Found'), $l('I'), $l('Item')]),
            $set('Maze', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['MirrorMazePuzzle'], 'TargetExpression' => $l('Item')])),
            $unlessTo($valid($s('Maze')), 'MazeNext'),
            $callDump(),
            ['label' => 'MazeNext'],
            $let('I', $math('Add_IntInt', $l('I'), $i(1))), $jumpTo('MazeLoop'), ['label' => 'MazeDone'],
            ...($inventoryMode ? [FilterCall($b->functions['Inventory'], [], 'LocalFinalFunction')] : []),
            $assert($l('Ready'), 'every streamed level became visible'),
        ];
    }
    array_push($code, FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $truth(true)]), $timer('VerifyLive', 'Finish', 0.2), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer('VerifyLive', 'Finish', 0.2));
    $b->define('VerifyLive', [], ['I' => 'Int', 'Count' => 'Int', 'Item' => 'Object', 'Ready' => 'Bool', 'Visible' => 'Bool', 'Timer' => 'Struct'], $code);
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) { if ($p->Name === 'Item') { $p->PropertyClass = $actor; } }

    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')]), $truth(true), $s('Out')]);
    $b->define('Finish', [], [], [FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $s('Passed')]), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)]);
    foreach (['/Game/SaveStatsTests/BP_SSRadarTest', '/Game/SaveStatsTests/SSRadarPass', '/Game/SaveStatsTests/SSRadarFail'] as $name) { $b->name($name); }
    $asset = $b->finish();
    AssertNoContextCallArguments($asset, 'labyrinth probe');
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($asset, JSON_THROW_ON_ERROR));
    foreach (['Login', 'SSRadarPass', 'SSRadarFail'] as $name) { copy(OverlayTestDataPath('EngineFixtures/' . $name . '.json'), $output . '/' . $name . '.json'); }
    $source = $level === null
        ? ['mode' => 'spawn', 'mazeClass' => $handmade ? '/Game/ASophia/Puzzle/MirrorMaze/BP_HandmadeMirrorMaze.BP_HandmadeMirrorMaze_C' : '/Game/ASophia/Puzzle/MirrorMaze/BP_MirrorMaze.BP_MirrorMaze_C',
            'params' => ['randSeed' => $seed, 'numMazeCellsXYZ' => [$cells, $cells, $cells], 'opaqueWallPercentChance' => 0.3, 'verticalPathsChance' => 0.3, 'trapDoorChance' => 0.3]]
        : ['mode' => $inventoryMode ? 'inventory' : 'level', 'levels' => $levels, 'waitSeconds' => $wait, 'include' => $include, 'exclude' => $exclude];
    $manifest = ['assertions' => count($checks), 'checks' => $checks] + $source
        + ['output' => 'LoadMap URL option lab=', 'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $manifest;
}

/** Decode the lab= option from an engine log LoadMap line into one record per maze (cells, meshes, summary). */
function ParseLabyrinthProbeLog(string $log): ?array
{
    if (!preg_match('/LoadMap: \/Game\/SaveStatsTests\/(SSRadarPass|SSRadarFail)\S*\?lab=(\S*)/', $log, $m)) { return null; }
    $records = explode('~', $m[2]);
    $header = array_shift($records);
    $parsed = ['outcome' => $m[1] === 'SSRadarPass' ? 'pass' : 'fail', 'header' => $header, 'mazes' => array_map('ParseLabyrinthProbeRecord', $records)];
    return $parsed;
}

function ParseLabyrinthProbeRecord(string $record): array
{
    $result = ['cells' => [], 'meshes' => []];
    foreach (explode(';', $record) as $entry) {
        if ($entry === '') { continue; }
        if ($entry[0] === 'c' && preg_match('/^c(-?\d+),(-?\d+),(-?\d+),t(\d+),u(-?\d+):a([^:]*):o(.*)$/', $entry, $c)) {
            $coords = static fn(string $list): array => array_values(array_map(static fn(string $v): array => array_map('intval', explode('.', $v)), array_filter(explode('/', $list))));
            $result['cells'][] = ['x' => (int)$c[1], 'y' => (int)$c[2], 'floor' => (int)$c[3], 'trapDoor' => $c[4] === '1', 'connectedAbove' => (int)$c[5], 'adjacent' => $coords($c[6]), 'opaque' => $coords($c[7])];
            continue;
        }
        [$key, $value] = array_pad(explode('=', $entry, 2), 2, '');
        if (in_array($key, ['cw', 'ow', 'ce', 'td', 'cy'], true)) {
            $parts = explode('|', $value);
            $samples = [];
            foreach (array_slice($parts, 1) as $sample) {
                if (preg_match('/^(-?\d+),(-?\d+),(-?\d+)x(-?\d+),(-?\d+),(-?\d+)y(-?\d+),(-?\d+),(-?\d+)z(-?\d+),(-?\d+),(-?\d+)$/', $sample, $v)) {
                    $n = array_map('intval', array_slice($v, 1));
                    $samples[] = ['at' => array_slice($n, 0, 3), 'x' => array_slice($n, 3, 3), 'y' => array_slice($n, 6, 3), 'z' => array_slice($n, 9, 3)];
                }
            }
            $result['meshes'][$key] = ['count' => (int)$parts[0], 'samples' => $samples];
        } else {
            $result[$key] = $key === 'maze' ? $value : (str_contains($value, ',') ? array_map('intval', explode(',', $value)) : (int)$value);
        }
    }
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['output-dir:', 'seed:', 'cells:', 'samples:', 'level:', 'wait:', 'parse-log:', 'handmade', 'inventory', 'max-entries:', 'include:', 'exclude:', 'instances']);
        if (isset($options['parse-log'])) {
            $parsed = ParseLabyrinthProbeLog((string)file_get_contents($options['parse-log']));
            if ($parsed === null) { throw new RuntimeException('No labyrinth probe LoadMap line in log.'); }
            echo json_encode($parsed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            exit(0);
        }
        if (!isset($options['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir'); }
        echo json_encode(BuildLabyrinthProbe(realpath($options['output-dir']), (int)($options['seed'] ?? 98217), (int)($options['cells'] ?? 3), (int)($options['samples'] ?? 4), $options['level'] ?? null, (float)($options['wait'] ?? 8.0), 400, isset($options['handmade']), isset($options['inventory']), (int)($options['max-entries'] ?? 1500), array_filter(explode(',', $options['include'] ?? '')), array_filter(explode(',', $options['exclude'] ?? '')), isset($options['instances'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
