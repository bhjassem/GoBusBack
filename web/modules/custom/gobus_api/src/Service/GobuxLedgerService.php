<?php

namespace Drupal\gobus_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Service to manage the Gobus Ledger Architecture.
 */
class GobuxLedgerService
{

    protected $entityTypeManager;
    protected $database;

    public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database)
    {
        $this->entityTypeManager = $entity_type_manager;
        $this->database = $database;
    }

    /**
     * Calculate the live balance for a given Account Node ID.
     * Balance = Incoming - Outgoing
     * 
     * @param int|string $account_id The Node ID of the gobus_account
     * @return float
     */
    public function calculateBalance($account_id): float
    {
        // Sum incoming transactions
        $incoming_query = $this->database->select('node__field_amount', 'amount')
            ->condition('amount.bundle', 'transaction');
        $incoming_query->join('node__field_to_account', 'to_account', 'amount.entity_id = to_account.entity_id');
        $incoming_query->condition('to_account.field_to_account_target_id', $account_id);
        $incoming_query->addExpression('SUM(amount.field_amount_value)', 'total_in');

        $total_in = $incoming_query->execute()->fetchField();

        // Sum outgoing transactions
        $outgoing_query = $this->database->select('node__field_amount', 'amount')
            ->condition('amount.bundle', 'transaction');
        $outgoing_query->join('node__field_from_account', 'from_account', 'amount.entity_id = from_account.entity_id');
        $outgoing_query->condition('from_account.field_from_account_target_id', $account_id);
        $outgoing_query->addExpression('SUM(amount.field_amount_value)', 'total_out');

        $total_out = $outgoing_query->execute()->fetchField();

        return (float)($total_in ?? 0) - (float)($total_out ?? 0);
    }

    /**
     * Calculate the "unsettled cash" for an Agent.
     * This represents the total amount of cash the agent has collected from clients
     * (via RELOAD operations) MINUS the cash they have already remitted to the system
     * (via COLLECTION operations).
     * 
     * @param int|string $account_id The Node ID of the agent's gobus_account
     * @return float
     */
    public function calculateUnsettledCash($account_id): float
    {
        // 1. Sum up all RELOAD amounts where this account is the Sender (field_from_account)
        // This is the cash they collected from clients.
        $reloads_query = $this->database->select('node__field_amount', 'amount')
            ->condition('amount.bundle', 'transaction');
        $reloads_query->join('node__field_from_account', 'from_account', 'amount.entity_id = from_account.entity_id');
        $reloads_query->join('node__field_transaction_type', 'type', 'amount.entity_id = type.entity_id');

        $reloads_query->condition('from_account.field_from_account_target_id', $account_id);
        $reloads_query->condition('type.field_transaction_type_value', 'RELOAD');
        $reloads_query->addExpression('SUM(amount.field_amount_value)', 'total_collected');

        $total_collected = $reloads_query->execute()->fetchField();

        // 2. Sum up all COLLECTION amounts where this account is the Sender
        // This is the cash they have already given back to the Admin.
        $collections_query = $this->database->select('node__field_amount', 'amount')
            ->condition('amount.bundle', 'transaction');
        $collections_query->join('node__field_from_account', 'from_account', 'amount.entity_id = from_account.entity_id');
        $collections_query->join('node__field_transaction_type', 'type', 'amount.entity_id = type.entity_id');

        $collections_query->condition('from_account.field_from_account_target_id', $account_id);
        $collections_query->condition('type.field_transaction_type_value', 'COLLECTION');
        $collections_query->addExpression('SUM(amount.field_amount_value)', 'total_remitted');

        $total_remitted = $collections_query->execute()->fetchField();

        return (float)($total_collected ?? 0) - (float)($total_remitted ?? 0);
    }

    /**
     * Retrieves the gobus_account node ID for a given user.
     * Creates it on the fly if it doesn't exist.
     * 
     * @param \Drupal\user\Entity\User $user
     * @return int|null Node ID of the account
     */
    public function getOrCreateAccountForUser(User $user)
    {
        if ($user->id() == 0)
            return null;

        $node_storage = $this->entityTypeManager->getStorage('node');
        $accounts = $node_storage->loadByProperties([
            'type' => 'gobus_account',
            'field_account_owner' => $user->id(),
        ]);

        if (!empty($accounts)) {
            return reset($accounts)->id();
        }

        // Role detection
        $roles = $user->getRoles();
        $role_name = 'unknown';
        if (in_array('client', $roles))
            $role_name = 'client';
        elseif (in_array('agent', $roles))
            $role_name = 'agent';
        elseif (in_array('captain', $roles))
            $role_name = 'captain';

        if ($role_name === 'unknown')
            return null;

        $ledger_id = '';
        if ($user->hasField('field_account_id') && !$user->get('field_account_id')->isEmpty()) {
            $ledger_id = 'ACC-' . $user->get('field_account_id')->getString();
        }
        else {
            $ledger_id = 'ACC-' . strtoupper($role_name) . '-' . str_pad($user->id(), 5, '0', STR_PAD_LEFT);
        }

        $account = Node::create([
            'type' => 'gobus_account',
            'title' => ucfirst($role_name) . ' Account - ' . $user->getAccountName(),
            'field_account_owner' => ['target_id' => $user->id()],
            'field_account_type' => $role_name,
            'field_ledger_id' => $ledger_id,
            'uid' => 1,
        ]);
        $account->save();

        return $account->id();
    }

    /**
     * Create a double-entry transaction.
     * 
     * @param int $from_account_id
     * @param int $to_account_id
     * @param float $amount
     * @param string $type e.g. 'RELOAD', 'COLLECTION'
     * @param int $creator_uid Admin or Agent who initiates this
     * @param float $commission
     * @param int|null $target_client_uid Only mapped for backwards compatibility in API response
     * @return \Drupal\node\Entity\Node The created transaction node
     */
    public function recordTransaction($from_account_id, $to_account_id, $amount, $type, $creator_uid, $commission = 0.0, $target_client_uid = NULL)
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Transaction amount must be strictly positive.");
        }

        $data = [
            'type' => 'transaction',
            'title' => ucfirst(strtolower($type)) . ' - ' . date('Y-m-d H:i'),
            'field_amount' => $amount,
            'field_commission' => $commission,
            'field_transaction_type' => $type,
            'field_from_account' => ['target_id' => $from_account_id],
            'field_to_account' => ['target_id' => $to_account_id],
            'uid' => $creator_uid,
        ];

        // Backwards compatibility with the older 'field_client' mapping on transactions
        if ($target_client_uid) {
            $data['field_client'] = ['target_id' => $target_client_uid];
        }

        $transaction = Node::create($data);
        $transaction->save();

        return $transaction;
    }
}