<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a resource to login (Wrapper for OAuth).
 *
 * @RestResource(
 *   id = "gobus_auth_login",
 *   label = @Translation("GoBus Auth Login"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/login"
 *   }
 * )
 */
class LoginResource extends ResourceBase
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
        // 1. Validation
        if (empty($data['phone']) || empty($data['password'])) {
            throw new BadRequestHttpException("Missing phone or password");
        }

        // 2. Internal Call to OAuth Endpoint
        try {
            // Determine Client ID based on platform or default to Reload App
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
                'verify' => getenv('GOBUS_SSL_VERIFY') !== 'false',
            ]);

            $oauth_data = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                return new ResourceResponse([
                    'success' => false,
                    'message' => $oauth_data['message'] ?? 'Login failed. Check credentials.',
                    'error_code' => $oauth_data['error'] ?? 'invalid_grant'
                ], 401);
            }

            // 3. Load User Profile for "data.user" part of response
            $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $data['phone']]);
            $user = reset($users);

            $user_dto = [];
            if ($user) {
                $ledger_service = \Drupal::service('gobus_api.ledger');
                $account_id = $ledger_service->getOrCreateAccountForUser($user);
                $balance = $account_id ? $ledger_service->calculateBalance($account_id) : 0.0;

                $user_dto = [
                    'id' => $user->id(),
                    'account_id' => $user->get('field_account_id')->getString(),
                    'phone' => $user->get('field_phone')->getString(),
                    'name' => $user->get('field_full_name')->getString(),
                    'shop_name' => $user->get('field_shop_name')->getString(),
                    'city' => $user->get('field_city')->getString(),
                    'balance' => $balance,
                    'role' => $user->getRoles()[1] ?? 'agent',
                    'is_verified' => (bool)$user->get('field_is_verified')->getString(),
                    'created_at' => date('d/m/Y', $user->get('created')->value),
                ];
            }

            // 4. Construct Response matching Mobile App DTO
            return new ResourceResponse([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => $user_dto,
                    'tokens' => [
                        'access_token' => $oauth_data['access_token'],
                        'refresh_token' => $oauth_data['refresh_token'],
                        'token_type' => $oauth_data['token_type'],
                        'expires_in' => $oauth_data['expires_in'],
                    ]
                ]
            ], 200);

        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error($e->getMessage());
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }

}