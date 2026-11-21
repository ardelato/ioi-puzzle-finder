# Third-party notices

The code license and the ownership of game assets are separate. This repository
does not include the game, the full Offline Restored Mod, SaveStats distributions,
player saves, encrypted archives or build-tool binaries.

| Component | Use and provenance | Distribution |
| --- | --- | --- |
| RRMod_PuzzleFix / Offline Restored Mod | Asset JSON and texture helper code adapted from the MIT-licensed [upstream repository](https://github.com/RiccTheThicc/RRMod_PuzzleFix), baseline commit `9f41102bfcb928c26bb8a88d4a549d285bd85694` (v1.6). Upstream copyright: 2025 Rushin. Created by Rushin, RiccTheThicc, meltdown and community contributors. | Preserve the upstream MIT notice in `LICENSE` for copied or adapted code. The full mod is installed separately. |
| SaveStats 1.3.1 | Build-time puzzle membership, locations, profiles and icon inputs from the [upstream release distributions](https://github.com/RiccTheThicc/RRMod_PuzzleFix/releases). Credit Rushin and the Islands of Insight modding community. | External build input. SaveStats executables, config, reports and source trees are not bundled. The package embeds the generated location catalog and type textures. |
| Islands of Insight assets | Native map/HUD assets, the BGRA texture template and puzzle/encyclopedia artwork originate from the game. The beta contains modified assets required by the addon. | These assets retain their respective owners' rights. The repository's MIT code license does not relicense game content. Original templates and extracted databases are kept as external local build inputs. |
| UAssetGUI / UAssetAPI | [UAssetGUI](https://github.com/atenfyr/UAssetGUI) and [UAssetAPI](https://github.com/atenfyr/UAssetAPI), by atenfyr, convert and inspect assets during development. Both upstream projects identify their code as MIT licensed. | External tools; their executables and libraries are not redistributed in this repository or beta. |
| UnrealPak | Unreal Engine packaging tool used to build and check the addon package. | External tool; not redistributed. Obtain a compatible tool under its applicable terms. |
| PHP | [PHP](https://www.php.net/) executes the build scripts; GD generates the addon artwork. The PHP distribution uses the PHP License and additional component notices. | External tool; no PHP runtime or extension binaries are bundled. |

Source references identify provenance; they do not imply endorsement. If the
distribution policy changes to include tools or additional upstream files, add
their exact license notices before shipping them. Do not copy the complete
ModMaker include tree: it contains unrelated third-party drawing helpers and
save/report code that this addon does not need.
