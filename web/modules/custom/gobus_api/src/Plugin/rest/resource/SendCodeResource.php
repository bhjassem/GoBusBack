<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to send SMS code (Mock).
 *
 * @RestResource(
 *   id = "gobus_auth_send_code",
 *   label = @Translation("GoBus Auth Send Code"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/send-code"
 *   }
 * )
 */
class SendCodeResource extends ResourceBase
{

    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        if (empty($data['phone'])) {
            throw new BadRequestHttpException("Missing phone number");
        }

        // 0. Rate Limiting: 3 attempts per 10 minutes per phone number
        $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
        $limited = $rateLimiter->check('gobus.send_code', $data['phone'], 3, 600);
        if ($limited) return $limited;

        // MOCK SEND LOGIC
        // In production, we would call Twilio/InfoBip here.
        // For now, we just say "Success" and user knows the code is 5588.

        return new ResourceResponse([
            'success' => true,
            'message' => 'Verification code sent. (Dev Hint: Use 5588)',
            'expires_in' => 300
        ], 200);
    }
}