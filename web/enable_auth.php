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

$test_users = [
    [
        'phone' => '55000001',
        'password' => 'agent123',
        'name' => 'Agent Test',
        'role' => 'agent',
        'prefix' => 'AGT',
        'shop_name' => 'Test Shop',
        'city' => 'Tunis',
    ],
    [
        'phone' => '55000002',
        'password' => 'captain123',
        'name' => 'Captain Test',
        'role' => 'captain',
        'prefix' => 'CPT',
        'shop_name' => '',
        'city' => 'Tunis',
    ],
];

foreach ($test_users as $test) {
    $users = \Drupal::entityTypeManager()->getStorage('user')
        ->loadByProperties(['field_phone' => $test['phone']]);

    if (!empty($users)) {
        $existing = reset($users);
        echo "  [OK] {$test['role']} test user already exists (uid: {$existing->id()}, phone: {$test['phone']})\n";
        // Make sure the role is assigned
        if (!$existing->hasRole($test['role'])) {
            $existing->addRole($test['role']);
            $existing->save();
            echo "       Added missing '{$test['role']}' role.\n";
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
        $account_id = $test['prefix'] . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

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
// STEP 6: Rebuild routes
// ===================================================================
echo "--- Step 6: Rebuilding routes ---\n";
try {
    \Drupal::service('router.builder')->rebuild();
    echo "  [OK] Routes rebuilt successfully.\n";
}
catch (\Exception $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    echo "  Try running: drush cr\n";
}

echo "\n=== DONE ===\n";
echo "\nTest commands:\n";
echo "  curl -X POST 'https://www.gobus.tn/api/v1/auth/agent/login?_format=json' -H 'Content-Type: application/json' -d '{\"phone\":\"55000001\",\"password\":\"agent123\"}'\n";
echo "  curl -X POST 'https://www.gobus.tn/api/v1/auth/captain/login?_format=json' -H 'Content-Type: application/json' -d '{\"phone\":\"55000002\",\"password\":\"captain123\"}'\n";
echo "</pre>";
