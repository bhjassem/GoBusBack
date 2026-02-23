<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\gobus_api\Plugin\rest\resource\RegisterResource;
use Drupal\rest\ResourceResponse;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to register new captains.
 *
 * @RestResource(
 *   id = "gobus_auth_captain_register",
 *   label = @Translation("GoBus Auth Captain Register"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/captain/register"
 *   }
 * )
 */
class CaptainRegisterResource extends RegisterResource
{
    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 1. Validation basics
        // Note: captains might not have 'shop_name', but for now adhering to common validation
        // or relaxing it if needed. Assuming captains just need basic info for now.
        $required_fields = ['phone', 'password', 'name', 'city', 'code'];
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

        // 4. Create User (CAPTAIN Specifics)
        try {
            $role = 'captain';
            $prefix = 'CPT';

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

            // Captain Specific Fields
            $user->set('field_account_id', $account_id);
            $user->set('field_phone', $data['phone']);
            $user->set('field_full_name', $data['name']);
            // Captains don't have shop_name, leaving it empty or null
            $user->set('field_city', $data['city']);

            $user->addRole($role);
            $user->activate();
            $user->save();

            // 5. Auto-Login
            // Captain app might have different ID/Secret in future, but using default for now or generic
            $client_id = 'gobus-reload-app-id'; // ToDo: Change to captain app id when available
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

            // 6. Response
            $response_data = [
                'success' => true,
                'message' => 'Captain registered successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'account_id' => $account_id,
                        'phone' => $data['phone'],
                        'name' => $data['name'],
                        'city' => $data['city'],
                        'role' => 'captain', // Explicit
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