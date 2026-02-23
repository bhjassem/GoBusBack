<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\gobus_api\Plugin\rest\resource\LoginResource;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to login specifically for Captains (GoBus App).
 * Enforces 'captain' role check.
 *
 * @RestResource(
 *   id = "gobus_auth_captain_login",
 *   label = @Translation("GoBus Auth Captain Login"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/captain/login"
 *   }
 * )
 */
class CaptainLoginResource extends LoginResource
{
    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 1. Validation
        if (empty($data['phone']) || empty($data['password'])) {
            throw new BadRequestHttpException("Missing phone or password");
        }

        try {
            // OAuth Client credentials from environment variables
            $client_id = getenv('OAUTH_CLIENT_ID') ?: throw new \RuntimeException('OAUTH_CLIENT_ID env variable not set');
            $client_secret = getenv('OAUTH_CLIENT_SECRET') ?: throw new \RuntimeException('OAUTH_CLIENT_SECRET env variable not set');

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

            // 3. Load User Profile
            $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $data['phone']]);
            $user = reset($users);

            if (!$user) {
                return new ResourceResponse(['success' => false, 'message' => 'User not found after OAuth success.'], 500);
            }

            // --- Security Check for Captain Role ---
            if (!$user->hasRole('captain')) {
                return new ResourceResponse([
                    'success' => false,
                    'message' => 'Unauthorized. Only captains can login to this app.',
                    'error_code' => 'forbidden_role'
                ], 403);
            }

            $user_dto = [
                'id' => $user->id(),
                'account_id' => $user->get('field_account_id')->getString(),
                'phone' => $user->get('field_phone')->getString(),
                'name' => $user->get('field_full_name')->getString(),
                'city' => $user->get('field_city')->getString(),
                'role' => 'captain',
                'is_verified' => (bool)$user->get('field_is_verified')->getString(),
                'created_at' => date('d/m/Y', $user->get('created')->value),
            ];

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