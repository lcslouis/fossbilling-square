<?php

namespace Box\Mod\Squaremanager;

use Pimple\Container;
use FOSSBilling\InjectionAwareInterface;

/**
 * Square Manager - Service
 *
 * Handles:
 * - Installation logic
 * - Deployment of adapter files
 * - Deployment of gateway logo
 *
 * IMPORTANT:
 * - Modules install to /modules/
 * - Payment adapters must be in /library/Payment/Adapter/
 * - Logos must be in /public/assets/gateways/
 *
 * This file bridges that gap automatically.
 */
class Service implements InjectionAwareInterface
{
    /**
     * Dependency container
     */
    protected Container $di;

    /**
     * Inject DI container
     */
    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    /**
     * Get DI container
     */
    public function getDi(): Container
    {
        return $this->di;
    }

    /**
     * Runs when module is activated
     */
    public function install(): bool
    {
        try {
            $this->deployAdapterFiles();
        } catch (\Throwable $e) {
            // Prevent install from failing completely
            error_log('[Squaremanager] Install error: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Deploy adapter + JS + logo into correct runtime locations
     */
    private function deployAdapterFiles(): void
    {
        // -------------------------
        // Source paths (inside module)
        // -------------------------
        $base = __DIR__ . '/installer_files/';

        $adapterSource = $base;
        $publicSource  = $base . 'public/assets/gateways/';

        // -------------------------
        // Target paths (FOSSBilling runtime)
        // -------------------------
        $adapterTarget = PATH_LIBRARY . '/Payment/Adapter/';
        $publicTarget  = PATH_ROOT . '/public/assets/gateways/';

        // Files to copy
        $adapterFiles = [
            'Square.php',
            'square-checkout.js',
        ];

        $logoFiles = [
            'square.png',
        ];

        // -------------------------
        // Ensure adapter directory exists
        // -------------------------
        if (!is_dir($adapterTarget)) {
            @mkdir($adapterTarget, 0755, true);
        }

        // -------------------------
        // Copy adapter files
        // -------------------------
        foreach ($adapterFiles as $file) {

            $source = $adapterSource . $file;
            $target = $adapterTarget . $file;

            if (file_exists($source)) {
                @copy($source, $target);
            }
        }

        // -------------------------
        // Ensure public directory exists
        // -------------------------
        if (!is_dir($publicTarget)) {
            @mkdir($publicTarget, 0755, true);
        }

        // -------------------------
        // Copy logo
        // -------------------------
        foreach ($logoFiles as $file) {

            $source = $publicSource . $file;
            $target = $publicTarget . $file;

            if (file_exists($source)) {
                @copy($source, $target);
            }
        }
    }

    /**
     * Optional: remove adapter files on uninstall
     */
    public function uninstall(): bool
    {
        $adapterTarget = PATH_LIBRARY . '/Payment/Adapter/';

        @unlink($adapterTarget . 'Square.php');
        @unlink($adapterTarget . 'square-checkout.js');

        return true;
    }
}