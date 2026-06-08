<?php
namespace Box\Mod\Squaremanager\Controller;

class Guest implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function register(\Box_App &$app): void
    {
        // No guest-facing routes required for this module.
        // Square webhook events are handled via the Guest API:
        //   POST https://<your-site>/api/guest/squaremanager/handle_webhook
    }
}
