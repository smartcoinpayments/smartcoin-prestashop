<?php
  include(dirname(__FILE__).'/../../config/config.inc.php');
  include(dirname(__FILE__).'/../../init.php');
  include(dirname(__FILE__).'/smartcoin.php');

  if (!defined('_PS_VERSION_'))
  	exit;


  /* Check that the Smartcoin's module is active and that we have the token */
  $smartcoin = new SmartCoin();
  $context = Context::getContext();
  if ($smartcoin->active && Tools::getIsset('smartcoin_token')) {
   	$smartcoin->processPayment(Tools::getValue('smartcoin_token'));
  }
  else {
  	$context->cookie->__set("smartcoin_error", 'There was a problem with your payment');
  	$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
  	$location = $context->link->getPageLink($controller).(strpos($controller, '?') !== false ? '&' : '?').'step=3#smartcoin_error';
  	header('Location: '.$location);
  }

?>
