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
    protected $requestStack;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        $instance->database = $container->get('database');
        $instance->requestStack = $container->get('request_stack');
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
        $request = $this->requestStack->getCurrentRequest();

        $since_last_collection = $request->query->get('since_last_collection') === 'true';
        $from = $request->query->get('from'); // Optional timestamp
        $to = $request->query->get('to'); // Optional timestamp

        // 1. Find the date of the LAST collection (if needed for filtering)
        $last_collection_timestamp = null;

        // Always fetch last collection date for response info
        $last_collection_query = $this->database->select('node_field_data', 'n');
        $last_collection_query->join('node__field_transaction_type', 'tt', 'n.nid = tt.entity_id');
        $last_collection_query->condition('n.type', 'transaction');
        $last_collection_query->condition('n.uid', $uid);
        $last_collection_query->condition('tt.field_transaction_type_value', 'COLLECTION');
        $last_collection_query->addExpression('MAX(n.created)', 'last_collection');
        $last_collection_result = $last_collection_query->execute()->fetchField();

        if ($last_collection_result) {
            $last_collection_timestamp = (int)$last_collection_result;
        }

        // Determine filter start time
        $filter_start_time = null;

        if ($since_last_collection && $last_collection_timestamp) {
            $filter_start_time = $last_collection_timestamp;
        }

        // Verify 'from' parameter precedence or combination?
        // Usually, explicit 'from' overrides 'since_last_collection' if both present, 
        // or we handle them as "AND". But logical requirement implies "since last collection" 
        // is automatic. If 'from' is provided (e.g. today), we use that.
        if ($from) {
            $filter_start_time = (int)$from;
        }

        // 2. Query Statistics (Reloads)
        $reload_query = $this->database->select('node_field_data', 'n');
        $reload_query->innerJoin('node__field_client', 'c', 'n.nid = c.entity_id');
        $reload_query->join('node__field_amount', 'a', 'n.nid = a.entity_id');
        $reload_query->join('node__field_transaction_type', 'tt', 'n.nid = tt.entity_id');
        $reload_query->leftJoin('node__field_commission', 'com', 'n.nid = com.entity_id');
        $reload_query->condition('n.type', 'transaction');
        $reload_query->condition('n.uid', $uid);
        $reload_query->condition('tt.field_transaction_type_value', 'RELOAD');

        // Apply date filters
        if ($filter_start_time) {
            $reload_query->condition('n.created', $filter_start_time, '>=');
        }
        if ($to) {
            $reload_query->condition('n.created', (int)$to, '<=');
        }

        $reload_query->addExpression('COUNT(n.nid)', 'reload_count');
        $reload_query->addExpression('COALESCE(SUM(a.field_amount_value), 0)', 'reload_total');
        $reload_query->addExpression('COALESCE(SUM(com.field_commission_value), 0)', 'commission_total');
        // Calculate unique clients served
        $reload_query->addExpression('COUNT(DISTINCT c.field_client_target_id)', 'unique_clients');

        $reload_result = $reload_query->execute()->fetchAssoc();

        // 3. Query Statistics (Collections)
        $collection_query = $this->database->select('node_field_data', 'n');
        $collection_query->innerJoin('node__field_client', 'c', 'n.nid = c.entity_id');
        $collection_query->join('node__field_amount', 'a', 'n.nid = a.entity_id');
        $collection_query->join('node__field_transaction_type', 'tt', 'n.nid = tt.entity_id');
        $collection_query->condition('n.type', 'transaction');
        $collection_query->condition('n.uid', $uid);
        $collection_query->condition('tt.field_transaction_type_value', 'COLLECTION');

        if ($filter_start_time) {
            $collection_query->condition('n.created', $filter_start_time, '>=');
        }
        if ($to) {
            $collection_query->condition('n.created', (int)$to, '<=');
        }

        $collection_query->addExpression('COUNT(n.nid)', 'collection_count');
        $collection_query->addExpression('COALESCE(SUM(a.field_amount_value), 0)', 'collection_total');

        $collection_result = $collection_query->execute()->fetchAssoc();

        // Format Date
        $last_collection_date_formatted = null;
        if ($last_collection_timestamp) {
            $last_collection_date_formatted = date('c', $last_collection_timestamp);
        }

        // Period Label logic
        $period_label = 'Toujours';
        if ($since_last_collection) {
            $period_label = 'Depuis dernière collecte';
        }
        elseif ($from) {
            $is_today = date('Y-m-d', (int)$from) === date('Y-m-d');
            $period_label = $is_today ? "Aujourd'hui" : 'Période personnalisée';
        }

        $response = new ResourceResponse([
            'success' => true,
            'data' => [
                'recharge_count' => (int)($reload_result['reload_count'] ?? 0),
                'total_recharge_amount' => (float)($reload_result['reload_total'] ?? 0),
                'total_commission' => (float)($reload_result['commission_total'] ?? 0),
                'clients_served' => (int)($reload_result['unique_clients'] ?? 0), // New field for unique clients
                'collection_count' => (int)($collection_result['collection_count'] ?? 0),
                'total_collection_amount' => (float)($collection_result['collection_total'] ?? 0),
                'last_collection_date' => $last_collection_date_formatted,
                'period_label' => $period_label
            ]
        ], 200);

        // Disable caching for this resource
        $response->getCacheableMetadata()->setCacheMaxAge(0);
        $response->getCacheableMetadata()->addCacheContexts(['user', 'url.query_args']);
        return $response;
    }
}