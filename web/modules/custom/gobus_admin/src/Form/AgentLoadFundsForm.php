<?php

namespace Drupal\gobus_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\gobus_api\Service\GobuxLedgerService;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a form for loading funds into an Agent's account.
 */
class AgentLoadFundsForm extends FormBase
{

    /**
     * The ledger service.
     *
     * @var \Drupal\gobus_api\Service\GobuxLedgerService
     */
    protected $ledgerService;

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * Constructs an AgentLoadFundsForm.
     *
     * @param \Drupal\gobus_api\Service\GobuxLedgerService $ledger_service
     *   The GoBus Ledger Service.
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     */
    public function __construct(GobuxLedgerService $ledger_service, EntityTypeManagerInterface $entity_type_manager)
    {
        $this->ledgerService = $ledger_service;
        $this->entityTypeManager = $entity_type_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static (
            $container->get('gobus_api.ledger'),
            $container->get('entity_type.manager')
            );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'gobus_admin_agent_load_funds_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state, User $user = NULL)
    {
        if (!$user) {
            return ['#markup' => 'User not found.'];
        }

        $form_state->set('target_user', $user);

        // Display current balance
        $account_id = $this->ledgerService->getOrCreateAccountForUser($user);
        $current_balance = $this->ledgerService->calculateBalance($account_id);

        $form['target_agent'] = [
            '#type' => 'item',
            '#title' => $this->t('Agent'),
            '#markup' => $user->getAccountName() . ' (' . $user->id() . ')',
        ];

        $form['current_balance'] = [
            '#type' => 'item',
            '#title' => $this->t('Current Ledger Balance'),
            '#markup' => '<strong>' . number_format($current_balance, 3, '.', '') . ' DT</strong>',
        ];

        $form['amount'] = [
            '#type' => 'number',
            '#title' => $this->t('Amount to Load (DT)'),
            '#description' => $this->t('Enter the amount of funds to add to this agent\'s account.'),
            '#required' => TRUE,
            '#min' => 0.001,
            '#step' => 0.001,
        ];

        $form['actions'] = [
            '#type' => 'actions',
        ];
        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Load Funds'),
            '#button_type' => 'primary',
        ];
        $form['actions']['cancel'] = [
            '#type' => 'link',
            '#title' => $this->t('Cancel'),
            '#url' => \Drupal\Core\Url::fromRoute('gobus_admin.agents_dashboard'),
            '#attributes' => ['class' => ['button']],
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $target_user = $form_state->get('target_user');
        $amount = (float)$form_state->getValue('amount');

        // 1. Get System Account
        $system_accounts = $this->entityTypeManager->getStorage('node')->loadByProperties([
            'type' => 'gobus_account',
            'field_ledger_id' => 'ACC-SYS-MAIN'
        ]);

        if (empty($system_accounts)) {
            $this->messenger()->addError($this->t('System account ACC-SYS-MAIN not found. Cannot process transaction.'));
            return;
        }
        $sys_account_node_id = reset($system_accounts)->id();

        // 2. Get Agent Account
        $agent_account_node_id = $this->ledgerService->getOrCreateAccountForUser($target_user);

        if (!$agent_account_node_id) {
            $this->messenger()->addError($this->t('Failed to load Agent ledger account.'));
            return;
        }

        try {
            // 3. Record Transaction (System -> Agent)
            $this->ledgerService->recordTransaction(
                $sys_account_node_id,
                $agent_account_node_id,
                $amount,
                'SYSTEM_LOAD',
                \Drupal::currentUser()->id(),
                0.0,
                null
            );

            $new_balance = $this->ledgerService->calculateBalance($agent_account_node_id);

            $this->messenger()->addStatus($this->t('Successfully loaded @amount DT to @agent. New balance: @balance DT.', [
                '@amount' => number_format($amount, 3),
                '@agent' => $target_user->getAccountName(),
                '@balance' => number_format($new_balance, 3)
            ]));

            $form_state->setRedirect('gobus_admin.agents_dashboard');

        }
        catch (\Exception $e) {
            $this->messenger()->addError($this->t('Transaction failed: @message', ['@message' => $e->getMessage()]));
        }
    }
}