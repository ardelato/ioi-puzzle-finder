<?php

declare(strict_types=1);

/** External integration fixtures are never loaded from a developer's checkout. */
function OverlayTestDataPath(string $relative): string
{
    $root = getenv('IOI_RADAR_TEST_DATA_DIR');
    if (!is_string($root) || $root === '' || !is_dir($root)) {
        throw new RuntimeException('Set IOI_RADAR_TEST_DATA_DIR to an external fixture directory for this integration test.');
    }
    $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) { throw new RuntimeException('Missing external integration fixture: ' . $relative); }
    return $path;
}

function OverlayTestAssetGui(): ?string
{
    $path = getenv('IOI_RADAR_ASSET_GUI');
    if (!is_string($path) || $path === '') { return null; }
    if (!is_file($path)) { throw new RuntimeException('IOI_RADAR_ASSET_GUI must name an existing UAssetGUI executable.'); }
    return $path;
}
