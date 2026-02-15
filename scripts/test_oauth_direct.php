<?php

use Drupal\Core\Http\RequestStack;
use Symfony\Component\HttpFoundation\Request;

// Simulate the request to the token endpoint
$request = Request::create('/oauth/token', 'POST', [
    'grant_type' => 'password',
    'client_id' => 'gobus-reload-app-id',
    'client_secret' => 'gobus_reload_secret',
    'username' => '50000001',
    'password' => 'agent123',
]);

// We need to use the http_client to make the actual request because the OAuth controller 
// is dispatched via the router, but here we want to test the full stack response.
// However, internally we can just try to execute the controller if we knew it.
// Simpler: Use Guzzle to hit the local site.

$client = \Drupal::httpClient();
$base_url = \Drupal::request()->getSchemeAndHttpHost();

echo "Testing OAuth Endpoint: $base_url/oauth/token\n";

try {
    $response = $client->post($base_url . '/oauth/token', [
        'form_params' => [
            'grant_type' => 'password',
            'client_id' => 'gobus-reload-app-id',
            'client_secret' => 'gobus_reload_secret',
            'username' => '50000001',
            'password' => 'agent123',
        ],
        'http_errors' => false,
        'verify' => false,
    ]);

    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Body: " . $response->getBody() . "\n";

}
catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}