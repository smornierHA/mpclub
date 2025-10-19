<?php
class MpclubCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public function initContent()
    {
        parent::initContent();
        header('Content-Type: text/plain; charset=utf-8');
        $token = Tools::getValue('token');
        if (!$token || $token !== (string)Configuration::get('MPC_CRON_TOKEN')) {
            header('HTTP/1.1 403 Forbidden'); die('forbidden');
        }
        if (class_exists('MpClubCronService')) { MpClubCronService::runDaily(); }
        die('OK');
    }
}
