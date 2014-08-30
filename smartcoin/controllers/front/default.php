<?php
class SmartCoinDefaultModuleFrontController extends ModuleFrontController {
	public function __construct() {
		$this->auth = false;
		parent::__construct();
		$this->context = Context::getContext();
		include_once($this->module->getLocalPath().'smartcoin.php');
	}

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		$this->display_column_left = false;
		$this->display_column_right = false;
		parent::initContent();
		if (Tools::getValue('process') == 'validation')
			$this->validation();
	}

	public function validation() {
		$smartcoin = new SmartCoin();
		if ($smartcoin->active && Tools::getIsset('smartcoin_token')) {
			$smartcoin->processPayment(Tools::getValue('smartcoin_token'),Tools::getValue('smartcoin_installments'));
		}
		else {
			if($smartcoin->active && Tools::getIsset('smartcoin_charge_type')){
				$smartcoin->processPayment(null,1,Tools::getValue('smartcoin_charge_type'));
			}else {
				$this->context->cookie->__set("smartcoin_error", 'There was a problem with your payment');
				$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
				$location = $this->context->link->getPageLink($controller).(strpos($controller, '?') !== false ? '&' : '?').'step=3#smartcoin_error';
				header('Location: '.$location);
			}
		}
	}
}
