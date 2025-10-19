<?php
if (!defined('_PS_VERSION_')) { exit; }
class MpClubMembership extends ObjectModel
{
    public $id_mpclub_membership; public $id_customer; public $level; public $date_start; public $date_end; public $active;
    public $id_order; public $id_product; public $id_cart_rule_ongoing; public $id_cart_rule_welcome; public $last_birthday_probe; public $last_renewal_probe; public $date_add; public $date_upd;
    public static $definition = array('table'=>'mpclub_membership','primary'=>'id_mpclub_membership','fields'=>array(
        'id_customer'=>array('type'=>self::TYPE_INT,'required'=>true),
        'level'=>array('type'=>self::TYPE_STRING,'required'=>true,'size'=>16),
        'date_start'=>array('type'=>self::TYPE_DATE,'required'=>true),
        'date_end'=>array('type'=>self::TYPE_DATE,'required'=>true),
        'active'=>array('type'=>self::TYPE_BOOL,'required'=>true),
        'id_order'=>array('type'=>self::TYPE_INT),'id_product'=>array('type'=>self::TYPE_INT),
        'id_cart_rule_ongoing'=>array('type'=>self::TYPE_INT),'id_cart_rule_welcome'=>array('type'=>self::TYPE_INT),
        'last_birthday_probe'=>array('type'=>self::TYPE_DATE),'last_renewal_probe'=>array('type'=>self::TYPE_DATE),
        'date_add'=>array('type'=>self::TYPE_DATE),'date_upd'=>array('type'=>self::TYPE_DATE),
    ));
    public static function loadByCustomer($idCustomer){ $id=(int)Db::getInstance()->getValue('SELECT id_mpclub_membership FROM `'._DB_PREFIX_.'mpclub_membership` WHERE id_customer='.(int)$idCustomer); return $id?new self($id):null; }
    public static function deleteByCustomer($idCustomer){ return Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'mpclub_membership` WHERE id_customer='.(int)$idCustomer); }
}
