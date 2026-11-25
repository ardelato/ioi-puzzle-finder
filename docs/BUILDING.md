# Build from source

The source repository contains addon code, tests and notices. Game assets, player
saves, tool binaries and SaveStats distributions are external inputs. A release
build does not read a player save or need a SaveStats progress report.

## Prepare inputs

1. Install Islands of Insight and the full Offline Restored Mod v1.6. Record the
   game installation folder; the builder reads its `RRMOD_PuzzleFix.pak`.
2. Obtain SaveStats 1.3.1 from the
   [Offline Restored Mod releases](https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases).
   Supply the extracted `SaveStats` folder, containing `include`, `media/data`
   and `media/img/icons`. Run no SaveStats report generation for this build.
3. Supply PHP 8.3 with GD enabled, PowerShell 7, a compatible UAssetGUI executable
   and UE4.27 UnrealPak. Development used PHP 8.3.10 and the upstream
   `UAssetGUI_103.exe`; other versions require their own conversion checks.
4. Prepare the ignored local asset-input directory from the supported Offline
   Restored development checkout:

   ```powershell
   .\scripts\prepare-inputs.ps1 `
     -OfflineRestoredSourceDir 'D:\Modding\RRMod_PuzzleFix' `
     -HudBaselineJson 'D:\Modding\verified-baselines\WBP_GameHUD.json'
   ```

   Its default output is `local-inputs`, containing:

   ```text
   MapMarker.uasset
   MapMarker.uexp
   WBP_GameHUD.json
   ```

   `MapMarker` is the upstream BGRA texture template. `WBP_GameHUD.json` must be
   the unmodified supported gameplay HUD exported using UAssetGUI with
   `VER_UE4_27`. Keep these inputs and extracted asset data outside tracked source.
   The development checkout's verified HUD baseline is suitable for the tested
   base version; do not substitute a HUD already patched by this addon. The
   upstream source checkout alone does not contain that HUD baseline.

   Alternatively, supply `-GamePak`, `-CryptoKeysPath`, `-UnrealPakPath` and
   `-UAssetGuiPath` to `prepare-inputs.ps1` instead of `-HudBaselineJson` to extract
   only the HUD from your local game archive. Keep the keys file local and out
   of source control. The script checks the three supported input hashes; do
   not weaken those checks for an unreviewed asset version.

Use trusted local tools obtained from their respective projects. Tool binaries,
game archives, extracted assets, encryption material and SaveStats installations
are not part of the addon source or release zip. See
[third-party notices](../THIRD_PARTY_NOTICES.md).

## Build

From the addon repository root:

```powershell
.\build.ps1 `
  -SaveStatsDir 'D:\Modding\SaveStats' `
  -GameDir 'D:\SteamLibrary\steamapps\common\Islands of Insight' `
  -PhpPath 'D:\Tools\php\php.exe' `
  -UAssetGuiPath 'D:\Tools\UAssetGUI_103.exe' `
  -UnrealPakPath 'D:\Tools\UnrealPak\Engine\Binaries\Win64\UnrealPak.exe' `
  -AssetInputDir '.\local-inputs'
```

The default universal mode includes the complete eligible catalog and uses live
game progress at runtime. `-Limit` produces a developer smoke build and must stay
zero for a release package. Snapshot mode is for local investigations only; its
player-specific output is not suitable for distribution.

Handmade Crystal Labyrinth floors are baked from the game itself: on the first
build (and whenever the game, the base mod or the bake code changes) the builder
runs the game once, headless, with an isolated profile under the output
directory, to read the labyrinth levels. Close the game before that build. Only
the launch settings (`GameUserSettings.ini`, `Launch.sav`, `LocalPersistence.sav`)
are copied into the isolated profile; the progress save and the installed files
are not touched. The result is cached as `labyrinth-layouts.json` in the asset
input directory and reused by later builds without launching the game. Pass
`-SkipLabyrinthBake` to build without handmade labyrinth floors.

The builder writes to a new output directory, verifies native serialization,
original map/HUD functions, coverage and package entries, and produces
`ZZ_IOIPuzzleRadar_P.pak` with local build evidence. It does not install files into
the game. Keep local logs, response files, raw manifests and decoded assets out
of public release archives: they may contain absolute paths or game data.

## Check the source

```powershell
.\scripts\test.ps1 -PhpPath 'D:\Tools\php\php.exe'
```

The public test gate uses synthetic data and runs without a game installation,
SaveStats, a save file or asset templates. For local integration checks, provide
an external test-data directory with these unmodified baseline exports:

```text
BaseJsons/WBP_LargeMap.json
BaseJsons/WBP_GameHUD.json
OutputJsons/Maps/RRMod_Objects.json
```

```powershell
.\scripts\test.ps1 -PhpPath 'D:\Tools\php\php.exe' `
  -Integration -TestDataDir 'D:\Modding\ioi-test-data' `
  -UAssetGuiPath 'D:\Tools\UAssetGUI_103.exe'
```

Keep those game-data fixtures outside tracked source. Engine checks run in
isolated profiles against copied saves; never point test fixtures at a live save
or use an installed archive as a generated output path.

## Package a release

The packager reads the version from `VERSION`:

```powershell
.\scripts\package-release.ps1 -BuildDirectory '.\output\build-EXAMPLE'
```

The packager writes to `dist` and includes only the verified universal `.pak`,
user installation instructions, changelog, license and
notices, sanitized `release.json` and `SHA256SUMS.txt`. The manifest records the
addon version, package hash and
tested base-mod identity without local paths or save provenance.

Follow [TESTING](TESTING.md), review the exact package, then create the
GitHub release as a separate publishing step with `scripts/publish-release.ps1`;
see [RELEASING](RELEASING.md). It only creates a draft, so unreviewed local
files are never published by a script alone.

## Package filename

`ZZ_SaveStatsMap_P.pak` was the prototype name. It described the original
feature of showing SaveStats puzzle locations on the world map. The private
beta 1 shipped with that filename. `ZZ_IOIPuzzleRadar_P.pak` names the addon and
its gameplay radar directly.

The `ZZ_` prefix keeps the filename sorted after `RRMOD_PuzzleFix.pak`, as with
the original overlay. The `_P` suffix follows
[Unreal Engine's patch-package convention](https://dev.epicgames.com/documentation/en-us/unreal-engine/patching-overview?application_version=4.27).
Move the old package outside the game's `Paks` folder when upgrading, so only
one version of the overlay can load.
