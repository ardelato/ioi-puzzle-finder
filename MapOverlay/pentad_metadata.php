<?php

declare(strict_types=1);

const PENTAD_METADATA_TOLERANCE_CM = 0.05;

/** Decode the installed native database, rather than infer identity from CSV order. */
function PentadNativeRecords(object $database): array
{
    $records = []; $found = false;
    foreach ($database->Exports ?? [] as $export) {
        foreach ($export->Data ?? [] as $field) {
            if (($field->Name ?? '') !== 'krakenIDToWorldPuzzleData') { continue; }
            if ($found || !is_array($field->Value ?? null)) { throw new InvalidArgumentException('Expected one native world-puzzle map.'); }
            $found = true;
            foreach ($field->Value as $pair) {
                if (!is_array($pair) || count($pair) !== 2 || !is_int($pair[0]->Value ?? null) || !is_string($pair[1]->Value ?? null)) {
                    throw new InvalidArgumentException('Invalid native world-puzzle map entry.');
                }
                $raw = json_decode($pair[1]->Value, true, 512, JSON_THROW_ON_ERROR);
                if (($raw['PuzzleType'] ?? '') !== 'seek5') { continue; }
                $pid = $pair[0]->Value;
                if ($pid <= 0 || $pid > 2147483647 || ($raw['KID'] ?? null) !== $pid || isset($records[$pid])) {
                    throw new InvalidArgumentException('Native Pentad identity must have one matching positive puzzle ID.');
                }
                $records[$pid] = $raw;
            }
        }
    }
    if (!$found) { throw new InvalidArgumentException('Native database has no world-puzzle map.'); }
    return $records;
}

function PentadTransform(mixed $raw): ?array
{
    if (!is_string($raw)) { return null; }
    $parts = explode('|', $raw);
    if (count($parts) !== 3) { return null; }
    $result = [];
    foreach ($parts as $part) {
        $axes = explode(',', $part);
        if (count($axes) !== 3) { return null; }
        foreach ($axes as $axis) {
            if (!is_numeric($axis) || !is_finite((float)$axis) || abs((float)$axis) > 3.402823466e38) { return null; }
        }
        $result[] = array_map('floatval', $axes);
    }
    return $result;
}

/** UE FTransform origin composition; legacy reproduces SaveStats' catalog encoding. */
function PentadProjectedPosition(array $parent, array $local, bool $legacy = false): array
{
    [$translation, $rotation, $scale] = $parent;
    [$pitch, $yaw, $roll] = array_map('deg2rad', $rotation);
    [$sp, $cp, $sy, $cy, $sr, $cr] = [sin($pitch), cos($pitch), sin($yaw), cos($yaw), sin($roll), cos($roll)];
    if ($legacy) {
        [$x, $y, $z] = $local;
        [$x, $z] = [$x * $cp + $z * $sp, -$x * $sp + $z * $cp];
        [$x, $y] = [$x * $cy - $y * $sy, $x * $sy + $y * $cy];
        [$y, $z] = [$y * $cr - $z * $sr, $y * $sr + $z * $cr];
        return [$translation[0] + $x, $translation[1] + $y, $translation[2] + $z];
    }
    [$x, $y, $z] = [$local[0] * $scale[0], $local[1] * $scale[1], $local[2] * $scale[2]];
    return [$translation[0] + $cp * $cy * $x + ($sr * $sp * $cy - $cr * $sy) * $y - ($cr * $sp * $cy + $sr * $sy) * $z,
        $translation[1] + $cp * $sy * $x + ($sr * $sp * $sy + $cr * $cy) * $y + ($cy * $sr - $cr * $sp * $sy) * $z,
        $translation[2] + $sp * $x - $sr * $cp * $y + $cr * $cp * $z];
}

/** Require a complete unique bijection; a nearby or ordinal guess is insufficient. */
function PentadSpatialMapping(array $locations, array $projected, float $toleranceCm = PENTAD_METADATA_TOLERANCE_CM): ?array
{
    $mapping = [];
    foreach ($locations as $location) {
        $matches = [];
        foreach ($projected as $index => $position) {
            $squared = ($location['x'] - $position[0]) ** 2 + ($location['y'] - $position[1]) ** 2 + ($location['z'] - $position[2]) ** 2;
            if (is_finite($squared) && $squared <= $toleranceCm ** 2) { $matches[] = $index; }
        }
        if (count($matches) !== 1 || in_array($matches[0], $mapping, true)) { return null; }
        $mapping[] = $matches[0];
    }
    return count($mapping) === 5 ? $mapping : null;
}

/** Add verified local identity to real CSV-based markers without relocating any pin. */
function EnrichOverlayPentadMetadata(array $overlay, ?object $nativeDatabase = null): array
{
    $records = $nativeDatabase === null ? [] : PentadNativeRecords($nativeDatabase);
    $markers = (array)$overlay['markers'];
    $coverage = ['locatedPentads' => 0, 'locatedRelics' => 0, 'verifiedPentads' => 0, 'verifiedRelics' => 0,
        'unresolved' => [], 'legacyProjectionIds' => [], 'toleranceCm' => PENTAD_METADATA_TOLERANCE_CM];
    foreach ($markers as $pid => &$marker) {
        if (($marker['ptype'] ?? '') !== 'seek5') { continue; }
        $coverage['locatedPentads']++;
        $locations = [];
        foreach ($marker['locations'] as &$location) {
            unset($location['pentadLocalPosition']);
            if ($location['role'] === 'object' && $location['index'] >= 1 && $location['index'] <= 5) { $locations[$location['index']] = $location; }
        }
        unset($location);
        ksort($locations, SORT_NUMERIC);
        $coverage['locatedRelics'] += count($locations);
        $raw = $records[$pid] ?? null;
        $reason = $nativeDatabase === null ? 'missing_native_source' : ($raw === null ? 'missing_native_record' : null);
        if ($reason === null && (array_keys($locations) !== range(1, 5) || count($raw['DuplicatedObjectOfType-Seek5HiddenObject'] ?? []) !== 5)) { $reason = 'incomplete_relic_geometry'; }
        $parent = $reason === null ? PentadTransform($raw['ActorTransform'] ?? null) : null;
        $transforms = $reason === null ? array_map('PentadTransform', array_values($raw['DuplicatedObjectOfType-Seek5HiddenObject'])) : [];
        if ($reason === null && ($parent === null || in_array(null, $transforms, true))) { $reason = 'invalid_native_transform'; }
        if ($reason === null) {
            $locals = array_map(static fn(array $transform): array => $transform[0], $transforms);
            $native = PentadSpatialMapping($locations, array_map(static fn(array $local): array => PentadProjectedPosition($parent, $local), $locals));
            $legacy = PentadSpatialMapping($locations, array_map(static fn(array $local): array => PentadProjectedPosition($parent, $local, true), $locals));
            if ($native !== null && $legacy !== null && $native !== $legacy) { $reason = 'conflicting_projection_identity'; }
            elseif ($native === null && $legacy === null) { $reason = 'no_unique_spatial_correspondence'; }
            else {
                $mapping = $native ?? $legacy;
                foreach ($marker['locations'] as &$location) {
                    if (!isset($locations[$location['index']])) { continue; }
                    $local = $locals[$mapping[$location['index'] - 1]];
                    $location['pentadLocalPosition'] = array_combine(['x', 'y', 'z'], $local);
                }
                unset($location);
                $coverage['verifiedPentads']++; $coverage['verifiedRelics'] += 5;
                if ($native === null) { $coverage['legacyProjectionIds'][] = (int)$pid; }
            }
        }
        if ($reason !== null) { $coverage['unresolved'][] = ['pid' => (int)$pid, 'reason' => $reason]; }
    }
    unset($marker);
    sort($coverage['legacyProjectionIds'], SORT_NUMERIC);
    $overlay['markers'] = (object)$markers;
    $overlay['pentadProgressCoverage'] = $coverage;
    return $overlay;
}
