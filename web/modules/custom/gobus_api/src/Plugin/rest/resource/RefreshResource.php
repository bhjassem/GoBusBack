<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to refresh OAuth2 tokens.
 *
 * @RestResource(
 *   id = "gobus_auth_refresh",
 *   label = @Translation("GoBus Auth Refresh"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/refresh"
 *   }
 * )
 */
class RefreshResource extends ResourceBase
{
    /**
     * Responds to POST requests.
     */
    public function post($data)
    {
        if (empty($data['refresh_token'])) {
            throw new BadRequestHttpException('Missing refresh_token');
        }

        $request = \Drupal::request();
        $client = \Drupal::httpClient();

        $client_id = 'gobus-reload-app-id';
        $client_secret = 'gobus_reload_secret';

        // Match working pattern from RegisterResource
        $base_url = $request->getSchemeAndHttpHost();
        $token_url = $base_url . '/oauth/token';

        try {
            $response = $client->post($token_url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $data['refresh_token'],
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                ],
                'http_errors' => false,
                'verify' => false,
            ]);

            $json_body = (string)$response->getBody();
            $body = json_decode($json_body, true);

            if ($response->getStatusCode() == 200) {
                return new ResourceResponse([
                    'success' => true,
                    'data' => [
                        'access_token' => $body['access_token'],
                        'refresh_token' => $body['refresh_token'],
                        'expires_in' => $body['expires_in'],
                        'token_type' => $body['token_type'] ?? 'Bearer',
                    ]
                ], 200);
            }
            else {
                return new ResourceResponse([
                    'success' => false,
                    'message' => $body['message'] ?? ($body['error_description'] ?? 'Refresh failed externally'),
                    'error' => $body['error'] ?? 'unknown_error',
                    'debug' => [
                        'status' => $response->getStatusCode(),
                        'url' => $token_url,
                        'content_type' => $response->getHeaderLine('Content-Type'),
                    ],
                    'raw_body_preview' => substr($json_body, 0, 1000)
                ], $response->getStatusCode() ?: 500);
            }
        }
        catch (\Exception $e) {
            return new ResourceResponse([
                'success' => false,
                'message' => 'Internal logic error: ' . $e->getMessage(),
                'debug' => ['url' => $token_url]
            ], 500);
        }
    }
}