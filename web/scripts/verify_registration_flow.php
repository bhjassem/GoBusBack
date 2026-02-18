<?php

use GuzzleHttp\Client;

/**
 * Verify that the new Registration endpoints work correctly and assign proper roles/IDs.
 * Run with: drush php:script scripts/verify_registration_flow.php
 */

$client = new Client(['allow_redirects' => false]);
$base_url = 'http://localhost'; // Adjust if needed

// Helper to generate random phone
$rand = rand(1000, 9999);
$agent_phone = '5000' . $rand;
$captain_phone = '5100' . $rand;

function register_user($client, $url, $data, $expected_code, $label)
{
    echo "Testing $label ($url)...\n";
    try {
        $response = $client->post($url, [
            'json' => $data,
            'http_errors' => false,
            'verify' => false
        ]);

        $code = $response->getStatusCode();
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if ($code === $expected_code) {
            echo " [PASS] Got expected code $code.\n";
            // Check Role and Account ID prefix if success
            if ($code === 201 && isset($data['data']['user'])) {
                $user = $data['data']['user'];
                echo "        ID: " . $user['id'] . "\n";
                echo "        Account ID: " . $user['account_id'] . "\n";
                echo "        Role: " . $user['role'] . "\n";
            }
            if ($code !== 200 && $code !== 201 && isset($data['message'])) {
                echo "        Message: " . $data['message'] . "\n";
            }
        }
        else {
            echo " [FAIL] Expected $expected_code, got $code.\n";
            echo "        Response: " . substr($body, 0, 300) . "...\n";
            if ($code >= 300 && $code < 400) {
                echo "        Location: " . implode(', ', $response->getHeader('Location')) . "\n";
            }
        }
    }
    catch (\Exception $e) {
        echo " [ERROR] " . $e->getMessage() . "\n";
    }
    echo "---------------------------------------------------\n";
}

echo "\n--- Verifying Agent Registration (App) ---\n";
$agent_data = [
    'phone' => $agent_phone,
    'password' => 'password123',
    'name' => 'Agent Test ' . $rand,
    'shop_name' => 'Shop ' . $rand,
    'city' => 'Tunis',
    'code' => '5588'
];
register_user($client, $base_url . '/api/v1/auth/agent/register?_format=json', $agent_data, 201, 'Agent Register');


echo "\n--- Verifying Captain Registration ---\n";
$captain_data = [
    'phone' => $captain_phone,
    'password' => 'password123',
    'name' => 'Captain Test ' . $rand,
    // No shop_name for captain
    'city' => 'Sfax',
    'code' => '5588'
];
register_user($client, $base_url . '/api/v1/auth/captain/register?_format=json', $captain_data, 201, 'Captain Register');

// Verify Captain on Agent Endpoint (Should fail validation if strict, or create wrong role if logic broken)
// Actually, strict validation isn't implemented per se, but fields might differ.
// If I send captain data to agent endpoint, it should look for shop_name and fail 400.
echo "\n--- Verifying Integrity (Captain Data on Agent Endpoint) ---\n";
$invalid_data = $captain_data;
$invalid_data['phone'] = '5200' . $rand;
register_user($client, $base_url . '/api/v1/auth/agent/register?_format=json', $invalid_data, 400, 'Captain Data -> Agent Endpoint (Missing Shop Name)');