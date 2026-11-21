function Assert-HubAvailabilitySnapshot($Snapshot) {
    $hub = @($Snapshot.markers.PSObject.Properties.Value | Where-Object group -CEQ 'hub')
    $eligible = @($hub | Where-Object { $_.hubAvailabilityZone -in @(2,3,4,5,6) })
    $locations = 0
    foreach ($marker in $eligible) { $locations += $marker.locations.Count }
    $coverage = $Snapshot.hubAvailabilityCoverage
    if ($null -eq $coverage -or $coverage.locatedHubPuzzles -ne $hub.Count -or
        $coverage.eligibleHubPuzzles -ne $eligible.Count -or $coverage.eligibleHubLocations -ne $locations -or
        $coverage.unknownHubPuzzles -ne $hub.Count - $eligible.Count -or
        @($coverage.unknown).Count -ne $coverage.unknownHubPuzzles -or
        ($hub.Count -gt 0 -and $eligible.Count -eq 0)) {
        throw 'Hub availability classification is missing or inconsistent; inspect hubAvailabilityCoverage in markers.json.'
    }
    foreach ($marker in $Snapshot.markers.PSObject.Properties.Value) {
        $zone = $marker.hubAvailabilityZone
        if ($null -eq $zone -or $zone -notin @(0,2,3,4,5,6) -or
            ($zone -gt 0 -and ($marker.group -cne 'hub' -or $marker.zoneIndex -ne $zone))) {
            throw "Invalid Hub availability scope for puzzle $($marker.pid)."
        }
    }
    return $coverage
}

function Assert-HubAvailabilityPackage($Snapshot, $RenderPoints, $Index, $Radar, [int]$Limit = 0) {
    $null = Assert-HubAvailabilitySnapshot $Snapshot
    $selected = @($Snapshot.markers.PSObject.Properties.Value | Sort-Object { [long]$_.pid })
    if ($Limit -gt 0) { $selected = @($selected | Select-Object -First $Limit) }
    $expected = @{}
    foreach ($marker in $selected) {
        if ($marker.hubAvailabilityZone -le 0) { continue }
        foreach ($location in $marker.locations) { $expected["$($marker.pid):$($location.index)"] = $marker.hubAvailabilityZone }
    }
    $renderByName = @{}
    $seen = @{}
    foreach ($point in $RenderPoints) {
        if ($renderByName.ContainsKey($point.actorName)) { throw 'Render manifest contains duplicate actor identities.' }
        $zone = [int]$point.hubAvailabilityZone
        $key = "$($point.pid):$($point.pointIndex)"
        $tags = @($point.tags | Where-Object { $_ -clike 'SaveStats.HubZone.*' })
        if ($zone -gt 0) {
            if (-not $expected.ContainsKey($key) -or $seen.ContainsKey($key) -or $expected[$key] -ne $zone -or
                $tags.Count -ne 1 -or $tags[0] -cne "SaveStats.HubZone.$zone") { throw "Render manifest changed Hub availability identity: $key" }
            $seen[$key] = $true
        } elseif ($expected.ContainsKey($key) -or $tags.Count -ne 0) {
            throw "Render manifest lost or invented Hub availability scope: $key"
        }
        $renderByName[$point.actorName] = $point
    }
    if ($seen.Count -ne $expected.Count) { throw 'Selected Hub markers were lost before radar generation.' }
    $defaults = @($Radar.Exports | Where-Object ObjectName -CEQ 'Default__WBP_SSOverlayRadar_C')
    if ($defaults.Count -ne 1) { throw 'Missing native radar defaults for Hub coverage.' }
    $nativeZones = @($defaults[0].Data | Where-Object Name -CEQ 'SSRadarHubAvailabilityZones')
    if ($nativeZones.Count -ne 1 -or $nativeZones[0].Value.Count -ne $Index.positions.Count -or
        $Index.hubAvailabilityZones.Count -ne $Index.positions.Count) { throw 'Hub availability arrays are missing or not parallel.' }
    $indexed = 0
    $indexedNames = @{}
    for ($pointIndex = 0; $pointIndex -lt $Index.positions.Count; $pointIndex++) {
        $name = $Index.pointIds[$pointIndex]
        if (-not $renderByName.ContainsKey($name)) { throw 'Radar index references an unknown Hub marker.' }
        $point = $renderByName[$name]
        $zone = [int]$point.hubAvailabilityZone
        if ($Index.hubAvailabilityZones[$pointIndex] -ne $zone -or $nativeZones[0].Value[$pointIndex].Value -ne $zone -or
            ($zone -gt 0 -and $Index.puzzleIds[$pointIndex] -ne $point.pid)) { throw "Hub scope changed between render/index/native widget: $name" }
        if ($zone -gt 0) {
            if ($indexedNames.ContainsKey($name)) { throw "Radar index duplicated an eligible Hub marker: $name" }
            $indexedNames[$name] = $true
            $indexed++
        }
    }
    if ($indexed -ne $expected.Count) { throw 'Hub availability scope count changed before packaging.' }
    return [ordered]@{selectedEligiblePoints=$expected.Count; indexedEligiblePoints=$indexed; nativeEligiblePoints=$indexed}
}
