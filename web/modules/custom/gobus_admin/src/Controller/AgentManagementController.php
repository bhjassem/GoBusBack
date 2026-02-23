<?php

namespace Drupal\gobus_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\gobus_api\Service\GobuxLedgerService;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Controller for GoBus Agent Management.
 */
class AgentManagementController extends ControllerBase
{

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The GoBus Ledger Service.
     *
     * @var \Drupal\gobus_api\Service\GobuxLedgerService
     */
    protected $ledgerService;

    /**
     * Constructs an AgentManagementController.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\gobus_api\Service\GobuxLedgerService $ledger_service
     *   The GoBus Ledger Service.
     */
    public function __construct(EntityTypeManagerInterface $entity_type_manager, GobuxLedgerService $ledger_service)
    {
        $this->entityTypeManager = $entity_type_manager;
        $this->ledgerService = $ledger_service;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static (
            $container->get('entity_type.manager'),
            $container->get('gobus_api.ledger')
            );
    }

    /**
     * Builds the agents dashboard table.
     */
    public function content()
    {
        $build = [];

        // Table header
        $header = [
            'id' => $this->t('User ID'),
            'name' => $this->t('User Name / Phone'),
            'full_name' => $this->t('Full Name'),
            'status' => $this->t('Status'),
            'balance' => $this->t('Ledger Balance (DT)'),
            'operations' => $this->t('Operations'),
        ];

        $rows = [];

        // Query for all users with the 'agent' role
        $query = $this->entityTypeManager->getStorage('user')->getQuery()
            ->condition('roles', 'agent')
            ->accessCheck(FALSE)
            ->sort('created', 'DESC');

        $uids = $query->execute();

        if (!empty($uids)) {
            $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);

            foreach ($users as $user) {
                // Calculate ledger balance
                $balance = 0.0;
                $account_node_id = $this->ledgerService->getOrCreateAccountForUser($user);
                if ($account_node_id) {
                    $balance = $this->ledgerService->calculateBalance($account_node_id);
                }

                // Output Status
                $status = $user->isActive() ? $this->t('Active') : $this->t('Blocked');
                $status_class = $user->isActive() ? 'status-active' : 'status-blocked';

                // Operations
                $operations = [
                    '#type' => 'dropbutton',
                    '#links' => [
                        'load' => [
                            'title' => $this->t('💰 Load Funds'),
                            'url' => Url::fromRoute('gobus_admin.agent_load', ['user' => $user->id()]),
                        ],
                        'collect' => [
                            'title' => $this->t('💸 Collect Cash'),
                            'url' => Url::fromRoute('gobus_admin.agent_collect', ['user' => $user->id()]),
                        ],
                        'toggle' => [
                            'title' => $user->isActive() ? $this->t('🚫 Block') : $this->t('✅ Unblock'),
                            'url' => Url::fromRoute('gobus_admin.agent_toggle_status', ['user' => $user->id()]),
                        ],
                        'edit' => [
                            'title' => $this->t('Edit Profile'),
                            'url' => Url::fromRoute('entity.user.edit_form', ['user' => $user->id()]),
                        ]
                    ],
                ];

                // Format Full Name
                $full_name = '';
                if ($user->hasField('field_full_name') && !$user->get('field_full_name')->isEmpty()) {
                    $full_name = $user->get('field_full_name')->value;
                }

                $rows[] = [
                    'id' => $user->id(),
                    'name' => $user->getAccountName(),
                    'full_name' => $full_name,
                    'status' => [
                        'data' => [
                            '#markup' => '<span class="' . $status_class . '">' . $status . '</span>'
                        ]
                    ],
                    'balance' => [
                        'data' => [
                            '#markup' => '<strong>' . number_format($balance, 3, '.', '') . '</strong>'
                        ]
                    ],
                    'operations' => [
                        'data' => $operations
                    ],
                ];
            }
        }

        $build['table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $rows,
            '#empty' => $this->t('No agents found.'),
            '#attached' => [
                'library' => [
                    'gobus_admin/dashboard_styles',
                ]
            ]
        ];

        return $build;
    }

    /**
     * Toggles the active/blocked status of an agent.
     */
    public function toggleStatus(\Drupal\user\Entity\User $user)
    {
        if ($user->isActive()) {
            $user->block();
            $this->messenger()->addStatus($this->t('Agent @name has been blocked.', ['@name' => $user->getAccountName()]));
        }
        else {
            $user->activate();
            $this->messenger()->addStatus($this->t('Agent @name has been activated.', ['@name' => $user->getAccountName()]));
        }

        $user->save();
        return $this->redirect('gobus_admin.agents_dashboard');
    }

    /**
     * Shows the transaction history for an agent.
     */
    public function transactionHistory(\Drupal\user\Entity\User $user)
    {
        $build = [];

        $account_node_id = $this->ledgerService->getOrCreateAccountForUser($user);

        $build['title'] = [
            '#markup' => '<h2>' . $this->t('Transaction History for @name', ['@name' => $user->getAccountName()]) . '</h2>',
        ];

        $build['back'] = [
            '#type' => 'link',
            '#title' => $this->t('Back to Dashboard'),
            '#url' => Url::fromRoute('gobus_admin.agents_dashboard'),
            '#attributes' => ['class' => ['button']],
        ];

        if (!$account_node_id) {
            $build['error'] = ['#markup' => '<p>' . $this->t('No ledger account found.') . '</p>'];
            return $build;
        }

        // Query transactions involving this account
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'transaction')
            ->condition('status', 1)
            ->accessCheck(FALSE)
            ->sort('created', 'DESC')
            ->range(0, 100);

        $orGroup = $query->orConditionGroup()
            ->condition('field_from_account', $account_node_id)
            ->condition('field_to_account', $account_node_id);

        $query->condition($orGroup);
        $tids = $query->execute();

        $header = [
            'date' => $this->t('Date'),
            'type' => $this->t('Type'),
            'direction' => $this->t('In/Out'),
            'amount' => $this->t('Amount (DT)'),
            'counterparty' => $this->t('Counterparty Account'),
            'performed_by' => $this->t('Performed By'),
        ];

        $rows = [];

        if (!empty($tids)) {
            $transactions = $this->entityTypeManager->getStorage('node')->loadMultiple($tids);

            foreach ($transactions as $txn) {
                $from_id = $txn->get('field_from_account')->target_id;
                $to_id = $txn->get('field_to_account')->target_id;

                $direction = ($to_id == $account_node_id) ? 'IN (+)' : 'OUT (-)';
                $dir_class = ($to_id == $account_node_id) ? 'status-active' : 'status-blocked';

                $counterparty_id = ($to_id == $account_node_id) ? $from_id : $to_id;
                $counterparty_name = 'Unknown';
                if ($counterparty_id) {
                    $cp = $this->entityTypeManager->getStorage('node')->load($counterparty_id);
                    if ($cp) {
                        $counterparty_name = $cp->getTitle();
                    }
                }

                $performed_by = 'System';
                if (!$txn->get('field_performed_by')->isEmpty()) {
                    $perf = $txn->get('field_performed_by')->entity;
                    if ($perf) {
                        $performed_by = $perf->getAccountName();
                    }
                }

                $amount = $txn->get('field_amount')->value;
                $txn_type = $txn->get('field_type')->value;

                $rows[] = [
                    'date' => date('Y-m-d H:i:s', $txn->getCreatedTime()),
                    'type' => $txn_type,
                    'direction' => [
                        'data' => [
                            '#markup' => '<span class="' . $dir_class . '">' . $direction . '</span>'
                        ]
                    ],
                    'amount' => number_format($amount, 3),
                    'counterparty' => $counterparty_name,
                    'performed_by' => $performed_by,
                ];
            }
        }

        $build['table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $rows,
            '#empty' => $this->t('No transactions found.'),
            '#attached' => [
                'library' => [
                    'gobus_admin/dashboard_styles',
                ]
            ]
        ];

        return $build;
    }

}