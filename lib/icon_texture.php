<?php

declare(strict_types=1);

// BGRA packing adapted from ModMaker/icon_inject.php in RRMod_PuzzleFix (MIT).
// Copyright (c) 2025 Rushin. See LICENSE and THIRD_PARTY_NOTICES.md.

/** Pack a 256px PNG into the known cooked MapMarker BGRA texture layout. */
function InjectIcon(string $template, string $pngPath, string $output): void
{
    $templateName = basename($template);
    $outputName = basename($output);
    if ($templateName === '' || strlen($templateName) !== strlen($outputName)) {
        throw new RuntimeException('Texture template and output names must have equal byte lengths.');
    }
    $inputs = [$template . '.uasset', $template . '.uexp', $pngPath];
    foreach ($inputs as $path) {
        if (!is_file($path)) { throw new RuntimeException('Missing texture input: ' . $path); }
    }
    if (!is_dir(dirname($output))) { throw new RuntimeException('Texture output directory must exist.'); }
    $key = static fn(string $path): string => PHP_OS_FAMILY === 'Windows'
        ? strtolower(str_replace('\\', '/', $path)) : $path;
    $inputKeys = array_map(static fn(string $path): string => $key((string)realpath($path)), $inputs);
    foreach (['.uasset', '.uexp'] as $extension) {
        $target = realpath($output . $extension);
        if ($target !== false && in_array($key($target), $inputKeys, true)) {
            throw new RuntimeException('Texture output must not replace an input.');
        }
    }
    $uasset = file_get_contents($inputs[0]);
    $uexp = file_get_contents($inputs[1]);
    $headerSize = 285;
    $pixelSize = 256 * 256 * 4;
    if ($uasset === false || $uexp === false || strlen($uexp) < $headerSize + $pixelSize
        || !str_contains($uasset, $templateName)) {
        throw new RuntimeException('Invalid or truncated MapMarker texture template.');
    }
    $image = @imagecreatefrompng($pngPath);
    if ($image === false) { throw new RuntimeException('Cannot decode texture PNG.'); }
    try {
        if (imagesx($image) !== 256 || imagesy($image) !== 256) {
            throw new RuntimeException('Texture PNG must be exactly 256 by 256 pixels.');
        }
        if (!imageistruecolor($image)) { imagepalettetotruecolor($image); }
        $pixels = '';
        // The native texture's established byte order is transposed by column.
        for ($x = 0; $x < 256; $x++) {
            for ($y = 0; $y < 256; $y++) {
                $rgba = imagecolorat($image, $y, $x);
                $alpha = ($rgba >> 24) & 0xFF;
                $nativeAlpha = $alpha === 0 ? 255 : ($alpha === 127 ? 0 : (127 - $alpha) * 2);
                $pixels .= chr($rgba & 0xFF) . chr(($rgba >> 8) & 0xFF)
                    . chr(($rgba >> 16) & 0xFF) . chr($nativeAlpha);
            }
        }
    } finally { imagedestroy($image); }
    $uexp = substr($uexp, 0, $headerSize) . $pixels . substr($uexp, $headerSize + $pixelSize);
    $uasset = str_replace($templateName, $outputName, $uasset);
    $uexp = str_replace($templateName, $outputName, $uexp);
    foreach (['.uasset' => $uasset, '.uexp' => $uexp] as $extension => $bytes) {
        if (file_put_contents($output . $extension, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write packed texture.');
        }
    }
}
