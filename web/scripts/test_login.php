<?php

use GuzzleHttp\Client;

/**
 * Script to test login via internal OAuth call.
 * Run with: drush php:script scripts/test_login.php
 */

$phone = '98000001';
$password = 'password123';

echo "Testing login for: $phone / $password\n";

$client_id = 'gobus-reload-app-id';
$client_secret = 'gobus_reload_secret';

$base_url = 'http://127.0.0.1'; // Use local IP or actual domain

$http_client = \Drupal::httpClient();

try {
    $response = $http_client->post($base_url . '/oauth/token', [
        'form_params' => [
            'grant_type' => 'password',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'username' => $phone,
            'password' => $password,
        ],
        'http_errors' => false,
        'verify' => false,
    ]);

    echo "Status Code: " . $response->getStatusCode() . "\n";
    echo "Body: " . $response->getBody() . "\n";
}
catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}