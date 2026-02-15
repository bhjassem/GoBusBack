<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;

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

    /**
     * The current user.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected $currentUser;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        return $instance;
    }

    /**
     * Responds to GET requests.
     *
     * @return \Drupal\rest\ResourceResponse
     *   The HTTP response object.
     */
    public function get()
    {
        // 1. Check if user is authenticated
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $uid = $this->currentUser->id();

        // 2. Query Transactions
        $query = \Drupal::entityQuery('node')
            ->condition('type', 'transaction')
            ->condition('uid', $uid) // Only transactions created by this user (Agent)
            ->accessCheck(FALSE);

        $tids = $query->execute();

        // Initialize stats
        $recharge_count = 0;
        $total_recharge_amount = 0.0;
        $total_commission = 0.0;
        $collection_count = 0;
        $total_collection_amount = 0.0;
        $last_collection_date = null;

        if (!empty($tids)) {
            $transactions = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($tids);

            foreach ($transactions as $transaction) {
                $type = $transaction->get('field_transaction_type')->value;
                $amount = (float)$transaction->get('field_amount')->value;
                $commission = (float)$transaction->get('field_commission')->value;
                $created = $transaction->get('created')->value;

                if ($type === 'RELOAD') {
                    $recharge_count++;
                    $total_recharge_amount += $amount;
                    $total_commission += $commission;
                }
                elseif ($type === 'COLLECTION') {
                    $collection_count++;
                    $total_collection_amount += $amount;
                    // Track last collection date
                    if ($last_collection_date === null || $created > strtotime($last_collection_date)) {
                        $last_collection_date = date('Y-m-d H:i:s', $created);
                    }
                }
            }
        }

        // 3. Construct Response
        $stats_dto = [
            'recharge_count' => $recharge_count,
            'total_recharge_amount' => $total_recharge_amount,
            'total_commission' => $total_commission,
            'collection_count' => $collection_count,
            'total_collection_amount' => $total_collection_amount,
            'last_collection_date' => $last_collection_date,
            'period_label' => 'Total', // Default label
        ];

        return new ResourceResponse([
            'success' => true,
            'message' => 'Stats retrieved successfully',
            'data' => $stats_dto,
        ], 200);
    }

}