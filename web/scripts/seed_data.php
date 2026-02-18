<?php

/**
 * @file
 * Seeder script for GoBus mock data.
 * Run with: drush php:script scripts/seed_data.php
 */

use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;

// --- CONFIGURATION ---
$num_agents = 10;
$num_drivers = 15;
$num_customers = 50;
$transactions_per_agent_min = 5;
$transactions_per_agent_max = 40;

$cities = ['Tunis', 'Sousse', 'Sfax', 'Bizerte', 'Gabès', 'Kairouan', 'Gafsa', 'Monastir', 'Ariana', 'Ben Arous'];
$shop_types = ['Boutique', 'Kiosque', 'Cafétéria', 'Alimentation Générale', 'Télécentre'];

// --- HELPERS ---

function create_user_if_not_exists($phone, $role, $data)
{
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['field_phone' => $phone]);
    if (!empty($users)) {
        $user = reset($users);
        echo "Updating existing user: $phone ($role)\n";
    }
    else {
        $user = User::create();
        $user->setUsername($phone);
        $user->setEmail($phone . '@gobus.tn'); // Mock email
        $user->setPassword('password123'); // Simple password for all
        $user->enforceIsNew();
        echo "Creating new user: $phone ($role)\n";
    }

    $user->addRole($role);
    $user->set('field_phone', $phone);
    $user->set('field_full_name', $data['name']);
    $user->set('field_city', $data['city']);
    if (isset($data['shop']))
        $user->set('field_shop_name', $data['shop']);
    $user->set('field_balance', $data['balance'] ?? 0);
    $user->set('field_is_verified', 1);
    $user->activate();

    // Randomize created time within the last 6 months
    $created_time = time() - mt_rand(0, 180 * 24 * 3600);
    $user->set('created', $created_time);

    $user->save();
    return $user;
}

function create_transaction($agent_uid, $customer_uid, $amount, $type, $date_offset)
{
    $node = Node::create([
        'type' => 'transaction',
        'title' => 'Transaction ' . $type . ' - ' . date('Y-m-d', time() - $date_offset),
        'field_amount' => $amount,
        'field_client' => ['target_id' => $customer_uid],
        'field_transaction_type' => $type,
        'field_commission' => ($type == 'RELOAD') ? $amount * 0.05 : 0, // 5% mock commission
        'created' => time() - $date_offset,
        'uid' => $agent_uid, // Set Agent as author
        'status' => 1,
    ]);
    $node->save();
}

// --- MAIN ---

echo "Starting data seeding...\n";

// 1. Create Agents
$agents = [];
for ($i = 1; $i <= $num_agents; $i++) {
    $phone = '980000' . str_pad($i, 2, '0', STR_PAD_LEFT);
    $agents[] = create_user_if_not_exists($phone, 'agent', [
        'name' => 'Agent ' . $i,
        'city' => $cities[array_rand($cities)],
        'shop' => $shop_types[array_rand($shop_types)] . ' ' . $i,
        'balance' => mt_rand(50, 2000),
    ]);
}

// 2. Create Drivers
for ($i = 1; $i <= $num_drivers; $i++) {
    $phone = '9700000' . str_pad($i, 2, '0', STR_PAD_LEFT);
    create_user_if_not_exists($phone, 'captain', [
        'name' => 'Chauffeur ' . $i,
        'city' => $cities[array_rand($cities)],
    ]);
}

// 3. Create Customers
$customers = [];
for ($i = 1; $i <= $num_customers; $i++) {
    $phone = '500000' . str_pad($i, 2, '0', STR_PAD_LEFT);
    $customers[] = create_user_if_not_exists($phone, 'client', [
        'name' => 'Client ' . $i,
        'city' => $cities[array_rand($cities)],
    ]);
}

// 4. Create Transactions for Agents
foreach ($agents as $agent) {
    $count = mt_rand($transactions_per_agent_min, $transactions_per_agent_max);
    echo "Seeding $count transactions for Agent: " . $agent->get('field_phone')->value . "\n";
    for ($j = 0; $j < $count; $j++) {
        $type = (mt_rand(0, 100) > 20) ? 'RELOAD' : 'COLLECTION';
        $amount = mt_rand(5, 100);

        // Date offsets: 5 today, 10 this week, 15 more
        if ($j < 5) {
            $offset = mt_rand(0, 3600 * 12); // Today
        }
        elseif ($j < 15) {
            $offset = mt_rand(1 * 24 * 3600, 6 * 24 * 3600); // This week
        }
        else {
            $offset = mt_rand(7 * 24 * 3600, 90 * 24 * 3600); // More (up to 3 months)
        }

        $random_customer = $customers[array_rand($customers)];
        create_transaction($agent->id(), $random_customer->id(), $amount, $type, $offset);
    }
}

echo "Data seeding completed successfully!\n";
echo "Data seeding completed successfully!\n";