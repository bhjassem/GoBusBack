<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

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
    public function post()
    {
        // For OAuth2, logout usually means the client discards the token.
        // On the server side, we could revoke the specific token if we had its ID.
        // For now, we'll return a success response to acknowledge the action.

        return new ResourceResponse([
            'success' => true,
            'message' => 'Logout successful',
        ], 200);
    }
}