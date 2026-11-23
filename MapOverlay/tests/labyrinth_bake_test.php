<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/labyrinth_bake.php';

/** Synthetic labyrinth bake checks: log transport, piece ownership, floors, wall kinds and holes. */
function LabyrinthBakeAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('Labyrinth bake test failed: ' . $message); }
}

// A maze at (10000, 0, 1000) turned 90 degrees, grid offset 0; local +X maps to world +Y. Its volume
// hugs the 2 x 2 room (local 0..1000), as the game's volumes hug their floors.
$maze = ['x' => 10000, 'y' => 0, 'z' => 1000, 'yaw' => 90];
$world = static fn(float $lx, float $ly): array => [10000 - $ly, $lx];
$tokens = ['ready=1', 'tries=0', 'mazes=1', 'M' . 'BP_HandmadeMirrorMaze9,10000,0,1000,90', 'B9500,500,1500,90,500,500,1000'];
$pieces = 0;
$wall = static function (float $lx, float $ly, bool $alongX, float $bottom, string $material) use (&$tokens, &$pieces, $world): void {
    [$wx, $wy] = $world($lx, $ly);
    // Upright 500 x 500 x 10 panel: long axis horizontal, one axis vertical.
    $long = $alongX ? [0, 500, 0] : [-500, 0, 0];
    $thin = $alongX ? [-10, 0, 0] : [0, 10, 0];
    $tokens[] = 'C' . 'cube^' . $material;
    $tokens[] = sprintf('I%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d', $wx, $wy, $bottom + 250, ...$long, ...$thin, ...[0, 0, 500]);
    $pieces++;
};
$tile = static function (float $lx, float $ly, float $top) use (&$tokens, &$pieces, $world): void {
    [$wx, $wy] = $world($lx, $ly);
    $tokens[] = 'Ccube^M_MirrorMaze_Floor';
    $tokens[] = sprintf('I%d,%d,%d,0,500,0,-500,0,0,0,0,10', $wx, $wy, $top);
    $pieces++;
};
// Floor 1 (z 1000): a 2 x 2 room, 8 boundary walls (6 glass, 2 solid), tiled.
foreach ([0, 1] as $c) {
    $wall($c * 500 + 250, 0, true, 1000, 'M_MirrorMaze_Glass_01');
    $wall($c * 500 + 250, 1000, true, 1000, $c === 0 ? 'MI_MirrorMaze_Wall_05' : 'M_MirrorMaze_Glass_01');
    $wall(0, $c * 500 + 250, false, 1000, 'M_MirrorMaze_Glass_01');
    $wall(1000, $c * 500 + 250, false, 1000, $c === 0 ? 'MI_MirrorMaze_Wall_Color1' : 'M_MirrorMaze_Glass_01');
    foreach ([0, 1] as $r) { $tile($c * 500 + 250, $r * 500 + 250, 1000); }
}
// Floor 2 (z 1500): same room, one tile missing at local cell (1, 1): a drop.
foreach ([0, 1] as $c) {
    $wall($c * 500 + 250, 0, true, 1500, 'M_MirrorMaze_Glass_01');
    $wall($c * 500 + 250, 1000, true, 1500, 'M_MirrorMaze_Glass_01');
    $wall(0, $c * 500 + 250, false, 1500, 'M_MirrorMaze_Glass_01');
    $wall(1000, $c * 500 + 250, false, 1500, 'M_MirrorMaze_Glass_01');
    foreach ([0, 1] as $r) { if ($c !== 1 || $r !== 1) { $tile($c * 500 + 250, $r * 500 + 250, 1500); } }
}
// Level 2000: a one-cell shaft with four walls is not a floor.
$wall(250, 0, true, 2000, 'M_MirrorMaze_Glass_01'); $wall(250, 500, true, 2000, 'M_MirrorMaze_Glass_01');
$wall(0, 250, false, 2000, 'M_MirrorMaze_Glass_01'); $wall(500, 250, false, 2000, 'M_MirrorMaze_Glass_01');
// Jump pads: one on the lower floor under the drop, one outside every maze (ignored).
[$px, $py] = $world(750, 750);
$tokens[] = sprintf('P%d,%d,%d', $px, $py, 1015);
$tokens[] = 'P30000,30000,1015';
// A cube far outside the volume belongs to no maze; a non-cube mesh is never a piece.
$tokens[] = 'Ccube^M_MirrorMaze_Glass_01'; $tokens[] = 'I30000,30000,1250,500,0,0,0,10,0,0,0,500'; $pieces++;
$tokens[] = 'cubes=' . $pieces; $tokens[] = 'pads=2'; $tokens[] = 'end=1';
$log = "[0]LogLoad: LoadMap: " . LABYRINTH_BAKE_DONE_MAP . "??game=/Script/Engine.GameModeBase?bake=level0=1;" . implode(';', $tokens) . "\n";

$dump = ParseLabyrinthBakeLog($log);
LabyrinthBakeAssert(count($dump['mazes']) === 1 && count($dump['mazes'][0]['boxes']) === 1, 'one maze with its volume');
LabyrinthBakeAssert(count($dump['pieces']) === $pieces, 'every piece transported');
$layouts = BuildLabyrinthLayouts($dump, ['777' => ['ptype' => 'mirrorMaze', 'x' => 10000.4, 'y' => -0.3, 'z' => 1000.2], '778' => ['ptype' => 'logicGrid', 'x' => 10000, 'y' => 0, 'z' => 1000]]);
LabyrinthBakeAssert(count($layouts) === 1, 'one layout');
$layout = $layouts[0];
LabyrinthBakeAssert($layout['pid'] === 777, 'catalogue puzzle matched by position and type');
LabyrinthBakeAssert(count($layout['floors']) === 2, 'two floors; the one-cell shaft is not a floor');
[$lower, $upper] = $layout['floors'];
LabyrinthBakeAssert(abs($lower['z'] - 1000) < 1 && abs($upper['z'] - 1500) < 1, 'floor heights');
LabyrinthBakeAssert(count($lower['walls']) === 8 && count($upper['walls']) === 8, 'eight walls per floor; the outside cube is excluded');
LabyrinthBakeAssert(array_sum(array_column($lower['walls'], 4)) === 2, 'solid walls by material, glass otherwise');
LabyrinthBakeAssert($lower['holes'] === [], 'the lowest floor never has holes');
LabyrinthBakeAssert(count($upper['holes']) === 1, 'one drop on the upper floor');
[$hx, $hy] = $upper['holes'][0];
[$ex, $ey] = $world(750, 750);
LabyrinthBakeAssert(abs($hx - $ex) < 1 && abs($hy - $ey) < 1, 'drop at the missing tile cell centre, in world space');
LabyrinthBakeAssert(count($dump['pads']) === 2, 'jump pads transported');
LabyrinthBakeAssert(count($lower['marks']) === 1 && $lower['marks'][0][2] === 1, 'up marker on the pad\'s floor');
LabyrinthBakeAssert(abs($lower['marks'][0][0] - $px) < 1 && abs($lower['marks'][0][1] - $py) < 1, 'up marker at the pad');
LabyrinthBakeAssert(count($upper['marks']) === 1 && $upper['marks'][0][2] === 2, 'down marker on the hole above the pad');
$w = $lower['walls'][0];
LabyrinthBakeAssert(abs(hypot($w[2] - $w[0], $w[3] - $w[1]) - 500) < 1, 'walls are one cell long');
// Radar tables flatten the layout consistently.
$tables = RadarLabyrinthTables($layouts);
LabyrinthBakeAssert($tables['MazeFloorStart'] === [0] && $tables['MazeFloorEnd'] === [2] && count($tables['WallAX']) === 16 && count($tables['HoleX']) === 1, 'radar tables');
LabyrinthBakeAssert($tables['FloorWallStart'] === [0, 8] && $tables['FloorHoleStart'] === [0, 0] && $tables['FloorHoleEnd'] === [0, 1], 'radar table ranges');
LabyrinthBakeAssert($tables['FloorMarkStart'] === [0, 1] && $tables['FloorMarkEnd'] === [1, 2] && $tables['MarkKind'] === [1, 2], 'radar marker tables');

// Transport failures are reported, never silently baked.
foreach (['end=1' => 'end=0', 'cubes=' . $pieces => 'cubes=' . ($pieces + 1), 'ready=1' => 'ready=0', 'pads=2' => 'pads=3'] as $from => $to) {
    $failed = false;
    try { ParseLabyrinthBakeLog(str_replace($from, $to, $log)); } catch (RuntimeException) { $failed = true; }
    LabyrinthBakeAssert($failed, 'rejects a dump with ' . $to);
}
$failed = false;
try { ParseLabyrinthBakeLog('no bake here'); } catch (RuntimeException) { $failed = true; }
LabyrinthBakeAssert($failed, 'rejects a log without the done map');
echo "Labyrinth bake transport, ownership, floor, wall-kind, hole, jump-pad marker and radar table checks passed.\n";
