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
 * Uses idempotency keys to prevent duplicate transactions:
 * - Client MUST send an "Idempotency-Key" header (UUID v4) with every request.
 * - If the key was already processed, the stored response is returned.
 * - If the key is new, the transaction is executed and the result is stored.
 * - Race conditions are handled via a UNIQUE DB constraint.
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

        // 1. Check permissions (defense-in-depth: route requires _user_is_logged_in)
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // 2. Idempotency Key — REQUIRED to prevent duplicate transactions
        $request = \Drupal::request();
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        $idempotencyService = \Drupal::service('gobus_api.idempotency');

        if (empty($idempotencyKey)) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Idempotency-Key header is required.',
            ], 400);
        }

        if (!$idempotencyService->isValidKey($idempotencyKey)) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Idempotency-Key must be a valid UUID v4.',
            ], 400);
        }

        $userId = (int) $this->currentUser->id();

        // 2a. Check if this key was already processed → return cached response
        $existing = $idempotencyService->getExistingResponse($idempotencyKey, $userId);
        if ($existing) {
            return new ResourceResponse($existing['body'], $existing['code']);
        }

        // 3. Validate Input
        if (empty($data['client_account_id'])) {
            throw new BadRequestHttpException("Missing client_account_id");
        }
        if (empty($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new BadRequestHttpException("Invalid amount");
        }

        $client_account_id = $data['client_account_id'];
        $amount = (float)$data['amount'];
        $commission = $amount * 0.01; // 1% commission

        // 4. Find Client by Account ID
        $users = \Drupal::entityTypeManager()->getStorage('user')
            ->loadByProperties(['field_account_id' => $client_account_id]);

        if (empty($users)) {
            $errorBody = ['success' => false, 'message' => 'Client not found'];
            $idempotencyService->storeResponse($idempotencyKey, $userId, '/api/v1/reload', 404, $errorBody);
            return new ResourceResponse($errorBody, 404);
        }

        $client = reset($users);

        try {
            $ledger_service = \Drupal::service('gobus_api.ledger');

            // 5. Get Accounts
            $agent_user = User::load($this->currentUser->id());
            $agent_account_id = $ledger_service->getOrCreateAccountForUser($agent_user);

            if (!$agent_account_id) {
                $errorBody = ['success' => false, 'message' => 'Agent account error.'];
                $idempotencyService->storeResponse($idempotencyKey, $userId, '/api/v1/reload', 400, $errorBody);
                return new ResourceResponse($errorBody, 400);
            }

            // 5a. Check Agent Balance
            $agent_balance = $ledger_service->calculateBalance($agent_account_id);
            if ($agent_balance < $amount) {
                $errorBody = ['success' => false, 'message' => 'Insufficient agent balance.'];
                // Do NOT store insufficient balance — agent may retry after receiving funds.
                return new ResourceResponse($errorBody, 400);
            }

            $client_account_node_id = $ledger_service->getOrCreateAccountForUser($client);
            if (!$client_account_node_id) {
                $errorBody = ['success' => false, 'message' => 'Client account error.'];
                $idempotencyService->storeResponse($idempotencyKey, $userId, '/api/v1/reload', 400, $errorBody);
                return new ResourceResponse($errorBody, 400);
            }

            // 6. Create Transaction Record via Ledger
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

            $successBody = [
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
            ];

            // 7. Store the successful response for this idempotency key.
            // If storeResponse returns FALSE (race condition), another request
            // already completed — that's fine, the transaction was already recorded.
            $idempotencyService->storeResponse($idempotencyKey, $userId, '/api/v1/reload', 200, $successBody);

            return new ResourceResponse($successBody, 200);

        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error($e->getMessage());
            // Do NOT store 500 errors — client should be able to retry.
            return new ResourceResponse(['success' => false, 'message' => 'Internal Transaction Error'], 500);
        }
    }

}