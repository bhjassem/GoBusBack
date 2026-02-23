<?php

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Script to load 100 DT into every Agent's ledger account.
 */

// 1. Find the System Account
$system_accounts = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
    'type' => 'gobus_account',
    'field_ledger_id' => 'ACC-SYS-MAIN'
]);

if (empty($system_accounts)) {
    echo "Error: System account ACC-SYS-MAIN not found. Please ensure migration 1 was fully run.\n";
    exit;
}

$system_account = reset($system_accounts);
$sys_account_node_id = $system_account->id();

// 2. Load all active Agents
$agent_ids = \Drupal::entityQuery('user')
    ->condition('status', 1)
    ->condition('roles', 'agent')
    ->accessCheck(false)
    ->execute();

$agents = User::loadMultiple($agent_ids);
$ledger_service = \Drupal::service('gobus_api.ledger');

echo "Found " . count($agents) . " active agents.\n";

$count = 0;
foreach ($agents as $agent) {
    // 3. Get or Create Ledger Account for Agent
    $agent_account_node_id = $ledger_service->getOrCreateAccountForUser($agent);

    if (!$agent_account_node_id) {
        echo "Failed to get/create account for Agent ID: " . $agent->id() . "\n";
        continue;
    }

    // 4. Record 100 DT Transaction from System to Agent
    try {
        $transaction = $ledger_service->recordTransaction(
            $sys_account_node_id, // From System
            $agent_account_node_id, // To Agent
            100.0, // Amount
            'SYSTEM_LOAD', // Transaction Type
            1, // Performed by Admin ID 1
            0.0, // No commission
            null // No target client
        );

        $new_balance = $ledger_service->calculateBalance($agent_account_node_id);

        echo "Successfully added 100 DT to Agent '" . $agent->getAccountName() . "' (ID: " . $agent->id() . "). New Ledger Balance: " . $new_balance . " DT\n";
        $count++;
    }
    catch (\Exception $e) {
        echo "Error adding funds to Agent '" . $agent->getAccountName() . "': " . $e->getMessage() . "\n";
    }
}

echo "Completed. Loaded 100 DT to $count agents.\n";