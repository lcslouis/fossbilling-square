<?php

declare(strict_types=1);

namespace Box\Mod\Squaremanager\Controller;

use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;

class Admin implements InjectionAwareInterface
{
    protected ?Container $di = null;

    /**
     * Toggle this to false to disable controller debug logging.
     */
    private bool $debugEnabled = false;

    /**
     * Optional separate log file.
     * Leave blank to send logs to php_error.log.
     *
     * Example:
     *   /home/Username/logs/squaremanager-controller.log
     */
    private string $debugLogFile = '';

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    /**
     * Central debug logger for the controller.
     */
    private function logDebug(mixed $msg): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $prefix = '[SquareManagerController] ';
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

    public function fetchNavigation(): array
    {
        $this->logDebug('fetchNavigation() called');

        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => 'Square Manager',
                    'uri' => $this->di['url']->adminLink('squaremanager'),
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $this->logDebug('register() called');

        $app->get('/squaremanager', 'get_index', [], static::class);
        $app->get('/squaremanager/export', 'get_export', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->logDebug('get_index() called');

        $this->di['is_admin_logged'];

        $this->logDebug('get_index() rendering mod_squaremanager_index');

        return $app->render('mod_squaremanager_index');
    }
/**
     * Export the Square CSV file.
     *
     * This version includes:
     * - controller-level debug logging
     * - exact Square CSV header
     * - recurring rows
     * - setup-fee rows
     */
    public function get_export(\Box_App $app): void
    {
        $this->logDebug('get_export() called');

        $this->di['is_admin_logged'];

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="square_export.csv"');

        $output = fopen('php://output', 'w');

        if ($output === false) {
            $this->logDebug('❌ get_export() failed to open php://output');
            throw new \Exception('Unable to open CSV output stream');
        }

        // UTF-8 BOM for spreadsheet compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $header = [
            'Token',
            'Item Name',
            'Variation Name',
            'SKU',
            'Description',
            'Reporting Category',
            'Weight (lb)',
            'Social Media Link Title',
            'Social Media Link Description',
            'Price',
            'Online Sale Price',
            'Sellable',
            'Stockable',
            'Option Name 1',
            'Option Value 1',
            'Current Quantity Default Test Account',
            'New Quantity Default Test Account',
            'Stock Alert Enabled Default Test Account',
            'Stock Alert Count Default Test Account',
            'Item Type',
        ];

        $this->logDebug('get_export() writing header');
        $this->logDebug($header);

        fputcsv($output, $header);

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT * FROM product WHERE status = 'enabled' ORDER BY id ASC"
        );

        $this->logDebug('get_export() enabled products count=' . count($products));

        $pricingMap = [
            'weekly'  => [
                'price'   => 'w_price',
                'setup'   => 'w_setup_price',
                'enabled' => 'w_enabled',
                'label'   => 'Weekly',
            ],
            'monthly' => [
                'price'   => 'm_price',
                'setup'   => 'm_setup_price',
                'enabled' => 'm_enabled',
                'label'   => 'Monthly',
            ],
            '3month'  => [
                'price'   => 'q_price',
                'setup'   => 'q_setup_price',
                'enabled' => 'q_enabled',
                'label'   => 'Every 3 Months',
            ],
            '6month'  => [
                'price'   => 'b_price',
                'setup'   => 'b_setup_price',
                'enabled' => 'b_enabled',
                'label'   => 'Every 6 Months',
            ],
            'yearly'  => [
                'price'   => 'a_price',
                'setup'   => 'a_setup_price',
                'enabled' => 'a_enabled',
                'label'   => 'Yearly',
            ],
            '2year'   => [
                'price'   => 'bia_price',
                'setup'   => 'bia_setup_price',
                'enabled' => 'bia_enabled',
                'label'   => 'Every 2 Years',
            ],
            '3year'   => [
                'price'   => 'tria_price',
                'setup'   => 'tria_setup_price',
                'enabled' => 'tria_enabled',
                'label'   => 'Every 3 Years',
            ],
        ];
foreach ($products as $product) {
            $productName = trim((string)($product['title'] ?? ''));
            $slug = strtolower(trim((string)($product['slug'] ?? '')));
            $description = trim(strip_tags((string)($product['description'] ?? '')));

            if ($productName === '' || $slug === '') {
                $this->logDebug('get_export() skipped product due to missing name/slug:');
                $this->logDebug($product);
                continue;
            }

            $payment = $db->getRow(
                "SELECT * FROM product_payment WHERE id = :id",
                [':id' => $product['product_payment_id']]
            );

            if (!$payment) {
                $this->logDebug('get_export() no product_payment row found for product_id=' . (int)$product['id']);
                continue;
            }

            // Flatten description to one line for CSV compatibility
            $normalizedDescription = preg_replace('/\s+/u', ' ', $description) ?? '';
            $normalizedDescription = trim($normalizedDescription);

            foreach ($pricingMap as $key => $map) {
                $enabled = (int)($payment[$map['enabled']] ?? 0);
                $price   = (float)($payment[$map['price']] ?? 0);
                $setup   = (float)($payment[$map['setup']] ?? 0);
                $label   = $map['label'];

                $this->logDebug([
                    'get_export() candidate' => [
                        'product_id' => (int)$product['id'],
                        'product_title' => $productName,
                        'billing_key' => $key,
                        'enabled' => $enabled,
                        'price' => $price,
                        'setup' => $setup,
                    ],
                ]);

                if ($enabled !== 1) {
                    continue;
                }

                /**
                 * Recurring row
                 *
                 * All variations of the same item must have the same description in Square,
                 * so we do not append the billing period into the description.
                 */
                if ($price > 0) {
                    $recurringDescription = $normalizedDescription !== ''
                        ? $normalizedDescription
                        : $productName;

                    $row = [
                        '',                                 // Token
                        $productName,                       // Item Name
                        $label,                             // Variation Name
                        $slug . '-' . $key,                // SKU
                        $recurringDescription,              // Description
                        '',                                 // Reporting Category
                        '',                                 // Weight (lb)
                        '',                                 // Social Media Link Title
                        '',                                 // Social Media Link Description
                        number_format($price, 2, '.', ''), // Price
                        '',                                 // Online Sale Price
                        'TRUE',                             // Sellable
                        'FALSE',                            // Stockable
                        '',                                 // Option Name 1
                        '',                                 // Option Value 1
                        '',                                 // Current Quantity Default Test Account
                        '',                                 // New Quantity Default Test Account
                        '',                                 // Stock Alert Enabled Default Test Account
                        '',                                 // Stock Alert Count Default Test Account
                        'REGULAR',                          // Item Type
                    ];

                    $this->logDebug('get_export() writing recurring row:');
                    $this->logDebug($row);

                    fputcsv($output, $row);
                }
/**
                 * Setup fee row
                 *
                 * Setup fees are exported as separate one-time items.
                 */
                if ($setup > 0) {
                    $setupItemName = $productName . ' ' . $label . ' Setup Fee';
                    $setupDescription = $productName . ' Setup Fee';

                    $row = [
                        '',                                 // Token
                        $setupItemName,                     // Item Name
                        '',                                 // Variation Name (single item, no variation)
                        $slug . '-' . $key . '-setup',     // SKU
                        $setupDescription,                  // Description
                        '',                                 // Reporting Category
                        '',                                 // Weight (lb)
                        '',                                 // Social Media Link Title
                        '',                                 // Social Media Link Description
                        number_format($setup, 2, '.', ''), // Price
                        '',                                 // Online Sale Price
                        'TRUE',                             // Sellable
                        'FALSE',                            // Stockable
                        '',                                 // Option Name 1
                        '',                                 // Option Value 1
                        '',                                 // Current Quantity Default Test Account
                        '',                                 // New Quantity Default Test Account
                        '',                                 // Stock Alert Enabled Default Test Account
                        '',                                 // Stock Alert Count Default Test Account
                        'REGULAR',                          // Item Type
                    ];

                    $this->logDebug('get_export() writing setup row:');
                    $this->logDebug($row);

                    fputcsv($output, $row);
                }
            }
        }

        $this->logDebug('get_export() completed export successfully');

        fclose($output);
        exit;
    }
}	