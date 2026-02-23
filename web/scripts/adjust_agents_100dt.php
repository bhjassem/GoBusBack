<?php

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Script to forcefully set every Agent's ledger account balance to exactly 100 DT.
 * Because this is a double-entry ledger, we calculate the exact difference needed
 * and post a transaction from the System account to reach the target balance.
 */

$TARGET_BALANCE = 100.0;

// 1. Find the System Account
$system_accounts = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
    'type' => 'gobus_account',
    'field_ledger_id' => 'ACC-SYS-MAIN'
]);

if (empty($system_accounts)) {
    echo "Error: System account ACC-SYS-MAIN not found.\n";
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

echo "Adjusting balances for " . count($agents) . " active agents to exactly $TARGET_BALANCE DT...\n";

$count = 0;
foreach ($agents as $agent) {
    // 3. Get or Create Ledger Account for Agent
    $agent_account_node_id = $ledger_service->getOrCreateAccountForUser($agent);

    if (!$agent_account_node_id) {
        continue;
    }

    // 4. Calculate required offset
    $current_balance = $ledger_service->calculateBalance($agent_account_node_id);

    // We already added 100 earlier by mistake, so current_balance incorporates that.
    $offset_needed = $TARGET_BALANCE - $current_balance;

    if (abs($offset_needed) < 0.001) {
        echo "Agent '" . $agent->getAccountName() . "' is already at $TARGET_BALANCE DT.\n";
        continue;
    }

    // 5. Record adjustment Transaction
    try {
        if ($offset_needed > 0) {
            // Transfer from system to agent
            $ledger_service->recordTransaction(
                $sys_account_node_id,
                $agent_account_node_id,
                $offset_needed,
                'SYSTEM_ADJUSTMENT',
                1,
                0.0,
                null
            );
        }
        else {
            // Transfer from agent back to system (they had too much)
            $ledger_service->recordTransaction(
                $agent_account_node_id,
                $sys_account_node_id,
                abs($offset_needed),
                'SYSTEM_ADJUSTMENT',
                1,
                0.0,
                null
            );
        }

        $new_balance = $ledger_service->calculateBalance($agent_account_node_id);
        echo "Adjusted Agent '" . $agent->getAccountName() . "' by " . $offset_needed . " DT. New Balance: " . $new_balance . " DT\n";
        $count++;
    }
    catch (\Exception $e) {
        echo "Error adjusting Agent '" . $agent->getAccountName() . "': " . $e->getMessage() . "\n";
    }
}

echo "Completed. Adjusted balances for $count agents.\n";