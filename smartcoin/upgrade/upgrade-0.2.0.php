<?php
  function upgrade_module_0_2_0($module) {
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
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` ADD `charge_type` varchar(32) NOT NULL DEFAULT \'credit_card\'';
    Db::getInstance()->Execute($create_transaction_db);
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` ADD `bank_slip_bar_code` varchar(256)';
    Db::getInstance()->Execute($create_transaction_db);
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` ADD `bank_slip_link` varchar(256)';
    Db::getInstance()->Execute($create_transaction_db);
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` MODIFY cc_type varchar(16) NULL';
    Db::getInstance()->Execute($create_transaction_db);
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` MODIFY cc_exp varchar(8) NULL';
    Db::getInstance()->Execute($create_transaction_db);
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` MODIFY cc_last_digits varchar(11) NULL';
    Db::getInstance()->Execute($create_transaction_db);
    $create_transaction_db = 'ALTER TABLE `'._DB_PREFIX_.'smartcoin_transaction` MODIFY installments varchar(11) NULL';
    Db::getInstance()->Execute($create_transaction_db);

    return true;
  }
?>