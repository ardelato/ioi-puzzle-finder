<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

require_once dirname(__DIR__) . '/radar_widget.php';

/**
 * Engine fixture for the radar's Crystal Labyrinth floor view; mutates only this test process's memory.
 *
 * Spawns a possessed SophiaCharacter and a rotated, scaled BP_MirrorMaze, generates it, then seeds the
 * native current-maze authority (CharPuzzleComponent.workedOnPuzzles) exactly as the game's worker list
 * does. The production radar widget is driven through every floor with no puzzle types selected. The
 * run travels to the pass/fail map with walls, markers and cells in the URL (lab=) for an offline check
 * against the maze graph (VerifyLabyrinthRadarLog).
 */
function BuildLabyrinthRadarFixture(string $output, int $cells = 4, float $yaw = 30, float $scale = 3, int $seed = 98217): array
{
    $base = json_decode(file_get_contents(OverlayTestDataPath('BaseJsons/WBP_LargeMap.json')), false, 512, JSON_THROW_ON_ERROR);
    $catalog = array_intersect_key(GetOverlayTypes(), array_flip(['hiddenCube']));
    $index = BuildRadarIndex([['actorName' => 'A', 'ptype' => 'hiddenCube', 'group' => 'static', 'x' => 10000, 'y' => 0, 'z' => 0, 'tags' => ['SaveStats.Type.hiddenCube']]], $catalog);
    [$radar, $manifest] = BuildRadarWidget($base, $index, [LabyrinthRadarFixtureHandmade()]);
    AssertNoContextCallArguments($radar, 'radar');
    // An installed overlay pak in Content/Paks outranks test paks, so test under a distinct package.
    $radarJson = str_replace(['/Game/SaveStats/WBP_SSOverlayRadar', 'WBP_SSOverlayRadar'], ['/Game/SaveStatsTests/WBP_SSLabyrinthRadar', 'WBP_SSLabyrinthRadar'], json_encode($radar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    file_put_contents($output . '/WBP_SSLabyrinthRadar.json', $radarJson);

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
    foreach (['Controller', 'KismetStringLibrary', 'WidgetBlueprintLibrary'] as $type) {
        $b->native[$type] = OverlayImport($b->asset, $type, $type === 'WidgetBlueprintLibrary' ? $b->native['UMG'] : $b->native['Engine'], '/Script/CoreUObject', 'Class');
    }
    foreach (['MirrorMazePuzzle', 'CharPuzzleComponent'] as $type) { $b->native[$type] = OverlayImport($b->asset, $type, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class'); }
    $b->native['MirrorMazeCell'] = OverlayImport($b->asset, 'MirrorMazeCell', $b->native['IslandsofInsight'], '/Script/CoreUObject', 'ScriptStruct');
    foreach (['IntVector', 'Transform'] as $type) { $b->native[$type] = OverlayImport($b->asset, $type, $b->native['CoreUObject'], '/Script/CoreUObject', 'ScriptStruct'); }
    $mazePackage = OverlayImport($b->asset, '/Game/ASophia/Puzzle/MirrorMaze/BP_MirrorMaze', 0, '/Script/CoreUObject', 'Package');
    $mazeClass = OverlayImport($b->asset, 'BP_MirrorMaze_C', $mazePackage, '/Script/Engine', 'BlueprintGeneratedClass');
    $radarPackage = OverlayImport($b->asset, '/Game/SaveStatsTests/WBP_SSLabyrinthRadar', 0, '/Script/CoreUObject', 'Package');
    $radarClass = OverlayImport($b->asset, 'WBP_SSLabyrinthRadar_C', $radarPackage, '/Script/UMG', 'WidgetBlueprintGeneratedClass');
    $radarFn = static fn(string $name): int => OverlayImport($b->asset, $name, $radarClass, '/Script/CoreUObject', 'Function');

    $b->objectField('Maze', $b->native['MirrorMazePuzzle']);
    $b->objectField('Player', $b->native['SophiaCharacter']);
    $b->objectField('Controller', $b->native['Object']);
    $b->objectField('Radar', $radarClass);
    $b->scalar('Str', 'Out', '?game=/Script/Engine.GameModeBase?lab=');
    $b->scalar('Bool', 'Passed', false);
    foreach (['ReceiveBeginPlay', 'VerifyLive', 'Finish'] as $fn) { $b->reserve($fn); }

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
    $vec = static fn(object $x, object $y, object $z): object => $math('MakeVector', $x, $y, $z);
    $transform = static fn(): object => $math('MakeTransform', $vec($f(1000), $f(2000), $f(3000)), $math('MakeRotator', $f(0), $f(0), $f($yaw)), $vec($f($scale), $f($scale), $f($scale)));
    $origin = static fn(): object => $math('MakeTransform', $vec($f(0), $f(0), $f(500)), $math('MakeRotator', $f(0), $f(0), $f(0)), $vec($f(1), $f(1), $f(1)));
    $property = static fn(object $obj, string $owner, string $name): object => RadarContext($obj, FilterVariable($b->native[$owner], $name, true), FilterPointer($b->native[$owner], $name));
    $assign = static fn(object $obj, string $owner, string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($b->native[$owner], $name), 'Variable' => $property($obj, $owner, $name), 'Expression' => $value]);
    $member = static fn(string $struct, string $name, object $of): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $name), 'StructExpression' => $of]);
    $radarField = static fn(string $name): object => RadarContext($s('Radar'), FilterVariable($radarClass, $name, true), FilterPointer($radarClass, $name));
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
    // Two decimals survive integer transport: centimetres x 100.
    $fixed = static fn(object $v): object => $int($math('Round', $math('Multiply_FloatFloat', $v, $f(100))));

    $spawn = static fn(object $class, object $xf): object => $b->call('GameplayStatics', 'BeginDeferredActorSpawnFromClass', [FilterExpr('Self'), $class, $xf, FilterExpr('ByteConst', ['Value' => 1]), FilterExpr('Self')]);
    $begin = [
        $set('Controller', $b->call('GameplayStatics', 'GetPlayerController', [FilterExpr('Self'), $i(0)])),
        $set('Player', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaCharacter'], 'TargetExpression' => $spawn(FilterExpr('ObjectConst', ['Value' => $b->native['SophiaCharacter']]), $origin())])),
        $b->method($s('Player'), 'Actor', 'SetActorTickEnabled', [$truth(false)]),
        $set('Player', $b->call('GameplayStatics', 'FinishSpawningActor', [$s('Player'), $origin()])),
        $b->method($s('Controller'), 'Controller', 'Possess', [$s('Player')]),
        $set('Maze', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['MirrorMazePuzzle'], 'TargetExpression' => $spawn(FilterExpr('ObjectConst', ['Value' => $mazeClass]), $transform())])),
        FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Spawned', 'BooleanExpression' => $valid($s('Maze'))]),
        $assign($s('Maze'), 'MirrorMazePuzzle', 'randSeed', $i($seed)),
        $assign($s('Maze'), 'MirrorMazePuzzle', 'numMazeCellsXYZ', $math('Conv_IntToIntVector', $i($cells))),
        $assign($s('Maze'), 'MirrorMazePuzzle', 'opaqueWallPercentChance', $f(0.3)),
        $assign($s('Maze'), 'MirrorMazePuzzle', 'verticalPathsChance', $f(0.3)),
        $assign($s('Maze'), 'MirrorMazePuzzle', 'trapDoorChance', $f(0.3)),
        $set('Maze', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['MirrorMazePuzzle'], 'TargetExpression' => $b->call('GameplayStatics', 'FinishSpawningActor', [$s('Maze'), $transform()])])),
        ['label' => 'Spawned'],
        $timer('ReceiveBeginPlay', 'VerifyLive', 1.0),
    ];
    $b->define('ReceiveBeginPlay', [], ['Timer' => 'Struct'], $begin);
    $receive = $b->asset->Exports[$b->functions['ReceiveBeginPlay'] - 1];
    $receive->SuperStruct = $receive->SuperIndex = OverlayImport($b->asset, 'ReceiveBeginPlay', $actor, '/Script/CoreUObject', 'Function');
    $receive->FunctionFlags = 'FUNC_Event, FUNC_Public, FUNC_BlueprintEvent';
    $receive->SerializationBeforeSerializationDependencies = [$receive->SuperStruct];

    $fn = $b->functions['VerifyLive'];
    $l = static fn(string $name): object => FilterVariable($fn, $name);
    $let = static fn(string $name, object $value): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $name), 'Variable' => $l($name), 'Expression' => $value]);
    $checks = [];
    $assert = static function (object $condition, string $name) use (&$checks): object { $checks[] = $name; return FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $condition]); };
    $jump = static fn(string $target): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $target]);
    $unless = static fn(object $condition, string $target): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $target, 'BooleanExpression' => $condition]);
    $intEq = static fn(object $a, int $v): object => $math('EqualEqual_IntInt', $a, $i($v));
    $radarCall = static fn(string $name, array $args): object => RadarContext($s('Radar'), FilterCall($radarFn($name), $args, 'FinalFunction'));
    $canvas = static fn(): object => $b->method($radarField('SSRadarCanvas'), 'Widget', 'GetVisibility');
    $worked = static fn(): object => $property($l('PuzzleComponent'), 'CharPuzzleComponent', 'workedOnPuzzles');
    // Stand at (X cells, Z floors) in the maze's own frame. Floors are centred on k * cell height, so a
    // player's capsule centre on floor k sits between k - 0.5 (walking surface) and k + 0.5 (ceiling).
    $standAt = static fn(float $cellsX, float $floors): array => [
        $let('At', $math('TransformLocation', $l('Xf'), $vec(
            $math('Multiply_FloatFloat', $math('Divide_FloatFloat', $member('Vector', 'X', $l('Size')), $f($scale)), $f($cellsX)), $f(0),
            $math('Multiply_FloatFloat', $math('Divide_FloatFloat', $member('Vector', 'Z', $l('Size')), $f($scale)), $f($floors))))),
        $radarCall('SSRadarUpdate', [$member('Vector', 'X', $l('At')), $member('Vector', 'Y', $l('At')), $member('Vector', 'Z', $l('At')), $f(0), $truth(false), $f(0.1)])];
    $hm = static fn(float $x, float $y, float $z): array => [$radarCall('SSRadarUpdate', [$f($x), $f($y), $f($z), $f(0), $truth(false), $f(0.1)])];
    // Just above the walking surface, as a standing player's capsule centre is.
    $stand = static fn(int $floor): array => $standAt(0, $floor - 0.35);
    $floatList = static fn(string $field, string $label, string $scope): array => [
        $append($str(';' . $label . '=')),
        $let('K', $i(0)), ['label' => $scope . $label . 'Loop'],
        $unless($math('Less_IntInt', $l('K'), $array('Array_Length', $radarField($field))), $scope . $label . 'Done'),
        $array('Array_Get', $radarField($field), $l('K'), $l('Value')),
        $append($fixed($l('Value')), $str(',')),
        $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump($scope . $label . 'Loop'), ['label' => $scope . $label . 'Done']];
    $intList = static fn(string $field, string $label, string $scope): array => [
        $append($str(';' . $label . '=')),
        $let('K', $i(0)), ['label' => $scope . $label . 'Loop'],
        $unless($math('Less_IntInt', $l('K'), $array('Array_Length', $radarField($field))), $scope . $label . 'Done'),
        $array('Array_Get', $radarField($field), $l('K'), $l('Number')),
        $append($int($l('Number')), $str(',')),
        $let('K', $math('Add_IntInt', $l('K'), $i(1))), $jump($scope . $label . 'Loop'), ['label' => $scope . $label . 'Done']];
    $dumpFloor = static fn(int $floor): array => [
        $append($str('~floor=' . $floor . ';active='), $int($math('Conv_BoolToInt', $radarField('SSRadarMazeActive'))), $str(';radarFloor='), $int($radarField('SSRadarMazeFloor')),
            $str(';shown='), $int($radarField('SSRadarMazeWallsShown')), $str(';marksShown='), $int($radarField('SSRadarMazeMarksShown')), $str(';holesShown='), $int($radarField('SSRadarMazeHolesShown'))),
        ...$floatList('SSRadarWallAX', 'ax', 'F' . $floor), ...$floatList('SSRadarWallAY', 'ay', 'F' . $floor), ...$floatList('SSRadarWallBX', 'bx', 'F' . $floor), ...$floatList('SSRadarWallBY', 'by', 'F' . $floor),
        ...$intList('SSRadarWallKinds', 'kind', 'F' . $floor), ...$floatList('SSRadarMarkX', 'mx', 'F' . $floor), ...$floatList('SSRadarMarkY', 'my', 'F' . $floor), ...$intList('SSRadarMarkKinds', 'mk', 'F' . $floor),
        ...$floatList('SSRadarHoleX', 'hx', 'F' . $floor), ...$floatList('SSRadarHoleY', 'hy', 'F' . $floor)];
    $cellList = static fn(string $field, string $label): array => [
        $append($str($label)), $let('J', $i(0)), ['label' => $label . 'Loop'],
        $unless($math('Less_IntInt', $l('J'), $array('Array_Length', $member('MirrorMazeCell', $field, $l('Cell')))), $label . 'Done'),
        $array('Array_Get', $member('MirrorMazeCell', $field, $l('Cell')), $l('J'), $l('Coord')),
        $append($int($member('IntVector', 'X', $l('Coord'))), $str('.'), $int($member('IntVector', 'Y', $l('Coord'))), $str('.'), $int($member('IntVector', 'Z', $l('Coord'))), $str('/')),
        $let('J', $math('Add_IntInt', $l('J'), $i(1))), $jump($label . 'Loop'), ['label' => $label . 'Done']];

    $code = [
        $assert($valid($s('Player')), 'possessed SophiaCharacter exists'),
        $assert($valid($s('Maze')), 'BP_MirrorMaze spawned as native MirrorMazePuzzle'),
        $b->method($s('Maze'), 'MirrorMazePuzzle', 'RegenerateMaze', []),
        $let('Cells', $array('Array_Length', $property($s('Maze'), 'MirrorMazePuzzle', 'generatedMaze'))),
        $assert($intEq($l('Cells'), $cells * $cells * $cells), 'generated cell count matches dimensions'),
        $let('Size', $property($s('Maze'), 'MirrorMazePuzzle', 'cellSize')),
        $let('At', $b->method($s('Maze'), 'Actor', 'GetActorScale3D', [], FilterPointer($fn, 'At'))),
        $assert($math('NearlyEqual_FloatFloat', $member('Vector', 'Z', $l('At')), $f($scale), $f(0.001)), 'maze actor carries the test scale'),
        $let('Xf', $b->method($s('Maze'), 'Actor', 'GetTransform', [], FilterPointer($fn, 'Xf'))),
        $append($str('cells='), $int($l('Cells')), $str(';size='), $fixed($member('Vector', 'X', $l('Size'))), $str(','), $fixed($member('Vector', 'Y', $l('Size'))), $str(','), $fixed($member('Vector', 'Z', $l('Size')))),
        $let('PuzzleComponent', $property($s('Player'), 'SophiaCharacter', 'CharPuzzleComponent')),
        $assert($valid($l('PuzzleComponent')), 'native CharPuzzleComponent exists'),
        $set('Radar', FilterExpr('DynamicCast', ['ClassPtr' => $radarClass, 'TargetExpression' => $b->call('WidgetBlueprintLibrary', 'Create', [FilterExpr('Self'), FilterExpr('ObjectConst', ['Value' => $radarClass]), FilterExpr('ObjectConst', ['Value' => 0])])])),
        $assert($valid($s('Radar')), 'production radar widget exists'),
        $radarCall('SSRadarSetSelection', [$i(0), $truth(false), $truth(true)]),
        ...$standAt($cells * 3, 0),
        $assert($math('Not_PreBool', $radarField('SSRadarMazeActive')), 'outside every maze: labyrinth layer inactive'),
        $assert($math('EqualEqual_ByteByte', $canvas(), FilterExpr('ByteConst', ['Value' => 1])), 'no types and no maze: radar stays hidden'),
        // Not started (absent from the worker list) but standing inside: outlines still show.
        ...$stand(1),
        $assert($radarField('SSRadarMazeActive'), 'inside a maze not in the worker list: layer active'),
        $assert($intEq($radarField('SSRadarMazeFloor'), 1), 'inside a maze not in the worker list: floor detected'),
        $array('Array_Insert', $worked(), $s('Maze'), $i(0)),
        $let('Current', $b->method($l('PuzzleComponent'), 'CharPuzzleComponent', 'GetCurrentMirrorMaze', [], FilterPointer($fn, 'Current'))),
        $assert($math('EqualEqual_ObjectObject', $l('Current'), $s('Maze')), 'worker list exposes the maze through GetCurrentMirrorMaze'),
    ];
    foreach (range(0, $cells - 1) as $floor) {
        $code = [...$code, ...$standAt(0, $floor + 0.45),
            $assert($intEq($radarField('SSRadarMazeFloor'), $floor), 'floor ' . $floor . ': near the ceiling still this floor'),
            ...$stand($floor),
            $assert($radarField('SSRadarMazeActive'), 'floor ' . $floor . ': labyrinth layer active'),
            $assert($intEq($radarField('SSRadarMazeFloor'), $floor), 'floor ' . $floor . ': floor detected from maze frame'),
            $assert($intEq($radarField('SSRadarMazeFloors'), $cells), 'floor ' . $floor . ': floor count'),
            $assert($math('Greater_IntInt', $array('Array_Length', $radarField('SSRadarWallAX')), $i(0)), 'floor ' . $floor . ': walls collected'),
            $assert($math('EqualEqual_ByteByte', $canvas(), FilterExpr('ByteConst', ['Value' => 3])), 'floor ' . $floor . ': radar visible without selected types'),
            $assert($math('NearlyEqual_FloatFloat', $radarField('SSRadarViewRadiusCm'), $radarField('SSRadarMazeRadiusCm'), $f(0.01)), 'floor ' . $floor . ': view fits the maze'),
            $assert($intEq($math('Subtract_IntInt', $array('Array_Length', $radarField('SSRadarHoleX')), $radarField('SSRadarMazeHolesShown')), 0), 'floor ' . $floor . ': every drop square shown'),
            ...$dumpFloor($floor)];
    }
    $code = [...$code,
        $append($str('~cells')),
        $let('I', $i(0)), ['label' => 'CellLoop'],
        $unless($math('Less_IntInt', $l('I'), $l('Cells')), 'CellDone'),
        $array('Array_Get', $property($s('Maze'), 'MirrorMazePuzzle', 'generatedMaze'), $l('I'), $l('Cell')),
        $append($str(';c'), $int($member('MirrorMazeCell', 'posX', $l('Cell'))), $str(','), $int($member('MirrorMazeCell', 'posY', $l('Cell'))), $str(','), $int($member('MirrorMazeCell', 'floor', $l('Cell'))),
            $str(',t'), $int($math('Conv_BoolToInt', $member('MirrorMazeCell', 'hasTrapDoor', $l('Cell'))))),
        ...$cellList('adjacentCells', ':a'), ...$cellList('opaqueCells', ':o'),
        $let('I', $math('Add_IntInt', $l('I'), $i(1))), $jump('CellLoop'), ['label' => 'CellDone'],
        // Solving removes the maze from the worker list; standing inside, the outline stays.
        $array('Array_RemoveItem', $worked(), $s('Maze')),
        ...$stand(2),
        $assert($radarField('SSRadarMazeActive'), 'maze left the worker list (solved): layer stays while inside'),
        $assert($intEq($radarField('SSRadarMazeFloor'), 2), 'maze left the worker list (solved): floor still tracked'),
        ...$standAt($cells * 3, 0),
        $assert($math('Not_PreBool', $radarField('SSRadarMazeActive')), 'leaving the maze deactivates the layer'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarWallAX')), 0), 'leaving the maze clears walls'),
        $assert($math('EqualEqual_ByteByte', $canvas(), FilterExpr('ByteConst', ['Value' => 1])), 'leaving the maze with no types hides the radar'),
        $assert($math('NearlyEqual_FloatFloat', $radarField('SSRadarViewRadiusCm'), $radarField('SSRadarRadiusCm'), $f(0.01)), 'leaving the maze restores the radar zoom'),
        // Baked handmade labyrinth: volume test, floor by height, walls and holes copied from the tables.
        ...$hm(20100, 20000, 1100),
        $assert($radarField('SSRadarMazeActive'), 'handmade: inside its volume the layer is active'),
        $assert($intEq($radarField('SSRadarMazeFloor'), 0), 'handmade: standing on the lower floor'),
        $assert($intEq($radarField('SSRadarMazeFloors'), 2), 'handmade: floor count'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarWallAX')), 6), 'handmade: lower floor walls loaded'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarHoleX')), 0), 'handmade: lower floor has no holes'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarMarkX')), 1), 'handmade: lower floor jump pad marker'),
        $assert($intEq($radarField('SSRadarMazeMarksShown'), 1), 'handmade: marker shown'),
        $array('Array_Get', $radarField('SSRadarWallKinds'), $i(1), $l('Number')),
        $assert($intEq($l('Number'), 1), 'handmade: wall kind copied'),
        $assert($math('EqualEqual_ByteByte', $canvas(), FilterExpr('ByteConst', ['Value' => 3])), 'handmade: radar visible without selected types'),
        ...$hm(20100, 20000, 1600),
        $assert($intEq($radarField('SSRadarMazeFloor'), 1), 'handmade: upper floor by height'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarWallAX')), 7), 'handmade: upper floor walls loaded'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarHoleX')), 2), 'handmade: upper floor holes loaded'),
        $array('Array_Get', $radarField('SSRadarWallAX'), $i(3), $l('Value')),
        $assert($math('NearlyEqual_FloatFloat', $l('Value'), $f(19400), $f(0.01)), 'handmade: wall coordinates copied'),
        $assert($math('NearlyEqual_FloatFloat', $radarField('SSRadarMazeYaw'), $f(30), $f(0.01)), 'handmade: maze yaw for drop squares'),
        $assert($intEq($radarField('SSRadarMazeHolesShown'), 2), 'handmade: drop squares shown'),
        $assert($intEq($array('Array_Length', $radarField('SSRadarMarkX')), 2), 'handmade: upper floor markers'),
        $array('Array_Get', $radarField('SSRadarMarkKinds'), $i(0), $l('Number')),
        $assert($intEq($l('Number'), 2), 'handmade: down marker over the shaft'),
        // Along the volume's rotated X axis: 1100 is past its 1000 half extent, 900 is inside.
        ...$hm(20000 + 1100 * cos(deg2rad(30)), 20000 + 1100 * sin(deg2rad(30)), 1600),
        $assert($math('Not_PreBool', $radarField('SSRadarMazeActive')), 'handmade: beyond the rotated volume the layer is off'),
        ...$hm(20000 + 900 * cos(deg2rad(30)), 20000 + 900 * sin(deg2rad(30)), 1600),
        $assert($radarField('SSRadarMazeActive'), 'handmade: just inside the rotated volume'),
        ...$hm(20100, 20000, 2700),
        $assert($math('Not_PreBool', $radarField('SSRadarMazeActive')), 'handmade: above the volume the layer is off'),
    ];
    array_push($code, FilterExpr('LetBool', ['VariableExpression' => $s('Passed'), 'AssignmentExpression' => $truth(true)]), $timer('VerifyLive', 'Finish', 0.2), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $timer('VerifyLive', 'Finish', 0.2));
    $b->define('VerifyLive', [], ['Cells' => 'Int', 'I' => 'Int', 'J' => 'Int', 'K' => 'Int', 'Number' => 'Int', 'Value' => 'Float', 'PuzzleComponent' => 'Object', 'Current' => 'Object',
        'Size' => 'Struct', 'At' => 'Struct', 'Xf' => 'Struct', 'Cell' => 'Struct', 'Coord' => 'Struct', 'Timer' => 'Struct'], $code);
    $types = ['PuzzleComponent' => 'CharPuzzleComponent', 'Current' => 'MirrorMazePuzzle', 'Xf' => ['Transform', 48], 'Cell' => ['MirrorMazeCell', 0x40], 'Coord' => ['IntVector', 12]];
    foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
        $type = $types[$p->Name] ?? null;
        if (is_array($type)) { $p->Struct = $b->native[$type[0]]; $p->ElementSize = $type[1]; }
        elseif ($type !== null) { $p->PropertyClass = $b->native[$type]; }
    }

    $travel = static fn(bool $pass): object => $b->call('GameplayStatics', 'OpenLevel', [FilterExpr('Self'), FilterExpr('NameConst', ['Value' => '/Game/SaveStatsTests/' . ($pass ? 'SSRadarPass' : 'SSRadarFail')]), $truth(true), $s('Out')]);
    $b->define('Finish', [], [], [FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => 'Fail', 'BooleanExpression' => $s('Passed')]), $travel(true), FilterExpr('Return', ['ReturnExpression' => FilterExpr('Nothing')]), ['label' => 'Fail'], $travel(false)]);
    foreach (['/Game/SaveStatsTests/BP_SSRadarTest', '/Game/SaveStatsTests/SSRadarPass', '/Game/SaveStatsTests/SSRadarFail'] as $name) { $b->name($name); }
    $asset = $b->finish();
    AssertNoContextCallArguments($asset, 'labyrinth radar fixture');
    file_put_contents($output . '/BP_SSRadarTest.json', json_encode($asset, JSON_THROW_ON_ERROR));
    foreach (['Login', 'SSRadarPass', 'SSRadarFail'] as $name) { copy(OverlayTestDataPath('EngineFixtures/' . $name . '.json'), $output . '/' . $name . '.json'); }
    $result = ['assertions' => count($checks), 'checks' => $checks, 'maze' => ['cells' => $cells, 'yaw' => $yaw, 'scale' => $scale, 'seed' => $seed, 'location' => [1000, 2000, 3000]],
        'radarLabyrinth' => $manifest['labyrinth'], 'onlyIsolatedMemoryMutated' => true, 'saveFunctionsCalled' => false];
    file_put_contents($output . '/fixture.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $result;
}

/** A baked handmade labyrinth with known answers: two floors, one rotated bounding volume. */
function LabyrinthRadarFixtureHandmade(): array
{
    $wall = static fn(float $ax, float $ay, float $bx, float $by, int $kind): array => [$ax, $ay, $bx, $by, $kind];
    return ['pid' => 99001, 'name' => 'FixtureHandmade', 'x' => 20000, 'y' => 20000, 'z' => 1000, 'yaw' => 30, 'cell' => 500,
        'boxes' => [['x' => 20000, 'y' => 20000, 'z' => 1500, 'yaw' => 30, 'ex' => 1000, 'ey' => 1000, 'ez' => 1000]],
        'floors' => [
            ['z' => 1000, 'walls' => array_map(static fn(int $k): array => $wall(19000 + $k * 100, 19500, 19500 + $k * 100, 19500, $k % 2), range(0, 5)), 'holes' => [], 'marks' => [[20250, 20250, 1]]],
            ['z' => 1500, 'walls' => array_map(static fn(int $k): array => $wall(19100 + $k * 100, 20500, 19600 + $k * 100, 20500, 1), range(0, 6)), 'holes' => [[20250, 20250], [20750, 20250]],
                'marks' => [[20250, 20250, 2], [19750, 19750, 1]]],
        ]];
}

/**
 * Offline check of a fixture run: every drawn wall must be exactly one maze edge on the player's floor
 * with no passage across it, every closed edge of that floor must be drawn once, glass/opaque must
 * match opaqueCells, and every up/down marker must sit on a cell with that vertical link.
 */
function VerifyLabyrinthRadarLog(string $log, array $fixture): array
{
    if (!preg_match('/LoadMap: \/Game\/SaveStatsTests\/(SSRadarPass|SSRadarFail)\S*\?lab=(\S*)/', $log, $m)) { throw new RuntimeException('No fixture LoadMap line in log.'); }
    if ($m[1] !== 'SSRadarPass') { throw new RuntimeException('Engine assertions failed: ' . substr($m[2], 0, 300)); }
    $records = explode('~', $m[2]);
    $head = []; foreach (explode(';', array_shift($records)) as $kv) { [$k, $v] = explode('=', $kv, 2); $head[$k] = $v; }
    // Reported cellSize is in world units; the grid below is in the actor's unscaled frame.
    $size = array_map(static fn(string $v): float => (int)$v / 100 / $fixture['maze']['scale'], explode(',', $head['size']));
    $cells = [];
    $floors = [];
    foreach ($records as $record) {
        if (str_starts_with($record, 'cells')) {
            foreach (array_slice(explode(';', $record), 1) as $c) {
                preg_match('/^c(-?\d+),(-?\d+),(-?\d+),t(\d):a([^:]*):o(.*)$/', $c, $x) || throw new RuntimeException('Bad cell ' . $c);
                $coords = static fn(string $s): array => array_map(static fn(string $v): array => array_map('intval', explode('.', $v)), array_values(array_filter(explode('/', $s))));
                $cells[$x[1] . ',' . $x[2] . ',' . $x[3]] = ['trap' => $x[4] === '1', 'adj' => $coords($x[5]), 'opaque' => $coords($x[6])];
            }
            continue;
        }
        $f = [];
        foreach (explode(';', $record) as $kv) { [$k, $v] = explode('=', $kv, 2) + [1 => '']; $f[$k] = $v; }
        $list = static fn(string $v, bool $float): array => array_map($float ? static fn(string $n): float => (int)$n / 100 : 'intval', array_values(array_filter(explode(',', $v), static fn(string $n): bool => $n !== '')));
        $floors[(int)$f['floor']] = ['shown' => (int)$f['shown'], 'marksShown' => (int)$f['marksShown'], 'ax' => $list($f['ax'], true), 'ay' => $list($f['ay'], true), 'bx' => $list($f['bx'], true), 'by' => $list($f['by'], true),
            'kind' => $list($f['kind'], false), 'mx' => $list($f['mx'], true), 'my' => $list($f['my'], true), 'mk' => $list($f['mk'], false),
            'hx' => $list($f['hx'], true), 'hy' => $list($f['hy'], true), 'holesShown' => (int)$f['holesShown']];
    }
    $n = $fixture['maze']['cells'];
    [$ox, $oy] = $fixture['maze']['location'];
    $yaw = deg2rad($fixture['maze']['yaw']); $scale = $fixture['maze']['scale'];
    // World XY -> grid units (0..n on each axis, corners integral).
    $grid = static function (float $x, float $y) use ($ox, $oy, $yaw, $scale, $size, $n): array {
        $dx = $x - $ox; $dy = $y - $oy;
        $lx = ($dx * cos(-$yaw) - $dy * sin(-$yaw)) / $scale; $ly = ($dx * sin(-$yaw) + $dy * cos(-$yaw)) / $scale;
        return [$lx / $size[0] + $n / 2, $ly / $size[1] + $n / 2];
    };
    $report = [];
    foreach ($floors as $floor => $data) {
        $drawn = [];
        foreach (array_keys($data['ax']) as $k) {
            [$ax, $ay] = $grid($data['ax'][$k], $data['ay'][$k]); [$bx, $by] = $grid($data['bx'][$k], $data['by'][$k]);
            foreach ([$ax, $ay, $bx, $by] as $v) { if (abs($v - round($v)) > 0.02) { throw new RuntimeException("Floor $floor wall $k endpoint off the cell grid ($v)."); } }
            [$ax, $ay, $bx, $by] = array_map('round', [$ax, $ay, $bx, $by]);
            if (abs($ax - $bx) + abs($ay - $by) != 1) { throw new RuntimeException("Floor $floor wall $k is not one cell edge."); }
            $key = $ax == $bx ? 'v' . (int)$ax . ',' . (int)min($ay, $by) : 'h' . (int)min($ax, $bx) . ',' . (int)$ay;
            if (isset($drawn[$key])) { throw new RuntimeException("Floor $floor edge $key drawn twice."); }
            $drawn[$key] = $data['kind'][$k];
        }
        // Expected: every edge of every cell on this floor with no passage across it.
        $expected = [];
        for ($x = 0; $x < $n; $x++) for ($y = 0; $y < $n; $y++) {
            $cell = $cells["$x,$y,$floor"] ?? throw new RuntimeException("Missing cell $x,$y,$floor");
            foreach ([[-1, 0, 'v' . $x . ',' . $y], [1, 0, 'v' . ($x + 1) . ',' . $y], [0, -1, 'h' . $x . ',' . $y], [0, 1, 'h' . $x . ',' . ($y + 1)]] as [$dx, $dy, $key]) {
                if (in_array([$x + $dx, $y + $dy, $floor], $cell['adj'], true)) { continue; }
                $expected[$key] = in_array([$x + $dx, $y + $dy, 0], $cell['opaque'], true) ? 1 : ($expected[$key] ?? 0);
            }
        }
        // The entrance (openingXY, here the default 0,0) has its outer walls removed on floor 0.
        $opening = $floor === 0 ? array_flip(['v0,0', 'h0,0']) : [];
        $missing = array_diff_key($expected, $drawn, $opening); $extra = array_diff_key($drawn, $expected);
        $kindMismatch = array_keys(array_filter($drawn, static fn(int $kind, string $key): bool => isset($expected[$key]) && $expected[$key] !== $kind, ARRAY_FILTER_USE_BOTH));
        // Markers: one per vertical link from this floor, placed at that cell's centre.
        $links = [];
        foreach ($cells as $pos => $cell) {
            [$x, $y, $z] = array_map('intval', explode(',', $pos));
            if ($z !== $floor) { continue; }
            foreach ($cell['adj'] as [$ax, $ay, $az]) { if ($az === $floor + 1) { $links[] = "$x,$y,1"; } elseif ($az === $floor - 1) { $links[] = "$x,$y,2"; } }
        }
        $marks = [];
        foreach (array_keys($data['mx']) as $k) {
            [$gx, $gy] = $grid($data['mx'][$k], $data['my'][$k]);
            if (abs($gx - floor($gx) - 0.5) > 0.02 || abs($gy - floor($gy) - 0.5) > 0.02) { throw new RuntimeException("Floor $floor marker $k is not at a cell centre."); }
            $marks[] = (int)floor($gx) . ',' . (int)floor($gy) . ',' . $data['mk'][$k];
        }
        sort($links); sort($marks);
        // Drops: a cell is open underfoot when the cell below has a trapdoor or a link up into it.
        $expectedHoles = [];
        if ($floor > 0) {
            for ($x = 0; $x < $n; $x++) for ($y = 0; $y < $n; $y++) {
                $below = $cells["$x,$y," . ($floor - 1)];
                if ($below['trap'] || in_array([$x, $y, $floor], $below['adj'], true)) { $expectedHoles[] = "$x,$y"; }
            }
        }
        $holes = [];
        foreach (array_keys($data['hx']) as $k) {
            [$gx, $gy] = $grid($data['hx'][$k], $data['hy'][$k]);
            if (abs($gx - floor($gx) - 0.5) > 0.02 || abs($gy - floor($gy) - 0.5) > 0.02) { throw new RuntimeException("Floor $floor hole $k is not at a cell centre."); }
            $holes[] = (int)floor($gx) . ',' . (int)floor($gy);
        }
        sort($holes); sort($expectedHoles);
        $report[$floor] = ['walls' => count($drawn), 'expected' => count($expected), 'missing' => array_keys($missing), 'extra' => array_keys($extra), 'kindMismatch' => $kindMismatch,
            'shown' => $data['shown'], 'markers' => count($marks), 'markersMatch' => $links === $marks, 'marksShown' => $data['marksShown'],
            'holes' => $holes, 'holesMatch' => $holes === $expectedHoles, 'expectedHoles' => $expectedHoles, 'holesShown' => $data['holesShown']];
    }
    return ['outcome' => 'pass', 'cellSize' => $size, 'cells' => count($cells), 'floors' => $report];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['output-dir:', 'verify-log:', 'fixture:']);
        if (isset($options['verify-log'])) {
            $fixture = json_decode(file_get_contents($options['fixture'] ?? throw new InvalidArgumentException('Provide --fixture with --verify-log')), true, 512, JSON_THROW_ON_ERROR);
            echo json_encode(VerifyLabyrinthRadarLog((string)file_get_contents($options['verify-log']), $fixture), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            exit(0);
        }
        if (!isset($options['output-dir'])) { throw new InvalidArgumentException('Provide --output-dir'); }
        echo json_encode(BuildLabyrinthRadarFixture(realpath($options['output-dir'])), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
