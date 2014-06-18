$(document).ready(function() {
	/* Set SmartCoin's api key */
	SmartCoin.set_api_key(smartcoin_api_key);

	/* Determine the Credit Card Type */
	$('.smartcoin-card-number').keyup(function() {
		if ($(this).val().length >= 2)
		{
			smartcoin_card_type = $.payment.cardType($('.smartcoin-card-number').val());
			$('.cc-icon').removeClass('enable');
			$('.cc-icon').removeClass('disable');
			$('.cc-icon').each(function() {
				if ($(this).attr('rel') == smartcoin_card_type)
					$(this).addClass('enable');
				else
					$(this).addClass('disable');
			});
		}
		else
		{
			$('.cc-icon').removeClass('enable');
			$('.cc-icon:not(.disable)').addClass('disable');
		}
	});

	$('#smartcoin-payment-form-cc').submit(function(event) {
		$('.smartcoin-payment-errors').hide();
		$('#smartcoin-payment-form-cc').hide();
		$('#smartcoin-ajax-loader').show();
		$('.smartcoin-submit-button-cc').attr('disabled', 'disabled'); /* Disable the submit button to prevent repeated clicks */
	});

	$('#smartcoin-payment-form').submit(function(event) {

		var month = ($('.smartcoin-card-expiry-month option:selected').val()); // this is the easiest way to check selected in mobile browsers and this will work in desktop as well
		var year  = ($('.smartcoin-card-expiry-year option:selected').val()); // this is the easiest way to check selected in mobile browsers and this will work in desktop as well
		if (!Stripe.validateCardNumber($('.smartcoin-card-number').val()))
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-card').text() + ' ' + $('#smartcoin-please-fix').text());
		else if (!Stripe.validateExpiry(month, year))
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-expiry').text() + ' ' + $('#smartcoin-please-fix').text());
		else if (!Stripe.validateCVC($('.smartcoin-card-cvc').val()))
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-cvc').text() + ' ' + $('#smartcoin-please-fix').text());
		else
		{
			$('.smartcoin-payment-errors').hide();
			$('#smartcoin-payment-form').hide();
			$('#smartcoin-ajax-loader').show();
			$('.smartcoin-submit-button').attr('disabled', 'disabled'); /* Disable the submit button to prevent repeated clicks */

			stripe_token_params = {
				number: $('.smartcoin-card-number').val(),
				cvc: $('.smartcoin-card-cvc').val(),
				exp_month: month,
				exp_year: year,
			};

			/* Check if the billing address element are set and add them to the Token */
			if (typeof stripe_billing_address != 'undefined')
			{
				stripe_token_params.name = stripe_billing_address.firstname + ' ' + stripe_billing_address.lastname;
				stripe_token_params.address_line1 = stripe_billing_address.address1;
				stripe_token_params.address_zip = stripe_billing_address.postcode;
				stripe_token_params.address_country = stripe_billing_address.country;
			}

			if (typeof stripe_billing_address.address2 != 'undefined')
				stripe_token_params.address_line2 = stripe_billing_address.address2;
			if (typeof stripe_billing_address.state != 'undefined')
				stripe_token_params.address_state = stripe_billing_address.state;

			Stripe.createToken(stripe_token_params, smartcoin_response_handler);

			return false; /* Prevent the form from submitting with the default action */
		}

		$('.smartcoin-payment-errors').fadeIn(1000);
		return false;
	});

	$('#smartcoin-replace-card').click(function() {
		$('#smartcoin-payment-form-cc').hide();
		$('#smartcoin-payment-form').fadeIn(1000);
	});

	$('#smartcoin-delete-card').click(function() {
		$.ajax({
			type: 'POST',
			url: baseDir + 'modules/stripejs/ajax.php',
			data: 'action=delete_card&token=' + stripe_secure_key
		}).done(function(msg)
		{
			if (msg == 1)
			{
				$('#smartcoin-payment-form-cc').hide();
				$('.smartcoin-card-deleted').text($('#smartcoin-card-del').text()).fadeIn(1000);
				$('#smartcoin-payment-form').fadeIn(1000);
			}
			else
				alert($('#smartcoin-card-del-error').text());
		});
	});

	/* Catch callback errors */
	if ($('.smartcoin-payment-errors').text())
		$('.smartcoin-payment-errors').fadeIn(1000);
});

function smartcoin_response_handler(status, response)
{
	if (response.error)
	{
		$('.smartcoin-payment-errors').text(response.error.message).fadeIn(1000);
		$('.smartcoin-submit-button').removeAttr('disabled');
		$('#smartcoin-payment-form').show();
		$('#smartcoin-ajax-loader').hide();
	}
	else
	{
		$('.smartcoin-payment-errors').hide();
		$('#smartcoin-payment-form').append('<input type="hidden" name="stripeToken" value="' + escape(response['id']) + '" />');
		$('#smartcoin-payment-form').append('<input type="hidden" name="StripLastDigits" value="' + parseInt($('.smartcoin-card-number').val().slice(-4)) + '" />');
		$('#smartcoin-payment-form').get(0).submit();
	}
}
