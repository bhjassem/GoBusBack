<?php

namespace Drupal\gobus_api\Service;

use Drupal\Core\Database\Connection;

/**
 * Service to manage idempotency keys for preventing duplicate transactions.
 *
 * Uses a database table with a UNIQUE constraint on (idempotency_key, user_id)
 * to guarantee that the same request is never processed twice, even under
 * race conditions (the DB INSERT will fail for the second concurrent request).
 *
 * Flow:
 *   1. Client sends Idempotency-Key header (UUID v4)
 *   2. We check if this key+user already exists in the table
 *   3a. If found → return the stored response (same code, same body)
 *   3b. If not found → execute the business logic, store the result, return it
 *
 * Keys are automatically cleaned up after 24 hours via hook_cron().
 */
class IdempotencyService {

  /**
   * TTL for idempotency keys: 24 hours in seconds.
   */
  const TTL_SECONDS = 86400;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Check if an idempotency key has already been processed.
   *
   * @param string $key
   *   The idempotency key (UUID v4).
   * @param int $userId
   *   The user ID making the request.
   *
   * @return array|null
   *   The stored response ['code' => int, 'body' => array] or NULL if not found.
   */
  public function getExistingResponse(string $key, int $userId): ?array {
    $record = $this->database->select('gobus_idempotency', 'gi')
      ->fields('gi', ['response_code', 'response_body'])
      ->condition('idempotency_key', $key)
      ->condition('user_id', $userId)
      ->execute()
      ->fetchObject();

    if (!$record) {
      return NULL;
    }

    return [
      'code' => (int) $record->response_code,
      'body' => json_decode($record->response_body, TRUE),
    ];
  }

  /**
   * Store a response for an idempotency key.
   *
   * Uses INSERT with a unique constraint to prevent race conditions:
   * if two concurrent requests arrive with the same key, only one will
   * succeed at INSERT; the other will get a database exception.
   *
   * @param string $key
   *   The idempotency key (UUID v4).
   * @param int $userId
   *   The user ID.
   * @param string $endpoint
   *   The API endpoint path.
   * @param int $responseCode
   *   The HTTP response status code.
   * @param array $responseBody
   *   The response body as an array.
   *
   * @return bool
   *   TRUE if stored successfully, FALSE if key already exists (race condition).
   */
  public function storeResponse(string $key, int $userId, string $endpoint, int $responseCode, array $responseBody): bool {
    try {
      $this->database->insert('gobus_idempotency')
        ->fields([
          'idempotency_key' => $key,
          'user_id' => $userId,
          'endpoint' => $endpoint,
          'response_code' => $responseCode,
          'response_body' => json_encode($responseBody),
          'created_at' => time(),
        ])
        ->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      // Unique constraint violation = another request already stored this key.
      // This is expected behavior for race condition prevention.
      \Drupal::logger('gobus_api')->notice(
        'Idempotency key collision detected for key @key, user @uid. This is expected for duplicate requests.',
        ['@key' => $key, '@uid' => $userId]
      );
      return FALSE;
    }
  }

  /**
   * Validate that an idempotency key is a valid UUID v4 format.
   *
   * @param string $key
   *   The key to validate.
   *
   * @return bool
   *   TRUE if valid UUID v4 format.
   */
  public function isValidKey(string $key): bool {
    return (bool) preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
      $key
    );
  }

  /**
   * Delete idempotency keys older than the TTL.
   *
   * Called by hook_cron() to keep the table clean.
   *
   * @return int
   *   Number of deleted records.
   */
  public function cleanupExpiredKeys(): int {
    $cutoff = time() - self::TTL_SECONDS;

    return $this->database->delete('gobus_idempotency')
      ->condition('created_at', $cutoff, '<')
      ->execute();
  }

}
