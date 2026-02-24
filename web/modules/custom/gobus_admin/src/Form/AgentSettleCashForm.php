<?php

namespace Drupal\gobus_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\gobus_api\Service\GobuxLedgerService;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a form for settling cash (Agent handing over physical cash to GoBus).
 * This reduces the 'unsettled_cash' (debt) but does NOT affect the virtual balance.
 */
class AgentSettleCashForm extends FormBase
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
     * Constructs an AgentSettleCashForm.
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
        return 'gobus_admin_agent_settle_cash_form';
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

        // Display current debt
        $account_id = $this->ledgerService->getOrCreateAccountForUser($user);
        $unsettled_cash = $this->ledgerService->calculateUnsettledCash($account_id);

        $form['target_agent'] = [
            '#type' => 'item',
            '#title' => $this->t('Agent'),
            '#markup' => $user->getAccountName() . ' (' . $user->id() . ')',
        ];

        $form['unsettled_cash'] = [
            '#type' => 'item',
            '#title' => $this->t('Unsettled Cash (Debt)'),
            '#markup' => '<strong>' . number_format($unsettled_cash, 3, '.', '') . ' DT</strong>',
        ];

        $form['amount'] = [
            '#type' => 'number',
            '#title' => $this->t('Cash Amount Collected (DT)'),
            '#description' => $this->t('Enter the amount of PHYSICAL CASH the commercial collected from this agent. This resets their debt but leaves their virtual balance intact.'),
            '#required' => TRUE,
            '#min' => 0.001,
            '#step' => 0.001,
            '#default_value' => ($unsettled_cash > 0) ? $unsettled_cash : 0,
        ];

        $form['actions'] = [
            '#type' => 'actions',
        ];
        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Confirm Cash Settlement'),
            '#button_type' => 'primary',
            '#attributes' => ['class' => ['button--primary', 'button--settle']], // Needs some custom css maybe later
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
            // 3. Record Transaction (Agent -> System)
            // Settlement means Agent gives the cash back to the System.
            // It acts as a COLLECTION transaction, which decreases unsettled_cash
            // BUT also decreases virtual balance. Wait... 
            // IF we do a standard COLLECTION, it decreases virtual balance.
            // Let's create a specific transaction type 'SETTLEMENT' to differentiate if we just want to clear cash.
            // ACTUALLY: In double entry, if he gives us cash, his liability decreases. 
            // We defined unsettled_cash = RELOADS - COLLECTIONS.
            // So this MUST be a COLLECTION transaction.
            // *BUT* the physical business logic the user wants is: "Settle cash without reducing virtual stock".
            // IF we use 'COLLECTION', the formula `virtual = SYSTEM_LOADS - RELOADS - COLLECTIONS` means his virtual balance drops.

            // AHA! If we want to collect cash WITHOUT dropping virtual balance, we must do two things (Double-entry magic):
            // 1. Record COLLECTION (Agent -> System) to clear the cash debt.
            // 2. Automatically record a SYSTEM_LOAD (System -> Agent) for the exact same amount to replenish his virtual stock!
            // This perfectly matches the "Pre-pay / Post-pay" fluid loop!

            // Transaction 1: Clear the Debt
            $this->ledgerService->recordTransaction(
                $agent_account_node_id,
                $sys_account_node_id,
                $amount,
                'COLLECTION', // This drops Unsettled Cash AND Virtual Balance
                \Drupal::currentUser()->id(),
                0.0,
                null
            );

            // Transaction 2: Replenish the Virtual Stock immediately
            $this->ledgerService->recordTransaction(
                $sys_account_node_id,
                $agent_account_node_id,
                $amount,
                'SYSTEM_LOAD', // This raises Virtual Balance back up
                \Drupal::currentUser()->id(),
                0.0,
                null
            );

            $new_unsettled = $this->ledgerService->calculateUnsettledCash($agent_account_node_id);
            $new_balance = $this->ledgerService->calculateBalance($agent_account_node_id);

            $this->messenger()->addStatus($this->t('Successfully settled @amount DT in cash from @agent. Debt remaining: @debt DT. Virtual Balance: @balance DT.', [
                '@amount' => number_format($amount, 3),
                '@agent' => $target_user->getAccountName(),
                '@debt' => number_format($new_unsettled, 3),
                '@balance' => number_format($new_balance, 3)
            ]));

            $form_state->setRedirect('gobus_admin.agents_dashboard');

        }
        catch (\Exception $e) {
            $this->messenger()->addError($this->t('Transaction failed: @message', ['@message' => $e->getMessage()]));
        }
    }
}