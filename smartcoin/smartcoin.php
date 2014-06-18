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

    public function install(){
      $ret = parent::install() && $this->registerHook('payment')
            && $this->registerHook('paymentReturn') && $this->installDB();

      return $ret;
    }

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

    public function hookPayment($params) {
      if (!empty($this->context->cookie->smartcoin_error)) {
  			$this->smarty->assign('smartcoin_error', $this->context->cookie->smartcoin_error);
  			$this->context->cookie->__set('smartcoin_error', null);
  		}

  		$this->smarty->assign('validation_url', (Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].__PS_BASE_URI__.'index.php?process=validation&fc=module&module=smartcoin&controller=default');
  		$this->smarty->assign('smartcoin_ps_version', _PS_VERSION_);

      return $this->display(__FILE__, './views/templates/hook/payment.tpl');
    }
  }
?>
