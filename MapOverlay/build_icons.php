<?php

declare(strict_types=1);

require_once __DIR__ . '/types.php';

/** Pack the established SaveStats artwork into the game's existing BGRA template. */
function BuildOverlayIcon(string $type, ?string $sourcePath): GdImage
{
    $canvas = imagecreatetruecolor(256, 256);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    if ($sourcePath !== null) {
        $source = imagecreatefrompng($sourcePath);
        if ($source === false) { throw new RuntimeException("Cannot decode icon: $sourcePath"); }
        imagecopyresampled($canvas, $source, 20, 20, 0, 0, 216, 216, imagesx($source), imagesy($source));
        imagedestroy($source);
        return $canvas;
    }

    // Four explicit symbols for records outside SaveStats' puzzle-type artwork.
    $ink = imagecolorallocatealpha($canvas, 255, 221, 139, 0);
    $shade = imagecolorallocatealpha($canvas, 22, 27, 34, 18);
    imagefilledellipse($canvas, 128, 128, 232, 232, $shade);
    imagesetthickness($canvas, 13);
    switch ($type) {
        case 'loreFragment':
            imagerectangle($canvas, 75, 50, 182, 205, $ink);
            foreach ([90, 126, 162] as $y) { imageline($canvas, 96, $y, 162, $y, $ink); }
            break;
        case 'levelRestrictionVolume':
            imageline($canvas, 68, 205, 68, 102, $ink);
            imagearc($canvas, 128, 102, 120, 120, 180, 360, $ink);
            imageline($canvas, 188, 102, 188, 205, $ink);
            imageline($canvas, 49, 205, 207, 205, $ink);
            break;
        case 'monolithFragment':
            imagepolygon($canvas, [128, 39, 187, 101, 155, 134, 178, 161, 128, 215, 68, 151, 96, 120, 71, 96], $ink);
            break;
        case 'spawnSite':
            for ($angle = 0; $angle < 360; $angle += 45) { imagearc($canvas, 128, 128, 204, 204, $angle, $angle + 25, $ink); }
            imagearc($canvas, 128, 105, 60, 63, 180, 410, $ink);
            imageline($canvas, 151, 128, 128, 148, $ink);
            imageline($canvas, 128, 148, 128, 162, $ink);
            imagefilledellipse($canvas, 128, 186, 13, 13, $ink);
            break;
        default:
            throw new RuntimeException("No artwork defined for puzzle type: $type");
    }
    return $canvas;
}

function BuildOverlayIconsMain(array $argv): int
{
    $options = getopt('', ['savestats-dir:', 'output-dir:', 'template:'], $next);
    if ($next !== count($argv) || !isset($options['savestats-dir'], $options['output-dir'], $options['template'])) {
        throw new RuntimeException('Usage: php build_icons.php --savestats-dir DIR --output-dir DIR --template PATHSTEM');
    }
    foreach ($options as $value) { if (!is_string($value) || $value === '') { throw new RuntimeException('Invalid or repeated option.'); } }
    $saveStats = realpath($options['savestats-dir']);
    $outputDir = realpath($options['output-dir']);
    if ($saveStats === false || $outputDir === false || !is_dir($outputDir)) { throw new RuntimeException('Input and output directories must exist.'); }
    $installPrefix = strtolower(str_replace('\\', '/', $saveStats)) . '/';
    if (str_starts_with(strtolower(str_replace('\\', '/', $outputDir)) . '/', $installPrefix)) {
        throw new RuntimeException('Output must be outside the SaveStats installation.');
    }
    require_once __DIR__ . '/../lib/icon_texture.php';
    $template = $options['template'];
    $manifest = [];
    foreach (GetOverlayTypes() as $type => $info) {
        $source = $info['iconFile'] === null ? null : $saveStats . '/media/img/icons/' . $info['iconFile'];
        if ($source !== null && !is_file($source)) { throw new RuntimeException("Missing type artwork: $source"); }
        $assetName = $info['iconAsset'];
        if (!preg_match('/^SSIcon[0-9]{3}$/D', $assetName)) { throw new RuntimeException('Texture names must match the nine-character template.'); }
        $pngPath = $outputDir . DIRECTORY_SEPARATOR . $assetName . '.png';
        $gd = BuildOverlayIcon($type, $source);
        if (!imagepng($gd, $pngPath)) { throw new RuntimeException('Cannot write packed icon image.'); }
        imagedestroy($gd);
        InjectIcon($template, $pngPath, $outputDir . DIRECTORY_SEPARATOR . $assetName);
        $manifest[$type] = ['asset' => $assetName, 'source' => $info['iconFile'], 'pngSha256' => hash_file('sha256', $pngPath)];
    }
    file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'icons.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    echo 'Packed ' . count($manifest) . " puzzle-type textures.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(BuildOverlayIconsMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
