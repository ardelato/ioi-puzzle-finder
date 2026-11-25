# Testing a release

Test the exact package hash recorded in the release manifest (`release.json`). Use the
full Offline Restored Mod v1.6, and note any additional map or HUD mods. Keep an
ordinary save backup before testing; no debug save or console command is needed.

## Gameplay checklist

- Start the game and open/close the map. Confirm normal map controls, hover
  banners, quest panels and the map legend remain usable.
- Open the **Puzzles** tab and tick several puzzle types, together and one at
  a time. Confirm each type's pins use its icon and **Clear** removes the
  addon pins. Change zone on the map and check the list, the *solved / total*
  counts and the pins follow it. Try the search box and **Hide completed**.
- Scroll the puzzle list to both ends. The wheel should stay with the list
  while the pointer is over it, without zooming the world map.
- Enable the compass, close the map and move/turn. Check F8, F3, F4 and F10,
  radius text, screen size and height carets. Open menus and a puzzle input
  screen; the compass should hide and resume appropriately.
- On the Compass tab, rebind Zoom in to another key and check it works in play.
  Pick a key another compass action uses: the two should swap and the note
  should say so. Esc while picking keeps the old key; **Reset** restores
  F8 / F3 / F4 / F10.
- Tick two types, change range, size and Hide completed, then quit and restart
  the game. Everything, including rebound keys, should come back.
- Place two custom map markers, one near and one far. The near one should show
  its icon at its position; the far one a rim arrow pointing at it with its
  distance, turning as you turn. Untick every type: the compass should stay up
  with only the markers. Remove the markers and check they leave the compass
  while it stays up.
- After a few seconds of play, open the map and tick a Hub type (e.g.
  Matchbox). Only currently active Hub puzzles should appear, with no brief
  burst of extra pins.
- Solve a visible puzzle. Its map and compass pins should disappear without a
  rebuild. For Matchboxes, both pins should disappear together.
- Activate a Hidden Pentad. Confirm the compass focuses on its relics. Collect
  one relic and check that only that relic disappears. Leave, complete or
  switch Pentads and check that focus changes correctly.
- Collect lore and a monolith fragment and check their markers disappear.
- Tick Logic Grid and switch on **Show random-spot puzzles**. After a few
  seconds, dashed **?** circles should appear on the map only where a Logic
  Grid is actually out, and the compass should show them with the Logic Grid
  icon. Walk to one and check a puzzle is there; solve it and check both pins
  go. Switch it off and check every **?** disappears.
- Play in sparse and dense areas at several zoom levels. Report stutters or
  delayed compass updates with location and type; do not infer an FPS improvement
  solely from the engine regression tests.
- Load another existing save and check marker visibility follows that save's
  progress, not the previous one's.
- Enter a Hub Crystal Labyrinth and a labyrinth in the Vault of the Vitric.
  The compass should show the current floor and *Floor N of M*, update as you
  change floors and return to normal when you leave.

## What has already been checked

The universal build passed source/data/package checks, isolated
fresh/mixed/completed progress tests, special collection lookups, current Hub
availability, bounded index processing and active Pentad collection/reset tests.
The data covers 293 Pentads and 1,465 relics; focused native collection tests used
five cooked Pentads and 25 relics. Those checks establish regression evidence,
not rendered FPS, every monitor layout or compatibility with every other mod.
The extracted repository's build and package checks are recorded separately for
the actual release artifact.

## Report a problem

Include the addon version and package SHA256, base-mod version, other installed
map/HUD mods, ticked puzzle types, compass radius/size, and clear reproduction
steps. For a marker issue, include the hover puzzle ID when available. For a UI
issue, include display resolution and UI scaling with a screenshot.

A short relevant log excerpt or crash message can help. Remove account IDs,
personal paths and tokens before sharing. Do not attach a save, full crash
directory or private build inputs to a public issue by default.

If the game cannot start, close it and remove only
your Puzzle Finder package from `IslandsofInsight/Content/Paks/`. The private beta 1
package was named `ZZ_SaveStatsMap_P.pak` and 0.1.0 `ZZ_IOIPuzzleRadar_P.pak`. Later
builds use `ZZ_IOIPuzzleFinder_P.pak`. Retest with the full base mod installed, and report
whether that resolves the crash.
