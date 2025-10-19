<?php
if (!defined('_PS_VERSION_')) { exit; }

class MpClubRuleService
{
    public static function ensureImmediateRule(Customer $c, $level, $idShop, Cart $cart = null)
    {
        $percent=(float)Configuration::get('MPC_PERCENT_'.Tools::strtoupper($level));
        $free=(int)Configuration::get('MPC_FREE_SHIPPING');
        $end=(new DateTime())->modify('+1 day');
        $ctx = Context::getContext();

        // Client loggé : règle nominative « Club – Avantages immédiats (panier) »
        if ($c && (int)$c->id > 0) {
            $idLang=(int)$ctx->language->id;
            $id=(int)Db::getInstance()->getValue(
                'SELECT cr.id_cart_rule
                 FROM `'._DB_PREFIX_.'cart_rule` cr
                 INNER JOIN `'._DB_PREFIX_.'cart_rule_lang` crl
                   ON (crl.id_cart_rule=cr.id_cart_rule AND crl.id_lang='.(int)$idLang.')
                 WHERE cr.id_customer='.(int)$c->id.' AND crl.name LIKE "Club – Avantages immédiats%"
                 ORDER BY cr.id_cart_rule DESC'
            );
            if ($id) {
                $cr=new CartRule($id);
                if(Validate::isLoadedObject($cr)){
                    $cr->reduction_percent=$percent; $cr->free_shipping=$free; $cr->date_to=$end->format('Y-m-d H:i:s'); $cr->update();
                    if($cart && $cart->id){ $cart->addCartRule((int)$cr->id); $cart->update(); }
                    CartRule::autoRemoveFromCart($ctx);
                    CartRule::autoAddToCart($ctx); // ✅ passer le CONTEXT, pas le Cart
                    return;
                }
            }
            $cr=new CartRule();
            $cr->id_customer=(int)$c->id;
            $cr->date_from=date('Y-m-d H:i:s');
            $cr->date_to=$end->format('Y-m-d H:i:s');
            $cr->quantity=0; $cr->quantity_per_user=0; // illimité
            $cr->reduction_percent=$percent; $cr->free_shipping=$free;
            $cr->code=null; $cr->shop_restriction=0; $cr->carrier_restriction=0;
            $cr->minimum_amount=0; $cr->minimum_amount_tax=1; $cr->minimum_amount_currency=(int)$ctx->currency->id;
            $cr->name=array_fill_keys(Language::getIDs(false), 'Club – Avantages immédiats (panier)');
            $cr->active=1; $cr->priority=1;
            if($cr->add()){
                if($cart && $cart->id){ $cart->addCartRule((int)$cr->id); $cart->update(); }
                CartRule::autoRemoveFromCart($ctx); CartRule::autoAddToCart($ctx);
            }
            return;
        }

        // Invité (séance rare, mais on garde)
        if (!$cart || !$cart->id) { return; }
        self::removeImmediateRuleForCart($cart);
        $cr=new CartRule();
        $cr->id_customer=0; $cr->date_from=date('Y-m-d H:i:s'); $cr->date_to=$end->format('Y-m-d H:i:s');
        $cr->quantity=1; $cr->quantity_per_user=1;
        $cr->reduction_percent=$percent; $cr->free_shipping=$free;
        $cr->carrier_restriction=0; $cr->minimum_amount=0; $cr->minimum_amount_tax=1; $cr->minimum_amount_currency=(int)$ctx->currency->id;
        $cr->code='MPC'.(int)$cart->id.'-'.Tools::substr(sha1($cart->id.$end->format('YmdHis')),0,6);
        $cr->shop_restriction=0;
        $cr->name=array_fill_keys(Language::getIDs(false), 'Club – Avantages immédiats (invité)');
        $cr->active=1; $cr->priority=1;
        if($cr->add()){ $cart->addCartRule((int)$cr->id); $cart->update(); CartRule::autoRemoveFromCart($ctx); CartRule::autoAddToCart($ctx); }
    }

    public static function removeImmediateRule(Customer $c)
    {
        if (!$c || !(int)$c->id) return;
        $rows=Db::getInstance()->executeS(
            'SELECT cr.id_cart_rule FROM `'._DB_PREFIX_.'cart_rule` cr
             INNER JOIN `'._DB_PREFIX_.'cart_rule_lang` crl ON (crl.id_cart_rule=cr.id_cart_rule)
             WHERE cr.id_customer='.(int)$c->id.' AND crl.name LIKE "Club – Avantages immédiats%"'
        );
        foreach($rows as $row){ $cr=new CartRule((int)$row['id_cart_rule']); if(Validate::isLoadedObject($cr)){ $cr->delete(); } }
    }

    public static function removeImmediateRuleForCart(Cart $cart)
    {
        if(!$cart || !$cart->id) return;
        foreach($cart->getCartRules() as $r){
            if(strpos($r['name'],'Avantages immédiats')!==false){ $cart->removeCartRule((int)$r['id_cart_rule']); }
        }
    }

    // + méthodes d’activation membre à la validation (identiques à ta base)
}
