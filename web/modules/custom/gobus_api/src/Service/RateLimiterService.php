<?php

namespace Drupal\gobus_api\Service;

use Drupal\Core\Flood\FloodInterface;
use Drupal\rest\ResourceResponse;

/**
 * Rate limiting service using Drupal's built-in flood control.
 *
 * Usage in REST Resources:
 *   $rateLimiter = \Drupal::service('gobus_api.rate_limiter');
 *   $result = $rateLimiter->check('gobus.login', $ip, 5, 60);
 *   if ($result !== null) return $result; // Returns 429 response
 */
class RateLimiterService {

  /**
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  public function __construct(FloodInterface $flood) {
    $this->flood = $flood;
  }

  /**
   * Check rate limit and register the attempt.
   *
   * @param string $event
   *   Event name (e.g. 'gobus.login', 'gobus.reload').
   * @param string $identifier
   *   Identifier to rate limit (IP address, user ID, phone number, etc.).
   * @param int $maxAttempts
   *   Maximum allowed attempts within the window.
   * @param int $windowSeconds
   *   Time window in seconds.
   *
   * @return \Drupal\rest\ResourceResponse|null
   *   Returns a 429 ResourceResponse if rate limited, or NULL if allowed.
   */
  public function check(string $event, string $identifier, int $maxAttempts, int $windowSeconds): ?ResourceResponse {
    if (!$this->flood->isAllowed($event, $maxAttempts, $windowSeconds, $identifier)) {
      $retryAfter = $windowSeconds;
      $response = new ResourceResponse([
        'success' => false,
        'error' => [
          'code' => 'RATE_LIMITED',
          'message' => 'Too many attempts. Please try again later.',
        ],
      ], 429);
      // Note: ResourceResponse doesn't support custom headers directly.
      // The Retry-After header will be useful for clients.
      return $response;
    }

    // Register this attempt
    $this->flood->register($event, $windowSeconds, $identifier);
    return null;
  }

  /**
   * Get the client IP address from the current request.
   *
   * @return string
   */
  public static function getClientIp(): string {
    return \Drupal::request()->getClientIp() ?? '0.0.0.0';
  }

  /**
   * Get the current authenticated user ID as string.
   *
   * @return string
   */
  public static function getCurrentUserId(): string {
    return (string) \Drupal::currentUser()->id();
  }

}
