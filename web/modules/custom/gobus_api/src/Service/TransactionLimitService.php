<?php

namespace Drupal\gobus_api\Service;

use Drupal\Core\Database\Connection;

/**
 * Service to enforce transaction limits and prevent financial risk.
 *
 * Limits are loaded from environment variables with sensible defaults.
 * All monetary values are in DT (Dinar Tunisien).
 *
 * Checks performed (in order, cheapest first):
 *   1. Per-reload min/max (no DB query)
 *   2. Agent daily reload count
 *   3. Agent daily total amount
 *   4. Agent monthly total amount
 *   5. Client daily received total
 */
class TransactionLimitService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Cached limits (loaded once per request).
   *
   * @var array
   */
  protected $limits;

  public function __construct(Connection $database) {
    $this->database = $database;
    $this->limits = $this->loadLimits();
  }

  /**
   * Load limits from environment variables with defaults.
   */
  protected function loadLimits(): array {
    return [
      'min_amount' => (float) (getenv('GOBUS_RELOAD_MIN_AMOUNT') ?: 5),
      'max_amount' => (float) (getenv('GOBUS_RELOAD_MAX_AMOUNT') ?: 300),
      'agent_daily_limit' => (float) (getenv('GOBUS_AGENT_DAILY_LIMIT') ?: 5000),
      'agent_monthly_limit' => (float) (getenv('GOBUS_AGENT_MONTHLY_LIMIT') ?: 150000),
      'client_daily_limit' => (float) (getenv('GOBUS_CLIENT_DAILY_LIMIT') ?: 1000),
      'agent_daily_count_limit' => (int) (getenv('GOBUS_AGENT_DAILY_COUNT_LIMIT') ?: 200),
    ];
  }

  /**
   * Check all transaction limits before allowing a reload.
   *
   * @param int $agentAccountId
   *   The agent's gobus_account node ID.
   * @param int $clientAccountId
   *   The client's gobus_account node ID.
   * @param float $amount
   *   The reload amount in DT.
   * @param int $agentUserId
   *   The agent's Drupal user ID (uid).
   *
   * @return array
   *   ['allowed' => true] or ['allowed' => false, 'reason' => string, 'message' => string].
   */
  public function checkLimits(int $agentAccountId, int $clientAccountId, float $amount, int $agentUserId): array {
    $limits = $this->limits;

    // 1. Per-reload minimum
    if ($amount < $limits['min_amount']) {
      return [
        'allowed' => FALSE,
        'reason' => 'AMOUNT_TOO_LOW',
        'message' => sprintf('Le montant minimum par rechargement est de %.0f DT.', $limits['min_amount']),
      ];
    }

    // 2. Per-reload maximum
    if ($amount > $limits['max_amount']) {
      return [
        'allowed' => FALSE,
        'reason' => 'AMOUNT_TOO_HIGH',
        'message' => sprintf('Le montant maximum par rechargement est de %.0f DT.', $limits['max_amount']),
      ];
    }

    // 3. Agent daily reload count
    $dailyCount = $this->getAgentDailyReloadCount($agentUserId);
    if ($dailyCount >= $limits['agent_daily_count_limit']) {
      return [
        'allowed' => FALSE,
        'reason' => 'AGENT_DAILY_COUNT_EXCEEDED',
        'message' => sprintf('Nombre maximum de rechargements par jour atteint (%d).', $limits['agent_daily_count_limit']),
      ];
    }

    // 4. Agent daily total amount
    $agentDailyTotal = $this->getAgentDailyTotal($agentAccountId);
    if (($agentDailyTotal + $amount) > $limits['agent_daily_limit']) {
      $remaining = max(0, $limits['agent_daily_limit'] - $agentDailyTotal);
      return [
        'allowed' => FALSE,
        'reason' => 'AGENT_DAILY_LIMIT_EXCEEDED',
        'message' => sprintf('Plafond journalier agent atteint (%.0f DT). Restant : %.2f DT.', $limits['agent_daily_limit'], $remaining),
      ];
    }

    // 5. Agent monthly total amount
    $agentMonthlyTotal = $this->getAgentMonthlyTotal($agentAccountId);
    if (($agentMonthlyTotal + $amount) > $limits['agent_monthly_limit']) {
      $remaining = max(0, $limits['agent_monthly_limit'] - $agentMonthlyTotal);
      return [
        'allowed' => FALSE,
        'reason' => 'AGENT_MONTHLY_LIMIT_EXCEEDED',
        'message' => sprintf('Plafond mensuel agent atteint (%.0f DT). Restant : %.2f DT.', $limits['agent_monthly_limit'], $remaining),
      ];
    }

    // 6. Client daily received total
    $clientDailyTotal = $this->getClientDailyTotal($clientAccountId);
    if (($clientDailyTotal + $amount) > $limits['client_daily_limit']) {
      $remaining = max(0, $limits['client_daily_limit'] - $clientDailyTotal);
      return [
        'allowed' => FALSE,
        'reason' => 'CLIENT_DAILY_LIMIT_EXCEEDED',
        'message' => sprintf('Plafond journalier du client atteint (%.0f DT). Restant : %.2f DT.', $limits['client_daily_limit'], $remaining),
      ];
    }

    return ['allowed' => TRUE];
  }

  /**
   * Get total RELOAD amount sent by an agent today.
   *
   * @param int $agentAccountId
   *   The agent's gobus_account node ID.
   *
   * @return float
   */
  protected function getAgentDailyTotal(int $agentAccountId): float {
    $startOfDay = strtotime('today');

    $query = $this->database->select('node__field_amount', 'amount')
      ->condition('amount.bundle', 'transaction');
    $query->join('node__field_from_account', 'from_acct', 'amount.entity_id = from_acct.entity_id');
    $query->join('node__field_transaction_type', 'type', 'amount.entity_id = type.entity_id');
    $query->join('node_field_data', 'node', 'amount.entity_id = node.nid');

    $query->condition('from_acct.field_from_account_target_id', $agentAccountId);
    $query->condition('type.field_transaction_type_value', 'RELOAD');
    $query->condition('node.created', $startOfDay, '>=');
    $query->addExpression('SUM(amount.field_amount_value)', 'total');

    $result = $query->execute()->fetchField();
    return (float) ($result ?? 0);
  }

  /**
   * Get total RELOAD amount sent by an agent this month.
   *
   * @param int $agentAccountId
   *   The agent's gobus_account node ID.
   *
   * @return float
   */
  protected function getAgentMonthlyTotal(int $agentAccountId): float {
    $startOfMonth = strtotime('first day of this month midnight');

    $query = $this->database->select('node__field_amount', 'amount')
      ->condition('amount.bundle', 'transaction');
    $query->join('node__field_from_account', 'from_acct', 'amount.entity_id = from_acct.entity_id');
    $query->join('node__field_transaction_type', 'type', 'amount.entity_id = type.entity_id');
    $query->join('node_field_data', 'node', 'amount.entity_id = node.nid');

    $query->condition('from_acct.field_from_account_target_id', $agentAccountId);
    $query->condition('type.field_transaction_type_value', 'RELOAD');
    $query->condition('node.created', $startOfMonth, '>=');
    $query->addExpression('SUM(amount.field_amount_value)', 'total');

    $result = $query->execute()->fetchField();
    return (float) ($result ?? 0);
  }

  /**
   * Get number of RELOAD transactions created by an agent today.
   *
   * Uses the node's uid field (creator) rather than the account reference,
   * since uid directly identifies the agent user.
   *
   * @param int $agentUserId
   *   The agent's Drupal user ID.
   *
   * @return int
   */
  protected function getAgentDailyReloadCount(int $agentUserId): int {
    $startOfDay = strtotime('today');

    $query = $this->database->select('node_field_data', 'node')
      ->condition('node.type', 'transaction')
      ->condition('node.uid', $agentUserId)
      ->condition('node.created', $startOfDay, '>=');
    $query->join('node__field_transaction_type', 'type', 'node.nid = type.entity_id');
    $query->condition('type.field_transaction_type_value', 'RELOAD');
    $query->addExpression('COUNT(node.nid)', 'total_count');

    $result = $query->execute()->fetchField();
    return (int) ($result ?? 0);
  }

  /**
   * Get total RELOAD amount received by a client today.
   *
   * @param int $clientAccountId
   *   The client's gobus_account node ID.
   *
   * @return float
   */
  protected function getClientDailyTotal(int $clientAccountId): float {
    $startOfDay = strtotime('today');

    $query = $this->database->select('node__field_amount', 'amount')
      ->condition('amount.bundle', 'transaction');
    $query->join('node__field_to_account', 'to_acct', 'amount.entity_id = to_acct.entity_id');
    $query->join('node__field_transaction_type', 'type', 'amount.entity_id = type.entity_id');
    $query->join('node_field_data', 'node', 'amount.entity_id = node.nid');

    $query->condition('to_acct.field_to_account_target_id', $clientAccountId);
    $query->condition('type.field_transaction_type_value', 'RELOAD');
    $query->condition('node.created', $startOfDay, '>=');
    $query->addExpression('SUM(amount.field_amount_value)', 'total');

    $result = $query->execute()->fetchField();
    return (float) ($result ?? 0);
  }

}
