<?php
/**
 * Square Payment Gateway Adapter for FOSSBilling
 *
 * Architecture:
 *   - API-style (not redirect-based)
 *   - Card tokenization in browser via Square Web Payments SDK
 *   - Token sent to backend; backend processes via Square API
 *   - No banklink POST flow
 *   - No nested forms
 *   - No core template modification required
 */
class Payment_Adapter_Square implements \FOSSBilling\InjectionAwareInterface
{
    private array $config = [];
    private ?\Pimple\Container $di = null;

    // -------------------------------------------------------------------------
    // Dependency Injection
    // -------------------------------------------------------------------------

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(array $config)
    {
        $required = ['access_token', 'application_id', 'location_id'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \FOSSBilling\Exception('Square gateway is missing required config: ' . $key);
            }
        }
        $this->config = $config;
    }

    // -------------------------------------------------------------------------
    // Gateway Configuration Declaration
    // -------------------------------------------------------------------------

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments'   => true,
            'supports_subscriptions'       => true,
            'description'                  => 'Pay securely with Square. Card details are tokenized in the browser and never touch this server. See Squaremanager admin for webhook and IPN reference URLs.',
            'logo'                         => [
                'logo'   => 'square.png',
                'height' => '30px',
                'width'  => '100px',
            ],
            'form' => [
                'access_token' => [
                    'text',
                    [
                        'label'    => 'Square Access Token',
                        'required' => true,
                    ],
                ],
                'application_id' => [
                    'text',
                    [
                        'label'    => 'Application ID',
                        'required' => true,
                    ],
                ],
                'location_id' => [
                    'text',
                    [
                        'label'    => 'Location ID',
                        'required' => true,
                    ],
                ],
                'environment' => [
                    'select',
                    [
                        'label'        => 'Environment',
                        'multiOptions' => [
                            'sandbox'    => 'Sandbox (Testing)',
                            'production' => 'Production (Live)',
                        ],
                    ],
                ],
                'webhook_signature_key' => [
                    'text',
                    [
                        'label'    => 'Webhook Signature Key (paste from Square Developer Dashboard after creating the webhook — optional but recommended)',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // getHtml() — Phase 1 / Phase 2
    // Render payment UI + inject squareConfig. NEVER throws fatal exceptions.
    // NEVER processes payment. NEVER submits forms.
    // -------------------------------------------------------------------------

    public function getHtml($api_admin, $invoice_id, $subscription = false): string
    {
        $invoiceIdInt = (int) $invoice_id;
        $amount       = '0.00';
        $currency     = 'USD';
        $gatewayId    = 0;
        $processUrl   = '/api/guest/squaremanager/process_payment';
        $invoiceModel = null;

        // --- Bootstrap DI ---
        // FOSSBilling calls getHtml() directly and does NOT call setDi() first,
        // so $this->di is null here. Recover it from $api_admin, which is the
        // admin API service (\Api_Abstract) and always has getDi().
        $di = $this->di;
        if ($di === null && is_object($api_admin)) {
            if ($api_admin instanceof \Pimple\Container) {
                $di = $api_admin;
            } elseif (method_exists($api_admin, 'getDi')) {
                try { $di = $api_admin->getDi(); } catch (\Throwable $ignored) {}
            }
            if ($di !== null) {
                $this->di = $di; // cache so remaining methods can use it
            }
        }

        if ($di === null) {
            error_log('[Square] getHtml: DI container unavailable — cannot load invoice ' . $invoiceIdInt);
        }

        // --- Load invoice ---
        // NOTE: FOSSBilling's invoice table has NO total column. The payable amount
        // must be computed via InvoiceService::getTotalWithTax() which sums
        // invoice_item.price * quantity and applies tax.
        if ($di !== null) {
            try {
                $invoiceModel = $di['db']->load('Invoice', $invoiceIdInt);
                if ($invoiceModel) {
                    $currency       = strtoupper($invoiceModel->currency ?? 'USD');
                    $invoiceService = $di['mod_service']('Invoice');
                    $rawTotal       = (float) $invoiceService->getTotalWithTax($invoiceModel);
                    if ($rawTotal > 0) {
                        $amount = number_format($rawTotal, 2, '.', '');
                    }
                }
                error_log('[Square] getHtml: invoice ' . $invoiceIdInt . ' amount=' . $amount . ' ' . $currency);
            } catch (\Throwable $e) {
                error_log('[Square] getHtml: invoice load failed: ' . $e->getMessage());
            }

            // --- Resolve gateway_id ---
            try {
                $gw = $di['db']->findOne('PayGateway', 'gateway = ?', ['Square']);
                if ($gw) {
                    $gatewayId = (int) ($gw->id ?? 0);
                }
            } catch (\Throwable $e) {
                error_log('[Square] getHtml: gateway lookup failed: ' . $e->getMessage());
            }

            // --- Build processUrl ---
            try {
                $processUrl = $di['url']->link('api/guest/squaremanager/process_payment');
            } catch (\Throwable $e) {
                try {
                    $processUrl = $di['url']->link('api/guest/squaremanager/process_payment', []);
                } catch (\Throwable $e2) {
                    $processUrl = '/api/guest/squaremanager/process_payment';
                }
            }
        }

        // --- Environment ---
        $environment = $this->_getEnvironment();
        $sdkUrl      = ($environment === 'production')
            ? 'https://web.squarecdn.com/v1/square.js'
            : 'https://sandbox.web.squarecdn.com/v1/square.js';

        $appId      = htmlspecialchars($this->config['application_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $locationId = htmlspecialchars($this->config['location_id'] ?? '', ENT_QUOTES, 'UTF-8');

        // --- Build redirect URL (invoice page after payment) ---
        $redirectUrl = '';
        try {
            $invoiceHash = $invoiceModel->hash ?? '';
            if ($invoiceHash && $di !== null) {
                $redirectUrl = $di['tools']->url('invoice/' . $invoiceHash);
            }
        } catch (\Throwable $e) {
            /* non-fatal — JS will fall back to reload */
        }

        // --- Encode config as JSON for inline script ---
        $jsConfig = json_encode([
            'applicationId'  => $this->config['application_id'] ?? '',
            'locationId'     => $this->config['location_id'] ?? '',
            'invoiceId'      => $invoiceIdInt,
            'gatewayId'      => $gatewayId,
            'processUrl'     => $processUrl,
            'redirectUrl'    => $redirectUrl,
            'amount'         => $amount,
            'currency'       => $currency,
            'environment'    => $environment,
            'isSubscription' => (bool) $subscription,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $inlineJs = $this->_buildCheckoutScript();

        return <<<HTML
<div id="square-checkout-root">
    <div id="square-card-container"></div>
    <button type="button" id="square-pay-button" class="btn btn-primary mt-2">Pay {$currency} {$amount}</button>
    <div id="square-payment-status" role="alert" aria-live="polite"></div>
</div>

<script>
    window.squareConfig = {$jsConfig};
</script>
<script src="{$sdkUrl}"></script>
<script>
{$inlineJs}
</script>
HTML;
    }

    // -------------------------------------------------------------------------
    // processTransaction() — Phase 3 / Phase 4
    // ONLY place where payment execution occurs.
    // Called by the guest API endpoint.
    // -------------------------------------------------------------------------

    public function processTransaction($api_admin, $id, $data, $gateway_id): bool
    {
        $invoiceId   = (int) ($data['invoice_id'] ?? 0);
        $sourceToken = trim($data['source_token'] ?? '');
        $gatewayId   = (int) $gateway_id;

        if ($invoiceId <= 0) {
            throw new \FOSSBilling\Exception('Square: invalid invoice_id');
        }
        if (empty($sourceToken)) {
            throw new \FOSSBilling\Exception('Square: source_token is required');
        }
        if ($gatewayId <= 0) {
            throw new \FOSSBilling\Exception('Square: invalid gateway_id');
        }

        // --- Load models ---
        $transaction = $this->di['db']->load('Transaction', (int) $id);
        if (!$transaction) {
            throw new \FOSSBilling\Exception('Square: transaction record not found: ' . $id);
        }

        $invoiceModel = $this->di['db']->load('Invoice', $invoiceId);
        if (!$invoiceModel) {
            throw new \FOSSBilling\Exception('Square: invoice not found: ' . $invoiceId);
        }

        // --- Derive amount from invoice (NEVER from frontend) ---
        // FOSSBilling's invoice table has no total column; use the Invoice service
        // which sums invoice_item.price * quantity and applies tax.
        $invoiceService = $this->di['mod_service']('Invoice');
        $amount   = (float) $invoiceService->getTotalWithTax($invoiceModel);
        $currency = strtoupper($invoiceModel->currency ?? 'USD');

        // --- Get billing period and product_id from client_order ---
        // The invoice table has NO period or product_id columns.
        // invoice_item.type='order' links via rel_id (TEXT) to client_order.id.
        // client_order has the definitive period and product_id for the order.
        $billingPeriod = '';
        $productId     = 0;
        try {
            $itemRows = $this->di['db']->getAll(
                "SELECT co.period, co.product_id
                 FROM invoice_item ii
                 JOIN client_order co ON co.id = CAST(ii.rel_id AS UNSIGNED)
                 WHERE ii.invoice_id = ? AND ii.type = 'order'
                   AND co.period IS NOT NULL AND co.period != ''
                 LIMIT 1",
                [$invoiceId]
            );
            if (!empty($itemRows)) {
                $billingPeriod = (string) ($itemRows[0]['period']     ?? '');
                $productId     = (int)   ($itemRows[0]['product_id']  ?? 0);
            }
            error_log('[Square] processTransaction: invoice=' . $invoiceId . ' period=' . $billingPeriod . ' product_id=' . $productId);
        } catch (\Throwable $e) {
            error_log('[Square] processTransaction: could not resolve period/product: ' . $e->getMessage());
        }

        // Build a plain array for downstream helpers that expect array access
        $invoice = [
            'id'         => $invoiceModel->id,
            'client_id'  => $invoiceModel->client_id,
            'total'      => $amount,
            'currency'   => $currency,
            'status'     => $invoiceModel->status,
            'period'     => $billingPeriod,
            'product_id' => $productId,
        ];

        if ($amount <= 0) {
            throw new \FOSSBilling\Exception('Square: invoice has zero or negative amount');
        }

        // --- Idempotency key ---
        $idempotencyKey = 'fossbilling-txn-' . $id . '-inv-' . $invoiceId;

        // --- Determine if subscription ---
        $isSubscription = $this->_isSubscriptionInvoice($invoice, $data);

        if ($isSubscription) {
            return $this->_processSubscriptionPayment(
                $transaction,
                $invoiceModel,
                $invoice,
                $sourceToken,
                $amount,
                $currency,
                $idempotencyKey,
                $data
            );
        }

        return $this->_processOneTimePayment(
            $transaction,
            $invoiceModel,
            $invoice,
            $sourceToken,
            $amount,
            $currency,
            $idempotencyKey
        );
    }

    // -------------------------------------------------------------------------
    // One-Time Payment
    // -------------------------------------------------------------------------

    private function _processOneTimePayment(
        object $transaction,
        object $invoiceModel,
        array  $invoice,
        string $sourceToken,
        float  $amount,
        string $currency,
        string $idempotencyKey
    ): bool {
        error_log('[Square] one-time payment: invoice=' . $invoice['id'] . ' amount=' . $amount . ' ' . $currency);

        $result = $this->_squareCreatePayment(
            $sourceToken,
            $amount,
            $currency,
            $idempotencyKey,
            null,
            null,
            $this->_buildPaymentNote((int) $invoice['id'])
        );

        $squarePaymentId = $result['payment']['id'] ?? null;
        if (empty($squarePaymentId)) {
            throw new \FOSSBilling\Exception('Square: payment response missing payment ID');
        }

        error_log('[Square] payment succeeded: squarePaymentId=' . $squarePaymentId);

        $this->_finalizeTransaction($transaction, $squarePaymentId, $amount, $currency, 'completed');
        $this->_finalizeInvoice($invoiceModel, $invoice, $transaction);

        return true;
    }

    // -------------------------------------------------------------------------
    // Subscription Payment
    // -------------------------------------------------------------------------

    private function _processSubscriptionPayment(
        object $transaction,
        object $invoiceModel,
        array  $invoice,
        string $sourceToken,
        float  $amount,
        string $currency,
        string $idempotencyKey,
        array  $data
    ): bool {
        error_log('[Square] subscription payment: invoice=' . $invoice['id']);

        $clientId = (int) ($invoice['client_id'] ?? 0);
        $gatewayId = (int) $transaction->gateway_id;

        // Step 1: Ensure Square customer exists
        $customerId = $this->_ensureSquareCustomer($clientId, $invoice);

        // Step 2: Store card as reusable payment source
        $cardId = $this->_storeCardOnFile($customerId, $sourceToken, $idempotencyKey . '-card');

        // Step 3: Resolve subscription plan mapping
        $planId = $this->_resolveSubscriptionPlanId($invoice, $data);

        // Step 4: Create Square subscription
        // Recurring amount = product's recurring price + tax (no setup fee).
        // The invoice total ($amount) may include a setup fee on the first invoice —
        // we must NOT pass that as the subscription price or Square charges setup fee every cycle.
        $billingPeriod   = $invoice['period'] ?? '';
        $recurringAmount = $this->_getRecurringAmountWithTax($invoiceModel, (int) $invoice['product_id'], $billingPeriod);
        error_log('[Square] subscription recurring amount (with tax, no setup): ' . $recurringAmount . ' vs invoice total: ' . $amount);

        $subscriptionResult = $this->_squareCreateSubscription(
            $customerId,
            $cardId,
            $planId,
            $idempotencyKey . '-sub',
            $billingPeriod,
            $recurringAmount,
            $currency
        );

        $squareSubId = $subscriptionResult['subscription']['id'] ?? null;
        if (empty($squareSubId)) {
            throw new \FOSSBilling\Exception('Square: subscription creation response missing subscription ID');
        }

        error_log('[Square] subscription created: squareSubId=' . $squareSubId);

        // Step 5: Charge the first invoice amount (initial payment)
        $payResult = $this->_squareCreatePayment(
            null,
            $amount,
            $currency,
            $idempotencyKey . '-initial',
            $customerId,
            $cardId,
            $this->_buildPaymentNote((int) $invoice['id'])
        );

        $squarePaymentId = $payResult['payment']['id'] ?? null;
        if (empty($squarePaymentId)) {
            throw new \FOSSBilling\Exception('Square: initial subscription payment response missing payment ID');
        }

        // Step 6: Persist subscription linkage (our square_subscription table)
        $this->_storeSubscriptionRecord([
            'client_id'          => $clientId,
            'invoice_id'         => (int) $invoice['id'],
            'gateway_id'         => $gatewayId,
            'currency'           => $currency,
            'amount'             => $recurringAmount > 0 ? $recurringAmount : $amount,
            'sq_subscription_id' => $squareSubId,
            'sq_customer_id'     => $customerId,
            'sq_card_id'         => $cardId,
            'status'             => 'active',
        ]);

        // Step 7: Register in FOSSBilling's native subscription table so it appears
        //         under Client → Subscriptions in the admin/client panel.
        $this->_storeFossBillingSubscription([
            'client_id'      => $clientId,
            'pay_gateway_id' => $gatewayId,
            'sid'            => $squareSubId,
            'rel_type'       => 'invoice',
            'rel_id'         => (int) $invoice['id'],
            'period'         => $billingPeriod,
            'amount'         => $recurringAmount > 0 ? $recurringAmount : $amount,
            'currency'       => $currency,
            'status'         => 'active',
        ]);

        $this->_finalizeTransaction($transaction, $squarePaymentId, $amount, $currency, 'completed');
        $this->_finalizeInvoice($invoiceModel, $invoice, $transaction);

        return true;
    }

    // -------------------------------------------------------------------------
    // Invoice / Transaction State
    // -------------------------------------------------------------------------

    private function _finalizeTransaction(
        object $transaction,
        string $externalId,
        float  $amount,
        string $currency,
        string $status
    ): void {
        $transaction->txn_id     = $externalId;
        $transaction->txn_status = $status;
        $transaction->amount     = $amount;
        $transaction->currency   = $currency;
        $transaction->status     = \Model_Transaction::STATUS_PROCESSED;
        $transaction->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($transaction);
    }

    private function _finalizeInvoice(object $invoiceModel, array $invoice, object $transaction): void
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $clientService  = $this->di['mod_service']('Client');

        // Load the client who owns this invoice
        $client = $this->di['db']->load('Client', (int) $invoice['client_id']);
        if (!$client) {
            throw new \FOSSBilling\Exception('Square: client not found: ' . $invoice['client_id']);
        }

        // Step 1: Credit the client balance with the payment amount.
        // markAsPaid() draws from this balance to settle the invoice.
        $txDesc = 'Square payment ' . ($transaction->txn_id ?? $transaction->id);
        $clientService->addFunds($client, $invoice['total'], $txDesc, []);

        // Step 2: Mark the invoice as paid and activate associated services
        //   $charge  = true  → use the credited balance to pay
        //   $execute = true  → activate related orders/services
        $invoiceService->markAsPaid($invoiceModel, true, true);
    }

    // -------------------------------------------------------------------------
    // Subscription Detection
    // -------------------------------------------------------------------------

    private function _isSubscriptionInvoice(array $invoice, array $data): bool
    {
        // Primary: billing period resolved from invoice_item in processTransaction
        if (!empty($invoice['period'])) {
            return true;
        }

        // Secondary: explicit flag from frontend or caller
        if (!empty($data['is_subscription'])) {
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Square Customer Management
    // -------------------------------------------------------------------------

    private function _ensureSquareCustomer(int $clientId, array $invoice): string
    {
        // Check if we already have a Square customer ID stored
        $existing = null;
        try {
            $existing = $this->di['db']->getCell(
                'SELECT sq_customer_id FROM square_customer WHERE client_id = ? LIMIT 1',
                [$clientId]
            );
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        if (!empty($existing)) {
            error_log('[Square] reusing existing customer: ' . $existing);
            return $existing;
        }

        // Create new customer in Square
        $clientModel = $this->di['db']->load('Client', $clientId);
        $email       = $clientModel ? ($clientModel->email ?? '') : '';
        $firstName   = $clientModel ? ($clientModel->first_name ?? '') : '';
        $lastName    = $clientModel ? ($clientModel->last_name ?? '') : '';

        $response = $this->squareRequest('POST', '/v2/customers', [
            'idempotency_key' => 'fossbilling-customer-' . $clientId,
            'given_name'      => $firstName,
            'family_name'     => $lastName,
            'email_address'   => $email,
        ]);

        $customerId = $response['customer']['id'] ?? null;
        if (empty($customerId)) {
            throw new \FOSSBilling\Exception('Square: failed to create customer');
        }

        // Persist customer ID — use direct SQL, dispense() fails for custom tables
        try {
            $this->di['db']->exec(
                'INSERT INTO square_customer (client_id, sq_customer_id, created_at)
                 VALUES (?, ?, ?)',
                [$clientId, $customerId, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            error_log('[Square] _ensureSquareCustomer: failed to persist customer record: ' . $e->getMessage());
        }

        error_log('[Square] created Square customer: ' . $customerId);
        return $customerId;
    }

    // -------------------------------------------------------------------------
    // Card on File
    // -------------------------------------------------------------------------

    private function _storeCardOnFile(string $customerId, string $sourceToken, string $idempotencyKey): string
    {
        $response = $this->squareRequest('POST', '/v2/cards', [
            'idempotency_key' => $idempotencyKey,
            'source_id'       => $sourceToken,
            'card'            => [
                'customer_id' => $customerId,
            ],
        ]);

        $cardId = $response['card']['id'] ?? null;
        if (empty($cardId)) {
            throw new \FOSSBilling\Exception('Square: failed to store card on file');
        }

        error_log('[Square] stored card on file: ' . $cardId);
        return $cardId;
    }

    // -------------------------------------------------------------------------
    // Subscription Plan Resolution
    // -------------------------------------------------------------------------

    private function _resolveSubscriptionPlanId(array $invoice, array $data): string
    {
        // Allow explicit override from data (e.g. passed by extension)
        if (!empty($data['sq_plan_id'])) {
            return (string) $data['sq_plan_id'];
        }

        // Attempt to look up from extension mapping table
        $productId     = (int) ($invoice['product_id'] ?? 0);
        $billingPeriod = $invoice['period'] ?? $invoice['billing_period'] ?? '';

        if ($productId > 0) {
            try {
                // Use direct SQL — findOne() on custom tables fails in frozen RedBeanPHP mode
                $environment = $this->_getEnvironment();
                $planId = $this->di['db']->getCell(
                    'SELECT sq_plan_id FROM square_plan_map WHERE product_id = ? AND billing_period = ? AND environment = ? LIMIT 1',
                    [$productId, $billingPeriod, $environment]
                );
                if (!empty($planId)) {
                    error_log('[Square] _resolveSubscriptionPlanId: found plan=' . $planId . ' for product=' . $productId . ' period=' . $billingPeriod . ' env=' . $environment);
                    return (string) $planId;
                }
            } catch (\Throwable $e) {
                error_log('[Square] _resolveSubscriptionPlanId: SQL error: ' . $e->getMessage());
            }
        }

        throw new \FOSSBilling\Exception(
            'Square: no subscription plan mapping found for product=' . $productId .
            ' period=' . $billingPeriod . '. Configure in Squaremanager admin module.'
        );
    }

    // -------------------------------------------------------------------------
    // Subscription Record Persistence
    // -------------------------------------------------------------------------

    private function _storeSubscriptionRecord(array $data): void
    {
        // Use direct SQL — dispense() fails for custom tables in frozen RedBeanPHP mode
        try {
            $now = date('Y-m-d H:i:s');
            $existingId = $this->di['db']->getCell(
                'SELECT id FROM square_subscription WHERE client_id = ? AND invoice_id = ? LIMIT 1',
                [$data['client_id'], $data['invoice_id']]
            );

            if ($existingId) {
                $this->di['db']->exec(
                    'UPDATE square_subscription
                     SET sq_subscription_id = ?, sq_customer_id = ?, sq_card_id = ?,
                         status = ?, updated_at = ?
                     WHERE id = ?',
                    [
                        $data['sq_subscription_id'],
                        $data['sq_customer_id'],
                        $data['sq_card_id'],
                        $data['status'],
                        $now,
                        $existingId,
                    ]
                );
            } else {
                $this->di['db']->exec(
                    'INSERT INTO square_subscription
                     (client_id, invoice_id, gateway_id, currency, amount,
                      sq_subscription_id, sq_customer_id, sq_card_id, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $data['client_id'],
                        $data['invoice_id'],
                        $data['gateway_id'],
                        $data['currency'],
                        $data['amount'],
                        $data['sq_subscription_id'],
                        $data['sq_customer_id'],
                        $data['sq_card_id'],
                        $data['status'],
                        $now,
                        $now,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Log but do not fail the payment — subscription record is helpful but not blocking
            error_log('[Square] _storeSubscriptionRecord failed: ' . $e->getMessage());
        }
    }

    /**
     * Write a record to FOSSBilling's native `subscription` table so the subscription
     * appears under Admin → Clients → Subscriptions (and in the client portal).
     *
     * Fields:
     *   client_id, pay_gateway_id, sid (Square sub ID), rel_type, rel_id,
     *   period, amount, currency, status, created_at, updated_at
     */
    private function _storeFossBillingSubscription(array $data): void
    {
        try {
            $now = date('Y-m-d H:i:s');

            // Check for an existing record by sid to avoid duplicates
            $existingId = $this->di['db']->getCell(
                'SELECT id FROM subscription WHERE sid = ? LIMIT 1',
                [$data['sid']]
            );

            if ($existingId) {
                $this->di['db']->exec(
                    'UPDATE subscription SET status = ?, amount = ?, updated_at = ? WHERE id = ?',
                    [$data['status'], $data['amount'], $now, $existingId]
                );
                error_log('[Square] FOSSBilling subscription record updated: id=' . $existingId);
            } else {
                $this->di['db']->exec(
                    'INSERT INTO subscription
                     (client_id, pay_gateway_id, sid, rel_type, rel_id, period, amount, currency, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $data['client_id'],
                        $data['pay_gateway_id'],
                        $data['sid'],
                        $data['rel_type'],
                        $data['rel_id'],
                        $data['period'],
                        $data['amount'],
                        $data['currency'],
                        $data['status'],
                        $now,
                        $now,
                    ]
                );
                error_log('[Square] FOSSBilling subscription record created: sid=' . $data['sid']);
            }
        } catch (\Throwable $e) {
            error_log('[Square] _storeFossBillingSubscription failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Public Subscription Management (called from Admin API)
    // -------------------------------------------------------------------------

    /**
     * Cancel a Square subscription and sync both local tables to 'canceled'.
     * Called by Admin API cancel_subscription.
     */
    public function cancelSubscription(string $sqSubId): array
    {
        $result = $this->squareRequest('POST', '/v2/subscriptions/' . $sqSubId . '/cancel', []);
        $squareStatus = $result['subscription']['status'] ?? 'CANCELED';

        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'UPDATE square_subscription SET status = ?, updated_at = ? WHERE sq_subscription_id = ?',
            ['canceled', $now, $sqSubId]
        );
        $this->di['db']->exec(
            'UPDATE subscription SET status = ?, updated_at = ? WHERE sid = ?',
            ['canceled', $now, $sqSubId]
        );

        error_log('[Square] cancelSubscription: ' . $sqSubId . ' → ' . $squareStatus);
        return ['sq_status' => $squareStatus, 'local_status' => 'canceled', 'updated' => true];
    }

    /**
     * Fetch current status from Square and sync both local tables.
     * Called by Admin API sync_subscription and sync_all_subscriptions.
     */
    public function syncSubscriptionStatus(string $sqSubId): array
    {
        $result = $this->squareRequest('GET', '/v2/subscriptions/' . $sqSubId);
        $sub    = $result['subscription'] ?? null;
        if (!$sub) {
            throw new \FOSSBilling\Exception('Square subscription not found: ' . $sqSubId);
        }

        $squareStatus = strtolower($sub['status'] ?? 'unknown');
        // Map Square statuses → our local statuses
        $statusMap = [
            'active'      => 'active',
            'canceled'    => 'canceled',
            'deactivated' => 'canceled',
            'paused'      => 'paused',
            'pending'     => 'pending',
        ];
        $localStatus = $statusMap[$squareStatus] ?? $squareStatus;

        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'UPDATE square_subscription SET status = ?, updated_at = ? WHERE sq_subscription_id = ?',
            [$localStatus, $now, $sqSubId]
        );
        $this->di['db']->exec(
            'UPDATE subscription SET status = ?, updated_at = ? WHERE sid = ?',
            [$localStatus, $now, $sqSubId]
        );

        error_log('[Square] syncSubscriptionStatus: ' . $sqSubId . ' Square=' . $squareStatus . ' local=' . $localStatus);
        return [
            'sq_subscription_id' => $sqSubId,
            'square_status'      => $sub['status'],
            'local_status'       => $localStatus,
            'synced'             => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Square API — Create Payment
    // -------------------------------------------------------------------------

    private function _squareCreatePayment(
        ?string $sourceToken,
        float   $amount,
        string  $currency,
        string  $idempotencyKey,
        ?string $customerId = null,
        ?string $cardId     = null,
        string  $note       = ''
    ): array {
        $amountMoney = [
            'amount'   => (int) round($amount * 100),
            'currency' => $currency,
        ];

        $payload = [
            'idempotency_key' => $idempotencyKey,
            'amount_money'    => $amountMoney,
            'location_id'     => $this->config['location_id'],
        ];

        if (!empty($note)) {
            $payload['note'] = substr($note, 0, 500); // Square note max 500 chars
        }

        if ($sourceToken !== null) {
            $payload['source_id'] = $sourceToken;
        } elseif ($cardId !== null && $customerId !== null) {
            $payload['source_id']   = $cardId;
            $payload['customer_id'] = $customerId;
        } else {
            throw new \FOSSBilling\Exception('Square: payment requires either source_token or card+customer');
        }

        return $this->squareRequest('POST', '/v2/payments', $payload);
    }

    private function _buildPaymentNote(int $invoiceId): string
    {
        try {
            $rows = $this->di['db']->getAll(
                'SELECT title, quantity, price FROM invoice_item WHERE invoice_id = ? ORDER BY id LIMIT 5',
                [$invoiceId]
            );
            if (empty($rows)) {
                return 'Invoice #' . $invoiceId;
            }
            $lines = array_map(function ($r) {
                $qty = (int) ($r['quantity'] ?? 1);
                $label = $r['title'] ?? '';
                return $qty > 1 ? $qty . 'x ' . $label : $label;
            }, $rows);
            $note = implode(', ', $lines);
            if (count($rows) === 5) {
                $note .= ' …';
            }
            return 'Invoice #' . $invoiceId . ': ' . $note;
        } catch (\Throwable $e) {
            return 'Invoice #' . $invoiceId;
        }
    }

    // -------------------------------------------------------------------------
    // Square API — Create Subscription
    // -------------------------------------------------------------------------

    private function _squareCreateSubscription(
        string $customerId,
        string $cardId,
        string $planId,
        string $idempotencyKey,
        string $billingPeriod    = '',
        float  $recurringAmount  = 0.0,
        string $currency         = 'USD'
    ): array {
        // Set start_date to 1 full billing period from today so Square only charges
        // after the cycle we already covered with our initial _squareCreatePayment call.
        $startDate = $this->_nextBillingDate($billingPeriod);

        $payload = [
            'idempotency_key'   => $idempotencyKey,
            'location_id'       => $this->config['location_id'],
            'plan_variation_id' => $planId,
            'customer_id'       => $customerId,
            'card_id'           => $cardId,
            'start_date'        => $startDate,
        ];

        // Override the subscription price with the recurring amount (tax-inclusive, no setup fee).
        // This ensures Square charges the correct amount each cycle even if the variation's
        // built-in price differs, and correctly includes tax without including the setup fee.
        // price_override_money is supported for STATIC pricing variations.
        if ($recurringAmount > 0) {
            $payload['price_override_money'] = [
                'amount'   => (int) round($recurringAmount * 100),
                'currency' => strtoupper($currency),
            ];
        }

        error_log('[Square] _squareCreateSubscription: planId=' . $planId . ' start_date=' . $startDate . ' period=' . $billingPeriod . ' recurring=' . $recurringAmount);
        return $this->squareRequest('POST', '/v2/subscriptions', $payload);
    }

    /**
     * Get the recurring price for a product/period combination with tax applied.
     * This is what Square should charge each billing cycle — it excludes the setup fee
     * (which only appears on the first invoice) and includes the same tax rates as the invoice.
     *
     * FOSSBilling period → product_payment price column:
     *   1W → w_price,  1M → m_price,  3M → q_price,
     *   6M → b_price,  1Y → a_price,  2Y → bia_price
     *
     * Returns 0.0 if the period has no dedicated price column (caller falls back to variation price).
     */
    private function _getRecurringAmountWithTax(object $invoiceModel, int $productId, string $billingPeriod): float
    {
        $periodToCol = [
            '1W'  => 'w_price',
            '1M'  => 'm_price',
            '3M'  => 'q_price',
            '6M'  => 'b_price',
            '1Y'  => 'a_price',
            '2Y'  => 'bia_price',
        ];

        $priceCol = $periodToCol[strtoupper(trim($billingPeriod))] ?? null;
        if (!$priceCol || $productId <= 0) {
            return 0.0;
        }

        try {
            $recurringPrice = (float) $this->di['db']->getCell(
                "SELECT pp.{$priceCol}
                 FROM product p
                 JOIN product_payment pp ON pp.id = p.product_payment_id
                 WHERE p.id = ? LIMIT 1",
                [$productId]
            );

            if ($recurringPrice <= 0) {
                return 0.0;
            }

            // Apply the same tax rates the invoice uses
            $taxrate  = (float) ($invoiceModel->taxrate  ?? 0);
            $taxrate2 = (float) ($invoiceModel->taxrate2 ?? 0);
            $multiplier = (1 + $taxrate / 100) * (1 + $taxrate2 / 100);

            $result = round($recurringPrice * $multiplier, 2);
            error_log('[Square] _getRecurringAmountWithTax: product=' . $productId . ' period=' . $billingPeriod . ' base=' . $recurringPrice . ' tax=' . $taxrate . '+' . $taxrate2 . '% total=' . $result);
            return $result;

        } catch (\Throwable $e) {
            error_log('[Square] _getRecurringAmountWithTax error: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Calculate the next billing date (1 full period from today) from a FOSSBilling period string.
     * FOSSBilling periods: 1D, 1W, 2W, 1M, 2M, 3M, 4M, 6M, 1Y, 2Y
     */
    private function _nextBillingDate(string $period): string
    {
        $map = [
            '1D' => '+1 day',
            '1W' => '+1 week',
            '2W' => '+2 weeks',
            '1M' => '+1 month',
            '2M' => '+2 months',
            '3M' => '+3 months',
            '4M' => '+4 months',
            '6M' => '+6 months',
            '1Y' => '+1 year',
            '2Y' => '+2 years',
        ];

        $modifier = $map[strtoupper(trim($period))] ?? '+1 month';
        return date('Y-m-d', strtotime($modifier));
    }

    // -------------------------------------------------------------------------
    // Square API — HTTP Client
    // -------------------------------------------------------------------------

    public function squareRequest(string $method, string $path, array $payload = []): array
    {
        $baseUrl = ($this->_getEnvironment() === 'production')
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';

        $url   = $baseUrl . $path;
        $token = $this->config['access_token'];

        $headers = [
            'Authorization: Bearer ' . $token,
            'Square-Version: 2024-01-18',
            'Accept: application/json',
        ];

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        // Only attach a body for methods that accept one; GET/HEAD/DELETE must never send a body
        if (!in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true)) {
            $body = json_encode(empty($payload) ? new \stdClass() : $payload);
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $curlOpts);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \FOSSBilling\Exception('Square: HTTP transport error: ' . $curlErr);
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            throw new \FOSSBilling\Exception('Square: API returned non-JSON response (HTTP ' . $httpCode . ')');
        }

        if (!empty($decoded['errors'])) {
            $errMsg = implode('; ', array_map(
                fn($e) => ($e['category'] ?? '') . ':' . ($e['code'] ?? '') . ' ' . ($e['detail'] ?? ''),
                $decoded['errors']
            ));
            throw new \FOSSBilling\Exception('Square API error: ' . $errMsg);
        }

        if ($httpCode >= 400) {
            throw new \FOSSBilling\Exception('Square API HTTP error: ' . $httpCode);
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function _getEnvironment(): string
    {
        return ($this->config['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    // -------------------------------------------------------------------------
    // Inline Frontend Checkout Script
    // -------------------------------------------------------------------------

    private function _buildCheckoutScript(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    var cfg = window.squareConfig;

    // --- Validate required config ---
    var required = ['applicationId', 'locationId', 'invoiceId', 'gatewayId', 'processUrl'];
    var missing = required.filter(function (k) { return !cfg || !cfg[k]; });

    if (missing.length > 0) {
        console.error('[Square] missing required config fields:', missing);
        var root = document.getElementById('square-checkout-root');
        if (root) {
            root.innerHTML = '<p style="color:#c0392b">Payment system is unavailable. Please contact support.</p>';
        }
        return;
    }

    // --- Wait for Square SDK ---
    if (typeof Square === 'undefined') {
        console.error('[Square] Square SDK not loaded.');
        var status = document.getElementById('square-payment-status');
        if (status) status.textContent = 'Payment system is temporarily unavailable.';
        var btn = document.getElementById('square-pay-button');
        if (btn) btn.disabled = true;
        return;
    }

    var payments = null;
    var card     = null;

    async function init() {
        try {
            payments = Square.payments(cfg.applicationId, cfg.locationId);
            card     = await payments.card();
            await card.attach('#square-card-container');
            console.log('[Square] card UI attached.');
        } catch (err) {
            console.error('[Square] init error:', err);
            setStatus('Payment system is unavailable. Please try again later.', true);
            var btn = document.getElementById('square-pay-button');
            if (btn) btn.disabled = true;
        }
    }

    function setStatus(msg, isError) {
        var el = document.getElementById('square-payment-status');
        if (!el) return;
        el.textContent = msg;
        el.style.color = isError ? '#c0392b' : '#27ae60';
    }

    async function handlePay(event) {
        event.preventDefault();
        event.stopPropagation();

        var btn = document.getElementById('square-pay-button');
        if (btn) btn.disabled = true;
        setStatus('', false);

        if (!card) {
            setStatus('Payment system is not ready. Please refresh and try again.', true);
            if (btn) btn.disabled = false;
            return;
        }

        var tokenResult;
        try {
            tokenResult = await card.tokenize();
        } catch (err) {
            console.error('[Square] tokenize error:', err);
            setStatus('Unable to process card details. Please try again.', true);
            if (btn) btn.disabled = false;
            return;
        }

        if (tokenResult.status !== 'OK') {
            var errors = (tokenResult.errors || []).map(function (e) {
                return e.message || 'Card error';
            }).join(' ');
            console.error('[Square] tokenization failed:', tokenResult.errors);
            setStatus(errors || 'Card details are invalid. Please check and try again.', true);
            if (btn) btn.disabled = false;
            return;
        }

        var token = tokenResult.token;

        // --- Send to backend ---
        var payload = JSON.stringify({
            invoice_id:   cfg.invoiceId,
            gateway_id:   cfg.gatewayId,
            source_token: token
        });

        var response;
        try {
            response = await fetch(cfg.processUrl, {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body:        payload
            });
        } catch (netErr) {
            console.error('[Square] network error:', netErr);
            setStatus('Network error. Please check your connection and try again.', true);
            if (btn) btn.disabled = false;
            return;
        }

        var json;
        try {
            json = await response.json();
        } catch (parseErr) {
            console.error('[Square] non-JSON response from server');
            setStatus('Payment failed. Please try again.', true);
            if (btn) btn.disabled = false;
            return;
        }

        /* FOSSBilling API wraps responses as:
           success → { result: true,  error: null }
           failure → { result: null,  error: { message: "...", code: 0 } }
           Our Guest.php throws Box_Exception on error and returns true on success. */
        if (json && json.result === true) {
            setStatus('Payment successful! Redirecting...', false);
            setTimeout(function () {
                if (cfg.redirectUrl) {
                    window.location.href = cfg.redirectUrl;
                } else {
                    window.location.reload();
                }
            }, 1500);
        } else {
            var errMsg = 'Payment failed. Please try again.';
            if (json && json.error) {
                if (typeof json.error === 'object' && json.error.message) {
                    errMsg = json.error.message;
                } else if (typeof json.error === 'string') {
                    errMsg = json.error;
                }
            }
            console.error('[Square] backend error:', json);
            setStatus(errMsg, true);
            if (btn) btn.disabled = false;
        }
    }

    // Wire up button
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('square-pay-button');
        if (btn) {
            btn.addEventListener('click', handlePay);
        }
        init();
    });

    // If DOMContentLoaded already fired
    if (document.readyState !== 'loading') {
        var btn = document.getElementById('square-pay-button');
        if (btn) {
            btn.addEventListener('click', handlePay);
        }
        init();
    }

})();
JS;
    }
}
