<?php
/**
 * @file
 * Test script to verify Ledger operations (Reload and Balances).
 * Run via: drush scr scripts/test_ledger_reload.php
 */

use Drupal\user\Entity\User;

echo "=== Ledger E2E Test ===\n\n";

$ledger_service = \Drupal::service('gobus_api.ledger');

// 1. Get Agent (UID 115) and Client (UID 117)
$agent = User::load(115);
$client = User::load(117);

if (!$agent || !$client) {
    die("Test users not found.\n");
}

// 2. Get Accounts and Initial Balances
$agent_acc_id = $ledger_service->getOrCreateAccountForUser($agent);
$client_acc_id = $ledger_service->getOrCreateAccountForUser($client);

$agent_initial_balance = $ledger_service->calculateBalance($agent_acc_id);
$client_initial_balance = $ledger_service->calculateBalance($client_acc_id);

echo "Initial Agent Balance: $agent_initial_balance\n";
echo "Initial Client Balance: $client_initial_balance\n";

// 3. Perform a Reload of 10 DT
$amount = 10.0;
echo "\nPerforming RELOAD of $amount DT from Agent to Client...\n";

try {
    $transaction = $ledger_service->recordTransaction(
        $agent_acc_id,
        $client_acc_id,
        $amount,
        'RELOAD',
        $agent->id(),
        0.0,
        $client->id()
    );
    echo "  [OK] Transaction Node Created: " . $transaction->id() . "\n";
}
catch (\Exception $e) {
    die("  [ERROR] Transaction failed: " . $e->getMessage() . "\n");
}

// 4. Verify Final Balances
$agent_final_balance = $ledger_service->calculateBalance($agent_acc_id);
$client_final_balance = $ledger_service->calculateBalance($client_acc_id);

echo "\nFinal Agent Balance: $agent_final_balance (Expected: " . ($agent_initial_balance - $amount) . ")\n";
echo "Final Client Balance: $client_final_balance (Expected: " . ($client_initial_balance + $amount) . ")\n";

if ($agent_final_balance == ($agent_initial_balance - $amount) && $client_final_balance == ($client_initial_balance + $amount)) {
    echo "\n=> SUCCESS: Ledger is mathematically sound!\n";
}
else {
    echo "\n=> FAILED: Ledger balance mismatch.\n";
}

// 5. Cleanup the test transaction
$transaction->delete();
echo "Cleanup: Test transaction deleted.\n";