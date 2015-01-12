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
		else if (Tools::getValue('process') == 'webhook')
			$this->webhook();
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

	public function webhook() {
		$smartcoin = new SmartCoin();
		if($smartcoin->active){
			if (Tools::getIsset('token') && Configuration::get('SMARTCOIN_WEBHOOK_TOKEN') == Tools::getValue('token')) {
				include($this->module->getLocalPath().'lib/Smartcoin.php'); 
				$event_json = Tools::jsonDecode(@Tools::file_get_contents('php://input'));

				if (isset($event_json->id)) {
					try{
						$api_key = (Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST'));
						$api_secret = (Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_SECRET_KEY_LIVE') : Configuration::get('SMARTCOIN_SECRET_KEY_TEST'));
						\Smartcoin\Smartcoin::api_key($api_key);
      			\Smartcoin\Smartcoin::api_secret($api_secret);

      			$event = new \Smartcoin\Smartcoin_Object($event_json, \Smartcoin\Smartcoin::access_keys());
						
						/* We are only handling charge update, other events are ignored */
						if ($event->type == 'charge.updated'){
							$id_order = (int)Db::getInstance()->getValue('SELECT id_order FROM '._DB_PREFIX_.'smartcoin_transaction WHERE id_transaction = \'' .pSQL($event->data->id) . '\'');

							if ($id_order) {
								$order = new Order((int)$id_order);
								if (Validate::isLoadedObject($order)) {			
									if ($order->getCurrentState() == Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS')) {
										$history = new OrderHistory();
										$history->id_order = (int)$order->id;
										$history->changeIdOrderState((int)(Configuration::get('SMARTCOIN_PAYMENT_ORDER_STATUS')), (int)($order->id));
										$history->addWithemail();
										$history->save();
										Db::getInstance()->getValue('UPDATE `'._DB_PREFIX_.'smartcoin_transaction` SET `status` = \'paid\' WHERE `id_transaction` = \'' .pSQL($event->data->id) . '\'');
									}

									$message = new Message();
									$message->message = $smartcoin->l('A charge update occured on this order and was reported by Smartcoin on').' '.date('Y-m-d H:i:s');
									$message->id_order = (int)$order->id;
									$message->id_employee = 1;
									$message->private = 1;
									$message->date_add = date('Y-m-d H:i:s');
									$message->add();
								}
							}
						}
					}
					catch (Exception $e)
					{
						header('HTTP/1.1 200 OK');
						exit;
					}
					header('HTTP/1.1 200 OK');
					exit;
				}
			}	
		}
		header('HTTP/1.1 200 OK');
		exit;
	}
}
