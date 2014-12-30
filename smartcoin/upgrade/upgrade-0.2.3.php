<?php
  function upgrade_module_0_2_3($module) {
  	return Configuration::updateValue('SMARTCOIN_PAYMENT_OPTION_CREDIT_CARD', 1) &&
           Configuration::updateValue('SMARTCOIN_PAYMENT_OPTION_BANK_SLIP', 1);
  }
?>