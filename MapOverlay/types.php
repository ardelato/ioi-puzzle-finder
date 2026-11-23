<?php

declare(strict_types=1);

/** Shared labels, native encyclopedia art, and stable nine-character marker asset names. */
function GetOverlayTypes(): array
{
    static $types = null;
    if ($types === null) {
        $names = [
            'completeThePattern' => 'Pattern Grid',
            'followTheShiny' => 'Wandering Echo',
            'fractalMatch' => 'Morphic Fractal',
            'ghostObject' => 'Shy Aura',
            'gyroRing' => 'Armillary Rings',
            'hiddenArchway' => 'Hidden Archway',
            'hiddenCube' => 'Hidden Cube',
            'hiddenRing' => 'Hidden Ring',
            'klotski' => 'Shifting Mosaic',
            'levelRestrictionVolume' => 'Entrance Unlock',
            'lightPattern' => 'Light Motif',
            'lockpick' => 'Phasic Dial',
            'logicGrid' => 'Logic Grid',
            'loreFragment' => 'Lore Fragment',
            'match3' => 'Match Three',
            'matchbox' => 'Matchbox',
            'memoryGrid' => 'Memory Grid',
            'mirrorMaze' => 'Crystal Labyrinth',
            'monolithFragment' => 'Monolith Fragment',
            'musicGrid' => 'Music Grid',
            'racingBallCourse' => 'Flow Orbs',
            'racingRingCourse' => 'Glide Rings',
            'rollingCube' => 'Rolling Block',
            'rosary' => 'Skydrop',
            'ryoanji' => 'Sentinel Stones',
            'seek5' => 'Hidden Pentad',
            'spawnSite' => 'Possible Spawn Site',
            'viewfinder' => 'Sightseer',
        ];
        ksort($names, SORT_STRING);
        // EncyclopediaData's EncyclopediaPuzzle.Image.ResourceObject bindings.
        // Its LOCKPICK identifier maps to SaveStats' lowercase lockpick key.
        $encyclopediaIcons = [
            'completeThePattern' => 'Pattern_Grid',
            'followTheShiny' => 'Wandering_Echo',
            'fractalMatch' => 'Fractal_Match',
            'ghostObject' => 'Ghost_Object',
            'gyroRing' => 'Golden_Ring',
            'hiddenArchway' => 'Hidden_Archway',
            'hiddenCube' => 'Hidden_Cube',
            'hiddenRing' => 'Hidden_Ring',
            'klotski' => 'Klotski',
            'lightPattern' => 'Projection_Cone',
            'lockpick' => 'LockPick',
            'logicGrid' => 'Logic_Grid',
            'match3' => 'Match_3',
            'matchbox' => 'Matchvox',
            'memoryGrid' => 'Memory_Grid',
            'mirrorMaze' => 'Mirror_Maze',
            'musicGrid' => 'Music_Grid',
            'racingBallCourse' => 'Racing_Ball',
            'racingRingCourse' => 'Racing_Ring',
            'rollingCube' => 'Rolling_Cube',
            'rosary' => 'Skydrop',
            'ryoanji' => 'Sentinel_Stone',
            'seek5' => 'Seek_5',
            'viewfinder' => 'Viewfinder',
        ];
        $noImage = ['levelRestrictionVolume', 'loreFragment', 'monolithFragment', 'spawnSite'];
        $types = [];
        foreach ($names as $key => $name) {
            $types[$key] = [
                'name' => $name,
                'iconFile' => in_array($key, $noImage, true) ? null : 'ptype_scaled/' . $key . '.png',
                'iconAsset' => sprintf('SSIcon%03d', count($types)),
                'encyclopediaIconPackage' => isset($encyclopediaIcons[$key])
                    ? '/Game/ASophia/UI/Common/Icons/PuzzleIcons/' . $encyclopediaIcons[$key] : null,
                'encyclopediaIconName' => $encyclopediaIcons[$key] ?? null,
            ];
        }
    }
    return $types;
}

/**
 * Selection bit shared by the map and radar. It follows the full catalog order, so a
 * radar built from a subset catalog still agrees with the map about every type.
 */
function OverlayTypeBit(string $key): int
{
    return 1 << OverlayCatalogSlot($key);
}

/** Position in the full catalog; also the index of per-type count arrays. */
function OverlayCatalogSlot(string $key): int
{
    $position = array_search($key, array_keys(GetOverlayTypes()), true);
    if ($position === false || $position > 30) { throw new InvalidArgumentException('Unknown overlay type: ' . $key); }
    return $position;
}
