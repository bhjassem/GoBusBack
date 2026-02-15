<?php

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

// 1. Create Field Storage if it doesn't exist
$field_name = 'field_balance';
$entity_type = 'user';

$field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);

if (!$field_storage) {
    $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'decimal',
        'settings' => [
            'precision' => 19,
            'scale' => 3,
        ],
        'cardinality' => 1,
    ]);
    $field_storage->save();
    echo "Field storage '$field_name' created.\n";
}
else {
    echo "Field storage '$field_name' already exists.\n";
}

// 2. Create Field Instance if it doesn't exist
$field = FieldConfig::loadByName($entity_type, $entity_type, $field_name);

if (!$field) {
    $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => $entity_type,
        'label' => 'Current Balance',
        'settings' => [
            'min' => 0.0,
            'max' => NULL,
        ],
        'default_value' => [
            ['value' => 0.000],
        ],
    ]);
    $field->save();
    echo "Field instance '$field_name' created on user bundle.\n";

    // Assign to form display (optional but good for checking in backend)
    $form_display = \Drupal::service('entity_display.repository')
        ->getFormDisplay($entity_type, $entity_type, 'default');
    if ($form_display) {
        $form_display->setComponent($field_name, [
            'type' => 'number',
            'weight' => 20,
        ])->save();
    }

}
else {
    echo "Field instance '$field_name' already exists.\n";
}