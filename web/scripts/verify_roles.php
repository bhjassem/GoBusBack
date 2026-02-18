<?php

use Drupal\user\Entity\User;

/**
 * Verify user roles to ensure the LoginResource check will work as intended.
 * Run with: drush php:script scripts/verify_roles.php
 */

$phones_to_check = ['98000001', '970000001']; // Agent vs Captain

echo "\n--- Verifying User Roles ---\n";

foreach ($phones_to_check as $phone) {
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $phone]);
    if (empty($users)) {
        echo "User $phone not found.\n";
        continue;
    }
    $user = reset($users);
    $roles = $user->getRoles();

    echo "User: " . $phone . " (ID: " . $user->id() . ")\n";
    echo "Roles: " . implode(', ', $roles) . "\n";
    echo "Has 'agent' role? " . ($user->hasRole('agent') ? "YES" : "NO") . "\n";
    echo "---------------------------\n";
}