<?php
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter;
use PrestaShop\PrestaShop\Core\Product\Search\PriceFormatter;

class MpclubClubModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function init()
    {
        parent::init();
        if ((int)Tools::getValue('ajax') === 1) { $this->ajax = true; }
    }

    public function initContent()
    {
        parent::initContent();

        if (Tools::getValue('action') === 'subscribe') {
            $this->processSubscribe((bool)$this->ajax);
            if (!$this->ajax) {
                Tools::redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $this->context->link->getPageLink('index'));
            }
            return;
        }

        $this->context->smarty->assign($this->module->getLandingVars());
        $this->setTemplate('module:mpclub/views/templates/front/landing.tpl');
    }

    public function displayAjaxSubscribe()
    {
        $this->processSubscribe(true);
    }

    private function processSubscribe($isAjax)
    {
        $ctx = $this->context;
        if (!$ctx->customer->isLogged()) {
            $payload = ['success' => false, 'redirect' => $ctx->link->getPageLink('my-account', true)];
            return $isAjax ? $this->ajaxDie(json_encode($payload)) : null;
        }

        $level = Tools::strtolower(Tools::getValue('level'));
        $map = array(
            'silver'   => (int)Configuration::get('MPC_PRODUCT_SILVER', null, null, (int)$ctx->shop->id),
            'gold'     => (int)Configuration::get('MPC_PRODUCT_GOLD', null, null, (int)$ctx->shop->id),
            'platinum' => (int)Configuration::get('MPC_PRODUCT_PLATINUM', null, null, (int)$ctx->shop->id),
        );
        $idProduct = isset($map[$level]) ? (int)$map[$level] : 0;
        if (!$idProduct) {
            $payload = ['success' => false, 'message' => 'Produit de formule introuvable.'];
            return $isAjax ? $this->ajaxDie(json_encode($payload)) : null;
        }

        if (!$ctx->cart->id) { $this->createEmptyCart(); }

        $this->module->sanitizeMembershipInCart($ctx->cart);
        $added = $ctx->cart->updateQty(1, $idProduct);

        MpClubRuleService::ensureImmediateRule($ctx->customer, $level, (int)$ctx->shop->id, $ctx->cart);
        CartRule::autoAddToCart($ctx); // ✅ CONTEXT

        if ($isAjax) {
            $presenter = new CartPresenter(new PriceFormatter());
            $presentedCart = $presenter->present($ctx->cart);
            $payload = [
                'success' => (bool)$added,
                'id_product' => (int)$idProduct,
                'id_product_attribute' => 0,
                'cart' => $presentedCart,
                'message' => $added ? $this->module->l('Formule ajoutée à votre panier.') : $this->module->l('Impossible d’ajouter la formule.'),
            ];
            return $this->ajaxDie(json_encode($payload));
        }
    }

    private function createEmptyCart()
    {
        $c=$this->context; $cart=new Cart();
        $cart->id_customer=(int)$c->customer->id; $cart->id_lang=(int)$c->language->id; $cart->id_currency=(int)$c->currency->id; $cart->id_shop=(int)$c->shop->id;
        $cart->save(); $c->cart=$cart; $c->cookie->id_cart=(int)$cart->id;
    }
}
