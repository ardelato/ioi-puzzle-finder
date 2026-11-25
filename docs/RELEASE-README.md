# Islands of Insight Puzzle Radar

Puzzle filters for the world map and a nearby-puzzle radar for the gameplay HUD.
Requires Islands of Insight on Windows and the full **Offline Restored Mod v1.6**,
installed from https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases/tag/v1.6.
SaveStats and build tools are not needed to play.

Project page, updates and bug reports: https://github.com/ardelato/ioi-puzzle-radar

## Install

1. Close the game. In Steam, choose **Islands of Insight → Manage → Browse local
   files**.
2. Move any previous Puzzle Radar package out of the game's `Paks` folder.
   Earlier test builds were named `ZZ_SaveStatsMap_P.pak`. Keep just one Puzzle
   Radar package installed.
3. Copy only `ZZ_IOIPuzzleRadar_P.pak` from this archive into the game's
   `IslandsofInsight/Content/Paks` folder:

   ```text
   IslandsofInsight/Content/Paks/ZZ_IOIPuzzleRadar_P.pak
   ```

   Keep the filename so this addon loads after the base mod. The README,
   notices and checksums can stay in the temporary folder.
4. Start the game, open the world map, then open the **Puzzles** tab in the map
   legend (lower right) and tick one or more puzzle types.

Keep all Offline Restored Mod files installed. To uninstall this addon, close
the game and remove `ZZ_IOIPuzzleRadar_P.pak` from the `Paks` folder.

## Use

The map legend has three tabs: **Legend** (the game's own), **Puzzles** and
**Radar**. **Puzzles** lists the puzzle types in the zone selected on the map,
with *solved / total* for each. Tick any number of types to pin them on the map;
hover a pin for its name and ID. Use the search box, **Clear** and **Hide
completed** to manage the list.

Leave **Show gameplay radar** on (Radar tab) and close the map to see the ticked
types on the radar in the lower-left corner. Your custom map markers always
appear on it.

| Default key | Action |
| --- | --- |
| F8 | Show/hide radar |
| F3 / F4 | Zoom in / out |
| F10 | Cycle display size |

To change a key, open the Radar tab, click the key next to the action and press
the new one; **Reset** restores the defaults. Avoid keys you use to play.

Your ticked types and radar settings are saved as you change them and restored
the next time you play. They are kept in `IOIPuzzleRadar.sav` in the game's
`Saved\SaveGames` folder, apart from your progress save; delete that file to
start over with the defaults.

The distance below the radar is its radius, not the distance to a particular
puzzle. Up/down carets mark points more than 10 metres above or below you.

Solved puzzle pins disappear from both views using live progress. Matchbox
pairs disappear together. An active Hidden Pentad focuses the radar on its
remaining relics. Only the Hub puzzles the game currently has active are shown.
Turn on **Show random-spot puzzles** in the Puzzles tab to also pin rotating
puzzles that land on a random spot each time, such as Logic Grids; they appear
a few seconds later, only where one is out right now.
Inside a Hub Crystal Labyrinth or the Vault of the Vitric, the radar draws the
floor you are on.

## Feedback

Other mods that replace the same map or gameplay HUD assets can conflict.
Compatibility with base-mod versions other than v1.6 has not been established.

For a bug report, include the addon version, package SHA256 from `release.json`,
base-mod version, other map/HUD mods, ticked puzzle types, location, steps to
reproduce, radar radius/size and resolution/UI scaling. Include the puzzle ID
from the hover label when available. Remove account IDs and personal paths from
logs; do not post save files or full crash directories.

See `NOTICE`, `THIRD_PARTY_NOTICES.md` and `LICENSE` for credits and provenance.
This addon is not affiliated with or endorsed by the game's developer or publisher.
