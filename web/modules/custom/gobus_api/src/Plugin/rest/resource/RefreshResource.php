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

        // 0. Rate Limiting: 10 attempts per minute per IP
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.refresh', $rateLimiter::getClientIp(), 10, 60);
        if ($limited) return $limited;

        $request = \Drupal::request();
        $client = \Drupal::httpClient();

        $client_id = getenv('OAUTH_CLIENT_ID') ?: throw new \RuntimeException('OAUTH_CLIENT_ID env variable not set');
        $client_secret = getenv('OAUTH_CLIENT_SECRET') ?: throw new \RuntimeException('OAUTH_CLIENT_SECRET env variable not set');

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
                'verify' => getenv('GOBUS_SSL_VERIFY') !== 'false',
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
                \Drupal::logger('gobus_api')->warning('Token refresh failed: status=@status, error=@error', [
                    '@status' => $response->getStatusCode(),
                    '@error' => $body['error'] ?? 'unknown',
                ]);
                return new ResourceResponse([
                    'success' => false,
                    'message' => $body['message'] ?? ($body['error_description'] ?? 'Token refresh failed.'),
                    'error' => $body['error'] ?? 'unknown_error',
                ], $response->getStatusCode() ?: 500);
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error('Token refresh exception: @message', ['@message' => $e->getMessage()]);
            return new ResourceResponse([
                'success' => false,
                'message' => 'Unable to refresh token.',
            ], 500);
        }
    }
}