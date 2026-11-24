<?php

declare(strict_types=1);

/*
 * Live random-spot pins. Random-spot (container) Hub puzzles have no fixed location:
 * the game picks a site for each one when it spawns. SophiaGameState.sandboxZones holds
 * one SandboxZone per zone, and each zone's spawnedPuzzles lists the live puzzle actors.
 * A budgeted pass walks those actors, keeps the ones whose KrakenId is a random-spot Hub
 * puzzle, and gives each the nearest site that allows its type within RADAR_SITE_MATCH_CM.
 * Sites then show (radar and map) only while such a puzzle stands on them.
 *
 * Proven on 2026-11-24 with an isolated copy of the game in the offline world: 486 of
 * 486 spawned random-spot puzzles matched 486 distinct sites.
 */

const RADAR_LIVE_SITE_BUDGET = 128;

function AddRadarLiveSiteFields(RadarWidgetBuilder $b, array $index): void
{
    foreach (['SophiaGameState', 'SandboxZone'] as $class) {
        $b->native[$class] = OverlayImport($b->asset, $class, $b->native['IslandsofInsight'], '/Script/CoreUObject', 'Class');
    }
    $sites = count($index['siteX']);
    $zeros = array_fill(0, $sites, 0);
    foreach (['SSRadarSiteNumbers' => $index['siteNumbers'], 'SSRadarSiteTypeBits' => $index['siteTypeBits'],
        'SSRadarSiteLookupKeys' => $index['siteLookupKeys'], 'SSRadarSiteLookupNumbers' => $index['siteLookupNumbers'],
        'SSRadarLiveIds' => $index['liveIds'], 'SSRadarLiveTypes' => $index['liveTypes'], 'SSRadarSiteZeros' => $zeros,
        'SSRadarSiteOccupant' => $zeros, 'SSRadarSiteOccupantType' => $zeros, 'SSRadarSiteWork' => $zeros, 'SSRadarSiteWorkType' => $zeros] as $name => $values) {
        $b->arrayField('Int', $name, $values);
    }
    foreach (['X', 'Y', 'Z'] as $axis) { $b->arrayField('Float', 'SSRadarSite' . $axis, $index['site' . $axis]); }
    $b->arrayField('Object', 'SSRadarSiteActors', []);
    $b->class->LoadedProperties[array_key_last($b->class->LoadedProperties)]->Inner->PropertyClass = $b->native['PuzzleBase'];
    $b->objectField('SSRadarSiteState', $b->native['SophiaGameState']);
    foreach (['SSRadarSiteZoneCursor' => -1, 'SSRadarSiteActorCursor' => 0] as $name => $value) { $b->scalar('Int', $name, $value); }
    $b->scalar('Bool', 'SSRadarSitesReady', 0);
}

/** @param array $invalidate code that drops the radar's pins and tells the map to recheck */
function AddRadarLiveSiteFunctions(RadarWidgetBuilder $b, array $index, array $invalidate): array
{
    $f = static fn(float $n): object => FilterExpr('FloatConst', ['Value' => $n]);
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
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
    $member = static fn(object $of, string $name): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native['Vector'], $name), 'StructExpression' => $of]);
    $patch = static function (int $fn, array $types) use ($b): void {
        foreach ($b->asset->Exports[$fn - 1]->LoadedProperties as $p) {
            $type = $types[$p->Name] ?? null;
            if ($type === null) { continue; }
            if (is_array($type)) { $p->Inner = $b->field('Object', $p->Name); $p->Inner->PropertyClass = $b->native[$type[0]]; }
            else { $p->PropertyClass = $b->native[$type]; }
        }
    };
    $radius = (float)$index['siteMatchCm'];
    $cell = (float)$index['siteCellCm'];

    $fn = $b->functions['SSRadarRefreshSites'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $let = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => $l($n), 'Expression' => $v]);
    $resetPass = [$set('SSRadarSiteWork', $state('SSRadarSiteZeros')), $set('SSRadarSiteWorkType', $state('SSRadarSiteZeros')),
        $set('SSRadarSiteZoneCursor', $i(-1)), $set('SSRadarSiteActorCursor', $i(0)), $array('Array_Clear', $state('SSRadarSiteActors'))];
    $code = [
        // Off: forget everything, so turning sites back on never shows an old assignment.
        $unless($state('SSRadarIncludeSites'), 'Lost'),
        $let('State', FilterExpr('DynamicCast', ['ClassPtr' => $b->native['SophiaGameState'], 'TargetExpression' => $b->call('GameplayStatics', 'GetGameState', [FilterExpr('Self')])])),
        $unless($valid($l('State')), 'Lost'),
        $unless($math('EqualEqual_ObjectObject', $l('State'), $state('SSRadarSiteState')), 'NewWorld'), $jump('Work'),
        ['label' => 'NewWorld'], $unless($state('SSRadarSitesReady'), 'Adopt'),
        $setBool('SSRadarSitesReady', $bool(false)), $set('SSRadarSiteOccupant', $state('SSRadarSiteZeros')), $set('SSRadarSiteOccupantType', $state('SSRadarSiteZeros')), ...$invalidate,
        ['label' => 'Adopt'], $set('SSRadarSiteState', $l('State')), ...$resetPass,
        ['label' => 'Work'], $let('Budget', $i(0)),
        ['label' => 'Loop'], $unless($math('Less_IntInt', $l('Budget'), $i(RADAR_LIVE_SITE_BUDGET)), 'Done'),
        $let('Budget', $math('Add_IntInt', $l('Budget'), $i(1))),
        $unless($math('Less_IntInt', $state('SSRadarSiteActorCursor'), $array('Array_Length', $state('SSRadarSiteActors'))), 'NextZone'),
        $array('Array_Get', $state('SSRadarSiteActors'), $state('SSRadarSiteActorCursor'), $l('Actor')),
        $set('SSRadarSiteActorCursor', $math('Add_IntInt', $state('SSRadarSiteActorCursor'), $i(1))),
        $unless($valid($l('Actor')), 'Loop'),
        $let('PuzzleId', $field($l('Actor'), 'PuzzleBase', 'KrakenId')),
        $let('Live', $array('Array_Find', $state('SSRadarLiveIds'), $l('PuzzleId'))),
        $unless($math('GreaterEqual_IntInt', $l('Live'), $i(0)), 'Loop'),
        $array('Array_Get', $state('SSRadarLiveTypes'), $l('Live'), $l('Type')),
        $array('Array_Get', $state('SSRadarTypeBits'), $l('Type'), $l('Bit')),
        $let('SiteLocation', $b->method($l('Actor'), 'Actor', 'K2_GetActorLocation', [], FilterPointer($fn, 'SiteLocation'))),
        $let('X', $member($l('SiteLocation'), 'X')), $let('Y', $member($l('SiteLocation'), 'Y')), $let('Z', $member($l('SiteLocation'), 'Z')),
        $let('Best', $i(0)), $let('BestDistance', $f($radius * $radius))];
    // The match circle spans at most 2x2 lookup cells; check each corner's cell.
    foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as $corner => [$sx, $sy]) {
        $c = 'Corner' . $corner;
        $code = [...$code,
            $let('CellX', $math('Subtract_IntInt', $math('FFloor', $math('Divide_FloatFloat', $math('Add_FloatFloat', $l('X'), $f($sx * $radius)), $f($cell))), $i($index['siteOriginCellX']))),
            $let('CellY', $math('Subtract_IntInt', $math('FFloor', $math('Divide_FloatFloat', $math('Add_FloatFloat', $l('Y'), $f($sy * $radius)), $f($cell))), $i($index['siteOriginCellY']))),
            $unless($math('BooleanAND', $math('BooleanAND', $math('GreaterEqual_IntInt', $l('CellX'), $i(0)), $math('Less_IntInt', $l('CellX'), $i($index['siteWidth']))),
                $math('BooleanAND', $math('GreaterEqual_IntInt', $l('CellY'), $i(0)), $math('Less_IntInt', $l('CellY'), $i($index['siteHeight'])))), $c . 'Done'),
            $let('Key', $math('Add_IntInt', $math('Multiply_IntInt', $l('CellX'), $i($index['siteHeight'])), $l('CellY'))),
            $let('Slot', $array('Array_Find', $state('SSRadarSiteLookupKeys'), $l('Key'))),
            ['label' => $c . 'Loop'],
            $unless($math('BooleanAND', $math('GreaterEqual_IntInt', $l('Slot'), $i(0)), $math('Less_IntInt', $l('Slot'), $array('Array_Length', $state('SSRadarSiteLookupKeys')))), $c . 'Done'),
            $array('Array_Get', $state('SSRadarSiteLookupKeys'), $l('Slot'), $l('LookupKey')),
            $unless($math('EqualEqual_IntInt', $l('LookupKey'), $l('Key')), $c . 'Done'),
            $array('Array_Get', $state('SSRadarSiteLookupNumbers'), $l('Slot'), $l('Site')),
            $let('Slot', $math('Add_IntInt', $l('Slot'), $i(1))),
            $array('Array_Get', $state('SSRadarSiteTypeBits'), $l('Site'), $l('Mask')),
            $unless($math('NotEqual_IntInt', $math('And_IntInt', $l('Mask'), $l('Bit')), $i(0)), $c . 'Loop'),
            $array('Array_Get', $state('SSRadarSiteX'), $l('Site'), $l('SiteX')), $array('Array_Get', $state('SSRadarSiteY'), $l('Site'), $l('SiteY')), $array('Array_Get', $state('SSRadarSiteZ'), $l('Site'), $l('SiteZ')),
            $let('DX', $math('Subtract_FloatFloat', $l('SiteX'), $l('X'))), $let('DY', $math('Subtract_FloatFloat', $l('SiteY'), $l('Y'))), $let('DZ', $math('Subtract_FloatFloat', $l('SiteZ'), $l('Z'))),
            $let('Distance', $math('Add_FloatFloat', $math('Add_FloatFloat', $math('Multiply_FloatFloat', $l('DX'), $l('DX')), $math('Multiply_FloatFloat', $l('DY'), $l('DY'))), $math('Multiply_FloatFloat', $l('DZ'), $l('DZ')))),
            $unless($math('Less_FloatFloat', $l('Distance'), $l('BestDistance')), $c . 'Loop'),
            $let('BestDistance', $l('Distance')), $let('Best', $l('Site')), $jump($c . 'Loop'),
            ['label' => $c . 'Done']];
    }
    $code = [...$code,
        $unless($math('Greater_IntInt', $l('Best'), $i(0)), 'Loop'),
        // Array_Set reads its item through MostRecentPropertyAddress: pass plain locals only.
        $array('Array_Set', $state('SSRadarSiteWork'), $l('Best'), $l('PuzzleId'), $bool(false)),
        $array('Array_Set', $state('SSRadarSiteWorkType'), $l('Best'), $l('Type'), $bool(false)),
        $jump('Loop'),
        ['label' => 'NextZone'],
        $set('SSRadarSiteZoneCursor', $math('Add_IntInt', $state('SSRadarSiteZoneCursor'), $i(1))),
        $array('Array_Clear', $state('SSRadarSiteActors')), $set('SSRadarSiteActorCursor', $i(0)),
        $let('Zones', $field($state('SSRadarSiteState'), 'SophiaGameState', 'sandboxZones')),
        $unless($math('Less_IntInt', $state('SSRadarSiteZoneCursor'), $array('Array_Length', $l('Zones'))), 'Publish'),
        $array('Array_Get', $l('Zones'), $state('SSRadarSiteZoneCursor'), $l('Zone')),
        $unless($valid($l('Zone')), 'Loop'),
        $set('SSRadarSiteActors', $field($l('Zone'), 'SandboxZone', 'spawnedPuzzles')),
        $jump('Loop'),
        // A whole pass is in: publish only a real change, then start the next pass.
        ['label' => 'Publish'],
        $unless($math('BooleanAND', $state('SSRadarSitesReady'), $math('BooleanAND', $array('Array_Identical', $state('SSRadarSiteWork'), $state('SSRadarSiteOccupant')),
            $array('Array_Identical', $state('SSRadarSiteWorkType'), $state('SSRadarSiteOccupantType')))), 'Changed'),
        $jump('NextPass'),
        ['label' => 'Changed'], $set('SSRadarSiteOccupant', $state('SSRadarSiteWork')), $set('SSRadarSiteOccupantType', $state('SSRadarSiteWorkType')),
        $setBool('SSRadarSitesReady', $bool(true)), ...$invalidate,
        ['label' => 'NextPass'], ...$resetPass, $jump('Done'),
        // No game state (or sites off): nothing is known, so no site may show.
        ['label' => 'Lost'], $unless($state('SSRadarSitesReady'), 'Forget'),
        $setBool('SSRadarSitesReady', $bool(false)), $set('SSRadarSiteOccupant', $state('SSRadarSiteZeros')), $set('SSRadarSiteOccupantType', $state('SSRadarSiteZeros')), ...$invalidate,
        // Already forgotten: the work tables are clean, so skip the per-tick reset.
        ['label' => 'Forget'], $unless($valid($state('SSRadarSiteState')), 'Done'),
        $set('SSRadarSiteState', FilterExpr('ObjectConst', ['Value' => 0])), ...$resetPass,
        ['label' => 'Done']];
    $b->define('SSRadarRefreshSites', [], ['State' => 'Object', 'Zones' => 'Array', 'Zone' => 'Object', 'Actor' => 'Object', 'SiteLocation' => 'Struct',
        'Budget' => 'Int', 'PuzzleId' => 'Int', 'Live' => 'Int', 'Type' => 'Int', 'Bit' => 'Int', 'Best' => 'Int', 'CellX' => 'Int', 'CellY' => 'Int',
        'Key' => 'Int', 'Slot' => 'Int', 'LookupKey' => 'Int', 'Site' => 'Int', 'Mask' => 'Int',
        'X' => 'Float', 'Y' => 'Float', 'Z' => 'Float', 'SiteX' => 'Float', 'SiteY' => 'Float', 'SiteZ' => 'Float',
        'DX' => 'Float', 'DY' => 'Float', 'DZ' => 'Float', 'Distance' => 'Float', 'BestDistance' => 'Float'], $code);
    $patch($fn, ['State' => 'SophiaGameState', 'Zones' => ['SandboxZone'], 'Zone' => 'SandboxZone', 'Actor' => 'PuzzleBase']);

    // Radar: the puzzle standing on a site point, if it is the point's own type; zero otherwise.
    $fn = $b->functions['SSRadarSitePuzzle'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $b->define('SSRadarSitePuzzle', ['Point' => 'Int'], ['Site' => 'Int', 'PuzzleId' => 'Int', 'Type' => 'Int', 'PointType' => 'Int'], [
        $unless($state('SSRadarSitesReady'), 'None'),
        $array('Array_Get', $state('SSRadarSiteNumbers'), $l('Point'), $l('Site')),
        $unless($math('Greater_IntInt', $l('Site'), $i(0)), 'None'),
        $array('Array_Get', $state('SSRadarSiteOccupant'), $l('Site'), $l('PuzzleId')),
        $unless($math('Greater_IntInt', $l('PuzzleId'), $i(0)), 'None'),
        $array('Array_Get', $state('SSRadarSiteOccupantType'), $l('Site'), $l('Type')),
        $array('Array_Get', $state('SSRadarPointTypes'), $l('Point'), $l('PointType')),
        $unless($math('EqualEqual_IntInt', $l('Type'), $l('PointType')), 'None'),
        FilterExpr('Return', ['ReturnExpression' => $l('PuzzleId')]),
        ['label' => 'None'], FilterExpr('Return', ['ReturnExpression' => $i(0)])], null, 'Int');

    // Map: a site marker shows while an unsolved puzzle of a selected type stands on it.
    $fn = $b->functions['SSRadarIsSiteLive'];
    $l = static fn(string $n): object => FilterVariable($fn, $n);
    $b->define('SSRadarIsSiteLive', ['Site' => 'Int'], ['PuzzleId' => 'Int', 'Type' => 'Int', 'Bit' => 'Int'], [
        $unless($state('SSRadarSitesReady'), 'Hidden'),
        $unless($math('BooleanAND', $math('Greater_IntInt', $l('Site'), $i(0)), $math('Less_IntInt', $l('Site'), $array('Array_Length', $state('SSRadarSiteOccupant')))), 'Hidden'),
        $array('Array_Get', $state('SSRadarSiteOccupant'), $l('Site'), $l('PuzzleId')),
        $unless($math('Greater_IntInt', $l('PuzzleId'), $i(0)), 'Hidden'),
        $array('Array_Get', $state('SSRadarSiteOccupantType'), $l('Site'), $l('Type')),
        $array('Array_Get', $state('SSRadarTypeBits'), $l('Type'), $l('Bit')),
        $unless($math('NotEqual_IntInt', $math('And_IntInt', $state('SSRadarSelectedMask'), $l('Bit')), $i(0)), 'Hidden'),
        FilterExpr('Return', ['ReturnExpression' => $math('Not_PreBool', $localCall('SSRadarIsSolved', [$l('PuzzleId')]))]),
        ['label' => 'Hidden'], FilterExpr('Return', ['ReturnExpression' => $bool(false)])], null, 'Bool');

    return ['refreshFunction' => 'SSRadarRefreshSites', 'radarQuery' => 'SSRadarSitePuzzle(Point)', 'mapQuery' => 'SSRadarIsSiteLive(Site)',
        'authority' => 'GameplayStatics.GetGameState as SophiaGameState -> sandboxZones[] (SandboxZone) -> spawnedPuzzles[] (PuzzleBase): KrakenId, K2_GetActorLocation',
        'actorsPerRefresh' => RADAR_LIVE_SITE_BUDGET, 'matchRadiusCm' => $index['siteMatchCm'], 'lookupCellCm' => $index['siteCellCm'],
        'sites' => count($index['siteX']) - 1, 'randomSpotHubIds' => count($index['liveIds']), 'siteTag' => 'SaveStats.Site.',
        'rule' => 'a site shows only while a spawned random-spot puzzle of the pin type stands on it; nearest allowed site wins',
        'unreadyHidesSites' => true, 'publishOnlyOnChange' => true, 'runsOnlyWhileSitesOn' => true,
        'runtimeEvidence' => 'isolated offline game 2026-11-24: 486/486 spawned random-spot puzzles matched distinct sites within 3.7 m'];
}
