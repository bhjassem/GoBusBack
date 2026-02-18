<?php

use Drupal\user\Entity\User;

/**
 * Debug script to see what's in the database.
 * Run with: drush php:script scripts/debug_user.php
 */

$phone_to_check = '98000001';
echo "Searching for phone: $phone_to_check ...\n";

$storage = \Drupal::entityTypeManager()->getStorage('user');

// Try 8-digit
$users = $storage->loadByProperties(['field_phone' => $phone_to_check]);
if (!empty($users)) {
    $u = reset($users);
    echo "FOUND 8-digit user! UID: " . $u->id() . " Name: " . $u->getAccountName() . "\n";
}
else {
    echo "NOT FOUND as 8-digit.\n";
}

// Try 9-digit
$phone_9 = '980000001';
$users_9 = $storage->loadByProperties(['field_phone' => $phone_9]);
if (!empty($users_9)) {
    $u = reset($users_9);
    echo "FOUND 9-digit user! UID: " . $u->id() . " Name: " . $u->getAccountName() . "\n";
}
else {
    echo "NOT FOUND as 9-digit.\n";
}

// Dump ALL agents to see what they look like
echo "\nDumping last 5 users...\n";
$query = $storage->getQuery();
$query->sort('uid', 'DESC');
$query->range(0, 5);
$query->accessCheck(FALSE);
$uids = $query->execute();
$all = $storage->loadMultiple($uids);
foreach ($all as $u) {
    $p = $u->hasField('field_phone') ? $u->get('field_phone')->value : 'NO FIELD';
    echo "UID: {$u->id()} | Name: {$u->getAccountName()} | Phone: $p | Status: " . $u->get('status')->value . "\n";
}