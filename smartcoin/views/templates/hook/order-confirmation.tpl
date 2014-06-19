{if $smartcoin_order.valid == 1}
	<div class="conf confirmation">{l s='Congratulations, your payment has been approved and your order has been saved under the reference' mod='smartcoin'} <b>{$smartcoin_order.reference|escape:html:'UTF-8'}</b>.</div>
{else}
	{if $order_pending}
		<div class="error">{l s='Unfortunately we detected a problem while processing your order and it needs to be reviewed.' mod='smartcoin'}<br /><br />
		{l s='Do not try to submit your order again, as the funds have already been received.  We will review the order and provide a status shortly.' mod='smartcoin'}<br /><br />
		({l s='Your Order\'s Reference:' mod='smartcoin'} <b>{$smartcoin_order.reference|escape:html:'UTF-8'}</b>)</div>
	{else}
		<div class="error">{l s='Sorry, unfortunately an error occured during the transaction.' mod='smartcoin'}<br /><br />
		{l s='Please double-check your credit card details and try again or feel free to contact us to resolve this issue.' mod='smartcoin'}<br /><br />
		({l s='Your Order\'s Reference:' mod='smartcoin'} <b>{$smartcoin_order.reference|escape:html:'UTF-8'}</b>)</div>
	{/if}
{/if}
