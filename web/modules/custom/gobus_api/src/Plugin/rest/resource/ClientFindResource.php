<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to find clients (end-users with 'client' role).
 *
 * Replaces SearchClientResource to solve caching issues and enforce list format.
 *
 * Routes:
 *   GET /api/v1/clients/find?account_id=CLT-00001
 *   GET /api/v1/clients/find?q=...
 *
 * @RestResource(
 *   id = "gobus_api_client_find",
 *   label = @Translation("GoBus API Client Find"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/clients/find"
 *   }
 * )
 */
class ClientFindResource extends ResourceBase
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
     */
    public function get()
    {
        // 0. Rate Limiting: 30 attempts per minute per user
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.client_find', $rateLimiter::getCurrentUserId(), 30, 60);
        if ($limited) return $limited;

        // 1. Auth check
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $request = \Drupal::request();
        $account_id = $request->query->get('account_id');
        $query = $request->query->get('q');

        // Trim inputs
        if ($account_id)
            $account_id = trim($account_id);
        if ($query)
            $query = trim($query);

        // 2. Validation: Both empty?
        if (empty($account_id) && empty($query)) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Missing parameters: provide either q or account_id',
            ], 400);
        }

        // 3. Logic: Priority to 'account_id' if both exist : searchByAccountId (QR)
        if (!empty($account_id)) {
            return $this->searchByAccountId($account_id);
        }

        // 4. Logic: Search by q (Manual search scenario)
        if (!empty($query)) {
            // Validate length
            if (strlen($query) < 2) {
                return new ResourceResponse([
                    'success' => false,
                    'message' => 'Search term too short (min 2 chars)',
                ], 400);
            }
            return $this->searchManual($query);
        }

        throw new BadRequestHttpException("Invalid request parameters.");
    }

    private function isClient($user): bool
    {
        return $user->hasRole('client');
    }

    /**
     * Search by exact account_id (QR code scan).
     * Returns LIST with single item.
     */
    private function searchByAccountId(string $account_id): ResourceResponse
    {
        $users = \Drupal::entityTypeManager()->getStorage('user')
            ->loadByProperties(['field_account_id' => $account_id]);

        $results = [];

        if (!empty($users)) {
            $client = reset($users);
            // Verify active and role
            if ($client->isActive() && $this->isClient($client)) {
                $results[] = $this->formatUser($client);
            }
        }

        // Return list (empty or 1 item)
        // If empty, frontend handles "Client not found"
        $response = new ResourceResponse([
            'success' => true,
            'data' => $results,
        ], 200);

        $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
        return $response;
    }

    /**
     * Manual search: match query against name, phone or account_id.
     */
    private function searchManual(string $query): ResourceResponse
    {
        $user_storage = \Drupal::entityTypeManager()->getStorage('user');

        $entity_query = $user_storage->getQuery()
            ->condition('status', 1)
            ->condition('roles', 'client') // Filter at DB level
            ->range(0, 10)
            ->accessCheck(FALSE);

        $or_group = $entity_query->orConditionGroup()
            ->condition('field_full_name', $query, 'CONTAINS')
            ->condition('field_phone', $query, 'CONTAINS')
            ->condition('field_account_id', $query, 'CONTAINS');

        $entity_query->condition($or_group);
        $uids = $entity_query->execute();

        $results = [];
        if (!empty($uids)) {
            $users = $user_storage->loadMultiple($uids);
            foreach ($users as $user) {
                if ($this->isClient($user)) {
                    $results[] = $this->formatUser($user);
                }
            }
        }

        $response = new ResourceResponse([
            'success' => true,
            'data' => $results,
        ], 200);

        // ESSENTIAL: Vary by query string!
        $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);

        return $response;
    }

    /**
     * Format user object for response.
     * Hides balance, keeps account_id.
     */
    private function formatUser($user)
    {
        $full_name = $user->get('field_full_name')->getString();
        $name_parts = explode(' ', $full_name, 2);

        return [
            'account_id' => $user->get('field_account_id')->getString(),
            'first_name' => $name_parts[0] ?? '',
            'last_name' => $name_parts[1] ?? '',
        ];
    }
}