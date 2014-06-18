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
      $ret = parent::install() && $this->registerHook('payment') && $this->registerHook('header')
            && $this->registerHook('paymentReturn') && $this->installDB();

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
  		<script type="text/javascript" src="https://js.smartcoin.com.br/v1/"></script>';
  		//<script type="text/javascript" src="'. $this->_path .'js/stripe-prestashop.js"></script>
  		//<script type="text/javascript">
  		//	var stripe_public_key = \''.addslashes(Configuration::get('STRIPE_MODE') ? Configuration::get('STRIPE_PUBLIC_KEY_LIVE') : Configuration::get('STRIPE_PUBLIC_KEY_TEST')).'\';
  		//</script>';
  	}
  }
?>
