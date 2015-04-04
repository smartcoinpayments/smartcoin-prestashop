<?php
  function upgrade_module_0_2_5($module) {
  	return Configuration::updateValue('SMARTCOIN_BANK_SLIP_DISCOUNT', 0);
  }
?>