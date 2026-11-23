<?php

declare(strict_types=1);

/**
 * Crystal Labyrinth floor view for the gameplay radar.
 *
 * Authority: SophiaCharacter.CharPuzzleComponent.GetCurrentMirrorMaze() (first MirrorMazePuzzle in
 * workedOnPuzzles). Only procedural (Hub) labyrinths carry a generated cell graph; handmade ones have
 * an empty generatedMaze and are ignored. The player's floor comes from their position in the maze
 * actor's own frame divided by cellSize.Z. Walls are the maze's own clear/opaque wall instances whose
 * origin lies on that floor; each instance is a 100-unit mesh centred on its origin along local X.
 * Up/down markers come from cells whose adjacentCells reach the floor above or below.
 * Holes (drops) are the cells of the current floor with no ceiling instance from the floor below: each
 * floor's ceiling tiles sit at (k + 0.5) * cell height, and both trapdoors and vertical links leave
 * the tile out. The ground floor has no holes.
 *
 * Handmade (world) labyrinths have no cell graph; their floors are baked at build time
 * (labyrinth_bake.php) into flat tables. When no Hub maze applies, the player's position is tested
 * against each baked maze's oriented bounding volumes; the floor is the highest baked floor at or
 * below the player, and its walls and holes load into the same arrays the Hub layer draws.
 */
// Large enough for the biggest baked handmade floor (215 walls); Hub floors need at most 84.
const RADAR_LABYRINTH_WALLS = 240;
const RADAR_LABYRINTH_MARKS = 12;
const RADAR_LABYRINTH_HOLES = 36;
const RADAR_LABYRINTH_INT_TABLES = ['MazeBoxStart', 'MazeBoxEnd', 'MazeFloorStart', 'MazeFloorEnd', 'FloorWallStart', 'FloorWallEnd', 'FloorHoleStart', 'FloorHoleEnd',
    'FloorMarkStart', 'FloorMarkEnd', 'WallKind', 'MarkKind', 'MazePid'];
const RADAR_LABYRINTH_REBUILD_TICKS = 20;

/** Flatten baked handmade layouts into parallel arrays for the widget. */
function RadarLabyrinthTables(array $layouts): array
{
    $t = array_fill_keys(['MazeX', 'MazeY', 'MazeR', 'MazeYaw', 'MazeCell', 'BoxX', 'BoxY', 'BoxZ', 'BoxYaw', 'BoxEX', 'BoxEY', 'BoxEZ', 'FloorZ', 'FloorView',
        'WallAX', 'WallAY', 'WallBX', 'WallBY', 'HoleX', 'HoleY', 'MarkX', 'MarkY'], []);
    $t += array_fill_keys(RADAR_LABYRINTH_INT_TABLES, []);
    foreach ($layouts as $maze) {
        $boxes = $maze['boxes'] ?? []; $floors = $maze['floors'] ?? [];
        if ($boxes === [] || $floors === []) { throw new InvalidArgumentException('Each baked labyrinth needs bounding volumes and floors.'); }
        $zs = array_column($floors, 'z');
        $sorted = $zs; sort($sorted);
        if ($zs !== $sorted) { throw new InvalidArgumentException('Baked labyrinth floors must be in ascending height.'); }
        // Prefilter circle around the volumes' centre.
        $cx = array_sum(array_column($boxes, 'x')) / count($boxes); $cy = array_sum(array_column($boxes, 'y')) / count($boxes);
        $r = 0.0;
        foreach ($boxes as $box) { $r = max($r, hypot($box['x'] - $cx, $box['y'] - $cy) + hypot($box['ex'], $box['ey'])); }
        array_push($t['MazeX'], $cx); array_push($t['MazeY'], $cy); $t['MazeR'][] = $r + 100; $t['MazeYaw'][] = (float)$maze['yaw']; $t['MazeCell'][] = (float)$maze['cell']; $t['MazePid'][] = (int)($maze['pid'] ?? 0);
        $t['MazeBoxStart'][] = count($t['BoxX']);
        foreach ($boxes as $box) {
            foreach (['x' => 'BoxX', 'y' => 'BoxY', 'z' => 'BoxZ', 'yaw' => 'BoxYaw', 'ex' => 'BoxEX', 'ey' => 'BoxEY', 'ez' => 'BoxEZ'] as $k => $n) { $t[$n][] = (float)$box[$k]; }
        }
        $t['MazeBoxEnd'][] = count($t['BoxX']);
        $t['MazeFloorStart'][] = count($t['FloorZ']);
        foreach ($floors as $floor) {
            $t['FloorZ'][] = (float)$floor['z'];
            // View fits the floor from anywhere on it: twice its radius about the walls' centre.
            $points = array_merge(...array_map(static fn(array $w): array => [[$w[0], $w[1]], [$w[2], $w[3]]], $floor['walls']));
            $mx = array_sum(array_column($points, 0)) / count($points); $my = array_sum(array_column($points, 1)) / count($points);
            $fr = 0.0; foreach ($points as [$px, $py]) { $fr = max($fr, hypot($px - $mx, $py - $my)); }
            $t['FloorView'][] = max(2000.0, 2 * $fr);
            $t['FloorWallStart'][] = count($t['WallAX']);
            foreach ($floor['walls'] as $w) { array_push($t['WallAX'], (float)$w[0]); $t['WallAY'][] = (float)$w[1]; $t['WallBX'][] = (float)$w[2]; $t['WallBY'][] = (float)$w[3]; $t['WallKind'][] = (int)$w[4]; }
            $t['FloorWallEnd'][] = count($t['WallAX']);
            $t['FloorHoleStart'][] = count($t['HoleX']);
            foreach ($floor['holes'] as $h) { $t['HoleX'][] = (float)$h[0]; $t['HoleY'][] = (float)$h[1]; }
            $t['FloorHoleEnd'][] = count($t['HoleX']);
            $t['FloorMarkStart'][] = count($t['MarkX']);
            foreach ($floor['marks'] ?? [] as $m) { $t['MarkX'][] = (float)$m[0]; $t['MarkY'][] = (float)$m[1]; $t['MarkKind'][] = (int)$m[2]; }
            $t['FloorMarkEnd'][] = count($t['MarkX']);
            if (count($floor['walls']) > RADAR_LABYRINTH_WALLS) { throw new InvalidArgumentException('A baked labyrinth floor exceeds the radar wall pool.'); }
        }
        $t['MazeFloorEnd'][] = count($t['FloorZ']);
    }
    return $t;
}

/** Imports, fields and widgets; call after the ring image and before pins so walls draw beneath pins. */
function AddRadarLabyrinthWidgets(RadarWidgetBuilder $b, callable $font, array $layouts = []): void
{
    foreach (RadarLabyrinthTables($layouts) as $n => $values) {
        $int = in_array($n, RADAR_LABYRINTH_INT_TABLES, true);
        $b->arrayField($int ? 'Int' : 'Float', 'SSRadarHm' . $n, $values);
    }
    $b->scalar('Int', 'SSRadarMazeSlot', -1);
    $b->native['MirrorMazePuzzle'] = OverlayImport($b->asset, 'MirrorMazePuzzle', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    $b->native['MirrorMazeCell'] = OverlayImport($b->asset, 'MirrorMazeCell', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    $b->native['InstancedStaticMeshComponent'] = OverlayImport($b->asset, 'InstancedStaticMeshComponent', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $b->native['CharPuzzleComponent'] ??= OverlayImport($b->asset, 'CharPuzzleComponent', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    foreach (['IntVector', 'Transform'] as $struct) {
        $b->native[$struct] = OverlayImport($b->asset, $struct, $b->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct');
    }
    $b->objectField('SSRadarMaze', $b->native['MirrorMazePuzzle']);
    $b->scalar('Bool', 'SSRadarMazeActive', 0);
    foreach (['SSRadarMazeFloor' => -1, 'SSRadarMazeFloors' => 0, 'SSRadarMazeInstances' => -1, 'SSRadarMazeAge' => 0,
        'SSRadarMazeWallsShown' => 0, 'SSRadarMazeMarksShown' => 0] as $n => $v) { $b->scalar('Int', $n, $v); }
    $b->scalar('Float', 'SSRadarMazeRadiusCm', 0);
    $b->scalar('Int', 'SSRadarMazeScanAge', 0);
    $b->arrayField('Object', 'SSRadarMazeCandidates', []);
    $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native['MirrorMazePuzzle'];
    $b->scalar('Float', 'SSRadarViewRadiusCm', 10000);
    foreach (['SSRadarWallAX', 'SSRadarWallAY', 'SSRadarWallBX', 'SSRadarWallBY', 'SSRadarMarkX', 'SSRadarMarkY'] as $n) { $b->arrayField('Float', $n, []); }
    foreach (['SSRadarWallKinds', 'SSRadarMarkKinds'] as $n) { $b->arrayField('Int', $n, []); }
    foreach (['SSRadarHoleX', 'SSRadarHoleY'] as $n) { $b->arrayField('Float', $n, []); }
    $b->arrayField('Int', 'SSRadarMazeCovered', []);
    foreach (['SSRadarMazeYaw' => 0, 'SSRadarMazeCellCm' => 0] as $n => $v) { $b->scalar('Float', $n, $v); }
    $b->scalar('Int', 'SSRadarMazeHolesShown', 0);
    // Drops draw first, so walls and markers stay on top of them.
    $square = FilterStruct('Brush', 'SlateBrush', [FilterVector('ImageSize', 10, 10)]);
    for ($k = 0; $k < RADAR_LABYRINTH_HOLES; $k++) {
        $b->child('Image', 'SSRadarHole' . $k, [FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed'), $square], 108, 108, 10, 10);
    }
    $bar = FilterStruct('Brush', 'SlateBrush', [FilterVector('ImageSize', 100, 3)]);
    for ($k = 0; $k < RADAR_LABYRINTH_WALLS; $k++) {
        $b->child('Image', 'SSRadarWall' . $k, [FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed'), $bar], 108, 108, 100, 3);
    }
    for ($k = 0; $k < RADAR_LABYRINTH_MARKS; $k++) {
        $b->child('TextBlock', 'SSRadarMazeMark' . $k, [OverlayText('Text', ''), $font(11), FilterEnum('Justification', 'ETextJustify', 'Center'), FilterEnum('Visibility', 'ESlateVisibility', 'Collapsed')], 108, 108, 14, 16);
    }
    foreach (['SSRadarWallBars' => 'Image', 'SSRadarMarkTexts' => 'TextBlock', 'SSRadarHoleImages' => 'Image'] as $n => $class) {
        $b->arrayField('Object', $n, []);
        $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = OverlayImport($b->asset, $class, $b->native['UMG'], '/Script/CoreUObject', 'Class');
    }
}

/** Define SSRadarInitMaze, SSRadarRefreshMaze(PlayerX, PlayerY, PlayerZ) and SSRadarRenderMaze. */
function AddRadarLabyrinthFunctions(RadarWidgetBuilder $b): array
{
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $str = static fn(string $v): object => FilterExpr('StringConst', ['Value' => $v]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $state = static fn(string $n): object => FilterVariable(1, $n, true);
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $state($n), 'Expression' => $v]);
    $setBool = static fn(string $n, object $v): object => FilterExpr('LetBool', ['VariableExpression' => $state($n), 'AssignmentExpression' => $v]);
    $jump = static fn(string $label): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $label]);
    $unless = static fn(object $condition, string $label): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $label, 'BooleanExpression' => $condition]);
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $localCall = static fn(string $fn, array $args = []): object => FilterCall($b->functions[$fn], $args, 'LocalFinalFunction');
    $field = static fn(object $object, string $owner, string $name): object => RadarContext($object, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $member = static fn(string $struct, string $name, object $of): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $name), 'StructExpression' => $of]);
    $vec = static fn(object $x, object $y, object $z): object => $math('MakeVector', $x, $y, $z);
    $return = static fn(): object => FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]);
    $patch = static function (int $fn, array $types) use ($b): void {
        foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
            $type = $types[$p->Name] ?? null;
            if ($type === null) { continue; }
            if (is_array($type)) { [$struct, $size] = $type; $p->Struct = $b->native[$struct]; $p->ElementSize = $size; }
            else { $p->PropertyClass = $b->native[$type]; }
        }
    };

    // Widget fields bind during Initialize; collect them into arrays once so render can loop.
    $init = [$unless($math('EqualEqual_IntInt', $array('Array_Length', $state('SSRadarWallBars')), $i(0)), 'Done')];
    for ($k = 0; $k < RADAR_LABYRINTH_WALLS; $k++) { $init[] = $array('Array_Add', $state('SSRadarWallBars'), $state('SSRadarWall' . $k)); }
    for ($k = 0; $k < RADAR_LABYRINTH_HOLES; $k++) { $init[] = $array('Array_Add', $state('SSRadarHoleImages'), $state('SSRadarHole' . $k)); }
    for ($k = 0; $k < RADAR_LABYRINTH_MARKS; $k++) { $init[] = $array('Array_Add', $state('SSRadarMarkTexts'), $state('SSRadarMazeMark' . $k)); }
    $init[] = ['label' => 'Done'];
    $b->define('SSRadarInitMaze', [], [], $init);

    $fn = $b->functions['SSRadarRefreshMaze'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $comp = static fn(string $local, string $axis): object => $member('Vector', $axis, $l($local));
    $dim = static fn(string $axis): object => $member('IntVector', $axis, $l('Dims'));
    $instances = static fn(string $prop, string $label) => [
        $let('Comp', $field($l('Maze'), 'MirrorMazePuzzle', $prop)),
        $unless($valid($l('Comp')), $label),
        $let('N', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceCount', [], FilterPointer($fn, 'N'))),
        $let('Count', $math('Add_IntInt', $l('Count'), $l('N'))), ['label' => $label]];
    $walls = static function (string $prop, int $kind) use ($b, $l, $let, $field, $valid, $unless, $jump, $math, $array, $comp, $vec, $f, $i, $fn): array {
        $tag = 'Walls' . $kind;
        $point = static fn(string $name, float $x): object => $let($name, $math('TransformLocation', $l('WallXf'), $vec($f($x), $f(0), $f(0))));
        return [
            $let('Comp', $field($l('Maze'), 'MirrorMazePuzzle', $prop)),
            $unless($valid($l('Comp')), $tag . 'Done'),
            $let('N', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceCount', [], FilterPointer($fn, 'N'))),
            $let('K', $i(0)), ['label' => $tag . 'Loop'],
            $unless($math('Less_IntInt', $l('K'), $l('N')), $tag . 'Done'),
            $let('Ok', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceTransform', [$l('K'), $l('WallXf'), FilterExpr('True')], FilterPointer($fn, 'Ok'))),
            $unless($l('Ok'), $tag . 'Next'),
            $point('Origin', 0),
            $let('Local', $math('InverseTransformLocation', $l('Xf'), $l('Origin'))),
            $unless($math('EqualEqual_IntInt', $math('Round', $math('Divide_FloatFloat', $comp('Local', 'Z'), $comp('Size', 'Z'))), $l('Floor')), $tag . 'Next'),
            $point('A', -50), $point('B', 50),
            $let('Value', $comp('A', 'X')), $array('Array_Add', FilterVariable(1, 'SSRadarWallAX', true), $l('Value')),
            $let('Value', $comp('A', 'Y')), $array('Array_Add', FilterVariable(1, 'SSRadarWallAY', true), $l('Value')),
            $let('Value', $comp('B', 'X')), $array('Array_Add', FilterVariable(1, 'SSRadarWallBX', true), $l('Value')),
            $let('Value', $comp('B', 'Y')), $array('Array_Add', FilterVariable(1, 'SSRadarWallBY', true), $l('Value')),
            $let('Kind', $i($kind)), $array('Array_Add', FilterVariable(1, 'SSRadarWallKinds', true), $l('Kind')),
            ['label' => $tag . 'Next'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump($tag . 'Loop'), ['label' => $tag . 'Done']];
    };
    $clearArrays = array_map(static fn(string $n): object => $array('Array_Clear', $state($n)),
        ['SSRadarWallAX', 'SSRadarWallAY', 'SSRadarWallBX', 'SSRadarWallBY', 'SSRadarWallKinds', 'SSRadarMarkX', 'SSRadarMarkY', 'SSRadarMarkKinds', 'SSRadarHoleX', 'SSRadarHoleY']);
    $cellList = $member('MirrorMazeCell', 'adjacentCells', $l('Cell'));
    $px = FilterVariable($fn, 'PlayerX'); $py = FilterVariable($fn, 'PlayerY'); $pz = FilterVariable($fn, 'PlayerZ');
    $inside = static fn(string $axis): object => $math('LessEqual_FloatFloat', $math('Abs', $comp('Local', $axis)),
        $math('Multiply_FloatFloat', $comp('Size', $axis), $math('Add_FloatFloat', $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim($axis)), $f(0.5)), $f(0.25))));
    // Cell centre in maze-local space: grid centred on the actor in X/Y, floors stacked up from its origin.
    $centre = static fn(string $axis, string $pos): object => $math('Multiply_FloatFloat',
        $math('Subtract_FloatFloat', $math('Add_FloatFloat', $math('Conv_IntToFloat', $member('MirrorMazeCell', $pos, $l('Cell'))), $f(0.5)), $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim($axis)), $f(0.5))),
        $comp('Size', $axis));
    $refresh = [
        // Candidate order: the game's current maze (worker list), then any Hub maze the player stands in.
        // The fallback keeps outlines for labyrinths already solved or not yet started.
        $let('Maze', FilterExpr('ObjectConst', ['Value' => 0])), $let('I', $i(-1)),
        $let('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $b->call('GameplayStatics', 'GetPlayerCharacter', [FilterExpr('Self'), $i(0)])])),
        $unless($valid($l('Player')), 'Check'),
        $let('PuzzleComponent', $field($l('Player'), 'SophiaCharacter', 'CharPuzzleComponent')),
        $unless($valid($l('PuzzleComponent')), 'Check'),
        $let('Maze', $b->method($l('PuzzleComponent'), 'CharPuzzleComponent', 'GetCurrentMirrorMaze', [], FilterPointer($fn, 'Maze'))),
        ['label' => 'Check'],
        $unless($valid($l('Maze')), 'Next'),
        // Handmade labyrinths have no generated cells; only procedural ones are drawn.
        $let('Cells', $array('Array_Length', $field($l('Maze'), 'MirrorMazePuzzle', 'generatedMaze'))),
        $unless($math('Greater_IntInt', $l('Cells'), $i(0)), 'Next'),
        // cellSize is in world units (it includes the actor's scale); the maths below runs in the actor frame.
        $let('Size', $field($l('Maze'), 'MirrorMazePuzzle', 'cellSize')),
        $let('A', $b->method($l('Maze'), 'Actor', 'GetActorScale3D', [], FilterPointer($fn, 'A'))),
        $unless($math('BooleanAND', $math('BooleanAND', $math('Greater_FloatFloat', $comp('A', 'X'), $f(0)), $math('Greater_FloatFloat', $comp('A', 'Y'), $f(0))), $math('Greater_FloatFloat', $comp('A', 'Z'), $f(0))), 'Next'),
        $let('Size', $math('Divide_VectorVector', $l('Size'), $l('A'))),
        $let('Dims', $field($l('Maze'), 'MirrorMazePuzzle', 'numMazeCellsXYZ')),
        $let('Floors', $dim('Z')),
        $unless($math('BooleanAND', $math('Greater_FloatFloat', $comp('Size', 'Z'), $f(0)), $math('Greater_IntInt', $l('Floors'), $i(0))), 'Next'),
        $let('Xf', $b->method($l('Maze'), 'Actor', 'GetTransform', [], FilterPointer($fn, 'Xf'))),
        $let('Local', $math('InverseTransformLocation', $l('Xf'), $vec(FilterVariable($fn, 'PlayerX'), FilterVariable($fn, 'PlayerY'), FilterVariable($fn, 'PlayerZ')))),
        $unless($math('GreaterEqual_IntInt', $l('I'), $i(0)), 'Found'),
        // Inside test in the actor frame: grid centred in X/Y (quarter-cell margin), floors centred on k * cell height.
        $unless($math('BooleanAND',
            $math('BooleanAND', $inside('X'), $inside('Y')),
            $math('BooleanAND', $math('GreaterEqual_FloatFloat', $comp('Local', 'Z'), $math('Multiply_FloatFloat', $comp('Size', 'Z'), $f(-1))),
                $math('LessEqual_FloatFloat', $comp('Local', 'Z'), $math('Multiply_FloatFloat', $comp('Size', 'Z'), $math('Conv_IntToFloat', $l('Floors')))))), 'Next'),
        $jump('Found'),
        ['label' => 'Next'],
        $unless($math('Less_IntInt', $l('I'), $i(0)), 'Advance'),
        // Maze actors are few; refresh the candidate list every two seconds rather than every tick.
        $unless($math('LessEqual_IntInt', $state('SSRadarMazeScanAge'), $i(0)), 'Cached'),
        $b->call('GameplayStatics', 'GetAllActorsOfClass', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $b->native['MirrorMazePuzzle']]), $state('SSRadarMazeCandidates')]),
        $set('SSRadarMazeScanAge', $i(RADAR_LABYRINTH_REBUILD_TICKS)), $jump('Scanned'),
        ['label' => 'Cached'], $set('SSRadarMazeScanAge', $math('Subtract_IntInt', $state('SSRadarMazeScanAge'), $i(1))),
        ['label' => 'Scanned'], $let('I', $i(0)), $jump('Pick'),
        ['label' => 'Advance'], $let('I', $math('Add_IntInt', $l('I'), $i(1))),
        ['label' => 'Pick'], $unless($math('Less_IntInt', $l('I'), $array('Array_Length', $state('SSRadarMazeCandidates'))), 'Off'),
        $array('Array_Get', $state('SSRadarMazeCandidates'), $l('I'), $l('Maze')), $jump('Check'),
        ['label' => 'Found'],
        // Floors are centred on k * cell height (walls, ceilings and pillars all sit there), so the walking
        // surface of floor k is half a cell below it: round, never floor, the player's height.
        $let('Floor', $math('Clamp', $math('Round', $math('Divide_FloatFloat', $comp('Local', 'Z'), $comp('Size', 'Z'))), $i(0), $math('Subtract_IntInt', $l('Floors'), $i(1)))),
        $let('Count', $i(0)),
        ...$instances('clearWallInstancedStaticMesh', 'CountedClear'), ...$instances('opaqueWallInstancedStaticMesh', 'CountedOpaque'),
        // Rebuild on a new maze, floor or wall set, and periodically in case the maze regenerated in place.
        $unless($math('BooleanOR', $math('BooleanOR', $math('NotEqual_ObjectObject', $l('Maze'), $state('SSRadarMaze')), $math('NotEqual_IntInt', $l('Floor'), $state('SSRadarMazeFloor'))),
            $math('BooleanOR', $math('NotEqual_IntInt', $l('Count'), $state('SSRadarMazeInstances')), $math('GreaterEqual_IntInt', $state('SSRadarMazeAge'), $i(RADAR_LABYRINTH_REBUILD_TICKS)))), 'Fresh'),
        ...$clearArrays,
        $set('SSRadarMaze', $l('Maze')), $set('SSRadarMazeFloor', $l('Floor')), $set('SSRadarMazeFloors', $l('Floors')), $set('SSRadarMazeInstances', $l('Count')), $set('SSRadarMazeSlot', $i(-1)),
        $set('SSRadarMazeAge', $i(0)),
        ...$walls('clearWallInstancedStaticMesh', 0), ...$walls('opaqueWallInstancedStaticMesh', 1),
        // One cell edge in world units and the maze's yaw, for drawing drop squares.
        $let('Origin', $math('TransformLocation', $l('Xf'), $vec($f(0), $f(0), $f(0)))),
        $let('B', $math('TransformLocation', $l('Xf'), $vec($comp('Size', 'X'), $f(0), $f(0)))),
        $set('SSRadarMazeCellCm', $math('VSizeXY', $math('Subtract_VectorVector', $l('B'), $l('Origin')))),
        $set('SSRadarMazeYaw', $math('DegAtan2', $math('Subtract_FloatFloat', $comp('B', 'Y'), $comp('Origin', 'Y')), $math('Subtract_FloatFloat', $comp('B', 'X'), $comp('Origin', 'X')))),
        $unless($math('Greater_IntInt', $l('Floor'), $i(0)), 'HolesDone'),
        $let('Comp', $field($l('Maze'), 'MirrorMazePuzzle', 'ceilingInstancedStaticMesh')),
        $unless($valid($l('Comp')), 'HolesDone'),
        $array('Array_Clear', $state('SSRadarMazeCovered')),
        $array('Array_Resize', $state('SSRadarMazeCovered'), $math('Multiply_IntInt', $dim('X'), $dim('Y'))),
        $let('One', $i(1)),
        $let('N', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceCount', [], FilterPointer($fn, 'N'))),
        $let('K', $i(0)), ['label' => 'CeilingLoop'],
        $unless($math('Less_IntInt', $l('K'), $l('N')), 'CeilingDone'),
        $let('Ok', $b->method($l('Comp'), 'InstancedStaticMeshComponent', 'GetInstanceTransform', [$l('K'), $l('WallXf'), FilterExpr('True')], FilterPointer($fn, 'Ok'))),
        $unless($l('Ok'), 'CeilingNext'),
        $let('Local', $math('InverseTransformLocation', $l('Xf'), $math('TransformLocation', $l('WallXf'), $vec($f(0), $f(0), $f(0))))),
        // The ceiling of floor k - 1 is the floor of floor k.
        $unless($math('EqualEqual_IntInt', $math('Round', $math('Add_FloatFloat', $math('Divide_FloatFloat', $comp('Local', 'Z'), $comp('Size', 'Z')), $f(0.5))), $l('Floor')), 'CeilingNext'),
        $let('IX', $math('FFloor', $math('Add_FloatFloat', $math('Divide_FloatFloat', $comp('Local', 'X'), $comp('Size', 'X')), $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim('X')), $f(0.5))))),
        $let('IY', $math('FFloor', $math('Add_FloatFloat', $math('Divide_FloatFloat', $comp('Local', 'Y'), $comp('Size', 'Y')), $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim('Y')), $f(0.5))))),
        $unless($math('BooleanAND', $math('BooleanAND', $math('GreaterEqual_IntInt', $l('IX'), $i(0)), $math('Less_IntInt', $l('IX'), $dim('X'))),
            $math('BooleanAND', $math('GreaterEqual_IntInt', $l('IY'), $i(0)), $math('Less_IntInt', $l('IY'), $dim('Y')))), 'CeilingNext'),
        $let('Index', $math('Add_IntInt', $math('Multiply_IntInt', $l('IY'), $dim('X')), $l('IX'))),
        $array('Array_Set', $state('SSRadarMazeCovered'), $l('Index'), $l('One'), FilterExpr('False')),
        ['label' => 'CeilingNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('CeilingLoop'),
        ['label' => 'CeilingDone'],
        $let('IY', $i(0)), ['label' => 'HoleRow'],
        $unless($math('Less_IntInt', $l('IY'), $dim('Y')), 'HolesDone'),
        $let('IX', $i(0)), ['label' => 'HoleColumn'],
        $unless($math('Less_IntInt', $l('IX'), $dim('X')), 'HoleRowNext'),
        $array('Array_Get', $state('SSRadarMazeCovered'), $math('Add_IntInt', $math('Multiply_IntInt', $l('IY'), $dim('X')), $l('IX')), $l('Covered')),
        $unless($math('EqualEqual_IntInt', $l('Covered'), $i(0)), 'HoleNext'),
        $let('Origin', $math('TransformLocation', $l('Xf'), $vec(
            $math('Multiply_FloatFloat', $math('Subtract_FloatFloat', $math('Add_FloatFloat', $math('Conv_IntToFloat', $l('IX')), $f(0.5)), $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim('X')), $f(0.5))), $comp('Size', 'X')),
            $math('Multiply_FloatFloat', $math('Subtract_FloatFloat', $math('Add_FloatFloat', $math('Conv_IntToFloat', $l('IY')), $f(0.5)), $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim('Y')), $f(0.5))), $comp('Size', 'Y')),
            $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $l('Floor')), $comp('Size', 'Z'))))),
        $let('Value', $comp('Origin', 'X')), $array('Array_Add', $state('SSRadarHoleX'), $l('Value')),
        $let('Value', $comp('Origin', 'Y')), $array('Array_Add', $state('SSRadarHoleY'), $l('Value')),
        ['label' => 'HoleNext'], $let('IX', $math('Add_IntInt', $l('IX'), $i(1))), $jump('HoleColumn'),
        ['label' => 'HoleRowNext'], $let('IY', $math('Add_IntInt', $l('IY'), $i(1))), $jump('HoleRow'),
        ['label' => 'HolesDone'],
        $let('I', $i(0)), ['label' => 'CellLoop'],
        $unless($math('Less_IntInt', $l('I'), $l('Cells')), 'CellDone'),
        $array('Array_Get', $field($l('Maze'), 'MirrorMazePuzzle', 'generatedMaze'), $l('I'), $l('Cell')),
        $unless($math('EqualEqual_IntInt', $member('MirrorMazeCell', 'floor', $l('Cell')), $l('Floor')), 'CellNext'),
        $let('J', $i(0)), ['label' => 'LinkLoop'],
        $unless($math('Less_IntInt', $l('J'), $array('Array_Length', $cellList)), 'CellNext'),
        $array('Array_Get', unserialize(serialize($cellList)), $l('J'), $l('Coord')),
        $let('Kind', $i(1)),
        $unless($math('EqualEqual_IntInt', $member('IntVector', 'Z', $l('Coord')), $math('Add_IntInt', $l('Floor'), $i(1))), 'Down'), $jump('Mark'),
        ['label' => 'Down'], $let('Kind', $i(2)),
        $unless($math('EqualEqual_IntInt', $member('IntVector', 'Z', $l('Coord')), $math('Subtract_IntInt', $l('Floor'), $i(1))), 'LinkNext'),
        ['label' => 'Mark'],
        $let('Origin', $math('TransformLocation', $l('Xf'), $vec($centre('X', 'posX'), $centre('Y', 'posY'), $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $l('Floor')), $comp('Size', 'Z'))))),
        $let('Value', $comp('Origin', 'X')), $array('Array_Add', $state('SSRadarMarkX'), $l('Value')),
        $let('Value', $comp('Origin', 'Y')), $array('Array_Add', $state('SSRadarMarkY'), $l('Value')),
        $array('Array_Add', $state('SSRadarMarkKinds'), $l('Kind')),
        ['label' => 'LinkNext'], $let('J', $math('Add_IntInt', $l('J'), $i(1))), $jump('LinkLoop'),
        ['label' => 'CellNext'], $let('I', $math('Add_IntInt', $l('I'), $i(1))), $jump('CellLoop'), ['label' => 'CellDone'],
        // Player-centred view must reach the far corner from anywhere inside: twice the half-diagonal.
        $let('Origin', $b->method($l('Maze'), 'Actor', 'K2_GetActorLocation', [], FilterPointer($fn, 'Origin'))),
        $let('A', $math('TransformLocation', $l('Xf'), $vec($math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim('X')), $math('Multiply_FloatFloat', $comp('Size', 'X'), $f(0.5))),
            $math('Multiply_FloatFloat', $math('Conv_IntToFloat', $dim('Y')), $math('Multiply_FloatFloat', $comp('Size', 'Y'), $f(0.5))), $f(0)))),
        $set('SSRadarMazeRadiusCm', $math('Multiply_FloatFloat', $math('VSizeXY', $math('Subtract_VectorVector', $l('A'), $l('Origin'))), $f(2))),
        ['label' => 'Fresh'],
        $set('SSRadarMazeAge', $math('Add_IntInt', $state('SSRadarMazeAge'), $i(1))),
        $setBool('SSRadarMazeActive', $bool(true)),
        $b->method($state('SSRadarRange'), 'TextBlock', 'SetText', [$b->call('KismetTextLibrary', 'Conv_StringToText', [$b->call('KismetStringLibrary', 'Concat_StrStr', [
            $b->call('KismetStringLibrary', 'Concat_StrStr', [$str('Floor '), $b->call('KismetStringLibrary', 'Conv_IntToString', [$math('Add_IntInt', $l('Floor'), $i(1))])]),
            $b->call('KismetStringLibrary', 'Concat_StrStr', [$str(' of '), $b->call('KismetStringLibrary', 'Conv_IntToString', [$l('Floors')])])])])]),
        $return(),
        ['label' => 'Off'],
        // No Hub maze: look for a baked handmade maze whose bounding volumes contain the player.
        $let('M', $i(0)), ['label' => 'HmMaze'],
        $unless($math('Less_IntInt', $l('M'), $array('Array_Length', $state('SSRadarHmMazeX'))), 'Inactive'),
        $array('Array_Get', $state('SSRadarHmMazeX'), $l('M'), $l('Value')), $let('DX', $math('Subtract_FloatFloat', $px, $l('Value'))),
        $array('Array_Get', $state('SSRadarHmMazeY'), $l('M'), $l('Value')), $let('DY', $math('Subtract_FloatFloat', $py, $l('Value'))),
        $array('Array_Get', $state('SSRadarHmMazeR'), $l('M'), $l('Value')),
        $unless($math('LessEqual_FloatFloat', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $l('DX')), $math('Multiply_FloatFloat', $l('DY'), $l('DY'))),
            $math('Multiply_FloatFloat', $l('Value'), $l('Value'))), 'HmNext'),
        $array('Array_Get', $state('SSRadarHmMazeBoxStart'), $l('M'), $l('K')), $array('Array_Get', $state('SSRadarHmMazeBoxEnd'), $l('M'), $l('N')),
        ['label' => 'HmBox'], $unless($math('Less_IntInt', $l('K'), $l('N')), 'HmNext'),
        $array('Array_Get', $state('SSRadarHmBoxX'), $l('K'), $l('Value')), $let('DX', $math('Subtract_FloatFloat', $px, $l('Value'))),
        $array('Array_Get', $state('SSRadarHmBoxY'), $l('K'), $l('Value')), $let('DY', $math('Subtract_FloatFloat', $py, $l('Value'))),
        $array('Array_Get', $state('SSRadarHmBoxYaw'), $l('K'), $l('Value')),
        // Into the volume's frame: rotate by -yaw.
        $let('LX', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $math('DegCos', $l('Value'))), $math('Multiply_FloatFloat', $l('DY'), $math('DegSin', $l('Value'))))),
        $let('LY', $math('Subtract_FloatFloat', $math('Multiply_FloatFloat', $l('DY'), $math('DegCos', $l('Value'))), $math('Multiply_FloatFloat', $l('DX'), $math('DegSin', $l('Value'))))),
        $array('Array_Get', $state('SSRadarHmBoxEX'), $l('K'), $l('Value')), $unless($math('LessEqual_FloatFloat', $math('Abs', $l('LX')), $l('Value')), 'HmBoxNext'),
        $array('Array_Get', $state('SSRadarHmBoxEY'), $l('K'), $l('Value')), $unless($math('LessEqual_FloatFloat', $math('Abs', $l('LY')), $l('Value')), 'HmBoxNext'),
        $array('Array_Get', $state('SSRadarHmBoxZ'), $l('K'), $l('Value')), $let('LX', $math('Abs', $math('Subtract_FloatFloat', $pz, $l('Value')))),
        $array('Array_Get', $state('SSRadarHmBoxEZ'), $l('K'), $l('Value')), $unless($math('LessEqual_FloatFloat', $l('LX'), $l('Value')), 'HmBoxNext'),
        $jump('HmFound'),
        ['label' => 'HmBoxNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('HmBox'),
        ['label' => 'HmNext'], $let('M', $math('Add_IntInt', $l('M'), $i(1))), $jump('HmMaze'),
        ['label' => 'HmFound'],
        // Floors are baked in ascending height: take the highest one at or below the player's feet.
        $array('Array_Get', $state('SSRadarHmMazeFloorStart'), $l('M'), $l('I')), $array('Array_Get', $state('SSRadarHmMazeFloorEnd'), $l('M'), $l('J')),
        $let('Index', $l('I')), $let('K', $math('Add_IntInt', $l('I'), $i(1))), ['label' => 'HmFloor'],
        $unless($math('Less_IntInt', $l('K'), $l('J')), 'HmFloorDone'),
        $array('Array_Get', $state('SSRadarHmFloorZ'), $l('K'), $l('Value')),
        $unless($math('LessEqual_FloatFloat', $l('Value'), $math('Subtract_FloatFloat', $pz, $f(20))), 'HmFloorDone'),
        $let('Index', $l('K')), $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('HmFloor'),
        ['label' => 'HmFloorDone'],
        $let('Floor', $math('Subtract_IntInt', $l('Index'), $l('I'))), $let('Floors', $math('Subtract_IntInt', $l('J'), $l('I'))),
        $unless($math('BooleanOR', $math('NotEqual_IntInt', $l('Index'), $state('SSRadarMazeSlot')), $math('Not_PreBool', $state('SSRadarMazeActive'))), 'Fresh'),
        ...$clearArrays,
        $set('SSRadarMazeSlot', $l('Index')), $set('SSRadarMaze', FilterExpr('ObjectConst', ['Value' => 0])),
        $set('SSRadarMazeFloor', $l('Floor')), $set('SSRadarMazeFloors', $l('Floors')), $set('SSRadarMazeInstances', $i(-1)),
        $array('Array_Get', $state('SSRadarHmFloorWallStart'), $l('Index'), $l('K')), $array('Array_Get', $state('SSRadarHmFloorWallEnd'), $l('Index'), $l('N')),
        ['label' => 'HmWall'], $unless($math('Less_IntInt', $l('K'), $l('N')), 'HmWallDone'),
        ...array_merge(...array_map(static fn(string $part): array => [$array('Array_Get', $state('SSRadarHmWall' . $part), $l('K'), $l('Value')), $array('Array_Add', $state('SSRadarWall' . $part), $l('Value'))], ['AX', 'AY', 'BX', 'BY'])),
        $array('Array_Get', $state('SSRadarHmWallKind'), $l('K'), $l('Kind')), $array('Array_Add', $state('SSRadarWallKinds'), $l('Kind')),
        $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('HmWall'), ['label' => 'HmWallDone'],
        $array('Array_Get', $state('SSRadarHmFloorHoleStart'), $l('Index'), $l('K')), $array('Array_Get', $state('SSRadarHmFloorHoleEnd'), $l('Index'), $l('N')),
        ['label' => 'HmHole'], $unless($math('Less_IntInt', $l('K'), $l('N')), 'HmHoleDone'),
        $array('Array_Get', $state('SSRadarHmHoleX'), $l('K'), $l('Value')), $array('Array_Add', $state('SSRadarHoleX'), $l('Value')),
        $array('Array_Get', $state('SSRadarHmHoleY'), $l('K'), $l('Value')), $array('Array_Add', $state('SSRadarHoleY'), $l('Value')),
        $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('HmHole'), ['label' => 'HmHoleDone'],
        // Up/down markers from the baked jump pads.
        $array('Array_Get', $state('SSRadarHmFloorMarkStart'), $l('Index'), $l('K')), $array('Array_Get', $state('SSRadarHmFloorMarkEnd'), $l('Index'), $l('N')),
        ['label' => 'HmMark'], $unless($math('Less_IntInt', $l('K'), $l('N')), 'HmMarkDone'),
        $array('Array_Get', $state('SSRadarHmMarkX'), $l('K'), $l('Value')), $array('Array_Add', $state('SSRadarMarkX'), $l('Value')),
        $array('Array_Get', $state('SSRadarHmMarkY'), $l('K'), $l('Value')), $array('Array_Add', $state('SSRadarMarkY'), $l('Value')),
        $array('Array_Get', $state('SSRadarHmMarkKind'), $l('K'), $l('Kind')), $array('Array_Add', $state('SSRadarMarkKinds'), $l('Kind')),
        $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('HmMark'), ['label' => 'HmMarkDone'],
        $array('Array_Get', $state('SSRadarHmMazeYaw'), $l('M'), $l('Value')), $set('SSRadarMazeYaw', $l('Value')),
        $array('Array_Get', $state('SSRadarHmMazeCell'), $l('M'), $l('Value')), $set('SSRadarMazeCellCm', $l('Value')),
        $array('Array_Get', $state('SSRadarHmFloorView'), $l('Index'), $l('Value')), $set('SSRadarMazeRadiusCm', $l('Value')),
        $jump('Fresh'),
        ['label' => 'Inactive'], $unless($state('SSRadarMazeActive'), 'Done'),
        $set('SSRadarMazeSlot', $i(-1)),
        $setBool('SSRadarMazeActive', $bool(false)), $set('SSRadarMaze', FilterExpr('ObjectConst', ['Value' => 0])),
        $set('SSRadarMazeFloor', $i(-1)), $set('SSRadarMazeFloors', $i(0)), $set('SSRadarMazeInstances', $i(-1)), ...$clearArrays,
        // Restores the range label the floor caption replaced.
        $localCall('SSRadarApplyView'),
        ['label' => 'Done'],
    ];
    $b->define('SSRadarRefreshMaze', ['PlayerX' => 'Float', 'PlayerY' => 'Float', 'PlayerZ' => 'Float'], [
        'Player' => 'Object', 'PuzzleComponent' => 'Object', 'Maze' => 'Object', 'Comp' => 'Object', 'Cells' => 'Int', 'Floors' => 'Int', 'Floor' => 'Int', 'Count' => 'Int',
        'N' => 'Int', 'K' => 'Int', 'I' => 'Int', 'J' => 'Int', 'Kind' => 'Int', 'Ok' => 'Bool', 'Value' => 'Float', 'One' => 'Int', 'IX' => 'Int', 'IY' => 'Int', 'Index' => 'Int', 'Covered' => 'Int',
        'M' => 'Int', 'DX' => 'Float', 'DY' => 'Float', 'LX' => 'Float', 'LY' => 'Float',
        'Size' => 'Struct', 'Dims' => 'Struct', 'Xf' => 'Struct', 'WallXf' => 'Struct', 'Local' => 'Struct', 'Origin' => 'Struct', 'A' => 'Struct', 'B' => 'Struct', 'Cell' => 'Struct', 'Coord' => 'Struct',
    ], $refresh);
    $patch($fn, ['Player' => 'SophiaCharacter', 'PuzzleComponent' => 'CharPuzzleComponent', 'Maze' => 'MirrorMazePuzzle', 'Comp' => 'InstancedStaticMeshComponent',
        'Dims' => ['IntVector', 12], 'Coord' => ['IntVector', 12], 'Xf' => ['Transform', 48], 'WallXf' => ['Transform', 48], 'Cell' => ['MirrorMazeCell', 0x40]]);

    // Render: same projection as pins (heading up, 96 px = view radius), one bar per wall segment.
    $fn = $b->functions['SSRadarRenderMaze'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $sub = static fn(object $a, object $c): object => $math('Subtract_FloatFloat', $a, $c);
    $mul = static fn(object $a, object $c): object => $math('Multiply_FloatFloat', $a, $c);
    $screen = static fn(string $x, string $y, string $sx, string $sy): array => [
        $let('DX', $sub($l($x), $state('SSRadarPlayerX'))), $let('DY', $sub($l($y), $state('SSRadarPlayerY'))),
        $let($sx, $mul($sub($mul($l('DY'), $l('Cos')), $mul($l('DX'), $l('Sin'))), $l('Scale'))),
        $let($sy, $mul($math('Add_FloatFloat', $mul($l('DX'), $l('Cos')), $mul($l('DY'), $l('Sin'))), $math('Multiply_FloatFloat', $l('Scale'), $f(-1))))];
    $visibility = static fn(string $local, int $value): object => $b->method($l($local), 'Widget', 'SetVisibility', [FilterExpr('ByteConst', ['Value' => $value])]);
    $render = [
        $localCall('SSRadarInitMaze'),
        $let('Sin', $math('DegSin', $state('SSRadarYaw'))), $let('Cos', $math('DegCos', $state('SSRadarYaw'))),
        $let('Scale', $math('Divide_FloatFloat', $f(96), $state('SSRadarViewRadiusCm'))),
        $let('Count', $i(0)),
        $unless($state('SSRadarMazeActive'), 'Walls'),
        $let('Count', $math('Min', $array('Array_Length', $state('SSRadarWallAX')), $i(RADAR_LABYRINTH_WALLS))),
        ['label' => 'Walls'], $let('Limit', $math('Max', $l('Count'), $state('SSRadarMazeWallsShown'))),
        $let('K', $i(0)), ['label' => 'WallLoop'],
        $unless($math('Less_IntInt', $l('K'), $l('Limit')), 'WallDone'),
        $array('Array_Get', $state('SSRadarWallBars'), $l('K'), $l('Bar')),
        $unless($valid($l('Bar')), 'WallNext'),
        $unless($math('Less_IntInt', $l('K'), $l('Count')), 'WallHide'),
        $array('Array_Get', $state('SSRadarWallAX'), $l('K'), $l('AX')), $array('Array_Get', $state('SSRadarWallAY'), $l('K'), $l('AY')),
        $array('Array_Get', $state('SSRadarWallBX'), $l('K'), $l('BX')), $array('Array_Get', $state('SSRadarWallBY'), $l('K'), $l('BY')),
        $array('Array_Get', $state('SSRadarWallKinds'), $l('K'), $l('Kind')),
        ...$screen('AX', 'AY', 'SAX', 'SAY'), ...$screen('BX', 'BY', 'SBX', 'SBY'),
        $let('DX', $sub($l('SBX'), $l('SAX'))), $let('DY', $sub($l('SBY'), $l('SAY'))),
        $b->method($l('Bar'), 'Widget', 'SetRenderTranslation', [$math('MakeVector2D', $mul($math('Add_FloatFloat', $l('SAX'), $l('SBX')), $f(0.5)), $mul($math('Add_FloatFloat', $l('SAY'), $l('SBY')), $f(0.5)))]),
        $b->method($l('Bar'), 'Widget', 'SetRenderScale', [$math('MakeVector2D', $mul($math('Sqrt', $math('Add_FloatFloat', $mul($l('DX'), $l('DX')), $mul($l('DY'), $l('DY')))), $f(0.01)), $f(1))]),
        $b->method($l('Bar'), 'Widget', 'SetRenderTransformAngle', [$math('DegAtan2', $l('DY'), $l('DX'))]),
        $unless($math('EqualEqual_IntInt', $l('Kind'), $i(1)), 'Glass'),
        $b->method($l('Bar'), 'Image', 'SetColorAndOpacity', [$math('MakeColor', $f(1), $f(1), $f(1), $f(0.95))]), $jump('Colored'),
        ['label' => 'Glass'], $b->method($l('Bar'), 'Image', 'SetColorAndOpacity', [$math('MakeColor', $f(0.45), $f(0.85), $f(1), $f(0.8))]),
        ['label' => 'Colored'], $visibility('Bar', 3), $jump('WallNext'),
        ['label' => 'WallHide'], $visibility('Bar', 1),
        ['label' => 'WallNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('WallLoop'),
        ['label' => 'WallDone'], $set('SSRadarMazeWallsShown', $l('Count')),
        $let('Count', $i(0)),
        $unless($state('SSRadarMazeActive'), 'Marks'),
        $let('Count', $math('Min', $array('Array_Length', $state('SSRadarMarkX')), $i(RADAR_LABYRINTH_MARKS))),
        ['label' => 'Marks'], $let('Limit', $math('Max', $l('Count'), $state('SSRadarMazeMarksShown'))),
        $let('K', $i(0)), ['label' => 'MarkLoop'],
        $unless($math('Less_IntInt', $l('K'), $l('Limit')), 'MarkDone'),
        $array('Array_Get', $state('SSRadarMarkTexts'), $l('K'), $l('Text')),
        $unless($valid($l('Text')), 'MarkNext'),
        $unless($math('Less_IntInt', $l('K'), $l('Count')), 'MarkHide'),
        $array('Array_Get', $state('SSRadarMarkX'), $l('K'), $l('AX')), $array('Array_Get', $state('SSRadarMarkY'), $l('K'), $l('AY')),
        $array('Array_Get', $state('SSRadarMarkKinds'), $l('K'), $l('Kind')),
        ...$screen('AX', 'AY', 'SAX', 'SAY'),
        $b->method($l('Text'), 'Widget', 'SetRenderTranslation', [$math('MakeVector2D', $l('SAX'), $l('SAY'))]),
        $unless($math('EqualEqual_IntInt', $l('Kind'), $i(1)), 'MarkDown'),
        $b->method($l('Text'), 'TextBlock', 'SetText', [$b->call('KismetTextLibrary', 'Conv_StringToText', [$str('^')])]), $jump('MarkShow'),
        ['label' => 'MarkDown'], $b->method($l('Text'), 'TextBlock', 'SetText', [$b->call('KismetTextLibrary', 'Conv_StringToText', [$str('v')])]),
        ['label' => 'MarkShow'], $visibility('Text', 3), $jump('MarkNext'),
        ['label' => 'MarkHide'], $visibility('Text', 1),
        ['label' => 'MarkNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('MarkLoop'),
        ['label' => 'MarkDone'], $set('SSRadarMazeMarksShown', $l('Count')),
        $let('Count', $i(0)),
        $unless($state('SSRadarMazeActive'), 'Holes'),
        $let('Count', $math('Min', $array('Array_Length', $state('SSRadarHoleX')), $i(RADAR_LABYRINTH_HOLES))),
        ['label' => 'Holes'], $let('Limit', $math('Max', $l('Count'), $state('SSRadarMazeHolesShown'))),
        // Screen angle of the maze's X axis, projected like the walls.
        $let('DX', $math('DegCos', $state('SSRadarMazeYaw'))), $let('DY', $math('DegSin', $state('SSRadarMazeYaw'))),
        $let('Angle', $math('DegAtan2', $math('Multiply_FloatFloat', $math('Add_FloatFloat', $mul($l('DX'), $l('Cos')), $mul($l('DY'), $l('Sin'))), $f(-1)),
            $sub($mul($l('DY'), $l('Cos')), $mul($l('DX'), $l('Sin'))))),
        $let('Side', $mul($mul($state('SSRadarMazeCellCm'), $l('Scale')), $f(0.08))),
        $let('K', $i(0)), ['label' => 'HoleLoop'],
        $unless($math('Less_IntInt', $l('K'), $l('Limit')), 'HoleDone'),
        $array('Array_Get', $state('SSRadarHoleImages'), $l('K'), $l('Bar')),
        $unless($valid($l('Bar')), 'HoleNext'),
        $unless($math('Less_IntInt', $l('K'), $l('Count')), 'HoleHide'),
        $array('Array_Get', $state('SSRadarHoleX'), $l('K'), $l('AX')), $array('Array_Get', $state('SSRadarHoleY'), $l('K'), $l('AY')),
        ...$screen('AX', 'AY', 'SAX', 'SAY'),
        $b->method($l('Bar'), 'Widget', 'SetRenderTranslation', [$math('MakeVector2D', $l('SAX'), $l('SAY'))]),
        $b->method($l('Bar'), 'Widget', 'SetRenderScale', [$math('MakeVector2D', $l('Side'), $l('Side'))]),
        $b->method($l('Bar'), 'Widget', 'SetRenderTransformAngle', [$l('Angle')]),
        $b->method($l('Bar'), 'Image', 'SetColorAndOpacity', [$math('MakeColor', $f(1), $f(0.3), $f(0.2), $f(0.55))]),
        $visibility('Bar', 3), $jump('HoleNext'),
        ['label' => 'HoleHide'], $visibility('Bar', 1),
        ['label' => 'HoleNext'], $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump('HoleLoop'),
        ['label' => 'HoleDone'], $set('SSRadarMazeHolesShown', $l('Count')),
    ];
    $b->define('SSRadarRenderMaze', [], ['Sin' => 'Float', 'Cos' => 'Float', 'Scale' => 'Float', 'Count' => 'Int', 'Limit' => 'Int', 'K' => 'Int', 'Kind' => 'Int', 'Bar' => 'Object', 'Text' => 'Object',
        'AX' => 'Float', 'AY' => 'Float', 'BX' => 'Float', 'BY' => 'Float', 'SAX' => 'Float', 'SAY' => 'Float', 'SBX' => 'Float', 'SBY' => 'Float', 'DX' => 'Float', 'DY' => 'Float',
        'Angle' => 'Float', 'Side' => 'Float'], $render);
    $b->native['Image'] = OverlayImport($b->asset, 'Image', $b->native['UMG'], '/Script/CoreUObject', 'Class');
    $b->native['TextBlock'] = OverlayImport($b->asset, 'TextBlock', $b->native['UMG'], '/Script/CoreUObject', 'Class');
    $patch($fn, ['Bar' => 'Image', 'Text' => 'TextBlock']);

    return ['authority' => 'SophiaCharacter.CharPuzzleComponent.GetCurrentMirrorMaze', 'cells' => 'MirrorMazePuzzle.generatedMaze (procedural only; empty for handmade)',
        'refreshFunction' => 'SSRadarRefreshMaze', 'renderFunction' => 'SSRadarRenderMaze', 'activeField' => 'SSRadarMazeActive', 'floorField' => 'SSRadarMazeFloor',
        'floorRule' => 'Round(InverseTransformLocation(actor, player).Z / (cellSize.Z / actor scale Z)), clamped to numMazeCellsXYZ.Z; floors are centred on k * cell height',
        'fallback' => 'without a Hub maze in the worker list, the first Hub MirrorMazePuzzle whose actor-frame bounds contain the player (solved or not started); candidates refreshed every 20 ticks',
        'cellSizeSpace' => 'world units including actor scale; divided by GetActorScale3D for actor-frame maths',
        'wallSources' => ['clearWallInstancedStaticMesh' => 'glass', 'opaqueWallInstancedStaticMesh' => 'opaque'], 'wallMeshSpanUnits' => 100,
        'wallFloorRule' => 'Round(local wall origin Z / cellSize.Z) == player floor', 'markers' => 'adjacentCells with Z = floor +/- 1',
        'holes' => 'cells of the current floor (above ground) with no ceilingInstancedStaticMesh instance at (floor - 0.5) * cell height; drawn as red squares beneath walls',
        'holePool' => RADAR_LABYRINTH_HOLES,
        'viewRadius' => 'twice the maze half-diagonal while active; radar zoom otherwise', 'wallPool' => RADAR_LABYRINTH_WALLS, 'markerPool' => RADAR_LABYRINTH_MARKS,
        'rebuildEveryTicks' => RADAR_LABYRINTH_REBUILD_TICKS, 'shownWithoutSelectedTypes' => true,
        'handmade' => ['source' => 'build-time bake (labyrinth_bake.php)', 'tables' => 'SSRadarHm*', 'selection' => 'first baked maze whose oriented bounding volumes contain the player; highest baked floor at or below the player', 'slotField' => 'SSRadarMazeSlot']];
}
