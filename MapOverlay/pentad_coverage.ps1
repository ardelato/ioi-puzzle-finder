function Assert-PentadSnapshotCoverage($Snapshot) {
    $pentads = @($Snapshot.markers.PSObject.Properties.Value | Where-Object ptype -CEQ 'seek5')
    $coverage = $Snapshot.pentadProgressCoverage
    if ($null -eq $coverage -or $coverage.locatedPentads -ne $pentads.Count -or
        $coverage.verifiedPentads -ne $pentads.Count -or
        $coverage.locatedRelics -ne 5 * $pentads.Count -or
        $coverage.verifiedRelics -ne 5 * $pentads.Count -or @($coverage.unresolved).Count -ne 0) {
        throw 'Pentad progress requires verified metadata for every catalogued relic. Inspect pentadProgressCoverage in markers.json.'
    }
    foreach ($pentad in $pentads) {
        $relics = @($pentad.locations | Where-Object { $_.index -ge 1 -and $_.index -le 5 -and $_.role -ceq 'object' })
        if ($relics.Count -ne 5 -or @($relics.index | Sort-Object -Unique).Count -ne 5) { throw "Invalid relic identities for Pentad $($pentad.pid)." }
        foreach ($relic in $relics) {
            if ($null -eq $relic.pentadLocalPosition) { throw "Missing progress metadata for Pentad $($pentad.pid), relic $($relic.index)." }
        }
    }
    return $coverage
}

function Assert-PentadPackagedCoverage($Snapshot, $RenderPoints, $Index, $Radar, [int]$Limit = 0) {
    $null = Assert-PentadSnapshotCoverage $Snapshot
    $selected = @($Snapshot.markers.PSObject.Properties.Value | Sort-Object { [long]$_.pid })
    if ($Limit -gt 0) { $selected = @($selected | Select-Object -First $Limit) }
    $expected = @{}
    foreach ($pentad in @($selected | Where-Object ptype -CEQ 'seek5')) {
        foreach ($relic in @($pentad.locations | Where-Object { $_.index -ge 1 -and $_.index -le 5 -and $_.role -ceq 'object' })) {
            $expected["$($pentad.pid):$($relic.index)"] = $relic.pentadLocalPosition
        }
    }
    $relics = @($RenderPoints | Where-Object { $_.ptype -ceq 'seek5' -and $_.role -ceq 'object' -and $_.pointIndex -ge 1 -and $_.pointIndex -le 5 })
    if ($relics.Count -ne $expected.Count) { throw 'Selected Pentad relics were lost from the render manifest.' }
    $renderByName = @{}
    $seen = @{}
    foreach ($relic in $relics) {
        $key = "$($relic.pid):$($relic.pointIndex)"
        if (-not $expected.ContainsKey($key) -or $seen.ContainsKey($key) -or $null -eq $relic.pentadLocalPosition) { throw "Render manifest lost relic identity: $($relic.actorName)" }
        foreach ($axis in @('x','y','z')) {
            if ($null -eq $relic.pentadLocalPosition.$axis -or [single]$relic.pentadLocalPosition.$axis -ne [single]$expected[$key].$axis) { throw "Render manifest changed relic coordinates: $key" }
        }
        $seen[$key] = $true
        $renderByName[$relic.actorName] = $relic
    }
    $indexedRelics = 0
    $indexedKnown = 0
    $indexedNames = @{}
    for ($point = 0; $point -lt $Index.pentadRelicIndices.Count; $point++) {
        if ($Index.pentadRelicIndices[$point] -le 0) { continue }
        $indexedRelics++
        $name = $Index.pointIds[$point]
        if (-not $renderByName.ContainsKey($name) -or $indexedNames.ContainsKey($name)) { throw 'Radar index changed relic identity.' }
        $relic = $renderByName[$name]
        if ($Index.puzzleIds[$point] -ne $relic.pid -or $Index.pentadRelicIndices[$point] -ne $relic.pointIndex -or $null -eq $Index.pentadLocalPositions[$point]) { throw "Radar index lost relic metadata: $name" }
        foreach ($axis in @('x','y','z')) {
            if ($null -eq $Index.pentadLocalPositions[$point].$axis -or [single]$Index.pentadLocalPositions[$point].$axis -ne [single]$relic.pentadLocalPosition.$axis) { throw "Radar index changed relic coordinates: $name" }
        }
        $indexedKnown++
        $indexedNames[$name] = $true
    }
    $defaults = @($Radar.Exports | Where-Object ObjectName -CEQ 'Default__WBP_SSOverlayRadar_C')
    if ($defaults.Count -ne 1) { throw 'Missing native radar defaults for progress coverage check.' }
    $known = @($defaults[0].Data | Where-Object Name -CEQ 'SSRadarPentadLocalKnown')
    if ($known.Count -ne 1) { throw 'Missing native relic progress validity flags.' }
    $nativeKnown = @($known[0].Value | Where-Object Value -EQ 1).Count
    if ($indexedRelics -ne $relics.Count -or $indexedKnown -ne $relics.Count -or
        $known[0].Value.Count -ne $relics.Count -or $nativeKnown -ne $relics.Count) {
        throw "Pentad progress metadata was lost before packaging: render=$($relics.Count), index=$indexedKnown/$indexedRelics, native=$nativeKnown/$($known[0].Value.Count)."
    }
    $data = @{}
    foreach ($property in $defaults[0].Data) { $data[$property.Name] = $property }
    foreach ($arrayName in @('SSRadarPentadIds','SSRadarPentadLocalX','SSRadarPentadLocalY','SSRadarPentadLocalZ')) {
        if (-not $data.ContainsKey($arrayName)) { throw "Missing native progress array: $arrayName" }
    }
    $expectedIds = @($selected | Where-Object ptype -CEQ 'seek5' | ForEach-Object pid | Sort-Object { [long]$_ })
    $nativeIds = @($data['SSRadarPentadIds'].Value)
    if ($nativeIds.Count -ne $expectedIds.Count) { throw 'Native Pentad catalog lost puzzle identities.' }
    foreach ($axis in @('X','Y','Z')) {
        if ($data['SSRadarPentadLocal' + $axis].Value.Count -ne $expected.Count) { throw 'Native relic coordinate array length changed.' }
    }
    for ($slot = 0; $slot -lt $expectedIds.Count; $slot++) {
        if ($nativeIds[$slot].Value -ne $expectedIds[$slot]) { throw 'Native Pentad catalog reordered puzzle identities.' }
        foreach ($relicIndex in 1..5) {
            $compactPoint = 5 * $slot + $relicIndex - 1
            $key = "$($expectedIds[$slot]):$relicIndex"
            foreach ($axis in @('X','Y','Z')) {
                if ([single]$data['SSRadarPentadLocal' + $axis].Value[$compactPoint].Value -ne [single]$expected[$key].$axis) { throw "Native relic coordinates changed: $key" }
            }
        }
    }
    return [ordered]@{renderRelics=$relics.Count; indexedRelics=$indexedRelics; indexedKnown=$indexedKnown; nativeKnown=$nativeKnown}
}
