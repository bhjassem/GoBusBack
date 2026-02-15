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
     * The HTTP client.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $httpClient;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->httpClient = $container->get('http_client');
        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 1. Validation des champs obligatoires
        $required_fields = ['phone', 'password', 'name', 'shop_name', 'city', 'code'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new BadRequestHttpException("Missing required field: " . $field);
            }
        }

        // 2. Vérification du Code SMS (Mock 5588)
        if ($data['code'] !== '5588') {
            return new ResourceResponse(['success' => false, 'message' => 'Invalid verification code.'], 400);
        }

        // 3. Vérification si le téléphone existe déjà
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['field_phone' => $data['phone']]);
        if (!empty($users)) {
            return new ResourceResponse(['success' => false, 'message' => 'Phone number already registered.'], 400);
        }

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
            $user->activate();

            $user->save();

            // 5. Internal Call to OAuth Endpoint after Registration (Auto-Login)
            $client_id = 'gobus-reload-app-id';
            $client_secret = 'gobus_reload_secret';

            $request = \Drupal::request();
            $base_url = $request->getSchemeAndHttpHost();

            $response = $this->httpClient->post($base_url . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'password',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'username' => $data['phone'],
                    'password' => $data['password'],
                ],
                'http_errors' => false,
                'verify' => false, // Bypass SSL verification for internal call if needed
            ]);

            $oauth_data = json_decode($response->getBody(), true);

            // 6. Response matching Mobile App DTO
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
                    ],
                    'tokens' => [
                        'access_token' => $oauth_data['access_token'] ?? null,
                        'refresh_token' => $oauth_data['refresh_token'] ?? null,
                        'token_type' => $oauth_data['token_type'] ?? 'Bearer',
                        'expires_in' => $oauth_data['expires_in'] ?? 3600,
                    ]
                ]
            ];

            return new ResourceResponse($response_data, 201);

        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error('Registration error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()], 500);
        }
    }

}