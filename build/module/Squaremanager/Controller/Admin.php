<?php

/**
 * Square Manager - Admin Controller
 *
 * Responsibilities:
 * - Register the Square Manager page in the FOSSBilling admin navigation
 * - Provide routes for:
 *   - Main admin UI page
 *   - Export endpoint (CSV download)
 *
 * Notes:
 * - This controller does NOT contain business logic
 * - Data logic belongs in Api/Admin.php
 * - UI rendering belongs in Twig templates
 */
class Squaremanager_Controller_Admin implements FOSSBilling\InjectionAwareInterface
{
    /**
     * FOSSBilling dependency injection container.
     *
     * @var Pimple\Container
     */
    protected $di;

    /**
     * Inject DI container.
     *
     * @param Pimple\Container $di
     */
    public function setDi(Pimple\Container $di)
    {
        $this->di = $di;
    }

    /**
     * Return DI container.
     *
     * @return Pimple\Container
     */
    public function getDi()
    {
        return $this->di;
    }

    /**
     * Register navigation menu item in admin panel.
     *
     * This adds:
     *
     *   System → Square Manager
     *
     * The route points to:
     *   /admin/squaremanager
     *
     * @param array $menu Existing admin menu structure
     *
     * @return array Modified menu structure
     */
    public function navigation($menu)
    {
        $menu['system']['children']['squaremanager'] = [
            'index' => [
                'label' => 'Square Manager',
                'uri'   => 'squaremanager',
                'class' => '',
                'order' => 90,
            ],
        ];

        return $menu;
    }

    /**
     * Main admin page route.
     *
     * URL:
     *   /admin/squaremanager
     *
     * This method:
     * - defines page metadata
     * - triggers Twig template rendering
     *
     * The actual UI content is rendered by:
     *   html_admin/index.html.twig
     *
     * @param mixed $api FOSSBilling admin API instance
     *
     * @return array Page context
     */
    public function get_index($api)
    {
        return [
            'page_title' => 'Square Manager',
        ];
    }

    /**
     * Export endpoint.
     *
     * URL:
     *   /admin/squaremanager/export
     *
     * This endpoint:
     * - generates a Square-compatible CSV export
     * - forces file download
     * - bypasses Twig rendering via exit
     *
     * @param mixed $api FOSSBilling admin API instance
     */
    public function get_export($api)
    {
        $this->exportProducts();

        // Prevent template rendering after output
        exit;
    }

    /**
     * Export FOSSBilling products into Square-compatible CSV format.
     *
     * Output includes:
     * - All products with valid slugs
     * - All supported billing periods
     * - Setup fee variations (only if > 0)
     *
     * SKU format:
     *   product-slug-billing
     *   product-slug-billing-setup
     *
     * Example:
     *   hosting-basic-monthly
     *   hosting-basic-monthly-setup
     *
     * Notes:
     * - Products without slugs are skipped
     * - Setup fee rows are only generated when amount > 0.00
     * - This export aligns exactly with the runtime lookup logic
     *   used by the payment adapter
     */
    private function exportProducts(): void
    {
        // --------------------------------------------------
        // HTTP Headers
        // --------------------------------------------------
        // Force browser to download CSV file
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="square_export.csv"');

        $output = fopen('php://output', 'w');

        // --------------------------------------------------
        // CSV Header Row
        // --------------------------------------------------
        fputcsv($output, [
            'Item Name',
            'Variation Name',
            'SKU',
            'Description',
            'Price',
            'Sellable',
            'Stockable'
        ]);

        $db = $this->di['db'];

        // Load all products
        $products = $db->getAll("SELECT * FROM product ORDER BY id ASC");

        foreach ($products as $product) {

            $slug = strtolower(trim((string)$product['slug'] ?? ''));

            // Products without slugs cannot be mapped to Square SKUs
            if ($slug === '') {
                continue;
            }

            $name = (string)$product['title'];
            $description = (string)($product['description'] ?? '');

            // --------------------------------------------------
            // Pricing per billing cycle
            // --------------------------------------------------
            $pricing = [
                'weekly'  => (float)($product['price_weekly'] ?? 0),
                'monthly' => (float)($product['price_monthly'] ?? 0),
                '3month'  => (float)($product['price_quarterly'] ?? 0),
                '6month'  => (float)($product['price_semi_annually'] ?? 0),
                'yearly'  => (float)($product['price_annually'] ?? 0),
                '2year'   => (float)($product['price_biennially'] ?? 0),
                '3year'   => (float)($product['price_triennially'] ?? 0),
            ];

            // --------------------------------------------------
            // Setup fees per billing cycle
            // --------------------------------------------------
            $setup = [
                'weekly'  => (float)($product['setup_weekly'] ?? 0),
                'monthly' => (float)($product['setup_monthly'] ?? 0),
                '3month'  => (float)($product['setup_quarterly'] ?? 0),
                '6month'  => (float)($product['setup_semi_annually'] ?? 0),
                'yearly'  => (float)($product['setup_annually'] ?? 0),
                '2year'   => (float)($product['setup_biennially'] ?? 0),
                '3year'   => (float)($product['setup_triennially'] ?? 0),
            ];

            foreach ($pricing as $key => $price) {

                // --------------------------------------------------
                // Recurring variation row
                // --------------------------------------------------
                if ($price > 0) {

                    $sku = $slug . '-' . $key;

                    fputcsv($output, [
                        $name,
                        ucfirst($key),
                        $sku,
                        $description,
                        number_format($price, 2, '.', ''),
                        'TRUE',
                        'FALSE'
                    ]);
                }

                // --------------------------------------------------
                // Setup fee variation row
                // --------------------------------------------------
                // Only generated if setup fee is greater than 0.00
                if (($setup[$key] ?? 0) > 0.00) {

                    $sku = $slug . '-' . $key . '-setup';

                    fputcsv($output, [
                        $name,
                        ucfirst($key) . ' Setup',
                        $sku,
                        $description . ' (Setup Fee)',
                        number_format($setup[$key], 2, '.', ''),
                        'TRUE',
                        'FALSE'
                    ]);
                }
            }
        }

        fclose($output);
    }
}