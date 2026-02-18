<?php

use Drupal\node\Entity\Node;

/**
 * Inspect transactions to see who they belong to.
 * Run with: drush php:script scripts/inspect_transactions.php
 */

$database = \Drupal::database();

echo "--- Last 10 Transactions ---\n";
$query = $database->select('node_field_data', 'n');
$query->leftJoin('node__field_client', 'c', 'n.nid = c.entity_id');
$query->leftJoin('node__field_transaction_type', 'tt', 'n.nid = tt.entity_id');
$query->leftJoin('node__field_amount', 'a', 'n.nid = a.entity_id');
$query->fields('n', ['nid', 'uid', 'title']);
$query->fields('c', ['field_client_target_id']);
$query->fields('tt', ['field_transaction_type_value']);
$query->fields('a', ['field_amount_value']);
$query->condition('n.type', 'transaction');
$query->orderBy('n.nid', 'DESC');
$query->range(0, 10);

$results = $query->execute()->fetchAll();

foreach ($results as $row) {
    echo "NID: {$row->nid} | Owner(UID): {$row->uid} | ClientID: {$row->field_client_target_id} | Type: {$row->field_transaction_type_value} | Amount: {$row->field_amount_value} | Title: {$row->title}\n";
}

echo "\n--- Summary by Client ---\n";
$summary_query = $database->select('node__field_client', 'c');
$summary_query->addExpression('COUNT(c.entity_id)', 'count');
$summary_query->fields('c', ['field_client_target_id']);
$summary_query->groupBy('field_client_target_id');
$sums = $summary_query->execute()->fetchAll();

foreach ($sums as $s) {
    echo "Client UID: {$s->field_client_target_id} | Transactions: {$s->count}\n";
}