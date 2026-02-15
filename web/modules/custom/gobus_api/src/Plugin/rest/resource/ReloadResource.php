<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Provides a resource to reload account balance.
 *
 * @RestResource(
 *   id = "gobus_api_reload",
 *   label = @Translation("GoBus API Reload"),
 *   uri_paths = {
 *     "create" = "/api/v1/reload"
 *   }
 * )
 */
class ReloadResource extends ResourceBase
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
     * Responds to POST requests.
     *
     * @param array $data
     *   The data containing 'client_account_id' and 'amount'.
     *
     * @return \Drupal\rest\ResourceResponse
     *   The HTTP response object.
     */
    public function post($data)
    {
        // 1. Check permissions
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        // Ideally check if user has 'agent' role here too, but permission system handles route access.

        // 2. Validate Input
        if (empty($data['client_account_id'])) {
            throw new BadRequestHttpException("Missing client_account_id");
        }
        if (empty($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new BadRequestHttpException("Invalid amount");
        }

        $client_account_id = $data['client_account_id'];
        $amount = (float)$data['amount'];
        $commission = $amount * 0.01; // 1% commission
        // Note: Business logic might deduct commission from amount or add it. 
        // Usually reload amount is what client pays. Commission is what agent earns.
        // For now assuming: Client gets full amount, Agent earns commission separately (or irrelevant for balance).
        // Let's stick to simple: Balance += Amount.

        // 3. Find Client by Account ID (field_access_code)
        $users = \Drupal::entityTypeManager()->getStorage('user')
            ->loadByProperties(['field_access_code' => $client_account_id]);

        if (empty($users)) {
            return new ResourceResponse(['success' => false, 'message' => 'Client not found'], 404);
        }

        $client = reset($users);

        try {
            // 4. Update Client Balance
            $current_balance = (float)$client->get('field_balance')->value;
            $new_balance = $current_balance + $amount;
            $client->set('field_balance', $new_balance);
            $client->save();

            // 5. Create Transaction Record
            $transaction = Node::create([
                'type' => 'transaction',
                'title' => 'Reload ' . $client_account_id . ' - ' . date('Y-m-d H:i'),
                'field_amount' => $amount,
                'field_commission' => $commission,
                'field_transaction_type' => 'RELOAD',
                // 'field_client' => $client->id(), // If we had a reference field
                'uid' => $this->currentUser->id(), // Created by Agent
            ]);
            $transaction->save();

            // 6. Return Response matching app expectations (or define new structure)
            // App expects something like:
            /*
             NewReloadEffect.NavigateToSuccess(
             transactionId = transactionId,
             amount = formatString("%.3f", currentState.amount),
             commission = formatString("%.3f", currentState.commission),
             clientName = currentState.client.fullName,
             accountId = currentState.client.accountId
             )
             */

            return new ResourceResponse([
                'success' => true,
                'message' => 'Reload successful',
                'data' => [
                    'transaction_id' => $transaction->id(),
                    'new_balance' => $new_balance,
                    'amount' => $amount,
                    'commission' => $commission,
                    'client_name' => $client->get('field_full_name')->value,
                    'account_id' => $client_account_id,
                    'timestamp' => time(),
                ]
            ], 200);

        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error($e->getMessage());
            return new ResourceResponse(['success' => false, 'message' => 'Internal Transaction Error'], 500);
        }
    }

}