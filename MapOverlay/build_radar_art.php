<?php

declare(strict_types=1);

/** Draw at 4x and downsample to keep the small circular HUD edges smooth. */
function DrawRadarArt(string $asset): GdImage
{
    $scale = 4;
    $large = imagecreatetruecolor(256 * $scale, 256 * $scale);
    imagealphablending($large, false);
    imagesavealpha($large, true);
    imagefill($large, 0, 0, imagecolorallocatealpha($large, 0, 0, 0, 127));
    $color = static fn(int $r, int $g, int $b, int $alpha): int => imagecolorallocatealpha($large, $r, $g, $b, $alpha);
    if ($asset === 'SSRadRing') {
        $disk = $color(9, 24, 30, 35);
        $gold = $color(220, 181, 99, 8);
        $subtle = $color(199, 164, 91, 100);
        $cross = $color(143, 178, 176, 114);
        imagefilledellipse($large, 128 * $scale, 128 * $scale, 252 * $scale, 252 * $scale, $disk);
        imagesetthickness($large, $scale);
        foreach ([[8, 128, 248, 128], [128, 8, 128, 248]] as [$x1, $y1, $x2, $y2]) {
            imageline($large, $x1 * $scale, $y1 * $scale, $x2 * $scale, $y2 * $scale, $cross);
        }
        imageellipse($large, 128 * $scale, 128 * $scale, 96 * $scale, 96 * $scale, $cross);
        imageellipse($large, 128 * $scale, 128 * $scale, 228 * $scale, 228 * $scale, $subtle);
        imagesetthickness($large, 2 * $scale);
        imageellipse($large, 128 * $scale, 128 * $scale, 252 * $scale, 252 * $scale, $gold);
        foreach ([0, 90, 180, 270] as $angle) {
            $radians = deg2rad($angle);
            imageline($large, (int)((128 + 118 * cos($radians)) * $scale), (int)((128 + 118 * sin($radians)) * $scale),
                (int)((128 + 125 * cos($radians)) * $scale), (int)((128 + 125 * sin($radians)) * $scale), $gold);
        }
    } elseif ($asset === 'SSRadHead') {
        // The texture fills the template; the widget renders it at 18px.
        $outline = $color(5, 35, 43, 0);
        $cyan = $color(73, 226, 235, 0);
        $highlight = $color(195, 255, 255, 8);
        imagefilledpolygon($large, [128 * $scale, 20 * $scale, 222 * $scale, 228 * $scale,
            128 * $scale, 182 * $scale, 34 * $scale, 228 * $scale], $outline);
        imagefilledpolygon($large, [128 * $scale, 33 * $scale, 209 * $scale, 214 * $scale,
            128 * $scale, 174 * $scale, 47 * $scale, 214 * $scale], $cyan);
        imagesetthickness($large, 3 * $scale);
        imageline($large, 128 * $scale, 42 * $scale, 128 * $scale, 164 * $scale, $highlight);
    } else {
        throw new InvalidArgumentException('Unknown radar artwork: ' . $asset);
    }
    $canvas = imagecreatetruecolor(256, 256);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagecopyresampled($canvas, $large, 0, 0, 0, 0, 256, 256, 256 * $scale, 256 * $scale);
    imagedestroy($large);
    return $canvas;
}

function BuildRadarArtMain(array $argv): int
{
    $options = getopt('', ['output-dir:', 'template:'], $next);
    if ($next !== count($argv) || !isset($options['output-dir'], $options['template']) || !is_string($options['output-dir']) || $options['output-dir'] === '' || !is_string($options['template']) || $options['template'] === '') {
        throw new InvalidArgumentException('Usage: php build_radar_art.php --output-dir DIR --template PATHSTEM');
    }
    $output = realpath($options['output-dir']);
    if ($output === false || !is_dir($output)) { throw new InvalidArgumentException('Output directory must exist.'); }
    require_once __DIR__ . '/../lib/icon_texture.php';
    $template = $options['template'];
    $manifest = [];
    foreach (['SSRadRing', 'SSRadHead'] as $asset) {
        $pngPath = $output . DIRECTORY_SEPARATOR . $asset . '.png';
        $image = DrawRadarArt($asset);
        try { if (!imagepng($image, $pngPath)) { throw new RuntimeException('Cannot write radar artwork.'); } }
        finally { imagedestroy($image); }
        InjectIcon($template, $pngPath, $output . DIRECTORY_SEPARATOR . $asset);
        $manifest[$asset] = ['asset' => $asset, 'pngSha256' => hash_file('sha256', $pngPath),
            'package' => '/Game/ASophia/UI/HUD/Markers/Textures/' . $asset];
    }
    $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($output . DIRECTORY_SEPARATOR . 'radar-art.json', $json) !== strlen($json)) {
        throw new RuntimeException('Cannot write radar artwork manifest.');
    }
    echo "Packed two radar HUD textures.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(BuildRadarArtMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
