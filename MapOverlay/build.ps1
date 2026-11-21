#requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$SaveStatsDir,
    [ValidateSet('Universal', 'Snapshot')][string]$CatalogMode = 'Universal',
    [string]$SavePath = "$env:LOCALAPPDATA\IslandsofInsight\Saved\SaveGames\OfflineSavegame.sav",
    [Parameter(Mandatory = $true)][string]$GameDir,
    [Parameter(Mandatory = $true)][string]$AssetInputDir,
    [string]$PhpPath = 'php',
    [string]$UAssetGuiPath = 'UAssetGUI',
    [string]$UnrealPakPath = 'UnrealPak',
    [string]$OutputDir = "$(Split-Path $PSScriptRoot -Parent)\output",
    [ValidateRange(0, 100000)][int]$Limit = 0
)

$ErrorActionPreference = 'Stop'
$catalogModeArgument = $CatalogMode.ToLowerInvariant()
if ($catalogModeArgument -eq 'universal' -and $PSBoundParameters.ContainsKey('SavePath')) {
    throw '-SavePath is only supported with -CatalogMode Snapshot. Universal builds do not read player saves.'
}
. (Join-Path $PSScriptRoot 'pentad_coverage.ps1')
. (Join-Path $PSScriptRoot 'availability_coverage.ps1')
. (Join-Path $PSScriptRoot 'completion_coverage.ps1')
$repoDir = Split-Path $PSScriptRoot -Parent
function Resolve-BuildTool([string]$Path) {
    $command = Get-Command -Name $Path -CommandType Application -ErrorAction Stop
    return $command.Source
}
$php = Resolve-BuildTool $PhpPath
$assetGui = Resolve-BuildTool $UAssetGuiPath
$unrealPak = Resolve-BuildTool $UnrealPakPath
$GameDir = (Resolve-Path -LiteralPath $GameDir).Path
$AssetInputDir = (Resolve-Path -LiteralPath $AssetInputDir).Path
$installedPak = Join-Path $GameDir 'IslandsofInsight\Content\Paks\RRMOD_PuzzleFix.pak'
$version = (Get-Content -Raw -LiteralPath (Join-Path $repoDir 'VERSION')).Trim()
$compatibility = Get-Content -Raw -LiteralPath (Join-Path $repoDir 'compatibility.json') | ConvertFrom-Json
if ((Get-FileHash -LiteralPath $installedPak -Algorithm SHA256).Hash -ine $compatibility.offlineRestoredPakSha256) {
    throw 'Unsupported Offline Restored Mod package. Use the tested release identified in compatibility.json.'
}
$script:OverlayToolLogIndex = 0

function Invoke-CheckedTool([string]$Program, [string[]]$ToolArguments) {
    $startInfo = [System.Diagnostics.ProcessStartInfo]::new($Program)
    $startInfo.UseShellExecute = $false
    $startInfo.WorkingDirectory = $runDir
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    foreach ($argument in $ToolArguments) { $startInfo.ArgumentList.Add($argument) }
    $process = [System.Diagnostics.Process]::Start($startInfo)
    try {
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $output = $stdout.GetAwaiter().GetResult() + $stderr.GetAwaiter().GetResult()
        $script:OverlayToolLogIndex++
        $logPath = Join-Path $runDir ('tool-{0:D2}.log' -f $script:OverlayToolLogIndex)
        [System.IO.File]::WriteAllText($logPath, $output)
        if ($process.ExitCode -ne 0) {
            throw "Tool failed with exit code $($process.ExitCode): $Program`n$output`nLog: $logPath"
        }
    } finally { $process.Dispose() }
}

function Assert-OverlayNativePayload($Export, $Asset, [System.IO.BinaryReader]$Reader, [long]$HeaderSize, [string[]]$AdditionalValueTypes = @(), [bool]$ClassDefaultObject = $false) {
    # These generated Actor/SceneComponent/MarkerEmitter instances have no native
    # tail in this game's cooked packages. UAssetAPI preserves unknown trailing
    # bytes as Extras, so a successful roundtrip alone cannot establish this.
    if ($Export.Extras -isnot [string] -or $Export.Extras.Length -ne 0) {
        throw "Generated export $($Export.ObjectName) contains an unexpected native payload."
    }
    $start = [long]$Export.SerialOffset - $HeaderSize
    $end = $start + [long]$Export.SerialSize
    if ($start -lt 0 -or $end -gt $Reader.BaseStream.Length) { throw 'Export is outside the generated .uexp file.' }
    $Reader.BaseStream.Position = $start
    while ($true) {
        if ($Reader.BaseStream.Position + 8 -gt $end) { throw 'Export has no complete property terminator.' }
        $nameIndex = $Reader.ReadInt32()
        $nullNumber = $Reader.ReadInt32()
        if ($nameIndex -lt 0 -or $nameIndex -ge $Asset.NameMap.Count) { throw 'Invalid serialized property name.' }
        if ($Asset.NameMap[$nameIndex] -ceq 'None') { break }
        if ($Reader.BaseStream.Position + 16 -gt $end) { throw 'Incomplete property tag.' }
        $typeIndex = $Reader.ReadInt32()
        $nullNumber = $Reader.ReadInt32()
        $size = $Reader.ReadInt32()
        $arrayIndex = $Reader.ReadInt32()
        if ($typeIndex -lt 0 -or $typeIndex -ge $Asset.NameMap.Count -or $size -lt 0) { throw 'Invalid property tag.' }
        switch -CaseSensitive ($Asset.NameMap[$typeIndex]) {
            'StructProperty' { $Reader.BaseStream.Position += 24 }
            'ArrayProperty' { $Reader.BaseStream.Position += 8 }
            'EnumProperty' { $Reader.BaseStream.Position += 8 }
            'BoolProperty' { $Reader.BaseStream.Position += 1 }
            'ObjectProperty' { }
            default {
                # The original world guard supplies no additional types. New
                # UMG assets also contain these fixed-header value properties.
                if ($AdditionalValueTypes -cnotcontains $Asset.NameMap[$typeIndex]) {
                    throw "Unexpected generated property type: $($Asset.NameMap[$typeIndex])"
                }
            }
        }
        if ($Reader.BaseStream.Position + 1 -gt $end) { throw 'Incomplete property GUID flag.' }
        $hasPropertyGuid = $Reader.ReadByte()
        if ($hasPropertyGuid -eq 1) { $Reader.BaseStream.Position += 16 }
        elseif ($hasPropertyGuid -ne 0) { throw 'Invalid property GUID flag.' }
        if ($Reader.BaseStream.Position + $size -gt $end) { throw 'Property payload exceeds export size.' }
        $Reader.BaseStream.Position += $size
    }
    if ($ClassDefaultObject) {
        # UE4 CDO exports omit the ordinary object's GUID flag entirely.
        if ($Reader.BaseStream.Position -ne $end) { throw "CDO $($Export.ObjectName) has unexpected trailing bytes." }
        return
    }
    if ($Reader.BaseStream.Position + 4 -gt $end) { throw "Export $($Export.ObjectName) has no object GUID flag." }
    $hasObjectGuid = $Reader.ReadInt32()
    if ($hasObjectGuid -eq 1) { $Reader.BaseStream.Position += 16 }
    elseif ($hasObjectGuid -ne 0) { throw 'Invalid object GUID flag.' }
    if ($Reader.BaseStream.Position -ne $end) {
        throw "Generated export $($Export.ObjectName) has $($end - $Reader.BaseStream.Position) unexpected trailing bytes."
    }
}

function ConvertTo-RadarCanonicalBytecode($Value) {
    if ($null -eq $Value) { return $null }
    if ($Value -is [System.Management.Automation.PSCustomObject]) {
        $type = $Value.'$type'
        [string[]]$names = @($Value.PSObject.Properties.Name)
        # These are serialization defaults, not omitted instructions. UAssetAPI
        # adds Context.PropertyType=0 and writes FloatConst as IEEE float32.
        if ($type -ceq 'UAssetAPI.Kismet.Bytecode.Expressions.EX_Context, UAssetAPI' -and $names -cnotcontains 'PropertyType') {
            $names += 'PropertyType'
        }
        [Array]::Sort($names, [StringComparer]::Ordinal)
        $canonical = [ordered]@{}
        foreach ($name in $names) {
            $child = $Value.$name
            if ($name -ceq 'PropertyType' -and $null -eq $child -and $type -ceq 'UAssetAPI.Kismet.Bytecode.Expressions.EX_Context, UAssetAPI') { $child = 0 }
            if ($name -ceq 'Value' -and $type -ceq 'UAssetAPI.Kismet.Bytecode.Expressions.EX_FloatConst, UAssetAPI') { $child = [double][single]$child }
            $canonical[$name] = ConvertTo-RadarCanonicalBytecode $child
        }
        return [PSCustomObject]$canonical
    }
    if ($Value -is [System.Collections.IList]) {
        $items = @()
        foreach ($item in $Value) { $items += ,(ConvertTo-RadarCanonicalBytecode $item) }
        return ,$items
    }
    return $Value
}

function Assert-RadarWidgetRoundtrip($Before, $After, [string]$AssetName, [string]$NativePath, [int]$FirstNewNormalExport = 0) {
    foreach ($function in @($Before.Exports | Where-Object { $_.'$type' -like '*FunctionExport,*' })) {
        $converted = @($After.Exports | Where-Object ObjectName -CEQ $function.ObjectName)
        if ($converted.Count -ne 1 -or $converted[0].OuterIndex -ne $function.OuterIndex -or
            (ConvertTo-RadarCanonicalBytecode $function.ScriptBytecode | ConvertTo-Json -Depth 100 -Compress) -cne
                (ConvertTo-RadarCanonicalBytecode $converted[0].ScriptBytecode | ConvertTo-Json -Depth 100 -Compress)) {
            throw "$AssetName function changed during conversion: $($function.ObjectName)"
        }
        if ($converted[0].OuterIndex -le 0 -or $converted[0].OuterIndex -gt $After.Exports.Count -or
            $After.Exports[$converted[0].OuterIndex - 1].'$type' -notlike '*ClassExport,*') {
            throw "$AssetName function has an invalid class owner: $($function.ObjectName)"
        }
    }
    $reader = [System.IO.BinaryReader]::new([System.IO.File]::OpenRead([System.IO.Path]::ChangeExtension($NativePath, '.uexp')))
    try {
        $headerSize = (Get-Item -LiteralPath $NativePath).Length
        for ($i = $FirstNewNormalExport; $i -lt $After.Exports.Count; $i++) {
            $export = $After.Exports[$i]
            if ($export.'$type' -notlike '*NormalExport,*') { continue }
            if ($export.OuterIndex -gt $After.Exports.Count) { throw "$AssetName widget has an invalid owner." }
            $isCdo = $export.ObjectFlags -cmatch '(^|,\s*)RF_ClassDefaultObject(,|$)'
            Assert-OverlayNativePayload $export $After $reader $headerSize @('IntProperty', 'FloatProperty', 'NameProperty', 'TextProperty') $isCdo
        }
    } finally { $reader.Dispose() }
}

$hudBaselineJson = Join-Path $AssetInputDir 'WBP_GameHUD.json'
$iconTemplate = Join-Path $AssetInputDir 'MapMarker'
$requiredFiles = @($php, $assetGui, $unrealPak, $installedPak, $hudBaselineJson, "$iconTemplate.uasset", "$iconTemplate.uexp")
if ($catalogModeArgument -eq 'snapshot') { $requiredFiles += $SavePath }
foreach ($required in $requiredFiles) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "Missing file: $required" }
}
$SaveStatsDir = (Resolve-Path -LiteralPath $SaveStatsDir).Path
if ($catalogModeArgument -eq 'snapshot') { $SavePath = (Resolve-Path -LiteralPath $SavePath).Path }
$OutputDir = [System.IO.Path]::GetFullPath($OutputDir)
foreach ($inputDirectory in @($GameDir, $AssetInputDir, $SaveStatsDir)) {
    $prefix = [System.IO.Path]::TrimEndingDirectorySeparator([System.IO.Path]::GetFullPath($inputDirectory)) + [System.IO.Path]::DirectorySeparatorChar
    if ($OutputDir.Equals($prefix.TrimEnd([System.IO.Path]::DirectorySeparatorChar), [StringComparison]::OrdinalIgnoreCase) -or
        $OutputDir.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Build output must be outside game and dependency input directories.'
    }
}
foreach ($inputAsset in $compatibility.assetInputs.PSObject.Properties) {
    $inputPath = Join-Path $AssetInputDir $inputAsset.Name
    if ((Get-FileHash -LiteralPath $inputPath -Algorithm SHA256).Hash -ine $inputAsset.Value) {
        throw "Unsupported build asset: $($inputAsset.Name). Run scripts/prepare-inputs.ps1 with the tested source checkout."
    }
}
$protectedInputs = @($installedPak, $hudBaselineJson, "$iconTemplate.uasset", "$iconTemplate.uexp") | ForEach-Object {
    [PSCustomObject]@{ path = $_; sha256 = (Get-FileHash -LiteralPath $_ -Algorithm SHA256).Hash }
}
# A fresh run directory avoids packaging stale files after a failed build.
$runDir = Join-Path $OutputDir ('build-' + [Guid]::NewGuid().ToString('N'))
$baselineDir = Join-Path $runDir 'baseline'
$stagedContent = Join-Path $runDir 'stage\IslandsofInsight\Content'
$stagedDir = Join-Path $stagedContent 'ASophia\Maps\MainMapSubmaps'
$stagedWidgetDir = Join-Path $stagedContent 'ASophia\UI\HUD\Map'
$stagedIconDir = Join-Path $stagedContent 'ASophia\UI\HUD\Markers\Textures'
$stagedHudDir = Join-Path $stagedContent 'ASophia\UI\HUD'
$stagedRadarDir = Join-Path $stagedContent 'SaveStats'
New-Item -ItemType Directory -Path $baselineDir, $stagedDir, $stagedWidgetDir, $stagedIconDir, $stagedHudDir, $stagedRadarDir -Force | Out-Null

$markersPath = Join-Path $runDir 'markers.json'
Invoke-CheckedTool $unrealPak @($installedPak, '-Extract', $baselineDir, '-Filter=*PuzzleDatabase*')
$nativePuzzleAsset = Join-Path $baselineDir 'ASophia\Data\PuzzleDatabase.uasset'
if (-not (Test-Path -LiteralPath $nativePuzzleAsset -PathType Leaf)) { throw 'Installed base mod did not provide PuzzleDatabase.' }
$nativePuzzleJson = Join-Path $runDir 'puzzle-database.json'
Invoke-CheckedTool $assetGui @('tojson', $nativePuzzleAsset, $nativePuzzleJson, 'VER_UE4_27')
$exportArguments = @(
    (Join-Path $PSScriptRoot 'export_markers.php'), '--savestats-dir', $SaveStatsDir,
    '--catalog-mode', $catalogModeArgument, '--output', $markersPath, '--puzzle-database-json', $nativePuzzleJson
)
if ($catalogModeArgument -eq 'snapshot') { $exportArguments += @('--save', $SavePath) }
Invoke-CheckedTool $php $exportArguments
$snapshot = Get-Content -Raw -LiteralPath $markersPath | ConvertFrom-Json -Depth 30
if ($snapshot.source.catalogMode -cne $catalogModeArgument) { throw 'Exporter catalog mode does not match the requested build mode.' }
$pentadCoverage = Assert-PentadSnapshotCoverage $snapshot
$hubCoverage = Assert-HubAvailabilitySnapshot $snapshot
$specialCoverage = Assert-SpecialCompletionSnapshot $snapshot

Invoke-CheckedTool $unrealPak @($installedPak, '-Extract', $baselineDir, '-Filter=*RRMod_Objects*')
Invoke-CheckedTool $unrealPak @($installedPak, '-Extract', $baselineDir, '-Filter=*WBP_LargeMap*')
$baselineMap = Join-Path $baselineDir 'ASophia\Maps\MainMapSubmaps\RRMod_Objects.umap'
$baselineData = Join-Path $baselineDir 'ASophia\Maps\MainMapSubmaps\RRMod_Objects.uexp'
foreach ($file in @($baselineMap, $baselineData)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Extraction failed: $file" }
}
$baselineJson = Join-Path $runDir 'baseline.json'
Invoke-CheckedTool $assetGui @('tojson', $baselineMap, $baselineJson, 'VER_UE4_27')
if (-not (Test-Path -LiteralPath $baselineJson -PathType Leaf)) { throw 'Baseline asset conversion failed.' }

$injectedJson = Join-Path $runDir 'RRMod_Objects.json'
$renderManifestPath = Join-Path $runDir 'render-points.json'
$injectArguments = @(
    (Join-Path $PSScriptRoot 'inject_markers.php'), '--base-map', $baselineJson,
    '--markers', $markersPath, '--output', $injectedJson, '--render-manifest', $renderManifestPath
)
if ($Limit -gt 0) { $injectArguments += @('--limit', $Limit.ToString()) }
Invoke-CheckedTool $php $injectArguments
Invoke-CheckedTool $php @(
    (Join-Path $PSScriptRoot 'build_icons.php'), '--savestats-dir', $SaveStatsDir, '--output-dir', $stagedIconDir, '--template', $iconTemplate
)
Invoke-CheckedTool $php @(
    (Join-Path $PSScriptRoot 'build_radar_art.php'), '--output-dir', $stagedIconDir, '--template', $iconTemplate
)
$radarDataPath = Join-Path $runDir 'radar-data.json'
Invoke-CheckedTool $php @(
    (Join-Path $PSScriptRoot 'radar_data.php'), '--render-manifest', $renderManifestPath, '--output', $radarDataPath
)
$stagedMap = Join-Path $stagedDir 'RRMod_Objects.umap'
Invoke-CheckedTool $assetGui @('fromjson', $injectedJson, $stagedMap)

# Decode the generated package again; a converter exit code alone is insufficient.
$roundtripJson = Join-Path $runDir 'roundtrip.json'
Invoke-CheckedTool $assetGui @('tojson', $stagedMap, $roundtripJson, 'VER_UE4_27')
if (-not (Test-Path -LiteralPath $roundtripJson -PathType Leaf)) { throw 'Generated asset could not be decoded.' }
$baselineAsset = Get-Content -Raw -LiteralPath $baselineJson | ConvertFrom-Json -Depth 100
$roundtripAsset = Get-Content -Raw -LiteralPath $roundtripJson | ConvertFrom-Json -Depth 100
$snapshot = Get-Content -Raw -LiteralPath $markersPath | ConvertFrom-Json -Depth 30
$renderManifest = Get-Content -Raw -LiteralPath $renderManifestPath | ConvertFrom-Json -Depth 30
$selectedMarkers = @($renderManifest.renderPoints)
$baseExportCount = $baselineAsset.Exports.Count
if ($roundtripAsset.Exports.Count -ne $baseExportCount + 3 * $selectedMarkers.Count) {
    throw 'Roundtrip changed the expected export count.'
}
for ($i = 0; $i -lt $baseExportCount; $i++) {
    $before = $baselineAsset.Exports[$i]
    $after = $roundtripAsset.Exports[$i]
    if ($before.ObjectName -cne $after.ObjectName -or $before.ClassIndex -ne $after.ClassIndex -or $before.OuterIndex -ne $after.OuterIndex) {
        throw "Roundtrip changed existing export identity at index $($i + 1)."
    }
}
$baselineLevel = @($baselineAsset.Exports | Where-Object { $_.ObjectName -eq 'PersistentLevel' -and $_.'$type' -like '*LevelExport,*' })
$roundtripLevel = @($roundtripAsset.Exports | Where-Object { $_.ObjectName -eq 'PersistentLevel' -and $_.'$type' -like '*LevelExport,*' })
if ($baselineLevel.Count -ne 1 -or $roundtripLevel.Count -ne 1) { throw 'Expected one persistent level.' }
if ($roundtripLevel[0].Actors.Count -ne $baselineLevel[0].Actors.Count + $selectedMarkers.Count) { throw 'Level actor count changed unexpectedly.' }
for ($i = 0; $i -lt $baselineLevel[0].Actors.Count; $i++) {
    if ($baselineLevel[0].Actors[$i] -ne $roundtripLevel[0].Actors[$i]) { throw 'An existing level actor reference changed.' }
}
for ($i = 0; $i -lt $selectedMarkers.Count; $i++) {
    $sourceMarker = $selectedMarkers[$i]
    $offset = $baseExportCount + 3 * $i
    $actor = $roundtripAsset.Exports[$offset]
    $root = $roundtripAsset.Exports[$offset + 1]
    $emitter = $roundtripAsset.Exports[$offset + 2]
    if ($actor.ObjectName -cne $sourceMarker.actorName -or $root.OuterIndex -ne $offset + 1 -or $emitter.OuterIndex -ne $offset + 1) {
        throw 'Marker identity or component ownership did not survive conversion.'
    }
    if ($roundtripAsset.Imports[-1 - $actor.ClassIndex].ObjectName -cne 'Actor' -or $roundtripAsset.Imports[-1 - $emitter.ClassIndex].ObjectName -cne 'MarkerEmitter') {
        throw 'Marker native classes did not survive conversion.'
    }
    $rootReference = @($actor.Data | Where-Object Name -eq 'RootComponent')
    $components = @($actor.Data | Where-Object Name -eq 'InstanceComponents')
    if ($rootReference.Count -ne 1 -or $rootReference[0].Value -ne $offset + 2 -or $components.Count -ne 1 -or $components[0].Value.Count -ne 2) {
        throw 'Marker root or component registration references changed.'
    }
    if ($roundtripLevel[0].Actors[$baselineLevel[0].Actors.Count + $i] -ne $offset + 1) { throw 'Marker is missing from level actors.' }
    $position = @($root.Data | Where-Object Name -eq 'RelativeLocation')[0].Value[0].Value
    foreach ($axis in @('x', 'y', 'z')) {
        # UE4 vectors use float32; allow sub-centimetre serialization rounding.
        if (-not [double]::IsFinite([double]$position.$axis) -or [Math]::Abs([double]$position.$axis - [double]$sourceMarker.$axis) -gt 0.1) {
            throw "Marker coordinate $axis changed for puzzle $($sourceMarker.pid)."
        }
    }
    $markerFields = @($emitter.Data | Where-Object Name -eq 'Marker')[0].Value
    $onMap = @($markerFields | Where-Object Name -eq 'VisibleOnMap')
    $onScreen = @($markerFields | Where-Object Name -eq 'VisibleOnScreen')
    if ($onMap.Count -ne 1 -or [bool]$onMap[0].Value -ne [bool]$sourceMarker.visibleByDefault -or $onScreen.Count -ne 1 -or $onScreen[0].Value) {
        throw 'Map-only marker flags did not survive conversion.'
    }
    $hoverName = @($markerFields | Where-Object Name -eq 'MarkerName')
    $hoverEnabled = @($markerFields | Where-Object Name -eq 'ShowInfoPanel')
    if ($hoverName.Count -ne 1 -or $hoverName[0].CultureInvariantString -cne $sourceMarker.label -or $hoverEnabled.Count -ne 1 -or -not $hoverEnabled[0].Value) {
        throw 'Marker hover text did not survive conversion.'
    }
    $iconReference = @($markerFields | Where-Object Name -eq 'mapTex')[0].Value
    if ($iconReference -ge 0 -or $roundtripAsset.Imports[-1 - $iconReference].ObjectName -cne $sourceMarker.iconAsset) {
        throw 'Marker type icon reference changed.'
    }
    $actorTags = @(@($actor.Data | Where-Object Name -eq 'Tags')[0].Value | ForEach-Object { $_.Value })
    foreach ($tag in $sourceMarker.tags) { if ($actorTags -cnotcontains $tag) { throw "Marker lost filter tag: $tag" } }
}

$payloadPath = Join-Path $stagedDir 'RRMod_Objects.uexp'
$payloadReader = [System.IO.BinaryReader]::new([System.IO.File]::OpenRead($payloadPath))
try {
    $headerSize = (Get-Item -LiteralPath $stagedMap).Length
    for ($i = $baseExportCount; $i -lt $roundtripAsset.Exports.Count; $i++) {
        Assert-OverlayNativePayload $roundtripAsset.Exports[$i] $roundtripAsset $payloadReader $headerSize
    }
} finally { $payloadReader.Dispose() }

$baselineWidget = Join-Path $baselineDir 'ASophia\UI\HUD\Map\WBP_LargeMap.uasset'
$baselineWidgetJson = Join-Path $runDir 'widget-baseline.json'
$filteredWidgetJson = Join-Path $runDir 'WBP_LargeMap.json'
$filterManifestPath = Join-Path $runDir 'filters.json'
if (-not (Test-Path -LiteralPath $baselineWidget)) { throw 'The installed mod did not provide WBP_LargeMap.' }
Invoke-CheckedTool $assetGui @('tojson', $baselineWidget, $baselineWidgetJson, 'VER_UE4_27')
Invoke-CheckedTool $php @(
    (Join-Path $PSScriptRoot 'filter_widget.php'), '--base-widget', $baselineWidgetJson,
    '--markers', $markersPath, '--output', $filteredWidgetJson, '--manifest', $filterManifestPath
)
$stagedWidget = Join-Path $stagedWidgetDir 'WBP_LargeMap.uasset'
$widgetRoundtripJson = Join-Path $runDir 'widget-roundtrip.json'
Invoke-CheckedTool $assetGui @('fromjson', $filteredWidgetJson, $stagedWidget)
Invoke-CheckedTool $assetGui @('tojson', $stagedWidget, $widgetRoundtripJson, 'VER_UE4_27')
$widgetBaseline = Get-Content -Raw -LiteralPath $baselineWidgetJson | ConvertFrom-Json -Depth 100
$widgetRoundtrip = Get-Content -Raw -LiteralPath $widgetRoundtripJson | ConvertFrom-Json -Depth 100
$filterManifest = Get-Content -Raw -LiteralPath $filterManifestPath | ConvertFrom-Json -Depth 30
foreach ($name in $filterManifest.functionNames) {
    $functions = @($widgetRoundtrip.Exports | Where-Object ObjectName -CEQ $name)
    if ($functions.Count -ne 1 -or $functions[0].'$type' -notlike '*FunctionExport,*' -or $functions[0].ScriptBytecode.Count -eq 0) {
        throw "Filter function could not be decoded: $name"
    }
}
foreach ($originalFunction in @($widgetBaseline.Exports | Where-Object { $_.'$type' -like '*FunctionExport,*' })) {
    $after = @($widgetRoundtrip.Exports | Where-Object ObjectName -CEQ $originalFunction.ObjectName)[0]
    if (($originalFunction.ScriptBytecode | ConvertTo-Json -Depth 100 -Compress) -cne ($after.ScriptBytecode | ConvertTo-Json -Depth 100 -Compress)) {
        throw "Existing map behavior changed: $($originalFunction.ObjectName)"
    }
}

$radarJson = Join-Path $runDir 'WBP_SSOverlayRadar.json'
$radarManifestPath = Join-Path $runDir 'radar.json'
$stagedRadar = Join-Path $stagedRadarDir 'WBP_SSOverlayRadar.uasset'
$radarRoundtripJson = Join-Path $runDir 'radar-roundtrip.json'
Invoke-CheckedTool $php @(
    (Join-Path $PSScriptRoot 'radar_widget.php'), '--base-widget', $baselineWidgetJson,
    '--radar-data', $radarDataPath, '--output', $radarJson, '--manifest', $radarManifestPath
)
Invoke-CheckedTool $assetGui @('fromjson', $radarJson, $stagedRadar)
Invoke-CheckedTool $assetGui @('tojson', $stagedRadar, $radarRoundtripJson, 'VER_UE4_27')
$radarSource = Get-Content -Raw -LiteralPath $radarJson | ConvertFrom-Json -Depth 100
$radarRoundtrip = Get-Content -Raw -LiteralPath $radarRoundtripJson | ConvertFrom-Json -Depth 100
Assert-RadarWidgetRoundtrip $radarSource $radarRoundtrip 'Radar' $stagedRadar
$radarClass = @($radarRoundtrip.Exports | Where-Object ObjectName -CEQ 'WBP_SSOverlayRadar_C')
if ($radarClass.Count -ne 1 -or $radarClass[0].'$type' -notlike '*ClassExport,*') { throw 'Generated radar class is missing.' }
$radarIndex = Get-Content -Raw -LiteralPath $radarDataPath | ConvertFrom-Json -Depth 30
$packagedPentadCoverage = Assert-PentadPackagedCoverage $snapshot $selectedMarkers $radarIndex $radarRoundtrip $Limit
$packagedHubCoverage = Assert-HubAvailabilityPackage $snapshot $selectedMarkers $radarIndex $radarRoundtrip $Limit
$packagedSpecialCoverage = Assert-SpecialCompletionPackage $snapshot $selectedMarkers $radarIndex $radarRoundtrip $Limit

$hudJson = Join-Path $runDir 'WBP_GameHUD.json'
$hudManifestPath = Join-Path $runDir 'hud.json'
$stagedHud = Join-Path $stagedHudDir 'WBP_GameHUD.uasset'
$hudRoundtripJson = Join-Path $runDir 'hud-roundtrip.json'
Invoke-CheckedTool $php @(
    (Join-Path $PSScriptRoot 'radar_hud.php'), '--base-hud', $hudBaselineJson,
    '--output', $hudJson, '--manifest', $hudManifestPath
)
Invoke-CheckedTool $assetGui @('fromjson', $hudJson, $stagedHud)
Invoke-CheckedTool $assetGui @('tojson', $stagedHud, $hudRoundtripJson, 'VER_UE4_27')
$hudBaseline = Get-Content -Raw -LiteralPath $hudBaselineJson | ConvertFrom-Json -Depth 100
$hudRoundtrip = Get-Content -Raw -LiteralPath $hudRoundtripJson | ConvertFrom-Json -Depth 100
Assert-RadarWidgetRoundtrip $hudBaseline $hudRoundtrip 'HUD' $stagedHud $hudBaseline.Exports.Count
$hudRadar = @($hudRoundtrip.Exports | Where-Object ObjectName -CEQ 'SSOverlayRadar')
if ($hudRadar.Count -ne 1 -or $hudRadar[0].ClassIndex -ge 0 -or
    $hudRoundtrip.Imports[-1 - $hudRadar[0].ClassIndex].ObjectName -cne 'WBP_SSOverlayRadar_C') {
    throw 'Gameplay HUD lost its embedded radar reference.'
}

$responsePath = Join-Path $runDir 'pak-response.txt'
$responseLines = foreach ($assetFile in @(Get-ChildItem -LiteralPath $stagedContent -Recurse -File | Where-Object { $_.Extension -in @('.umap', '.uasset', '.uexp', '.ubulk') } | Sort-Object FullName)) {
    $mountPath = [System.IO.Path]::GetRelativePath($stagedContent, $assetFile.FullName).Replace('\', '/')
    '"' + $assetFile.FullName + '" "../../../IslandsofInsight/Content/' + $mountPath + '"'
}
[System.IO.File]::WriteAllLines($responsePath, $responseLines, [System.Text.UTF8Encoding]::new($false))
$pakPath = Join-Path $runDir 'ZZ_SaveStatsMap_P.pak'
Invoke-CheckedTool $unrealPak @($pakPath, "-Create=$responsePath")
Invoke-CheckedTool $unrealPak @($pakPath, '-Test')
Invoke-CheckedTool $unrealPak @($pakPath, '-List')
$packageListLog = Join-Path $runDir ('tool-{0:D2}.log' -f $script:OverlayToolLogIndex)
$packageEntries = [regex]::Matches((Get-Content -Raw -LiteralPath $packageListLog), '(?m)^.*LogPakFile:\s*Display:\s*"[^"]+"\s+offset:').Count
if ($packageEntries -ne $responseLines.Count) {
    throw "Package contains $packageEntries entries; expected $($responseLines.Count). A tool exit code does not prove the response file was read."
}

$manifest = [ordered]@{
    version = $version
    pakSha256 = (Get-FileHash -LiteralPath $pakPath -Algorithm SHA256).Hash
    pak = $pakPath
    catalogMode = $catalogModeArgument
    markerSnapshot = $markersPath
    markerLimit = $Limit
    renderPointCount = $selectedMarkers.Count
    packagedAssetCount = $packageEntries
    puzzleCounts = $snapshot.counts
    pentadProgressCoverage = $pentadCoverage
    packagedPentadProgressCoverage = $packagedPentadCoverage
    hubAvailabilityCoverage = $hubCoverage
    packagedHubAvailabilityCoverage = $packagedHubCoverage
    nativePuzzleDatabase = $nativePuzzleJson
    nativePuzzleDatabaseSha256 = (Get-FileHash -LiteralPath $nativePuzzleJson -Algorithm SHA256).Hash
    renderManifest = $renderManifestPath
    filterManifest = $filterManifestPath
    radarData = $radarDataPath
    radarManifest = $radarManifestPath
    radarRoundtripJson = $radarRoundtripJson
    specialCompletionCoverage = $specialCoverage
    packagedSpecialCompletionCoverage = $packagedSpecialCoverage
    hudManifest = $hudManifestPath
    hudRoundtripJson = $hudRoundtripJson
    baseHudJsonSha256 = (Get-FileHash -LiteralPath $hudBaselineJson -Algorithm SHA256).Hash
    installedBasePakSha256 = (Get-FileHash -LiteralPath $installedPak -Algorithm SHA256).Hash
    baselineJson = $baselineJson
    injectedJson = $injectedJson
    roundtripJson = $roundtripJson
    status = 'Built; native package checks passed. Rendered gameplay beta checks remain manual.'
}
foreach ($protectedInput in $protectedInputs) {
    if ((Get-FileHash -LiteralPath $protectedInput.path -Algorithm SHA256).Hash -cne $protectedInput.sha256) {
        throw "Build input changed unexpectedly: $($protectedInput.path)"
    }
}
$manifestPath = Join-Path $runDir 'build.json'
$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding utf8
Write-Output "Overlay package: $pakPath"
Write-Output "Build evidence: $manifestPath"
Write-Output 'This script does not install files into the game or modify the save.'
