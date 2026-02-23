<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to change user password.
 *
 * @RestResource(
 *   id = "gobus_auth_change_password",
 *   label = @Translation("GoBus Auth Change Password"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/change-password"
 *   }
 * )
 */
class ChangePasswordOldResource extends ResourceBase
{
    protected $currentUser;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        return $instance;
    }

    public function post($data)
    {
        // 0. Rate Limiting: 5 attempts per minute per user (sensitive action)
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.change_password', $rateLimiter::getCurrentUserId(), 5, 60);
        if ($limited) return $limited;

        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (empty($data['current_password']) || empty($data['new_password'])) {
            throw new BadRequestHttpException('Missing required fields');
        }

        $user = User::load($this->currentUser->id());

        // Verify current password
        $auth_service = \Drupal::service('user.auth');
        if (!$auth_service->authenticate($user->getAccountName(), $data['current_password'])) {
            return new ResourceResponse(['success' => false, 'message' => 'Invalid current password'], 400);
        }

        try {
            $user->setPassword($data['new_password']);
            $user->save();
            return new ResourceResponse([
                'success' => true,
                'message' => 'Password changed successfully',
            ], 200);
        }
        catch (\Exception $e) {
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }
}