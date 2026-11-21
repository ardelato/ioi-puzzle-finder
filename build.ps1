#requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$SaveStatsDir,
    [ValidateSet('Universal', 'Snapshot')][string]$CatalogMode,
    [string]$SavePath,
    [Parameter(Mandatory)][string]$GameDir,
    [Parameter(Mandatory)][string]$AssetInputDir,
    [string]$PhpPath,
    [string]$UAssetGuiPath,
    [string]$UnrealPakPath,
    [string]$OutputDir,
    [ValidateRange(0, 100000)][int]$Limit
)
# Forward named arguments; the implementation owns defaults for omitted options.
& (Join-Path $PSScriptRoot 'MapOverlay/build.ps1') @PSBoundParameters
