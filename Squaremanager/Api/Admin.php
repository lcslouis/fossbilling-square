<?php
/**
 * Squaremanager Admin API
 *
 * Method names are camelCase with NO underscores — FOSSBilling's module API
 * router splits method names on '_' after stripping the module prefix, so any
 * underscore in the method name would cause the call to be truncated.
 *
 * These endpoints support administration only. They do NOT execute payments.
 */
namespace Box\Mod\Squaremanager\Api;

class Admin extends \Api_Abstract
{
    // =========================================================================
    // Plan Mapping
    // =========================================================================

    /**
     * Alias kept for backward compatibility with any cached Twig templates
     * that call admin.squaremanager_mapping (Twig dot-notation strips _list).
     */
    public function mapping(array $data): array
    {
        return $this->get_mappings($data);
    }

    /** List all product → Square plan mappings. */
    public function get_mappings(array $data): array
    {
        $rows = $this->di['db']->getAll(
            'SELECT id, product_id, billing_period, sq_plan_id, sq_plan_label,
                    environment, notes, created_at, updated_at
             FROM square_plan_map
             ORDER BY created_at DESC'
        );

        return array_map(function (array $row): array {
            return [
                'id'             => (int)    $row['id'],
                'product_id'     => (int)    $row['product_id'],
                'billing_period' => (string) $row['billing_period'],
                'sq_plan_id'     => (string) $row['sq_plan_id'],
                'sq_plan_label'  => (string)($row['sq_plan_label'] ?? ''),
                'environment'    => (string)($row['environment']   ?? 'sandbox'),
                'notes'          => (string)($row['notes']         ?? ''),
                'created_at'     => (string)($row['created_at']    ?? ''),
                'updated_at'     => (string)($row['updated_at']    ?? ''),
            ];
        }, $rows ?: []);
    }

    /** Create a new plan mapping. */
    public function create_mapping(array $data): array
    {
        $productId     = (int)   ($data['product_id']    ?? 0);
        $billingPeriod = trim(    $data['billing_period'] ?? '');
        $sqPlanId      = trim(    $data['sq_plan_id']     ?? '');
        $sqPlanLabel   = trim(    $data['sq_plan_label']  ?? '');
        $notes         = trim(    $data['notes']          ?? '');
        $environment   = in_array($data['environment'] ?? '', ['sandbox', 'production'])
                         ? $data['environment'] : 'sandbox';

        if ($productId <= 0)       throw new \RuntimeException('product_id is required.');
        if (empty($billingPeriod)) throw new \RuntimeException('billing_period is required.');
        if (empty($sqPlanId))      throw new \RuntimeException('sq_plan_id is required.');

        // Verify product exists
        $productTitle = $this->di['db']->getCell(
            'SELECT title FROM product WHERE id = ? AND active = 1 LIMIT 1',
            [$productId]
        );
        if (!$productTitle) throw new \RuntimeException('Product not found or inactive: ' . $productId);

        // Duplicate check
        $existingId = $this->di['db']->getCell(
            'SELECT id FROM square_plan_map
             WHERE product_id = ? AND billing_period = ? AND environment = ? LIMIT 1',
            [$productId, $billingPeriod, $environment]
        );
        if ($existingId) throw new \RuntimeException('A mapping already exists for this product/period/environment.');

        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'INSERT INTO square_plan_map
             (product_id, billing_period, sq_plan_id, sq_plan_label, environment, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$productId, $billingPeriod, $sqPlanId, $sqPlanLabel, $environment, $notes, $now, $now]
        );

        $newId = (int) $this->di['db']->getCell('SELECT LAST_INSERT_ID()');
        return ['id' => $newId, 'created' => true];
    }

    /** Update an existing mapping. */
    public function update_mapping(array $data): bool
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new \RuntimeException('id is required.');

        $exists = $this->di['db']->getCell(
            'SELECT id FROM square_plan_map WHERE id = ? LIMIT 1', [$id]
        );
        if (!$exists) throw new \RuntimeException('Mapping not found: ' . $id);

        $sets   = [];
        $params = [];

        if (!empty($data['sq_plan_id']))     { $sets[] = 'sq_plan_id = ?';     $params[] = trim($data['sq_plan_id']); }
        if (isset($data['sq_plan_label']))   { $sets[] = 'sq_plan_label = ?';  $params[] = trim($data['sq_plan_label']); }
        if (!empty($data['billing_period'])) { $sets[] = 'billing_period = ?'; $params[] = trim($data['billing_period']); }
        if (isset($data['notes']))           { $sets[] = 'notes = ?';          $params[] = trim($data['notes']); }
        if (in_array($data['environment'] ?? '', ['sandbox', 'production'])) {
            $sets[]   = 'environment = ?';
            $params[] = $data['environment'];
        }

        if (empty($sets)) return true; // nothing to update

        $sets[]   = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;

        $this->di['db']->exec(
            'UPDATE square_plan_map SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return true;
    }

    /** Delete a mapping. */
    public function delete_mapping(array $data): bool
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new \RuntimeException('id is required.');

        $exists = $this->di['db']->getCell(
            'SELECT id FROM square_plan_map WHERE id = ? LIMIT 1', [$id]
        );
        if (!$exists) throw new \RuntimeException('Mapping not found: ' . $id);

        $this->di['db']->exec('DELETE FROM square_plan_map WHERE id = ?', [$id]);
        return true;
    }

    /** Export all mappings as a JSON-safe array. */
    public function export_mappings(array $data): array
    {
        return $this->get_mappings([]);
    }

    /** Import mappings; skips duplicates silently. */
    public function import_mappings(array $data): array
    {
        $items   = $data['mappings'] ?? [];
        $created = 0;
        $skipped = 0;

        foreach ($items as $item) {
            try {
                $this->create_mapping($item);
                $created++;
            } catch (\RuntimeException $e) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    // =========================================================================
    // Product Listing (with pricing from product_payment)
    // =========================================================================

    /**
     * Return active products with their recurring prices from product_payment.
     *
     * Price columns → FOSSBilling period labels:
     *   w_price   → Weekly        (WEEKLY)
     *   m_price   → Monthly       (MONTHLY)
     *   q_price   → Quarterly     (QUARTERLY)
     *   b_price   → Semi-annual   (SEMI_ANNUAL)
     *   a_price   → Annual        (ANNUAL)
     *   bia_price  → Every 2 yrs  (EVERY_TWO_YEARS)
     *   tria_price → Every 3 yrs  (triannual — no direct Square cadence)
     */
    public function get_products(array $data): array
    {
        $rows = $this->di['db']->getAll(
            'SELECT p.id, p.title, p.type, p.status,
                    pp.type          AS payment_type,
                    pp.once_price,
                    pp.w_price,      pp.w_enabled,
                    pp.m_price,      pp.m_enabled,
                    pp.q_price,      pp.q_enabled,
                    pp.b_price,      pp.b_enabled,
                    pp.a_price,      pp.a_enabled,
                    pp.bia_price,    pp.bia_enabled,
                    pp.tria_price,   pp.tria_enabled
             FROM product p
             LEFT JOIN product_payment pp ON pp.id = p.product_payment_id
             WHERE p.active = 1
             ORDER BY p.title ASC'
        );

        /* Map FOSSBilling payment period codes → human label + Square cadence hint */
        $periodMeta = [
            'w'    => ['label' => 'Wk',  'cadence' => 'WEEKLY'],
            'm'    => ['label' => 'Mo',  'cadence' => 'MONTHLY'],
            'q'    => ['label' => 'Qtr', 'cadence' => 'QUARTERLY'],
            'b'    => ['label' => '6Mo', 'cadence' => 'SEMI_ANNUAL'],
            'a'    => ['label' => 'Yr',  'cadence' => 'ANNUAL'],
            'bia'  => ['label' => '2Yr', 'cadence' => 'EVERY_TWO_YEARS'],
            'tria' => ['label' => '3Yr', 'cadence' => null],
        ];

        $result = [];
        foreach ($rows as $row) {
            $prices = [];
            foreach ($periodMeta as $code => $meta) {
                $priceCol   = $code . '_price';
                $enabledCol = $code . '_enabled';
                if (!empty($row[$enabledCol]) && isset($row[$priceCol]) && (float)$row[$priceCol] > 0) {
                    $prices[] = [
                        'period'  => $code,
                        'label'   => $meta['label'],
                        'cadence' => $meta['cadence'],
                        'price'   => (float) $row[$priceCol],
                    ];
                }
            }

            $result[] = [
                'id'           => (int)   $row['id'],
                'title'        => (string)($row['title'] ?? ''),
                'type'         => (string)($row['type']  ?? ''),
                'status'       => (string)($row['status'] ?? ''),
                'payment_type' => (string)($row['payment_type'] ?? ''),
                'once_price'   => isset($row['once_price']) ? (float) $row['once_price'] : null,
                'prices'       => $prices,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Subscription Inspection
    // =========================================================================

    /** List subscription records with optional client_id / status filters. */
    public function get_subscriptions(array $data): array
    {
        $where  = ['1'];
        $params = [];

        if (!empty($data['client_id'])) {
            $where[]  = 'client_id = ?';
            $params[] = (int) $data['client_id'];
        }
        if (!empty($data['status'])) {
            $where[]  = 'status = ?';
            $params[] = $data['status'];
        }

        $sql  = 'SELECT * FROM square_subscription WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
        $rows = $this->di['db']->getAll($sql, $params);

        return array_map([$this, '_subRow'], $rows ?: []);
    }

    /** Get a single subscription record by id. */
    public function get_subscription(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new \RuntimeException('id is required.');

        $rows = $this->di['db']->getAll(
            'SELECT * FROM square_subscription WHERE id = ? LIMIT 1', [$id]
        );
        if (empty($rows)) throw new \RuntimeException('Subscription record not found: ' . $id);

        return $this->_subRow($rows[0]);
    }

    /**
     * List all subscriptions joined with FOSSBilling's native subscription table
     * so both statuses can be compared side-by-side.
     */
    public function list_subscriptions_sync(array $data): array
    {
        $rows = $this->di['db']->getAll(
            'SELECT ss.*,
                    c.first_name, c.last_name, c.email,
                    s.status AS foss_status,
                    s.period AS foss_period,
                    s.id     AS foss_sub_id
             FROM square_subscription ss
             LEFT JOIN client c ON c.id = ss.client_id
             LEFT JOIN subscription s ON s.sid = ss.sq_subscription_id
             ORDER BY ss.created_at DESC'
        );

        return array_map(function (array $row): array {
            $sqStatus   = (string) ($row['status']      ?? '');
            $fossStatus = isset($row['foss_status']) ? (string) $row['foss_status'] : null;
            $mismatch   = $fossStatus !== null && $fossStatus !== $sqStatus;

            return [
                'id'                 => (int)    $row['id'],
                'client_id'          => (int)    $row['client_id'],
                'client_name'        => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'client_email'       => (string)($row['email']            ?? ''),
                'invoice_id'         => (int)    $row['invoice_id'],
                'currency'           => (string) $row['currency'],
                'amount'             => (float)  $row['amount'],
                'sq_subscription_id' => (string) $row['sq_subscription_id'],
                'sq_status'          => $sqStatus,
                'foss_sub_id'        => $row['foss_sub_id'] ? (int) $row['foss_sub_id'] : null,
                'foss_status'        => $fossStatus,
                'foss_period'        => (string)($row['foss_period'] ?? ''),
                'status_mismatch'    => $mismatch,
                'created_at'         => (string)($row['created_at'] ?? ''),
            ];
        }, $rows ?: []);
    }

    /**
     * Delete a subscription record from both square_subscription and FOSSBilling's
     * subscription table. Only allowed for non-active records (canceled, pending, etc.)
     * to prevent accidental deletion of live billing subscriptions.
     * Pass force=1 to delete regardless of status.
     */
    public function delete_subscription(array $data): bool
    {
        $id    = (int)  ($data['id']    ?? 0);
        $force = (bool) ($data['force'] ?? false);
        if ($id <= 0) throw new \RuntimeException('id is required.');

        $rows = $this->di['db']->getAll(
            'SELECT * FROM square_subscription WHERE id = ? LIMIT 1', [$id]
        );
        if (empty($rows)) throw new \RuntimeException('Subscription record not found: ' . $id);
        $row = $rows[0];

        if (!$force && $row['status'] === 'active') {
            throw new \RuntimeException('Cannot delete an active subscription. Cancel it in Square first, sync, then delete.');
        }

        $sqId = $row['sq_subscription_id'];
        $now  = date('Y-m-d H:i:s');

        $this->di['db']->exec('DELETE FROM square_subscription WHERE id = ?', [$id]);
        $this->di['db']->exec('DELETE FROM subscription WHERE sid = ?', [$sqId]);

        error_log('[Squaremanager] deleted subscription record id=' . $id . ' sq_id=' . $sqId);
        return true;
    }

    /** Cancel a subscription in Square and sync both local tables to 'canceled'. */
    public function cancel_subscription(array $data): bool
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new \RuntimeException('id is required.');

        $rows = $this->di['db']->getAll(
            'SELECT * FROM square_subscription WHERE id = ? LIMIT 1', [$id]
        );
        if (empty($rows)) throw new \RuntimeException('Subscription record not found: ' . $id);
        $row = $rows[0];

        if ($row['status'] === 'canceled') throw new \RuntimeException('Subscription is already canceled.');

        $adapter = $this->_loadAdapterFromConfig();
        $adapter->cancelSubscription($row['sq_subscription_id']);

        return true;
    }

    /** Pull the latest status from Square and update both local tables. */
    public function sync_subscription(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new \RuntimeException('id is required.');

        $rows = $this->di['db']->getAll(
            'SELECT * FROM square_subscription WHERE id = ? LIMIT 1', [$id]
        );
        if (empty($rows)) throw new \RuntimeException('Subscription record not found: ' . $id);
        $row = $rows[0];

        $adapter = $this->_loadAdapterFromConfig();
        return $adapter->syncSubscriptionStatus($row['sq_subscription_id']);
    }

    /**
     * Sync all non-canceled subscriptions from Square in one pass.
     * Returns a summary: synced count, error count, and per-record results.
     */
    public function sync_all_subscriptions(array $data): array
    {
        $rows = $this->di['db']->getAll(
            "SELECT sq_subscription_id FROM square_subscription
             WHERE status NOT IN ('canceled', 'deactivated')"
        );

        $adapter = $this->_loadAdapterFromConfig();
        $synced  = 0;
        $errors  = 0;
        $results = [];

        foreach ($rows as $row) {
            $sqId = $row['sq_subscription_id'];
            try {
                $r       = $adapter->syncSubscriptionStatus($sqId);
                $results[] = $r;
                $synced++;
            } catch (\Throwable $e) {
                $errors++;
                $results[] = ['sq_subscription_id' => $sqId, 'error' => $e->getMessage()];
                error_log('[Squaremanager] sync_all error for ' . $sqId . ': ' . $e->getMessage());
            }
        }

        return ['synced' => $synced, 'errors' => $errors, 'results' => $results];
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    /** Validate gateway configuration and return a status summary. */
    public function validate_config(array $data): array
    {
        $gatewayId = (int) ($data['gateway_id'] ?? 0);

        $gw = $gatewayId > 0
            ? $this->di['db']->load('PayGateway', $gatewayId)
            : $this->di['db']->findOne('PayGateway', 'name = ?', ['Square']);

        if (!$gw) {
            return [
                'valid'         => false,
                'issues'        => ['Square gateway not found. Add it under Payment Gateways first.'],
                'environment'   => 'unknown',
                'mapping_count' => 0,
                'summary'       => 'Gateway not configured.',
            ];
        }

        $config = json_decode($gw->config ?? '{}', true) ?: [];
        $issues = [];

        foreach (['access_token', 'application_id', 'location_id'] as $field) {
            if (empty($config[$field])) {
                $issues[] = 'Missing required field: ' . $field;
            }
        }

        $mappingCount = (int) $this->di['db']->getCell('SELECT COUNT(*) FROM square_plan_map');

        return [
            'valid'         => empty($issues),
            'issues'        => $issues,
            'environment'   => $config['environment'] ?? 'sandbox',
            'mapping_count' => $mappingCount,
            'gateway_id'    => (int) $gw->id,
            'summary'       => empty($issues) ? 'Configuration looks valid.' : 'Configuration has issues.',
        ];
    }

    // =========================================================================
    // Debug / Diagnostics
    // =========================================================================

    /**
     * Return a detailed diagnostic snapshot: gateway config (keys masked),
     * table row counts, PHP/FOSSBilling version, and a live API ping.
     */
    public function debug_info(array $data): array
    {
        $gw     = $this->di['db']->findOne('PayGateway', 'name = ?', ['Square']);
        $config = $gw ? (json_decode($gw->config ?? '{}', true) ?: []) : [];

        // Mask secrets — show only the first 6 chars
        $masked = [];
        foreach ($config as $k => $v) {
            $masked[$k] = in_array($k, ['access_token', 'application_secret'], true)
                ? (strlen((string) $v) > 6 ? substr($v, 0, 6) . '…[masked]' : '(empty)')
                : $v;
        }

        // Live API ping
        $pingOk  = false;
        $pingErr = '';
        try {
            if ($gw) {
                $adapter = $this->_loadAdapterFromConfig();
                $adapter->squareRequest('GET', '/v2/locations', []);
                $pingOk = true;
            } else {
                $pingErr = 'Gateway not configured.';
            }
        } catch (\Throwable $e) {
            $pingErr = $e->getMessage();
        }

        return [
            'php_version'         => PHP_VERSION,
            'fossbilling_version' => defined('BB_VERSION') ? BB_VERSION : 'unknown',
            'gateway_id'          => $gw ? (int) $gw->id : null,
            'gateway_name'        => $gw ? $gw->name : null,
            'config_masked'       => $masked,
            'table_counts'        => [
                'sq_plan_map'     => (int) $this->di['db']->getCell('SELECT COUNT(*) FROM square_plan_map'),
                'sq_subscription' => (int) $this->di['db']->getCell('SELECT COUNT(*) FROM square_subscription'),
            ],
            'api_ping'            => ['ok' => $pingOk, 'error' => $pingErr],
        ];
    }

    // =========================================================================
    // Square Plan Variation Creator
    // =========================================================================

    /**
     * Create a STATIC-pricing subscription plan variation via the Square Catalog API.
     *
     * Square's Dashboard forces RELATIVE pricing whenever catalog items are linked.
     * This method bypasses that limitation by calling the Catalog API directly.
     *
     * Required: plan_id, cadence (e.g. MONTHLY), amount (e.g. 15.75), currency (e.g. USD)
     * Optional: name (defaults to "{cadence} - {currency}{amount}")
     */
    public function create_static_variation(array $data): array
    {
        $planId   = trim($data['plan_id']  ?? '');
        $cadence  = strtoupper(trim($data['cadence']  ?? ''));
        $amount   = (float) ($data['amount']   ?? 0);
        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        $name     = trim($data['name'] ?? '');

        if (empty($planId))  throw new \RuntimeException('plan_id is required.');
        if (empty($cadence)) throw new \RuntimeException('cadence is required (e.g. MONTHLY, ANNUAL).');
        if ($amount <= 0)    throw new \RuntimeException('amount must be greater than 0.');

        $validCadences = [
            'DAILY', 'WEEKLY', 'EVERY_TWO_WEEKS', 'THIRTY_DAYS', 'SIXTY_DAYS',
            'NINETY_DAYS', 'MONTHLY', 'EVERY_TWO_MONTHS', 'QUARTERLY',
            'EVERY_FOUR_MONTHS', 'EVERY_SIX_MONTHS', 'ANNUAL', 'EVERY_TWO_YEARS',
        ];
        if (!in_array($cadence, $validCadences, true)) {
            throw new \RuntimeException('Invalid cadence: ' . $cadence);
        }

        if (empty($name)) {
            $name = $cadence . ' - ' . $currency . number_format($amount, 2);
        }

        $adapter      = $this->_loadAdapterFromConfig();
        $amountCents  = (int) round($amount * 100);
        $idempotencyKey = 'fossbilling-static-var-' . md5($planId . $cadence . $amountCents . $currency . time());

        $response = $adapter->squareRequest('POST', '/v2/catalog/batch-upsert', [
            'idempotency_key' => $idempotencyKey,
            'batches' => [[
                'objects' => [[
                    'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                    'id'   => '#new_static_variation',
                    'present_at_all_locations' => true,
                    'subscription_plan_variation_data' => [
                        'name'                 => $name,
                        'subscription_plan_id' => $planId,
                        'phases' => [[
                            'ordinal' => 0,
                            'cadence' => $cadence,
                            'pricing' => [
                                'type'        => 'STATIC',
                                'price_money' => [
                                    'amount'   => $amountCents,
                                    'currency' => $currency,
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $objects    = $response['objects'] ?? $response['id_mappings'] ?? [];
        $newId      = null;
        $idMappings = $response['id_mappings'] ?? [];
        foreach ($idMappings as $m) {
            if (($m['client_object_id'] ?? '') === '#new_static_variation') {
                $newId = $m['object_id'] ?? null;
                break;
            }
        }

        // Fallback: find it in returned objects
        if (!$newId) {
            foreach ($response['objects'] ?? [] as $o) {
                if (($o['type'] ?? '') === 'SUBSCRIPTION_PLAN_VARIATION') {
                    $newId = $o['id'] ?? null;
                    break;
                }
            }
        }

        return [
            'success'      => true,
            'variation_id' => $newId,
            'name'         => $name,
            'cadence'      => $cadence,
            'amount'       => $amount,
            'currency'     => $currency,
            'message'      => $newId
                ? 'Variation created. ID: ' . $newId . ' — update your plan mapping to use this ID.'
                : 'Variation created but ID not found in response. Check Square dashboard.',
        ];
    }

    // =========================================================================
    // Square Catalog Object Deletion
    // =========================================================================

    /**
     * Delete (or archive) a Square catalog object (plan or variation) by ID.
     *
     * Square refuses to delete individual variations that are referenced by a parent plan.
     * Strategy:
     *   1. Try batch-delete (handles cascading references better than single DELETE).
     *   2. If that fails, archive the object by setting present_at_all_locations=false
     *      and fetching its current version first.
     *   Archiving hides it from all locations and from our plan browser (which filters
     *   by present_at_all_locations=true), which is functionally equivalent for our use case.
     */
    public function delete_square_object(array $data): array
    {
        $objectId = trim($data['object_id'] ?? '');
        if (empty($objectId)) throw new \RuntimeException('object_id is required.');

        $adapter = $this->_loadAdapterFromConfig();

        // Attempt 1: batch-delete (cascades child references)
        try {
            $adapter->squareRequest('POST', '/v2/catalog/batch-delete', [
                'object_ids' => [$objectId],
            ]);
            return [
                'success'    => true,
                'method'     => 'deleted',
                'deleted_id' => $objectId,
                'message'    => 'Object ' . $objectId . ' deleted from Square catalog.',
            ];
        } catch (\Throwable $deleteErr) {
            error_log('[Squaremanager] batch-delete failed for ' . $objectId . ': ' . $deleteErr->getMessage() . ' — trying archive.');
        }

        // Attempt 2: archive — fetch current version, then upsert with present_at_all_locations=false
        $current = $adapter->squareRequest('GET', '/v2/catalog/object/' . $objectId);
        $obj     = $current['object'] ?? null;
        if (!$obj) {
            throw new \RuntimeException('Could not fetch object to archive: ' . $objectId);
        }

        $version = $obj['version'] ?? 1;
        $type    = $obj['type']    ?? 'UNKNOWN';

        $upsertObj = [
            'type'                      => $type,
            'id'                        => $objectId,
            'version'                   => $version,
            'present_at_all_locations'  => false,
            'present_at_location_ids'   => [],
        ];

        // Carry over the type-specific data so the upsert doesn't strip it
        $dataKey = match ($type) {
            'SUBSCRIPTION_PLAN_VARIATION' => 'subscription_plan_variation_data',
            'SUBSCRIPTION_PLAN'           => 'subscription_plan_data',
            'ITEM'                        => 'item_data',
            'ITEM_VARIATION'              => 'item_variation_data',
            default                       => null,
        };
        if ($dataKey && isset($obj[$dataKey])) {
            $upsertObj[$dataKey] = $obj[$dataKey];
        }

        $idempotencyKey = 'fossbilling-archive-' . md5($objectId . time());
        $adapter->squareRequest('POST', '/v2/catalog/batch-upsert', [
            'idempotency_key' => $idempotencyKey,
            'batches'         => [['objects' => [$upsertObj]]],
        ]);

        return [
            'success'    => true,
            'method'     => 'archived',
            'deleted_id' => $objectId,
            'message'    => 'Object ' . $objectId . ' archived (hidden from all locations). Square does not allow deleting variations that are part of a plan — archiving achieves the same result for this integration.',
        ];
    }

    // =========================================================================
    // Square Object Inspection
    // =========================================================================

    /**
     * Fetch subscription plans from the Square Catalog API.
     * Lets admins browse real plan IDs when configuring mappings.
     */
    public function list_square_objects(array $data): array
    {
        $adapter = $this->_loadAdapterFromConfig();

        // Catalog list is paginated — walk all pages so nothing is missed
        $allObjects = [];
        $cursor     = null;
        do {
            $url = '/v2/catalog/list?types=' . urlencode('SUBSCRIPTION_PLAN,SUBSCRIPTION_PLAN_VARIATION');
            if ($cursor) {
                $url .= '&cursor=' . urlencode($cursor);
            }
            $page   = $adapter->squareRequest('GET', $url, []);
            foreach ($page['objects'] ?? [] as $o) {
                $allObjects[] = $o;
            }
            $cursor = $page['cursor'] ?? null;
        } while ($cursor);

        // Active = not deleted AND present_at_all_locations === true
        $isActive = static function (array $obj): bool {
            if ($obj['is_deleted'] ?? false) return false;
            if (!($obj['present_at_all_locations'] ?? true)) return false;
            return true;
        };

        // Index ALL variation objects by ID — do NOT filter by present_at_all_locations here.
        // Variations may have that flag false while still belonging to an active plan;
        // we need their data (cadence etc.) regardless.
        $varIndex = [];
        foreach ($allObjects as $obj) {
            if (($obj['type'] ?? '') !== 'SUBSCRIPTION_PLAN_VARIATION') continue;
            if ($obj['is_deleted'] ?? false) continue;
            $varIndex[$obj['id']] = $obj;
        }

        // Human-readable cadence labels
        $cadenceLabel = [
            'DAILY'             => 'Daily',
            'WEEKLY'            => 'Weekly',
            'EVERY_TWO_WEEKS'   => 'Every 2 weeks',
            'THIRTY_DAYS'       => 'Every 30 days',
            'SIXTY_DAYS'        => 'Every 60 days',
            'NINETY_DAYS'       => 'Every 90 days',
            'MONTHLY'           => 'Monthly',
            'EVERY_TWO_MONTHS'  => 'Every 2 months',
            'QUARTERLY'         => 'Quarterly',
            'EVERY_FOUR_MONTHS' => 'Every 4 months',
            'EVERY_SIX_MONTHS'  => 'Every 6 months',
            'ANNUAL'            => 'Annual',
            'EVERY_TWO_YEARS'   => 'Every 2 years',
        ];

        $result = [];
        foreach ($allObjects as $obj) {
            if (($obj['type'] ?? '') !== 'SUBSCRIPTION_PLAN') continue;
            if (!$isActive($obj)) continue;

            $planName   = $obj['subscription_plan_data']['name'] ?? $obj['id'];
            $planPhases = $obj['subscription_plan_data']['phases'] ?? [];
            $variations = [];

            // New-style (2022+): plan lists variation IDs in subscription_plan_variations
            foreach ($obj['subscription_plan_data']['subscription_plan_variations'] ?? [] as $ref) {
                $varId   = $ref['id'] ?? '';
                if (empty($varId)) continue;

                $varData = isset($varIndex[$varId])
                    ? ($varIndex[$varId]['subscription_plan_variation_data'] ?? [])
                    : [];

                $phase      = $varData['phases'][0] ?? $planPhases[0] ?? [];
                $rawCadence = $phase['cadence'] ?? '';

                $variations[] = [
                    'id'      => $varId,
                    'name'    => $varData['name'] ?? $varId,
                    'cadence' => $cadenceLabel[$rawCadence] ?? $rawCadence,
                ];
            }

            // Old-style fallback: cadence is on the plan phases directly
            if (empty($variations)) {
                foreach ($planPhases as $phase) {
                    $uid = $phase['uid'] ?? '';
                    if (empty($uid)) continue;
                    $rawCadence = $phase['cadence'] ?? '';
                    $variations[] = [
                        'id'      => $uid,
                        'name'    => $cadenceLabel[$rawCadence] ?? $rawCadence ?: $uid,
                        'cadence' => $cadenceLabel[$rawCadence] ?? $rawCadence,
                    ];
                }
            }

            if (empty($variations)) continue;

            $result[] = [
                'id'         => $obj['id'],
                'name'       => $planName,
                'variations' => $variations,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function _subRow(array $row): array
    {
        return [
            'id'                 => (int)    $row['id'],
            'client_id'          => (int)    $row['client_id'],
            'invoice_id'         => (int)    $row['invoice_id'],
            'gateway_id'         => (int)    $row['gateway_id'],
            'currency'           => (string) $row['currency'],
            'amount'             => (float)  $row['amount'],
            'sq_subscription_id' => (string) $row['sq_subscription_id'],
            'sq_customer_id'     => (string)($row['sq_customer_id'] ?? ''),
            'sq_card_id'         => (string)($row['sq_card_id']     ?? ''),
            'status'             => (string) $row['status'],
            'created_at'         => (string)($row['created_at']     ?? ''),
            'updated_at'         => (string)($row['updated_at']     ?? ''),
        ];
    }

    private function _loadAdapter(int $gatewayId): \Payment_Adapter_Square
    {
        $gw = $this->di['db']->load('PayGateway', $gatewayId);
        if (!$gw) throw new \RuntimeException('Gateway not found: ' . $gatewayId);

        $config  = json_decode($gw->config ?? '{}', true) ?: [];
        $adapter = new \Payment_Adapter_Square($config);
        $adapter->setDi($this->di);

        return $adapter;
    }

    private function _loadAdapterFromConfig(): \Payment_Adapter_Square
    {
        $gw = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Square']);
        if (!$gw) throw new \RuntimeException('Square gateway is not configured.');

        $config  = json_decode($gw->config ?? '{}', true) ?: [];
        $adapter = new \Payment_Adapter_Square($config);
        $adapter->setDi($this->di);

        return $adapter;
    }
}
