# Releasing

The addon `.pak` is built from the maintainer's own game install and base-mod
files, so releases are built and packaged locally, not in GitHub Actions.
Publishing is a separate, reviewed step: the scripts below only ever create a
**draft** release.

## 1. Prepare the version

1. Set the new version in `VERSION` (for example `0.1.1`, or `0.2.0-beta.1`
   for a test build).
2. Give it a section in `CHANGELOG.md` headed `## <version> (YYYY-MM-DD)`. The
   publish script uses that section as the release notes and refuses a heading
   that still says *unreleased*.
3. Update `docs/RELEASE-README.md` if installation or usage changed. It ships
   inside the ZIP as `README.md`.
4. Merge to `main` and let the [Tests](../.github/workflows/tests.yml) workflow
   pass.

## 2. Build and package

From a clean checkout of that commit, follow [BUILDING.md](BUILDING.md) to
build, then package the verified build:

```powershell
.\build.ps1 -SaveStatsDir ... -GameDir ... -AssetInputDir .\local-inputs ...
.\scripts\package-release.ps1 -BuildDirectory .\output\build-<timestamp>
```

This writes `dist/ioi-puzzle-finder-<version>.zip` and its `.zip.sha256`. Install
the `.pak` from that ZIP and run through [TESTING.md](TESTING.md).

## 3. Create the draft release

Preview the notes first:

```powershell
.\scripts\publish-release.ps1 -NotesOnly
```

Then create the draft (requires the [GitHub CLI](https://cli.github.com/),
signed in with `gh auth login`):

```powershell
.\scripts\publish-release.ps1
```

The script checks that the working tree is clean, the commit is on `origin`,
the tag `v<version>` does not exist yet and the ZIP still matches its checksum.
It then creates a draft release targeting the current commit, attaches the ZIP
and `.zip.sha256`, and marks versions with a `-` suffix (such as `-beta.1`) as
pre-releases.

## 4. Publish

Open the draft on GitHub, check the notes and assets, and press **Publish
release**, or run:

```powershell
gh release edit v<version> --draft=false
```

GitHub creates the `v<version>` tag when the release is published. The README's
download links point at the Releases page, so nothing else needs updating.

## Screenshots

The README's screenshots are in `docs/images/`. Take them with Steam's
screenshot key so they're full resolution with no desktop overlays, and crop
ultrawide shots to the part that matters.
