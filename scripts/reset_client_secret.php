<?php

use Drupal\consumers\Entity\Consumer;

// Load by ID to be sure
$consumer = Consumer::load(2);

if ($consumer) {
    // Update UUID and Label to be consistent
    $uuid = 'gobus-reload-app-id';
    $consumer->set('uuid', $uuid);
    $consumer->set('label', 'GoBus Reload App');
    
    // Set secret in PLAIN TEXT - The module should hash it on save
    $consumer->set('secret', 'gobus_reload_secret');
    
    // Also set client_id field if it exists (it was NULL in DB)
    if ($consumer->hasField('client_id')) {
        $consumer->set('client_id', $uuid);
    }

    $consumer->save();
    echo "Updated Consumer (ID: " . $consumer->id() . ").\n";
    echo "UUID: " . $consumer->uuid() . "\n";
    echo "Secret reset to: gobus_reload_secret (Hashed on save)\n";
} else {
    echo "Consumer ID 2 not found.\n";
}