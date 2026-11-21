<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/icon_texture.php';

function TextureCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function TextureReject(callable $call, string $message): void
{
    $failed = false;
    try { $call(); } catch (RuntimeException) { $failed = true; }
    TextureCheck($failed, $message);
}

$directory = sys_get_temp_dir() . '/ioi-radar-icon-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    $template = $directory . '/MapMarker';
    $output = $directory . '/SSIcon000';
    $uasset = 'SYNTHETIC_TEMPLATE_MapMarker_END';
    $header = str_pad('MapMarker_HEADER', 285, 'H');
    $tail = 'UNCHANGED_TRAILING_BYTES';
    file_put_contents($template . '.uasset', $uasset);
    file_put_contents($template . '.uexp', $header . str_repeat("\0", 256 * 256 * 4) . $tail);
    $png = $directory . '/input.png';
    $image = imagecreatetruecolor(256, 256);
    imagealphablending($image, false); imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 0x12, 0x34, 0x56, 0));
    imagesetpixel($image, 1, 0, imagecolorallocatealpha($image, 0x78, 0x9A, 0xBC, 64));
    imagesetpixel($image, 255, 255, imagecolorallocatealpha($image, 0xDE, 0xF0, 0x11, 1));
    imagepng($image, $png); imagedestroy($image);
    $beforeAsset = file_get_contents($template . '.uasset');
    $beforeExp = file_get_contents($template . '.uexp');
    InjectIcon($template, $png, $output);
    $packed = file_get_contents($output . '.uexp');
    TextureCheck(file_get_contents($output . '.uasset') === 'SYNTHETIC_TEMPLATE_SSIcon000_END', 'Equal-width asset rename failed.');
    TextureCheck(substr($packed, 0, 285) === str_replace('MapMarker', 'SSIcon000', $header) && substr($packed, -strlen($tail)) === $tail, 'Texture headers or trailing native payload changed.');
    TextureCheck(substr($packed, 285, 4) === "\x56\x34\x12\xFF", 'Opaque BGRA pixel encoding changed.');
    TextureCheck(substr($packed, 289, 4) === "\xBC\x9A\x78\x7E", 'Partial alpha or adjacent pixel encoding changed.');
    TextureCheck(substr($packed, 285 + 256 * 4, 4) === "\0\0\0\0", 'Transparent next-row pixel or pixel order changed.');
    TextureCheck(substr($packed, 285 + (256 * 256 - 1) * 4, 4) === "\x11\xF0\xDE\xFC", 'Last pixel encoding changed.');
    $packedHash = hash_file('sha256', $output . '.uexp');
    TextureReject(fn() => InjectIcon($template, $png, $directory . '/differentLength'), 'Mismatched filename lengths accepted.');
    TextureReject(fn() => InjectIcon($template, $png, $template), 'Template overwrite accepted.');
    file_put_contents($template . '.uexp', 'truncated');
    TextureReject(fn() => InjectIcon($template, $png, $output), 'Truncated native template accepted.');
    file_put_contents($template . '.uexp', $beforeExp);
    $small = imagecreatetruecolor(128, 128); imagepng($small, $png); imagedestroy($small);
    TextureReject(fn() => InjectIcon($template, $png, $output), 'Wrong PNG dimensions accepted.');
    file_put_contents($png, 'invalid PNG');
    TextureReject(fn() => InjectIcon($template, $png, $output), 'Invalid PNG accepted.');
    TextureCheck(hash_file('sha256', $output . '.uexp') === $packedHash, 'Rejected inputs clobbered previous output.');
    TextureCheck(file_get_contents($template . '.uasset') === $beforeAsset && file_get_contents($template . '.uexp') === $beforeExp, 'Packing modified input template.');
    echo "Texture BGRA, alpha, layout, rename, invalid input and no-clobber checks passed.\n";
} finally {
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
