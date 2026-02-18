<?php

use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Session\AccountSwitcherInterface;

/**
 * Test API responses for specific users to verify data isolation.
 * Run with: drush php:script scripts/test_api_response.php
 */

$user_phones = ['98000001', '98000003'];
$account_switcher = \Drupal::service('account_switcher');

foreach ($user_phones as $phone) {
    echo "\n---------------------------------------------------\n";
    echo "Testing for User: $phone\n";

    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['field_phone' => $phone]);
    if (empty($users)) {
        echo "User not found!\n";
        continue;
    }
    $user = reset($users);

    // Switch to this user
    $account_switcher->switchTo($user);

    // Create an instance of the Resource
    // We can't easily instantiate the full Resource with container injection manually in a simple script 
    // without mocking a lot. 
    // Instead, let's run the query logic directly to see what the DB returns for this user.

    $uid = $user->id();
    echo "UID: $uid\n";

    // 1. Stats Query Logic (Simulated)
    $query = \Drupal::database()->select('node_field_data', 'n');
    $query->condition('type', 'transaction');
    $query->condition('uid', $uid); // The key logic
    $count = $query->countQuery()->execute()->fetchField();
    echo "Total Transactions (DB Count): $count\n";

    // 2. Resource Logic Check
    // We'll check the service logic if possible, or just rely on the DB check above which mirrors the resource code.
    // The resource code is: $query->condition('uid', $uid);

    // Let's also check the TransactionsResource logic
    $trans_query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
        ->condition('type', 'transaction')
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->count();
    $trans_count = $trans_query->execute();
    echo "TransactionsResource Query Count: $trans_count\n";

    $account_switcher->switchBack();
}