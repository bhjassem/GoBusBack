<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides a resource to get the current authenticated user details.
 *
 * @RestResource(
 *   id = "gobus_auth_me",
 *   label = @Translation("GoBus Auth Me"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/auth/me"
 *   }
 * )
 */
class MeResource extends ResourceBase
{
    protected $currentUser;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        return $instance;
    }

    public function get()
    {
        // 0. Rate Limiting: 30 attempts per minute per user (or IP if anonymous)
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $identifier = $this->currentUser->isAnonymous() ? $rateLimiter::getClientIp() : $rateLimiter::getCurrentUserId();
        $limited = $rateLimiter->check('gobus.me', $identifier, 30, 60);
        if ($limited)
            return $limited;

        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $user = \Drupal\user\Entity\User::load($this->currentUser->id());

        $ledger_service = \Drupal::service('gobus_api.ledger');
        $account_id = $ledger_service->getOrCreateAccountForUser($user);
        $balance = $account_id ? $ledger_service->calculateBalance($account_id) : 0.0;

        // Add unsettled_cash primarily for agents, but safe to calc for any account.
        $unsettled_cash = $account_id ? $ledger_service->calculateUnsettledCash($account_id) : 0.0;

        $response = new ResourceResponse([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id(),
                    'account_id' => $user->get('field_account_id')->getString(),
                    'phone' => $user->get('field_phone')->getString(),
                    'name' => $user->get('field_full_name')->getString(),
                    'shop_name' => $user->get('field_shop_name')->getString(),
                    'city' => $user->get('field_city')->getString(),
                    'balance' => $balance,
                    'unsettled_cash' => $unsettled_cash,
                    'role' => $user->getRoles()[1] ?? 'authenticated',
                    'is_verified' => (bool)$user->get('field_is_verified')->getString(),
                ]
            ]
        ], 200);

        $response->addCacheableDependency($user);

        return $response;
    }
}