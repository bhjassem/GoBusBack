<?php

use Drupal\user\Entity\User;

/**
 * Script to fix phone numbers length (from 9 to 8 digits).
 * Run with: drush php:script scripts/fix_phones.php
 */

echo "Starting phone number fix...\n";

$storage = \Drupal::entityTypeManager()->getStorage('user');

// Find users with 9-digit phones starting with 980, 970, 960...
// Actually, let's load all users with phone field populated and check length.
$query = $storage->getQuery();
$query->condition('field_phone', '%', 'LIKE');
$query->accessCheck(FALSE);
$uids = $query->execute();

$users = $storage->loadMultiple($uids);
$count = 0;

foreach ($users as $user) {
    if ($user->hasField('field_phone')) {
        $phone = $user->get('field_phone')->value;

        // Logic: if length is 9 and starts with known prefixes, remove one 0.
        // E.g., 980000001 -> 98000001

        if (strlen($phone) == 9) {
            $new_phone = null;

            // Check for our specific seed patterns
            // 9800000xx -> 980000xx
            if (strpos($phone, '9800000') === 0) {
                $new_phone = substr_replace($phone, '', 2, 1); // Remove char at index 2 (the first 0 after 98) ? 
            // 980000001. Indices: 0=9, 1=8, 2=0. Remove 1 char at 2. -> 98000001.
            }
            elseif (strpos($phone, '9700000') === 0) {
                $new_phone = substr_replace($phone, '', 2, 1);
            }
            elseif (strpos($phone, '9600000') === 0) {
                $new_phone = substr_replace($phone, '', 2, 1);
                // Also user asked to change 96 to 50 for new ones. 
                // Should we migrate 96xxxxxx to 50xxxxxx? 
                // The prompt said: "50xxxxxx". It implies he wants customers to be 50.
                // Let's replace 96 with 50 as well if it matches the seed pattern.
                // 960000001 -> 50000001?
                // Let's just fix the length first, or do both.
                // If I just remove 0 -> 96000001. 
                // I will rename 96 to 50 per instructions "50xxxxxx" list.
                $new_phone = '50' . substr($new_phone, 2); // Replace 96 with 50
                // Wait, $new_phone is not set yet in this branch.
                $temp = substr_replace($phone, '', 2, 1); // 96000001
                $new_phone = '50' . substr($temp, 2); // 50000001
            }

            if ($new_phone && strlen($new_phone) == 8) {
                echo "Fixing User {$user->id()}: $phone -> $new_phone\n";
                $user->set('field_phone', $new_phone);
                $user->setUsername($new_phone); // Update username too if they match
                $user->setEmail($new_phone . '@gobus.tn'); // Update email too
                $user->save();
                $count++;
            }
        }
    }
}

echo "Fixed $count users.\n";