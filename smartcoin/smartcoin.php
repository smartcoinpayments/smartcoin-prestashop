<?php

  if (!defined('_PS_VERSION_'))
    exit;

  class Smartcoin extends PaymentModule {
    public function __construct() {
      $this->name = 'smartcoin';
      $this->tab = "payments_gateways";
      $this->version = '0.2.5';
      $this->author = "Smartcoin LTDA.";
      $this->need_instance = 0;

      parent::__construct();

      $this->displayName = $this->l('Smartcoin');
      $this->description = $this->l('Accept payments with Smartcoin');
      $this->confirmUninstall = $this->l('Warning: Are you sure you want uninstall this module?');
      $this->backward = true;

    }


    /**
  	 * Smartcoin's module installation
  	 *
  	 * @return boolean Install result
  	 */
    public function install(){
      $ret = parent::install() && $this->registerHook('payment') && $this->registerHook('header') &&
            $this->registerHook('paymentReturn') && $this->registerHook('backOfficeHeader') &&
            $this->installDB() && Configuration::updateValue('SMARTCOIN_MODE', 0) &&
            $this->createSmartcoinPendingOrderStatus() &&
        		Configuration::updateValue('SMARTCOIN_PAYMENT_ORDER_STATUS', (int)Configuration::get('PS_OS_PAYMENT')) &&
        		Configuration::updateValue('SMARTCOIN_CHARGEBACKS_ORDER_STATUS', (int)Configuration::get('PS_OS_ERROR')) &&
            Configuration::updateValue('SMARTCOIN_PAYMENT_OPTION_CREDIT_CARD', 1) &&
            Configuration::updateValue('SMARTCOIN_PAYMENT_OPTION_BANK_SLIP', 1) &&
            Configuration::updateValue('SMARTCOIN_BANK_SLIP_DISCOUNT', 0) &&
            Configuration::updateValue('SMARTCOIN_WEBHOOK_TOKEN', md5(Tools::passwdGen()));

      return $ret;
    }

    public function createSmartcoinPendingOrderStatus() {
      $order_state = new OrderState();
      $order_state->module_name = 'smartcoin';
      $order_state->send_email = true;
      $order_state->color = 'DarkOrange';
      $order_state->hidden = false;
      $order_state->delivery = false;
      $order_state->logable = false;
      $order_state->invoice = false;

      foreach (Language::getLanguages(false) as $language) {
        $order_state->name[(int) $language['id_lang']] = 'Awaiting Payment';
        $order_state->template[$language['id_lang']] = 'awaiting_payment';

        $template = _PS_MAIL_DIR_.$language['iso_code'].'/awaiting_payment.html';    
        if (!file_exists($template)) {
            $templateToCopy = _PS_ROOT_DIR_ . '/modules/smartcoin/mail/awaiting_payment.html';
            copy($templateToCopy, $template);
        }
      }
      
      if (version_compare(_PS_VERSION_, '1.5', '>')) {
          $order_state->unremovable = false;
          $order_state->shipped = false;
          $order_state->paid = false;
      }
      $order_state->add();

      $file = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.gif';
      $image = _PS_ROOT_DIR_ . '/modules/smartcoin/img/pending-icon.gif';
      copy($image, $file);

      Configuration::updateValue('SMARTCOIN_PENDING_ORDER_STATUS', $order_state->id);
      return $order_state;
    }

    /**
  	 * Smartcoin's module database tables installation
  	 *
  	 * @return boolean Database tables installation result
  	 */
    public function installDB() {
      $create_transaction_db = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'smartcoin_transaction` (`id_smartcoin_transaction` int(11) NOT NULL AUTO_INCREMENT,
		  `type` enum(\'payment\',\'refund\') NOT NULL, `id_cart` int(10) unsigned NOT NULL, `id_order` int(10) unsigned NOT NULL, `id_transaction` varchar(32) NOT NULL,
      `amount` decimal(10,2) NOT NULL, `status` enum(\'paid\',\'unpaid\') NOT NULL, `currency` varchar(3) NOT NULL, `charge_type` varchar(32) NOT NULL DEFAULT \'credit_card\',
      `cc_type` varchar(16), `cc_exp` varchar(8), `cc_last_digits` int(11), `installments` int(11), `bank_slip_bar_code` varchar(256), `bank_slip_link` varchar(256),`fee` decimal(10,2) NOT NULL,
      `mode` enum(\'live\',\'test\') NOT NULL, `date_add` datetime NOT NULL, `charge_back` tinyint(1) NOT NULL DEFAULT \'0\',
      PRIMARY KEY (`id_smartcoin_transaction`), KEY `idx_transaction` (`type`,`id_order`,`status`))
		  ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1';

      return Db::getInstance()->Execute($create_transaction_db);
    }

    /**
  	 * Smartcoin's module uninstallation (Configuration values, database tables...)
  	 *
  	 * @return boolean Uninstall result
  	 */
  	public function uninstall() {
  		return parent::uninstall() && Configuration::deleteByName('SMARTCOIN_API_KEY_TEST') && Configuration::deleteByName('SMARTCOIN_API_KEY_LIVE')
  		&& Configuration::deleteByName('SMARTCOIN_MODE') && Configuration::deleteByName('SMARTCOIN_SECRET_KEY_TEST') && Configuration::deleteByName('SMARTCOIN_SECRET_KEY_LIVE') 
      && Configuration::deleteByName('SMARTCOIN_CHARGEBACKS_ORDER_STATUS') && Configuration::deleteByName('SMARTCOIN_PENDING_ORDER_STATUS')
      && Configuration::deleteByName('SMARTCOIN_PAYMENT_ORDER_STATUS') && Configuration::deleteByName('SMARTCOIN_PAYMENT_OPTION_CREDIT_CARD')
      && Configuration::deleteByName('SMARTCOIN_PAYMENT_OPTION_BANK_SLIP') && Configuration::deleteByName('SMARTCOIN_BANK_SLIP_DISCOUNT') && Configuration::deleteByName('SMARTCOIN_WEBHOOK_TOKEN')
      && Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'smartcoin_transaction`');
  	}


    /**
  	 * Display the Smartcoin's payment form
  	 *
  	 * @return string Smartcoin's Smarty template content
  	 */
    public function hookPayment($params) {
      /* If the address check has been enabled by the merchant, we will transmitt the billing address to Smartcoin */
  		if (isset($this->context->cart->id_address_invoice)) {
  			$billing_address = new Address((int)$this->context->cart->id_address_invoice);
  			if ($billing_address->id_state) {
  				$state = new State((int)$billing_address->id_state);
  				if (Validate::isLoadedObject($state))
  					$billing_address->state = $state->iso_code;
  			}
        if ($billing_address->id_country) {
          $country = new Country((int)$billing_address->id_country);
          if (Validate::isLoadedObject($country))
            $billing_address->country = $country->iso_code;
        }
  		}


      if (!empty($this->context->cookie->smartcoin_error)) {
  			$this->smarty->assign('smartcoin_error', $this->context->cookie->smartcoin_error);
  			$this->context->cookie->__set('smartcoin_error', null);
  		}

  		$this->smarty->assign('validation_url', (Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].__PS_BASE_URI__.'index.php?process=validation&fc=module&module=smartcoin&controller=default');
  		$this->smarty->assign('smartcoin_ps_version', _PS_VERSION_);
      $this->smarty->assign('smartcoin_payment_option_credit_card', Configuration::get('SMARTCOIN_PAYMENT_OPTION_CREDIT_CARD'));
      $this->smarty->assign('smartcoin_payment_option_bank_slip', Configuration::get('SMARTCOIN_PAYMENT_OPTION_BANK_SLIP'));
      $customer = new Customer((int)$this->context->cookie->id_customer);

      return '
    		<script type="text/javascript">'.
    			((isset($billing_address) && Validate::isLoadedObject($billing_address)) ? 'var smartcoin_billing_address = '. Tools::jsonEncode($billing_address).';' : '').'
          var ps_customer_email = "'.$customer->email.'";
          if(typeof smartcoin_settings_payments != "undefined")
            smartcoin_settings_payments();
    		</script>'
        .$this->display(__FILE__, './views/templates/hook/payment.tpl');
    }

    /**
  	 * Display a confirmation message after an order has been placed
  	 *
  	 * @param array Hook parameters
  	 */
  	public function hookPaymentReturn($params) {
  		if (!isset($params['objOrder']) || ($params['objOrder']->module != $this->name))
  			return false;

  		if ($params['objOrder'] && Validate::isLoadedObject($params['objOrder']) && isset($params['objOrder']->valid))
        $smartcoin_transaction_details = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'smartcoin_transaction WHERE id_order = '.(int)$params['objOrder']->id .' AND type = \'payment\'');

  			$this->smarty->assign('smartcoin_order', array('reference' => isset($params['objOrder']->reference) ? $params['objOrder']->reference : '#'.sprintf('%06d', $params['objOrder']->id),
                      'valid' => $params['objOrder']->valid, 'bank_slip_bar_code' => $smartcoin_transaction_details['bank_slip_bar_code'],
                      'bank_slip_link' => $smartcoin_transaction_details['bank_slip_link']));

  			// added this so we could present a better/meaningful message to the customer when the charge suceeds, but verifications have failed.
  			$pendingOrderStatus = (int)Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS');
  			$currentOrderStatus = (int)$params['objOrder']->getCurrentState();

  			if ($pendingOrderStatus==$currentOrderStatus)
  				$this->smarty->assign('order_pending', true);
  			else
  				$this->smarty->assign('order_pending', false);

  		return $this->display(__FILE__, './views/templates/hook/order-confirmation.tpl');

  	}


    public function handler_msg_error($message) {
      $msg = $this->l('The payment was not complete.');
      if(strpos($message,'Denied') !== false){
        $msg = $this->l('The payment was not complete. The issuer bank denied authorization.');
      }

      return $msg;
    }

    /**
  	 * Process a payment
  	 *
  	 * @param string $token Smartcoin Transaction ID (token) and installments
  	 */
  	public function processPayment($token=null, $installments=1, $charge_type=null) {
  		/* If 1.4 and no backward, then leave */
  		if (!$this->backward)
  			return;

  		include(dirname(__FILE__).'/lib/Smartcoin.php');
      $api_key = (Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST'));
      $api_secret = (Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_SECRET_KEY_LIVE') : Configuration::get('SMARTCOIN_SECRET_KEY_TEST'));
      \Smartcoin\Smartcoin::api_key($api_key);
      \Smartcoin\Smartcoin::api_secret($api_secret);

  		try {
        $charge_details = array();
        if($charge_type === 'bank_slip'){
          $bank_slip_discount = Configuration::get('SMARTCOIN_BANK_SLIP_DISCOUNT');
          $amount = $this->context->cart->getOrderTotal() * 100;

          if($bank_slip_discount > 0 )
            $amount = $amount - ($amount*($bank_slip_discount/100));
          
          $charge_details = array('amount' => $amount, 'currency' => $this->context->currency->iso_code, 'description' => $this->l('PrestaShop Customer ID:').
        ' '.(int)$this->context->cookie->id_customer.' - '.$this->l('PrestaShop Cart ID:').' '.(int)$this->context->cart->id);
          $charge_details['type'] = $charge_type;
        }else{ //credit card
          $charge_details = array('amount' => $this->context->cart->getOrderTotal() * 100, 'currency' => $this->context->currency->iso_code, 'description' => $this->l('PrestaShop Customer ID:').
        ' '.(int)$this->context->cookie->id_customer.' - '.$this->l('PrestaShop Cart ID:').' '.(int)$this->context->cart->id);  
          $charge_details['card'] = $token;
          $charge_details['installment'] = $installments;
        }

  			$result_json = \Smartcoin\Charge::create($charge_details);

        if($result_json->failure_code != Null){
          $message = $result_json->failure_message;

          if (class_exists('Logger'))
            Logger::addLog($this->l('Smartcoin - Payment transaction failed').' '.$message, 1, null, 'Cart', (int)$this->context->cart->id, true);

          $message = $this->handler_msg_error($result_json->failure_message);
        }

  		// catch the smartcoin error the correct way.
      } catch(\Smartcoin\Error $e) {
  			$body = $e->get_json_body();
  			$err = $body['error'];

  			$message = $err['message'];

  			if (class_exists('Logger'))
  				Logger::addLog($this->l('Smartcoin - Payment transaction failed').' '.$message, 1, null, 'Cart', (int)$this->context->cart->id, true);
  			$this->context->cookie->__set("smartcoin_error", 'There was a problem with your payment');
  			$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
  			$location = $this->context->link->getPageLink($controller).(strpos($controller, '?') !== false ? '&' : '?').'step=3#smartcoin_error';
  			header('Location: '.$location);
  			exit;

  		} catch (Exception $e) {
  			$message = $e->getMessage();
  			if (class_exists('Logger'))
  				Logger::addLog($this->l('Smartcoin - Payment transaction failed').' '.$message, 1, null, 'Cart', (int)$this->context->cart->id, true);

  			/* If it's not a critical error, display the payment form again */
  			if ($e->getCode() != 'card_declined') {
  				$this->context->cookie->__set("smartcoin_error",$e->getMessage());
  				$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
  				header('Location: '.$this->context->link->getPageLink($controller).(strpos($controller, '?') !== false ? '&' : '?').'step=3#smartcoin_error');
  				exit;
  			}
  		}

  		/* Log Transaction details */
  		if (!isset($message)) {
  			if (!isset($result_json->fees))
  				$result_json->fee = 0;
        else {
          $total_fee = 0;
          foreach($result_json->fees as $fee) {
            $total_fee += $fee->amount;
          }
          $result_json->fee = $total_fee;
        }

  			$order_status = (int)Configuration::get('SMARTCOIN_PAYMENT_ORDER_STATUS');
        if($charge_type === 'bank_slip'){
          $order_status = (int)Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS');
        }

        $payment_message = '';
        if($charge_type === 'bank_slip'){
          $payment_message = $this->l('Bank Slip (bar code):').' '. $result_json->bank_slip->bar_code ."\n".
          $this->l('Bank Slip (link):').' '. $result_json->bank_slip->link ."\n";
        }else{
          $payment_message = $this->l('Credit card:').' '.$result_json->card->type.' ('.$this->l('Exp.:').' '.$result_json->card->exp_month.'/'.$result_json->card->exp_year.')'."\n".
          $this->l('Last 4 digits:').' '.sprintf('%04d', $result_json->card->last4).' ('.$this->l('CVC Check:').' '.($result_json->card->cvc_check == 'pass' ? $this->l('OK') : $this->l('NOT OK')).')'."\n".
          $this->l('Installments:').' '. (int)count($result_json->installments)."\n";
        }

  			$message = $this->l('Smartcoin Transaction Details:')."\n\n".
  			$this->l('Smartcoin Transaction ID:').' '.$result_json->id."\n".
  			$this->l('Amount:').' '.($result_json->amount * 0.01)."\n".
  			$this->l('Status:').' '.($result_json->paid == 'true' ? $this->l('Paid') : $this->l('Unpaid'))."\n".
  			$this->l('Processed on:').' '.strftime('%Y-%m-%d %H:%M:%S', $result_json->created)."\n".
  			$this->l('Currency:').' '. Tools::strtoupper($result_json->currency)."\n".
        $payment_message .
        $this->l('Processing Fee:').' '.($result_json->fee * 0.01)."\n".
  			$this->l('Mode:').' '.($result_json->livemode == 'true' ? $this->l('Live') : $this->l('Test'))."\n";

  			/* In case of successful payment, the address / zip-code can however fail */
  			if (isset($result_json->card->address_line1_check) && $result_json->card->address_line1_check == 'fail') {
  				$message .= "\n".$this->l('Warning: Address line 1 check failed');
  				$order_status = (int)Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS');
  			}
  			if (isset($result_json->card->address_zip_check) && $result_json->card->address_zip_check == 'fail') {
  				$message .= "\n".$this->l('Warning: Address zip-code check failed');
  				$order_status = (int)Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS');
  			}
  			// warn if cvc check fails
  			if (isset($result_json->card->cvc_check) && $result_json->card->cvc_check == 'fail') {
  				$message .= "\n".$this->l('Warning: CVC verification check failed');
  				$order_status = (int)Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS');
  			}
  		}
  		else
  			$order_status = (int)Configuration::get('PS_OS_ERROR');

  		/* Create the PrestaShop order in database */
  		$this->validateOrder((int)$this->context->cart->id, (int)$order_status, ($result_json->amount * 0.01), $this->displayName, $message, array(), null, false, $this->context->customer->secure_key);

  		/** @since 1.5.0 Attach the Smartcoin Transaction ID to this Order */
  		if (version_compare(_PS_VERSION_, '1.5', '>=')) {
  			$new_order = new Order((int)$this->currentOrder);
  			if (Validate::isLoadedObject($new_order)) {
  				$payment = $new_order->getOrderPaymentCollection();
  				if (isset($payment[0])) {
  					$payment[0]->transaction_id = pSQL($result_json->id);
  					$payment[0]->save();
  				}
  			}
  		}

  		/* Store the transaction details */
  		if (isset($result_json->id))
        if($charge_type === 'bank_slip'){
          $sql = '
          INSERT INTO '._DB_PREFIX_.'smartcoin_transaction (type, id_cart, id_order,
          id_transaction, amount, status, currency, charge_type, bank_slip_bar_code, bank_slip_link, fee, mode, date_add)
          VALUES (\'payment\', '.(int)$this->context->cart->id.', '.(int)$this->currentOrder.', \''.pSQL($result_json->id).'\',
          \''.($result_json->amount * 0.01).'\', \''.($result_json->paid == 'true' ? 'paid' : 'unpaid').'\', \''.pSQL($result_json->currency).'\',
          \''.$charge_type.'\', \''.$result_json->bank_slip->bar_code.'\', \''.$result_json->bank_slip->link.'\', \''.($result_json->fee * 0.01).'\', \''.($result_json->livemode == 'true' ? 'live' : 'test').'\', NOW())';
          error_log($sql);
          Db::getInstance()->Execute($sql);
        }else{
    			Db::getInstance()->Execute('
    			INSERT INTO '._DB_PREFIX_.'smartcoin_transaction (type, id_cart, id_order,
    			id_transaction, amount, status, currency, cc_type, cc_exp, cc_last_digits, installments, fee, mode, date_add)
    			VALUES (\'payment\', '.(int)$this->context->cart->id.', '.(int)$this->currentOrder.', \''.pSQL($result_json->id).'\',
    			\''.($result_json->amount * 0.01).'\', \''.($result_json->paid == 'true' ? 'paid' : 'unpaid').'\', \''.pSQL($result_json->currency).'\',
    			\''.pSQL($result_json->card->type).'\', \''.(int)$result_json->card->exp_month.'/'.(int)$result_json->card->exp_year.'\', '.(int)$result_json->card->last4.',
    			\''.(int)count($result_json->installments).'\', \''.($result_json->fee * 0.01).'\', \''.($result_json->livemode == 'true' ? 'live' : 'test').'\', NOW())');
        }
  		/* Redirect the user to the order confirmation page / history */
  		if (_PS_VERSION_ < 1.5)
  			$redirect = __PS_BASE_URI__.'order-confirmation.php?id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->id.'&id_order='.(int)$this->currentOrder.'&key='.$this->context->customer->secure_key;
  		else
  			$redirect = __PS_BASE_URI__.'index.php?controller=order-confirmation&id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->id.'&id_order='.(int)$this->currentOrder.'&key='.$this->context->customer->secure_key;

  		header('Location: '.$redirect);
  		exit;
  	}
  /**
  	 * Process a partial or full refund
  	 *
  	 * @param string $id_transaction_smartcoin Smartcoin Transaction ID (token)
  	 * @param float $amount Amount to refund
  	 * @param array $original_transaction Original transaction details
  	 */
  	public function processRefund($id_transaction_smartcoin, $amount, $original_transaction) {
  		/* If 1.4 and no backward, then leave */
  		if (!$this->backward)
  			return;

  		include(dirname(__FILE__).'/lib/Smartcoin.php');
      $access_key = Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') .':'. Configuration::get('SMARTCOIN_SECRET_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST') .':'. Configuration::get('SMARTCOIN_SECRET_KEY_TEST');

  		/* Try to process the refund and catch any error message */
  		try {
        $api_key = (Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST'));
        $api_secret = (Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_SECRET_KEY_LIVE') : Configuration::get('SMARTCOIN_SECRET_KEY_TEST'));
        \Smartcoin\Smartcoin::api_key($api_key);
        \Smartcoin\Smartcoin::api_secret($api_secret);
  			$charge = \Smartcoin\Charge::retrieve($id_transaction_smartcoin, $access_key);
  			$result_json = $charge->refund(array('amount' => $amount * 100));
  		}
  		catch (Exception $e) {
  			$this->_errors['smartcoin_refund_error'] = $e->getMessage();
  			if (class_exists('Logger'))
  				Logger::addLog($this->l('Smartcoin - Refund transaction failed').' '.$e->getMessage(), 2, null, 'Cart', (int)$this->context->cart->id, true);
  		}

  		/* Store the refund details */
  		Db::getInstance()->Execute('
  		  INSERT INTO '._DB_PREFIX_.'smartcoin_transaction (type, id_cart, id_order, id_transaction,
        amount, status, currency, cc_type, cc_exp, cc_last_digits, installments, fee, mode, date_add)
  		    VALUES (\'refund\', '.(int)$original_transaction['id_cart'].', '.
  		(int)$original_transaction['id_order'].', \''.pSQL($id_transaction_smartcoin).'\',
  		\''.(float)$amount.'\', \''.(!isset($this->_errors['smartcoin_refund_error']) ? 'paid' : 'unpaid').'\', \''.pSQL($result_json->currency).'\',
  		\'\', \'\', 0, 0, 0, \''.(Configuration::get('smartcoin_MODE') ? 'live' : 'test').'\', NOW())');
  	}


    /**
  	 * Display the two fieldsets containing Smartcoin's transactions details
  	 * Visible on the Order's detail page in the Back-office only
  	 *
  	 * @return string HTML/JS Content
  	 */
  	public function hookBackOfficeHeader() {
      /* If 1.4 and no backward, then leave */
  		if (!$this->backward)
  			return;

  		/* Continue only if we are on the order's details page (Back-office) */
  		if (!Tools::getIsset('vieworder') || !Tools::getIsset('id_order'))
  			return;

  		/* If the "Refund" button has been clicked, check if we can perform a partial or full refund on this order */
  		if (Tools::isSubmit('SubmitSmartcoinRefund') && Tools::getIsset('smartcoin_amount_to_refund') && Tools::getIsset('id_transaction_smartcoin')) {
  			/* Get transaction details and make sure the token is valid */
  			$smartcoin_transaction_details = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'smartcoin_transaction WHERE id_order = '.(int)Tools::getValue('id_order').' AND type = \'payment\' AND status = \'paid\'');
  			if (isset($smartcoin_transaction_details['id_transaction']) && $smartcoin_transaction_details['id_transaction'] === Tools::getValue('id_transaction_smartcoin')) {
  				/* Check how much has been refunded already on this order */
  				$smartcoin_refunded = Db::getInstance()->getValue('SELECT SUM(amount) FROM '._DB_PREFIX_.'smartcoin_transaction WHERE id_order = '.(int)Tools::getValue('id_order').' AND type = \'refund\' AND status = \'paid\'');
  				if (Tools::getValue('smartcoin_amount_to_refund') <= number_format($smartcoin_transaction_details['amount'] - $smartcoin_refunded, 2, '.', ''))
  					$this->processRefund(Tools::getValue('id_transaction_smartcoin'), (float)Tools::getValue('smartcoin_amount_to_refund'), $smartcoin_transaction_details);
  				else
  					$this->_errors['smartcoin_refund_error'] = $this->l('You cannot refund more than').' '.Tools::displayPrice($smartcoin_transaction_details['amount'] - $smartcoin_refunded).' '.$this->l('on this order');
  			}
  		}

      /* Check if the order was paid with Smartcoin and display the transaction details */
  		if(Db::getInstance()->getValue('SELECT module FROM '._DB_PREFIX_.'orders WHERE id_order = '.(int)Tools::getValue('id_order')) == $this->name) {
  			/* Get the transaction details */
  			$smartcoin_transaction_details = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'smartcoin_transaction WHERE id_order = '.(int)Tools::getValue('id_order').' AND type = \'payment\'');

  			/* Get all the refunds previously made (to build a list and determine if another refund is still possible) */
  			$smartcoin_refunded = 0;
  			$output_refund = '';
  			$smartcoin_refund_details = Db::getInstance()->ExecuteS('SELECT amount, status, date_add FROM '._DB_PREFIX_.'smartcoin_transaction
  			  WHERE id_order = '.(int)Tools::getValue('id_order').' AND type = \'refund\' ORDER BY date_add DESC');
  			foreach ($smartcoin_refund_details as $smartcoin_refund_detail) {
  				$smartcoin_refunded += ($smartcoin_refund_detail['status'] == 'paid' ? $smartcoin_refund_detail['amount'] : 0);
  				$output_refund .= '<tr'.($smartcoin_refund_detail['status'] != 'paid' ? ' style="background: #FFBBAA;"': '').'><td>'.
  				Tools::safeOutput($smartcoin_refund_detail['date_add']).'</td><td style="">'.Tools::displayPrice($smartcoin_refund_detail['amount']).
  				    '</td><td>'.($smartcoin_refund_detail['status'] == 'paid' ? $this->l('Processed') : $this->l('Error')).'</td></tr>';
  			}
  			$currency = $this->context->currency;
  			$c_char = $currency->sign;
  			$output = '
  			<script type="text/javascript">
  				$(document).ready(function() {
  					var appendEl;
  					if ($(\'select[name=id_order_state]\').is(":visible")) {
  						appendEl = $(\'select[name=id_order_state]\').parents(\'form\').after($(\'<div/>\'));
  					} else {
  						appendEl = $("#status");
  					}
  					$(\'<fieldset'.(_PS_VERSION_ < 1.5 ? ' style="width: 400px;"' : '').'><legend><img src="../img/admin/money.gif" alt="" />'.$this->l('Smartcoin Payment Details').'</legend>';

  			if (isset($smartcoin_transaction_details['id_transaction'])){
          $payment_output = '';
          if($smartcoin_transaction_details['charge_type'] !== 'bank_slip'){
            $payment_output = $this->l('Credit card:').' '.Tools::safeOutput($smartcoin_transaction_details['cc_type']).' ('.$this->l('Exp.:').' '.Tools::safeOutput($smartcoin_transaction_details['cc_exp']).')<br />'.
                              $this->l('Last 4 digits:').' '.sprintf('%04d', $smartcoin_transaction_details['cc_last_digits']).' <br />'.
                              $this->l('Installments:').' '.sprintf('%d',$smartcoin_transaction_details['installments']).'<br />';
          }

  				$output .= $this->l('Smartcoin Transaction ID:').' '.Tools::safeOutput($smartcoin_transaction_details['id_transaction']).'<br /><br />'.
  				$this->l('Status:').' <span style="font-weight: bold; color: '.($smartcoin_transaction_details['status'] == 'paid' ? 'green;">'.$this->l('Paid') : '#CC0000;">'.$this->l('Unpaid')).'</span><br />'.
  				$this->l('Amount:').' '.Tools::displayPrice($smartcoin_transaction_details['amount']).'<br />'.
  				$this->l('Processed on:').' '.Tools::safeOutput($smartcoin_transaction_details['date_add']).'<br />'.
          $payment_output .
          $this->l('Processing Fee:').' '.Tools::displayPrice($smartcoin_transaction_details['fee']).'<br /><br />'.
  				$this->l('Mode:').' <span style="font-weight: bold; color: '.($smartcoin_transaction_details['mode'] == 'live' ? 'green;">'.$this->l('Live') : '#CC0000;">'.$this->l('Test (You will not receive any payment, until you enable the "Live" mode)')).'</span>';
  			}else{
  				$output .= '<b style="color: #CC0000;">'.$this->l('Warning:').'</b> '.$this->l('The customer paid using Smartcoin and an error occured (check details at the bottom of this page)');
        }

  			$output .= '</fieldset><br />';
        if($smartcoin_transaction_details['charge_type'] !== 'bank_slip'){
          $output .= '<fieldset'.(_PS_VERSION_ < 1.5 ? ' style="width: 400px;"' : '').'><legend><img src="../img/admin/money.gif" alt="" />'.$this->l('Proceed to a full or partial refund via Smartcoin').'</legend>'.
                  ((empty($this->_errors['smartcoin_refund_error']) &&  Tools::getIsset('id_transaction_smartcoin')) ? '<div class="conf confirmation">'.$this->l('Your refund was successfully processed').'</div>' : '').
                  (!empty($this->_errors['smartcoin_refund_error']) ? '<span style="color: #CC0000; font-weight: bold;">'.$this->l('Error:').' '.Tools::safeOutput($this->_errors['smartcoin_refund_error']).'</span><br /><br />' : '').
                  $this->l('Already refunded:').' <b>'.Tools::displayPrice($smartcoin_refunded).'</b><br /><br />'.($smartcoin_refunded ? '<table class="table" cellpadding="0" cellspacing="0" style="font-size: 12px;"><tr><th>'.$this->l('Date').'</th><th>'.$this->l('Amount refunded').'</th><th>'.$this->l('Status').'</th></tr>'.$output_refund.'</table><br />' : '').
                  ($smartcoin_transaction_details['amount'] > $smartcoin_refunded ? '<form action="" method="post">'.$this->l('Refund:'). ' ' . $c_char .' <input type="text" value="'.number_format($smartcoin_transaction_details['amount'] - $smartcoin_refunded, 2, '.', '').
                  '" name="smartcoin_amount_to_refund" style="display: inline-block; width: 60px;" /> <input type="hidden" name="id_transaction_smartcoin" value="'.
                  Tools::safeOutput($smartcoin_transaction_details['id_transaction']).'" /><input type="submit" class="button" onclick="return confirm(\\\''.addslashes($this->l('Do you want to proceed to this refund?')).'\\\');" name="SubmitSmartcoinRefund" value="'.
                  $this->l('Process Refund').'" /></form>' : '').'</fieldset><br />';
        }
        
        $output .= '\').appendTo(appendEl); }); </script>';

  			return $output;
  		}
    }


    /**
  	 * Load Javascripts and CSS related to the Smartcoin's module
  	 *
  	 * @return string HTML/JS Content
  	 */
    public function hookHeader() {
      $output = '<script type="text/javascript">
                  var _smartcoin_api_key = "'.addslashes(Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST')).'";
                  var headTag = document.getElementsByTagName("head")[0];
                  var smartTag = document.createElement("script");
                  smartTag.type = "text/javascript";
                  smartTag.async = false;
                  smartTag.src = "https://js.smartcoin.com.br/v1/smartcoin.js";
                  headTag.appendChild(smartTag);
                </script>';
  		/* Continue only if we are in the checkout process */
  		if (Tools::getValue('controller') == 'order-opc' || ($_SERVER['PHP_SELF'] == __PS_BASE_URI__.'order.php' || $_SERVER['PHP_SELF'] == __PS_BASE_URI__.'order-opc.php' || Tools::getValue('controller') == 'order' || Tools::getValue('controller') == 'orderopc' || Tools::getValue('step') == 3)) {
        /* Load JS and CSS files through CCC */
        $this->context->controller->addCSS($this->_path.'css/smartcoin-card.css');
        $this->context->controller->addCSS($this->_path.'css/smartcoin-prestashop.css');
        $output .= '<script type="text/javascript">
                      var headTag = document.getElementsByTagName("head")[0];
                      var smartTag = document.createElement("script");
                      smartTag.type = "text/javascript";
                      smartTag.async = false;
                      smartTag.src = "'. $this->_path .'js/smartcoin-card.js";
                      headTag.appendChild(smartTag);
                  </script>
                  <script type="text/javascript">
                    var headTag = document.getElementsByTagName("head")[0];
                    var smartTag = document.createElement("script");
                    smartTag.type = "text/javascript";
                    smartTag.async = false;
                    smartTag.src = "'. $this->_path .'js/smartcoin-prestashop.js";
                    headTag.appendChild(smartTag);
                  </script>';
      }

      return $output;
  	}


    /**
  	 * Check settings requirements to make sure the Smartcoin's module will work properly
  	 *
  	 * @return boolean Check result
  	 */
  	public function checkSettings() {
  		if (Configuration::get('SMARTCOIN_MODE'))
  			return Configuration::get('SMARTCOIN_API_KEY_LIVE') != '' && Configuration::get('SMARTCOIN_SECRET_KEY_LIVE') != '';
  		else
  			return Configuration::get('SMARTCOIN_API_KEY_TEST') != '' && Configuration::get('SMARTCOIN_SECRET_KEY_TEST') != '';
  	}

    /**
  	 * Check technical requirements to make sure the Smartcoin's module will work properly
  	 *
  	 * @return array Requirements tests results
  	 */
  	public function checkRequirements() {
  		$tests = array('result' => true);
  		$tests['curl'] = array('name' => $this->l('PHP cURL extension must be enabled on your server'), 'result' => extension_loaded('curl'));
  		if (Configuration::get('SMARTCOIN_MODE'))
  			$tests['ssl'] = array('name' => $this->l('SSL must be enabled on your store (before entering Live mode)'), 'result' => Configuration::get('PS_SSL_ENABLED') || (!empty($_SERVER['HTTPS']) && Tools::strtolower($_SERVER['HTTPS']) != 'off'));
  		$tests['php52'] = array('name' => $this->l('Your server must run PHP 5.2 or greater'), 'result' => version_compare(PHP_VERSION, '5.2.0', '>='));
  		$tests['configuration'] = array('name' => $this->l('You must sign-up for Smartcoin and configure your account settings in the module (api key, secret key...etc.)'), 'result' => $this->checkSettings());

  		if (_PS_VERSION_ < 1.5)
  		{
  			$tests['backward'] = array('name' => $this->l('You are using the backward compatibility module'), 'result' => $this->backward, 'resolution' => $this->backward_error);
  			$tmp = Module::getInstanceByName('mobile_theme');
  			if ($tmp && isset($tmp->version) && !version_compare($tmp->version, '0.3.8', '>='))
  				$tests['mobile_version'] = array('name' => $this->l('You are currently using the default mobile template, the minimum version required is v0.3.8').' (v'.$tmp->version.' '.$this->l('detected').' - <a target="_blank" href="http://addons.prestashop.com/en/mobile-iphone/6165-prestashop-mobile-template.html">'.$this->l('Please Upgrade').'</a>)', 'result' => version_compare($tmp->version, '0.3.8', '>='));
  		}

  		foreach ($tests as $k => $test)
  			if ($k != 'result' && !$test['result'])
  				$tests['result'] = false;

  		return $tests;
  	}

    /**
	   * Display the Back-office interface of the Smartcoin's module
	   *
	   * @return string HTML/JS Content
	   */
    public function getContent() {
      $output = '';

  		if (version_compare(_PS_VERSION_, '1.5', '>'))
  			$this->context->controller->addJQueryPlugin('fancybox');
  		else
  			$output .= '
  			     <script type="text/javascript" src="'.__PS_BASE_URI__.'js/jquery/jquery.fancybox-1.3.4.js"></script>
  		  	   <link type="text/css" rel="stylesheet" href="'.__PS_BASE_URI__.'css/jquery.fancybox-1.3.4.css" />';


  		$requirements = $this->checkRequirements();
  		$errors = array();
  		/* Update Configuration Values when settings are updated */
  		if (Tools::isSubmit('SubmitSmartcoin')) {
  			if (strpos(Tools::getValue('smartcoin_api_key_test'), "sk") !== false || strpos(Tools::getValue('smartcoin_api_key_live'), "sk") !== false ) {
  				$errors[] = "You've entered your private key in the public key field!";
  			}
  			if (empty($errors)) {
  				$configuration_values = array(
  					'SMARTCOIN_MODE' => Tools::getValue('smartcoin_mode'),
  					'SMARTCOIN_API_KEY_TEST' => trim(Tools::getValue('smartcoin_api_key_test')),
  					'SMARTCOIN_API_KEY_LIVE' => trim(Tools::getValue('smartcoin_api_key_live')),
  					'SMARTCOIN_SECRET_KEY_TEST' => trim(Tools::getValue('smartcoin_secret_key_test')),
  					'SMARTCOIN_SECRET_KEY_LIVE' => trim(Tools::getValue('smartcoin_secret_key_live')),
  					'SMARTCOIN_PENDING_ORDER_STATUS' => (int)Tools::getValue('smartcoin_pending_status'),
  					'SMARTCOIN_PAYMENT_ORDER_STATUS' => (int)Tools::getValue('smartcoin_payment_status'),
  					'SMARTCOIN_CHARGEBACKS_ORDER_STATUS' => (int)Tools::getValue('smartcoin_chargebacks_status'),
            'SMARTCOIN_PAYMENT_OPTION_CREDIT_CARD' => (int)Tools::getValue('smartcoin_payment_option_credit_card'),
            'SMARTCOIN_PAYMENT_OPTION_BANK_SLIP' => (int)Tools::getValue('smartcoin_payment_option_bank_slip'),
            'SMARTCOIN_BANK_SLIP_DISCOUNT' => (int)Tools::getValue('smartcoin_bank_slip_discount')
  				);

  				foreach ($configuration_values as $configuration_key => $configuration_value)
  					Configuration::updateValue($configuration_key, $configuration_value);
  			}
  		}

      $output .= '
  		<script type="text/javascript">
  			/* Fancybox */
  			$(\'a.smartcoin-module-video-btn\').live(\'click\', function(){
  			    $.fancybox({\'type\' : \'iframe\', \'href\' : this.href.replace(new RegExp(\'watch\\?v=\', \'i\'), \'embed/\') + \'?rel=0&autoplay=1\',
  			    \'swf\': {\'allowfullscreen\':\'true\', \'wmode\':\'transparent\'}, \'overlayShow\' : true, \'centerOnScroll\' : true,
  			    \'speedIn\' : 100, \'speedOut\' : 50, \'width\' : 853, \'height\' : 480 });
  			    return false;
  			});
  		</script>
  		<link href="'.$this->_path.'css/smartcoin-prestashop-admin.css" rel="stylesheet" type="text/css" media="all" />
  		<div class="smartcoin-module-wrapper">
  			'.(Tools::isSubmit('SubmitSmartcoin') ? '<div class="conf confirmation">'.$this->l('Settings successfully saved').'<img src="http://www.prestashop.com/modules/'.$this->name.'.png?api_user='.urlencode($_SERVER['HTTP_HOST']).'" style="display: none;" /></div>' : '').'
  			<div class="smartcoin-module-header">
  				<a href="https://manage.smartcoin.com.br/signup" rel="external"><img src="'.$this->_path.'img/smartcoin-logo.gif" alt="smartcoin" class="smartcoin-logo" /></a>
  				<span class="smartcoin-module-intro">'.$this->l('Smartcoin makes it easy to start accepting credit cards on the web today.').'</span>
  				<a href="https://manage.smartcoin.com.br/signup" rel="external" target="_blank" class="smartcoin-module-create-btn"><span>'.$this->l('Create an Account').'</span></a>
  			</div>
  			<fieldset>
  				<legend><img src="'.$this->_path.'img/checks-icon.gif" alt="" />'.$this->l('Technical Checks').'</legend>
  				<div class="'.($requirements['result'] ? 'conf">'.$this->l('Good news! All the checks were successfully performed. You can now configure your module and start using Smartcoin.') :
  				'warn">'.$this->l('Unfortunately, at least one issue is preventing you from using Smartcoin. Please fix the issue and reload this page.')).'</div>
  				<table cellspacing="0" cellpadding="0" class="smartcoin-technical">';
  				foreach ($requirements as $k => $requirement)
  					if ($k != 'result')
  						$output .= '
  						<tr>
  							<td><img src="../img/admin/'.($requirement['result'] ? 'ok' : 'forbbiden').'.gif" alt="" /></td>
  							<td>'.$requirement['name'].(!$requirement['result'] && isset($requirement['resolution']) ? '<br />'.Tools::safeOutput($requirement['resolution'], true) : '').'</td>
  						</tr>';
  				$output .= '
  				</table>
  			</fieldset>
  		<br />';


      if (!empty($errors)) {
  			$output .= '
  			<fieldset>
  				<legend>Errors</legend>
  				<table cellspacing="0" cellpadding="0" class="smartcoin-technical">
  						<tbody>
  						';
  					foreach ($errors as $error) {
  							$output .= '
  						<tr>
  							<td><img src="../img/admin/forbbiden.gif" alt=""></td>
  							<td>'. $error .'</td>
  						</tr>';
  					}
  				$output .= '
  				</tbody></table>
  			</fieldset>';
  		}


      /* If 1.4 and no backward, then leave */
  		if (!$this->backward)
  			return $output;

  		$statuses = OrderState::getOrderStates((int)$this->context->cookie->id_lang);
  		$output .= '
  		<form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post">
  			<fieldset class="smartcoin-settings">
  				<legend><img src="'.$this->_path.'img/technical-icon.gif" alt="" />'.$this->l('Settings').'</legend>
  				<label>'.$this->l('Mode').'</label>
  				<input type="radio" name="smartcoin_mode" value="0"'.(!Configuration::get('SMARTCOIN_MODE') ? ' checked="checked"' : '').' /> Test
  				<input type="radio" name="smartcoin_mode" value="1"'.(Configuration::get('SMARTCOIN_MODE') ? ' checked="checked"' : '').' /> Live
  				<br /><br />
  				<table cellspacing="0" cellpadding="0" class="smartcoin-settings">
  					<tr>
  						<td align="center" valign="middle" colspan="2">
  							<table cellspacing="0" cellpadding="0" class="innerTable">
  								<tr>
  									<td align="right" valign="middle">'.$this->l('Test API Key').'</td>
  									<td align="left" valign="middle"><input type="text" name="smartcoin_api_key_test" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_API_KEY_TEST')).'" /></td>
  									<td width="15"></td>
  									<td width="15" class="vertBorder"></td>
  									<td align="left" valign="middle">'.$this->l('Live API Key').'</td>
  									<td align="left" valign="middle"><input type="text" name="smartcoin_api_key_live" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_API_KEY_LIVE')).'" /></td>
  								</tr>
  								<tr>
  									<td align="right" valign="middle">'.$this->l('Test API Secret').'</td>
  									<td align="left" valign="middle"><input type="password" name="smartcoin_secret_key_test" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_SECRET_KEY_TEST')).'" /></td>
  									<td width="15"></td>
  									<td width="15" class="vertBorder"></td>
  									<td align="left" valign="middle">'.$this->l('Live API Secret').'</td>
  									<td align="left" valign="middle"><input type="password" name="smartcoin_secret_key_live" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_SECRET_KEY_LIVE')).'" /></td>
  								</tr>
  							</table>
  						</td>
  					</tr>';

      $output .= '
        <td align="center" valign="middle" colspan="2">
          <table cellspacing="0" cellpadding="0" class="innerTable">
            <tr>
              <td align="right" valign="middle">'.$this->l('Payment Options:').'</td>
              <td align="right" valign="middle">'.$this->l('Credit Card').'</td>
              <td>
                <input type="checkbox" name="smartcoin_payment_option_credit_card" value="1" ' . (Configuration::get('SMARTCOIN_PAYMENT_OPTION_CREDIT_CARD') ? ' checked="checked"' : '') . ' />
              <td>
              <td align="right" valign="middle">'.$this->l('Bank Slip').'</td>
              <td>
                <input type="checkbox" name="smartcoin_payment_option_bank_slip" value="1" ' . (Configuration::get('SMARTCOIN_PAYMENT_OPTION_BANK_SLIP') ? ' checked="checked"' : '') . ' />
              <td>
            <tr>
          </table>
        </tr>';

      $output .= '
        <td align="center" valign="middle" colspan="2">
          <table cellspacing="0" cellpadding="0" class="innerTable">
            <tr>
              <td align="right" valign="middle">'.$this->l('Discount for Bank Slip:').'</td>
              <td align="right" valign="middle">
                <input type="number" name="smartcoin_bank_slip_discount" value="'.Configuration::get('SMARTCOIN_BANK_SLIP_DISCOUNT').'" style="width: 30px;" />%
              </td>
            </tr>
          </table>
        </tr>
      ';

      $statuses_options = array(array('name' => 'smartcoin_payment_status', 'label' => $this->l('Order status in case of sucessfull payment:'), 'current_value' => Configuration::get('SMARTCOIN_PAYMENT_ORDER_STATUS')),
			array('name' => 'smartcoin_pending_status', 'label' => $this->l('Order status in case of unsucessfull address/zip-code check:'), 'current_value' => Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS')),
			array('name' => 'smartcoin_chargebacks_status', 'label' => $this->l('Order status in case of a chargeback (dispute):'), 'current_value' => Configuration::get('SMARTCOIN_CHARGEBACKS_ORDER_STATUS')));
			foreach ($statuses_options as $status_options)
			{
				$output .= '
				<tr>
					<td align="right" valign="middle"><label>'.$status_options['label'].'</label></td>
					<td align="left" valign="middle" class="td-right">
						<select name="'.$status_options['name'].'">';
							foreach ($statuses as $status)
								$output .= '<option value="'.(int)$status['id_order_state'].'"'.($status['id_order_state'] == $status_options['current_value'] ? ' selected="selected"' : '').'>'.Tools::safeOutput($status['name']).'</option>';
				$output .= '
						</select>
					</td>
				</tr>';
			}

      $output .= '
					<tr>
						<td colspan="2" class="td-noborder save"><input type="submit" class="button" name="SubmitSmartcoin" value="'.$this->l('Save Settings').'" /></td>
					</tr>
				</table>
			</fieldset>
			<fieldset class="smartcoin-cc-numbers">
				<legend><img src="'.$this->_path.'img/cc-icon.gif" alt="" />'.$this->l('Test Credit Card Numbers').'</legend>
				<table cellspacing="0" cellpadding="0" class="smartcoin-cc-numbers">
				  <thead>
					<tr>
					  <th>'.$this->l('Number').'</th>
					  <th>'.$this->l('Card type').'</th>
					</tr>
				  </thead>
				  <tbody>
					<tr><td class="number"><code>4242424242424242</code></td><td>Visa</td></tr>
					<tr><td class="number"><code>5555555555554444</code></td><td>MasterCard</td></tr>
					<tr><td class="number"><code>378282246310005</code></td><td>American Express</td></tr>
					<tr><td class="number"><code>6011111111111117</code></td><td>Discover</td></tr>
					<tr><td class="number"><code>30569309025904</code></td><td>Diner\'s Club</td></tr>
					<tr><td class="number last"><code>3530111333300000</code></td><td class="last">JCB</td></tr>
				  </tbody>
				</table>
			</fieldset>
			<div class="clear"></div>
			<br />
      <fieldset>
        <legend><img src="'.$this->_path.'img/checks-icon.gif" alt="" />'.$this->l('Webhooks').'</legend>
        '.$this->l('In order to receive charge updates from Smartcoin, you must provide a Webhook link in Smartcoin\'s admin panel.').'<br />
        '.$this->l('To get started, please visit Smartcoin and setup the following Webhook in Menu->Settings->Webhooks:').'<br /><br />
        <strong>'.(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].__PS_BASE_URI__.'index.php?process=webhook&fc=module&module=smartcoin&controller=default&token='.Tools::safeOutput(Configuration::get('SMARTCOIN_WEBHOOK_TOKEN')).'</strong>
      </fieldset>
		</div>
		</form>
		<script type="text/javascript">
			function update_smartcoin_settings()
			{
				if ($(\'input:radio[name=smartcoin_mode]:checked\').val() == 1)
					$(\'fieldset.smartcoin-cc-numbers\').hide();
				else
					$(\'fieldset.smartcoin-cc-numbers\').show(1000);
			}

			$(\'input:radio[name=smartcoin_mode]\').click(function() { update_smartcoin_settings(); });
			$(document).ready(function() { update_smartcoin_settings(); });
		</script>';

      return $output;
    }
  }
?>
