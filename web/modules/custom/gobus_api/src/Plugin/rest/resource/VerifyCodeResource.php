<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to verify SMS code (Mock 5588).
 *
 * @RestResource(
 *   id = "gobus_auth_verify_code",
 *   label = @Translation("GoBus Auth Verify Code"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/verify-code"
 *   }
 * )
 */
class VerifyCodeResource extends ResourceBase
{

    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 1. Validation
        if (empty($data['phone']) || empty($data['code'])) {
            throw new BadRequestHttpException("Missing phone or code");
        }

        // 2. MOCK VERIFICATION LOGIC (Magic Code 5588)
        if ($data['code'] === '5588') {
            return new ResourceResponse([
                'success' => true,
                'message' => 'Code verified successfully.',
                'verification_token' => 'mock-token-' . time() // Token to use for registration
            ], 200);
        }

        return new ResourceResponse([
            'success' => false,
            'message' => 'Invalid verification code.'
        ], 400); // 400 Bad Request
    }
}