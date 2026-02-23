<?php
/**
 * @file
 * Step 1 Migration: Create `gobus_account` nodes for all existing users.
 * Run via: drush scr scripts/migrate_ledger_1_accounts.php
 */

use Drupal\node\Entity\Node;

echo "=== Ledger Setup Step 1: Account Creation ===\n\n";

$users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple();
$count = 0;

foreach ($users as $user) {
    if ($user->id() == 0 || $user->id() == 1)
        continue; // Skip anonymous and root admin

    // Check if account already exists
    $existing = \Drupal::entityTypeManager()->getStorage('node')
        ->loadByProperties([
        'type' => 'gobus_account',
        'field_account_owner' => $user->id()
    ]);

    if (!empty($existing)) {
        // echo "  [SKIP] Account already exists for User ID: " . $user->id() . "\n";
        continue;
    }

    $roles = $user->getRoles();
    $role_name = 'unknown';
    if (in_array('client', $roles))
        $role_name = 'client';
    elseif (in_array('agent', $roles))
        $role_name = 'agent';
    elseif (in_array('captain', $roles))
        $role_name = 'captain';

    // Only create accounts for relevant users
    if ($role_name === 'unknown')
        continue;

    // Use field_account_id if available, otherwise generate
    $ledger_id = '';
    if ($user->hasField('field_account_id') && !$user->get('field_account_id')->isEmpty()) {
        $ledger_id = 'ACC-' . $user->get('field_account_id')->getString();
    }
    else {
        $ledger_id = 'ACC-' . strtoupper($role_name) . '-' . str_pad($user->id(), 5, '0', STR_PAD_LEFT);
    }

    try {
        $account = Node::create([
            'type' => 'gobus_account',
            'title' => $role_name . ' Account - ' . $user->getAccountName(),
            'field_account_owner' => ['target_id' => $user->id()],
            'field_account_type' => $role_name,
            'field_ledger_id' => $ledger_id,
            'uid' => 1, // Admin owns the node record
        ]);
        $account->save();
        echo "  [OK] Created Account '{$ledger_id}' for User ID {$user->id()}.\n";
        $count++;
    }
    catch (\Exception $e) {
        echo "  [ERROR] User {$user->id()}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Created $count accounts.\n";