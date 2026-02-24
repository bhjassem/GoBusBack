<?php

/**
 * @file
 * Web script to programmatically enable GoBus Auth REST Resources,
 * create roles (agent/captain) and test users if missing.
 * Access via: https://www.gobus.tn/enable_auth.php
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

// Bootstrap Drupal
$autoloader = require_once 'autoload.php';
chdir(__DIR__);

$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);

$container = $kernel->getContainer();
\Drupal::setContainer($container);

echo "<pre>";
echo "=== GoBus Auth Resource Enabler ===\n\n";

// ===================================================================
// STEP 1: Ensure roles exist
// ===================================================================
echo "--- Step 1: Roles ---\n";

$roles_to_create = [
    'agent' => 'Agent',
    'captain' => 'Captain',
    'client' => 'Client',
];

foreach ($roles_to_create as $role_id => $role_label) {
    $existing_role = Role::load($role_id);
    if ($existing_role) {
        echo "  [OK] Role '$role_id' already exists.\n";
    }
    else {
        echo "  Creating role '$role_id'...\n";
        try {
            $new_role = Role::create([
                'id' => $role_id,
                'label' => $role_label,
            ]);
            $new_role->save();
            echo "  [OK] Role '$role_id' created.\n";
        }
        catch (\Exception $e) {
            echo "  [ERROR] " . $e->getMessage() . "\n";
        }
    }
}
echo "\n";

// ===================================================================
// STEP 2: Show discovered REST plugins for debugging
// ===================================================================
echo "--- Step 2: Discovered REST plugins ---\n";

// Clear plugin cache so newly deployed files are discovered
\Drupal::service('plugin.manager.rest')->clearCachedDefinitions();

$plugin_manager = \Drupal::service('plugin.manager.rest');
$definitions = $plugin_manager->getDefinitions();
$gobus_plugins = array_filter(array_keys($definitions), function ($key) {
    return str_starts_with($key, 'gobus_');
});
foreach ($gobus_plugins as $plugin_id) {
    echo "  [OK] $plugin_id\n";
}
echo "  Total GoBus plugins: " . count($gobus_plugins) . "\n\n";

// ===================================================================
// STEP 3: Clean up corrupted configs via ConfigFactory (bypasses entity storage)
// ===================================================================
echo "--- Step 3: Cleaning corrupted configs ---\n";
$config_factory = \Drupal::service('config.factory');
try {
    $all_rest_names = $config_factory->listAll('rest.resource.');
    echo "  Found " . count($all_rest_names) . " REST resource configs in database.\n";
    $cleaned = 0;
    foreach ($all_rest_names as $config_name) {
        $config_data = $config_factory->get($config_name);
        $entity_id = $config_data->get('id');
        if (empty($entity_id)) {
            echo "  Deleting corrupted config: $config_name (no ID)...\n";
            $config_factory->getEditable($config_name)->delete();
            $cleaned++;
        }
        else {
            echo "  [OK] $config_name (id: $entity_id)\n";
        }
    }
    echo "  Cleaned $cleaned corrupted entries.\n\n";
}
catch (\Exception $e) {
    echo "  Cleanup error: " . $e->getMessage() . "\n\n";
}

// ===================================================================
// STEP 4: Write REST resource configs directly via ConfigFactory
// ===================================================================
echo "--- Step 4: Enabling REST resources ---\n";

$resources = [
    'gobus_auth_agent_login' => [
        'plugin_id' => 'gobus_auth_agent_login',
        'granularity' => 'resource',
        'configuration' => [
            'methods' => ['POST'],
            'formats' => ['json'],
            'authentication' => ['oauth2'],
        ],
    ],
    'gobus_auth_agent_register' => [
        'plugin_id' => 'gobus_auth_agent_register',
        'granularity' => 'resource',
        'configuration' => [
            'methods' => ['POST'],
            'formats' => ['json'],
            'authentication' => ['oauth2'],
        ],
    ],
    'gobus_auth_captain_login' => [
        'plugin_id' => 'gobus_auth_captain_login',
        'granularity' => 'resource',
        'configuration' => [
            'methods' => ['POST'],
            'formats' => ['json'],
            'authentication' => ['oauth2'],
        ],
    ],
    'gobus_auth_captain_register' => [
        'plugin_id' => 'gobus_auth_captain_register',
        'granularity' => 'resource',
        'configuration' => [
            'methods' => ['POST'],
            'formats' => ['json'],
            'authentication' => ['oauth2'],
        ],
    ],
    'gobus_api_client_find' => [
        'plugin_id' => 'gobus_api_client_find',
        'granularity' => 'resource',
        'configuration' => [
            'methods' => ['GET'],
            'formats' => ['json'],
            'authentication' => ['oauth2'],
        ],
    ],
    'gobus_api_reload' => [
        'plugin_id' => 'gobus_api_reload',
        'granularity' => 'resource',
        'configuration' => [
            'methods' => ['POST'],
            'formats' => ['json'],
            'authentication' => ['oauth2'],
        ],
    ],
];

foreach ($resources as $id => $data) {
    echo "  Processing $id...\n";

    // Check if the plugin exists on this server
    if (!isset($definitions[$data['plugin_id']])) {
        echo "    SKIPPED: Plugin '{$data['plugin_id']}' not found on server.\n";
        continue;
    }

    try {
        $config_name = 'rest.resource.' . $id;

        // Delete existing via ConfigFactory (safe even for corrupted entries)
        $existing = $config_factory->getEditable($config_name);
        if (!$existing->isNew()) {
            $existing->delete();
            echo "    Deleted old config.\n";
        }

        // Write config directly via ConfigFactory (bypasses entity validation)
        $new_config = $config_factory->getEditable($config_name);
        $new_config->setData([
            'langcode' => 'en',
            'status' => TRUE,
            'dependencies' => [
                'module' => ['gobus_api', 'serialization', 'user'],
            ],
            'id' => $id,
            'plugin_id' => $data['plugin_id'],
            'granularity' => $data['granularity'],
            'configuration' => $data['configuration'],
        ]);
        $new_config->save();

        echo "    [OK] Saved and Enabled.\n";
    }
    catch (\Exception $e) {
        echo "    [ERROR] " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ===================================================================
// STEP 5: Create test users (if they don't exist)
// ===================================================================
echo "--- Step 5: Test users ---\n";

// Pre-check: Free up CLT-00001 if it's taken by a different user than our test user (50000099)
$target_id = 'CLT-00001';
$target_phone = '50000099';

$conflict_users = \Drupal::entityTypeManager()->getStorage('user')
    ->loadByProperties(['field_account_id' => $target_id]);

if (!empty($conflict_users)) {
    foreach ($conflict_users as $u) {
        $u_phone = $u->hasField('field_phone') ? $u->get('field_phone')->getString() : '';
        if ($u_phone !== $target_phone) {
            $new_id = $target_id . '-OLD-' . rand(100, 999);
            $u->set('field_account_id', $new_id);
            $u->save();
            echo "  [FIX] Freed up $target_id from user {$u->id()} ($u_phone). Renamed to $new_id.\n";
        }
    }
}

// Test user password: read from env var or generate a secure random one.
// Set GOBUS_TEST_PASSWORD in .env to use a custom password for all test users.
$test_password = getenv('GOBUS_TEST_PASSWORD') ?: bin2hex(random_bytes(12));
echo "  Test users password: $test_password\n";
echo "  (Set GOBUS_TEST_PASSWORD env var to override)\n\n";

$test_users = [
    [
        'phone' => '55000001',
        'password' => $test_password,
        'name' => 'Agent Test',
        'role' => 'agent',
        'prefix' => 'AGT',
        'shop_name' => 'Test Shop',
        'city' => 'Tunis',
        'balance' => 500.0,
    ],
    [
        'phone' => '55000002',
        'password' => $test_password,
        'name' => 'Captain Test',
        'role' => 'captain',
        'prefix' => 'CPT',
        'shop_name' => '',
        'city' => 'Tunis',
        'balance' => 0,
    ],
    [
        'phone' => '55000003',
        'password' => $test_password,
        'name' => 'Amine Ben Salah',
        'role' => 'client',
        'prefix' => 'CLT',
        'shop_name' => '',
        'city' => 'Tunis',
    ],
    [
        'phone' => '50000099',
        'password' => $test_password,
        'name' => 'Client Test QR',
        'role' => 'client',
        'prefix' => 'CLT',
        'shop_name' => '',
        'city' => 'Ariana',
        'balance' => 10.500,
        'force_account_id' => 'CLT-00001',
    ],
];

foreach ($test_users as $test) {
    $users = \Drupal::entityTypeManager()->getStorage('user')
        ->loadByProperties(['field_phone' => $test['phone']]);

    if (!empty($users)) {
        $existing = reset($users);
        echo "  [OK] {$test['role']} test user already exists (uid: {$existing->id()}, phone: {$test['phone']})\n";

        $changed = false;
        // Ensure role is assigned
        if (!$existing->hasRole($test['role'])) {
            $existing->addRole($test['role']);
            $changed = true;
            echo "       + Added missing '{$test['role']}' role.\n";
        }

        // Ensure force_account_id is applied if set
        if (!empty($test['force_account_id'])) {
            // Check if field exists first
            if ($existing->hasField('field_account_id')) {
                $current_id = $existing->get('field_account_id')->getString();
                if ($current_id !== $test['force_account_id']) {
                    $existing->set('field_account_id', $test['force_account_id']);
                    $changed = true;
                    echo "       + Forced account_id to '{$test['force_account_id']}' (was '$current_id').\n";
                }
            }
        }

        if ($changed) {
            $existing->save();
        }
        continue;
    }

    echo "  Creating {$test['role']} test user (phone: {$test['phone']})...\n";
    try {
        // Generate account_id
        $query = \Drupal::entityTypeManager()->getStorage('user')->getQuery()
            ->condition('field_account_id', $test['prefix'] . '-', 'STARTS_WITH')
            ->accessCheck(FALSE)
            ->count();
        $count = (int)$query->execute();

        if (!empty($test['force_account_id'])) {
            $account_id = $test['force_account_id'];
        }
        else {
            $account_id = $test['prefix'] . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
        }

        $test_user = User::create();
        $test_user->setPassword($test['password']);
        $test_user->enforceIsNew();
        $test_user->setEmail($test['phone'] . '@gobus.tn');
        $test_user->setUsername($test['phone']);
        $test_user->set('field_account_id', $account_id);
        $test_user->set('field_phone', $test['phone']);
        $test_user->set('field_full_name', $test['name']);
        if (!empty($test['shop_name'])) {
            $test_user->set('field_shop_name', $test['shop_name']);
        }
        $test_user->set('field_city', $test['city']);
        if (!empty($test['balance'])) {
            $test_user->set('field_balance', $test['balance']);
        }
        $test_user->addRole($test['role']);
        $test_user->activate();
        $test_user->save();

        echo "  [OK] Created: $account_id (phone: {$test['phone']}, pwd: {$test['password']})\n";
    }
    catch (\Exception $e) {
        echo "  [ERROR] " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ===================================================================
// STEP 6: Assign account_id to existing clients that don't have one
// ===================================================================
echo "--- Step 6: Fixing client account IDs ---\n";
try {
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');

    // Find all users with 'client' role
    $client_uids = $user_storage->getQuery()
        ->condition('roles', 'client')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

    echo "  Found " . count($client_uids) . " client users.\n";
    $fixed = 0;

    if (!empty($client_uids)) {
        $clients = $user_storage->loadMultiple($client_uids);

        // Count existing CLT- accounts to know the next number
        $clt_count_query = $user_storage->getQuery()
            ->condition('field_account_id', 'CLT-', 'STARTS_WITH')
            ->accessCheck(FALSE)
            ->count();
        $next_num = (int)$clt_count_query->execute() + 1;

        foreach ($clients as $client_user) {
            $existing_account_id = $client_user->get('field_account_id')->getString();
            if (empty($existing_account_id)) {
                $new_account_id = 'CLT-' . str_pad($next_num, 5, '0', STR_PAD_LEFT);
                $client_user->set('field_account_id', $new_account_id);
                $client_user->save();
                echo "  [OK] Assigned $new_account_id to uid " . $client_user->id() . " (" . $client_user->get('field_full_name')->getString() . ")\n";
                $next_num++;
                $fixed++;
            }
        }
    }
    echo "  Fixed $fixed clients without account_id.\n\n";
}
catch (\Exception $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n\n";
}

// ===================================================================
// STEP 7: Rebuild routes
// ===================================================================
echo "--- Step 7: Rebuilding routes ---\n";
try {
    \Drupal::service('router.builder')->rebuild();
    echo "  [OK] Routes rebuilt successfully.\n";
}
catch (\Exception $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    echo "  Try running: drush cr\n";
}

// ===================================================================
// STEP 8: Verification
// ===================================================================
echo "--- Step 8: Current Users Dump ---\n";
$uids = \Drupal::entityTypeManager()->getStorage('user')->getQuery()
    ->condition('uid', 0, '>')
    ->accessCheck(FALSE)
    ->execute();
$users = User::loadMultiple($uids);

echo str_pad("ID", 5) . " | " . str_pad("Role", 10) . " | " . str_pad("Phone", 12) . " | " . str_pad("Account ID", 12) . " | Name\n";
echo str_repeat("-", 60) . "\n";

foreach ($users as $u) {
    if ($u->id() == 1)
        continue; // Skip admin
    $roles = $u->getRoles();
    $role = end($roles); // get last role (usually the specific one)
    $phone = $u->hasField('field_phone') ? $u->get('field_phone')->getString() : '-';
    $acc_id = $u->hasField('field_account_id') ? $u->get('field_account_id')->getString() : '-';

    echo str_pad($u->id(), 5) . " | " . str_pad($role, 10) . " | " . str_pad($phone, 12) . " | " . str_pad($acc_id, 12) . " | " . $u->getAccountName() . "\n";
}

echo "\n=== DONE ===\n";
echo "\nTest commands:\n";
echo "  curl -X GET 'https://www.gobus.tn/api/v1/clients/search?q=97&_format=json' -H 'Authorization: Bearer <TOKEN>'\n";
echo "</pre>";