<?php
/**
 * Squaremanager Service
 *
 * Handles installation, file deployment, and shared helper utilities.
 * Does NOT execute payments.
 */
namespace Box\Mod\Squaremanager;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    // -------------------------------------------------------------------------
    // Module Permissions — required for the Settings button to appear
    // -------------------------------------------------------------------------

    public function getModulePermissions(): array
    {
        return [
            'manage_settings' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Installer
    // -------------------------------------------------------------------------

    public function install(): void
    {
        $sqlFile = __DIR__ . '/sql/install.sql';
        if (!file_exists($sqlFile)) {
            throw new \RuntimeException('Squaremanager: install.sql not found.');
        }

        $sql        = file_get_contents($sqlFile);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s)
        );

        foreach ($statements as $stmt) {
            try {
                $this->di['db']->exec($stmt);
            } catch (\Throwable $e) {
                if (stripos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }

        $this->_deployAdapter();
    }

    public function uninstall(): void
    {
        // Preserve data — admin must clean tables manually if desired.
    }

    public function update(string $currentVersion): void
    {
        // Future: run incremental migration SQL files based on $currentVersion.
    }

    // -------------------------------------------------------------------------
    // Adapter Deployment
    // -------------------------------------------------------------------------

    private function _deployAdapter(): void
    {
        $root = realpath(__DIR__ . '/../../') . '/';

        $this->_copyFile(
            __DIR__ . '/assets/Square.php',
            $root . 'library/Payment/Adapter/Square.php',
            'adapter'
        );

        $this->_copyFile(
            __DIR__ . '/assets/square.png',
            $root . 'public/assets/gateways/square.png',
            'logo'
        );
    }

    private function _copyFile(string $src, string $dest, string $label): void
    {
        if (!file_exists($src)) {
            error_log('[Squaremanager] ' . $label . ' source not found at ' . $src . ', skipping.');
            return;
        }

        $destDir = dirname($dest);

        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException('Squaremanager: could not create directory for ' . $label . ': ' . $destDir);
            }
        }

        if (!is_writable($destDir)) {
            throw new \RuntimeException('Squaremanager: destination not writable for ' . $label . ': ' . $destDir);
        }

        if (!copy($src, $dest)) {
            throw new \RuntimeException('Squaremanager: failed to copy ' . $label . ' to ' . $dest);
        }

        error_log('[Squaremanager] deployed ' . $label . ' → ' . $dest);
    }

    // -------------------------------------------------------------------------
    // Mapping Helpers
    // -------------------------------------------------------------------------

    public function getPlanId(int $productId, string $billingPeriod, string $environment = 'production'): ?string
    {
        try {
            $row = $this->di['db']->findOne(
                'SquarePlanMap',
                'product_id = ? AND billing_period = ? AND environment = ?',
                [$productId, $billingPeriod, $environment]
            );
            return $row ? (string) $row->sq_plan_id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Subscription Helpers
    // -------------------------------------------------------------------------

    public function getSubscriptionBySquareId(string $squareSubId): ?object
    {
        try {
            return $this->di['db']->findOne(
                'SquareSubscription',
                'sq_subscription_id = ?',
                [$squareSubId]
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function updateSubscriptionStatus(string $squareSubId, string $status): void
    {
        $record = $this->getSubscriptionBySquareId($squareSubId);
        if (!$record) return;

        $record->status     = $status;
        $record->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($record);
    }
}
