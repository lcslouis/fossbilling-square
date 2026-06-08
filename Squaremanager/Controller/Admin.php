<?php
namespace Box\Mod\Squaremanager\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function register(\Box_App &$app): void
    {
        // FOSSBilling redirects to /admin/squaremanager after activation.
        // Send a permanent redirect to the canonical settings URL instead.
        $app->get('/squaremanager', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        header('Location: /admin/extension/settings/squaremanager', true, 301);
        exit;
    }
}
