<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/uasset.php';

function HelperCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$directory = sys_get_temp_dir() . '/ioi-radar-helper-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    $text = "quoted \" | \\ text \u{03A9}";
    $asset = (object)[
        '$type' => 'UAssetAPI.UAsset, UAssetAPI', 'NameMap' => ['None'],
        'Imports' => [(object)['$type' => 'UAssetAPI.Import, UAssetAPI', 'ObjectName' => '/Script/Engine', 'OuterIndex' => 0]],
        'Exports' => [
            (object)['$type' => 'UAssetAPI.ExportTypes.NormalExport, UAssetAPI', 'ObjectName' => 'Example', 'ClassIndex' => -1, 'OuterIndex' => 0,
                'Data' => [(object)['$type' => 'UAssetAPI.PropertyTypes.Objects.BoolPropertyData, UAssetAPI', 'Name' => 'Enabled', 'Value' => false],
                    (object)['$type' => 'UAssetAPI.PropertyTypes.Objects.StrPropertyData, UAssetAPI', 'Name' => 'Text', 'Value' => $text]]],
        ],
    ];
    $original = serialize($asset);
    $path = $directory . '/asset.json';
    $cwd = getcwd();
    SaveCompressedDecodedUasset($path, $asset, ['bakeAllIndices' => true, 'verbose' => false]);
    $decoded = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    HelperCheck(getcwd() === $cwd && serialize($asset) === $original, 'Helper changed caller directory or input asset.');
    HelperCheck($decoded->Exports[0]->{'$index'} === 1 && $decoded->Imports[0]->{'$index'} === -1, 'Native import/export indices changed.');
    HelperCheck($decoded->Exports[0]->Data[0]->Value === 0, 'Established native bool representation changed.');
    HelperCheck($decoded->Exports[0]->Data[1]->Value === $text, 'Escaped/non-ASCII property text changed.');
    HelperCheck(in_array('Example', $decoded->NameMap, true), 'Export object name missing from serialized NameMap.');
    HelperCheck(!function_exists('GetPuzzleMap') && !function_exists('ReadSaveFile') && !function_exists('ColorStr'), 'Unrelated save/config helpers loaded.');
    $failed = false;
    try { OverlayAssetWrite($directory . '/missing/file.json', '{}'); } catch (RuntimeException) { $failed = true; }
    HelperCheck($failed && !is_dir($directory . '/missing'), 'Missing output parents must fail without creating directories.');
    echo "Extracted asset helper input, indices, bool, string and isolation checks passed.\n";
} finally {
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
