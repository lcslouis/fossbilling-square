<?php
/**
 * Squaremanager Guest API
 *
 * Provides the processPayment endpoint called by the frontend checkout script.
 * Acts as the API integration layer between browser tokenization and
 * the Payment_Adapter_Square::processTransaction() method.
 *
 * Route: POST /api/guest/square/process_payment
 */
namespace Box\Mod\Squaremanager\Api;

class Guest extends \Api_Abstract
{

    /**
     * Process a tokenized Square payment.
     *
     * Expected POST body (JSON or form):
     *   invoice_id   int     — FOSSBilling invoice to pay
     *   gateway_id   int     — FOSSBilling pay_gateway record ID
     *   source_token string  — Square nonce token from Web Payments SDK
     *
     * FOSSBilling wraps the return value as:
     *   success → { result: true,  error: null }
     *   failure → { result: null,  error: { message: "...", code: 0 } }
     *
     * Throw \Box_Exception for any error so FOSSBilling serialises the
     * message correctly. Return true (bool) on success.
     */
    public function process_payment(array $data): bool
    {
        $invoiceId   = (int) ($data['invoice_id'] ?? 0);
        $gatewayId   = (int) ($data['gateway_id'] ?? 0);
        $sourceToken = trim($data['source_token'] ?? '');

        // --- Input validation ---
        if ($invoiceId <= 0)    throw new \Box_Exception('Invalid invoice.');
        if ($gatewayId <= 0)    throw new \Box_Exception('Invalid payment gateway.');
        if (empty($sourceToken)) throw new \Box_Exception('Payment token is missing.');

        // --- Load invoice and verify it is payable ---
        $invoiceModel = $this->di['db']->load('Invoice', $invoiceId);
        if (!$invoiceModel) throw new \Box_Exception('Invoice not found.');
        if ($invoiceModel->status === 'paid') throw new \Box_Exception('This invoice has already been paid.');

        // --- Load gateway ---
        $gatewayModel = $this->di['db']->load('PayGateway', $gatewayId);
        if (!$gatewayModel) throw new \Box_Exception('Payment gateway not found.');

        // --- Build / locate transaction record ---
        $transactionId = $this->_findOrCreateTransaction($invoiceId, $gatewayId);

        // --- Instantiate adapter and process ---
        $gatewayConfig = $this->_getGatewayConfig($gatewayModel);

        $adapter = new \Payment_Adapter_Square($gatewayConfig);
        $adapter->setDi($this->di);

        $processData = [
            'invoice_id'   => $invoiceId,
            'gateway_id'   => $gatewayId,
            'source_token' => $sourceToken,
        ];

        // Let any Box_Exception or Throwable bubble up — FOSSBilling will
        // serialise the message into json.error.message for the frontend.
        $adapter->processTransaction(null, $transactionId, $processData, $gatewayId);

        return true;
    }

    // -------------------------------------------------------------------------
    // Square Webhook Receiver
    // -------------------------------------------------------------------------

    /**
     * Receives Square subscription webhook events and syncs status to both
     * square_subscription and FOSSBilling's native subscription table.
     *
     * Register this URL in your Square Developer dashboard:
     *   https://<your-site>/api/guest/squaremanager/handle_webhook
     *
     * Supported events: subscription.updated, subscription.created
     *
     * Optional: set webhook_signature_key in the Square gateway config to
     * enable HMAC-SHA256 signature verification.
     */
    public function handle_webhook(array $data): bool
    {
        $rawBody   = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';

        // Load gateway config for optional signature verification
        $gw     = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Square']);
        $cfg    = $gw ? (json_decode($gw->config ?? '{}', true) ?: []) : [];
        $sigKey = $cfg['webhook_signature_key'] ?? '';

        if (!empty($sigKey) && !empty($sigHeader)) {
            $notificationUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
                               '://' . ($_SERVER['HTTP_HOST'] ?? '') .
                               ($_SERVER['REQUEST_URI'] ?? '/api/guest/squaremanager/handle_webhook');
            $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, $sigKey, true));
            if (!hash_equals($expected, $sigHeader)) {
                error_log('[Squaremanager] Webhook signature mismatch — rejected.');
                throw new \Box_Exception('Invalid webhook signature.', [], 401);
            }
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new \Box_Exception('Invalid webhook payload.');
        }

        $eventType = $event['type'] ?? '';
        error_log('[Squaremanager] Webhook received: ' . $eventType);

        if (in_array($eventType, ['subscription.updated', 'subscription.created'], true)) {
            $sub  = $event['data']['object']['subscription'] ?? null;
            $sqId = $sub['id']     ?? '';
            $sqSt = strtolower($sub['status'] ?? '');

            if (!empty($sqId) && !empty($sqSt)) {
                $statusMap = [
                    'active'      => 'active',
                    'canceled'    => 'canceled',
                    'deactivated' => 'canceled',
                    'paused'      => 'paused',
                    'pending'     => 'pending',
                ];
                $localStatus = $statusMap[$sqSt] ?? $sqSt;
                $now = date('Y-m-d H:i:s');

                $this->di['db']->exec(
                    'UPDATE square_subscription SET status = ?, updated_at = ? WHERE sq_subscription_id = ?',
                    [$localStatus, $now, $sqId]
                );
                $this->di['db']->exec(
                    'UPDATE subscription SET status = ?, updated_at = ? WHERE sid = ?',
                    [$localStatus, $now, $sqId]
                );

                error_log('[Squaremanager] Webhook sync: ' . $sqId . ' → ' . $localStatus);
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function _findOrCreateTransaction(int $invoiceId, int $gatewayId): int
    {
        // Look for an existing pending transaction via direct SQL
        $existingId = $this->di['db']->getCell(
            'SELECT id FROM transaction
             WHERE invoice_id = ? AND gateway_id = ? AND status = ? LIMIT 1',
            [$invoiceId, $gatewayId, 'received']
        );

        if ($existingId) {
            return (int) $existingId;
        }

        // Create a new transaction record via direct SQL (avoids dispense() issues)
        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'INSERT INTO transaction (invoice_id, gateway_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$invoiceId, $gatewayId, 'received', $now, $now]
        );

        $newId = (int) $this->di['db']->getCell('SELECT LAST_INSERT_ID()');
        if ($newId <= 0) {
            throw new \Box_Exception('Square: failed to create transaction record.');
        }

        return $newId;
    }

    private function _getGatewayConfig(\Model_PayGateway $gatewayModel): array
    {
        $config = [];
        if (!empty($gatewayModel->config)) {
            $decoded = json_decode($gatewayModel->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        return $config;
    }
}
