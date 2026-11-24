# Changelog

## 0.1.0-beta.2 (unreleased)

- Rename the addon package to `ZZ_IOIPuzzleRadar_P.pak`. Move the beta 1 package
  out of the game's `Paks` folder before installing the renamed package.
- Move puzzle filters into the map legend as **Legend / Puzzles / Radar** tabs,
  replacing the lower-left panel and its separate toggle. The map no longer
  reacts to the cursor while it is over the panel.
- Select any number of puzzle types; none are selected by default. The radar
  shows every selected type with per-type pin icons.
- Follow the map's selected zone: list only the types found there, show
  *solved / total* per type (fixed plus currently active Hub puzzles), and
  query map pins for that zone only.
- Add a search box and a **Hide completed** switch; list types by their
  displayed name.
- Restyle the panel: solid backdrop, gold diamond checkboxes, switches, game-
  style buttons and range / size choices on the Radar tab.
- Rebind the radar keys on the Radar tab: click a key and press a new one.
  A key another radar action uses is swapped; **Reset** restores F8 / F3 /
  F4 / F10.
- Save settings between sessions: ticked types, Hide completed, Show
  random-spot puzzles, the radar switch, range, size and keys are kept in their own `IOIPuzzleRadar.sav`
  slot (never the progress save) and restored at start.
- Replace **Show possible sites** with **Show random-spot puzzles**. Instead
  of every spot a random-spot Hub puzzle could use, most of them empty, it pins
  only the spots where the game has a live, unsolved puzzle of a ticked type,
  on the map and the radar. Pins appear after a few seconds and clear when the
  puzzle is solved.
- Show your custom map markers on the radar whatever types are selected: the
  map marker icon within range, otherwise a cyan rim arrow with the distance.
  An enabled radar now stays on screen with no puzzle type ticked.
- Stop inactive Hub puzzles flashing on the map after ticking a type: Hub
  availability is now tracked for every Hub type in the background instead of
  restarting on each selection change.
- Show the current floor of a Hub Crystal Labyrinth on the radar: solid and
  glass walls, gaps in the floor, passages up and down, and *Floor N of M*.
  The radar fits the labyrinth while you are inside, also for labyrinths
  already solved. The seven handmade labyrinths in the Vault of the Vitric are
  drawn too, with jump pads as the passages up and down; other handmade
  labyrinths are not yet.

## 0.1.0-beta.1

First private beta.

- Add world-map filters with puzzle icons and hover labels; select one puzzle
  type at a time.
- Add a gameplay radar with zoom, display-size controls and F3/F4/F8/F10 shortcuts.
- Read live completion so solved puzzle pins disappear without rebuilding,
  including collected lore and monolith fragments.
- Follow the game's current fixed Hub puzzle selection.
- Focus active Hidden Pentads on their uncollected relics.
- Offer optional possible spawn-site pins for the selected type.
- Build a universal location catalog without a player save.
- Separate addon source and packaging from the Offline Restored Mod checkout.

Requires the full Offline Restored Mod v1.6. Variable-location puzzles are not
assigned to potential spawn sites. Rendered performance and compatibility with
other map/HUD mods still need beta gameplay feedback.
