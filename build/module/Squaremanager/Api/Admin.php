<?php

declare(strict_types=1);

namespace Box\Mod\Squaremanager\Api;

use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;

/**
 * Square Manager - Admin API
 *
 * Responsibilities:
 * - Return mapping rows for the Square Manager admin UI
 * - Save manual variation ID overrides
 * - Auto-detect variation IDs from Square using SKU logic
 *
 * Notes:
 * - Intended for authenticated administrators only
 * - Reuses the Square payment adapter's discovery logic so:
 *   - admin auto-sync
 *   - runtime subscription checkout
 *   both behave consistently
 */
class Admin implements InjectionAwareInterface
{
    /**
     * Dependency injection container.
     */
    protected ?Container $di = null;

    /**
     * Inject DI container.
     */
    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    /**
     * Return DI container.
     */
    public function getDi(): ?Container
    {
        return $this->di;
    }

    /**
     * Return mapping rows for the admin UI table.
     *
     * Each row contains:
     * - product_id
     * - product title
     * - base slug
     * - billing key
     * - generated SKU
     * - stored Square subscription plan variation ID (if mapped)
     *
     * Frontend usage:
     *   API.admin.squaremanager_mapping()
     */
    public function mapping(array $data): array
    {
        $this->ensureMappingTable();

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT * FROM product WHERE status = 'enabled' ORDER BY id ASC"
        );

        $rows = [];
        $billingKeys = [
            'weekly',
            'monthly',
            '3month',
            '6month',
            'yearly',
            '2year',
            '3year',
        ];

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));

            // Products without a slug cannot participate in SKU-based mapping.
            if ($slug === '') {
                continue;
            }

            foreach ($billingKeys as $billingKey) {
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
                    'slug'       => $slug,
                    'billing'    => $billingKey,
                    'sku'        => $slug . '-' . $billingKey,
                    'variation'  => (string)($stored['square_plan_variation_id'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * Save or replace a manual mapping row.
     *
     * Expected input:
     * - product_id
     * - billing
     * - sku
     * - variation
     *
     * Frontend usage:
     *   API.admin.squaremanager_save_mapping(...)
     */
    public function save_mapping(array $data): bool
    {
        $this->ensureMappingTable();

        $db = $this->di['db'];

        $db->exec(
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

        return true;
    }

    /**
     * Auto-detect variation IDs from Square using SKU lookup.
     *
     * Supported modes:
     * - Full sync: loops through all enabled products + all billing keys
     * - Single row sync: only processes one SKU if:
     *     - single = true
     *     - sku = exact SKU
     *
     * Frontend usage:
     *   API.admin.squaremanager_auto_sync({})
     *   API.admin.squaremanager_auto_sync({ sku: "...", single: true })
     */
    public function auto_sync(array $data): array
    {
        $this->ensureMappingTable();

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT * FROM product WHERE status = 'enabled' ORDER BY id ASC"
        );

        $billingKeys = [
            'weekly',
            'monthly',
            '3month',
            '6month',
            'yearly',
            '2year',
            '3year',
        ];

        $singleMode = !empty($data['single']);
        $singleSku = trim((string)($data['sku'] ?? ''));

        $results = [];

        // Reuse the Square payment adapter's discovery logic so the admin UI
        // and live runtime checkout remain in sync.
        $invoiceService = $this->di['mod_service']('Invoice');
        $adapter = $invoiceService->getGatewayAdapter('square');

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));

            if ($slug === '') {
                continue;
            }

            foreach ($billingKeys as $billingKey) {
                $sku = $slug . '-' . $billingKey;

                if ($singleMode && $sku !== $singleSku) {
                    continue;
                }

                $variationId = $adapter->discoverPlanVariationId(
                    (int)$product['id'],
                    $billingKey,
                    $sku
                );

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

        return $results;
    }

    /**
     * Ensure the mapping table exists.
     *
     * This table stores the relationship between:
     * - product_id
     * - billing_key
     * - generated Square SKU
     * - Square subscription plan variation ID
     */
    private function ensureMappingTable(): void
    {
        $this->di['db']->exec(
            "CREATE TABLE IF NOT EXISTS square_product_plan_map (
                product_id INT UNSIGNED NOT NULL,
                billing_key VARCHAR(32) NOT NULL,
                square_sku VARCHAR(191) NOT NULL,
                square_plan_variation_id VARCHAR(64) NOT NULL,
                last_discovered_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (product_id, billing_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
