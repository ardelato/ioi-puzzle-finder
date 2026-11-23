<?php

declare(strict_types=1);

require_once __DIR__ . '/markers.php';
require_once __DIR__ . '/pentad_metadata.php';
require_once __DIR__ . '/hub_availability_data.php';
require_once __DIR__ . '/completion_metadata.php';

function MarkerAbsolutePath(string $path, string $base): string
{
    if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path)) {
        return $path;
    }
    return $base . DIRECTORY_SEPARATOR . $path;
}

function ValidateMarkerSave(object $save): void
{
    $root = $save->root ?? null;
    if (!is_object($root) || ($root->save_game_type ?? '') !== '/Script/IslandsofInsight.SophiaSaveGame') {
        throw new RuntimeException('Input is not an Islands of Insight uesave JSON save.');
    }
    $statuses = $root->properties->PuzzleStatuses_0->Array->value->Struct->value ?? null;
    if (!is_array($statuses)) {
        throw new RuntimeException('Save has no valid PuzzleStatuses array; refusing to assume every puzzle is unsolved.');
    }
    foreach ($statuses as $status) {
        $fields = $status->Struct ?? null;
        if (!is_int($fields->PuzzleId_0->Int->value ?? null) || !is_bool($fields->bSolved_0->Bool->value ?? null)) {
            throw new RuntimeException('Save contains an invalid puzzle completion record.');
        }
    }
}

function DecodeMarkerSnapshot(string $inputPath, string $outputPath, string $saveStatsDirectory): void
{
    $decoder = $saveStatsDirectory . '/uesave/' . (PHP_OS_FAMILY === 'Windows' ? 'uesave.exe' : 'uesave');
    if (!is_file($decoder)) {
        throw new RuntimeException('SaveStats uesave decoder is missing; use --decoded-save or restore the bundled decoder.');
    }
    $pipes = [];
    // Array arguments and file descriptors avoid shell interpretation of paths.
    $process = proc_open(
        [$decoder, 'to-json'],
        [0 => ['file', $inputPath, 'rb'], 1 => ['file', $outputPath, 'wb'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch the SaveStats uesave decoder.');
    }
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('SaveStats uesave decoder failed: ' . trim($error));
    }
}

function ExportMarkersMain(array $argv): int
{
    $options = getopt('', ['savestats-dir:', 'save:', 'decoded-save:', 'output:', 'puzzle-database-json:', 'catalog-mode:', 'help'], $nextArgument);
    if (isset($options['help'])) {
        echo "Usage: php export_markers.php --savestats-dir DIR --output FILE.json [--catalog-mode snapshot|universal] [--save FILE.sav | --decoded-save FILE.json] [--puzzle-database-json FILE]\nSnapshot is the CLI default. Universal uses all eligible metadata IDs and rejects save inputs.\n";
        return 0;
    }
    if ($nextArgument !== count($argv) || !isset($options['savestats-dir'], $options['output'])) {
        throw new RuntimeException('Provide --savestats-dir and --output. Use --help for usage.');
    }
    if (isset($options['save'], $options['decoded-save'])) {
        throw new RuntimeException('Choose --save or --decoded-save, not both.');
    }
    foreach ($options as $key => $value) {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException("Invalid or repeated option: --$key");
        }
    }
    $catalogMode = $options['catalog-mode'] ?? 'snapshot';
    if (!in_array($catalogMode, ['snapshot', 'universal'], true)) {
        throw new RuntimeException('Invalid --catalog-mode; choose snapshot or universal.');
    }
    if ($catalogMode === 'universal' && (isset($options['save']) || isset($options['decoded-save']))) {
        throw new RuntimeException('Universal catalog mode does not accept --save or --decoded-save.');
    }

    $originalDirectory = getcwd();
    $originalIncludePath = get_include_path();
    $saveStatsDirectory = realpath($options['savestats-dir']);
    $provider = $catalogMode === 'universal' ? '/include/stats.php' : '/include/savefile.php';
    if ($saveStatsDirectory === false || !is_file($saveStatsDirectory . $provider)) {
        throw new RuntimeException('SaveStats directory does not contain ' . ltrim($provider, '/') . '.');
    }
    $metadataPath = $saveStatsDirectory . '/media/data/megatable_v3.csv';
    if (!is_file($metadataPath)) {
        throw new RuntimeException('SaveStats directory does not contain media/data/megatable_v3.csv.');
    }
    $spawnMetadataPath = $saveStatsDirectory . '/media/data/spawns.csv';
    if (!is_file($spawnMetadataPath)) {
        throw new RuntimeException('SaveStats directory does not contain media/data/spawns.csv.');
    }
    $hubProfilePath = $saveStatsDirectory . '/media/data/profileHub.json';
    if (!is_file($hubProfilePath)) {
        throw new RuntimeException('SaveStats directory does not contain media/data/profileHub.json.');
    }
    $outputPath = MarkerAbsolutePath($options['output'], $originalDirectory);
    $outputDirectory = realpath(dirname($outputPath));
    if ($outputDirectory === false) {
        throw new RuntimeException('Output directory does not exist.');
    }
    $outputPath = $outputDirectory . DIRECTORY_SEPARATOR . basename($outputPath);
    $existingOutput = realpath($outputPath);
    if ($existingOutput !== false) {
        $outputPath = $existingOutput;
    }
    $normalizedOutput = strtolower(str_replace('\\', '/', $outputPath));
    $normalizedInstall = strtolower(str_replace('\\', '/', $saveStatsDirectory)) . '/';
    if (str_starts_with($normalizedOutput, $normalizedInstall)) {
        throw new RuntimeException('Output must be outside the SaveStats installation.');
    }
    $nativeDatabase = null;
    $nativeDatabaseSha256 = null;
    if (isset($options['puzzle-database-json'])) {
        $databasePath = realpath(MarkerAbsolutePath($options['puzzle-database-json'], $originalDirectory));
        if ($databasePath === false || !is_file($databasePath)) { throw new RuntimeException('Native puzzle database JSON does not exist.'); }
        if (strtolower(str_replace('\\', '/', $databasePath)) === $normalizedOutput) { throw new RuntimeException('Output must not replace the native puzzle database input.'); }
        $databaseBytes = file_get_contents($databasePath);
        if ($databaseBytes === false) { throw new RuntimeException('Unable to read the native puzzle database JSON.'); }
        $nativeDatabase = json_decode($databaseBytes, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($nativeDatabase)) { throw new RuntimeException('Native puzzle database JSON must be an object.'); }
        $nativeDatabaseSha256 = hash('sha256', $databaseBytes);
        unset($databaseBytes);
    }
    $savePath = null;
    $decoded = isset($options['decoded-save']);
    if ($catalogMode === 'snapshot') {
        $configured = is_file($saveStatsDirectory . '/config.txt') ? parse_ini_file($saveStatsDirectory . '/config.txt') : [];
        $savePath = $options['decoded-save'] ?? $options['save'] ?? ($configured['save_path'] ?? '');
        if ($savePath === '') {
            $localData = getenv('LOCALAPPDATA');
            if ($localData === false || $localData === '') {
                throw new RuntimeException('Provide --save when LOCALAPPDATA is unavailable.');
            }
            $savePath = $localData . '/IslandsofInsight/Saved/SaveGames/OfflineSavegame.sav';
        }
        $saveBase = isset($options['save']) || $decoded ? $originalDirectory : $saveStatsDirectory;
        $savePath = realpath(MarkerAbsolutePath($savePath, $saveBase));
        if ($savePath === false || !is_file($savePath)) {
            throw new RuntimeException('Save input does not exist.');
        }
        if (strcasecmp(str_replace('\\', '/', $savePath), str_replace('\\', '/', $outputPath)) === 0) {
            throw new RuntimeException('Output must not replace the input save.');
        }
    }

    $temporaryJson = null;
    $temporarySave = null;
    // Some upstream failures call exit(), which skips finally blocks.
    register_shutdown_function(function () use (&$temporaryJson, &$temporarySave): void {
        foreach ([$temporaryJson, $temporarySave] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    });
    // Override only the side-effectful config bootstrap; all parsing stays in SaveStats.
    global $config;
    $config = ['base_dir' => str_replace('\\', '/', $saveStatsDirectory) . '/', 'timezone_offset' => '', 'time_format' => 24];
    set_include_path(__DIR__ . '/bootstrap' . PATH_SEPARATOR . $saveStatsDirectory . PATH_SEPARATOR . $originalIncludePath);
    if (!chdir($saveStatsDirectory)) {
        set_include_path($originalIncludePath);
        throw new RuntimeException('Unable to enter the SaveStats metadata directory.');
    }
    try {
        require_once $saveStatsDirectory . '/include/file_io.php';
        require_once $saveStatsDirectory . $provider;
        $puzzleMap = GetPuzzleMap();
        // SaveStats leaves Mysteries out of its static list; add them as fixed-location puzzles.
        $mysteries = GetOverlayMysteryPids($puzzleMap);
        $solvedPids = [];
        $sourceMetadata = [
            'catalogMode' => $catalogMode,
            'metadataSha256' => hash_file('sha256', $metadataPath),
            'spawnMetadataSha256' => hash_file('sha256', $spawnMetadataPath),
            'hubProfileSha256' => hash_file('sha256', $hubProfilePath),
            'puzzleDatabaseSha256' => $nativeDatabaseSha256,
            'scope' => 'universal metadata catalog; all eligible static and hub puzzle IDs; fixed metadata locations; possible spawn sites are not active assignments',
        ];
        if ($catalogMode === 'snapshot') {
            $source = fopen($savePath, 'rb');
            if ($source === false) {
                throw new RuntimeException('Unable to read save input.');
            }
            try {
                $sourceStat = fstat($source);
                $sourceBytes = stream_get_contents($source);
                $afterRead = fstat($source);
            } finally {
                fclose($source);
            }
            if ($sourceBytes === false || $sourceStat === false || $afterRead === false || strlen($sourceBytes) !== $sourceStat['size'] || $sourceStat['mtime'] !== $afterRead['mtime'] || $sourceStat['size'] !== $afterRead['size']) {
                throw new RuntimeException('Save input changed while reading; retry after saving finishes.');
            }
            if ($decoded) {
                $rawSave = json_decode($sourceBytes, false, 512, JSON_THROW_ON_ERROR);
            } else {
                $temporarySave = tempnam(dirname($outputPath), '.save-source-');
                $temporaryJson = tempnam(dirname($outputPath), '.save-');
                if ($temporarySave === false || $temporaryJson === false) {
                    throw new RuntimeException('Unable to create temporary decoded save.');
                }
                if (file_put_contents($temporarySave, $sourceBytes) !== strlen($sourceBytes)) {
                    throw new RuntimeException('Unable to snapshot complete save input.');
                }
                // Decode the exact hashed bytes, even if the game saves again during export.
                DecodeMarkerSnapshot($temporarySave, $temporaryJson, $saveStatsDirectory);
                $rawSave = json_decode(file_get_contents($temporaryJson), false, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_object($rawSave)) {
                throw new RuntimeException('Decoded save must be a JSON object.');
            }
            ValidateMarkerSave($rawSave);
            $solvedPids = GetAllSolvedPids($rawSave);
            $staticRemainingPids = array_merge(GetStaticRemainingPids($solvedPids), array_diff($mysteries, $solvedPids));
            $hubRemainingPids = ExtractAllPids(SubtractProfiles(GetHubProfile(), BuildProfile($solvedPids)));
            $sourceMetadata = [
                'saveName' => basename($savePath),
                'saveSha256' => hash('sha256', $sourceBytes),
                'savedAtUtc' => gmdate('Y-m-d\TH:i:s\Z', $sourceStat['mtime']),
            ] + $sourceMetadata;
            $sourceMetadata['scope'] = 'save snapshot; static and hub puzzle IDs; fixed metadata locations; possible spawn sites are not active assignments';
        } else {
            // This metadata-only API is exactly GetStaticRemainingPids([]), without
            // loading the save parser or consulting a player profile/config file.
            $staticRemainingPids = array_merge(GetAllStaticPids(), $mysteries);
            $hubRemainingPids = ExtractAllPids(GetHubProfile());
        }
        $overlay = BuildOverlayData($puzzleMap, $solvedPids, $staticRemainingPids, $hubRemainingPids, LoadCsv($spawnMetadataPath));
        $overlay = EnrichOverlayCompletionMetadata($overlay, $puzzleMap);
        $overlay = EnrichOverlayPentadMetadata($overlay, $nativeDatabase);
        $overlay = EnrichOverlayHubAvailability($overlay, $nativeDatabase);
        $document = [
            'schemaVersion' => 2,
            'source' => $sourceMetadata,
        ] + $overlay + ['mysteryCoverage' => ['included' => $mysteries]];
        WriteMarkerJson($outputPath, $document);
        printf(
            "Exported %d located puzzles (%d render points), %d unlocated puzzles, and %d possible spawn sites (hidden by default) to %s\n",
            count((array)$overlay['markers']),
            $overlay['counts']['static']['renderPoints'] + $overlay['counts']['hub']['renderPoints'],
            count((array)$overlay['unlocated']), count((array)$overlay['spawnSites']), $outputPath
        );
    } finally {
        chdir($originalDirectory);
        set_include_path($originalIncludePath);
        if (is_string($temporaryJson) && is_file($temporaryJson)) {
            unlink($temporaryJson);
        }
        if (is_string($temporarySave) && is_file($temporarySave)) {
            unlink($temporarySave);
        }
    }
    return 0;
}

try {
    exit(ExportMarkersMain($argv));
} catch (Throwable $error) {
    fwrite(STDERR, 'Marker export failed: ' . $error->getMessage() . "\n");
    exit(1);
}
