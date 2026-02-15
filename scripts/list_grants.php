<?php

$manager = \Drupal::service('plugin.manager.oauth2_grant.processor');
$definitions = $manager->getDefinitions();

echo "Available OAuth2 Grants:\n";
foreach ($definitions as $id => $def) {
    echo "- $id (" . $def['class'] . ")\n";
}