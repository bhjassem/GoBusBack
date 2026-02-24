<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource for logout.
 *
 * @RestResource(
 *   id = "gobus_auth_logout",
 *   label = @Translation("GoBus Auth Logout"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/logout"
 *   }
 * )
 */
class LogoutResource extends ResourceBase
{
    protected $currentUser;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        return $instance;
    }

    public function post()
    {
        // Rate Limiting: 10 attempts per minute per user (or IP if anonymous)
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $identifier = $this->currentUser->isAnonymous() ? $rateLimiter::getClientIp() : $rateLimiter::getCurrentUserId();
        $limited = $rateLimiter->check('gobus.logout', $identifier, 10, 60);
        if ($limited)
            return $limited;

        // Defense-in-depth: route already requires _user_is_logged_in
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        // For OAuth2, logout usually means the client discards the token.
        // On the server side, we could revoke the specific token if we had its ID.
        // For now, we'll return a success response to acknowledge the action.

        return new ResourceResponse([
            'success' => true,
            'message' => 'Logout successful',
        ], 200);
    }
}