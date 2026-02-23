<?php
/**
 * @file
 * Setup script for GoBus Ledger Architecture.
 * Run via: drush scr scripts/setup_ledger_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

echo "=== GoBus Ledger Setup ===\n\n";

// 1. Create 'Account' Node Type
$type = NodeType::load('gobus_account');
if (!$type) {
    try {
        $type = NodeType::create([
            'type' => 'gobus_account',
            'name' => 'GoBus Account',
            'description' => 'Financial account representing a user portfolio.',
        ]);
        $type->save();
        echo "  [OK] Created 'gobus_account' content type.\n";
    }
    catch (\Exception $e) {
        echo "  [ERROR] Creating node type: " . $e->getMessage() . "\n";
    }
}
else {
    echo "  [SKIP] 'gobus_account' already exists.\n";
}

// Helper function to create Entity Reference fields
function create_entity_reference_field($entity_type, $bundle, $field_name, $field_label, $target_type, $target_bundles = [])
{
    // Check if field storage exists
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    if (!$field_storage) {
        try {
            $field_storage = FieldStorageConfig::create([
                'field_name' => $field_name,
                'entity_type' => $entity_type,
                'type' => 'entity_reference',
                'settings' => [
                    'target_type' => $target_type,
                ],
            ]);
            $field_storage->save();
            echo "    [OK] Storage for {$field_name} created.\n";
        }
        catch (\Exception $e) {
            echo "    [ERROR] Storage {$field_name}: " . $e->getMessage() . "\n";
            return;
        }
    }

    // Check if field instance exists
    $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
    if (!$field) {
        try {
            $settings = [
                'handler' => 'default:' . $target_type,
            ];
            if (!empty($target_bundles)) {
                $settings['handler_settings'] = [
                    'target_bundles' => $target_bundles,
                ];
            }

            $field = FieldConfig::create([
                'field_name' => $field_name,
                'entity_type' => $entity_type,
                'bundle' => $bundle,
                'label' => $field_label,
                'settings' => $settings,
            ]);
            $field->save();
            echo "    [OK] Field {$field_name} added to {$bundle}.\n";
        }
        catch (\Exception $e) {
            echo "    [ERROR] Field {$field_name}: " . $e->getMessage() . "\n";
        }
    }
    else {
        echo "    [SKIP] Field {$field_name} on {$bundle} already exists.\n";
    }
}

// Helper function to create String fields
function create_string_field($entity_type, $bundle, $field_name, $field_label)
{
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    if (!$field_storage) {
        try {
            $field_storage = FieldStorageConfig::create([
                'field_name' => $field_name,
                'entity_type' => $entity_type,
                'type' => 'string',
            ]);
            $field_storage->save();
        }
        catch (\Exception $e) {
            echo "    [ERROR] " . $e->getMessage() . "\n";
            return;
        }
    }

    $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
    if (!$field) {
        try {
            $field = FieldConfig::create([
                'field_name' => $field_name,
                'entity_type' => $entity_type,
                'bundle' => $bundle,
                'label' => $field_label,
            ]);
            $field->save();
            echo "    [OK] Field {$field_name} added to {$bundle}.\n";
        }
        catch (\Exception $e) {
            echo "    [ERROR] " . $e->getMessage() . "\n";
        }
    }
}

// 2. Add fields to 'gobus_account'
echo "--- Configuring gobus_account fields ---\n";
// field_account_owner -> Entity Reference (user)
create_entity_reference_field('node', 'gobus_account', 'field_account_owner', 'Owner', 'user');
// field_account_type -> String (e.g. 'client', 'agent')
create_string_field('node', 'gobus_account', 'field_account_type', 'Account Type');
// field_acc_id -> String (ACC-CLT-00001) Note: can't use field_account_id as it exists on user and might have different settings
create_string_field('node', 'gobus_account', 'field_ledger_id', 'Ledger ID');

// 3. Update 'transaction' Node Type
echo "--- Configuring transaction fields ---\n";
// field_from_account -> Entity Reference (node: gobus_account)
create_entity_reference_field('node', 'transaction', 'field_from_account', 'From Account', 'node', ['gobus_account' => 'gobus_account']);
// field_to_account -> Entity Reference (node: gobus_account)
create_entity_reference_field('node', 'transaction', 'field_to_account', 'To Account', 'node', ['gobus_account' => 'gobus_account']);

echo "\n--- Clearing Caches ---\n";
drupal_flush_all_caches();
echo "Done.\n";