<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\gobus_api\Plugin\rest\resource\RegisterResource;
use Drupal\rest\ResourceResponse;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to register new agents.
 *
 * @RestResource(
 *   id = "gobus_auth_agent_register",
 *   label = @Translation("GoBus Auth Agent Register"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/agent/register"
 *   }
 * )
 */
class AgentRegisterResource extends RegisterResource
{
    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 0. Rate Limiting: 3 attempts per minute per IP
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.agent_register', $rateLimiter::getClientIp(), 3, 60);
        if ($limited) return $limited;

        // 1. Validation basics
        $required_fields = ['phone', 'password', 'name', 'shop_name', 'city', 'code'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new BadRequestHttpException("Missing required field: " . $field);
            }
        }

        // 2. Mock SMS Verification
        if ($data['code'] !== '5588') {
            return new ResourceResponse(['success' => false, 'message' => 'Invalid verification code.'], 400);
        }

        // 3. User Existence Check
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['field_phone' => $data['phone']]);
        if (!empty($users)) {
            return new ResourceResponse(['success' => false, 'message' => 'Phone number already registered.'], 400);
        }

        // 4. Create User (AGENT Specifics)
        try {
            $role = 'agent';
            $prefix = 'AGT';

            // Auto-generate ID logic
            $query = \Drupal::entityTypeManager()->getStorage('user')->getQuery()
                ->condition('field_account_id', $prefix . '-', 'STARTS_WITH')
                ->accessCheck(FALSE)
                ->count();
            $count = (int)$query->execute();
            $account_id = $prefix . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

            $user = User::create();
            $user->setPassword($data['password']);
            $user->enforceIsNew();
            $user->setEmail($data['phone'] . '@gobus.tn');
            $user->setUsername($data['phone']);

            // Agent Specific Fields
            $user->set('field_account_id', $account_id);
            $user->set('field_phone', $data['phone']);
            $user->set('field_full_name', $data['name']);
            $user->set('field_shop_name', $data['shop_name']);
            $user->set('field_city', $data['city']);

            $user->addRole($role);
            $user->activate();
            $user->save();

            // 5. Auto-Login (Delegate to parent logic or duplicate if needed)
            // For simplicity and independence, duplicating the OAuth call
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

            // 6. Response
            $response_data = [
                'success' => true,
                'message' => 'Agent registered successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'account_id' => $account_id,
                        'phone' => $data['phone'],
                        'name' => $data['name'],
                        'shop_name' => $data['shop_name'],
                        'city' => $data['city'],
                        'balance' => 0.0,
                        'role' => 'agent', // Explicit
                        'is_verified' => false,
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
            \Drupal::logger('gobus_api')->error($e->getMessage());
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }
}