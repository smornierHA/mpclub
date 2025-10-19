<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once _PS_MODULE_DIR_.'mpclub/classes/MpClubMembership.php';
require_once _PS_MODULE_DIR_.'mpclub/classes/MpClubRuleService.php';
require_once _PS_MODULE_DIR_.'mpclub/classes/MpClubCronService.php';

class Mpclub extends Module
{
    const CONFIG_KEYS = array(
        'MPC_PRODUCT_SILVER','MPC_PRODUCT_GOLD','MPC_PRODUCT_PLATINUM',
        'MPC_WELCOME_SILVER','MPC_WELCOME_GOLD','MPC_WELCOME_PLATINUM',
        'MPC_PERCENT_SILVER','MPC_PERCENT_GOLD','MPC_PERCENT_PLATINUM',
        'MPC_FREE_SHIPPING','MPC_ORDER_TAG','MPC_STAFF_BDAY_EMAIL',
        'MPC_CRON_TOKEN','MPC_PAID_STATES',
    );

    public function __construct()
    {
        $this->name = 'mpclub';
        $this->tab = 'front_office_features';
        $this->version = '1.2.2';
        $this->author = 'MS consulting';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
        parent::__construct();
        $this->displayName = $this->l('Club Maison Perrotte');
        $this->description = $this->l('Adhésions, avantages et automatisations du Club (Argent, Or, Platine).');
    }

    public function install()
    {
        try {
            if (!parent::install()) { throw new Exception('parent::install failed'); }
            $this->installSql();

            Configuration::updateValue('MPC_WELCOME_SILVER', 5.0);
            Configuration::updateValue('MPC_WELCOME_GOLD', 7.0);
            Configuration::updateValue('MPC_WELCOME_PLATINUM', 10.0);
            Configuration::updateValue('MPC_PERCENT_SILVER', 5.0);
            Configuration::updateValue('MPC_PERCENT_GOLD', 7.0);
            Configuration::updateValue('MPC_PERCENT_PLATINUM', 10.0);
            Configuration::updateValue('MPC_FREE_SHIPPING', 1);
            Configuration::updateValue('MPC_ORDER_TAG', 'Prioritaire – Club Maison Perrotte');
            Configuration::updateValue('MPC_STAFF_BDAY_EMAIL', 'stephan@maisonperrotte.fr');
            if (!Configuration::get('MPC_CRON_TOKEN')) {
                Configuration::updateValue('MPC_CRON_TOKEN', Tools::substr(sha1(mt_rand()), 0, 16));
            }

            $paidStates = array();
            foreach (OrderState::getOrderStates((int)Context::getContext()->language->id) as $st) {
                if (!empty($st['paid'])) { $paidStates[] = (int)$st['id_order_state']; }
            }
            if (!empty($paidStates)) {
                Configuration::updateValue('MPC_PAID_STATES', implode(',', $paidStates));
            }

            foreach (array(
                'actionOrderStatusPostUpdate','displayCustomerAccount','moduleRoutes',
                'actionFrontControllerSetMedia','actionAdminCustomersFormModifier',
                'displayPDFInvoice','actionObjectCustomerDeleteAfter','actionCartSave',
                'displayShoppingCart'
            ) as $h) { try { $this->registerHook($h); } catch (Exception $e) {} }
            if ((int)Hook::getIdByName('actionCronJob') > 0) { try { $this->registerHook('actionCronJob'); } catch (Exception $e) {} }

            return true;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('mpclub install error: '.$e->getMessage(), 3);
            return true;
        }
    }

    public function uninstall()
    {
        foreach (self::CONFIG_KEYS as $k) { Configuration::deleteByName($k); }
        return parent::uninstall();
    }

    private function installSql()
    {
        $sql1 = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'mpclub_membership` (
            `id_mpclub_membership` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `id_customer` INT UNSIGNED NOT NULL,
            `level` VARCHAR(16) NOT NULL,
            `date_start` DATETIME NOT NULL,
            `date_end` DATETIME NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `id_order` INT UNSIGNED NULL,
            `id_product` INT UNSIGNED NULL,
            `id_cart_rule_ongoing` INT UNSIGNED NULL,
            `id_cart_rule_welcome` INT UNSIGNED NULL,
            `last_birthday_probe` DATE NULL,
            `last_renewal_probe` DATE NULL,
            `date_add` DATETIME NULL,
            `date_upd` DATETIME NULL,
            UNIQUE KEY (`id_customer`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

        $sql2 = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'mpclub_logs` (
            `id_mpclub_logs` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `id_customer` INT UNSIGNED NOT NULL,
            `event` VARCHAR(64) NOT NULL,
            `payload` TEXT NULL,
            `date_add` DATETIME NOT NULL
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;';

        Db::getInstance()->execute($sql1);
        Db::getInstance()->execute($sql2);
    }

    public function hookModuleRoutes($params)
    {
        return array(
            'module-mpclub-club'  => array('controller' => 'club',  'rule' => 'club-maison-perrotte', 'keywords' => array(), 'params' => array('fc' => 'module', 'module' => $this->name)),
            'module-mpclub-renew' => array('controller' => 'renew', 'rule' => 'club-renouveler',       'keywords' => array(), 'params' => array('fc' => 'module', 'module' => $this->name)),
            'module-mpclub-cron'  => array('controller' => 'cron',  'rule' => 'mpclub-cron',           'keywords' => array(), 'params' => array('fc' => 'module', 'module' => $this->name)),
        );
    }

    public function hookDisplayCustomerAccount($params) { return $this->fetch('module:mpclub/views/templates/hook/customer_account_link.tpl'); }

    public function hookActionFrontControllerSetMedia($params)
    {
        $controller = Context::getContext()->controller;
        if ($controller && $controller->php_self === 'module-mpclub-club') {
            $this->registerStylesheet('mpclub-front', 'modules/'.$this->name.'/views/css/front.css', array('media' => 'all', 'priority' => 50));
        }
    }

    public function hookActionAdminCustomersFormModifier($params)
    {
        if (!isset($params['fields'])) { $params['fields'] = array(); }
        $customer = new Customer((int)$params['id_customer']);
        if (Validate::isLoadedObject($customer)) {
            $membership = MpClubMembership::loadByCustomer((int)$customer->id);
            $html = '';
            if ($membership) {
                $html .= '<div class="alert alert-info">';
                $html .= $this->l('Club level').': <strong>'.Tools::strtoupper($membership->level).'</strong><br>';
                $html .= $this->l('Validity').': '.pSQL($membership->date_start).' → '.pSQL($membership->date_end).'<br>';
                $html .= $this->l('Active').': '.($membership->active ? 'YES' : 'NO');
                $html .= '</div>';
            } else {
                $html .= '<div class="alert alert-warning">'.$this->l('This customer is not a Club member.').'</div>';
            }
            $params['fields'][] = array('type'=>'free','label'=>$this->l('Club Maison Perrotte'),'name'=>'mpclub_card','desc'=>$html);
        }
    }

    public function hookDisplayShoppingCart($params)
    {
        if (Tools::getValue('mpclub_added') === '1') {
            $this->context->smarty->assign('mpclub_alert', $this->l('Formule ajoutée. Les avantages sont appliqués immédiatement.'));
            return $this->fetch('module:mpclub/views/templates/hook/cart_alert.tpl');
        }
        return '';
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        try {
            if (!isset($params['order']) || !isset($params['newOrderStatus'])) { return; }
            $order = $params['order'];
            $newState = (int)$params['newOrderStatus']->id;
            if (!$this->isPaidState($newState)) { return; }
            $membershipData = $this->containsMembershipProduct($order);
            if ($membershipData) {
                MpClubRuleService::activateMembershipForOrder($order, $membershipData);
                $this->addPrivateNote($order, Configuration::get('MPC_ORDER_TAG'));
                $customer = new Customer((int)$order->id_customer);
                MpClubRuleService::removeImmediateRule($customer);
            }
        } catch (Exception $e) { PrestaShopLogger::addLog('mpclub hookActionOrderStatusPostUpdate: '.$e->getMessage(), 3); }
    }

    public function hookActionCartSave($params)
    {
        try {
            $context = Context::getContext();
            $cart = $context->cart;
            if (!$cart || !$cart->id) { return; }

            $this->sanitizeMembershipInCart($cart);

            $level = $this->getMembershipLevelInCart($cart);
            if ($level) {
                $customer = $context->customer && $context->customer->id ? $context->customer : new Customer();
                MpClubRuleService::ensureImmediateRule($customer, $level, (int)$context->shop->id, $cart);
            } else {
                if ($context->customer && $context->customer->id) { MpClubRuleService::removeImmediateRule($context->customer); }
                MpClubRuleService::removeImmediateRuleForCart($cart);
            }
        } catch (Exception $e) { PrestaShopLogger::addLog('mpclub hookActionCartSave: '.$e->getMessage(), 3); }
    }

    public function hookActionCronJob($params) { if (class_exists('MpClubCronService')) { MpClubCronService::runDaily(); } }

    public function hookDisplayPDFInvoice($params)
    {
        $order = $params['object'];
        if (!Validate::isLoadedObject($order)) { return ''; }
        $membership = MpClubMembership::loadByCustomer((int)$order->id_customer);
        if ($membership) { return '<table style="width:100%;font-size:10px;margin-top:6px"><tr><td><strong>Club:</strong> '.Tools::strtoupper($membership->level).'</td></tr></table>'; }
        return '';
    }
    public function hookActionObjectCustomerDeleteAfter($params) { if (isset($params['object']) && $params['object'] instanceof Customer) { MpClubMembership::deleteByCustomer((int)$params['object']->id); } }

    private function isPaidState($idState)
    {
        $csv = (string)Configuration::get('MPC_PAID_STATES'); $ids = array();
        foreach (explode(',', $csv) as $s) { $s = trim($s); if ($s !== '') { $ids[] = (int)$s; } }
        return in_array((int)$idState, $ids, true);
    }
    private function containsMembershipProduct(Order $order)
    {
        $idSilver = (int)Configuration::get('MPC_PRODUCT_SILVER');
        $idGold   = (int)Configuration::get('MPC_PRODUCT_GOLD');
        $idPlat   = (int)Configuration::get('MPC_PRODUCT_PLATINUM');
        foreach ($order->getProducts() as $p) {
            $idp = (int)$p['product_id'];
            if ($idp && ($idp === $idSilver || $idp === $idGold || $idp === $idPlat)) {
                $level = ($idp === $idSilver) ? 'silver' : (($idp === $idGold) ? 'gold' : 'platinum');
                return array('level' => $level, 'id_product' => $idp);
            }
        }
        return null;
    }
    private function addPrivateNote(Order $order, $note)
    {
        if (!$note) { return; }
        $msg = new Message();
        $msg->message = pSQL((string)$note);
        $msg->id_order = (int)$order->id;
        $msg->private = 1;
        $employee = Context::getContext()->employee;
        $msg->id_employee = $employee && isset($employee->id) ? (int)$employee->id : 0;
        $msg->add();
    }

    public function getContent()
    {
        $output = $this->fetch('module:mpclub/views/templates/admin/branding.tpl');

        if (Tools::isSubmit('submitMpclub')) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        if (Tools::isSubmit('submitCreateProducts')) {
            $this->createOrUpdateMembershipProducts();
            $output .= $this->displayConfirmation($this->l('Membership products created/updated.'));
        }
        if (Tools::isSubmit('submitRepairDb')) {
            $this->installSql();
            $output .= $this->displayConfirmation($this->l('Database checked (tables ensured).'));
        }
        if (Tools::isSubmit('submitRegenRules')) {
            $count = $this->regenerateOngoingRules();
            $output .= $this->displayConfirmation(sprintf($this->l('Member discounts updated: %d rules.'), $count));
        }

        $helper = new HelperForm();
        $helper->show_toolbar = false; $helper->table = $this->table;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->module = $this; $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMpclub';
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $fields_form = array();
        $fields_form[]['form'] = array(
            'legend' => array('title' => $this->l('Club Settings')),
            'input'  => array(
                array('type'=>'text','label'=>$this->l('Product ID - Silver'),'name'=>'MPC_PRODUCT_SILVER'),
                array('type'=>'text','label'=>$this->l('Product ID - Gold'),'name'=>'MPC_PRODUCT_GOLD'),
                array('type'=>'text','label'=>$this->l('Product ID - Platinum'),'name'=>'MPC_PRODUCT_PLATINUM'),
                array('type'=>'html','label'=>$this->l('Create products'),'name'=>'create_products','html_content'=>'<button type="submit" value="1" class="btn btn-default" name="submitCreateProducts">'.$this->l('Create/Update membership products').'</button>'),
                array('type'=>'html','name'=>'hr1','html_content'=>'<hr />'),
                array('type'=>'text','label'=>$this->l('Welcome bonus Silver (€)'),'name'=>'MPC_WELCOME_SILVER'),
                array('type'=>'text','label'=>$this->l('Welcome bonus Gold (€)'),'name'=>'MPC_WELCOME_GOLD'),
                array('type'=>'text','label'=>$this->l('Welcome bonus Platinum (€)'),'name'=>'MPC_WELCOME_PLATINUM'),
                array('type'=>'text','label'=>$this->l('Permanent discount Silver (%)'),'name'=>'MPC_PERCENT_SILVER'),
                array('type'=>'text','label'=>$this->l('Permanent discount Gold (%)'),'name'=>'MPC_PERCENT_GOLD'),
                array('type'=>'text','label'=>$this->l('Permanent discount Platinum (%)'),'name'=>'MPC_PERCENT_PLATINUM'),
                array('type'=>'switch','label'=>$this->l('Free shipping for members'),'name'=>'MPC_FREE_SHIPPING','is_bool'=>true,
                      'values'=>array(
                          array('id'=>'on','value'=>1,'label'=>$this->l('Enabled')),
                          array('id'=>'off','value'=>0,'label'=>$this->l('Disabled')),
                      )
                ),
                array('type'=>'text','label'=>$this->l('Order tag (private note)'),'name'=>'MPC_ORDER_TAG'),
                array('type'=>'text','label'=>$this->l('Birthday staff email'),'name'=>'MPC_STAFF_BDAY_EMAIL'),
                array('type'=>'text','label'=>$this->l('Paid states (IDs CSV)'),'name'=>'MPC_PAID_STATES'),
                array('type'=>'text','label'=>$this->l('CRON token'),'name'=>'MPC_CRON_TOKEN','desc'=>$this->l('Use with /index.php?fc=module&module=mpclub&controller=cron&token=XXXX') ),
                array('type'=>'html','label'=>$this->l('Maintenance'),'name'=>'db_tools','html_content'=>'
                    <div class="clearfix"></div>
                    <button type="submit" class="btn btn-default" name="submitRepairDb">'.$this->l('Repair/Install DB').'</button>
                    <button type="submit" class="btn btn-default" name="submitRegenRules" style="margin-left:10px">'.$this->l('Regenerate member discounts').'</button>
                '),
            ),
            'submit' => array('title' => $this->l('Save')),
        );

        $helper->fields_value = array();
        foreach (self::CONFIG_KEYS as $k) { $helper->fields_value[$k] = Configuration::get($k); }
        $helper->fields_value['create_products'] = '';
        return $output.$helper->generateForm($fields_form);
    }

    private function postProcess()
    {
        foreach (self::CONFIG_KEYS as $k) { if (Tools::getIsset($k)) { Configuration::updateValue($k, Tools::getValue($k)); } }
    }

    private function attachImageToProduct($idProduct, $imagePath, $isCover = true)
    {
        if (!file_exists($imagePath)) { return; }
        $image = new Image();
        $image->id_product = (int)$idProduct;
        $image->position = Image::getHighestPosition((int)$idProduct) + 1;
        $image->cover = $isCover ? 1 : 0;
        if ($image->add()) {
            $path = $image->getPathForCreation();
            ImageManager::resize($imagePath, $path.'.jpg');
            foreach (ImageType::getImagesTypes('products') as $type) {
                ImageManager::resize($imagePath, $path.'-'.stripslashes($type['name']).'.jpg', (int)$type['width'], (int)$type['height']);
            }
        }
    }

    private function createOrUpdateMembershipProducts()
    {
        $map = array(
            'silver'   => array('idconf'=>'MPC_PRODUCT_SILVER',   'name'=>'Club Maison Perrotte – Argent',  'price'=>19.99,'img'=>'views/img/silver.png'),
            'gold'     => array('idconf'=>'MPC_PRODUCT_GOLD',     'name'=>'Club Maison Perrotte – Or',      'price'=>24.99,'img'=>'views/img/gold.png'),
            'platinum' => array('idconf'=>'MPC_PRODUCT_PLATINUM', 'name'=>'Club Maison Perrotte – Platine', 'price'=>34.99,'img'=>'views/img/platinum.png'),
        );
        $id_home = (int)Configuration::get('PS_HOME_CATEGORY'); if (!$id_home) { $id_home = 2; }
        foreach ($map as $level => $cfg) {
            $idp = (int)Configuration::get($cfg['idconf']);
            if ($idp > 0) {
                $p = new Product($idp);
                if (Validate::isLoadedObject($p)) {
                    foreach (Language::getIDs(false) as $id_lang) {
                        $p->name[(int)$id_lang] = $cfg['name'];
                        $p->link_rewrite[(int)$id_lang] = Tools::link_rewrite($cfg['name']);
                    }
                    $p->price = (float)$cfg['price'];
                    $p->is_virtual = 1;
                    $p->visibility = 'none';
                    $p->id_category_default = $id_home;
                    $p->update();
                    $p->updateCategories(array($id_home));
                    if (!Image::getCover((int)$p->id)) { $this->attachImageToProduct((int)$p->id, _PS_MODULE_DIR_.$this->name.'/'.$cfg['img'], true); }
                    continue;
                }
            }
            $product = new Product();
            $product->name = array(); $product->link_rewrite = array();
            foreach (Language::getLanguages(false) as $l) {
                $product->name[(int)$l['id_lang']] = $cfg['name'];
                $product->link_rewrite[(int)$l['id_lang']] = Tools::link_rewrite($cfg['name']);
            }
            $product->price = (float)$cfg['price'];
            $product->id_tax_rules_group = 0; $product->active = 1; $product->is_virtual = 1;
            $product->visibility = 'none'; $product->redirect_type = '404'; $product->id_category_default = $id_home;
            if ($product->add()) {
                $product->addToCategories(array($id_home));
                Configuration::updateValue($cfg['idconf'], (int)$product->id);
                $this->attachImageToProduct((int)$product->id, _PS_MODULE_DIR_.$this->name.'/'.$cfg['img'], true);
            }
        }
    }

    public function getMembershipLevelInCart(Cart $cart)
    {
        $idSilver = (int)Configuration::get('MPC_PRODUCT_SILVER');
        $idGold   = (int)Configuration::get('MPC_PRODUCT_GOLD');
        $idPlat   = (int)Configuration::get('MPC_PRODUCT_PLATINUM');
        foreach ($cart->getProducts() as $p) {
            $idp = (int)$p['id_product'];
            if ($idp === $idSilver) { return 'silver'; }
            if ($idp === $idGold)   { return 'gold'; }
            if ($idp === $idPlat)   { return 'platinum'; }
        }
        return null;
    }

    public function sanitizeMembershipInCart(Cart $cart)
    {
        $idSilver = (int)Configuration::get('MPC_PRODUCT_SILVER');
        $idGold   = (int)Configuration::get('MPC_PRODUCT_GOLD');
        $idPlat   = (int)Configuration::get('MPC_PRODUCT_PLATINUM');
        $ids = array($idSilver, $idGold, $idPlat);
        $found = 0;
        foreach ($cart->getProducts() as $p) {
            $idp = (int)$p['id_product'];
            if (in_array($idp, $ids)) {
                $found++;
                if ((int)$p['cart_quantity'] > 1) { $diff = 1 - (int)$p['cart_quantity']; $cart->updateQty($diff, $idp); }
                if ($found > 1) { $cart->updateQty(0, $idp); }
            }
        }
    }

    public function getLandingVars()
    {
        $idSilver = (int)Configuration::get('MPC_PRODUCT_SILVER');
        $idGold   = (int)Configuration::get('MPC_PRODUCT_GOLD');
        $idPlat   = (int)Configuration::get('MPC_PRODUCT_PLATINUM');
        $taxInc = true;
        $silverPrice = $idSilver ? Product::getPriceStatic($idSilver, $taxInc) : 0;
        $goldPrice   = $idGold   ? Product::getPriceStatic($idGold, $taxInc)   : 0;
        $platPrice   = $idPlat   ? Product::getPriceStatic($idPlat, $taxInc)   : 0;
        return array(
            'mpc_prices'  => array('silver' => Tools::displayPrice($silverPrice), 'gold' => Tools::displayPrice($goldPrice), 'platinum'=> Tools::displayPrice($platPrice)),
            'mpc_welcome' => array('silver' => (float)Configuration::get('MPC_WELCOME_SILVER'), 'gold' => (float)Configuration::get('MPC_WELCOME_GOLD'), 'platinum'=> (float)Configuration::get('MPC_WELCOME_PLATINUM')),
            'mpc_percent' => array('silver' => (float)Configuration::get('MPC_PERCENT_SILVER'), 'gold' => (float)Configuration::get('MPC_PERCENT_GOLD'), 'platinum'=> (float)Configuration::get('MPC_PERCENT_PLATINUM')),
        );
    }

    public function logEvent($idCustomer, $event, $payload = null)
    {
        Db::getInstance()->insert('mpclub_logs', array(
            'id_customer' => (int)$idCustomer,
            'event'       => pSQL($event),
            'payload'     => $payload ? pSQL(Tools::jsonEncode($payload), true) : null,
            'date_add'    => date('Y-m-d H:i:s'),
        ));
        @file_put_contents(_PS_ROOT_DIR_.'/var/logs/mpclub_debug.log', date('c')." [$event] uid=$idCustomer ".($payload?Tools::jsonEncode($payload):'')."\n", FILE_APPEND);
    }

    private function regenerateOngoingRules()
    {
        $rows = Db::getInstance()->executeS('SELECT id_customer, level, id_cart_rule_ongoing FROM `'._DB_PREFIX_.'mpclub_membership` WHERE active=1');
        $n = 0;
        foreach ($rows as $r) {
            if (!(int)$r['id_cart_rule_ongoing']) { continue; }
            $cr = new CartRule((int)$r['id_cart_rule_ongoing']);
            if (!Validate::isLoadedObject($cr)) { continue; }
            $percent = (float)Configuration::get('MPC_PERCENT_'.Tools::strtoupper($r['level']));
            $cr->reduction_percent = $percent;
            $cr->free_shipping = (int)Configuration::get('MPC_FREE_SHIPPING');
            if ($cr->update()) { $n++; }
        }
        return $n;
    }
}
