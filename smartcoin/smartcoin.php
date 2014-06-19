<?php

  if (!defined('_PS_VERSION_'))
    exit;

  class SmartCoin extends PaymentModule {
    public function __construct() {
      $this->name = 'smartcoin';
      $this->tab = "payments_gateways";
      $this->version = '0.0.1';
      $this->author = "SmartCoin LTDA.";
      $this->need_instance = 0;

      parent::__construct();

      $this->displayName = $this->l('SmartCoin');
      $this->description = $this->l('Accept payments with SmartCoin');
      $this->confirmUninstall = $this->l('Warning: Are you sure you want uninstall this module?');
      $this->backward = true;

    }


    /**
  	 * SmartCoin's module installation
  	 *
  	 * @return boolean Install result
  	 */
    public function install(){
      $ret = parent::install() && $this->registerHook('payment') && $this->registerHook('header') &&
            $this->registerHook('paymentReturn') && $this->installDB() &&
            Configuration::updateValue('SMARTCOIN_MODE', 0) &&
            Configuration::updateValue('SMARTCOIN_PENDING_ORDER_STATUS', (int)Configuration::get('PS_OS_PAYMENT')) &&
        		Configuration::updateValue('SMARTCOIN_PAYMENT_ORDER_STATUS', (int)Configuration::get('PS_OS_PAYMENT')) &&
        		Configuration::updateValue('SMARTCOIN_CHARGEBACKS_ORDER_STATUS', (int)Configuration::get('PS_OS_ERROR'));

      return $ret;
    }


    /**
  	 * SmartCoin's module database tables installation
  	 *
  	 * @return boolean Database tables installation result
  	 */
    public function installDB() {
      $create_transaction_db = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'smartcoin_transaction` (`id_smartcoin_transaction` int(11) NOT NULL AUTO_INCREMENT,
		  `type` enum(\'payment\',\'refund\') NOT NULL, `id_order` int(10) unsigned NOT NULL, `id_transaction` varchar(32) NOT NULL,
      `amount` decimal(10,2) NOT NULL, `status` enum(\'paid\',\'unpaid\') NOT NULL, `currency` varchar(3) NOT NULL,
      `cc_type` varchar(16) NOT NULL, `cc_exp` varchar(8) NOT NULL, `cc_last_digits` int(11) NOT NULL, `fee` decimal(10,2) NOT NULL,
      `mode` enum(\'live\',\'test\') NOT NULL, `date_add` datetime NOT NULL, `charge_back` tinyint(1) NOT NULL DEFAULT \'0\',
      PRIMARY KEY (`id_smartcoin_transaction`), KEY `idx_transaction` (`type`,`id_order`,`status`))
		  ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1';

      return Db::getInstance()->Execute($create_transaction_db);
    }

    /**
  	 * SmartCoin's module uninstallation (Configuration values, database tables...)
  	 *
  	 * @return boolean Uninstall result
  	 */
  	public function uninstall() {
  		return parent::uninstall() && Configuration::deleteByName('SMARTCOIN_API_KEY_TEST') && Configuration::deleteByName('SMARTCOIN_API_KEY_LIVE')
  		&& Configuration::deleteByName('SMARTCOIN_MODE') && Configuration::deleteByName('SMARTCOIN_SECRET_KEY_TEST') && Configuration::deleteByName('SMARTCOIN_SECRET_KEY_LIVE') &&
  		Configuration::deleteByName('SMARTCOIN_CHARGEBACKS_ORDER_STATUS') && Configuration::deleteByName('SMARTCOIN_PENDING_ORDER_STATUS') && Configuration::deleteByName('SMARTCOIN_PAYMENT_ORDER_STATUS') &&
  		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'smartcoin_transaction`');
  	}


    /**
  	 * Display the SmartCoin's payment form
  	 *
  	 * @return string SmartCoin's Smarty template content
  	 */
    public function hookPayment($params) {
      if (!empty($this->context->cookie->smartcoin_error)) {
  			$this->smarty->assign('smartcoin_error', $this->context->cookie->smartcoin_error);
  			$this->context->cookie->__set('smartcoin_error', null);
  		}

  		$this->smarty->assign('validation_url', (Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].__PS_BASE_URI__.'index.php?process=validation&fc=module&module=smartcoin&controller=default');
  		$this->smarty->assign('smartcoin_ps_version', _PS_VERSION_);

      return $this->display(__FILE__, './views/templates/hook/payment.tpl');
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

  			$this->smarty->assign('smartcoin_order', array('reference' => isset($params['objOrder']->reference) ? $params['objOrder']->reference : '#'.sprintf('%06d', $params['objOrder']->id), 'valid' => $params['objOrder']->valid));

  			// added this so we could present a better/meaningful message to the customer when the charge suceeds, but verifications have failed.
  			$pendingOrderStatus = (int)Configuration::get('SMARTCOIN_PENDING_ORDER_STATUS');
  			$currentOrderStatus = (int)$params['objOrder']->getCurrentState();

  			if ($pendingOrderStatus==$currentOrderStatus)
  				$this->smarty->assign('order_pending', true);
  			else
  				$this->smarty->assign('order_pending', false);

  		return $this->display(__FILE__, './views/templates/hook/order-confirmation.tpl');

  	}


    /**
  	 * Process a payment
  	 *
  	 * @param string $token SmartCoin Transaction ID (token)
  	 */
  	public function processPayment($token) {
  		/* If 1.4 and no backward, then leave */
  		if (!$this->backward)
  			return;

  		include(dirname(__FILE__).'/lib/SmartCoin.php');
  		$access_key = Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') .':'. Configuration::get('SMARTCOIN_SECRECT_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST') .':'. Configuration::get('SMARTCOIN_SECRET_KEY_TEST');

  		try {
  			$charge_details = array('amount' => $this->context->cart->getOrderTotal() * 100, 'currency' => $this->context->currency->iso_code, 'description' => $this->l('PrestaShop Customer ID:').
  			' '.(int)$this->context->cookie->id_customer.' - '.$this->l('PrestaShop Cart ID:').' '.(int)$this->context->cart->id);

  			$charge_details['card'] = $token;

  			$result_json = Charge::create($charge_details,$access_key);

  		// catch the smartcoin error the correct way.
      } catch(\SmartCoin\Error $e) {
  			$body = $e->get_json_body();
  			$err = $body['error'];

  			$message = $err['message'];

  			if (class_exists('Logger'))
  				Logger::addLog($this->l('SmartCoin - Payment transaction failed').' '.$message, 1, null, 'Cart', (int)$this->context->cart->id, true);
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
  			$message = $this->l('SmartCoin Transaction Details:')."\n\n".
  			$this->l('SmartCoin Transaction ID:').' '.$result_json->id."\n".
  			$this->l('Amount:').' '.($result_json->amount * 0.01)."\n".
  			$this->l('Status:').' '.($result_json->paid == 'true' ? $this->l('Paid') : $this->l('Unpaid'))."\n".
  			$this->l('Processed on:').' '.strftime('%Y-%m-%d %H:%M:%S', $result_json->created)."\n".
  			$this->l('Currency:').' '. Tools::strtoupper($result_json->currency)."\n".
  			$this->l('Credit card:').' '.$result_json->card->type.' ('.$this->l('Exp.:').' '.$result_json->card->exp_month.'/'.$result_json->card->exp_year.')'."\n".
  			$this->l('Last 4 digits:').' '.sprintf('%04d', $result_json->card->last4).' ('.$this->l('CVC Check:').' '.($result_json->card->cvc_check == 'pass' ? $this->l('OK') : $this->l('NOT OK')).')'."\n".
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

  		/** @since 1.5.0 Attach the SmartCoin Transaction ID to this Order */
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
  			Db::getInstance()->Execute('
  			INSERT INTO '._DB_PREFIX_.'smartcoin_transaction (type, id_cart, id_order,
  			id_transaction, amount, status, currency, cc_type, cc_exp, cc_last_digits, cvc_check, fee, mode, date_add)
  			VALUES (\'payment\', '.(int)$this->context->cart->id.', '.(int)$this->currentOrder.', \''.pSQL($result_json->id).'\',
  			\''.($result_json->amount * 0.01).'\', \''.($result_json->paid == 'true' ? 'paid' : 'unpaid').'\', \''.pSQL($result_json->currency).'\',
  			\''.pSQL($result_json->card->type).'\', \''.(int)$result_json->card->exp_month.'/'.(int)$result_json->card->exp_year.'\', '.(int)$result_json->card->last4.',
  			'.($result_json->card->cvc_check == 'pass' ? 1 : 0).', \''.($result_json->fee * 0.01).'\', \''.($result_json->livemode == 'true' ? 'live' : 'test').'\', NOW())');

  		/* Redirect the user to the order confirmation page / history */
  		if (_PS_VERSION_ < 1.5)
  			$redirect = __PS_BASE_URI__.'order-confirmation.php?id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->id.'&id_order='.(int)$this->currentOrder.'&key='.$this->context->customer->secure_key;
  		else
  			$redirect = __PS_BASE_URI__.'index.php?controller=order-confirmation&id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->id.'&id_order='.(int)$this->currentOrder.'&key='.$this->context->customer->secure_key;

  		header('Location: '.$redirect);
  		exit;
  	}


    /**
  	 * Display the two fieldsets containing SmartCoin's transactions details
  	 * Visible on the Order's detail page in the Back-office only
  	 *
  	 * @return string HTML/JS Content
  	 */
  	public function hookBackOfficeHeader() {

    }


    /**
  	 * Load Javascripts and CSS related to the SmartCoin's module
  	 * Only loaded during the checkout process
  	 *
  	 * @return string HTML/JS Content
  	 */
    public function hookHeader() {
  		/* Continue only if we are in the checkout process */
  		if (Tools::getValue('controller') != 'order-opc' && (!($_SERVER['PHP_SELF'] == __PS_BASE_URI__.'order.php' || $_SERVER['PHP_SELF'] == __PS_BASE_URI__.'order-opc.php' || Tools::getValue('controller') == 'order' || Tools::getValue('controller') == 'orderopc' || Tools::getValue('step') == 3)))
  			return;

  		/* Load JS and CSS files through CCC */
  		$this->context->controller->addCSS($this->_path.'css/smartcoin-prestashop.css');

  		return '
  		<script type="text/javascript" src="https://js.smartcoin.com.br/v1/smartcoin.js"></script>
      <script type="text/javascript" src="https://js.smartcoin.com.br/v1/jquery.payment.js"></script>
  		<script type="text/javascript" src="'. $this->_path .'js/smartcoin-prestashop.js"></script>
  		<script type="text/javascript">
  			var smartcoin_api_key = \''.addslashes(Configuration::get('SMARTCOIN_MODE') ? Configuration::get('SMARTCOIN_API_KEY_LIVE') : Configuration::get('SMARTCOIN_API_KEY_TEST')).'\';
  		</script>';
  	}


    /**
  	 * Check settings requirements to make sure the SmartCoin's module will work properly
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
  	 * Check technical requirements to make sure the SmartCoin's module will work properly
  	 *
  	 * @return array Requirements tests results
  	 */
  	public function checkRequirements() {
  		$tests = array('result' => true);
  		$tests['curl'] = array('name' => $this->l('PHP cURL extension must be enabled on your server'), 'result' => extension_loaded('curl'));
  		if (Configuration::get('SMARTCOIN_MODE'))
  			$tests['ssl'] = array('name' => $this->l('SSL must be enabled on your store (before entering Live mode)'), 'result' => Configuration::get('PS_SSL_ENABLED') || (!empty($_SERVER['HTTPS']) && Tools::strtolower($_SERVER['HTTPS']) != 'off'));
  		$tests['php52'] = array('name' => $this->l('Your server must run PHP 5.2 or greater'), 'result' => version_compare(PHP_VERSION, '5.2.0', '>='));
  		$tests['configuration'] = array('name' => $this->l('You must sign-up for SmartCoin and configure your account settings in the module (api key, secret key...etc.)'), 'result' => $this->checkSettings());

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
	   * Display the Back-office interface of the SmartCoin's module
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
  		if (Tools::isSubmit('SubmitSmartCoin')) {
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
  					'SMARTCOIN_CHARGEBACKS_ORDER_STATUS' => (int)Tools::getValue('smartcoin_chargebacks_status')
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
  			'.(Tools::isSubmit('SubmitSmartCoin') ? '<div class="conf confirmation">'.$this->l('Settings successfully saved').'<img src="http://www.prestashop.com/modules/'.$this->name.'.png?api_user='.urlencode($_SERVER['HTTP_HOST']).'" style="display: none;" /></div>' : '').'
  			<div class="smartcoin-module-header">
  				<a href="https://smartcoin.com.br/signup" rel="external"><img src="'.$this->_path.'img/smartcoin-logo.gif" alt="smartcoin" class="smartcoin-logo" /></a>
  				<span class="smartcoin-module-intro">'.$this->l('SmartCoin makes it easy to start accepting credit cards on the web today.').'</span>
  				<a href="https://smartcoin.com.br/signup" rel="external" class="smartcoin-module-create-btn"><span>'.$this->l('Create an Account').'</span></a>
  			</div>
  			<fieldset>
  				<legend><img src="'.$this->_path.'img/checks-icon.gif" alt="" />'.$this->l('Technical Checks').'</legend>
  				<div class="'.($requirements['result'] ? 'conf">'.$this->l('Good news! All the checks were successfully performed. You can now configure your module and start using SmartCoin.') :
  				'warn">'.$this->l('Unfortunately, at least one issue is preventing you from using SmartCoin. Please fix the issue and reload this page.')).'</div>
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
  									<td align="right" valign="middle">'.$this->l('Test Publishable Key').'</td>
  									<td align="left" valign="middle"><input type="text" name="smartcoin_api_key_test" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_API_KEY_TEST')).'" /></td>
  									<td width="15"></td>
  									<td width="15" class="vertBorder"></td>
  									<td align="left" valign="middle">'.$this->l('Live Publishable Key').'</td>
  									<td align="left" valign="middle"><input type="text" name="ssmartcoin_api_key_live" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_API_KEY_LIVE')).'" /></td>
  								</tr>
  								<tr>
  									<td align="right" valign="middle">'.$this->l('Test Secret Key').'</td>
  									<td align="left" valign="middle"><input type="password" name="smartcoin_secret_key_test" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_SECRET_KEY_TEST')).'" /></td>
  									<td width="15"></td>
  									<td width="15" class="vertBorder"></td>
  									<td align="left" valign="middle">'.$this->l('Live Secret Key').'</td>
  									<td align="left" valign="middle"><input type="password" name="smartcoin_secret_key_live" value="'.Tools::safeOutput(Configuration::get('SMARTCOIN_SECRET_KEY_LIVE')).'" /></td>
  								</tr>
  							</table>
  						</td>
  					</tr>';

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
						<td colspan="2" class="td-noborder save"><input type="submit" class="button" name="SubmitSmartCoin" value="'.$this->l('Save Settings').'" /></td>
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
