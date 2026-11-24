# Islands of Insight Puzzle Radar

Find nearby puzzles and track what’s left on the world map while you play.
Puzzle Radar is an addon for **Islands of Insight** on Windows with the full
**Offline Restored Mod v1.6** installed.

[Download the beta](https://github.com/ardelato/ioi-puzzle-radar/releases/tag/v0.1.0-beta.1) · [Build from source](docs/BUILDING.md) · [Beta test checklist](docs/BETA-TESTING.md) · [Contribute](CONTRIBUTING.md)

![Gameplay inside an enclave, with Matchbox pins on the radar in the lower-left corner](docs/images/radar-preview.png)

*Early gameplay capture showing Matchboxes on the radar in the lower-left corner.*

| In the world map | While exploring |
| --- | --- |
| Open the **Puzzles** tab in the map legend and tick any number of puzzle types. Hover a pin to see its name and ID. | Leave **Show gameplay radar** on and close the map to see nearby puzzles of the ticked types. |
| The list follows the selected zone and shows *solved / total* for each type. Solved puzzles disappear as you play. | Activate a Hidden Pentad to focus the radar on its remaining relics. |

## Install

1. Install the full [Offline Restored Mod v1.6](https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases/tag/v1.6), including its runtime components.
2. Close the game. In Steam, open **Islands of Insight → Manage → Browse local files**.
3. Extract the release ZIP. Move any previous Puzzle Radar package outside `IslandsofInsight/Content/Paks/` and copy the single `.pak` from the ZIP into that folder.
4. Start the game, open the map, then open the **Puzzles** tab in the map legend (lower right) and tick one or more puzzle types.

The published `0.1.0-beta.1` archive contains `ZZ_SaveStatsMap_P.pak`.
Builds from this source tree produce `ZZ_IOIPuzzleRadar_P.pak`.
Keep only one of these files in `Paks`. To uninstall, close the game and
remove your Puzzle Radar package from that folder.

## Map panel

The map legend in the lower right has three tabs: **Legend** (the game's own),
**Puzzles** and **Radar**. The map ignores the cursor while it is over the panel.

**Puzzles** follows the zone selected on the map. Its header shows the zone and
how many puzzles are left there. The list shows only puzzle types found in that
zone, sorted by name; nothing is ticked at first. Type in the search box to
narrow the list, and use **Clear** to untick everything. Each row shows
*solved / total* for the zone, or *done* when nothing is left. Counts cover
fixed puzzles plus the Hub puzzles the game currently has active, and fill in
over a few seconds after opening the map or changing zone. **Hide completed**
removes finished types from the list; ticked types stay so they can be
unticked. Map pins are limited to the selected zone.

**Show possible sites** also lists types that only have possible spawn sites
in the zone and shows those sites for ticked types. Treat these cyan pins as
search areas, not confirmation that a puzzle is there.

**Radar** holds **Show gameplay radar**, the range and size choices, and the
shortcut keys.

## Radar controls

| Default key | Action |
| --- | --- |
| **F8** | Show or hide the radar |
| **F3** | Zoom in |
| **F4** | Zoom out |
| **F10** | Cycle the display size |

To change a key, open the map's **Radar** tab, click the key next to the
action and press the new one. **Esc** cancels; mouse buttons and Shift, Ctrl,
Alt or Cmd on their own are not accepted. Choosing a key another radar action
already uses swaps the two. **Reset** restores the defaults. The radar does
not check the game's own bindings, so avoid keys you use to play.

The radar shows the ticked types, each pin with its type's icon. The distance
under the radar is its radius. Up and down carets mark puzzles more than
10 metres above or below you. Your custom map markers always appear: within
range at their position, farther away as a cyan arrow on the rim with their
distance. While enabled, the radar stays up even when no puzzle type is
ticked. The ticked types, **Show possible sites**, **Hide completed**, the
radar switch, range, size and keys are saved as you change them and restored
the next time you play. They are kept in `IOIPuzzleRadar.sav` in the game's
`Saved\SaveGames` folder, apart from your progress save; delete that file to
start over with the defaults. The radar hides during menus and puzzle
input. Fixed puzzle pins follow live progress and clear when the game records
a solve.

Inside a Crystal Labyrinth, the radar shows the floor you are on: white lines
for solid walls, blue lines for glass, red squares for gaps in the floor you can
fall through, and **^** / **v** where a passage (in the Vault of the Vitric, a
jump pad and the shaft above it) leads to the floor above or below. The radar
zooms to fit the labyrinth and shows *Floor N of M* under the ring, also for
labyrinths you have already solved, and returns to normal when you leave. Hub
labyrinths and the seven in the Vault of the Vitric are drawn; the other
handmade labyrinths are not yet.

## Compatibility and feedback

The release ZIP includes everything needed for the addon once the full base
mod is installed. Other mods that replace the world map or gameplay HUD can
conflict. For beta reports, include the package version, ticked puzzle types,
and steps to reproduce. [Open an issue](https://github.com/ardelato/ioi-puzzle-radar/issues) · [Testing checklist](docs/BETA-TESTING.md)

Thanks to Rushin, RiccTheThicc, meltdown, and the Islands of Insight community
for the Offline Restored Mod and SaveStats. See the
[third-party notices](THIRD_PARTY_NOTICES.md) for credits and asset provenance.
