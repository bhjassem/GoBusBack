<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides a resource to get transaction statistics.
 *
 * @RestResource(
 *   id = "gobus_api_stats",
 *   label = @Translation("GoBus API Stats"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/transactions/stats"
 *   }
 * )
 */
class StatsResource extends ResourceBase
{
    protected $currentUser;
    protected $database;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        $instance->database = $container->get('database');
        return $instance;
    }

    public function get()
    {
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Unauthorized. Please login.',
            ], 401);
        }

        $uid = $this->currentUser->id();

        // Reload stats
        $reload_query = $this->database->select('node_field_data', 'n');
        $reload_query->join('node__field_amount', 'a', 'n.nid = a.entity_id');
        $reload_query->join('node__field_transaction_type', 'tt', 'n.nid = tt.entity_id');
        $reload_query->leftJoin('node__field_commission', 'c', 'n.nid = c.entity_id');
        $reload_query->condition('n.type', 'transaction');
        $reload_query->condition('n.uid', $uid);
        $reload_query->condition('tt.field_transaction_type_value', 'RELOAD');
        $reload_query->addExpression('COUNT(n.nid)', 'reload_count');
        $reload_query->addExpression('COALESCE(SUM(a.field_amount_value), 0)', 'reload_total');
        $reload_query->addExpression('COALESCE(SUM(c.field_commission_value), 0)', 'commission_total');
        $reload_result = $reload_query->execute()->fetchAssoc();

        // Collection stats
        $collection_query = $this->database->select('node_field_data', 'n');
        $collection_query->join('node__field_amount', 'a', 'n.nid = a.entity_id');
        $collection_query->join('node__field_transaction_type', 'tt', 'n.nid = tt.entity_id');
        $collection_query->condition('n.type', 'transaction');
        $collection_query->condition('n.uid', $uid);
        $collection_query->condition('tt.field_transaction_type_value', 'COLLECTION');
        $collection_query->addExpression('COUNT(n.nid)', 'collection_count');
        $collection_query->addExpression('COALESCE(SUM(a.field_amount_value), 0)', 'collection_total');
        $collection_query->addExpression('MAX(n.created)', 'last_collection');
        $collection_result = $collection_query->execute()->fetchAssoc();

        $last_collection_date = null;
        if (!empty($collection_result['last_collection'])) {
            $last_collection_date = date('c', (int)$collection_result['last_collection']);
        }

        return new ResourceResponse([
            'success' => true,
            'data' => [
                'recharge_count' => (int)($reload_result['reload_count'] ?? 0),
                'total_recharge_amount' => (float)($reload_result['reload_total'] ?? 0),
                'total_commission' => (float)($reload_result['commission_total'] ?? 0),
                'collection_count' => (int)($collection_result['collection_count'] ?? 0),
                'total_collection_amount' => (float)($collection_result['collection_total'] ?? 0),
                'last_collection_date' => $last_collection_date,
            ]
        ], 200);
    }
}