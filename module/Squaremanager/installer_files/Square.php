<?php
declare(strict_types=1);

/**
 * Square Payment Adapter for FOSSBilling
 *
 * Responsibilities:
 * - Render the Square payment form for one-time and subscription invoices
 * - Load the Square frontend script that tokenizes card details
 * - Provide common configuration helpers (sandbox/live mode, keys, locations)
 *
 * Notes:
 * - This adapter is intentionally focused on payment runtime behavior.
 * - Admin/export/mapping UI belongs in the Squaremanager module.
 * - The module can deploy this adapter file into the Payment/Adapter directory.
 */
class Payment_Adapter_Square implements FOSSBilling\InjectionAwareInterface
{
    /**
     * FOSSBilling dependency injection container.
     *
     * @var Pimple\Container|null
     */
    protected ?Pimple\Container $di = null;

    /**
     * Constructor.
     *
     * Validates the minimum required Square credentials depending on whether
     * the adapter is running in sandbox or live mode.
     *
     * @param array $config Gateway configuration stored by FOSSBilling
     *
     * @throws Payment_Exception When required credentials are missing
     */
    public function __construct(private array $config)
    {
        if ($this->isTestMode()) {
            foreach (['test_application_id', 'test_access_token', 'test_location_id'] as $required) {
                if (empty($this->config[$required])) {
                    throw new Payment_Exception(
                        'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                        [':pay_gateway' => 'Square', ':missing' => $required],
                        4001
                    );
                }
            }
        } else {
            foreach (['application_id', 'access_token', 'location_id'] as $required) {
                if (empty($this->config[$required])) {
                    throw new Payment_Exception(
                        'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                        [':pay_gateway' => 'Square', ':missing' => $required],
                        4001
                    );
                }
            }
        }
    }

    /**
     * Inject the FOSSBilling DI container.
     *
     * @param Pimple\Container $di Dependency container
     */
    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    /**
     * Return the DI container.
     *
     * @return Pimple\Container|null
     */
    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    /**
     * Return gateway configuration metadata used by FOSSBilling admin UI.
     *
     * This controls:
     * - whether subscriptions are supported
     * - which gateway fields appear in admin configuration
     *
     * @return array
     */
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => true,
            'can_load_in_iframe'         => true,
            'description'                => 'Accept card payments and subscriptions through Square.',
            'logo' => [
                'logo'   => 'square.png',
                'height' => '50px',
                'width'  => '200px',
            ],
            'form' => [
                'application_id' => [
                    'text',
                    [
                        'label' => 'Live Application ID',
                    ],
                ],
                'access_token' => [
                    'password',
                    [
                        'label' => 'Live Access Token',
                    ],
                ],
                'location_id' => [
                    'text',
                    [
                        'label' => 'Live Location ID',
                    ],
                ],
                'webhook_signature_key' => [
                    'password',
                    [
                        'label'    => 'Live Webhook Signature Key',
                        'required' => false,
                    ],
                ],
                'test_application_id' => [
                    'text',
                    [
                        'label'    => 'Test Application ID',
                        'required' => false,
                    ],
                ],
                'test_access_token' => [
                    'password',
                    [
                        'label'    => 'Test Access Token',
                        'required' => false,
                    ],
                ],
                'test_location_id' => [
                    'text',
                    [
                        'label'    => 'Test Location ID',
                        'required' => false,
                    ],
                ],
                'test_webhook_signature_key' => [
                    'password',
                    [
                        'label'    => 'Test Webhook Signature Key',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Render the Square checkout form HTML.
     *
     * This method does not process payments itself. It only:
     * - loads invoice context
     * - determines whether this is a one-time or subscription action
     * - prints the Square card container
     * - loads the frontend JS that tokenizes the card and submits the form
     *
     * Important:
     * - The adapter JS file is expected to live alongside this adapter for
     *   easier deployment by the companion module.
     * - The actual charge/subscription logic runs later in processTransaction().
     *
     * @param mixed $api_admin  FOSSBilling admin API instance
     * @param int   $invoice_id Invoice ID being paid
     * @param bool  $subscription Whether the rendered flow is subscription mode
     *
     * @return string HTML markup for the payment form
     */
    public function getHtml($api_admin, int $invoice_id, bool $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        // Resolve the correct Square Application ID and Location ID depending
        // on whether the gateway is currently in sandbox or live mode.
        $applicationId = $this->getAppId();
        $locationId = $this->getLocationId();

        // The Square Web Payments SDK uses different script URLs depending
        // on environment.
        $squareJsUrl = $this->isTestMode()
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';

        // Notify URL is the callback target for the tokenized form submission.
        $formAction = (string)($this->config['notify_url'] ?? $this->config['redirect_url'] ?? '');

        $amount = number_format((float)$invoice['total'], 2, '.', '');
        $currency = (string)$invoice['currency'];

        // The frontend posts this hidden action value so the backend knows
        // whether to create a one-time payment or a subscription.
        $squareAction = $subscription ? 'create_subscription' : 'create_payment';

        $buttonText = $subscription
            ? 'Start subscription with Square'
            : 'Pay ' . $amount . ' ' . $currency . ' with Square';

        // Escape all rendered values to keep the HTML output safe.
        $formActionEsc = htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8');
        $invoiceIdEsc = (int)$invoice['id'];
        $invoiceHashEsc = htmlspecialchars((string)$invoice['hash'], ENT_QUOTES, 'UTF-8');
        $buttonTextEsc = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');

        $appIdEsc = htmlspecialchars($applicationId, ENT_QUOTES, 'UTF-8');
        $locIdEsc = htmlspecialchars($locationId, ENT_QUOTES, 'UTF-8');
        $squareJsUrlEsc = htmlspecialchars($squareJsUrl, ENT_QUOTES, 'UTF-8');
        $squareActionEsc = htmlspecialchars($squareAction, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div
    id="square-checkout-root"
    data-square-application-id="{$appIdEsc}"
    data-square-location-id="{$locIdEsc}"
    data-square-js-url="{$squareJsUrlEsc}"
    data-square-action="{$squareActionEsc}"
>
    <div id="square-card-container" style="max-width:480px;"></div>
    <div id="square-payment-status" style="margin-top:10px;color:#c00;"></div>

    <form id="square-payment-form" method="post" action="{$formActionEsc}">
        <input type="hidden" name="invoice_id" value="{$invoiceIdEsc}">
        <input type="hidden" name="invoice_hash" value="{$invoiceHashEsc}">
        <input type="hidden" name="source_id" id="square_source_id" value="">
        <input type="hidden" name="square_action" id="square_action" value="{$squareActionEsc}">

        <button id="square-pay-button" class="btn btn-primary" type="button">
            {$buttonTextEsc}
        </button>
    </form>
</div>

<script src="{$squareJsUrlEsc}"></script>
<script src="/library/Payment/Adapter/square-checkout.js"></script>
HTML;
    }

    /**
     * Determine whether the gateway is currently running in sandbox mode.
     *
     * @return bool
     */
    private function isTestMode(): bool
    {
        return (bool)($this->config['test_mode'] ?? false);
    }

    /**
     * Return the correct Square Application ID for the active mode.
     *
     * @return string
     */
    private function getAppId(): string
    {
        return $this->isTestMode()
            ? (string)$this->config['test_application_id']
            : (string)$this->config['application_id'];
    }

    /**
     * Return the correct Square access token for the active mode.
     *
     * @return string
     */
    private function getAccessToken(): string
    {
        return $this->isTestMode()
            ? (string)$this->config['test_access_token']
            : (string)$this->config['access_token'];
    }

    /**
     * Return the correct Square location ID for the active mode.
     *
     * @return string
     */
    private function getLocationId(): string
    {
        return $this->isTestMode()
            ? (string)$this->config['test_location_id']
            : (string)$this->config['location_id'];
    }

    /**
     * Return the webhook signing key for the active mode.
     *
     * @return string
     */
    private function getWebhookSignatureKey(): string
    {
        return $this->isTestMode()
            ? (string)($this->config['test_webhook_signature_key'] ?? '')
            : (string)($this->config['webhook_signature_key'] ?? '');
    }

    /**
     * Return the base Square API URL for the active mode.
     *
     * Sandbox and live use different API hosts.
     *
     * @return string
     */
    private function getApiBaseUrl(): string
    {
        return $this->isTestMode()
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }
/**
     * Process an incoming transaction request.
     *
     * This method is the main entry point for:
     * - Browser-submitted payment/subscription forms
     * - Square webhook callbacks
     *
     * Flow:
     * 1. If the request contains a raw JSON body and Square signature header,
     *    treat it as a webhook and validate/process it.
     * 2. Otherwise, treat it as a browser-submitted payment form.
     * 3. Route to either one-time payment handling or subscription handling.
     *
     * @param mixed $api_admin  FOSSBilling admin API instance
     * @param int   $id         Transaction ID
     * @param array $data       Incoming callback/request data
     * @param int   $gateway_id Payment gateway ID
     *
     * @throws Payment_Exception When required payment data is missing
     */
    public function processTransaction($api_admin, int $id, array $data, int $gateway_id): void
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        // --------------------------------------------------
        // Webhook Path
        // --------------------------------------------------
        // If Square is calling our notify URL directly, the request will contain:
        // - a raw JSON body
        // - the X-Square-HmacSha256-Signature header
        //
        // In that case we do not process the request as a browser payment form.
        $rawBody = (string)($data['http_raw_post_data'] ?? '');
        $headerSignature =
            $data['server']['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']
            ?? $data['server']['REDIRECT_HTTP_X_SQUARE_HMACSHA256_SIGNATURE']
            ?? null;

        if ($rawBody !== '' && $headerSignature) {
            $this->handleWebhook($api_admin, $tx, $rawBody, (string)$headerSignature, $gateway_id);
            return;
        }

        // --------------------------------------------------
        // Browser Form Path
        // --------------------------------------------------
        // For browser-submitted requests, we recover the invoice ID from:
        // - the existing transaction if already linked
        // - POST invoice_id
        // - GET invoice_id
        //
        // This adapter requires an invoice because setup fees, recurring amounts,
        // billing periods, and product identity all come from invoice context.
        $invoiceId =
            ($tx->invoice_id ?? null)
            ?: (int)($data['post']['invoice_id'] ?? 0)
            ?: (int)($data['get']['invoice_id'] ?? 0);

        if (!$invoiceId) {
            throw new Payment_Exception('Invoice ID not provided in callback');
        }

        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);
        $tx->invoice_id = $invoice->id;

        // The frontend posts the hidden action field so the backend knows whether
        // to create a one-time payment or a subscription.
        $squareAction = (string)($data['post']['square_action'] ?? 'create_payment');

        // The Square Web Payments SDK tokenizes the card and posts the secure token
        // as source_id. Without this token, Square cannot create a payment or save
        // the card for subscription billing.
        $sourceId = (string)($data['post']['source_id'] ?? '');

        if ($sourceId === '') {
            throw new Payment_Exception('Missing Square source_id');
        }

        // --------------------------------------------------
        // Subscription Flow
        // --------------------------------------------------
        // Subscription invoices can include:
        // - an optional one-time setup fee
        // - a recurring line item
        //
        // The setup fee is charged separately as a normal one-time payment.
        // The recurring portion is then turned into a Square subscription.
        if ($squareAction === 'create_subscription') {
            $this->handleCreateSubscription($api_admin, $tx, $invoice, $sourceId, $gateway_id);
            return;
        }

        // --------------------------------------------------
        // One-Time Payment Flow
        // --------------------------------------------------
        // For normal invoices, simply create a standard Square payment.
        $this->handleOneTimePayment($tx, $invoice, $sourceId);
    }

    /**
     * Handle a standard one-time invoice payment.
     *
     * This method:
     * - calculates invoice total
     * - creates a Square payment
     * - stores the returned transaction details
     * - applies funds/marks invoice paid if the payment completed
     *
     * @param object $tx       Transaction model
     * @param object $invoice  Invoice model
     * @param string $sourceId Square tokenized card source ID
     *
     * @throws Payment_Exception When Square returns an invalid or failed response
     */
    private function handleOneTimePayment($tx, $invoice, string $sourceId): void
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        // Total invoice amount (including tax) is sent to Square in the smallest
        // currency unit, for example cents for USD.
        $amount = (float)$invoiceService->getTotalWithTax($invoice);
        $amountCents = (int)round($amount * 100);

        $squareResponse = $this->createSquarePayment(
            $sourceId,
            $amountCents,
            (string)$invoice->currency,
            'Invoice #' . (string)$invoice->serie_nr,
            (string)$invoice->id
        );

        $payment = $squareResponse['payment'] ?? null;
        $errors = $squareResponse['errors'] ?? null;

        if (!empty($errors)) {
            $tx->status = 'error';
            $tx->error = json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Square payment failed: ' . $tx->error);
        }

        if (!is_array($payment) || empty($payment['id'])) {
            $tx->status = 'error';
            $tx->error = 'Square payment response missing payment object';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Square payment response invalid');
        }

        $tx->txn_id = (string)$payment['id'];
        $tx->txn_status = (string)($payment['status'] ?? 'UNKNOWN');
        $tx->amount = ((float)($payment['amount_money']['amount'] ?? 0)) / 100;
        $tx->currency = (string)($payment['amount_money']['currency'] ?? $invoice->currency);

        // If Square completed the payment immediately, apply the funds and mark
        // the invoice as paid through FOSSBilling services.
        if (($payment['status'] ?? '') === 'COMPLETED') {
            $this->applySuccessfulPayment($tx, $invoice, (float)$tx->amount, (string)$payment['id']);
        } else {
            $tx->status = 'received';
        }

        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /**
     * Handle a subscription invoice.
     *
     * Subscription invoices may contain:
     * - a separate one-time setup fee line
     * - a recurring service line
     *
     * This method:
     * 1. Extracts setup fee + recurring portion from the invoice
     * 2. Charges setup fee as a one-time payment only if it is > 0.00
     * 3. Resolves the correct Square subscription plan variation using the
     *    product slug + billing period strategy
     * 4. Saves the card on file for the customer
     * 5. Creates the Square subscription
     * 6. Creates the matching FOSSBilling subscription record
     *
     * @param mixed  $api_admin  FOSSBilling admin API instance
     * @param object $tx         Transaction model
     * @param object $invoice    Invoice model
     * @param string $sourceId   Square tokenized card source ID
     * @param int    $gateway_id Gateway ID
     *
     * @throws Payment_Exception When setup fee or subscription creation fails
     */
    private function handleCreateSubscription($api_admin, $tx, $invoice, string $sourceId, int $gateway_id): void
    {
        $invoiceApi = $api_admin->invoice_get(['id' => $invoice->id]);

        // Extract the logical payment pieces from the invoice:
        // - setup_fee
        // - recurring_amount
        // - billing_key (monthly, yearly, 3month, etc.)
        // - generated Square SKU
        // - subscription period string for FOSSBilling
        $parts = $this->extractInvoiceChargeParts($invoiceApi);

        if ($parts['product_id'] <= 0) {
            throw new Payment_Exception('Unable to determine product for subscription invoice');
        }

        // --------------------------------------------------
        // Setup Fee Handling
        // --------------------------------------------------
        // Setup fees in FOSSBilling are separate one-time invoice lines.
        // Square subscriptions do not directly embed those one-time setup fees,
        // so we charge them first as a one-time payment if and only if the fee
        // is greater than 0.00.
        //
        // If setup fee is 0 / 0.00 / missing, we skip this step entirely.
        if ($parts['setup_fee'] > 0.00) {
            $setupResponse = $this->createSquarePayment(
                $sourceId,
                (int)round($parts['setup_fee'] * 100),
                (string)$invoice->currency,
                'Setup fee for invoice #' . (string)$invoice->serie_nr,
                (string)$invoice->id . '-setup'
            );

            $setupPayment = $setupResponse['payment'] ?? null;
            $setupErrors = $setupResponse['errors'] ?? null;

            if (!empty($setupErrors)) {
                $tx->status = 'error';
                $tx->error = json_encode($setupErrors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);

                throw new Payment_Exception('Square setup fee payment failed: ' . $tx->error);
            }

            if (!is_array($setupPayment) || ($setupPayment['status'] ?? '') !== 'COMPLETED') {
                throw new Payment_Exception('Square setup fee payment did not complete');
            }
        }

        // --------------------------------------------------
        // SKU-Based Variation Resolution
        // --------------------------------------------------
        // The runtime mapping strategy is:
        //
        //   FOSS product slug + billing key → deterministic Square SKU
        //
        // Example:
        //   hosting-basic + monthly → hosting-basic-monthly
        //
        // This allows exact matching against Square catalog item variation SKUs.
        $billingKey = $parts['billing_key'];
        $squareSku = $parts['square_sku'];

        $planVariationId = $this->resolvePlanVariationId(
            (int)$parts['product_id'],
            $billingKey,
            $squareSku
        );

        if ($planVariationId === '') {
            throw new Payment_Exception(
                'No Square plan variation found for product ' . $parts['product_id'] . ' [' . $squareSku . ']'
            );
        }

        // --------------------------------------------------
        // Customer + Card-on-File
        // --------------------------------------------------
        // Square subscriptions charge a saved card on file rather than a raw
        // browser token. Therefore we:
        // - find or create the Square customer
        // - save the tokenized card to that customer
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $customerId = $this->findOrCreateSquareCustomer($client);
        $cardId = $this->createCardOnFileFromNonce($customerId, $client, $sourceId);

        // Create the actual Square subscription using the resolved plan variation.
        $subscriptionResponse = $this->createSquareSubscription(
            $customerId,
            $cardId,
            $planVariationId
        );

        $subscription = $subscriptionResponse['subscription'] ?? null;
        $errors = $subscriptionResponse['errors'] ?? null;

        if (!empty($errors)) {
            $tx->status = 'error';
            $tx->error = json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Square subscription failed: ' . $tx->error);
        }

        if (!is_array($subscription) || empty($subscription['id'])) {
            $tx->status = 'error';
            $tx->error = 'Square subscription response missing subscription object';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Square subscription response invalid');
        }

        $subscriptionId = (string)$subscription['id'];
        $subscriptionStatus = (string)($subscription['status'] ?? 'ACTIVE');

        // Mirror the subscription into FOSSBilling so future billing/renewal logic
        // can stay consistent with internal records.
        $api_admin->invoice_subscription_create([
            'client_id'  => (int)$client->id,
            'gateway_id' => $gateway_id,
            'currency'   => (string)$invoice->currency,
            'sid'        => $subscriptionId,
            'status'     => strtolower($subscriptionStatus) === 'active' ? 'active' : 'pending',
            'period'     => $parts['period_string'],
            'amount'     => (float)$parts['recurring_amount'],
            'rel_type'   => 'invoice',
            'rel_id'     => $invoice->id,
        ]);

        $tx->txn_id = $subscriptionId;
        $tx->txn_status = $subscriptionStatus;
        $tx->s_id = $subscriptionId;
        $tx->s_period = $parts['period_string'];
        $tx->amount = (float)$parts['recurring_amount'];
        $tx->currency = (string)$invoice->currency;
        $tx->status = 'processed';
        $tx->error = '';
        $tx->updated_at = date('Y-m-d H:i:s');

        $this->di['db']->store($tx);
    }

    /**
     * Extract invoice charge components needed for subscription processing.
     *
     * Returns:
     * - product_id:         FOSSBilling product ID
     * - product_slug:       Base product slug
     * - setup_fee:          One-time setup amount
     * - recurring_amount:   Recurring amount only
     * - billing_key:        Deterministic billing token used for SKU generation
     * - period_string:      FOSSBilling subscription period string (e.g. 1M, 3M, 1Y)
     * - square_sku:         Generated Square SKU based on slug + billing key
     *
     * The billing key drives both export and runtime mapping.
     *
     * @param array $invoiceApi Invoice representation from FOSSBilling API
     *
     * @return array
     */
    private function extractInvoiceChargeParts(array $invoiceApi): array
    {
        $productId = 0;
        $setupFee = 0.00;
        $recurringAmount = 0.00;
        $billingKey = 'monthly';
        $periodString = '1M';
        $productSlug = '';

        foreach (($invoiceApi['lines'] ?? []) as $line) {
            if (!$productId && !empty($line['product_id'])) {
                $productId = (int)$line['product_id'];
            }

            if ($productSlug === '' && !empty($line['slug'])) {
                $productSlug = trim((string)$line['slug']);
            }

            $title = strtoupper((string)($line['title'] ?? ''));
            $period = strtoupper((string)($line['period'] ?? ''));
            $amount = (float)($line['total'] ?? $line['price'] ?? 0);

            // Setup fees are modeled as separate invoice lines. If a line title
            // includes SETUP, we treat that amount as non-recurring.
            if (str_contains($title, 'SETUP')) {
                $setupFee += $amount;
                continue;
            }

            if ($amount > 0) {
                $recurringAmount += $amount;
            }

            // Infer the billing key and FOSSBilling period string from invoice text.
            if ($period !== '' || $title !== '') {
                [$billingKey, $periodString] = $this->inferBillingKeyAndPeriod($period . ' ' . $title);
            }
        }

        // If the slug was not embedded in invoice lines, load it from the product.
        if ($productSlug === '' && $productId > 0) {
            try {
                $product = $this->di['db']->getExistingModelById('Product', $productId);
                $productSlug = trim((string)($product->slug ?? ''));
            } catch (\Throwable $e) {
                $productSlug = '';
            }
        }

        return [
            'product_id'        => $productId,
            'product_slug'      => $productSlug,
            'setup_fee'         => round($setupFee, 2),
            'recurring_amount'  => round($recurringAmount, 2),
            'billing_key'       => $billingKey,
            'period_string'     => $periodString,
            'square_sku'        => $this->buildSquareSku($productSlug, $billingKey),
        ];
    }

    /**
     * Infer the internal billing key and FOSSBilling period string.
     *
     * Supported billing keys:
     * - weekly
     * - monthly
     * - 3month
     * - 6month
     * - yearly
     * - 2year
     * - 3year
     *
     * @param string $text Combined invoice period/title text
     *
     * @return array{0:string,1:string}
     */
    private function inferBillingKeyAndPeriod(string $text): array
    {
        $text = strtoupper($text);

        if (str_contains($text, 'WEEK')) {
            return ['weekly', '1W'];
        }

        if (str_contains($text, '3 MONTH')) {
            return ['3month', '3M'];
        }

        if (str_contains($text, '6 MONTH')) {
            return ['6month', '6M'];
        }

        if (str_contains($text, '2 YEAR')) {
            return ['2year', '2Y'];
        }

        if (str_contains($text, '3 YEAR')) {
            return ['3year', '3Y'];
        }

        if (str_contains($text, 'YEAR')) {
            return ['yearly', '1Y'];
        }

        return ['monthly', '1M'];
    }

    /**
     * Build the deterministic Square SKU used across:
     * - export
     * - admin mapping UI
     * - runtime subscription lookup
     *
     * Example:
     *   hosting-basic + monthly => hosting-basic-monthly
     *
     * @param string $slug       Base FOSSBilling product slug
     * @param string $billingKey Billing token
     *
     * @return string
     */
    private function buildSquareSku(string $slug, string $billingKey): string
    {
        $slug = trim(strtolower($slug));
        $billingKey = trim(strtolower($billingKey));

        if ($slug === '') {
            return '';
        }

        return $slug . '-' . $billingKey;
    }
/**
     * Resolve the correct Square subscription plan variation ID.
     *
     * Resolution order:
     * 1. Ensure the local mapping table exists
     * 2. Try the locally stored mapping first
     * 3. If no mapping exists, discover it from Square using the generated SKU
     * 4. Save the discovered mapping locally for future use
     *
     * This makes subscription creation both deterministic and efficient:
     * - first run may discover mapping dynamically
     * - later runs use the DB cache immediately
     *
     * @param int    $productId  FOSSBilling product ID
     * @param string $billingKey Internal billing key (monthly, yearly, 3month, etc.)
     * @param string $squareSku  Generated exact Square SKU to look up
     *
     * @return string Square subscription plan variation ID, or empty string if not found
     */
    private function resolvePlanVariationId(int $productId, string $billingKey, string $squareSku): string
    {
        $this->ensureSquarePlanMapTable();

        $mapped = $this->getMappedPlanVariationId($productId, $billingKey);
        if ($mapped !== '') {
            return $mapped;
        }

        $discovered = $this->findSquarePlanVariationIdBySku($squareSku, $billingKey);
        if ($discovered !== '') {
            $this->saveMappedPlanVariationId($productId, $billingKey, $squareSku, $discovered);
        }

        return $discovered;
    }

    /**
     * Ensure the local Square mapping table exists.
     *
     * Table purpose:
     * - store the resolved relationship between a FOSSBilling product + billing key
     *   and the exact Square subscription plan variation ID
     * - keep a local record of the exact Square SKU used for discovery/debugging
     *
     * Design:
     * - product_id + billing_key is the logical unique key
     * - square_sku is stored for visibility and traceability
     * - last_discovered_at records when the mapping was auto-found from Square
     * - updated_at records when the record was last changed
     */
    private function ensureSquarePlanMapTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS square_product_plan_map (
                product_id INT UNSIGNED NOT NULL,
                billing_key VARCHAR(32) NOT NULL,
                square_sku VARCHAR(191) NOT NULL,
                square_plan_variation_id VARCHAR(64) NOT NULL,
                last_discovered_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (product_id, billing_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->di['db']->exec($sql);
    }

    /**
     * Return a stored plan variation ID from the local mapping table.
     *
     * This is the preferred lookup path during runtime because it avoids
     * repeated Square catalog scans once the mapping has already been established.
     *
     * @param int    $productId  FOSSBilling product ID
     * @param string $billingKey Billing key
     *
     * @return string
     */
    private function getMappedPlanVariationId(int $productId, string $billingKey): string
    {
        $row = $this->di['db']->getRow(
            "SELECT square_plan_variation_id
             FROM square_product_plan_map
             WHERE product_id = :product_id AND billing_key = :billing_key
             LIMIT 1",
            [
                ':product_id' => $productId,
                ':billing_key' => $billingKey,
            ]
        );

        return (string)($row['square_plan_variation_id'] ?? '');
    }

    /**
     * Save or update a plan variation mapping in the local table.
     *
     * This is used both for:
     * - automatic discovery results
     * - manual overrides from the admin UI module
     *
     * @param int    $productId   FOSSBilling product ID
     * @param string $billingKey  Internal billing key
     * @param string $squareSku   Exact Square SKU used for the mapping
     * @param string $variationId Square subscription plan variation ID
     */
    private function saveMappedPlanVariationId(
        int $productId,
        string $billingKey,
        string $squareSku,
        string $variationId
    ): void {
        $exists = $this->di['db']->getRow(
            "SELECT product_id
             FROM square_product_plan_map
             WHERE product_id = :product_id AND billing_key = :billing_key
             LIMIT 1",
            [
                ':product_id' => $productId,
                ':billing_key' => $billingKey,
            ]
        );

        if ($exists) {
            $sql = "
                UPDATE square_product_plan_map
                SET square_sku = :square_sku,
                    square_plan_variation_id = :variation_id,
                    last_discovered_at = :last_discovered_at,
                    updated_at = :updated_at
                WHERE product_id = :product_id
                  AND billing_key = :billing_key
            ";
        } else {
            $sql = "
                INSERT INTO square_product_plan_map
                    (product_id, billing_key, square_sku, square_plan_variation_id, last_discovered_at, updated_at)
                VALUES
                    (:product_id, :billing_key, :square_sku, :variation_id, :last_discovered_at, :updated_at)
            ";
        }

        $params = [
            ':product_id' => $productId,
            ':billing_key' => $billingKey,
            ':square_sku' => $squareSku,
            ':variation_id' => $variationId,
            ':last_discovered_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->di['db']->exec($sql, $params);
    }

    /**
     * Discover a Square subscription plan variation ID by exact generated SKU.
     *
     * Discovery strategy:
     * 1. Load relevant Square catalog objects:
     *    - ITEM_VARIATION
     *    - SUBSCRIPTION_PLAN
     *    - SUBSCRIPTION_PLAN_VARIATION
     * 2. Find the Square item variation with the exact target SKU
     * 3. Get the parent catalog item ID from that item variation
     * 4. Find subscription plans that apply to that item
     * 5. Find the subscription plan variation under those plans that matches
     *    the expected billing cadence
     *
     * Why this is needed:
     * - FOSSBilling only gives us the main product slug
     * - we convert slug + billing key into a deterministic Square SKU
     * - this lets us bridge from FOSS product → Square item variation →
     *   Square subscription plan variation
     *
     * @param string $squareSku  Exact generated SKU, such as hosting-basic-monthly
     * @param string $billingKey Internal billing key
     *
     * @return string Matching Square subscription plan variation ID, or empty string
     */
    private function findSquarePlanVariationIdBySku(string $squareSku, string $billingKey): string
    {
        if ($squareSku === '') {
            return '';
        }

        $response = $this->squareGet(
            $this->getApiBaseUrl() . '/v2/catalog/list?types=ITEM_VARIATION,SUBSCRIPTION_PLAN,SUBSCRIPTION_PLAN_VARIATION'
        );

        $objects = $response['objects'] ?? [];
        if (!is_array($objects) || !$objects) {
            return '';
        }

        $itemId = '';
        $candidatePlanIds = [];

        // --------------------------------------------------
        // Step 1: Find the Square item variation with the exact SKU
        // --------------------------------------------------
        // Because the generated SKU is deterministic, we avoid weak name matching
        // and instead use an exact SKU match.
        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') !== 'ITEM_VARIATION') {
                continue;
            }

            $sku = trim((string)($obj['item_variation_data']['sku'] ?? ''));
            if (strcasecmp($sku, $squareSku) !== 0) {
                continue;
            }

            $itemId = (string)($obj['item_variation_data']['item_id'] ?? '');
            break;
        }

        if ($itemId === '') {
            return '';
        }

        // --------------------------------------------------
        // Step 2: Find subscription plans linked to that item
        // --------------------------------------------------
        // A Square subscription plan can either:
        // - explicitly list eligible_item_ids
        // - or apply to all items
        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') !== 'SUBSCRIPTION_PLAN') {
                continue;
            }

            $planId = (string)($obj['id'] ?? '');
            $eligibleItems = $obj['subscription_plan_data']['eligible_item_ids'] ?? [];
            $allItems = (bool)($obj['subscription_plan_data']['all_items'] ?? false);

            if (
                $planId !== '' &&
                ($allItems || (is_array($eligibleItems) && in_array($itemId, $eligibleItems, true)))
            ) {
                $candidatePlanIds[] = $planId;
            }
        }

        if (!$candidatePlanIds) {
            return '';
        }

        // --------------------------------------------------
        // Step 3: Find the matching subscription plan variation
        // --------------------------------------------------
        // We use the internal billing key to decide which Square cadence(s) are
        // acceptable for this lookup.
        $allowedCadences = $this->getAllowedCadencesForBillingKey($billingKey);

        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') !== 'SUBSCRIPTION_PLAN_VARIATION') {
                continue;
            }

            $variationId = (string)($obj['id'] ?? '');
            $planId = (string)($obj['subscription_plan_variation_data']['subscription_plan_id'] ?? '');
            $phaseCadence = strtoupper(
                (string)($obj['subscription_plan_variation_data']['phases'][0]['cadence'] ?? '')
            );

            if ($variationId === '' || $planId === '') {
                continue;
            }

            if (!in_array($planId, $candidatePlanIds, true)) {
                continue;
            }

            if (in_array($phaseCadence, $allowedCadences, true)) {
                return $variationId;
            }
        }

        return '';
    }

    /**
     * Return allowed Square cadence values for a given internal billing key.
     *
     * This exists because Square cadence labels and FOSSBilling billing periods
     * are not always named exactly the same way.
     *
     * Examples:
     * - monthly  → MONTHLY
     * - yearly   → ANNUAL / YEARLY
     * - 3month   → QUARTERLY / EVERY_THREE_MONTHS
     *
     * @param string $billingKey Internal billing key
     *
     * @return array<int, string>
     */
    private function getAllowedCadencesForBillingKey(string $billingKey): array
    {
        return match (strtolower($billingKey)) {
            'weekly'  => ['WEEKLY'],
            'monthly' => ['MONTHLY'],
            '3month'  => ['EVERY_THREE_MONTHS', 'QUARTERLY', 'THREE_MONTHS'],
            '6month'  => ['EVERY_SIX_MONTHS', 'SEMIANNUALLY', 'BIANNUAL', 'SIX_MONTHS'],
            'yearly'  => ['ANNUAL', 'YEARLY'],
            '2year'   => ['EVERY_TWO_YEARS', 'TWO_YEARS'],
            '3year'   => ['EVERY_THREE_YEARS', 'THREE_YEARS'],
            default   => ['MONTHLY'],
        };
    }
/**
     * Find an existing Square customer by email, or create a new one.
     *
     * Why this exists:
     * - Square subscriptions must be associated with a Square customer
     * - the saved card on file is attached to that customer
     *
     * Lookup strategy:
     * 1. If the client has an email address, search Square for an existing customer
     * 2. If found, reuse that customer
     * 3. Otherwise, create a new customer in Square
     *
     * @param object $client FOSSBilling client model
     *
     * @return string Square customer ID
     *
     * @throws Payment_Exception When Square does not return a valid customer ID
     */
    private function findOrCreateSquareCustomer($client): string
    {
        $email = trim((string)($client->email ?? ''));

        if ($email !== '') {
            $searchResponse = $this->squarePost(
                $this->getApiBaseUrl() . '/v2/customers/search',
                [
                    'query' => [
                        'filter' => [
                            'email_address' => [
                                'exact' => $email,
                            ],
                        ],
                    ],
                ]
            );

            if (!empty($searchResponse['customers'][0]['id'])) {
                return (string)$searchResponse['customers'][0]['id'];
            }
        }

        $payload = array_filter([
            'given_name'    => (string)($client->first_name ?? ''),
            'family_name'   => (string)($client->last_name ?? ''),
            'email_address' => $email !== '' ? $email : null,
            'phone_number'  => trim((string)($client->phone_cc ?? '') . (string)($client->phone ?? '')),
            'reference_id'  => 'fossbilling-client-' . (string)$client->id,
            'note'          => 'Created by FOSSBilling Square adapter',
        ], static fn($v) => $v !== null && $v !== '');

        $response = $this->squarePost($this->getApiBaseUrl() . '/v2/customers', $payload);

        if (empty($response['customer']['id'])) {
            throw new Payment_Exception('Could not create Square customer');
        }

        return (string)$response['customer']['id'];
    }

    /**
     * Save a tokenized Square card to the customer's card-on-file list.
     *
     * Why this exists:
     * - Square subscriptions charge a stored card on file
     * - the browser token (source_id) is single-use and cannot be used as the
     *   recurring billing source directly
     *
     * Sandbox note:
     * - Square sandbox card save flows often require postal code 94103 for testing
     *
     * @param string $customerId Square customer ID
     * @param object $client     FOSSBilling client model
     * @param string $sourceId   Tokenized browser card source
     *
     * @return string Saved Square card ID
     *
     * @throws Payment_Exception When Square does not return a valid card ID
     */
    private function createCardOnFileFromNonce(string $customerId, $client, string $sourceId): string
    {
        $cardholderName = trim(
            (string)($client->first_name ?? '') . ' ' . (string)($client->last_name ?? '')
        );

        $payload = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'source_id'       => $sourceId,
            'card' => [
                'customer_id'     => $customerId,
                'cardholder_name' => $cardholderName,
                'reference_id'    => 'fossbilling-client-' . (string)$client->id,
                'billing_address' => [
                    'postal_code' => $this->isTestMode() ? '94103' : (string)($client->zip ?? ''),
                    'country'     => 'US',
                ],
            ],
        ];

        $response = $this->squarePost($this->getApiBaseUrl() . '/v2/cards', $payload);

        if (!empty($response['errors'])) {
            throw new Payment_Exception(
                'Square create card failed: ' .
                json_encode($response['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        if (empty($response['card']['id'])) {
            throw new Payment_Exception('Square create card response missing card ID');
        }

        return (string)$response['card']['id'];
    }

    /**
     * Create a Square subscription.
     *
     * Inputs required by Square:
     * - location_id
     * - customer_id
     * - plan_variation_id
     * - card_id
     *
     * Note:
     * - If your Square plan variation uses RELATIVE pricing, Square may require
     *   additional phase/order-template data beyond this basic payload.
     *
     * @param string $customerId      Square customer ID
     * @param string $cardId          Square saved card ID
     * @param string $planVariationId Square subscription plan variation ID
     *
     * @return array Raw Square API response
     */
    private function createSquareSubscription(string $customerId, string $cardId, string $planVariationId): array
    {
        $payload = [
            'idempotency_key'   => bin2hex(random_bytes(16)),
            'location_id'       => $this->getLocationId(),
            'customer_id'       => $customerId,
            'plan_variation_id' => $planVariationId,
            'card_id'           => $cardId,
        ];

        return $this->squarePost($this->getApiBaseUrl() . '/v2/subscriptions', $payload);
    }

    /**
     * Create a one-time Square payment.
     *
     * This is used for:
     * - normal one-time invoice payments
     * - optional setup fee charges before creating subscriptions
     *
     * @param string $sourceId     Square tokenized card source
     * @param int    $amountCents  Amount in the smallest currency unit
     * @param string $currency     ISO currency code
     * @param string $note         Human-readable note stored in Square
     * @param string $referenceId  Reference stored in Square for reconciliation
     *
     * @return array Raw Square API response
     */
    private function createSquarePayment(
        string $sourceId,
        int $amountCents,
        string $currency,
        string $note,
        string $referenceId
    ): array {
        $payload = [
            'source_id' => $sourceId,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'amount_money' => [
                'amount' => $amountCents,
                'currency' => strtoupper($currency),
            ],
            'location_id' => $this->getLocationId(),
            'reference_id' => $referenceId,
            'note' => $note,
        ];

        return $this->squarePost($this->getApiBaseUrl() . '/v2/payments', $payload);
    }

    /**
     * Process a Square webhook callback.
     *
     * Supported event categories:
     * - subscription.created / subscription.updated
     * - payment.created / payment.updated / invoice.payment_made
     *
     * Why this exists:
     * - keep FOSSBilling subscription status aligned with Square
     * - confirm and apply one-time payments asynchronously from Square events
     *
     * @param mixed  $api_admin       FOSSBilling admin API instance
     * @param object $tx              Transaction model
     * @param string $rawBody         Raw webhook JSON
     * @param string $headerSignature Square webhook signature header
     * @param int    $gateway_id      Payment gateway ID
     *
     * @throws Payment_Exception When signature validation or payload validation fails
     */
    private function handleWebhook($api_admin, $tx, string $rawBody, string $headerSignature, int $gateway_id): void
    {
        $signatureKey = $this->getWebhookSignatureKey();
        $notificationUrl = (string)($this->config['notify_url'] ?? '');

        if ($signatureKey === '' || $notificationUrl === '') {
            throw new Payment_Exception('Square webhook config is incomplete');
        }

        if (!$this->isValidWebhookSignature($notificationUrl, $rawBody, $headerSignature, $signatureKey)) {
            throw new Payment_Exception('Square webhook signature validation failed');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new Payment_Exception('Square webhook payload is invalid JSON');
        }

        $eventType = (string)($payload['type'] ?? '');
        if ($eventType === '') {
            return;
        }

        // --------------------------------------------------
        // Subscription status synchronization
        // --------------------------------------------------
        // When Square updates a subscription status, reflect it in the local
        // FOSSBilling subscription record where possible.
        if (in_array($eventType, ['subscription.created', 'subscription.updated'], true)) {
            $subscriptionId = (string)($payload['data']['object']['subscription']['id'] ?? '');
            $subscriptionStatus = (string)($payload['data']['object']['subscription']['status'] ?? '');

            if ($subscriptionId !== '') {
                try {
                    $subscription = $api_admin->invoice_subscription_get(['sid' => $subscriptionId]);

                    if (!empty($subscription['id'])) {
                        $mappedStatus = 'active';
                        $normalized = strtoupper($subscriptionStatus);

                        if (in_array($normalized, ['CANCELED', 'DEACTIVATED', 'PAUSED'], true)) {
                            $mappedStatus = 'canceled';
                        } elseif ($normalized === 'PENDING') {
                            $mappedStatus = 'pending';
                        }

                        $api_admin->invoice_subscription_update([
                            'id'     => $subscription['id'],
                            'status' => $mappedStatus,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // It is valid for a webhook to arrive before the local side
                    // has fully stored the subscription. In that case we ignore
                    // the sync attempt rather than breaking the webhook flow.
                }
            }

            return;
        }

        // If the webhook is unrelated to payments or subscriptions we simply
        // ignore it.
        if (!in_array($eventType, ['payment.created', 'payment.updated', 'invoice.payment_made'], true)) {
            return;
        }

        $paymentId = (string)($payload['data']['object']['payment']['id'] ?? '');
        if ($paymentId === '') {
            return;
        }

        // --------------------------------------------------
        // Payment verification
        // --------------------------------------------------
        // We re-fetch the payment from Square using the payment ID rather than
        // trusting the minimal webhook payload blindly.
        $verified = $this->retrieveSquarePayment($paymentId);
        $payment = $verified['payment'] ?? null;

        if (!is_array($payment) || empty($payment['id'])) {
            throw new Payment_Exception('Square webhook verification failed');
        }

        // Attempt to recover the invoice ID from the existing transaction or
        // from the Square reference ID.
        $invoiceId =
            ($tx->invoice_id ?? null)
            ?: (int)($payment['reference_id'] ?? 0)
            ?: (int)($payload['data']['object']['payment']['reference_id'] ?? 0)
            ?: 0;

        if ($invoiceId > 0) {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);

            $tx->invoice_id = $invoice->id;
            $tx->txn_id = (string)$payment['id'];
            $tx->txn_status = (string)($payment['status'] ?? 'UNKNOWN');
            $tx->amount = ((float)($payment['amount_money']['amount'] ?? 0)) / 100;
            $tx->currency = (string)($payment['amount_money']['currency'] ?? $invoice->currency);

            if (($payment['status'] ?? '') === 'COMPLETED') {
                $this->applySuccessfulPayment($tx, $invoice, (float)$tx->amount, (string)$payment['id']);
            } elseif (in_array(($payment['status'] ?? ''), ['FAILED', 'CANCELED'], true)) {
                $tx->status = 'error';
                $tx->error = 'Square webhook reported payment status: ' . (string)$payment['status'];
            } else {
                $tx->status = 'received';
            }

            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
        }
    }

    /**
     * Validate a Square webhook signature.
     *
     * Square signs:
     *   notification_url + raw_body
     *
     * using HMAC SHA-256 and base64 encoding.
     *
     * @param string $notificationUrl Full notify URL configured in Square
     * @param string $rawBody         Raw webhook request body
     * @param string $headerSignature Signature from request header
     * @param string $signatureKey    Square webhook signature key
     *
     * @return bool
     */
    private function isValidWebhookSignature(
        string $notificationUrl,
        string $rawBody,
        string $headerSignature,
        string $signatureKey
    ): bool {
        $expected = base64_encode(
            hash_hmac('sha256', $notificationUrl . $rawBody, $signatureKey, true)
        );

        return hash_equals($expected, trim($headerSignature));
    }

    /**
     * Retrieve a payment directly from Square by payment ID.
     *
     * This is used during webhook processing to verify payment state with
     * a fresh API call.
     *
     * @param string $paymentId Square payment ID
     *
     * @return array
     */
    private function retrieveSquarePayment(string $paymentId): array
    {
        return $this->squareGet($this->getApiBaseUrl() . '/v2/payments/' . rawurlencode($paymentId));
    }

    /**
     * Execute a Square GET request and decode JSON response.
     *
     * @param string $endpoint Full Square API URL
     *
     * @return array
     *
     * @throws Payment_Exception On network or invalid JSON errors
     */
    private function squareGet(string $endpoint): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->getAccessToken(),
                'Accept: application/json',
                'Square-Version: 2026-05-20',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Payment_Exception('Square cURL error: ' . $error);
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new Payment_Exception('Square returned invalid JSON. HTTP ' . $httpCode);
        }

        return $decoded;
    }

    /**
     * Execute a Square POST request and decode JSON response.
     *
     * @param string $endpoint Full Square API URL
     * @param array  $payload  JSON payload to send
     *
     * @return array
     *
     * @throws Payment_Exception On network or invalid JSON errors
     */
    private function squarePost(string $endpoint, array $payload): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->getAccessToken(),
                'Content-Type: application/json',
                'Accept: application/json',
                'Square-Version: 2026-05-20',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Payment_Exception('Square cURL error: ' . $error);
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new Payment_Exception('Square returned invalid JSON. HTTP ' . $httpCode);
        }

        return $decoded;
    }

    /**
     * Apply a successful payment to the client/invoice inside FOSSBilling.
     *
     * This method:
     * - adds the received payment as client funds/transaction record
     * - attempts to pay the invoice using credits unless it is a pure deposit invoice
     * - marks the local transaction status as processed
     *
     * @param object $tx              Transaction model
     * @param object $invoice         Invoice model
     * @param float  $amount          Paid amount in invoice currency
     * @param string $squarePaymentId Square payment ID for traceability
     */
    private function applySuccessfulPayment($tx, $invoice, float $amount, string $squarePaymentId): void
    {
        $clientService = $this->di['mod_service']('Client');
        $invoiceService = $this->di['mod_service']('Invoice');
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);

        $clientService->addFunds(
            $client,
            $amount,
            'Square payment ' . $squarePaymentId,
            [
                'amount' => $amount,
                'description' => 'Square transaction ' . $squarePaymentId,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ]
        );

        if (!$invoiceService->isInvoiceTypeDeposit($invoice)) {
            $invoiceService->payInvoiceWithCredits($invoice);
        }

        $tx->status = 'processed';
    }
}	