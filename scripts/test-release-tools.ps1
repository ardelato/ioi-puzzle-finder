#requires -Version 7.0
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$fixture = Join-Path ([IO.Path]::GetTempPath()) ('ioi-release-test-' + [Guid]::NewGuid().ToString('N'))
$build = Join-Path $fixture 'build'
New-Item -ItemType Directory -Path $build -Force | Out-Null
$checks = 0
function Assert-ReleaseTest([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
    $script:checks++
}
function Expect-ReleaseFailure([scriptblock]$Operation, [string]$Message) {
    $failed = $false
    try { & $Operation | Out-Null } catch { $failed = $true }
    Assert-ReleaseTest $failed $Message
}
try {
    $version = (Get-Content -Raw -LiteralPath (Join-Path $repo 'VERSION')).Trim()
    $compatibility = Get-Content -Raw -LiteralPath (Join-Path $repo 'compatibility.json') | ConvertFrom-Json
    $pak = Join-Path $build 'ZZ_IOIPuzzleRadar_P.pak'
    [IO.File]::WriteAllText($pak, 'Synthetic packaging fixture. This is not a game package.')
    $inputHash = (Get-FileHash -LiteralPath $pak -Algorithm SHA256).Hash
    $manifest = [ordered]@{
        version=$version;pak=$pak;pakSha256=$inputHash;catalogMode='universal';markerLimit=0;packagedAssetCount=68
        installedBasePakSha256=$compatibility.offlineRestoredPakSha256;renderPointCount=18999
        specialCompletionCoverage=@{lorePuzzles=98;monolithFragments=59;total=157}
    }
    $manifestPath = Join-Path $build 'build.json'
    $packager = Join-Path $PSScriptRoot 'package-beta.ps1'
    $caseNumber = 0
    foreach ($change in @(
        @{key='catalogMode';value='snapshot'}, @{key='markerLimit';value=1},
        @{key='packagedAssetCount';value=67}, @{key='version';value='9.9.9-beta.1'},
        @{key='installedBasePakSha256';value=('0'*64)}, @{key='pak';value=(Join-Path $fixture 'elsewhere.pak')},
        @{key='pakSha256';value=('0'*64)}
    )) {
        $caseNumber++
        $prior = $manifest[$change.key]
        $manifest[$change.key] = $change.value
        $manifest | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $manifestPath -Encoding utf8NoBOM
        $rejectedOutput = Join-Path $fixture "rejected-$caseNumber"
        Expect-ReleaseFailure { & $packager -BuildDirectory $build -OutputDir $rejectedOutput } "Packager accepted invalid $($change.key)."
        Assert-ReleaseTest (-not (Test-Path -LiteralPath $rejectedOutput)) 'Rejected build created release output.'
        $manifest[$change.key] = $prior
    }
    $manifest | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $manifestPath -Encoding utf8NoBOM
    # These private development artifacts must never leak through recursive copying.
    [IO.File]::WriteAllText((Join-Path $build 'Crypto.json'), 'synthetic private sentinel')
    [IO.File]::WriteAllText((Join-Path $build 'player.sav'), 'synthetic private sentinel')
    [IO.File]::WriteAllText((Join-Path $build 'tool.log'), $fixture)
    $output = Join-Path $fixture 'release'
    & $packager -BuildDirectory $build -OutputDir $output | Out-Null
    $zip = Join-Path $output "ioi-puzzle-radar-$version.zip"
    Assert-ReleaseTest (Test-Path -LiteralPath $zip -PathType Leaf) 'No release archive was written.'
    $expected = @('ZZ_IOIPuzzleRadar_P.pak','README.md','LICENSE','NOTICE','THIRD_PARTY_NOTICES.md','CHANGELOG.md','BETA-TESTING.md','release.json','SHA256SUMS.txt')
    $archive = [IO.Compression.ZipFile]::OpenRead($zip)
    try {
        Assert-ReleaseTest (@(Compare-Object ($expected | Sort-Object) (@($archive.Entries.FullName) | Sort-Object)).Count -eq 0) 'Public archive contains unexpected or missing files.'
        $entry = $archive.GetEntry('release.json')
        $reader = [IO.StreamReader]::new($entry.Open())
        try { $releaseText = $reader.ReadToEnd() } finally { $reader.Dispose() }
        $release = $releaseText | ConvertFrom-Json -Depth 20
        Assert-ReleaseTest ($release.package -ceq 'ZZ_IOIPuzzleRadar_P.pak') 'Release manifest package name is wrong.'
        Assert-ReleaseTest ($release.packageSha256 -ceq $inputHash) 'Release manifest package hash is wrong.'
        Assert-ReleaseTest (-not $releaseText.Contains($fixture)) 'Local paths leaked into release metadata.'
        Assert-ReleaseTest ($release.catalogMode -ceq 'universal' -and $release.version -ceq $version) 'Release identity disagrees with source.'
        $readme = [IO.StreamReader]::new($archive.GetEntry('README.md').Open())
        try { $readmeText = $readme.ReadToEnd() } finally { $readme.Dispose() }
        Assert-ReleaseTest ($readmeText -notmatch '\]\((?:\.\./|docs/|scripts/)') 'Release README links to missing source-only files.'
    } finally { $archive.Dispose() }
    Assert-ReleaseTest ((Get-FileHash -LiteralPath $pak -Algorithm SHA256).Hash -ceq $inputHash) 'Packager mutated its input package.'
    $zipHash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    Assert-ReleaseTest ((Get-Content -Raw -LiteralPath "$zip.sha256").StartsWith($zipHash)) 'Archive checksum is wrong.'
    Expect-ReleaseFailure { & $packager -BuildDirectory $build -OutputDir $output } 'Packager overwrote an existing release.'

    $upstream = Join-Path $fixture 'upstream'
    New-Item -ItemType Directory -Path (Join-Path $upstream 'Assets') | Out-Null
    [IO.File]::WriteAllText((Join-Path $upstream 'Assets/MapMarker.uasset'), 'synthetic invalid template')
    [IO.File]::WriteAllText((Join-Path $upstream 'Assets/MapMarker.uexp'), 'synthetic invalid template')
    $hud = Join-Path $fixture 'hud.json'
    [IO.File]::WriteAllText($hud, '{}')
    $preparer = Join-Path $PSScriptRoot 'prepare-inputs.ps1'
    Expect-ReleaseFailure { & $preparer -OfflineRestoredSourceDir $upstream -HudBaselineJson $hud -OutputDir (Join-Path $upstream 'local-inputs') } 'Preparer wrote inside its source checkout.'
    Assert-ReleaseTest (-not (Test-Path -LiteralPath (Join-Path $upstream 'local-inputs'))) 'Rejected preparation created output inside inputs.'
    Expect-ReleaseFailure { & $preparer -OfflineRestoredSourceDir $upstream -HudBaselineJson $hud -OutputDir $output } 'Preparer overwrote existing inputs.'
    Expect-ReleaseFailure { & $preparer -OfflineRestoredSourceDir $upstream -HudBaselineJson $hud -OutputDir (Join-Path $fixture 'invalid-inputs') } 'Preparer accepted unverified asset hashes.'

    $game = Join-Path $fixture 'game'
    $gamePaks = Join-Path $game 'IslandsofInsight/Content/Paks'
    New-Item -ItemType Directory -Path $gamePaks -Force | Out-Null
    $gamePak = Join-Path $gamePaks 'game.pak'
    $localKeys = Join-Path $fixture 'local-keys.json'
    [IO.File]::WriteAllText($gamePak, 'synthetic read-only game input')
    [IO.File]::WriteAllText($localKeys, '{}')
    $forbidden = Join-Path $game 'new-extraction'
    Expect-ReleaseFailure { & $preparer -OfflineRestoredSourceDir $upstream -GamePak $gamePak -CryptoKeysPath $localKeys -UnrealPakPath 'unused-tool' -UAssetGuiPath 'unused-tool' -OutputDir $forbidden } 'Preparer accepted an output at the game installation root.'
    Assert-ReleaseTest (-not (Test-Path -LiteralPath $forbidden)) 'Rejected extraction created output in the game installation.'

    $wrapperRoot = Join-Path $fixture 'wrapper'
    New-Item -ItemType Directory -Path (Join-Path $wrapperRoot 'MapOverlay') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $repo 'build.ps1') -Destination (Join-Path $wrapperRoot 'build.ps1')
    $stub = @'
[CmdletBinding()]
param([string]$SaveStatsDir,[string]$GameDir,[string]$AssetInputDir,[string]$PhpPath,[string]$CatalogMode='Universal',[int]$Limit=0)
[PSCustomObject]@{stats=$SaveStatsDir;game=$GameDir;assets=$AssetInputDir;php=$PhpPath;mode=$CatalogMode;limit=$Limit}
'@
    [IO.File]::WriteAllText((Join-Path $wrapperRoot 'MapOverlay/build.ps1'), $stub)
    $forwarded = & (Join-Path $wrapperRoot 'build.ps1') -SaveStatsDir 'provider with spaces' -GameDir 'game with spaces' -AssetInputDir 'asset inputs' -PhpPath 'php path' -Limit 2
    Assert-ReleaseTest ($forwarded.stats -ceq 'provider with spaces' -and $forwarded.game -ceq 'game with spaces' -and $forwarded.assets -ceq 'asset inputs') 'Root wrapper did not forward named directory arguments.'
    Assert-ReleaseTest ($forwarded.php -ceq 'php path' -and $forwarded.limit -eq 2 -and $forwarded.mode -ceq 'Universal') 'Root wrapper lost a named tool argument or implementation default.'
    Write-Output "Release tooling: PASS ($checks assertions; synthetic packages only)."
} finally {
    # Remove only the uniquely-created fixture under the actual temp root.
    $resolvedFixture = [IO.Path]::GetFullPath($fixture)
    $tempPrefix = [IO.Path]::TrimEndingDirectorySeparator([IO.Path]::GetFullPath([IO.Path]::GetTempPath())) + [IO.Path]::DirectorySeparatorChar
    if ($resolvedFixture.StartsWith($tempPrefix, [StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolvedFixture).StartsWith('ioi-release-test-')) {
        Remove-Item -LiteralPath $resolvedFixture -Recurse -Force
    }
}
