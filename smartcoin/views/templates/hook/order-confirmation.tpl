{if $smartcoin_order.valid == 1}
	<div class="conf confirmation">{l s='Congratulations, your payment has been approved and your order has been saved under the reference' mod='smartcoin'} <b>{$smartcoin_order.reference|escape:html:'UTF-8'}</b>.</div>
{else}
	{if $order_pending}
		<div class="conf confirmation">{l s='Your order was processed. We are awaiting the payment confirmation.' mod='smartcoin'}<br /><br />
		{l s='Your bank slip bar code is: ' mod='smartcoin'} <strong>{$smartcoin_order.bank_slip_bar_code}</strong><br /><br />
		<a style="padding: 10px; border-radius: 5px; -webkit-border-radius: 5px; -moz-border-radius: 5px; color: #FFF; display: inline-block; background:#45B1E8;" href="{$smartcoin_order.bank_slip_link}" target="_blank">
		{l s='VISUALIZAR BOLETO' mod='smartcoin'}
		</a>
		<br /><br />
		{l s='Do not try to submit your order again. We will review the order and provide a status shortly.' mod='smartcoin'}<br /><br />
		({l s='Your Order\'s Reference:' mod='smartcoin'} <b>{$smartcoin_order.reference|escape:html:'UTF-8'}</b>)</div>
	{else}
		<div class="error">{l s='Sorry, unfortunately an error occured during the transaction.' mod='smartcoin'}<br /><br />
		{l s='Please double-check your credit card details and try again or feel free to contact us to resolve this issue.' mod='smartcoin'}<br /><br />
		({l s='Your Order\'s Reference:' mod='smartcoin'} <b>{$smartcoin_order.reference|escape:html:'UTF-8'}</b>)</div>
	{/if}
{/if}
