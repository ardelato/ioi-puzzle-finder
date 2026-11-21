#requires -Version 7.0
[CmdletBinding(DefaultParameterSetName = 'ExistingHud')]
param(
    [Parameter(Mandatory)][string]$OfflineRestoredSourceDir,
    [Parameter(Mandatory, ParameterSetName = 'ExistingHud')][string]$HudBaselineJson,
    [Parameter(Mandatory, ParameterSetName = 'ExtractHud')][string]$GamePak,
    [Parameter(Mandatory, ParameterSetName = 'ExtractHud')][string]$CryptoKeysPath,
    [Parameter(Mandatory, ParameterSetName = 'ExtractHud')][string]$UnrealPakPath,
    [Parameter(Mandatory, ParameterSetName = 'ExtractHud')][string]$UAssetGuiPath,
    [string]$OutputDir = "$(Split-Path $PSScriptRoot -Parent)/local-inputs"
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$compatibility = Get-Content -Raw -LiteralPath (Join-Path $repo 'compatibility.json') | ConvertFrom-Json
$sourceDir = (Resolve-Path -LiteralPath $OfflineRestoredSourceDir).Path
$OutputDir = [IO.Path]::GetFullPath($OutputDir)
if (Test-Path -LiteralPath $OutputDir) { throw 'Use a new output directory; existing inputs are never overwritten.' }
$inputPaths = @((Join-Path $sourceDir 'Assets/MapMarker.uasset'), (Join-Path $sourceDir 'Assets/MapMarker.uexp'))
if ($PSCmdlet.ParameterSetName -eq 'ExistingHud') {
    $HudBaselineJson = (Resolve-Path -LiteralPath $HudBaselineJson).Path
    $inputPaths += $HudBaselineJson
} else {
    $GamePak = (Resolve-Path -LiteralPath $GamePak).Path
    $CryptoKeysPath = (Resolve-Path -LiteralPath $CryptoKeysPath).Path
    $inputPaths += @($GamePak, $CryptoKeysPath)
}
# Never place extracted output inside a checkout, game installation or input file.
$protectedDirectories = @($sourceDir)
if ($PSCmdlet.ParameterSetName -eq 'ExtractHud') {
    $pakDirectory = Split-Path $GamePak -Parent
    $contentDirectory = Split-Path $pakDirectory -Parent
    if ((Split-Path $pakDirectory -Leaf) -ieq 'Paks' -and (Split-Path $contentDirectory -Leaf) -ieq 'Content') {
        $protectedDirectories += Split-Path (Split-Path $contentDirectory -Parent) -Parent
    } else { $protectedDirectories += $pakDirectory }
}
foreach ($directory in $protectedDirectories) {
    $prefix = [IO.Path]::TrimEndingDirectorySeparator([IO.Path]::GetFullPath($directory)) + [IO.Path]::DirectorySeparatorChar
    if ($OutputDir.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) -or $OutputDir -ieq $prefix.TrimEnd([IO.Path]::DirectorySeparatorChar)) {
        throw 'Input preparation output must be outside source and game directories.'
    }
}
$before = @($inputPaths | ForEach-Object { [PSCustomObject]@{path=$_;sha256=(Get-FileHash -LiteralPath $_ -Algorithm SHA256).Hash} })
New-Item -ItemType Directory -Path $OutputDir | Out-Null
function Invoke-InputTool([string]$Tool, [string[]]$Arguments, [string]$LogName) {
    $program = (Get-Command -Name $Tool -CommandType Application -ErrorAction Stop).Source
    $start = [Diagnostics.ProcessStartInfo]::new($program)
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.WorkingDirectory = $OutputDir
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    foreach ($argument in $Arguments) { $start.ArgumentList.Add($argument) }
    $process = [Diagnostics.Process]::Start($start)
    try {
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $log = Join-Path $OutputDir $LogName
        [IO.File]::WriteAllText($log, $stdout.GetAwaiter().GetResult() + $stderr.GetAwaiter().GetResult())
        if ($process.ExitCode -ne 0) { throw "Input tool failed; see local log $LogName." }
    } finally { $process.Dispose() }
}
Copy-Item -LiteralPath $inputPaths[0] -Destination (Join-Path $OutputDir 'MapMarker.uasset')
Copy-Item -LiteralPath $inputPaths[1] -Destination (Join-Path $OutputDir 'MapMarker.uexp')
if ($PSCmdlet.ParameterSetName -eq 'ExtractHud') {
    $extraction = Join-Path $OutputDir 'extracted'
    New-Item -ItemType Directory -Path $extraction | Out-Null
    # -Extract and its destination MUST be separate arguments. Installed paks are inputs only.
    Invoke-InputTool $UnrealPakPath @($GamePak, '-Extract', $extraction, '-Filter=*WBP_GameHUD*', "-cryptokeys=$CryptoKeysPath") 'extract.log'
    $hudAsset = Join-Path $extraction 'ASophia/UI/HUD/WBP_GameHUD.uasset'
    if (-not (Test-Path -LiteralPath $hudAsset -PathType Leaf)) { throw 'The selected game pak did not contain the HUD asset.' }
    Invoke-InputTool $UAssetGuiPath @('tojson', $hudAsset, (Join-Path $OutputDir 'WBP_GameHUD.json'), 'VER_UE4_27') 'convert.log'
} else {
    Copy-Item -LiteralPath $HudBaselineJson -Destination (Join-Path $OutputDir 'WBP_GameHUD.json')
}
foreach ($asset in $compatibility.assetInputs.PSObject.Properties) {
    if ((Get-FileHash -LiteralPath (Join-Path $OutputDir $asset.Name) -Algorithm SHA256).Hash -ine $asset.Value) {
        throw "Unsupported asset $($asset.Name); expected the tested game/mod version and UAssetGUI 1.0.3 serialization."
    }
}
foreach ($entry in $before) {
    if ((Get-FileHash -LiteralPath $entry.path -Algorithm SHA256).Hash -cne $entry.sha256) { throw 'Input preparation changed a source file.' }
}
Write-Output "Verified local build inputs: $OutputDir"
