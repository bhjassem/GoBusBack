<?php

/**
 * @file
 * Script to programmatically enable GoBus Auth REST Resources.
 * Run with: drush php:script scripts/enable_auth_resources.php
 */

use Drupal\rest\Entity\RestResourceConfig;

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

// Check if Drupal is bootstrapped
if (!class_exists('\Drupal')) {
    die("Error: Drupal is not bootstrapped. Please run this script using 'drush scr web/scripts/enable_auth_resources.php' or ensure Drupal is loaded.\n");
}

echo "Checking and enabling GoBus Auth Resources...\n";

// Check if REST module is enabled
if (!\Drupal::moduleHandler()->moduleExists('rest')) {
    echo "Enabling 'rest' module...\n";
    \Drupal::service('module_installer')->install(['rest']);
}

$storage = \Drupal::entityTypeManager()->getStorage('rest_resource_config');

foreach ($resources as $id => $data) {
    echo "Processing $id...\n";

    $resource_config = $storage->load($id);

    if ($resource_config) {
        echo " - Resource already exists. Updating configuration...\n";
    }
    else {
        echo " - Creating new resource configuration...\n";
        $resource_config = $storage->create([
            'id' => $id,
            'plugin_id' => $data['plugin_id'],
            'granularity' => $data['granularity'],
        ]);
    }

    // Force configuration
    $resource_config->set('configuration', $data['configuration']);
    $resource_config->setStatus(TRUE);
    $resource_config->save();

    echo " - Saved and Enabled.\n";
}

// Clear cache to ensure routes are rebuilt
echo "Clearing routing cache...\n";
\Drupal::service('router.builder')->rebuild();
echo "Done!\n";