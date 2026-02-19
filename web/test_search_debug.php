<?php

$baseUrl = 'http://localhost';
$loginUrl = $baseUrl . '/api/v1/auth/agent/login?_format=json';
$searchUrl = $baseUrl . '/api/v1/clients/find?_format=json&q=50';

echo "Testing URL: $baseUrl\n";

// 1. Login
$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'name' => 'agent_test',
    'pass' => 'password123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookie.txt');
$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "Curl error (Login): " . curl_error($ch) . "\n";
}
curl_close($ch);

$data = json_decode($response, true);
$token = $data['data']['tokens']['access_token'] ?? '';

if (empty($token)) {
    echo "Login failed or no token.\n";
    echo "Login Response: " . substr($response, 0, 500) . "\n";
    die();
}

echo "Token obtained: " . substr($token, 0, 10) . "...\n";

// 2. Search
$ch = curl_init($searchUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookie.txt');
// Enable header info
curl_setopt($ch, CURLOPT_HEADER, true);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "Curl error (Search): " . curl_error($ch) . "\n";
}

$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $header_size);
$body = substr($response, $header_size);
curl_close($ch);

echo "Search Response Headers:\n$headers\n";
echo "Search Response Body:\n$body\n";