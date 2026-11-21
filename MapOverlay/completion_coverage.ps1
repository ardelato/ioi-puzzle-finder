function Assert-SpecialCompletionSnapshot($Snapshot) {
    $lore = @($Snapshot.markers.PSObject.Properties.Value | Where-Object ptype -CEQ 'loreFragment')
    $fragments = @($Snapshot.markers.PSObject.Properties.Value | Where-Object ptype -CEQ 'monolithFragment')
    $coverage = $Snapshot.specialCompletionCoverage
    if ($null -eq $coverage -or $coverage.lorePuzzles -ne $lore.Count -or
        $coverage.monolithFragments -ne $fragments.Count -or $coverage.total -ne $lore.Count + $fragments.Count) {
        throw 'Special completion coverage is absent or inconsistent.'
    }
    foreach ($marker in @($lore) + @($fragments)) {
        $descriptor = $marker.completion
        if ($marker.group -cne 'static' -or $null -eq $descriptor) { throw 'Special completion identity is missing.' }
        if ($marker.ptype -ceq 'loreFragment') {
            if ($descriptor.kind -cne 'lore' -or [string]::IsNullOrWhiteSpace($descriptor.key) -or $descriptor.key.Contains(',')) {
                throw "Invalid lore completion key for $($marker.pid)."
            }
        } elseif ($descriptor.kind -cne 'monolith' -or $descriptor.parentPid -le 0 -or
            $descriptor.fragmentIndex -lt 0 -or $descriptor.fragmentIndex -ge 100 -or
            [long]$marker.pid -ne 42000 + 100 * [long]$marker.zoneIndex + [long]$descriptor.fragmentIndex) {
            throw "Invalid monolith completion identity for $($marker.pid)."
        }
    }
    return $coverage
}

function Assert-SpecialCompletionPackage($Snapshot, $RenderPoints, $Index, $Radar, [int]$Limit = 0) {
    $null = Assert-SpecialCompletionSnapshot $Snapshot
    $selected = @($Snapshot.markers.PSObject.Properties.Value | Sort-Object { [long]$_.pid })
    if ($Limit -gt 0) { $selected = @($selected | Select-Object -First $Limit) }
    $expected = @{}
    foreach ($marker in $selected) { if ($null -ne $marker.completion) { $expected[[long]$marker.pid] = $marker.completion } }
    $rendered = @{}
    foreach ($point in $RenderPoints) {
        if ($point.group -ceq 'spawn' -and $null -ne $point.completion) { throw 'Possible sites must not carry completion identities.' }
        if ($null -eq $point.completion) {
            if ($point.ptype -cin @('loreFragment','monolithFragment')) { throw 'Special identity was lost from rendered points.' }
            continue
        }
        $puzzleId = [long]$point.pid
        if (-not $expected.ContainsKey($puzzleId)) { throw 'Unexpected special render identity.' }
        $a = $point.completion; $b = $expected[$puzzleId]
        if ($a.kind -cne $b.kind -or $a.key -cne $b.key -or $a.parentPid -ne $b.parentPid -or $a.fragmentIndex -ne $b.fragmentIndex) {
            throw "Special render metadata changed for $puzzleId."
        }
        $rendered[$puzzleId] = $true
    }
    if ($rendered.Count -ne $expected.Count -or @($Index.specialCompletions).Count -ne $expected.Count) {
        throw 'Special completion identities were lost during expansion/indexing.'
    }
    $indexed = @{}
    $previous = 0L
    foreach ($entry in @($Index.specialCompletions)) {
        $puzzleId = [long]$entry.pid
        if ($puzzleId -le $previous -or -not $expected.ContainsKey($puzzleId)) { throw 'Special index IDs must be unique, expected and sorted.' }
        $previous = $puzzleId
        $a = $entry; $b = $expected[$puzzleId]
        if ($a.kind -cne $b.kind -or $a.key -cne $b.key -or $a.parentPid -ne $b.parentPid -or $a.fragmentIndex -ne $b.fragmentIndex) {
            throw "Special index metadata changed for $puzzleId."
        }
        $indexed[$puzzleId] = $true
    }
    $defaults = @($Radar.Exports | Where-Object ObjectName -CEQ 'Default__WBP_SSOverlayRadar_C')
    if ($defaults.Count -ne 1) { throw 'Missing native radar defaults for special completion.' }
    $arrays = @{}
    foreach ($name in @('SSRadarSpecialIds','SSRadarSpecialKinds','SSRadarSpecialKeys','SSRadarSpecialParentIds','SSRadarSpecialFragmentIndices')) {
        $field = @($defaults[0].Data | Where-Object Name -CEQ $name)
        if ($field.Count -ne 1 -or @($field[0].Value).Count -ne $expected.Count) { throw "Missing or mismatched native array $name." }
        $arrays[$name] = @($field[0].Value | ForEach-Object Value)
    }
    for ($position = 0; $position -lt $expected.Count; $position++) {
        $entry = @($Index.specialCompletions)[$position]
        $lore = $entry.kind -ceq 'lore'
        $kind = if ($lore) { 1 } else { 2 }
        $key = if ($lore) { [string]$entry.key } else { '' }
        $parent = if ($lore) { 0 } else { [long]$entry.parentPid }
        $fragment = if ($lore) { -1 } else { [long]$entry.fragmentIndex }
        if ([long]$arrays.SSRadarSpecialIds[$position] -ne [long]$entry.pid -or
            [int]$arrays.SSRadarSpecialKinds[$position] -ne $kind -or [string]$arrays.SSRadarSpecialKeys[$position] -cne $key -or
            [long]$arrays.SSRadarSpecialParentIds[$position] -ne $parent -or [int]$arrays.SSRadarSpecialFragmentIndices[$position] -ne $fragment) {
            throw "Native special completion metadata changed at index $position."
        }
    }
    return @{ specialPuzzles = $expected.Count; lorePuzzles = @($Index.specialCompletions | Where-Object kind -CEQ 'lore').Count; monolithFragments = @($Index.specialCompletions | Where-Object kind -CEQ 'monolith').Count }
}
