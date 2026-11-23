# Islands of Insight Puzzle Radar

Adds puzzle-type map filters and a nearby-puzzle radar. Requires Islands of
Insight on Windows and the full **Offline Restored Mod v1.6**, installed from
https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases/tag/v1.6.
SaveStats and build tools are not needed to play.

## Install

1. Close the game. In Steam, choose **Islands of Insight → Manage → Browse local
   files**.
2. Extract this archive to a temporary folder.
3. Move any previous Puzzle Radar package outside the game's `Paks` folder.
   Beta 1 used `ZZ_SaveStatsMap_P.pak`. Keep just one Puzzle Radar package
   installed when upgrading.
4. Copy only `ZZ_IOIPuzzleRadar_P.pak` from the extracted files into the game's
   `IslandsofInsight/Content/Paks` folder. Its final location should be:

   ```text
   IslandsofInsight/Content/Paks/ZZ_IOIPuzzleRadar_P.pak
   ```

   Keep the existing filename so this addon loads after the base mod. The
   README, notices and checksums can stay in the temporary folder.
5. Start the game, open the world map, then open **Puzzle filters** in the
   lower-left corner and select one puzzle type.

Keep all Offline Restored Mod files installed. To uninstall this addon, close
the game and remove `ZZ_IOIPuzzleRadar_P.pak` from the game folder.

## Use

Select one puzzle type at a time; hover map markers for labels and puzzle IDs.
Leave **Show radar** enabled and close the map to see the radar in the lower-left
gameplay HUD. A new session starts without a selected puzzle type.

| Shortcut | Action |
| --- | --- |
| F8 | Show/hide radar |
| F3 / F4 | Zoom in / out |
| F10 | Cycle display size |

The distance below the radar is its radius, not the distance to a particular
puzzle. Up/down carets mark points more than 10 metres above or below you.

Solved puzzle pins disappear from both views using live progress. Matchbox
pairs disappear together. An active Hidden Pentad focuses the radar on its
remaining relics; collected relics disappear individually. Fixed Hub pins follow
the game's current puzzle selection.

**Include possible spawn sites** adds cyan potential locations for the selected
type. They do not confirm an active puzzle is there. Unknown progress leaves
pins visible until the game supplies a result. Dense radar searches can take
several updates to settle; at most 24 nearby points are displayed.

## Beta feedback

Other mods that replace the same map or gameplay HUD assets can conflict.
Compatibility with base-mod versions other than v1.6 has not been established.
Rendered FPS and all display layouts have not been measured.

For a bug report, include the addon version, package SHA256 from `release.json`,
base-mod version, other map/HUD mods, selected puzzle type, location, steps to
reproduce, radar radius/size and resolution/UI scaling. Include the marker ID
when available. Remove account IDs and personal paths from logs; do not post
save files or full crash directories by default.

See `NOTICE`, `THIRD_PARTY_NOTICES.md` and `LICENSE` for credits and provenance.
This addon is not affiliated with or endorsed by the game's developer or publisher.
