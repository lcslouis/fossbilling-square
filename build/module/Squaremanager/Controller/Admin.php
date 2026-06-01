<?php

declare(strict_types=1);

namespace Box\Mod\Squaremanager\Controller;

use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;

class Admin implements InjectionAwareInterface
{
    protected ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => __trans('Square Manager'),
                    'index' => 2100,
                    'uri' => $this->di['url']->adminLink('squaremanager'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/squaremanager', 'get_index', [], static::class);
        $app->get('/squaremanager/export', 'get_export', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_squaremanager_index');
    }

    public function get_export(\Box_App $app): void
    {
        $this->di['is_admin_logged'];

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="square_export.csv"');

        $output = fopen('php://output', 'w');

        fputcsv($output, [
            'Item Name',
            'Variation Name',
            'SKU',
            'Description',
            'Price',
            'Sellable',
            'Stockable',
        ]);

        $db = $this->di['db'];

        $products = $db->getAll(
            "SELECT * FROM product WHERE status = 'enabled' ORDER BY id ASC"
        );

        foreach ($products as $product) {
            $slug = strtolower(trim((string)($product['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $productName = trim((string)($product['title'] ?? ''));
            $baseDescription = trim(strip_tags((string)($product['description'] ?? '')));

            $paymentId = $product['product_payment_id'] ?? null;
            if (empty($paymentId)) {
                continue;
            }

            $payment = $db->getRow(
                "SELECT * FROM product_payment WHERE id = :id LIMIT 1",
                [
                    ':id' => $paymentId,
                ]
            );

            if (!$payment) {
                continue;
            }

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

            foreach ($pricingMap as $key => $map) {
                $enabled = (int)($payment[$map['enabled']] ?? 0);
                $price   = (float)($payment[$map['price']] ?? 0);
                $setup   = (float)($payment[$map['setup']] ?? 0);
                $label   = $map['label'];

                if ($enabled !== 1) {
                    continue;
                }

                /**
                 * Recurring item row
                 *
                 * Item Name:
                 *   Basic Hosting
                 *
                 * Description:
                 *   Basic Hosting
                 *   10GB Storage
                 *   100GB Bandwidth
                 *   1 Domain
                 *
                 *   Monthly
                 */
                if ($price > 0) {
                    $recurringDescription = $productName;

                    if ($baseDescription !== '') {
                        $recurringDescription .= "\n" . $baseDescription;
                    }

                    $recurringDescription .= "\n\n" . $label;

                    fputcsv($output, [
                        $productName,
                        $label,
                        $slug . '-' . $key,
                        $recurringDescription,
                        number_format($price, 2, '.', ''),
                        'TRUE',
                        'FALSE',
                    ]);
                }

                /**
                 * Setup fee row
                 *
                 * Item Name:
                 *   Basic Hosting Monthly Setup Fee
                 *
                 * Description:
                 *   Basic Hosting
                 *   Monthly (Setup Fee)
                 */
                if ($setup > 0) {
                    $setupItemName = $productName . ' ' . $label . ' Setup Fee';
                    $setupDescription = $productName . "\n" . $label . ' (Setup Fee)';

                    fputcsv($output, [
                        $setupItemName,
                        $label . ' Setup',
                        $slug . '-' . $key . '-setup',
                        $setupDescription,
                        number_format($setup, 2, '.', ''),
                        'TRUE',
                        'FALSE',
                    ]);
                }
            }
        }

        fclose($output);
        exit;
    }
}