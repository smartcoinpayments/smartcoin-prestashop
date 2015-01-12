<?php
  function upgrade_module_0_2_4($module) {
  	return Configuration::updateValue('SMARTCOIN_WEBHOOK_TOKEN', md5(Tools::passwdGen()));
  }
?>