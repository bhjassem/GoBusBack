<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * Verify that the new Agent Login endpoint restricts access correctly,
 * while the original endpoint remains open.
 * Run with: drush php:script scripts/verify_agent_login.php
 */

$client = new Client();
$base_url = 'http://localhost';

$agent_phone = '98000001';
$agent_password = 'password123'; // Assuming default password from seeder

$captain_phone = '970000001';
$captain_password = 'password123';

function test_login($client, $url, $phone, $password, $expected_code, $label)
{
    echo "Testing $label ($url) with User $phone...\n";
    try {
        $response = $client->post($url, [
            'json' => [
                'phone' => $phone,
                'password' => $password
            ],
            'http_errors' => false,
            'verify' => false
        ]);

        $code = $response->getStatusCode();
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if ($code === $expected_code) {
            echo " [PASS] Got expected code $code.\n";
            if ($code !== 200 && isset($data['message'])) {
                echo "        Message: " . $data['message'] . "\n";
            }
        }
        else {
            echo " [FAIL] Expected $expected_code, got $code.\n";
            echo "        Response: " . substr($body, 0, 200) . "...\n";
        }
    }
    catch (\Exception $e) {
        echo " [ERROR] " . $e->getMessage() . "\n";
    }
    echo "---------------------------------------------------\n";
}

echo "\n--- Verifying Agent Login Endpoint (Secured) ---\n";
// 1. Agent on Agent Endpoint -> Should Pass (200)
test_login($client, $base_url . '/api/v1/auth/agent/login?_format=json', $agent_phone, $agent_password, 200, 'Agent -> Agent Login');

// 2. Captain on Agent Endpoint -> Should Fail (403)
test_login($client, $base_url . '/api/v1/auth/agent/login?_format=json', $captain_phone, $captain_password, 403, 'Captain -> Agent Login');

echo "\n--- Verifying Global Login Endpoint (Open) ---\n";
// 3. Agent on Global Endpoint -> Should Pass (200)
test_login($client, $base_url . '/api/v1/auth/login?_format=json', $agent_phone, $agent_password, 200, 'Agent -> Global Login');

// 4. Captain on Global Endpoint -> Should Pass (200)
test_login($client, $base_url . '/api/v1/auth/login?_format=json', $captain_phone, $captain_password, 200, 'Captain -> Global Login');
test_login($client, $base_url . '/api/v1/auth/login?_format=json', $captain_phone, $captain_password, 200, 'Captain -> Global Login');