<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to get transaction history.
 *
 * @RestResource(
 *   id = "gobus_api_transactions",
 *   label = @Translation("GoBus API Transactions"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/transactions"
 *   }
 * )
 */
class TransactionsResource extends ResourceBase
{
    protected $currentUser;
    protected $entityTypeManager;
    protected $database;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        $instance->entityTypeManager = $container->get('entity_type.manager');
        $instance->database = $container->get('database');
        return $instance;
    }

    public function get()
    {
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $request = \Drupal::request();
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(50, max(1, (int)$request->query->get('limit', 20)));
        $type_filter = $request->query->get('type'); // RELOAD, COLLECTION
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $offset = ($page - 1) * $limit;

        $uid = $this->currentUser->id();

        // Count query
        $count_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'transaction')
            ->condition('uid', $uid)
            ->accessCheck(FALSE)
            ->count();

        if ($type_filter) {
            $count_query->condition('field_transaction_type', $type_filter);
        }
        if ($from) {
            $count_query->condition('created', (int)$from, '>=');
        }
        if ($to) {
            $count_query->condition('created', (int)$to, '<=');
        }

        $total_items = (int)$count_query->execute();

        // Data query
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'transaction')
            ->condition('uid', $uid)
            ->sort('created', 'DESC')
            ->accessCheck(FALSE)
            ->range($offset, $limit);

        if ($type_filter) {
            $query->condition('field_transaction_type', $type_filter);
        }
        if ($from) {
            $query->condition('created', (int)$from, '>=');
        }
        if ($to) {
            $query->condition('created', (int)$to, '<=');
        }

        $nids = $query->execute();
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

        $transactions = [];
        $user_storage = $this->entityTypeManager->getStorage('user');

        foreach ($nodes as $node) {
            $client_account_id = '';
            $client_name = '';

            $client_ref = $node->get('field_client')->target_id;
            if ($client_ref) {
                $client = $user_storage->load($client_ref);
                if ($client) {
                    $client_account_id = $client->get('field_account_id')->getString();
                    $client_name = $client->get('field_full_name')->getString() ?: $client->getAccountName();
                }
            }

            $transactions[] = [
                'id' => (string)$node->id(),
                'client_account_id' => $client_account_id,
                'client_name' => $client_name,
                'amount' => (float)$node->get('field_amount')->getString(),
                'commission' => (float)$node->get('field_commission')->getString(),
                'type' => $node->get('field_transaction_type')->getString(),
                'timestamp' => $node->getCreatedTime(),
                'created_at' => date('c', $node->getCreatedTime()),
            ];
        }

        $total_pages = (int)ceil($total_items / $limit);
        $has_more = $page < $total_pages;

        if (count($transactions) < $limit && $page >= $total_pages) {
            $has_more = false;
        }

        $response = new ResourceResponse([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => $total_items,
                'has_more' => $has_more,
            ],
        ], 200);

        $response->getCacheableMetadata()->setCacheMaxAge(0);
        $response->getCacheableMetadata()->addCacheContexts(['user', 'url.query_args']);
        return $response;
    }
}