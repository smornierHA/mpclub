<?php
class MpclubAccountModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public function initContent(){ parent::initContent(); $membership=null; if($this->context->customer && $this->context->customer->id){ $membership=MpClubMembership::loadByCustomer((int)$this->context->customer->id); } $this->context->smarty->assign(array('mpclub_membership'=>$membership)); $this->setTemplate('module:mpclub/views/templates/front/account.tpl'); }
}
