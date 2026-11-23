<?php

declare(strict_types=1);

/**
 * File-based solves (Skydrops, Mystery matchboxes) are saved as text keys shaped like
 * "<type>--<author>--<id>--<time>--<Class> <ObjectPath>". GISProgression.GetPuzzleSolveStatus
 * accepts the full key as its hackID (verified in game).
 */
function IsSaveKey(mixed $key): bool
{
    return is_string($key) && strlen($key) <= 512 && preg_match('~^[\x20-\x7E]+--[A-Za-z][A-Za-z0-9_]* /Game/[\x21-\x7E]+$~', $key) === 1;
}

/** Validate catalog identity, independently of any player completion state. */
function ValidateOverlayCompletion(mixed $raw, int $pid, string $ptype, string $group): array
{
    if ((!is_array($raw) && !is_object($raw)) || $pid <= 0 || $pid > 2147483647 || $group !== 'static') {
        throw new InvalidArgumentException('Special completion requires a descriptor and an exact static puzzle ID.');
    }
    $raw = (array)$raw;
    $keys = array_keys($raw); sort($keys, SORT_STRING);
    if (($raw['kind'] ?? null) === 'lore' && $ptype === 'loreFragment' && $keys === ['key', 'kind']
        && is_string($raw['key']) && trim($raw['key']) !== '' && !str_contains($raw['key'], ',')) {
        return ['kind' => 'lore', 'key' => $raw['key']];
    }
    if (($raw['kind'] ?? null) === 'monolith' && $ptype === 'monolithFragment' && $keys === ['fragmentIndex', 'kind', 'parentPid']
        && is_int($raw['parentPid']) && $raw['parentPid'] > 0 && $raw['parentPid'] <= 2147483647 && $raw['parentPid'] !== $pid
        && is_int($raw['fragmentIndex']) && $raw['fragmentIndex'] >= 0 && $raw['fragmentIndex'] < 100
        && $pid >= 42200 && $pid < 42700 && ($pid - 42000) % 100 === $raw['fragmentIndex']) {
        return ['kind' => 'monolith', 'parentPid' => $raw['parentPid'], 'fragmentIndex' => $raw['fragmentIndex']];
    }
    if (($raw['kind'] ?? null) === 'saveKey' && in_array($ptype, ['rosary', 'matchbox'], true) && $keys === ['key', 'kind'] && IsSaveKey($raw['key'])) {
        return ['kind' => 'saveKey', 'key' => $raw['key']];
    }
    throw new InvalidArgumentException('Invalid special completion descriptor for puzzle ' . $pid . '.');
}

/** Bind special IDs only to source CSV identities; never load a player save. */
function EnrichOverlayCompletionMetadata(array $overlay, array $puzzleMap): array
{
    $metadata = [];
    foreach ($puzzleMap as $entry) { $metadata[(int)$entry->pid] = $entry; }
    $coverage = ['lorePuzzles' => 0, 'monolithFragments' => 0, 'saveKeyPuzzles' => 0, 'total' => 0];
    $markers = (array)$overlay['markers'];
    foreach ($markers as $pid => &$marker) {
        // Skydrops and Mystery matchboxes solved via a save-text key have no native puzzle status by ID.
        $saveName = $metadata[$pid]->saveName ?? null;
        if (in_array($marker['ptype'] ?? '', ['rosary', 'matchbox'], true) && is_string($saveName)
            && in_array($metadata[$pid]->family ?? null, ['static', 'mystery'], true)) {
            $marker['completion'] = ValidateOverlayCompletion(['kind' => 'saveKey', 'key' => $saveName], $marker['pid'], $marker['ptype'], $marker['group']);
            $coverage['saveKeyPuzzles']++;
            $coverage['total']++;
            continue;
        }
        if (!in_array($marker['ptype'] ?? '', ['loreFragment', 'monolithFragment'], true)) { continue; }
        $entry = $metadata[$pid] ?? null;
        if ($entry === null || ($entry->ptype ?? null) !== $marker['ptype'] || ($entry->family ?? null) !== 'static') {
            throw new RuntimeException('Special completion is missing matching static metadata for puzzle ' . $pid . '.');
        }
        if ($marker['ptype'] === 'loreFragment') {
            if (!is_string($entry->saveName ?? null)) { throw new RuntimeException('Lore completion metadata requires a saveName for puzzle ' . $pid . '.'); }
            // UnlockTitleToPid uses this same comma normalization in SaveStats.
            $descriptor = ['kind' => 'lore', 'key' => str_replace(',', '', $entry->saveName)];
            $coverage['lorePuzzles']++;
        } else {
            $parentPid = filter_var($entry->parent ?? null, FILTER_VALIDATE_INT);
            $parent = $parentPid === false ? null : ($metadata[$parentPid] ?? null);
            $zone = (int)($entry->zoneIndex ?? -1);
            $fragmentIndex = (int)$pid - 42000 - $zone * 100;
            if ($parent === null || ($parent->ptype ?? '') !== 'obelisk' || (int)($parent->zoneIndex ?? -1) !== $zone || !in_array($zone, [2, 3, 4, 5, 6], true)) {
                throw new RuntimeException('Monolith fragment metadata requires its matching parent obelisk and zone for puzzle ' . $pid . '.');
            }
            $descriptor = ['kind' => 'monolith', 'parentPid' => $parentPid, 'fragmentIndex' => $fragmentIndex];
            $coverage['monolithFragments']++;
        }
        $marker['completion'] = ValidateOverlayCompletion($descriptor, $marker['pid'], $marker['ptype'], $marker['group']);
        $coverage['total']++;
    }
    unset($marker);
    $overlay['markers'] = (object)$markers;
    $overlay['specialCompletionCoverage'] = $coverage;
    return $overlay;
}
