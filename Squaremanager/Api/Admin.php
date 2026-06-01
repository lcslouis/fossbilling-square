<?php

declare(strict_types=1);

namespace Box\Mod\Squaremanager\Api;

class Admin extends \Api_Abstract
{
    /**
     * Toggle this to false to disable all SquareManager API debug logging.
     */
    private bool $debugEnabled = true;

    /**
     * Optional separate log file.
     * Leave blank to send logs to php_error.log.
     *
     * Example:
     *   /home/lcsworldsales.com/logs/squaremanager-debug.log
     */
    private string $debugLogFile = '/home/lcsworldsales.com/public_html/modules/Squaremanager/issues.log';

    public function isAdminApiAllowed(): bool
    {
        return true;
    }

    /**
     * Central debug logger for this API file.
     */
    private function logDebug(mixed $msg): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $prefix = '[SquareManager] ';
        $line = is_array($msg) || is_object($msg)
            ? $prefix . print_r($msg, true)
            : $prefix . (string)$msg;

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
     * TEMP diagnostic adapter loader.
     *
     * This intentionally bypasses unknown FOSSBilling payment config storage
     * while we debug. Replace these placeholder values with the exact sandbox
     * credentials that already returned valid Square API data for you.
     *
     * Once debugging is complete, we can replace this with proper dynamic
     * config loading.
     */
    
	
	private function getSquareAdapter(): \Payment_Adapter_Square
	{
		$this->logDebug('getSquareAdapter() loading from pay_gateway');

		$row = $this->di['db']->getRow(
			"SELECT * FROM pay_gateway
			WHERE name = :name OR gateway = :gateway
			LIMIT 1",
			[
				':name' => 'Square',
				':gateway' => 'Square',
			]
		);

		if (!$row) {
			throw new \Exception('Square gateway not found in pay_gateway');
		}

		$this->logDebug('Raw pay_gateway row:');
		$this->logDebug($row);

		$config = json_decode((string)$row['config'], true);

		if (!is_array($config)) {
			throw new \Exception('Invalid Square config JSON in pay_gateway.config');
		}

		// Required runtime URLs for Payment_AdapterAbstract
		$config['return_url'] = $this->di['tools']->url('');
		$config['cancel_url'] = $this->di['tools']->url('');
		$config['notify_url'] = $this->di['tools']->url('ipn.php?gateway=Square');

		// Keep debug enabled while validating
		$config['debug_enabled'] = true;
		$config['debug_log_file'] = '';

		$this->logDebug('Normalized adapter config:');
		$this->logDebug($config);

		$adapter = new \Payment_Adapter_Square($config);
		$adapter->setDi($this->di);

		return $adapter;
	}





    /**
     * Ensure mapping table exists.
     */
    private function ensureMappingTable(): void
    {
        $this->logDebug('ensureMappingTable() called');

        $this->di['db']->exec(
            "CREATE TABLE IF NOT EXISTS square_product_plan_map (
                product_id INT NOT NULL,
                billing_key VARCHAR(32) NOT NULL,
                square_sku VARCHAR(191) NOT NULL,
                square_plan_variation_id VARCHAR(64) NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (product_id, billing_key)
            )"
        );
    }
/**
     * Recurring rows only.
     * Setup fee rows are intentionally excluded because they are charged once
     * and do not need subscription plan variation IDs.
     */
    public function mapping(array $data): array
    {
        $this->logDebug('mapping() called');
        $this->ensureMappingTable();

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT * FROM product WHERE status = 'enabled' ORDER BY id ASC"
        );

        $rows = [];

        $pricingMap = [
            'weekly'  => ['price' => 'w_price',   'enabled' => 'w_enabled'],
            'monthly' => ['price' => 'm_price',   'enabled' => 'm_enabled'],
            '3month'  => ['price' => 'q_price',   'enabled' => 'q_enabled'],
            '6month'  => ['price' => 'b_price',   'enabled' => 'b_enabled'],
            'yearly'  => ['price' => 'a_price',   'enabled' => 'a_enabled'],
            '2year'   => ['price' => 'bia_price', 'enabled' => 'bia_enabled'],
            '3year'   => ['price' => 'tria_price','enabled' => 'tria_enabled'],
        ];

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));
            if ($slug === '') {
                $this->logDebug('mapping() skipped product with empty slug: ' . print_r($product, true));
                continue;
            }

            $payment = $db->getRow(
                "SELECT * FROM product_payment WHERE id = :id LIMIT 1",
                [':id' => $product['product_payment_id']]
            );

            if (!$payment) {
                $this->logDebug('mapping() no payment row found for product_id=' . (int)$product['id']);
                continue;
            }

            foreach ($pricingMap as $billingKey => $map) {
                $enabled = (int)($payment[$map['enabled']] ?? 0);
                $price   = (float)($payment[$map['price']] ?? 0);

                $this->logDebug([
                    'mapping() candidate' => [
                        'product_id' => (int)$product['id'],
                        'product_title' => (string)$product['title'],
                        'billing_key' => $billingKey,
                        'enabled' => $enabled,
                        'price' => $price,
                    ],
                ]);

                if ($enabled !== 1 || $price <= 0) {
                    continue;
                }

                $stored = $db->getRow(
                    "SELECT square_plan_variation_id
                     FROM square_product_plan_map
                     WHERE product_id = :product_id
                       AND billing_key = :billing_key
                     LIMIT 1",
                    [
                        ':product_id' => $product['id'],
                        ':billing_key' => $billingKey,
                    ]
                );

                $rows[] = [
                    'product_id' => (int)$product['id'],
                    'product'    => (string)$product['title'],
                    'billing'    => $billingKey,
                    'sku'        => $slug . '-' . $billingKey,
                    'variation'  => (string)($stored['square_plan_variation_id'] ?? ''),
                ];
            }
        }

        $this->logDebug('mapping() returning rows count=' . count($rows));
        $this->logDebug($rows);

        return $rows;
    }

    /**
     * Save or replace a manual mapping row.
     */
    public function save_mapping(array $data): bool
    {
        $this->logDebug('save_mapping() called');
        $this->logDebug($data);

        $this->ensureMappingTable();

        $this->di['db']->exec(
            "REPLACE INTO square_product_plan_map
            (product_id, billing_key, square_sku, square_plan_variation_id, updated_at)
            VALUES
            (:product_id, :billing_key, :square_sku, :variation_id, :updated_at)",
            [
                ':product_id'   => (int)$data['product_id'],
                ':billing_key'  => (string)$data['billing'],
                ':square_sku'   => (string)$data['sku'],
                ':variation_id' => (string)$data['variation'],
                ':updated_at'   => date('Y-m-d H:i:s'),
            ]
        );

        $this->logDebug('save_mapping() completed successfully');

        return true;
    }
	/**
     * Auto-detect recurring subscription plan variation IDs only.
     * If a cadence maps to more than one Square subscription plan variation,
     * no auto-match is made for that billing key.
     */
    public function auto_sync(array $data): array
    {
        $this->logDebug('auto_sync() called');
        $this->logDebug($data);

        $this->ensureMappingTable();

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT * FROM product WHERE status = 'enabled' ORDER BY id ASC"
        );

        $pricingMap = [
            'weekly'  => ['price' => 'w_price',   'enabled' => 'w_enabled'],
            'monthly' => ['price' => 'm_price',   'enabled' => 'm_enabled'],
            '3month'  => ['price' => 'q_price',   'enabled' => 'q_enabled'],
            '6month'  => ['price' => 'b_price',   'enabled' => 'b_enabled'],
            'yearly'  => ['price' => 'a_price',   'enabled' => 'a_enabled'],
            '2year'   => ['price' => 'bia_price', 'enabled' => 'bia_enabled'],
            '3year'   => ['price' => 'tria_price','enabled' => 'tria_enabled'],
        ];

        $singleMode = !empty($data['single']);
        $singleSku = trim((string)($data['sku'] ?? ''));

        $results = [];
        $adapter = $this->getSquareAdapter();

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));
            if ($slug === '') {
                $this->logDebug('auto_sync() skipped product with empty slug: ' . print_r($product, true));
                continue;
            }

            $payment = $db->getRow(
                "SELECT * FROM product_payment WHERE id = :id LIMIT 1",
                [':id' => $product['product_payment_id']]
            );

            if (!$payment) {
                $this->logDebug('auto_sync() no payment row found for product_id=' . (int)$product['id']);
                continue;
            }

            foreach ($pricingMap as $billingKey => $map) {
                $enabled = (int)($payment[$map['enabled']] ?? 0);
                $price   = (float)($payment[$map['price']] ?? 0);

                if ($enabled !== 1 || $price <= 0) {
                    continue;
                }

                $sku = $slug . '-' . $billingKey;

                if ($singleMode && $sku !== $singleSku) {
                    continue;
                }

                $this->logDebug([
                    'auto_sync() detect attempt' => [
                        'product_id' => (int)$product['id'],
                        'product_title' => (string)$product['title'],
                        'billing_key' => $billingKey,
                        'sku' => $sku,
                    ],
                ]);

                $variationId = $adapter->discoverPlanVariationId(
                    (int)$product['id'],
                    $billingKey,
                    $sku
                );

                $this->logDebug('auto_sync() detect result for ' . $sku . ': ' . ($variationId !== '' ? $variationId : '[empty]'));

                if ($variationId !== '') {
                    $db->exec(
                        "REPLACE INTO square_product_plan_map
                        (product_id, billing_key, square_sku, square_plan_variation_id, updated_at)
                        VALUES
                        (:product_id, :billing_key, :square_sku, :variation_id, :updated_at)",
                        [
                            ':product_id'   => (int)$product['id'],
                            ':billing_key'  => $billingKey,
                            ':square_sku'   => $sku,
                            ':variation_id' => $variationId,
                            ':updated_at'   => date('Y-m-d H:i:s'),
                        ]
                    );

                    $results[] = [
                        'sku'       => $sku,
                        'variation' => $variationId,
                    ];
                }
            }
        }

        $this->logDebug('auto_sync() returning results count=' . count($results));
        $this->logDebug($results);

        return $results;
    }

    /**
     * Inspector data:
     * - item variations from item library
     * - subscription plan variations for recurring plans
     */
    public function list_square_objects(array $data): array
    {
        $this->logDebug('list_square_objects() called');

        $adapter = $this->getSquareAdapter();

        $result = $adapter->listSquareObjectsForInspection();

        $this->logDebug('list_square_objects() adapter result:');
        $this->logDebug($result);

        return $result;
    }
/**
     * Optional helper to quickly verify DB access and product/payment shape.
     * Safe to keep for debugging while debug logging is enabled.
     */
    public function debug_snapshot(array $data): array
    {
        $this->logDebug('debug_snapshot() called');

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT id, title, slug, product_payment_id
             FROM product
             WHERE status = 'enabled'
             ORDER BY id ASC
             LIMIT 10"
        );

        $this->logDebug('debug_snapshot() products:');
        $this->logDebug($products);

        $snapshot = [];

        foreach ($products as $product) {
            $payment = null;

            if (!empty($product['product_payment_id'])) {
                $payment = $db->getRow(
                    "SELECT *
                     FROM product_payment
                     WHERE id = :id
                     LIMIT 1",
                    [':id' => $product['product_payment_id']]
                );
            }

            $snapshot[] = [
                'product' => $product,
                'payment' => $payment,
            ];
        }

        $this->logDebug('debug_snapshot() final snapshot:');
        $this->logDebug($snapshot);

        return $snapshot;
    }

    /**
     * Return current debug settings so you can verify the API file is
     * using the expected log toggle and log destination.
     */
    public function debug_status(array $data): array
    {
        $status = [
            'debug_enabled' => $this->debugEnabled,
            'debug_log_file' => $this->debugLogFile,
        ];

        $this->logDebug('debug_status() called');
        $this->logDebug($status);

        return $status;
    }
}	