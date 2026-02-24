<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a resource to update current user profile.
 *
 * @RestResource(
 *   id = "gobus_auth_update_profile",
 *   label = @Translation("GoBus Auth Update Profile"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/auth/profile"
 *   }
 * )
 */
class UpdateProfileResource extends ResourceBase
{
    protected $currentUser;
    protected $requestStack;

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        $instance->requestStack = $container->get('request_stack');
        return $instance;
    }

    public function put()
    {
        // 0. Rate Limiting: 30 attempts per minute per user
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.update_profile', $rateLimiter::getCurrentUserId(), 30, 60);
        if ($limited) return $limited;

        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Manually parse JSON body — Drupal REST only auto-deserializes $data
        // for POST on "create" uri_paths, not for PUT on "canonical" paths.
        $request = $this->requestStack->getCurrentRequest();
        $data = json_decode($request->getContent(), TRUE);

        if (empty($data)) {
            return new ResourceResponse(['success' => false, 'message' => 'Invalid or missing JSON body'], 400);
        }

        $user = User::load($this->currentUser->id());

        if (isset($data['name']))
            $user->set('field_full_name', $data['name']);
        if (isset($data['shop_name']))
            $user->set('field_shop_name', $data['shop_name']);
        if (isset($data['city']))
            $user->set('field_city', $data['city']);
        if (isset($data['phone']))
            $user->set('field_phone', $data['phone']);

        try {
            $user->save();

            $ledger_service = \Drupal::service('gobus_api.ledger');
            $account_id = $ledger_service->getOrCreateAccountForUser($user);
            $balance = $account_id ? $ledger_service->calculateBalance($account_id) : 0.0;

            return new ResourceResponse([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'account_id' => $user->get('field_account_id')->getString(),
                        'phone' => $user->get('field_phone')->getString(),
                        'name' => $user->get('field_full_name')->getString(),
                        'shop_name' => $user->get('field_shop_name')->getString(),
                        'city' => $user->get('field_city')->getString(),
                        'balance' => $balance,
                        'role' => $user->getRoles()[1] ?? 'authenticated',
                        'is_verified' => (bool)$user->get('field_is_verified')->getString(),
                    ]
                ],
            ], 200);
        }
        catch (\Throwable $e) {
            \Drupal::logger('gobus_api')->error('Update Profile Error: @message', ['@message' => $e->getMessage()]);
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }
}