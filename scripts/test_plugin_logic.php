<?php

use Drupal\consumers\Entity\Consumer;

$consumer = Consumer::load(2);
$manager = \Drupal::service('plugin.manager.oauth2_grant.processor');

try {
    $plugin = $manager->createInstance('password');
    echo "Plugin instance created: " . get_class($plugin) . "\n";

    $grant = $plugin->getGrantType($consumer);
    echo "Grant object retrieved: " . get_class($grant) . "\n";
    echo "Grant identifier: " . $grant->getIdentifier() . "\n";

}
catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}