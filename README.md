<div align="center">

# Islands of Insight Puzzle Radar

**Find nearby puzzles and track what's left, right on the world map and in your HUD.**

An addon for **Islands of Insight** (Windows) with the
[Offline Restored Mod v1.6](https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases/tag/v1.6).

[![Latest release](https://img.shields.io/github/v/release/ardelato/ioi-puzzle-radar?include_prereleases&label=download)](https://github.com/ardelato/ioi-puzzle-radar/releases)
[![Tests](https://github.com/ardelato/ioi-puzzle-radar/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/ardelato/ioi-puzzle-radar/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/code-MIT-blue)](LICENSE)

[**Download**](https://github.com/ardelato/ioi-puzzle-radar/releases) ·
[Install](#install) ·
[Controls](#the-radar) ·
[Report a bug](https://github.com/ardelato/ioi-puzzle-radar/issues/new?template=bug_report.md)

![Exploring a forest under a pink sky, with the Puzzle Radar in the lower-left corner showing Hidden Cube and Hidden Ring pins and three custom map markers on its rim](docs/images/hero.jpg)

<sub>Hidden Cubes and Rings nearby, and three of your own map markers on the rim with their distances.</sub>

</div>

## Why I built this

Finishing Islands of Insight meant hunting down whatever I'd missed, and the
only way to see that was [SaveStats](https://github.com/RiccTheThicc/RRMod_PuzzleFix/tree/main/SaveStats_src),
the community tool that reads your save and draws your unsolved puzzles on a
map image. So my evenings went like this: run the script, open the image, line
it up with the in-game map, then wander around trying to find the spot.

Puzzle Radar puts that map **inside the game**. It adds puzzle filters to the
world map and a radar to your HUD, and reads your progress live: solve a
puzzle and its pin is gone. From there it grew into the parts of the game
that frustrated me most, **Hidden Pentads** and **Crystal Labyrinths**, and
more helpers are on the way.

## Features

- 🗺️ **Puzzle filters on the map.** A **Puzzles** tab in the map legend lists
  every puzzle type in the zone you're looking at, with how many you've solved.
  Tick any number of types to pin them on the map; hover a pin to see what it is.
- 📡 **A radar in your HUD.** Close the map and the ticked types follow you,
  each with its own icon. **^** and **v** mark puzzles above or below you.
- ✅ **Solved means gone.** Pins disappear as soon as the game counts a puzzle
  as solved. Rotating puzzles only show while the game has them out.
- 🎲 **Random-spot puzzles too.** Some rotating puzzles, like Logic Grids, land
  on a different spot each time. Switch on **Show random-spot puzzles** and
  they're pinned where one actually is right now.
- ⭐ **Hidden Pentad helper.** Start a Pentad and the radar shows just its
  remaining relics.
- 🔷 **Crystal Labyrinth floors.** Inside a labyrinth, the radar draws the floor
  you're on.
- 🧭 **Your own map markers** always show on the radar, with the distance to
  far-away ones.
- ⌨️ **Your keys, your settings.** Rebind the radar keys, and everything you
  pick is remembered next time you play.

### On the map

<div align="center">
  <img src="docs/images/map-puzzles-right.jpg" alt="The world map of Verdant Glen with Hidden Cube, Pentad and Ring pins, and the Puzzles tab listing the zone's types with solved counts" width="600">
</div>

### On the radar

| Ticked puzzle types | Your map markers |
| :---: | :---: |
| <img src="docs/images/radar-types.jpg" alt="The radar with Hidden Cube and Ring icons and ^ / v height marks" width="220"> | <img src="docs/images/radar-markers.jpg" alt="The radar with three cyan arrows on its rim labelled 1.0 km, 960 m and 762 m, plus puzzle pins inside the ring" width="220"> |

### Crystal Labyrinths

<div align="center">
  <img src="docs/images/labyrinth-left.jpg" alt="Standing on the second floor of a Crystal Labyrinth among glass walls. In the lower-left corner the radar draws this floor's walls, with two reddish cells for trap doors, and reads Floor 2 of 3" width="600">
  <br>
  <sub>Floor 2 of 3. The reddish cells on the radar are trap doors: holes in the floor you'd fall through.</sub>
</div>

Inside a Crystal Labyrinth the radar switches to a floor plan of the level you
are on and zooms to fit it:

| Mark | Meaning |
| --- | --- |
| White lines | Solid walls |
| Light-blue lines | Glass walls |
| Reddish squares | Trap doors: holes in the floor you can fall through |
| **^** / **v** | A way to the floor above or below (in the Vault of the Vitric, a jump pad and the shaft above it) |

*Floor N of M* appears under the ring, also in labyrinths you've already
solved. Hub labyrinths and the seven in the Vault of the Vitric are drawn; the
other handmade labyrinths aren't yet.

### Hidden Pentad

| Before you start | Started: five relics | Two found: three left |
| :---: | :---: | :---: |
| <img src="docs/images/pentad-1.jpg" alt="Radar before the Pentad starts" width="180"> | <img src="docs/images/pentad-2.jpg" alt="Radar after starting: five relics" width="180"> | <img src="docs/images/pentad-3.jpg" alt="Radar after two relics found: three left" width="180"> |

## Install

**You need:** Islands of Insight on Windows with the **full**
[Offline Restored Mod v1.6](https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases/tag/v1.6)
installed, including its runtime components. Nothing else: no PHP, SaveStats or
build tools.

1. Download the latest `ioi-puzzle-radar-<version>.zip` from
   [**Releases**](https://github.com/ardelato/ioi-puzzle-radar/releases).
2. Close the game. In Steam, right-click **Islands of Insight** and choose
   **Manage → Browse local files**.
3. Extract the ZIP to a temporary folder and copy **only** the `.pak` file into:

   ```text
   IslandsofInsight/Content/Paks/
   ```

   Keep its filename. The `ZZ_` prefix makes it load after the base mod.
4. Start the game, open the map, then open the **Puzzles** tab in the map legend
   (lower right) and tick one or more puzzle types.

**Updating:** close the game and copy the new `.pak` over the old one.

**Uninstalling:** close the game and remove the Puzzle Radar `.pak` from `Paks`.
The base mod and your save are left untouched.

> [!IMPORTANT]
> Only download Puzzle Radar from this repository's
> [Releases](https://github.com/ardelato/ioi-puzzle-radar/releases) page. A copy
> from anywhere else may have been changed. Each release also lists a SHA-256
> checksum, for anyone who wants to confirm the file is exactly what was published.

## Using it

### The map panel

The map legend in the lower right has three tabs: **Legend** (the game's own),
**Puzzles** and **Radar**. The map ignores the cursor while it's over the panel.

**Puzzles** follows the zone selected on the map:

- The header shows the zone and how many puzzles are left there.
- The list shows the puzzle types found in that zone, sorted by name, with
  *solved / total* for each, or *done* when nothing is left. Counts fill in
  over a few seconds after opening the map or changing zone.
- Type in the search box to narrow the list; **Clear** unticks everything.
- **Hide completed** removes finished types from the list. Ticked types stay so
  you can untick them.
- **Show random-spot puzzles** also pins rotating puzzles that the game puts on
  a random spot each time, such as Logic Grids. A pin appears only where a
  puzzle of a ticked type is out right now, starting a few seconds after you
  switch it on. On the map these pins are a dashed circle marked **?**.

**Radar** holds **Show gameplay radar**, the range (50 to 400 m) and size
choices, and the radar keys.

| The Puzzles tab | The Radar tab |
| :---: | :---: |
| <img src="docs/images/puzzles-tab.jpg" alt="The Puzzles tab for Verdant Glen: 409 left in this zone, a search box, the puzzle types with solved counts and three of them ticked, and the switches below" width="300"> | <img src="docs/images/radar-tab.jpg" alt="The Radar tab: Show gameplay radar, range and size choices, and the Keys section with F8, F3, F4 and F10 and a Reset button" width="300"> |

### The radar

| Default key | Action |
| :---: | --- |
| <kbd>F8</kbd> | Show or hide the radar |
| <kbd>F3</kbd> | Zoom in |
| <kbd>F4</kbd> | Zoom out |
| <kbd>F10</kbd> | Cycle the display size |

**To change a key**, open the map's **Radar** tab, click the key next to the
action and press the new one. **Esc** cancels; mouse buttons and Shift, Ctrl,
Alt or Cmd on their own aren't accepted. Choosing a key another radar action
already uses swaps the two, and **Reset** restores the defaults. The radar
doesn't check the game's own bindings, so avoid keys you use to play.

- The number under the radar is its **range**, not the distance to a puzzle.
- **^** / **v** mark puzzles more than 10 metres above or below you.
- Your custom map markers always appear: in range at their position, farther
  away as a cyan arrow on the rim with their distance.
- The radar hides during menus and puzzle input, and comes back afterwards.

### Saved settings

Your ticked types, **Hide completed**, **Show random-spot puzzles**, the radar
switch, range, size and keys
are saved as you change them and restored the next time you play. They live in
`IOIPuzzleRadar.sav` in the game's `Saved\SaveGames` folder, apart from your
progress save. Delete that file to start over with the defaults.

## Questions

<details>
<summary>Does it change my save?</summary>

No. Puzzle Radar only reads your progress so it can hide solved puzzles. Its
own settings go in a separate `IOIPuzzleRadar.sav`. Removing the `.pak` puts
the game back exactly as it was.

</details>

<details>
<summary>Why don't I see every rotating (Hub) puzzle?</summary>

Many puzzles rotate: the game brings them out for a while, then swaps them for
others. Rotating puzzles that always appear in the same place get a pin, but
only while the game has them out.

Others, like Logic Grids, are placed on a random spot each time. Switch on
**Show random-spot puzzles** in the Puzzles tab to pin those too. The addon
looks up where the game put them as you play, so their pins take a few seconds
to appear and empty spots stay hidden.

</details>

<details>
<summary>The Puzzles tab doesn't appear</summary>

Check that the `.pak` is directly in `IslandsofInsight/Content/Paks/` (not in a
subfolder), that its filename still starts with `ZZ_`, and that the full Offline
Restored Mod v1.6 is installed.

</details>

<details>
<summary>The game won't start after installing</summary>

Close the game and remove the Puzzle Radar `.pak` from `Paks`. Make sure only
one Puzzle Radar package was installed and that the base mod is complete, then
[open an issue](https://github.com/ardelato/ioi-puzzle-radar/issues/new?template=bug_report.md)
saying whether removing the addon fixed it.

</details>

## Compatibility

- Built and tested against the **Offline Restored Mod v1.6**. Other base-mod
  versions are untested.
- Other mods that replace the world map or the gameplay HUD can conflict.
- At most 24 nearby points are drawn on the radar at once; very dense areas may
  take a few updates to settle.

## Reporting a bug

[Open an issue](https://github.com/ardelato/ioi-puzzle-radar/issues/new?template=bug_report.md)
with the addon version, the ticked puzzle types, where you were and the steps
to reproduce. A screenshot helps for anything visual. Please remove account
IDs and personal paths from logs, and don't attach save files.

## For developers

The addon is generated rather than hand-made. Islands of Insight runs on Unreal
Engine 4.27, and modders don't have the game's editor project, so a PHP and
PowerShell pipeline does the editor's job:

1. **Extract** the world map, HUD and puzzle database from the installed mod
   with UnrealPak, and decode them to JSON with UAssetGUI.
2. **Catalog** every puzzle location by combining SaveStats' data with the
   game's puzzle database.
3. **Generate** the map panel and radar as new Blueprint classes, writing their
   logic directly as Kismet bytecode, plus map markers and icon textures.
4. **Verify** by re-encoding everything, decoding it again and checking it
   matches what was generated, with the game's own functions left unchanged.
5. **Pack** a `.pak` that asks the game's own progression system what's solved
   and what's out, live.

- [docs/BUILDING.md](docs/BUILDING.md): building from your own game install.
  The synthetic test suite runs without any game files: `.\scripts\test.ps1`.
- [docs/RELEASING.md](docs/RELEASING.md): how releases are packaged and published.
- [CONTRIBUTING.md](CONTRIBUTING.md): issues and pull requests.

## Credits and license

Thanks to Rushin, [RiccTheThicc](https://github.com/RiccTheThicc), meltdown and
the Islands of Insight community for the
[Offline Restored Mod](https://github.com/RiccTheThicc/RRMod_PuzzleFix) and
[SaveStats](https://github.com/RiccTheThicc/RRMod_PuzzleFix/tree/main/SaveStats_src),
which this addon builds on.

The source code is [MIT licensed](LICENSE). Game assets keep their owners'
rights; see [NOTICE](NOTICE) and the
[third-party notices](THIRD_PARTY_NOTICES.md) for credits and asset provenance.

*Not affiliated with or endorsed by the game's developer or publisher.*
