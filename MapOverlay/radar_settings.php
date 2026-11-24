<?php

declare(strict_types=1);

require_once __DIR__ . '/radar_settings_schema.php';
require_once __DIR__ . '/radar_widget.php';

/**
 * Radar functions that restore, store and rebind. The radar is the only writer of the
 * settings slot; the map pushes its changes here.
 */
function AddRadarSettingsFunctions(RadarWidgetBuilder $b, array $actions): void
{
    $i = static fn(int $n): object => FilterExpr('IntConst', ['Value' => $n]);
    $str = static fn(string $s): object => FilterExpr('StringConst', ['Value' => $s]);
    $name = static fn(string $s): object => FilterExpr('NameConst', ['Value' => $s]);
    $bool = static fn(bool $v): object => FilterExpr($v ? 'True' : 'False');
    $state = static fn(string $n): object => FilterVariable(1, $n, true);
    $set = static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer(1, $n), 'Variable' => $state($n), 'Expression' => $v]);
    $setBool = static fn(string $n, object $v): object => FilterExpr('LetBool', ['VariableExpression' => $state($n), 'AssignmentExpression' => $v]);
    $math = static fn(string $fn, object ...$args): object => $b->call('KismetMathLibrary', $fn, $args);
    $array = static fn(string $fn, object ...$args): object => $b->call('KismetArrayLibrary', $fn, $args);
    $input = static fn(string $fn, object ...$args): object => $b->call('KismetInputLibrary', $fn, $args);
    $jump = static fn(string $label): object => FilterExpr('Jump', ['CodeOffset' => 0, '__target' => $label]);
    $unless = static fn(object $condition, string $label): object => FilterExpr('JumpIfNot', ['CodeOffset' => 0, '__target' => $label, 'BooleanExpression' => $condition]);
    $localCall = static fn(string $fn, array $args = []): object => FilterCall($b->functions[$fn], $args, 'LocalFinalFunction');
    $valid = static fn(object $v): object => $b->call('KismetSystemLibrary', 'IsValid', [$v]);
    $member = static fn(string $struct, string $field, object $of): object => FilterExpr('StructMemberContext', ['StructMemberExpression' => FilterPointer($b->native[$struct], $field), 'StructExpression' => $of]);
    $setMember = static fn(string $struct, string $field, object $of, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($b->native[$struct], $field), 'Variable' => $member($struct, $field, $of), 'Expression' => $v]);
    $settings = $b->native['RadarSettings'];
    $field = static fn(object $store, string $n): object => RadarContext($store, FilterVariable($settings, $n, true), FilterPointer($settings, $n));
    $locals = static function (int $fn): array {
        return [static fn(string $n): object => FilterVariable($fn, $n),
            static fn(string $n, object $v): object => FilterExpr('Let', ['Value' => FilterPointer($fn, $n), 'Variable' => FilterVariable($fn, $n), 'Expression' => $v])];
    };
    // Saved field => radar variable (keys are handled per action).
    $savedInts = ['SelectedMask' => 'SSRadarSelectedMask', 'ZoomIndex' => 'SSRadarZoomIndex', 'SizeIndex' => 'SSRadarSizeIndex'];
    $savedBools = ['IncludeSites' => 'SSRadarIncludeSites', 'HideDone' => 'SSRadarHideDone', 'Enabled' => 'SSRadarEnabled'];
    $mappingsInner = static function (string $function) use ($b): void {
        foreach ($b->asset->Exports[$b->functions[$function] - 1]->LoadedProperties as $property) {
            if ($property->Name === 'Mappings') { $property->Inner = $b->field('Struct', 'Mappings'); $property->Inner->Struct = $b->native['InputActionKeyMapping']; $property->Inner->ElementSize = 48; }
        }
    };

    // Exactly one native mapping per radar action, whatever earlier HUDs registered.
    [$l, $let] = $locals($b->functions['SSRadarRegisterKeys']);
    $code = [$let('Settings', $b->call('InputSettings', 'GetInputSettings')), $unless($valid($l('Settings')), 'Done')];
    foreach ($actions as $action => [$actionName]) {
        $code = [...$code, $array('Array_Clear', $l('Mappings')),
            $b->method($l('Settings'), 'InputSettings', 'GetActionMappingByName', [$name($actionName), $l('Mappings')]),
            $let('Cursor', $i(0)), ['label' => 'Remove' . $action],
            $unless($math('Less_IntInt', $l('Cursor'), $array('Array_Length', $l('Mappings'))), 'Add' . $action),
            $array('Array_Get', $l('Mappings'), $l('Cursor'), $l('InputMapping')),
            $b->method($l('Settings'), 'InputSettings', 'RemoveActionMapping', [$l('InputMapping'), $bool(false)]),
            $let('Cursor', $math('Add_IntInt', $l('Cursor'), $i(1))), $jump('Remove' . $action),
            ['label' => 'Add' . $action], $let('InputMapping', $state('SSRadarKey' . $action)),
            $b->method($l('Settings'), 'InputSettings', 'AddActionMapping', [$l('InputMapping'), $bool(false)])];
    }
    $code = [...$code, $b->method($l('Settings'), 'InputSettings', 'ForceRebuildKeymaps'), ['label' => 'Done']];
    $b->define('SSRadarRegisterKeys', [], ['Settings' => 'Object', 'Mappings' => 'Array', 'InputMapping' => 'Struct', 'Cursor' => 'Int'], $code);
    $mappingsInner('SSRadarRegisterKeys');

    [$l, $let] = $locals($b->functions['SSRadarSaveSettings']);
    $code = [$let('Store', FilterExpr('DynamicCast', ['ClassPtr' => $settings, 'TargetExpression' => $b->call('GameplayStatics', 'CreateSaveGameObject', [FilterExpr('ObjectConst', ['Value' => $settings])])])),
        $unless($valid($l('Store')), 'Done'),
        FilterExpr('Let', ['Value' => FilterPointer($settings, 'Catalog'), 'Variable' => $field($l('Store'), 'Catalog'), 'Expression' => $str(RadarSettingsCatalogKey())])];
    foreach ($savedInts as $saved => $source) {
        $code[] = FilterExpr('Let', ['Value' => FilterPointer($settings, $saved), 'Variable' => $field($l('Store'), $saved), 'Expression' => $state($source)]);
    }
    foreach ($savedBools as $saved => $source) {
        $code[] = FilterExpr('LetBool', ['VariableExpression' => $field($l('Store'), $saved), 'AssignmentExpression' => $state($source)]);
    }
    foreach (array_keys($actions) as $action) {
        $code = [...$code, $let('InputMapping', $state('SSRadarKey' . $action)), $let('InputKey', $member('InputActionKeyMapping', 'Key', $l('InputMapping'))),
            FilterExpr('Let', ['Value' => FilterPointer($settings, 'Key' . $action), 'Variable' => $field($l('Store'), 'Key' . $action), 'Expression' => $member('Key', 'KeyName', $l('InputKey'))])];
    }
    $code = [...$code, $setBool('SSRadarSettingsSaved', $b->call('GameplayStatics', 'SaveGameToSlot', [$l('Store'), $str(RADAR_SETTINGS_SLOT), $i(0)])), ['label' => 'Done']];
    $b->define('SSRadarSaveSettings', [], ['Store' => 'Object', 'InputMapping' => 'Struct', 'InputKey' => 'Struct'], $code);

    // Fields are written directly: loading is not a change, so the revision stays 0 and a map
    // that already holds state may still migrate it into this (untouched) radar.
    [$l, $let] = $locals($b->functions['SSRadarLoadSettings']);
    $code = [$unless($b->call('GameplayStatics', 'DoesSaveGameExist', [$str(RADAR_SETTINGS_SLOT), $i(0)]), 'Done'),
        $let('Store', FilterExpr('DynamicCast', ['ClassPtr' => $settings, 'TargetExpression' => $b->call('GameplayStatics', 'LoadGameFromSlot', [$str(RADAR_SETTINGS_SLOT), $i(0)])])),
        $unless($valid($l('Store')), 'Done'),
        $let('Catalog', $field($l('Store'), 'Catalog')),
        // Selection bits are catalog positions: a mask saved against another catalog is dropped.
        $unless($b->call('KismetStringLibrary', 'EqualEqual_StrStr', [$l('Catalog'), $str(RadarSettingsCatalogKey())]), 'Options'),
        $set($savedInts['SelectedMask'], $field($l('Store'), 'SelectedMask')),
        ['label' => 'Options']];
    foreach ($savedBools as $saved => $target) {
        $code[] = $setBool($target, $field($l('Store'), $saved));
    }
    foreach (['ZoomIndex' => 3, 'SizeIndex' => 2] as $saved => $max) {
        $code = [...$code, $let('Value', $field($l('Store'), $saved)), $set($savedInts[$saved], $math('Max', $i(0), $math('Min', $i($max), $l('Value'))))];
    }
    foreach (array_keys($actions) as $action) {
        $loaded = 'LoadedKey' . $action;
        $code = [...$code, $let('KeyName', $field($l('Store'), 'Key' . $action)), $setMember('Key', 'KeyName', $l($loaded), $l('KeyName')),
            // An unknown name or a mouse button keeps the default.
            $unless($input('Key_IsValid', $l($loaded)), 'Kept' . $action),
            $unless($math('Not_PreBool', $input('Key_IsMouseButton', $l($loaded))), 'Kept' . $action),
            $setMember('InputActionKeyMapping', 'Key', $state('SSRadarKey' . $action), $l($loaded)), ['label' => 'Kept' . $action]];
    }
    $code = [...$code, $setBool('SSRadarSettingsLoaded', $bool(true)), ['label' => 'Done']];
    $loadLocals = ['Store' => 'Object', 'Catalog' => 'Str', 'Value' => 'Int', 'KeyName' => 'Name'];
    foreach (array_keys($actions) as $action) { $loadLocals['LoadedKey' . $action] = 'Struct'; }
    $b->define('SSRadarLoadSettings', [], $loadLocals, $code);

    // A key another radar action already uses is swapped: that action takes the old key.
    $fn = $b->functions['SSRadarRebind'];
    [$l, $let] = $locals($fn);
    $code = [$set('SSRadarRebindSwap', $i(-1))];
    $names = array_keys($actions);
    foreach ($names as $index => $action) {
        $code = [...$code, $unless($math('EqualEqual_IntInt', $l('Action'), $i($index)), 'Next' . $action),
            $let('InputMapping', $state('SSRadarKey' . $action)), $let('OldKey', $member('InputActionKeyMapping', 'Key', $l('InputMapping'))),
            $unless($math('Not_PreBool', $input('EqualEqual_KeyKey', $l('OldKey'), $l('InputKey'))), 'Done')];
        foreach ($names as $otherIndex => $other) {
            if ($other === $action) { continue; }
            $code = [...$code, $let('InputMapping', $state('SSRadarKey' . $other)), $let('OtherKey', $member('InputActionKeyMapping', 'Key', $l('InputMapping'))),
                $unless($input('EqualEqual_KeyKey', $l('OtherKey'), $l('InputKey')), 'Keep' . $action . $other),
                $setMember('InputActionKeyMapping', 'Key', $state('SSRadarKey' . $other), $l('OldKey')), $set('SSRadarRebindSwap', $i($otherIndex)),
                ['label' => 'Keep' . $action . $other]];
        }
        $code = [...$code, $setMember('InputActionKeyMapping', 'Key', $state('SSRadarKey' . $action), $l('InputKey')), $jump('Commit'), ['label' => 'Next' . $action]];
    }
    $code = [...$code, $jump('Done'), ['label' => 'Commit'], $localCall('SSRadarRegisterKeys'), $localCall('SSRadarSaveSettings'), ['label' => 'Done']];
    $b->define('SSRadarRebind', ['Action' => 'Int', 'InputKey' => 'Struct'], ['InputMapping' => 'Struct', 'OldKey' => 'Struct', 'OtherKey' => 'Struct'], $code);

    [$l, $let] = $locals($b->functions['SSRadarResetKeys']);
    $code = [$set('SSRadarRebindSwap', $i(-1))];
    $resetLocals = [];
    foreach ($actions as $action => [, $key]) {
        $resetLocals['DefaultKey' . $action] = 'Struct';
        $code = [...$code, $setMember('Key', 'KeyName', $l('DefaultKey' . $action), $name($key)),
            $setMember('InputActionKeyMapping', 'Key', $state('SSRadarKey' . $action), $l('DefaultKey' . $action))];
    }
    $code = [...$code, $localCall('SSRadarRegisterKeys'), $localCall('SSRadarSaveSettings')];
    $b->define('SSRadarResetKeys', [], $resetLocals, $code);

    [$l] = $locals($b->functions['SSRadarSetHideDone']);
    $b->define('SSRadarSetHideDone', ['HideDone' => 'Bool'], [], [
        $unless($math('Not_PreBool', $math('EqualEqual_BoolBool', $state('SSRadarHideDone'), $l('HideDone'))), 'Done'),
        $setBool('SSRadarHideDone', $l('HideDone')), $localCall('SSRadarSaveSettings'), ['label' => 'Done']]);
}

function BuildRadarSettingsAsset(object $base): object
{
    $b = new RadarWidgetBuilder($base);
    $b->asset->Exports = [$b->class, $b->default];
    $b->asset->NameMap = ['None', RADAR_SETTINGS_PACKAGE];
    $b->asset->PackageGuid = '{5E0C3A57-2B9B-4D6E-9F31-7C1D2A8E4B60}';
    $saveGame = OverlayImport($b->asset, 'SaveGame', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $saveGameDefault = OverlayImport($b->asset, 'Default__SaveGame', $b->native['Engine'], '/Script/Engine', 'SaveGame');
    $generated = OverlayImport($b->asset, 'BlueprintGeneratedClass', $b->native['Engine'], '/Script/CoreUObject', 'Class');
    $generatedDefault = OverlayImport($b->asset, 'Default__BlueprintGeneratedClass', $b->native['Engine'], '/Script/Engine', 'BlueprintGeneratedClass');
    $b->class->ObjectName = RADAR_SETTINGS_CLASS;
    $b->class->ClassIndex = $generated;
    $b->class->TemplateIndex = $generatedDefault;
    $b->class->ClassFlags = 'CLASS_Parsed, CLASS_ReplicationDataIsSetUp, CLASS_CompiledFromBlueprint';
    $b->class->SuperStruct = $b->class->SuperIndex = $saveGame;
    $b->class->LoadedProperties = $b->class->Data = [];
    $b->class->SerializationBeforeSerializationDependencies = [$saveGame];
    $b->class->CreateBeforeSerializationDependencies = [];
    $b->class->SerializationBeforeCreateDependencies = [$generated, $generatedDefault];
    $b->class->CreateBeforeCreateDependencies = [$saveGame];
    $b->default->ObjectName = 'Default__' . RADAR_SETTINGS_CLASS;
    $b->default->TemplateIndex = $saveGameDefault;
    $b->default->Data = [];
    $b->default->SerializationBeforeCreateDependencies = [1, $saveGameDefault];
    foreach (RadarSettingsFields() as $name => [$kind, $value]) {
        $b->scalar($kind, $name, is_bool($value) ? ($value ? 1 : 0) : $value);
    }
    return $b->finish();
}

function RadarSettingsMain(array $argv): int
{
    $options = getopt('', ['base-widget:', 'output:'], $next);
    if ($next !== count($argv) || !isset($options['base-widget'], $options['output'])) { throw new InvalidArgumentException('Usage: php radar_settings.php --base-widget FILE --output FILE'); }
    $base = json_decode(file_get_contents($options['base-widget']), false, 512, JSON_THROW_ON_ERROR);
    $asset = BuildRadarSettingsAsset($base);
    file_put_contents($options['output'], json_encode($asset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    echo 'Generated radar settings with ' . count($asset->Exports) . " exports.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(RadarSettingsMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
