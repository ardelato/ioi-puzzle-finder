#requires -Version 7.0
<#
Bake handmade Crystal Labyrinth layouts by running the game headless once.

The game streams the labyrinth levels in an isolated profile (-UserDir under RunDir), a generated
driver dumps the maze actors and their wall pieces into its travel URL, and the engine log line is
turned into labyrinth-layouts JSON. Nothing in the game install or the player's save is written:
the driver package mounts from the isolated profile's Saved/Paks, and only the launch settings
(GameUserSettings.ini, Launch.sav, LocalPersistence.sav) are copied in so the game skips its GPU
benchmark, which crashes under -nullrhi.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$GameDir,
    [Parameter(Mandatory)][string]$PhpPath,
    [Parameter(Mandatory)][string]$UAssetGuiPath,
    [Parameter(Mandatory)][string]$UnrealPakPath,
    [Parameter(Mandatory)][string]$BaseWidgetJson,
    [Parameter(Mandatory)][string]$BaselineWorldJson,
    [Parameter(Mandatory)][string]$RunDir,
    [Parameter(Mandatory)][string]$OutputJson,
    [string]$MarkersJson,
    [string]$UserSavedDir = "$env:LOCALAPPDATA\IslandsofInsight\Saved",
    [int]$MaximumSeconds = 240
)
$ErrorActionPreference = 'Stop'
$exe = Join-Path $GameDir 'IslandsofInsight\Binaries\Win64\IslandsofInsight-Win64-Shipping.exe'
if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) { throw "Game executable not found under $GameDir." }
if (Get-Process -Name 'IslandsofInsight-Win64-Shipping' -ErrorAction SilentlyContinue) {
    throw 'Islands of Insight is running. Close it so the labyrinth bake can run the game headless, or pass -SkipLabyrinthBake.'
}
New-Item -ItemType Directory -Force -Path $RunDir | Out-Null
$RunDir = (Resolve-Path -LiteralPath $RunDir).Path
$assetsDir = Join-Path $RunDir 'assets'
$profile = Join-Path $RunDir 'profile'
if (Test-Path -LiteralPath $profile) { throw "Use a fresh labyrinth bake directory: $RunDir" }
New-Item -ItemType Directory -Force -Path $assetsDir | Out-Null

$manifest = & $PhpPath (Join-Path $PSScriptRoot 'labyrinth_bake.php') --mode assets --base-widget $BaseWidgetJson --baseline-world $BaselineWorldJson --output-dir $assetsDir
if ($LASTEXITCODE -ne 0) { throw "Labyrinth bake assets failed: $manifest" }
$manifest = $manifest | ConvertFrom-Json

$response = [Collections.Generic.List[string]]::new()
foreach ($entry in $manifest.pak.PSObject.Properties) {
    $extension = [IO.Path]::GetExtension($entry.Value)
    $json = Join-Path $assetsDir ($entry.Name + '.json')
    $asset = Join-Path $assetsDir ($entry.Name + $extension)
    $start = [Diagnostics.ProcessStartInfo]::new($UAssetGuiPath)
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true; $start.RedirectStandardError = $true
    foreach ($value in @('fromjson', $json, $asset)) { $start.ArgumentList.Add($value) }
    $process = [Diagnostics.Process]::Start($start); $err = $process.StandardError.ReadToEndAsync(); $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw "UAssetGUI could not write $($entry.Name): $($err.GetAwaiter().GetResult())" }
    $target = [IO.Path]::ChangeExtension($entry.Value, $null).TrimEnd('.')
    foreach ($ext in @($extension, '.uexp')) {
        $response.Add(('"' + [IO.Path]::ChangeExtension($asset, $ext) + '" "../../../IslandsofInsight/Content/' + $target + $ext + '"'))
    }
}
$responsePath = Join-Path $assetsDir 'response.txt'
$response | Set-Content -LiteralPath $responsePath -Encoding ascii
$pakDir = Join-Path $profile 'Saved\Paks'
New-Item -ItemType Directory -Force -Path $pakDir, (Join-Path $profile 'Saved\SaveGames'), (Join-Path $profile 'Saved\Config\WindowsNoEditor') | Out-Null
$pak = Join-Path $pakDir 'ZZ_SSLabyrinthBake_P.pak'
& $UnrealPakPath $pak "-Create=$responsePath" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'UnrealPak could not package the labyrinth bake driver.' }

# Launch settings only; never the progress save.
$settings = Join-Path $UserSavedDir 'Config\WindowsNoEditor\GameUserSettings.ini'
if (Test-Path -LiteralPath $settings) { Copy-Item -LiteralPath $settings -Destination (Join-Path $profile 'Saved\Config\WindowsNoEditor') }
foreach ($name in @('Launch.sav', 'LocalPersistence.sav')) {
    $source = Join-Path $UserSavedDir "SaveGames\$name"
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination (Join-Path $profile 'Saved\SaveGames') }
}

$log = Join-Path $RunDir 'engine.log'
$start = [Diagnostics.ProcessStartInfo]::new($exe)
$start.UseShellExecute = $false; $start.CreateNoWindow = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
$start.Environment['SteamAppId'] = '2071500'; $start.Environment['SteamGameId'] = '2071500'
foreach ($argument in @('/Game/ASophia/Maps/MainMapSubmaps/RRMod_Objects?game=/Script/Engine.GameModeBase',
        '-ini:Engine:[/Script/EngineSettings.GameMapsSettings]:GameDefaultMap=/Game/ASophia/Maps/MainMapSubmaps/RRMod_Objects',
        '-ini:Engine:[/Script/EngineSettings.GameMapsSettings]:GlobalDefaultGameMode=/Script/Engine.GameModeBase',
        '-nullrhi', '-unattended', '-nosound', '-nosplash', "-UserDir=$profile", "-ABSLOG=$log")) { $start.ArgumentList.Add($argument) }
$child = [Diagnostics.Process]::Start($start)
$null = $child.StandardOutput.ReadToEndAsync(); $null = $child.StandardError.ReadToEndAsync()
$outcome = 'TIMEOUT'
try {
    for ($tick = 0; $tick -lt [Math]::Ceiling($MaximumSeconds / 2); $tick++) {
        if ($child.WaitForExit(2000)) { $outcome = 'EXITED'; break }
        if ((Test-Path -LiteralPath $log) -and (Select-String -LiteralPath $log -Pattern ('LoadMap: ' + [regex]::Escape($manifest.doneMap)) -Quiet)) { $outcome = 'DONE'; break }
    }
} finally {
    if (-not $child.HasExited) { $child.Kill(); $child.WaitForExit() }
}
if ($outcome -ne 'DONE') { throw "The labyrinth bake run ended with $outcome; see $log." }

$layoutArgs = @((Join-Path $PSScriptRoot 'labyrinth_bake.php'), '--mode', 'layouts', '--log', $log, '--output', $OutputJson)
if ($MarkersJson) { $layoutArgs += @('--markers', $MarkersJson) }
$summary = & $PhpPath @layoutArgs
if ($LASTEXITCODE -ne 0) { throw "Labyrinth layouts failed: $summary" }
$summary
