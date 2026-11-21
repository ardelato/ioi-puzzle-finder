#requires -Version 7.0
[CmdletBinding()]
param(
    [string]$PhpPath = 'php',
    [switch]$Integration,
    [string]$TestDataDir = $env:IOI_RADAR_TEST_DATA_DIR,
    [string]$UAssetGuiPath = ''
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$php = (Get-Command -Name $PhpPath -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$previousDataDir = $env:IOI_RADAR_TEST_DATA_DIR
$previousAssetGui = $env:IOI_RADAR_ASSET_GUI

function Invoke-TestPhp([string[]]$Arguments, [string]$Description) {
    $output = & $php @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "$Description failed (exit $LASTEXITCODE):`n$($output -join [Environment]::NewLine)"
    }
    return $output
}

try {
    if ($UAssetGuiPath -ne '' -and -not $Integration) {
        throw '-UAssetGuiPath requires -Integration.'
    }
    # The default suite must not pick up a developer's game-data configuration.
    $env:IOI_RADAR_TEST_DATA_DIR = $null
    $env:IOI_RADAR_ASSET_GUI = $null
    if ($Integration) {
        if ([string]::IsNullOrWhiteSpace($TestDataDir)) {
            throw '-Integration requires -TestDataDir (or IOI_RADAR_TEST_DATA_DIR). See docs/BUILDING.md.'
        }
        $resolvedData = (Resolve-Path -LiteralPath $TestDataDir -ErrorAction Stop).Path
        foreach ($relative in @('BaseJsons/WBP_LargeMap.json', 'BaseJsons/WBP_GameHUD.json', 'OutputJsons/Maps/RRMod_Objects.json')) {
            if (-not (Test-Path -LiteralPath (Join-Path $resolvedData $relative) -PathType Leaf)) {
                throw "Integration test data is missing $relative."
            }
        }
        $env:IOI_RADAR_TEST_DATA_DIR = $resolvedData
        if ($UAssetGuiPath -ne '') {
            $env:IOI_RADAR_ASSET_GUI = (Get-Command -Name $UAssetGuiPath -CommandType Application -ErrorAction Stop).Source
        }
    }

    Invoke-TestPhp @('-r', 'if (PHP_VERSION_ID < 80300) { fwrite(STDERR, "PHP 8.3 or newer is required.\n"); exit(1); } if (!extension_loaded("gd")) { fwrite(STDERR, "The PHP GD extension is required.\n"); exit(1); } echo "PHP ", PHP_VERSION, " with GD\n";') 'PHP prerequisites'

    $phpFiles = @(Get-ChildItem -LiteralPath (Join-Path $repoRoot 'MapOverlay'), (Join-Path $repoRoot 'lib') -Filter '*.php' -File -Recurse | Sort-Object FullName)
    foreach ($file in $phpFiles) {
        Invoke-TestPhp @('-l', $file.FullName) "PHP syntax: $($file.Name)" | Out-Null
    }
    Write-Output "PHP syntax: PASS ($($phpFiles.Count) files)."
    $psFiles = @(Get-ChildItem -LiteralPath $repoRoot -Filter '*.ps1' -File -Recurse | Where-Object { $_.FullName -notmatch '[\\/](output|dist|\.git)[\\/]' })
    foreach ($file in $psFiles) {
        $parseTokens = $null
        $parseErrors = $null
        [void][System.Management.Automation.Language.Parser]::ParseFile($file.FullName, [ref]$parseTokens, [ref]$parseErrors)
        if ($parseErrors.Count -gt 0) {
            throw "PowerShell syntax: $($file.FullName)`n$($parseErrors.Message -join [Environment]::NewLine)"
        }
    }
    Write-Output "PowerShell syntax: PASS ($($psFiles.Count) files)."

    $unitTests = @(
        'MapOverlay/tests/export_markers_test.php',
        'MapOverlay/tests/universal_catalog_test.php',
        'MapOverlay/tests/radar_data_test.php',
        'MapOverlay/tests/hub_availability_data_test.php',
        'MapOverlay/tests/completion_metadata_test.php',
        'MapOverlay/tests/lib_helpers_test.php',
        'MapOverlay/tests/icon_texture_test.php'
    )
    foreach ($test in $unitTests) {
        Write-Output "Running $test"
        Invoke-TestPhp @((Join-Path $repoRoot $test)) $test
    }
    Write-Output "Synthetic regression suites: PASS ($($unitTests.Count) suites; no game assets or player saves)."
    & (Join-Path $PSScriptRoot 'test-release-tools.ps1')

    if ($Integration) {
        $assetTests = @('MapOverlay/test_inject_markers.php', 'MapOverlay/tests/filter_widget_test.php', 'MapOverlay/tests/radar_hud_test.php')
        foreach ($test in $assetTests) {
            Write-Output "Running integration: $test"
            Invoke-TestPhp @((Join-Path $repoRoot $test)) $test
        }
        $roundtrip = if ($UAssetGuiPath -ne '') { 'requested' } else { 'not requested' }
        Write-Output "Asset integration suites: PASS ($($assetTests.Count) suites; UAssetGUI roundtrip $roundtrip)."
    }
    else {
        Write-Output 'Asset integration suites: NOT RUN (use -Integration with external test data).'
    }
    Write-Output 'In-game engine and visual checks are separate; this script does not launch or install the game.'
}
finally {
    $env:IOI_RADAR_TEST_DATA_DIR = $previousDataDir
    $env:IOI_RADAR_ASSET_GUI = $previousAssetGui
}
