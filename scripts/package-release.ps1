#requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$BuildDirectory,
    [string]$OutputDir = "$(Split-Path $PSScriptRoot -Parent)/dist"
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$version = (Get-Content -Raw -LiteralPath (Join-Path $repo 'VERSION')).Trim()
if ($version -cnotmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$') { throw 'Expected a SemVer in VERSION.' }
$BuildDirectory = (Resolve-Path -LiteralPath $BuildDirectory).Path
$build = Get-Content -Raw -LiteralPath (Join-Path $BuildDirectory 'build.json') | ConvertFrom-Json -Depth 40
$compatibility = Get-Content -Raw -LiteralPath (Join-Path $repo 'compatibility.json') | ConvertFrom-Json
if ($build.version -cne $version -or $build.catalogMode -cne 'universal' -or $build.markerLimit -ne 0 -or $build.packagedAssetCount -ne 84) {
    throw 'A full, version-matched universal build with all 84 assets is required.'
}
if ($build.installedBasePakSha256 -ine $compatibility.offlineRestoredPakSha256) { throw 'Build uses an unsupported base mod.' }
$pakName = 'ZZ_IOIPuzzleRadar_P.pak'
$pak = Join-Path $BuildDirectory $pakName
if ([IO.Path]::GetFullPath($build.pak) -ine $pak) { throw 'Build manifest package path disagrees with the build directory.' }
$pakSha = (Get-FileHash -LiteralPath $pak -Algorithm SHA256).Hash
if ($build.pakSha256 -cne $pakSha) { throw 'Package hash no longer matches the verified build manifest.' }
$OutputDir = [IO.Path]::GetFullPath($OutputDir)
$name = "ioi-puzzle-radar-$version"
$folder = Join-Path $OutputDir $name
$zipPath = Join-Path $OutputDir "$name.zip"
if ((Test-Path -LiteralPath $folder) -or (Test-Path -LiteralPath $zipPath)) { throw 'Release output already exists; refusing to overwrite it.' }
# Public files are selected explicitly. Never copy a build directory recursively.
$documents = [ordered]@{
    'README.md' = 'docs/RELEASE-README.md'
    'LICENSE' = 'LICENSE'
    'NOTICE' = 'NOTICE'
    'THIRD_PARTY_NOTICES.md' = 'THIRD_PARTY_NOTICES.md'
    'CHANGELOG.md' = 'CHANGELOG.md'
}
foreach ($source in $documents.Values) {
    if (-not (Test-Path -LiteralPath (Join-Path $repo $source) -PathType Leaf)) { throw "Missing release document: $source" }
}
New-Item -ItemType Directory -Path $folder -Force | Out-Null
Copy-Item -LiteralPath $pak -Destination (Join-Path $folder $pakName)
foreach ($document in $documents.GetEnumerator()) {
    Copy-Item -LiteralPath (Join-Path $repo $document.Value) -Destination (Join-Path $folder $document.Key)
}
$release = [ordered]@{
    name = 'ioi-puzzle-radar'
    version = $version
    channel = if ($version.Contains('-')) { 'prerelease' } else { 'stable' }
    package = $pakName
    packageSha256 = $pakSha
    catalogMode = 'universal'
    runtimeDependencies = [ordered]@{
        game = 'Islands of Insight (Windows)'
        offlineRestoredVersion = $compatibility.offlineRestoredVersion
        offlineRestoredSource = $compatibility.offlineRestoredSource
        offlineRestoredPakSha256 = $compatibility.offlineRestoredPakSha256
        installation = 'Full Offline Restored Mod release, including its runtime components.'
    }
    packagedAssetCount = $build.packagedAssetCount
    markerActorCount = $build.renderPointCount
    specialCompletionCoverage = $build.specialCompletionCoverage
    checks = [ordered]@{
        nativePackageRoundtrip = 'passed by build'
        completeCatalogCoverage = 'passed by build'
        manualGameplay = 'See docs/TESTING.md in the source repository; not implied by packaging.'
    }
}
$release | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath (Join-Path $folder 'release.json') -Encoding utf8NoBOM
$checksums = @(Get-ChildItem -LiteralPath $folder -File | Sort-Object Name | ForEach-Object {
    ((Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()) + '  ' + $_.Name
})
[IO.File]::WriteAllLines((Join-Path $folder 'SHA256SUMS.txt'), $checksums, [Text.UTF8Encoding]::new($false))
$allowed = @($pakName, 'release.json', 'SHA256SUMS.txt') + @($documents.Keys)
$actual = @(Get-ChildItem -LiteralPath $folder -File | Select-Object -ExpandProperty Name)
if (@(Compare-Object ($allowed | Sort-Object) ($actual | Sort-Object)).Count -ne 0) { throw 'Release allowlist mismatch.' }
# Reject local paths or save provenance in every public text file.
foreach ($file in Get-ChildItem -LiteralPath $folder -File | Where-Object Extension -ne '.pak') {
    $content = Get-Content -Raw -LiteralPath $file.FullName
    if ($content -match '(?i)[A-Z]:[\\/](?:Users|Program Files)|saveSha256|savedAtUtc|EpicAccountId|LoginId|BEGIN (?:RSA |OPENSSH )?PRIVATE KEY') {
        throw "Private build data detected in $($file.Name)."
    }
}
Compress-Archive -LiteralPath @(Get-ChildItem -LiteralPath $folder -File | Select-Object -ExpandProperty FullName) -DestinationPath $zipPath -CompressionLevel Optimal
$archive = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    if (@(Compare-Object ($allowed | Sort-Object) (@($archive.Entries.FullName) | Sort-Object)).Count -ne 0) { throw 'Archive allowlist mismatch.' }
    foreach ($entry in $archive.Entries) {
        $stream = $entry.Open()
        $hasher = [Security.Cryptography.SHA256]::Create()
        try { $hash = [BitConverter]::ToString($hasher.ComputeHash($stream)).Replace('-', '') } finally { $hasher.Dispose(); $stream.Dispose() }
        if ($hash -cne (Get-FileHash -LiteralPath (Join-Path $folder $entry.FullName) -Algorithm SHA256).Hash) { throw "Archive hash mismatch: $($entry.FullName)" }
    }
} finally { $archive.Dispose() }
$zipSha = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText("$zipPath.sha256", "$zipSha  $name.zip`n", [Text.UTF8Encoding]::new($false))
Write-Output "Prepared local release: $zipPath"
Write-Output "SHA256: $zipSha"
Write-Output 'Nothing was uploaded or published.'
