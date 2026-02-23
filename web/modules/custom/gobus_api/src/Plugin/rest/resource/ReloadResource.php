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
        // 0. Rate Limiting: 30 attempts per minute per user
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.reload', $rateLimiter::getCurrentUserId(), 30, 60);
        if ($limited) return $limited;

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

        // 3. Find Client by Account ID
        $users = \Drupal::entityTypeManager()->getStorage('user')
            ->loadByProperties(['field_account_id' => $client_account_id]);

        if (empty($users)) {
            return new ResourceResponse(['success' => false, 'message' => 'Client not found'], 404);
        }

        $client = reset($users);

        try {
            $ledger_service = \Drupal::service('gobus_api.ledger');

            // 4. Get Accounts
            $agent_user = User::load($this->currentUser->id());
            $agent_account_id = $ledger_service->getOrCreateAccountForUser($agent_user);

            if (!$agent_account_id) {
                return new ResourceResponse(['success' => false, 'message' => 'Agent account error.'], 400);
            }

            // Optional: Check Agent Balance
            $agent_balance = $ledger_service->calculateBalance($agent_account_id);
            if ($agent_balance < $amount) {
                return new ResourceResponse(['success' => false, 'message' => 'Insufficient agent balance.'], 400);
            }

            $client_account_node_id = $ledger_service->getOrCreateAccountForUser($client);
            if (!$client_account_node_id) {
                return new ResourceResponse(['success' => false, 'message' => 'Client account error.'], 400);
            }

            // 5. Create Transaction Record via Ledger
            $transaction = $ledger_service->recordTransaction(
                $agent_account_id,
                $client_account_node_id,
                $amount,
                'RELOAD',
                $this->currentUser->id(),
                $commission,
                $client->id()
            );

            $new_balance = $ledger_service->calculateBalance($client_account_node_id);

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