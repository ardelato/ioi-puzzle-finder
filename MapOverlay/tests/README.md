# Tests

Three kinds of files live here.

## Synthetic regression tests

`*_test.php` files that `scripts/test.ps1` runs by default, locally and in CI.
They need only PHP 8.3 with GD: no game, saves or asset templates.

## Integration tests

`filter_widget_test.php`, `radar_hud_test.php` and `../test_inject_markers.php`
run with `scripts/test.ps1 -Integration -TestDataDir <dir>`. They read unmodified
baseline exports of the game's map and HUD widgets; see
[docs/BUILDING.md](../../docs/BUILDING.md#check-the-source).
`pentad_metadata_test.php` is run by hand against a SaveStats install
(`--savestats-dir`).

## Engine probes and fixtures

`*_probe.php` and `*_fixture.php` generate test-only Blueprint assets
(`BP_*Test.json` plus a `fixture.json` of expected results) that are cooked and
run inside the real game to check behaviour the synthetic tests cannot: native
progress lookups, Hub availability, Pentad state, compass timers and shortcuts,
Crystal Labyrinth layouts. Each is a CLI script; run it without arguments to see
the options it needs. They mutate only the test process's memory and must be
run against an isolated game profile, never a live save. They are developer
tools and not part of the automated suite. `paths.php` resolves the external
test-data directory they share with the integration tests.
