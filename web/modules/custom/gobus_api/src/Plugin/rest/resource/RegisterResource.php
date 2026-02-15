<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to register new agents.
 *
 * @RestResource(
 *   id = "gobus_auth_register",
 *   label = @Translation("GoBus Auth Register"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/register"
 *   }
 * )
 */
class RegisterResource extends ResourceBase
{

    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 1. Validation des champs obligatoires
        $required_fields = ['phone', 'password', 'name', 'shop_name', 'city', 'access_code'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new BadRequestHttpException("Missing required field: " . $field);
            }
        }

        // 2. Vérification si le téléphone existe déjà
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['field_phone' => $data['phone']]);
        if (!empty($users)) {
            return new ResourceResponse(['success' => false, 'message' => 'Phone number already registered.'], 400);
        }

        // 3. TODO: Valider le Code d'Accès (Logique GoBus)
        // Pour l'instant on accepte tout, mais c'est ici qu'on vérifierait.

        // 4. Création de l'utilisateur
        try {
            $user = User::create();
            $user->setPassword($data['password']);
            $user->enforceIsNew();
            $user->setEmail($data['phone'] . '@gobus.tn'); // Fake email based on phone
            $user->setUsername($data['phone']); // Username = Phone

            // Custom Fields
            $user->set('field_phone', $data['phone']);
            $user->set('field_full_name', $data['name']); // Changed from 'name' which is username
            $user->set('field_shop_name', $data['shop_name']);
            $user->set('field_city', $data['city']);
            $user->set('field_access_code', $data['access_code']);

            // Role & Status
            $user->addRole('agent');
            $user->activate(); // Active by default or wait for verification?

            $user->save();

            // Response matching Mobile App DTO
            $response_data = [
                'success' => true,
                'message' => 'Agent registered successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'phone' => $data['phone'],
                        'name' => $data['name'],
                        'shop_name' => $data['shop_name'],
                        'city' => $data['city'],
                        'role' => 'agent'
                    ]
                ]
            ];

            return new ResourceResponse($response_data, 201);

        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error($e->getMessage());
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }

}