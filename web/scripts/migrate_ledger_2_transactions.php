<?php
/**
 * @file
 * Step 2 Migration: Retro-fit existing transactions into double-entry ledger.
 * Run via: drush scr scripts/migrate_ledger_2_transactions.php
 */

use Drupal\node\Entity\Node;

echo "=== Ledger Setup Step 2: Transaction Migration ===\n\n";

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');

// Get all transactions
$nids = $node_storage->getQuery()
    ->condition('type', 'transaction')
    ->accessCheck(FALSE)
    ->execute();

$transactions = $node_storage->loadMultiple($nids);
$count = 0;
$skipped = 0;

// Get or create System Account
$system_accounts = $node_storage->loadByProperties(['type' => 'gobus_account', 'field_ledger_id' => 'ACC-SYS-MAIN']);
if (empty($system_accounts)) {
    $system_account = Node::create([
        'type' => 'gobus_account',
        'title' => 'System Main Account',
        'field_account_owner' => ['target_id' => 1], // Admin
        'field_account_type' => 'system',
        'field_ledger_id' => 'ACC-SYS-MAIN',
        'uid' => 1,
    ]);
    $system_account->save();
}
else {
    $system_account = reset($system_accounts);
}
$sys_acc_id = $system_account->id();

// Helper to get account by user ID
function get_account_id_for_user($uid)
{
    if (!$uid) return NULL;
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $accounts = $node_storage->loadByProperties([
        'type' => 'gobus_account',
        'field_account_owner' => $uid,
    ]);
    if (!empty($accounts)) {
        return reset($accounts)->id();
    }
    return NULL;
}

foreach ($transactions as $txn) {
    // If it already has from/to, skip
    $has_from = !$txn->get('field_from_account')->isEmpty();
    $has_to = !$txn->get('field_to_account')->isEmpty();

    if ($has_from && $has_to) {
        $skipped++;
        continue;
    }

    $type = $txn->get('field_transaction_type')->getString();
    $creator_uid = $txn->getOwnerId(); // The agent or admin who created it

    if ($type === 'RELOAD') {
        // Agent -> Client
        $agent_acc_id = get_account_id_for_user($creator_uid);

        $client_uid = $txn->get('field_client')->target_id;
        $client_acc_id = get_account_id_for_user($client_uid);

        if ($agent_acc_id && $client_acc_id) {
            $txn->set('field_from_account', $agent_acc_id);
            $txn->set('field_to_account', $client_acc_id);
            $txn->save();
            $count++;
        // echo "  [OK] Migrated RELOAD Txn {$txn->id()}\n";
        }
        else {
            echo "  [WARN] Missing accounts for RELOAD Txn {$txn->id()} (Agent: {$agent_acc_id}, Client: {$client_acc_id})\n";
        }
    }
    elseif ($type === 'COLLECTION') {
        // System -> Agent
        $agent_acc_id = get_account_id_for_user($creator_uid);

        if ($agent_acc_id) {
            $txn->set('field_from_account', $sys_acc_id);
            $txn->set('field_to_account', $agent_acc_id);
            $txn->save();
            $count++;
        // echo "  [OK] Migrated COLLECTION Txn {$txn->id()}\n";
        }
        else {
            echo "  [WARN] Missing account for COLLECTION Txn {$txn->id()} (Agent UID: {$creator_uid})\n";
        }
    }
    else {
        echo "  [WARN] Unknown transaction type '{$type}' for Txn {$txn->id()}\n";
    }
}

echo "\nDone. Migrated {$count} transactions, skipped {$skipped}.\n";