#requires -Version 7.0
<#
.SYNOPSIS
Uploads a packaged release from dist/ to GitHub as a draft release.

.DESCRIPTION
Run after scripts/package-release.ps1. The script checks the archive against its
.sha256 file, builds release notes from the CHANGELOG section for VERSION and
creates a draft GitHub release tagged v<VERSION> at the current commit. Nothing
is public until a maintainer reviews the draft and publishes it.

With -NotesOnly it only writes the release notes and touches neither git nor
GitHub.
#>
[CmdletBinding()]
param(
    [string]$DistDir = "$(Split-Path $PSScriptRoot -Parent)/dist",
    [switch]$NotesOnly,
    [string]$NotesPath
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$version = (Get-Content -Raw -LiteralPath (Join-Path $repo 'VERSION')).Trim()
if ($version -cnotmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$') { throw "VERSION is not a SemVer: $version" }
$tag = "v$version"
$prerelease = $version.Contains('-')
$name = "ioi-puzzle-finder-$version"
$zip = Join-Path ([IO.Path]::GetFullPath($DistDir)) "$name.zip"
$zipChecksum = "$zip.sha256"
foreach ($file in @($zip, $zipChecksum)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing $file. Run scripts/package-release.ps1 first." }
}
$zipSha = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
if ((Get-Content -Raw -LiteralPath $zipChecksum).Trim() -cne "$zipSha  $name.zip") { throw 'The archive does not match its .sha256 file.' }

# Release notes: the CHANGELOG section for this version, plus install and checksum details.
$changelog = Get-Content -LiteralPath (Join-Path $repo 'CHANGELOG.md')
$start = -1
for ($i = 0; $i -lt $changelog.Count; $i++) {
    if ($changelog[$i] -cmatch "^## $([regex]::Escape($version))(?:\s|$)") { $start = $i; break }
}
if ($start -lt 0) { throw "CHANGELOG.md has no '## $version' section." }
$heading = $changelog[$start]
$section = [Collections.Generic.List[string]]::new()
for ($i = $start + 1; $i -lt $changelog.Count -and $changelog[$i] -notmatch '^## '; $i++) { $section.Add($changelog[$i]) }
$changes = ($section -join "`n").Trim()
if ($changes -eq '') { throw "CHANGELOG.md section for $version is empty." }
$compatibility = Get-Content -Raw -LiteralPath (Join-Path $repo 'compatibility.json') | ConvertFrom-Json
$baseVersion = $compatibility.offlineRestoredVersion
$notes = @"
Puzzle filters for the world map and a nearby-puzzle compass for **Islands of Insight**, an addon for the full [Offline Restored Mod v$baseVersion]($($compatibility.offlineRestoredSource)/releases/tag/v$baseVersion).

## What's new

$changes

## Install

1. Install the full Offline Restored Mod v$baseVersion, including its runtime components.
2. Close the game and download ``$name.zip`` below.
3. Move any previous ``.pak`` of this addon (0.1.0 was ``ZZ_IOIPuzzleRadar_P.pak``) out of ``IslandsofInsight/Content/Paks/``, then copy only ``ZZ_IOIPuzzleFinder_P.pak`` from the ZIP into that folder.
4. Start the game, open the map and use the **Puzzles** tab in the map legend.

See the [README](https://github.com/ardelato/ioi-puzzle-finder#readme) for usage, controls and troubleshooting.

## Verify the download

``$name.zip`` SHA-256:

``````text
$zipSha
``````

The ``.zip.sha256`` asset verifies the archive; ``SHA256SUMS.txt`` inside it verifies the extracted files.
"@
$notesFile = if ($NotesPath) { [IO.Path]::GetFullPath($NotesPath) } else { Join-Path ([IO.Path]::GetTempPath()) "ioi-release-notes-$([Guid]::NewGuid().ToString('N')).md" }
if ($NotesOnly -and -not $NotesPath) { Write-Output $notes; return }
[IO.File]::WriteAllText($notesFile, $notes + "`n", [Text.UTF8Encoding]::new($false))
if ($NotesOnly) { return }

# Publishing checks: an exact, clean, pushed commit and a dated CHANGELOG entry.
try {
    if ($heading -match '(?i)unreleased') { throw "Date the CHANGELOG heading before publishing: '$heading'." }
    $gh = (Get-Command -Name 'gh' -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
    $status = git -C $repo status --porcelain
    if ($LASTEXITCODE -ne 0) { throw 'git status failed.' }
    if ($status) { throw 'The working tree has uncommitted changes. Publish from a clean, reviewed commit.' }
    $commit = git -C $repo rev-parse HEAD
    if ($LASTEXITCODE -ne 0 -or -not $commit) { throw 'git rev-parse HEAD failed.' }
    git -C $repo fetch --quiet origin
    if ($LASTEXITCODE -ne 0) { throw 'git fetch failed.' }
    $onOrigin = git -C $repo branch -r --contains $commit --list 'origin/*'
    if ($LASTEXITCODE -ne 0) { throw 'git branch --contains failed.' }
    if (-not $onOrigin) { throw "Commit $commit is not on origin. Push it (normally via main) first." }
    $existingTag = git -C $repo ls-remote --tags origin "refs/tags/$tag"
    if ($LASTEXITCODE -ne 0) { throw 'git ls-remote failed.' }
    if ($existingTag) { throw "Tag $tag already exists on origin." }

    $arguments = @('release', 'create', $tag, $zip, $zipChecksum, '--draft', '--target', $commit, '--title', $version, '--notes-file', $notesFile)
    if ($prerelease) { $arguments += '--prerelease' }
    $url = & $gh @arguments
    if ($LASTEXITCODE -ne 0) { throw 'gh release create failed.' }
} finally {
    if (-not $NotesPath) { Remove-Item -LiteralPath $notesFile -Force -ErrorAction SilentlyContinue }
}
Write-Output "Draft release created: $url"
Write-Output "Review it, then publish on GitHub or run: gh release edit $tag --draft=false"
