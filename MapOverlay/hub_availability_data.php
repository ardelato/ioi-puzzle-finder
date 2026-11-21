<?php

declare(strict_types=1);

/** Identify only fixed World Hub records covered by native sandbox group selection. */
function EnrichOverlayHubAvailability(array $overlay, ?object $nativeDatabase = null): array
{
    $world = []; $relevance = [];
    foreach ($nativeDatabase->Exports ?? [] as $export) {
        foreach ($export->Data ?? [] as $field) {
            if (($field->Name ?? '') === 'krakenIDToWorldPuzzleData') {
                foreach ($field->Value as [$key, $value]) {
                    if (!is_int($key->Value ?? null) || !is_string($value->Value ?? null)) { throw new InvalidArgumentException('Invalid native World puzzle identity.'); }
                    if (isset($world[$key->Value])) { throw new InvalidArgumentException('Duplicate native World puzzle identity.'); }
                    $world[$key->Value] = json_decode($value->Value, true, 512, JSON_THROW_ON_ERROR);
                }
            } elseif (($field->Name ?? '') === 'zoneToSandboxProgressionRelevant') {
                foreach ($field->Value as [$key, $value]) { $relevance[$key->Value] = $value->Value; }
            }
        }
    }
    $zoneEnums = [2 => 'EMainMapZoneName::Egypt', 3 => 'EMainMapZoneName::Central', 4 => 'EMainMapZoneName::Riverlands',
        5 => 'EMainMapZoneName::Redwoods', 6 => 'EMainMapZoneName::Geyser'];
    $coverage = ['locatedHubPuzzles' => 0, 'eligibleHubPuzzles' => 0, 'eligibleHubLocations' => 0, 'unknownHubPuzzles' => 0, 'unknown' => []];
    $markers = (array)$overlay['markers'];
    foreach ($markers as $pid => &$marker) {
        $marker['hubAvailabilityZone'] = 0;
        if (($marker['group'] ?? '') !== 'hub') { continue; }
        $coverage['locatedHubPuzzles']++;
        $raw = $world[$pid] ?? null;
        $zone = $marker['zoneIndex'];
        $reason = match (true) {
            $nativeDatabase === null => 'missing_native_source',
            $raw === null => 'not_a_native_world_record',
            !is_int($marker['pid'] ?? null) || $marker['pid'] <= 0 || (int)$pid !== $marker['pid'] || ($raw['KID'] ?? null) !== $marker['pid'] => 'native_identity_mismatch',
            ($raw['SpawnBehaviour'] ?? null) !== 2 => 'not_dynamic_world_spawn',
            !in_array($raw['Disabled'] ?? null, [false, 0], true) => 'native_record_disabled_or_unknown',
            !isset($zoneEnums[$zone]) || ($raw['Zone'] ?? null) !== $zone => 'native_zone_mismatch',
            ($raw['PuzzleType'] ?? null) !== $marker['ptype'] => 'native_type_mismatch',
            ($relevance[$zoneEnums[$zone]] ?? null) !== true => 'native_zone_relevance_unknown_or_false',
            default => null,
        };
        if ($reason === null) {
            $marker['hubAvailabilityZone'] = $zone;
            $coverage['eligibleHubPuzzles']++;
            $coverage['eligibleHubLocations'] += count($marker['locations']);
        } else {
            $coverage['unknownHubPuzzles']++;
            $coverage['unknown'][] = ['pid' => (int)$pid, 'reason' => $reason];
        }
    }
    unset($marker);
    $overlay['markers'] = (object)$markers;
    $overlay['hubAvailabilityCoverage'] = $coverage;
    return $overlay;
}
