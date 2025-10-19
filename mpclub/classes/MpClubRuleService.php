<?php
if (!defined('_PS_VERSION_')) { exit; }

class MpClubRuleService
{
    public static function ensureImmediateRule(Customer $c, $level, $idShop, Cart $cart = null)
    {
        $percent = self::getPercentForLevel($level);
        $free=(int)Configuration::get('MPC_FREE_SHIPPING');
        $end=(new DateTime())->modify('+1 day');

        $context = Context::getContext();

        if ($c && (int)$c->id > 0) {
            $idLang=(int)$context->language->id;
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
                    CartRule::autoRemoveFromCart($context); CartRule::autoAddToCart($context);
                    return;
                }
            }
            $cr=new CartRule();
            $cr->id_customer=(int)$c->id;
            $cr->date_from=date('Y-m-d H:i:s');
            $cr->date_to=$end->format('Y-m-d H:i:s');
            $cr->quantity=0;
            $cr->quantity_per_user=0;
            $cr->reduction_percent=$percent;
            $cr->free_shipping=$free;
            $cr->code=null;
            $cr->shop_restriction=0; $cr->carrier_restriction=0;
            $cr->minimum_amount=0; $cr->minimum_amount_tax=1; $cr->minimum_amount_currency=(int)$context->currency->id;
            $cr->name=array_fill_keys(Language::getIDs(false), 'Club – Avantages immédiats (panier)');
            $cr->active=1;
            $cr->priority=1;
            if($cr->add()){
                if($cart && $cart->id){ $cart->addCartRule((int)$cr->id); $cart->update(); }
                CartRule::autoRemoveFromCart($context); CartRule::autoAddToCart($context);
            }
            return;
        }

        if (!$cart || !$cart->id) { return; }
        self::removeImmediateRuleForCart($cart);
        $cr=new CartRule();
        $cr->id_customer=0;
        $cr->date_from=date('Y-m-d H:i:s');
        $cr->date_to=$end->format('Y-m-d H:i:s');
        $cr->quantity=1;
        $cr->quantity_per_user=1;
        $cr->reduction_percent=$percent;
        $cr->free_shipping=$free;
        $cr->carrier_restriction=0;
        $cr->minimum_amount=0; $cr->minimum_amount_tax=1; $cr->minimum_amount_currency=(int)$context->currency->id;
        $cr->code='MPC'.(int)$cart->id.'-'.Tools::substr(sha1($cart->id.$end->format('YmdHis')),0,6);
        $cr->shop_restriction=0;
        $cr->name=array_fill_keys(Language::getIDs(false), 'Club – Avantages immédiats (invité)');
        $cr->active=1;
        $cr->priority=1;
        if($cr->add()){
            $cart->addCartRule((int)$cr->id);
            $cart->update();
            CartRule::autoRemoveFromCart($context);
            CartRule::autoAddToCart($context);
        }
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

    public static function activateMembershipForOrder(Order $order, array $membershipData)
    {
        $context=Context::getContext(); $customer=new Customer((int)$order->id_customer); if(!Validate::isLoadedObject($customer)){ return; }
        $level=$membershipData['level']; $now=new DateTime(); $end=(new DateTime())->modify('+1 year');
        $m=MpClubMembership::loadByCustomer((int)$customer->id); if(!$m){ $m=new MpClubMembership(); }
        $m->id_customer=(int)$customer->id; $m->level=pSQL($level); $m->date_start=$now->format('Y-m-d H:i:s'); $m->date_end=$end->format('Y-m-d H:i:s'); $m->active=1;
        $m->id_order=(int)$order->id; $m->id_product=(int)$membershipData['id_product'];

        if(!empty($m->id_cart_rule_ongoing)){ $cr=new CartRule((int)$m->id_cart_rule_ongoing); if(Validate::isLoadedObject($cr)){ $cr->delete(); } $m->id_cart_rule_ongoing=null; }
        if(!empty($m->id_cart_rule_welcome)){ $cr=new CartRule((int)$m->id_cart_rule_welcome); if(Validate::isLoadedObject($cr)){ $cr->delete(); } $m->id_cart_rule_welcome=null; }

        $percent=(float)Configuration::get('MPC_PERCENT_'.Tools::strtoupper($level));
        $freeShip=(int)Configuration::get('MPC_FREE_SHIPPING');

        $m->id_cart_rule_ongoing=(int)self::createOngoingRule($customer,$percent,$freeShip,$end);
        $welcome=(float)Configuration::get('MPC_WELCOME_'.Tools::strtoupper($level));
        $m->id_cart_rule_welcome=(int)self::createWelcomeVoucher($customer,$welcome,$end);
        $m->save();
    }

    public static function createOngoingRule(Customer $c,$percent,$freeShipping,DateTime $end)
    {
        $rule=new CartRule();
        $rule->id_customer=(int)$c->id;
        $rule->date_from=date('Y-m-d H:i:s');
        $rule->date_to=$end->format('Y-m-d H:i:s');
        $rule->quantity=0;
        $rule->quantity_per_user=0;
        $rule->reduction_percent=(float)$percent;
        $rule->free_shipping=(int)$freeShipping;
        $rule->code=null;
        $rule->shop_restriction=0; $rule->carrier_restriction=0;
        $rule->name=array_fill_keys(Language::getIDs(false),'Club Maison Perrotte – Remise membre');
        $rule->active=1;
        $rule->priority=1;
        $rule->add();
        return (int)$rule->id;
    }

    public static function createWelcomeVoucher(Customer $c,$amount,DateTime $end)
    {
        $cr=new CartRule();
        $cr->id_customer=(int)$c->id;
        $cr->date_from=date('Y-m-d H:i:s');
        $cr->date_to=$end->format('Y-m-d H:i:s');
        $cr->quantity=1;
        $cr->quantity_per_user=1;
        $cr->reduction_amount=(float)$amount;
        $cr->reduction_tax=1;
        $cr->code=null;
        $cr->shop_restriction=0; $cr->carrier_restriction=0;
        $cr->name=array_fill_keys(Language::getIDs(false),'Club – Bonus de bienvenue');
        $cr->active=1;
        $cr->priority=0;
        $cr->add();
        return (int)$cr->id;
    }
    private static function getPercentForLevel($level)
    {
        $keys = [
            'MPC_PERCENT_'.Tools::strtoupper($level),
            'MPC_DISCOUNT_'.Tools::strtoupper($level),
            'MPCLUB_DISCOUNT_'.Tools::strtoupper($level),
        ];
        foreach ($keys as $k) {
            $v = Configuration::get($k);
            if ($v !== false && $v !== null) return (float)$v;
        }
        return 0.0;
    }
}
