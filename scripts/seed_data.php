<?php

use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;

// 1. Create Test Users
$users_data = [
    [
        'phone' => '20000001',
        'pass' => 'client123',
        'role' => 'client',
        'name' => 'Ahmed Client',
        'city' => 'Tunis',
        'verified' => true,
    ],
    [
        'phone' => '20000002',
        'pass' => 'client123',
        'role' => 'client',
        'name' => 'Sarra Client (Unverified)',
        'city' => 'Sousse',
        'verified' => false,
    ],
    [
        'phone' => '50000001',
        'pass' => 'agent123',
        'role' => 'agent',
        'name' => 'Mohamed Agent',
        'shop' => 'Kiosque Mohamed',
        'city' => 'Ariana',
        'access_code' => '1111',
        'verified' => true,
    ],
    [
        'phone' => '50000002',
        'pass' => 'agent123',
        'role' => 'agent',
        'name' => 'Amira Agent (Blocked)',
        'shop' => 'Tabac Amira',
        'city' => 'Bizerte',
        'access_code' => '2222',
        'status' => 0, // Blocked
        'verified' => true,
    ],
    [
        'phone' => '90000001',
        'pass' => 'captain123',
        'role' => 'captain',
        'name' => 'Salah Chauffeur',
        'city' => 'Sfax',
        'verified' => true,
    ],
];

foreach ($users_data as $data) {
    $exists = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $data['phone']]);
    if (!$exists) {
        $user = User::create();
        $user->setUsername($data['phone']);
        $user->setPassword($data['pass']);
        $user->setEmail($data['phone'] . '@example.com');
        $user->addRole($data['role']);
        $user->set('status', $data['status'] ?? 1);

        // Custom Fields
        $user->set('field_phone', $data['phone']);
        $user->set('field_full_name', $data['name']);
        $user->set('field_city', $data['city']);
        $user->set('field_is_verified', $data['verified']);

        if (isset($data['shop'])) {
            $user->set('field_shop_name', $data['shop']);
        }
        if (isset($data['access_code'])) {
            $user->set('field_access_code', $data['access_code']);
        }

        $user->save();
        print "Created User: " . $data['name'] . " (" . $data['role'] . ")\n";
    }
    else {
        print "User already exists: " . $data['name'] . "\n";
    }
}

// 2. Create Transactions (for Agent Mohamed)
$agent = array_values(\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => '50000001']))[0];
$client = array_values(\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => '20000001']))[0];

if ($agent && $client) {
    $transactions_data = [
        ['amount' => 10.00, 'commission' => 0.50, 'type' => 'RELOAD'],
        ['amount' => 5.00, 'commission' => 0.25, 'type' => 'RELOAD'],
        ['amount' => 20.00, 'commission' => 1.00, 'type' => 'RELOAD'],
        ['amount' => 100.00, 'commission' => 0.00, 'type' => 'COLLECTION'], // Encaissement (Agent verse à GoBus)
    ];

    foreach ($transactions_data as $tx) {
        $node = Node::create([
            'type' => 'transaction',
            'title' => 'Transaction ' . time(),
            'uid' => $agent->id(), // Author is Agent
            'field_amount' => $tx['amount'],
            'field_commission' => $tx['commission'],
            'field_transaction_type' => $tx['type'],
            'field_client' => $client->id(),
        ]);
        $node->save();
        print "Created Transaction: " . $tx['type'] . " " . $tx['amount'] . "DT\n";
    }
}