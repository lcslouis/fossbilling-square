<?php

declare(strict_types=1);

/**
 * Square payment adapter for FOSSBilling
 *
 * This version includes:
 * - toggleable debug logging
 * - separate log file support
 * - helpers for recurring plan variation detection
 * - helpers for listing Square objects for inspection
 *
 */

class Payment_Adapter_Square extends Payment_AdapterAbstract
{
    /**
     * Optional DI container.
     */
    protected ?Pimple\Container $di = null;

    /**
     * Square API version header.
     */
    protected string $squareApiVersion = '2025-10-16';

    /**
     * Toggle debug logging on/off.
     *
     * You can also override this via adapter config:
     *   'debug_enabled' => true
     */
    protected bool $debugEnabled = false;

    /**
     * Custom debug log file path.
     *
     * You can also override this via adapter config:
     *   'debug_log_file' => '/full/path/to/your/logfile.log'
     */
    protected string $debugLogFile = '';

    /**
     * Set DI container.
     */
    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    /**
     * Get DI container.
     */
    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    /**
     * Construct adapter and initialize debug settings.
     *
     * @param array $_config
     */
    public function __construct(protected $_config)
    {
        

        // Debug toggle from config
        if (isset($_config['debug_enabled'])) {
            $this->debugEnabled = (bool)$_config['debug_enabled'];
        }

        // Separate log file from config
        if (!empty($_config['debug_log_file'])) {
            $this->debugLogFile = (string)$_config['debug_log_file'];
        }
    }

    /**
     * Write a debug message either to a separate file or php_error.log.
     *
     * @param mixed $message
     * @return void
     */
    protected function debugLog(mixed $message): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $prefix = '[SquareAdapter] ';
        $line = is_array($message) || is_object($message)
            ? $prefix . print_r($message, true)
            : $prefix . (string)$message;

        if ($this->debugLogFile !== '') {
            @file_put_contents(
                $this->debugLogFile,
                '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $line . PHP_EOL,
                FILE_APPEND
            );
            return;
        }

        error_log($line);
    }

    /**
     * Return adapter configuration schema.
     */
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'description' => 'Accept card payments and subscriptions with Square.',
            'logo' => [
                'logo'   => 'square.png',
                'height' => '50px',
                'width'  => '200px',
            ],
            'form' => [
                'application_id' => [
                    'text',
                    [
                        'label' => 'Application ID',
                        'required' => true,
                    ],
                ],
                'access_token' => [
                    'password',
                    [
                        'label' => 'Access Token',
                        'required' => true,
                    ],
                ],
                'location_id' => [
                    'text',
                    [
                        'label' => 'Location ID',
                        'required' => true,
                    ],
                ],
                'environment' => [
                    'select',
                    [
                        'label' => 'Environment',
                        'multiOptions' => [
                            'production' => 'Production',
                            'sandbox'    => 'Sandbox',
                        ],
                    ],
                ],
                'webhook_signature_key' => [
                    'password',
                    [
                        'label' => 'Webhook Signature Key',
                        'required' => false,
                    ],
                ],
                'debug_enabled' => [
                    'select',
                    [
                        'label' => 'Enable Debug Logging',
                        'multiOptions' => [
                            0 => 'No',
                            1 => 'Yes',
                        ],
                    ],
                ],
                'debug_log_file' => [
                    'text',
                    [
                        'label' => 'Debug Log File Path',
                        'required' => false,
                        'description' => 'Optional full server path for a dedicated Square debug log file.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Adapter title.
     */
    public function getTitle(): string
    {
        return 'Square';
    }

    /**
     * Return base API URL for the active environment.
     */
    protected function getApiBaseUrl(): string
    {
        $environment = (string)($this->getParam('environment') ?: 'production');

        if ($environment === 'sandbox') {
            return 'https://connect.squareupsandbox.com';
        }

        return 'https://connect.squareup.com';
    }

    /**
     * Return configured access token.
     */
    protected function getAccessToken(): string
    {
        return (string)$this->getParam('access_token');
    }

    /**
     * Return configured application ID.
     */
    protected function getApplicationId(): string
    {
        return (string)$this->getParam('application_id');
    }

    /**
     * Return configured location ID.
     */
    protected function getLocationId(): string
    {
        return (string)$this->getParam('location_id');
    }
/**
     * Return adapter logo metadata.
     */
    public function getLogo(): array
    {
        return [
            'logo'   => 'square.png',
            'height' => '50px',
            'width'  => '200px',
        ];
    }

    /**
     * Confirm whether recurring subscriptions are supported.
     */
    public function supportsSubscriptions(): bool
    {
        return true;
    }

    /**
     * Confirm whether one-time payments are supported.
     */
    public function supportsOneTimePayments(): bool
    {
        return true;
    }

    /**
     * Perform a GET request to the Square API.
     *
     * @param string $url
     * @return array
     */
    protected function squareGet(string $url): array
    {
        $this->debugLog('squareGet() URL: ' . $url);

        return $this->squareRequest('GET', $url);
    }

    /**
     * Perform a POST request to the Square API.
     *
     * @param string $url
     * @param array $payload
     * @return array
     */
    protected function squarePost(string $url, array $payload = []): array
    {
        $this->debugLog('squarePost() URL: ' . $url);
        $this->debugLog('squarePost() Payload:');
        $this->debugLog($payload);

        return $this->squareRequest('POST', $url, $payload);
    }

    /**
     * Perform a generic request to the Square API.
     *
     * @param string $method
     * @param string $url
     * @param array|null $payload
     * @return array
     */
    protected function squareRequest(string $method, string $url, ?array $payload = null): array
    {
        $this->debugLog("squareRequest() START method={$method} url={$url}");

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Square-Version: ' . $this->squareApiVersion,
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        $this->debugLog("squareRequest() HTTP code: {$httpCode}");

        if ($responseBody !== false) {
            $this->debugLog('squareRequest() raw response body:');
            $this->debugLog($responseBody);
        }

        if ($responseBody === false || $curlError) {
            $this->debugLog('squareRequest() CURL error: ' . $curlError);
            throw new Exception('Square API request failed: ' . $curlError);
        }

        $decoded = json_decode((string)$responseBody, true);

        if (!is_array($decoded)) {
            $this->debugLog('squareRequest() invalid JSON response');
            throw new Exception('Square API returned an invalid response.');
        }

        if ($httpCode >= 400) {
            $message = 'Square API error.';

            if (isset($decoded['errors'][0]['detail'])) {
                $message = (string)$decoded['errors'][0]['detail'];
            } elseif (isset($decoded['errors'][0]['code'])) {
                $message = (string)$decoded['errors'][0]['code'];
            }

            $this->debugLog('squareRequest() decoded error response:');
            $this->debugLog($decoded);

            throw new Exception($message);
        }

        $this->debugLog('squareRequest() decoded response array:');
        $this->debugLog($decoded);

        return $decoded;
    }

    /**
     * Normalize a decimal amount into Square minor units.
     *
     * @param float $amount
     * @return int
     */
    protected function toMinorUnits(float $amount): int
    {
        return (int)round($amount * 100);
    }

    /**
     * Return a stable unique idempotency key.
     *
     * @param string $seed
     * @return string
     */
    protected function generateIdempotencyKey(string $seed): string
    {
        return substr(
            hash('sha256', $seed . '|' . microtime(true) . '|' . random_int(1000, 999999)),
            0,
            45
        );
    }
/**
     * Render the payment form shown to the client.
     *
     * For one-time payments, this renders the Square card form.
     * For recurring payments, this still begins with card capture because
     * Square needs a card on file before a subscription can be created.
     *
     * @param array $data
     * @return string
     */
    public function getHtml(array $data): string
    {
        $this->debugLog('getHtml() called');
        $this->debugLog($data);

        $invoiceData = $this->getInvoiceData($data);
        $isSubscription = $this->isSubscriptionInvoice($data);

        $this->debugLog('getHtml() invoiceData:');
        $this->debugLog($invoiceData);
        $this->debugLog('getHtml() isSubscription: ' . ($isSubscription ? 'true' : 'false'));

        $html = [];
        $html[] = '<div id="square-payment-wrapper">';
        $html[] = '  <div id="square-card-container"></div>';
        $html[] = '  <button id="square-card-button" type="button">Pay with Square</button>';
        $html[] = '  <div id="square-payment-status" style="margin-top:10px;"></div>';
        $html[] = '</div>';

        $squareConfig = [
            'applicationId' => $this->getApplicationId(),
            'locationId'    => $this->getLocationId(),
            'environment'   => (string)($this->getParam('environment') ?: 'production'),
            'invoiceId'     => $invoiceData['id'],
            'amount'        => number_format($invoiceData['total'], 2, '.', ''),
            'isSubscription'=> $isSubscription,
        ];

        $this->debugLog('getHtml() squareConfig:');
        $this->debugLog($squareConfig);

        $html[] = '<script>';
        $html[] = 'window.squareConfig = ' . json_encode($squareConfig) . ';';
        $html[] = '</script>';

        $squareJsUrl = $squareConfig['environment'] === 'sandbox'
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';

        $html[] = '<script src="' . $squareJsUrl . '"></script>';

        return implode("\n", $html);
    }

    /**
     * Determine whether the invoice should be treated as a recurring subscription.
     *
     * @param array $data
     * @return bool
     */
    protected function isSubscriptionInvoice(array $data): bool
    {
        $invoice = $data['invoice'] ?? null;
        if (!$invoice) {
            $this->debugLog('isSubscriptionInvoice() => false (missing invoice)');
            return false;
        }

        $items = $invoice['items'] ?? [];
        if (!is_array($items) || !$items) {
            $this->debugLog('isSubscriptionInvoice() => false (missing items)');
            return false;
        }

        foreach ($items as $item) {
            if (!empty($item['rel_type']) && $item['rel_type'] === 'invoice_subscription') {
                $this->debugLog('isSubscriptionInvoice() => true (rel_type invoice_subscription)');
                return true;
            }

            if (!empty($item['type']) && stripos((string)$item['type'], 'subscription') !== false) {
                $this->debugLog('isSubscriptionInvoice() => true (type contains subscription)');
                return true;
            }
        }

        $this->debugLog('isSubscriptionInvoice() => false (no subscription markers)');
        return false;
    }

    /**
     * Return normalized invoice data needed by payment processing.
     *
     * @param array $data
     * @return array
     */
    protected function getInvoiceData(array $data): array
    {
        $invoice = $data['invoice'] ?? null;

        if (!$invoice) {
            $this->debugLog('getInvoiceData() missing invoice');
            throw new Exception('Invoice data is missing.');
        }

        $invoiceId = (int)($invoice['id'] ?? 0);
        $currency = strtoupper((string)($invoice['currency'] ?? 'USD'));
        $total = (float)($invoice['total'] ?? 0);

        $invoiceData = [
            'id' => $invoiceId,
            'currency' => $currency,
            'total' => $total,
            'amount_minor' => $this->toMinorUnits($total),
        ];

        $this->debugLog('getInvoiceData() normalized result:');
        $this->debugLog($invoiceData);

        return $invoiceData;
    }

    /**
     * Main transaction entrypoint.
     *
     * @param array $data
     * @return array
     */
    public function processTransaction(array $data): array
    {
        $this->debugLog('processTransaction() called');
        $this->debugLog($data);

        $invoiceData = $this->getInvoiceData($data);
        $sourceToken = (string)($data['source_token'] ?? '');

        if ($sourceToken === '') {
            $this->debugLog('processTransaction() missing source_token');
            throw new Exception('Square source token is missing.');
        }

        if ($this->isSubscriptionInvoice($data)) {
            $this->debugLog('processTransaction() routing to processSubscriptionTransaction()');
            return $this->processSubscriptionTransaction($data, $invoiceData, $sourceToken);
        }

        $this->debugLog('processTransaction() routing to processOneTimeTransaction()');
        return $this->processOneTimeTransaction($data, $invoiceData, $sourceToken);
    }	
	/**
     * Process a one-time Square payment.
     *
     * @param array  $data
     * @param array  $invoiceData
     * @param string $sourceToken
     * @return array
     */
    protected function processOneTimeTransaction(array $data, array $invoiceData, string $sourceToken): array
    {
        $this->debugLog('processOneTimeTransaction() called');
        $this->debugLog([
            'invoice_id' => $invoiceData['id'] ?? null,
            'amount_minor' => $invoiceData['amount_minor'] ?? null,
            'currency' => $invoiceData['currency'] ?? null,
        ]);

        $payload = [
            'idempotency_key' => $this->generateIdempotencyKey('pay_' . $invoiceData['id']),
            'source_id'       => $sourceToken,
            'location_id'     => $this->getLocationId(),
            'amount_money'    => [
                'amount'   => $invoiceData['amount_minor'],
                'currency' => $invoiceData['currency'],
            ],
            'autocomplete'    => true,
            'note'            => 'FOSSBilling Invoice #' . $invoiceData['id'],
        ];

        $response = $this->squarePost(
            $this->getApiBaseUrl() . '/v2/payments',
            $payload
        );

        $this->debugLog('processOneTimeTransaction() Square response:');
        $this->debugLog($response);

        return [
            'status'         => 'processed',
            'txn_id'         => (string)($response['payment']['id'] ?? ''),
            'amount'         => $invoiceData['total'],
            'raw'            => $response,
            'invoice_id'     => $invoiceData['id'],
            'gateway_status' => (string)($response['payment']['status'] ?? ''),
        ];
    }

    /**
     * Process a recurring subscription transaction.
     *
     * Flow:
     * 1. Charge one-time setup fee if needed
     * 2. Ensure Square customer exists
     * 3. Store card on file
     * 4. Resolve mapped subscription plan variation ID
     * 5. Create subscription
     *
     * @param array  $data
     * @param array  $invoiceData
     * @param string $sourceToken
     * @return array
     */
    protected function processSubscriptionTransaction(array $data, array $invoiceData, string $sourceToken): array
    {
        $this->debugLog('processSubscriptionTransaction() called');

        $subscriptionMeta = $this->extractSubscriptionMeta($data);

        $this->debugLog('processSubscriptionTransaction() extracted subscription meta:');
        $this->debugLog($subscriptionMeta);

        if ((float)$subscriptionMeta['setup_fee'] > 0) {
            $this->debugLog('processSubscriptionTransaction() charging setup fee: ' . $subscriptionMeta['setup_fee']);

            $this->chargeSetupFee(
                $invoiceData,
                $sourceToken,
                (float)$subscriptionMeta['setup_fee']
            );
        } else {
            $this->debugLog('processSubscriptionTransaction() no setup fee');
        }

        $customerId = $this->ensureSquareCustomer($data);
        $this->debugLog('processSubscriptionTransaction() customerId: ' . $customerId);

        $cardId = $this->storeCardOnFile($customerId, $sourceToken);
        $this->debugLog('processSubscriptionTransaction() cardId: ' . $cardId);

        $planVariationId = $this->resolveMappedPlanVariationId(
            (int)$subscriptionMeta['product_id'],
            (string)$subscriptionMeta['billing_key'],
            (string)$subscriptionMeta['sku']
        );

        $this->debugLog('processSubscriptionTransaction() mapped planVariationId: ' . ($planVariationId ?: '[empty]'));

        if ($planVariationId === '') {
            throw new Exception('No Square subscription plan variation ID is mapped for this product/billing period.');
        }

        $subscription = $this->createSubscription(
            $customerId,
            $cardId,
            $planVariationId,
            $subscriptionMeta
        );

        $this->debugLog('processSubscriptionTransaction() Square subscription response:');
        $this->debugLog($subscription);

        return [
            'status'             => 'processed',
            'txn_id'             => (string)($subscription['subscription']['id'] ?? ''),
            'amount'             => $invoiceData['total'],
            'invoice_id'         => $invoiceData['id'],
            'gateway_status'     => (string)($subscription['subscription']['status'] ?? ''),
            'subscription_id'    => (string)($subscription['subscription']['id'] ?? ''),
            'plan_variation_id'  => $planVariationId,
            'raw'                => $subscription,
        ];
    }

    /**
     * Charge setup fee as a one-time payment.
     *
     * @param array  $invoiceData
     * @param string $sourceToken
     * @param float  $setupFee
     * @return array
     */
    protected function chargeSetupFee(array $invoiceData, string $sourceToken, float $setupFee): array
    {
        $this->debugLog('chargeSetupFee() called');
        $this->debugLog([
            'invoice_id' => $invoiceData['id'] ?? null,
            'setup_fee' => $setupFee,
            'currency' => $invoiceData['currency'] ?? null,
        ]);

        $payload = [
            'idempotency_key' => $this->generateIdempotencyKey('setup_' . $invoiceData['id']),
            'source_id'       => $sourceToken,
            'location_id'     => $this->getLocationId(),
            'amount_money'    => [
                'amount'   => $this->toMinorUnits($setupFee),
                'currency' => $invoiceData['currency'],
            ],
            'autocomplete'    => true,
            'note'            => 'FOSSBilling Setup Fee for Invoice #' . $invoiceData['id'],
        ];

        $response = $this->squarePost(
            $this->getApiBaseUrl() . '/v2/payments',
            $payload
        );

        $this->debugLog('chargeSetupFee() response:');
        $this->debugLog($response);

        return $response;
    }
/**
     * Extract subscription-related metadata from invoice/request data.
     *
     * This derives:
     * - product_id
     * - billing_key
     * - recurring SKU
     * - setup_fee amount
     *
     * @param array $data
     * @return array
     */
    protected function extractSubscriptionMeta(array $data): array
    {
        $this->debugLog('extractSubscriptionMeta() called');
        $this->debugLog($data);

        $invoice = $data['invoice'] ?? null;
        if (!$invoice) {
            $this->debugLog('extractSubscriptionMeta() missing invoice');
            throw new Exception('Invoice data is missing.');
        }

        $items = $invoice['items'] ?? [];
        if (!is_array($items) || !$items) {
            $this->debugLog('extractSubscriptionMeta() missing items');
            throw new Exception('Invoice items are missing.');
        }

        $productId = 0;

        foreach ($items as $item) {
            if (!empty($item['rel_id']) && is_numeric($item['rel_id'])) {
                $productId = (int)$item['rel_id'];
                break;
            }

            if (!empty($item['product_id']) && is_numeric($item['product_id'])) {
                $productId = (int)$item['product_id'];
                break;
            }
        }

        if ($productId <= 0) {
            $this->debugLog('extractSubscriptionMeta() could not determine productId');
            throw new Exception('Unable to determine the product for this subscription invoice.');
        }

        $product = $this->di['db']->getRow(
            "SELECT * FROM product WHERE id = :id LIMIT 1",
            [':id' => $productId]
        );

        if (!$product) {
            $this->debugLog('extractSubscriptionMeta() product not found for id=' . $productId);
            throw new Exception('Product not found for subscription invoice.');
        }

        $slug = strtolower(trim((string)($product['slug'] ?? '')));
        if ($slug === '') {
            $this->debugLog('extractSubscriptionMeta() missing product slug');
            throw new Exception('Product slug is required for Square subscription mapping.');
        }

        $payment = $this->di['db']->getRow(
            "SELECT * FROM product_payment WHERE id = :id LIMIT 1",
            [':id' => $product['product_payment_id']]
        );

        if (!$payment) {
            $this->debugLog('extractSubscriptionMeta() missing product_payment row');
            throw new Exception('Product payment data not found.');
        }

        $billingKey = $this->detectBillingKeyFromInvoice($items, $payment);
        $setupFee = $this->getSetupFeeForBillingKey($payment, $billingKey);

        $meta = [
            'product_id' => $productId,
            'billing_key' => $billingKey,
            'sku' => $slug . '-' . $billingKey,
            'setup_fee' => $setupFee,
            'product' => $product,
            'payment' => $payment,
        ];

        $this->debugLog('extractSubscriptionMeta() result:');
        $this->debugLog($meta);

        return $meta;
    }

    /**
     * Determine which billing key the invoice represents.
     *
     * This uses invoice line amounts and enabled payment amounts
     * to infer the chosen period.
     *
     * @param array $items
     * @param array $payment
     * @return string
     */
    protected function detectBillingKeyFromInvoice(array $items, array $payment): string
    {
        $this->debugLog('detectBillingKeyFromInvoice() called');

        $candidates = [
            'weekly'  => ['price' => 'w_price',   'enabled' => 'w_enabled'],
            'monthly' => ['price' => 'm_price',   'enabled' => 'm_enabled'],
            '3month'  => ['price' => 'q_price',   'enabled' => 'q_enabled'],
            '6month'  => ['price' => 'b_price',   'enabled' => 'b_enabled'],
            'yearly'  => ['price' => 'a_price',   'enabled' => 'a_enabled'],
            '2year'   => ['price' => 'bia_price', 'enabled' => 'bia_enabled'],
            '3year'   => ['price' => 'tria_price','enabled' => 'tria_enabled'],
        ];

        $lineAmounts = [];

        foreach ($items as $item) {
            if (isset($item['price']) && is_numeric($item['price'])) {
                $lineAmounts[] = (float)$item['price'];
            } elseif (isset($item['total']) && is_numeric($item['total'])) {
                $lineAmounts[] = (float)$item['total'];
            }
        }

        $this->debugLog('detectBillingKeyFromInvoice() lineAmounts:');
        $this->debugLog($lineAmounts);

        foreach ($candidates as $billingKey => $map) {
            $enabled = (int)($payment[$map['enabled']] ?? 0);
            $price = (float)($payment[$map['price']] ?? 0);

            if ($enabled !== 1 || $price <= 0) {
                continue;
            }

            foreach ($lineAmounts as $amount) {
                if (abs($amount - $price) < 0.01) {
                    $this->debugLog("detectBillingKeyFromInvoice() matched {$billingKey} via exact amount {$amount}");
                    return $billingKey;
                }
            }
        }

        // Safe fallback if invoice line matching cannot determine the period.
        // Prefer monthly when enabled, otherwise first enabled recurring candidate.
        if ((int)($payment['m_enabled'] ?? 0) === 1 && (float)($payment['m_price'] ?? 0) > 0) {
            $this->debugLog('detectBillingKeyFromInvoice() fallback to monthly');
            return 'monthly';
        }

        foreach ($candidates as $billingKey => $map) {
            if ((int)($payment[$map['enabled']] ?? 0) === 1 && (float)($payment[$map['price']] ?? 0) > 0) {
                $this->debugLog("detectBillingKeyFromInvoice() fallback to first enabled candidate {$billingKey}");
                return $billingKey;
            }
        }

        $this->debugLog('detectBillingKeyFromInvoice() failed to determine billing key');
        throw new Exception('Unable to determine subscription billing period.');
    }

    /**
     * Return the setup fee for a given billing key.
     *
     * @param array $payment
     * @param string $billingKey
     * @return float
     */
    protected function getSetupFeeForBillingKey(array $payment, string $billingKey): float
    {
        $setupFieldMap = [
            'weekly'  => 'w_setup_price',
            'monthly' => 'm_setup_price',
            '3month'  => 'q_setup_price',
            '6month'  => 'b_setup_price',
            'yearly'  => 'a_setup_price',
            '2year'   => 'bia_setup_price',
            '3year'   => 'tria_setup_price',
        ];

        $field = $setupFieldMap[$billingKey] ?? null;
        if ($field === null) {
            $this->debugLog('getSetupFeeForBillingKey() no setup field for billingKey=' . $billingKey);
            return 0.0;
        }

        $setupFee = (float)($payment[$field] ?? 0);

        $this->debugLog("getSetupFeeForBillingKey() {$billingKey} => {$setupFee}");

        return $setupFee;
    }
/**
     * Ensure a Square customer exists for the current FOSSBilling client.
     *
     * @param array $data
     * @return string Square customer ID
     */
    protected function ensureSquareCustomer(array $data): string
    {
        $this->debugLog('ensureSquareCustomer() called');

        $client = $data['client'] ?? null;

        if (!$client) {
            $this->debugLog('ensureSquareCustomer() missing client');
            throw new Exception('Client data is missing for Square customer creation.');
        }

        $email = trim((string)($client['email'] ?? ''));
        $firstName = trim((string)($client['first_name'] ?? ''));
        $lastName = trim((string)($client['last_name'] ?? ''));

        $this->debugLog([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        if ($email === '') {
            $this->debugLog('ensureSquareCustomer() missing email');
            throw new Exception('Client email is required for Square customer creation.');
        }

        // Try to find an existing Square customer by email
        $searchPayload = [
            'query' => [
                'filter' => [
                    'email_address' => [
                        'exact' => $email,
                    ],
                ],
            ],
            'limit' => 1,
        ];

        $searchResponse = $this->squarePost(
            $this->getApiBaseUrl() . '/v2/customers/search',
            $searchPayload
        );

        $this->debugLog('ensureSquareCustomer() search response:');
        $this->debugLog($searchResponse);

        if (!empty($searchResponse['customers'][0]['id'])) {
            $customerId = (string)$searchResponse['customers'][0]['id'];
            $this->debugLog('ensureSquareCustomer() found existing customerId=' . $customerId);

            return $customerId;
        }

        // Create a new Square customer
        $createPayload = [
            'given_name'    => $firstName,
            'family_name'   => $lastName,
            'email_address' => $email,
        ];

        $createResponse = $this->squarePost(
            $this->getApiBaseUrl() . '/v2/customers',
            $createPayload
        );

        $this->debugLog('ensureSquareCustomer() create response:');
        $this->debugLog($createResponse);

        $customerId = (string)($createResponse['customer']['id'] ?? '');
        if ($customerId === '') {
            $this->debugLog('ensureSquareCustomer() failed to create customer');
            throw new Exception('Square customer could not be created.');
        }

        $this->debugLog('ensureSquareCustomer() created customerId=' . $customerId);

        return $customerId;
    }

    /**
     * Store a card on file in Square for the customer.
     *
     * @param string $customerId
     * @param string $sourceToken
     * @return string Square card ID
     */
    protected function storeCardOnFile(string $customerId, string $sourceToken): string
    {
        $this->debugLog('storeCardOnFile() called');
        $this->debugLog([
            'customer_id' => $customerId,
            'source_token_prefix' => substr($sourceToken, 0, 10) . '...',
        ]);

        $payload = [
            'source_id' => $sourceToken,
            'card' => [
                'customer_id' => $customerId,
            ],
            'idempotency_key' => $this->generateIdempotencyKey('card_' . $customerId),
        ];

        $response = $this->squarePost(
            $this->getApiBaseUrl() . '/v2/cards',
            $payload
        );

        $this->debugLog('storeCardOnFile() response:');
        $this->debugLog($response);

        $cardId = (string)($response['card']['id'] ?? '');
        if ($cardId === '') {
            $this->debugLog('storeCardOnFile() failed to create card');
            throw new Exception('Square card on file could not be created.');
        }

        $this->debugLog('storeCardOnFile() cardId=' . $cardId);

        return $cardId;
    }

    /**
     * Resolve a manually stored subscription plan variation ID.
     *
     * @param int $productId
     * @param string $billingKey
     * @param string $sku
     * @return string
     */
    protected function resolveMappedPlanVariationId(int $productId, string $billingKey, string $sku): string
    {
        $this->debugLog('resolveMappedPlanVariationId() called');
        $this->debugLog([
            'product_id' => $productId,
            'billing_key' => $billingKey,
            'sku' => $sku,
        ]);

        $row = $this->di['db']->getRow(
            "SELECT square_plan_variation_id
             FROM square_product_plan_map
             WHERE product_id = :product_id
               AND billing_key = :billing_key
             LIMIT 1",
            [
                ':product_id' => $productId,
                ':billing_key' => $billingKey,
            ]
        );

        $planVariationId = (string)($row['square_plan_variation_id'] ?? '');

        $this->debugLog('resolveMappedPlanVariationId() result=' . ($planVariationId !== '' ? $planVariationId : '[empty]'));

        return $planVariationId;
    }

    /**
     * Create a Square subscription using a stored card and a subscription plan variation.
     *
     * @param string $customerId
     * @param string $cardId
     * @param string $planVariationId
     * @param array  $subscriptionMeta
     * @return array
     */
    protected function createSubscription(
        string $customerId,
        string $cardId,
        string $planVariationId,
        array $subscriptionMeta
    ): array {
        $this->debugLog('createSubscription() called');
        $this->debugLog([
            'customer_id' => $customerId,
            'card_id' => $cardId,
            'plan_variation_id' => $planVariationId,
            'billing_key' => $subscriptionMeta['billing_key'] ?? '',
            'sku' => $subscriptionMeta['sku'] ?? '',
        ]);

        $payload = [
            'idempotency_key' => $this->generateIdempotencyKey('sub_' . $customerId . '_' . $planVariationId),
            'location_id' => $this->getLocationId(),
            'plan_variation_id' => $planVariationId,
            'customer_id' => $customerId,
            'card_id' => $cardId,
            'timezone' => 'America/Chicago',
            'source' => [
                'name' => 'FOSSBilling',
            ],
        ];

        $response = $this->squarePost(
            $this->getApiBaseUrl() . '/v2/subscriptions',
            $payload
        );

        $this->debugLog('createSubscription() response:');
        $this->debugLog($response);

        return $response;
    }
/**
     * Return the current stored Square subscription plan variation mapping
     * for a product/billing pair.
     *
     * @param int $productId
     * @param string $billingKey
     * @return string
     */
    public function getMappedPlanVariationId(int $productId, string $billingKey): string
    {
        $this->debugLog('getMappedPlanVariationId() called');
        $this->debugLog([
            'product_id' => $productId,
            'billing_key' => $billingKey,
        ]);

        return $this->resolveMappedPlanVariationId($productId, $billingKey, '');
    }

    /**
     * Try to auto-detect the correct Square subscription plan variation ID
     * for a recurring billing key.
     *
     * IMPORTANT:
     * - This uses cadence as a helper only.
     * - If multiple plan variations share the same cadence,
     *   this returns an empty string so the mapping can be chosen manually.
     * - 3-year billing does not have a documented Square cadence match,
     *   so it returns an empty string.
     *
     * @param int $productId
     * @param string $billingKey
     * @param string $squareSku
     * @return string
     */
    public function discoverPlanVariationId(int $productId, string $billingKey, string $squareSku): string
    {
        $this->debugLog('discoverPlanVariationId() called');
        $this->debugLog([
            'product_id' => $productId,
            'billing_key' => $billingKey,
            'square_sku' => $squareSku,
        ]);

        $cadence = $this->mapBillingKeyToSquareCadence($billingKey);

        $this->debugLog('discoverPlanVariationId() mapped cadence=' . ($cadence !== '' ? $cadence : '[empty]'));

        if ($cadence === '') {
            $this->debugLog('discoverPlanVariationId() no cadence mapping available, returning empty');
            return '';
        }

        $allObjects = [];
        $cursor = null;

        do {
            $url = $this->getApiBaseUrl() . '/v2/catalog/list?types=SUBSCRIPTION_PLAN_VARIATION';

            if ($cursor) {
                $url .= '&cursor=' . urlencode($cursor);
            }

            $response = $this->squareGet($url);

            if (!empty($response['objects']) && is_array($response['objects'])) {
                $allObjects = array_merge($allObjects, $response['objects']);
            }

            $cursor = $response['cursor'] ?? null;

            $this->debugLog('discoverPlanVariationId() pagination cursor=' . ($cursor ?: 'NONE'));
        } while ($cursor);

        $this->debugLog('discoverPlanVariationId() total plan variation objects fetched=' . count($allObjects));

        if (!$allObjects) {
            $this->debugLog('discoverPlanVariationId() no SUBSCRIPTION_PLAN_VARIATION objects found');
            return '';
        }

        $matches = [];

        foreach ($allObjects as $object) {
            if (($object['type'] ?? '') !== 'SUBSCRIPTION_PLAN_VARIATION') {
                continue;
            }
			if (!empty($object['is_deleted'])) {
				continue;
			}

			if (empty($object['present_at_all_locations'])) {
				continue;
			}
            $planData = $object['subscription_plan_variation_data'] ?? [];
            $phases = $planData['phases'] ?? [];
            $phaseCadence = (string)($phases[0]['cadence'] ?? '');
            $id = (string)($object['id'] ?? '');

            $this->debugLog([
                'discoverPlanVariationId() inspecting object' => [
                    'id' => $id,
                    'name' => (string)($planData['name'] ?? ''),
                    'plan_id' => (string)($planData['subscription_plan_id'] ?? ''),
                    'phase_cadence' => $phaseCadence,
                ],
            ]);

            if ($phaseCadence === $cadence) {
                $matches[] = $id;
            }
        }

        $this->debugLog('discoverPlanVariationId() cadence matches:');
        $this->debugLog($matches);

        // Only auto-match when exactly one plan variation matches the cadence.
        if (count($matches) === 1) {
            $this->debugLog('discoverPlanVariationId() returning unique match=' . $matches[0]);
            return $matches[0];
        }

        $this->debugLog('discoverPlanVariationId() returning empty because match count=' . count($matches));

        return '';
    }

    /**
     * Map internal FOSSBilling billing keys to documented Square subscription cadence values.
     *
     * Supported:
     * - weekly  -> WEEKLY
     * - monthly -> MONTHLY
     * - 3month  -> QUARTERLY
     * - 6month  -> EVERY_SIX_MONTHS
     * - yearly  -> ANNUAL
     * - 2year   -> EVERY_TWO_YEARS
     *
     * Unsupported:
     * - 3year -> no documented Square cadence match
     *
     * @param string $billingKey
     * @return string
     */
    private function mapBillingKeyToSquareCadence(string $billingKey): string
    {
        $mapped = match ($billingKey) {
            'weekly'  => 'WEEKLY',
            'monthly' => 'MONTHLY',
            '3month'  => 'QUARTERLY',
            '6month'  => 'EVERY_SIX_MONTHS',
            'yearly'  => 'ANNUAL',
            '2year'   => 'EVERY_TWO_YEARS',
            default   => '',
        };

        $this->debugLog("mapBillingKeyToSquareCadence() {$billingKey} => " . ($mapped !== '' ? $mapped : '[empty]'));

        return $mapped;
    }
/**
     * Return Square catalog inspection data:
     * - item variations (from item library / CSV import)
     * - subscription plan variations (used for recurring subscriptions)
     *
     * This supports the Square Manager admin inspection utility.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listSquareObjectsForInspection(): array
    {
        $this->debugLog('listSquareObjectsForInspection() START');

        $allObjects = [];
        $cursor = null;

        do {
            $url = $this->getApiBaseUrl() . '/v2/catalog/list?types=ITEM,ITEM_VARIATION,SUBSCRIPTION_PLAN_VARIATION';

            if ($cursor) {
                $url .= '&cursor=' . urlencode($cursor);
            }

            $this->debugLog('listSquareObjectsForInspection() request URL: ' . $url);

            $response = $this->squareGet($url);

            $this->debugLog('listSquareObjectsForInspection() page response:');
            $this->debugLog($response);

            if (!empty($response['objects']) && is_array($response['objects'])) {
                $allObjects = array_merge($allObjects, $response['objects']);
            }

            $cursor = $response['cursor'] ?? null;
            $this->debugLog('listSquareObjectsForInspection() cursor=' . ($cursor ?: 'NONE'));
        } while ($cursor);

        $this->debugLog('listSquareObjectsForInspection() total fetched objects=' . count($allObjects));

        if (!$allObjects) {
            $this->debugLog('listSquareObjectsForInspection() no objects found');

            return [
                'item_variations' => [],
                'plan_variations' => [],
            ];
        }

        $itemNames = [];
        $itemVariations = [];
        $planVariations = [];

        // First pass: collect ITEM names by ID
        foreach ($allObjects as $object) {
            if (($object['type'] ?? '') !== 'ITEM') {
                continue;
            }

            $itemId = (string)($object['id'] ?? '');
            $itemName = (string)($object['item_data']['name'] ?? '');

            if ($itemId !== '') {
                $itemNames[$itemId] = $itemName;
            }
        }

        $this->debugLog('listSquareObjectsForInspection() collected item names:');
        $this->debugLog($itemNames);

        // Second pass: collect ITEM_VARIATION and SUBSCRIPTION_PLAN_VARIATION rows
        foreach ($allObjects as $object) {
            $type = (string)($object['type'] ?? '');

            if ($type === 'ITEM_VARIATION') {
                $variationData = $object['item_variation_data'] ?? [];
                $itemId = (string)($variationData['item_id'] ?? '');

                $row = [
                    'id' => (string)($object['id'] ?? ''),
                    'item_name' => (string)($itemNames[$itemId] ?? ''),
                    'variation_name' => (string)($variationData['name'] ?? ''),
                    'sku' => (string)($variationData['sku'] ?? ''),
                    'price' => (int)($variationData['price_money']['amount'] ?? 0),
                ];

                $itemVariations[] = $row;
            }

            
				if ($type === 'SUBSCRIPTION_PLAN_VARIATION') {

					// ✅ Skip deleted
					if (!empty($object['is_deleted'])) {
						continue;
					}

					// ✅ Skip not active across locations (your requirement)
					if (empty($object['present_at_all_locations'])) {
						continue;
					}

					$planData = $object['subscription_plan_variation_data'] ?? [];
					$phases = $planData['phases'] ?? [];

					// ✅ Require a cadence
					if (empty($phases[0]['cadence'])) {
						continue;
					}

					$row = [
						'id' => (string)($object['id'] ?? ''),
						'name' => (string)($planData['name'] ?? ''),
						'plan_id' => (string)($planData['subscription_plan_id'] ?? ''),
						'cadence' => (string)($phases[0]['cadence'] ?? ''),
					];

					$planVariations[] = $row;
				}

        }

        $result = [
            'item_variations' => $itemVariations,
            'plan_variations' => $planVariations,
        ];

        $this->debugLog('listSquareObjectsForInspection() final result:');
        $this->debugLog($result);

        return $result;
    }

    /**
     * Optional helper for older code paths that expect a simple variation listing method.
     *
     * This returns only item-library variations, not subscription plan variations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCatalogVariations(): array
    {
        $this->debugLog('listCatalogVariations() called');

        $all = $this->listSquareObjectsForInspection();

        $result = $all['item_variations'] ?? [];

        $this->debugLog('listCatalogVariations() result count=' . count($result));

        return $result;
    }

    /**
     * Legacy-compatible wrapper retained for code paths that still call
     * resolvePlanVariationId() instead of discoverPlanVariationId().
     *
     * @param int $productId
     * @param string $billingKey
     * @param string $squareSku
     * @return string
     */
    public function resolvePlanVariationId(int $productId, string $billingKey, string $squareSku): string
    {
        $this->debugLog('resolvePlanVariationId() wrapper called');

        return $this->discoverPlanVariationId($productId, $billingKey, $squareSku);
    }
/**
     * Process Square webhook notifications if needed.
     *
     * This is optional / minimal here. You can expand this later
     * if you want automatic payment or subscription status sync.
     *
     * @param array $data
     * @return array
     */
    public function processIpn(array $data): array
    {
        $this->debugLog('processIpn() called');
        $this->debugLog($data);

        return [
            'status' => 'ok',
            'raw' => $data,
        ];
    }

    /**
     * Refund support placeholder.
     *
     * Expand later if you want admin-side Square refunds via API.
     *
     * @param array $data
     * @return bool
     */
    public function refund(array $data): bool
    {
        $this->debugLog('refund() called');
        $this->debugLog($data);

        return false;
    }

    /**
     * Return whether the adapter supports refunds directly.
     *
     * @return bool
     */
    public function supportsRefunds(): bool
    {
        return false;
    }

    /**
     * Return adapter features summary.
     *
     * @return array
     */
    public function getFeatures(): array
    {
        $features = [
            'one_time_payments' => true,
            'subscriptions' => true,
            'setup_fees' => true,
            'refunds' => false,
            'debug_enabled' => $this->debugEnabled,
            'debug_log_file' => $this->debugLogFile,
        ];

        $this->debugLog('getFeatures() called');
        $this->debugLog($features);

        return $features;
    }
}	