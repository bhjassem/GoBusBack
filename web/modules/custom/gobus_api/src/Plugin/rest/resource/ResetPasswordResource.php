<?php

namespace Drupal\gobus_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a resource to reset user password via SMS code verification.
 *
 * @RestResource(
 *   id = "gobus_auth_reset_password",
 *   label = @Translation("GoBus Auth Reset Password"),
 *   uri_paths = {
 *     "create" = "/api/v1/auth/reset-password"
 *   }
 * )
 */
class ResetPasswordResource extends ResourceBase
{

    /**
     * {@inheritdoc}
     */
    public function post($data)
    {
        // 1. Validation
        $required = ['phone', 'code', 'new_password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new BadRequestHttpException("Missing required field: " . $field);
            }
        }

        // 2. Code Verification (Mock 5588)
        if ($data['code'] !== '5588') {
            return new ResourceResponse(['success' => false, 'message' => 'Invalid verification code.'], 400);
        }

        // 3. Load User
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['field_phone' => $data['phone']]);
        if (empty($users)) {
            return new ResourceResponse(['success' => false, 'message' => 'No account found with this phone number.'], 404);
        }

        /** @var \Drupal\user\UserInterface $user */
        $user = reset($users);

        // 4. Update Password
        try {
            $user->setPassword($data['new_password']);
            $user->save();

            return new ResourceResponse([
                'success' => true,
                'message' => 'Password reset successfully.'
            ], 200);
        }
        catch (\Exception $e) {
            \Drupal::logger('gobus_api')->error('Password reset failed for ' . $data['phone'] . ': ' . $e->getMessage());
            return new ResourceResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }
}