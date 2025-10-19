<?php
class MpclubClubModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public function initContent()
    {
        parent::initContent();
        $action=Tools::getValue('action'); $level=Tools::getValue('level');
        if($action==='subscribe' && in_array($level,array('silver','gold','platinum'))){
            try{
                $map=array('silver'=>(int)Configuration::get('MPC_PRODUCT_SILVER'),'gold'=>(int)Configuration::get('MPC_PRODUCT_GOLD'),'platinum'=>(int)Configuration::get('MPC_PRODUCT_PLATINUM'));
                $idProduct=(int)$map[$level];
                if($idProduct<=0){ $this->errors[]=$this->module->l('Membership product is not configured.'); $this->setTemplate('module:mpclub/views/templates/front/landing.tpl'); return; }
                if(!$this->context->cart->id){ $this->createEmptyCart(); }
                $this->module->sanitizeMembershipInCart($this->context->cart);
                $ok=$this->context->cart->updateQty(1,$idProduct);
                if($ok){
                    // Rely on hookActionCartSave to add rules, and redirect fast to avoid timeouts
                    Tools::redirect($this->context->link->getPageLink('cart',true,null,array('mpclub_added'=>1))); return;
                } else {
                    $this->errors[]=$this->module->l('Unable to add the membership to cart.');
                }
            }catch(Exception $e){
                $this->errors[]=$this->module->l('Unexpected error while adding membership.');
            }
        }
        $this->context->smarty->assign($this->module->getLandingVars());
        $this->setTemplate('module:mpclub/views/templates/front/landing.tpl');
    }
    private function createEmptyCart()
    {
        $c=$this->context; $cart=new Cart();
        $cart->id_customer=(int)$c->customer->id; $cart->id_lang=(int)$c->language->id; $cart->id_currency=(int)$c->currency->id; $cart->id_shop=(int)$c->shop->id;
        $cart->save(); $c->cart=$cart; $c->cookie->id_cart=(int)$cart->id;
    }
}
