# Islands of Insight Puzzle Radar

`ioi-puzzle-radar` adds puzzle filters to the world map and a nearby-puzzle radar
to the gameplay HUD. It is an addon for the **Offline Restored Mod**, with its own
source repository and release package.

Current version: **0.1.0-beta.1**.

## Requirements

- Islands of Insight on Windows.
- The full [Offline Restored Mod v1.6](https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases/tag/v1.6),
  including its base package and DLL component, installed and working.

Players using a prebuilt beta do not need SaveStats, PHP, UAssetGUI or UnrealPak.
Compatibility with other base-mod versions has not been established. Changes to
the same map or gameplay HUD assets can conflict with this addon.

## Install or update

1. Close the game. In Steam, right-click **Islands of Insight → Manage → Browse
   local files**.
2. Extract the beta archive to a temporary folder.
3. If replacing an older addon package, keep a copy outside the game's `Paks`
   folder first.
4. Copy only `ZZ_SaveStatsMap_P.pak` from the extracted files into the game's
   `IslandsofInsight/Content/Paks` folder. Its final location should be:

   ```text
   IslandsofInsight/Content/Paks/ZZ_SaveStatsMap_P.pak
   ```

   Keep the package's existing filename so it loads after the base mod. The
   archive's README, notices and checksums can stay in the temporary folder.
5. Start the game, open the map, then open the **Puzzles** tab in the map
   legend (lower right) and select one or more puzzle types.

The addon does not replace the Offline Restored Mod's package, DLL or config.
To uninstall, close the game and remove only `ZZ_SaveStatsMap_P.pak`.

## Map and radar

The map legend in the lower right has three tabs: **Legend** (the game's own),
**Puzzles** and **Radar**.

**Puzzles** follows the zone selected on the map. Its header shows the zone and
how many puzzles are left there. The list shows only puzzle types found in that
zone; tick any number of them to show their pins (nothing is ticked at first),
type in the search box to narrow the list, and use **Clear** to untick them all.
Each row shows *solved / total* for the zone, or *done* when nothing is left.
Counts cover puzzles with a known location in the addon's catalog: fixed puzzles
plus the Hub puzzles the game currently has active. They fill in over a few
seconds after opening the map or changing zone. **Hide completed** removes
finished types from the list (ticked types stay so they can be unticked). Map
pins are limited to the selected zone. Hovering map markers shows their labels
and IDs.

**Radar** holds **Show gameplay radar**, the range and size choices, and the
shortcut keys. The gameplay radar shows the ticked types in the lower-left HUD
after closing the map, each pin with its type's icon. It hides during menus and
puzzle input. Settings last for the current game session; a new session starts
with nothing ticked.

| Shortcut | Action |
| --- | --- |
| F8 | Show or hide radar |
| F3 | Zoom in |
| F4 | Zoom out |
| F10 | Cycle radar display size |

The distance below the radar is the radius from your position to its outer ring,
not the distance to a particular puzzle. Zoom chooses 50, 100, 200 or 400 metres;
display size chooses 100%, 125% or 150%. Up/down carets indicate pins more than
10 metres above or below you. The radar displays up to 24 nearby points.

Solved puzzles disappear from both views using live game progress. Matchbox
pairs share one ID. Selecting **Hidden Pentad** shows unsolved pillars; activating
a Pentad focuses the radar on that puzzle's uncollected relics. Relics disappear
as you collect them. Fixed Hub puzzles also follow the game's current selection.

**Show possible sites** is optional and off by default. With it on, the list
also includes types that only have possible sites in the zone, and ticked types
show their eligible locations. These cyan
pins are potential sites, not confirmation that a puzzle is currently there.

## Beta limits

The location catalog is shared across players; visibility follows live progress.
It covers eligible SaveStats static and Hub metadata, without inventing positions
for puzzles that lack fixed coordinates. It does not assign variable puzzles to
spawn containers. Unknown progress or availability leaves pins visible until the
game supplies a result.

Dense radar searches can take several updates to settle. Engine regression checks
cover progress changes, current Hub availability and Pentad collection; rendered
FPS and compatibility with every HUD mod have not been measured.

For source builds, see [BUILDING](docs/BUILDING.md). For a beta test checklist and
useful bug-report details, see [BETA-TESTING](docs/BETA-TESTING.md).

## Credits

Built on the Offline Restored Mod and SaveStats work by Rushin, RiccTheThicc,
meltdown and the Islands of Insight community. Asset conversion uses UAssetGUI /
UAssetAPI by atenfyr. See [third-party notices](THIRD_PARTY_NOTICES.md) for source
and asset attribution. This addon is not affiliated with or endorsed by the
game's developer or publisher.
