<?php

use Drupal\user\Entity\User;

/**
 * Script to reset passwords for all test users to 'password123'.
 * Run with: drush php:script scripts/reset_passwords.php
 */

echo "Starting password reset...\n";

$storage = \Drupal::entityTypeManager()->getStorage('user');

// Find all users with phone numbers like our test patterns (8 or 9 digits)
// Patterns: 98% (Agents), 97% (Drivers), 96% (Old Clients), 50% (New Clients)
$query = $storage->getQuery();
$group = $query->orConditionGroup()
    ->condition('name', '98%', 'LIKE')
    ->condition('name', '97%', 'LIKE')
    ->condition('name', '96%', 'LIKE')
    ->condition('name', '50%', 'LIKE');
$query->condition($group);
$query->accessCheck(FALSE);
$uids = $query->execute();

$users = $storage->loadMultiple($uids);
$count = 0;

foreach ($users as $user) {
    // Reset password to their own login (phone number) for easier JDD
    $password = $user->getAccountName();
    $user->setPassword($password);
    $user->save();
    echo "Reset password for User {$user->id()} ({$user->getAccountName()}) -> Password: $password\n";
    $count++;
}

echo "Reset passwords for $count users.\n";