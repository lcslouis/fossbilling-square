<?php

/**
 * Square Manager - Admin API
 *
 * Responsibilities:
 * - Provide mapping data to the admin UI
 * - Save manual overrides for Square subscription plan variation IDs
 * - Run automatic SKU-based detection against the Square adapter
 *
 * Notes:
 * - This API is intended for authenticated FOSSBilling administrators only.
 * - The payment adapter remains responsible for runtime payment/subscription logic.
 * - This API exists purely to support the Square Manager admin interface.
 */
class Squaremanager_Api_Admin implements FOSSBilling\InjectionAwareInterface
{
    /**
     * FOSSBilling dependency injection container.
     *
     * @var Pimple\Container
     */
    protected $di;

    /**
     * Inject the FOSSBilling DI container.
     *
     * @param Pimple\Container $di
     */
    public function setDi(Pimple\Container $di)
    {
        $this->di = $di;
    }

    /**
     * Return the DI container.
     *
     * @return Pimple\Container
     */
    public function getDi()
    {
        return $this->di;
    }

    /**
     * Return all product/billing mappings for the admin UI.
     *
     * Each row returned represents:
     * - one FOSSBilling product
     * - one billing period key
     * - one generated Square SKU
     * - one stored Square subscription plan variation ID (if mapped)
     *
     * This data is used to render the Square Manager table where admins can:
     * - review generated SKUs
     * - see missing mappings
     * - manually edit variation IDs
     *
     * @param array $data Unused request payload
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_mapping(array $data)
    {
        $db = $this->di['db'];

        // Load all products so we can generate all expected billing/SKU combinations.
        $products = $db->getAll("SELECT * FROM product ORDER BY id ASC");

        $rows = [];

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));

            // Products without slugs cannot participate in the Square SKU mapping strategy,
            // so they are intentionally skipped.
            if ($slug === '') {
                continue;
            }

            // These billing keys match the convention used by the Square payment adapter
            // for SKU generation and runtime lookup.
            $billingKeys = [
                'weekly',
                'monthly',
                '3month',
                '6month',
                'yearly',
                '2year',
                '3year',
            ];

            foreach ($billingKeys as $key) {
                // Attempt to load any existing stored mapping for this product + billing key.
                $row = $db->getRow(
                    "SELECT square_plan_variation_id
                     FROM square_product_plan_map
                     WHERE product_id = :pid AND billing_key = :bk
                     LIMIT 1",
                    [
                        ':pid' => $product['id'],
                        ':bk'  => $key,
                    ]
                );

                $rows[] = [
                    'product_id' => $product['id'],
                    'product'    => $product['title'],
                    'slug'       => $slug,
                    'billing'    => $key,
                    'sku'        => $slug . '-' . $key,
                    'variation'  => $row['square_plan_variation_id'] ?? '',
                ];
            }
        }

        return $rows;
    }

    /**
     * Save or replace a manually entered variation mapping.
     *
     * This is used when an administrator enters (or corrects) the Square
     * subscription plan variation ID for a specific product + billing key row.
     *
     * Storage model:
     * - product_id + billing_key is the logical unique key
     * - square_sku is stored alongside the mapping for traceability
     *
     * @param array $data Expected keys:
     *  - product_id
     *  - billing
     *  - sku
     *  - variation
     *
     * @return bool
     */
    public function save_mapping(array $data)
    {
        $db = $this->di['db'];

        $sql = "
            REPLACE INTO square_product_plan_map
            (product_id, billing_key, square_sku, square_plan_variation_id, updated_at)
            VALUES
            (:pid, :bk, :sku, :vid, :updated)
        ";

        $db->exec($sql, [
            ':pid'     => $data['product_id'],
            ':bk'      => $data['billing'],
            ':sku'     => $data['sku'],
            ':vid'     => $data['variation'],
            ':updated' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Automatically attempt to discover Square variation mappings using SKU.
     *
     * Behavior:
     * - If called with no specific SKU, loops through all products/billing keys
     * - If called with "single" + "sku", tries only that one SKU row
     * - Uses the Square payment adapter's runtime discovery logic
     * - Saves discovered mappings into the local mapping table
     *
     * Why this is useful:
     * - Reduces manual setup effort
     * - Lets admins auto-fill mappings after importing products into Square
     * - Keeps the runtime logic and admin discovery logic consistent
     *
     * Expected optional inputs:
     * - sku    => exact SKU to detect
     * - single => truthy value to limit detection to one row
     *
     * @param array $data
     *
     * @return array<int, array<string, string>>
     */
    public function auto_sync(array $data)
    {
        $db = $this->di['db'];
        $results = [];

        // Load the Square gateway adapter via FOSSBilling.
        // This allows the module to reuse the exact same variation-discovery logic
        // used during live payment/subscription processing.
        $invoiceService = $this->di['mod_service']('Invoice');
        $adapter = $invoiceService->getGatewayAdapter('square');

        $products = $db->getAll("SELECT * FROM product ORDER BY id ASC");

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

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));

            if ($slug === '') {
                continue;
            }

            foreach ($billingKeys as $key) {
                $sku = $slug . '-' . $key;

                // In single mode, only evaluate the requested SKU.
                if ($singleMode && $sku !== $singleSku) {
                    continue;
                }

                // Reuse the payment adapter's exact resolution logic.
                // This keeps admin sync and runtime subscription mapping aligned.
                $variationId = $adapter->resolvePlanVariationId(
                    (int)$product['id'],
                    $key,
                    $sku
                );

                if ($variationId) {
                    $db->exec(
                        "REPLACE INTO square_product_plan_map
                        (product_id, billing_key, square_sku, square_plan_variation_id, updated_at)
                        VALUES (:pid, :bk, :sku, :vid, :updated)",
                        [
                            ':pid'     => $product['id'],
                            ':bk'      => $key,
                            ':sku'     => $sku,
                            ':vid'     => $variationId,
                            ':updated' => date('Y-m-d H:i:s'),
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
}