<?php
class MpclubRenewModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public function initContent()
    {
        parent::initContent();
        if(!$this->context->customer->isLogged()){ Tools::redirect('index.php?controller=authentication&back='.$this->context->link->getModuleLink('mpclub','renew')); }
        $m=MpClubMembership::loadByCustomer((int)$this->context->customer->id); if(!$m){ Tools::redirect($this->context->link->getModuleLink('mpclub','club')); }
        $map=array('silver'=>(int)Configuration::get('MPC_PRODUCT_SILVER'),'gold'=>(int)Configuration::get('MPC_PRODUCT_GOLD'),'platinum'=>(int)Configuration::get('MPC_PRODUCT_PLATINUM'));
        $idProduct=(int)$map[$m->level]; if(!$this->context->cart->id){ $this->createEmptyCart(); }
        $this->module->sanitizeMembershipInCart($this->context->cart);
        $ok=$this->context->cart->updateQty(1,$idProduct); 
        if($ok){ Tools::redirect($this->context->link->getPageLink('cart',true,null,array('mpclub_added'=>1))); } 
        else { $this->errors[]=$this->module->l('Unable to add the renewal to the cart.'); $this->setTemplate('module:mpclub/views/templates/front/account.tpl'); }
    }
    private function createEmptyCart(){ $c=$this->context; $cart=new Cart(); $cart->id_customer=(int)$c->customer->id; $cart->id_lang=(int)$c->language->id; $cart->id_currency=(int)$c->currency->id; $cart->id_shop=(int)$c->shop->id; $cart->save(); $c->cart=$cart; $c->cookie->id_cart=(int)$cart->id; }
}
