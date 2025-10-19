<?php
use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter;
use PrestaShop\PrestaShop\Core\Product\Search\PriceFormatter;

class MpclubClubModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function init()
    {
        parent::init();
        if ((int)Tools::getValue('ajax') === 1) {
            $this->ajax = true;
        }
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

        die('Club Maison Perrotte');
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
        $map = ['silver'=>'MPC_PRODUCT_SILVER','gold'=>'MPC_PRODUCT_GOLD','platinum'=>'MPC_PRODUCT_PLATINUM'];
        if (!isset($map[$level])) {
            return $isAjax ? $this->ajaxDie(json_encode(['success'=>false,'message'=>'Niveau invalide'])) : null;
        }

        $idProduct = (int)Configuration::get($map[$level], null, null, (int)$ctx->shop->id);
        if (!$idProduct) {
            return $isAjax ? $this->ajaxDie(json_encode(['success'=>false,'message'=>'Produit introuvable'])) : null;
        }

        if (!$ctx->cart->id) {
            $cart = new Cart();
            $cart->id_customer = (int)$ctx->customer->id;
            $cart->id_lang     = (int)$ctx->language->id;
            $cart->id_currency = (int)$ctx->currency->id;
            $cart->id_shop     = (int)$ctx->shop->id;
            $cart->save();
            $ctx->cart = $cart;
            $ctx->cookie->id_cart = (int)$cart->id;
        }

        // 1 formule max, quantité 1
        $this->module->sanitizeMembershipInCart($ctx->cart);
        $ok = $ctx->cart->updateQty(1, $idProduct);

        // avantages immédiats (réduc + port gratuit)
        MpClubRuleService::ensureImmediateRule($ctx->customer, $level, (int)$ctx->shop->id, $ctx->cart);
        CartRule::autoAddToCart($ctx); // IMPORTANT: passer le Context, pas un Cart

        if ($isAjax) {
            $presenter = new CartPresenter(new PriceFormatter());
            $payload = [
                'success'              => (bool)$ok,
                'id_product'           => (int)$idProduct,
                'id_product_attribute' => 0,
                'cart'                 => $presenter->present($ctx->cart),
                'message'              => $ok ? 'Formule ajoutée à votre panier.' : 'Impossible d’ajouter la formule.',
            ];
            return $this->ajaxDie(json_encode($payload));
        }
    }
}
