<?php

$id = 2;
$consumer = \Drupal\consumers\Entity\Consumer::load($id);

if ($consumer) {
    echo "Consumer ID: " . $consumer->id() . "\n";
    echo "Client ID Field: " . $consumer->get('client_id')->value . "\n";
    echo "UUID: " . $consumer->uuid() . "\n";

    $grant_types = $consumer->get('grant_types')->getValue();
    echo "Grant Types Count: " . count($grant_types) . "\n";
    foreach ($grant_types as $delta => $item) {
        echo " - Delta $delta: " . $item['value'] . "\n";
    }
}
else {
    echo "Consumer $id not found.\n";
}