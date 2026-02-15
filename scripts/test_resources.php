<?php

use Drupal\user\Entity\User;
use Drupal\gobus_api\Plugin\rest\resource\StatsResource;
use Drupal\gobus_api\Plugin\rest\resource\ReloadResource;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\UserSession;

// 1. Setup Test User (Agent)
$agent = user_load_by_name('50000001'); // Mohamed Agent
if (!$agent) {
    die("Error: Agent user '50000001' not found.\n");
}
// Switch current user to Agent
$account_switcher = \Drupal::service('account_switcher');
$account_switcher->switchTo(new UserSession([
    'uid' => $agent->id(),
    'roles' => $agent->getRoles()
]));

echo "--- Testing as Agent: " . $agent->getAccountName() . " ---\n";

// 2. Test StatsResource
echo "\n[TEST] StatsResource:\n";
$stats_resource = StatsResource::create(\Drupal::getContainer(), [], 'gobus_api_stats', []);
$response = $stats_resource->get();
$data = $response->getResponseData();

if ($data['success']) {
    echo "SUCCESS: Stats retrieved.\n";
    echo "Recharge Count: " . $data['data']['recharge_count'] . "\n";
    echo "Total Recharge: " . $data['data']['total_recharge_amount'] . "\n";
}
else {
    echo "FAILURE: " . $data['message'] . "\n";
}

// 3. Test ReloadResource
echo "\n[TEST] ReloadResource:\n";
$client_code = 'CLIENT1';
$amount = 25.000;

// Ensure Client has Access Code and Balance
$client_user = user_load_by_name('20000001');
if (!$client_user) {
    die("Error: Client user '20000001' not found.\n");
}
$client_user->set('field_access_code', $client_code);
if ($client_user->get('field_balance')->isEmpty()) {
    $client_user->set('field_balance', 0.000);
}
$client_user->save();
echo "Updated Client Access Code to '$client_code' and ensuring balance exists.\n";

// Get initial balance
$initial_balance = (float) $client_user->get('field_balance')->value;
echo "Initial Balance for $client_code: $initial_balance\n";

$reload_resource = ReloadResource::create(\Drupal::getContainer(), [], 'gobus_api_reload', []);

try {
    $response = $reload_resource->post([
        'client_account_id' => $client_code,
        'amount' => $amount
    ]);
    $data = $response->getResponseData();

    if ($data['success']) {
        echo "SUCCESS: Reload processed.\n";
        echo "New Balance: " . $data['data']['new_balance'] . "\n";
        echo "Transaction ID: " . $data['data']['transaction_id'] . "\n";

        // Verify balance increase
        if ($data['data']['new_balance'] == $initial_balance + $amount) {
            echo "VERIFIED: Balance increased correctly.\n";
        }
        else {
            echo "ERROR: Balance mismatch!\n";
        }

    }
    else {
        echo "FAILURE: " . $data['message'] . "\n";
    }
}
catch (\Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// Switch back
$account_switcher->switchBack();