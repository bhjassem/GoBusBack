<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->currentUser = $container->get('current_user');
        return $instance;
    }

    public function put($data)
    {
        if ($this->currentUser->isAnonymous()) {
            return new ResourceResponse(['success' => false, 'message' => 'Unauthorized'], 401);
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
                        'balance' => (float)$user->get('field_balance')->getString(),
                        'role' => $user->getRoles()[1] ?? 'authenticated',
                        'is_verified' => (bool)$user->get('field_is_verified')->getString(),
                    ]
                ],
            ], 200);
        }
        catch (\Exception $e) {
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }
}