<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a resource for logout with real OAuth2 token revocation.
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
    protected AccountProxyInterface $currentUser;
    protected Request $request;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        /** @var RequestStack $requestStack */
        $requestStack = $container->get('request_stack');
        $instance->request = $requestStack->getCurrentRequest() ?? Request::createFromGlobals();
        return $instance;
    }

    public function post(array $data = [])
    {
        // ── Rate limiting: 10 attempts / minute ───────────────────────────────
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $identifier = $this->currentUser->isAnonymous()
            ? $rateLimiter::getClientIp()
            : $rateLimiter::getCurrentUserId();

        $limited = $rateLimiter->check('gobus.logout', $identifier, 10, 60);
        if ($limited) {
            return $limited;
        }

        // ── Auth guard ────────────────────────────────────────────────────────
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $tokenStorage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
        $revokedCount = 0;

        // ── Logout all devices ────────────────────────────────────────────────
        // If the client sends { "logout_all_devices": true }, revoke every token
        // belonging to this user (access + refresh across all devices).
        if (!empty($data['logout_all_devices'])) {
            $allTokens = $tokenStorage->loadByProperties([
                'auth_user_id' => $this->currentUser->id(),
            ]);
            foreach ($allTokens as $token) {
                $token->delete();
                $revokedCount++;
            }

            return new ResourceResponse([
                'success' => true,
                'message' => 'Logged out from all devices.',
                'revoked_count' => $revokedCount,
            ], 200);
        }

        // ── Revoke the current access token (from Authorization header) ───────
        $authHeader = $this->request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $rawAccessToken = trim($matches[1]);
            $accessTokens = $tokenStorage->loadByProperties(['value' => $rawAccessToken]);
            foreach ($accessTokens as $token) {
                $token->delete();
                $revokedCount++;
            }
        }

        // ── Revoke the refresh token (sent in request body) ───────────────────
        // The mobile client sends { "refresh_token": "...", "device_id": "..." }
        if (!empty($data['refresh_token'])) {
            $refreshTokens = $tokenStorage->loadByProperties(['value' => $data['refresh_token']]);
            foreach ($refreshTokens as $token) {
                $token->delete();
                $revokedCount++;
            }
        }

        return new ResourceResponse([
            'success' => true,
            'message' => 'Logout successful.',
            'revoked_count' => $revokedCount,
        ], 200);
    }
}