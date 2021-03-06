var smartcoin_form_interval;

var smartcoin_settings_payments = function() {
	smartcoin_setting_payment_options();
	smartcoin_setting_payment_bank_slip();
	smartcoin_setting_payment_card_form();
	window.clearInterval(smartcoin_form_interval);
};

var smartcoin_setting_payment_options = function() {
	$('input#smartcoin_payment_option_bank_slip').click(function(){
		$('div#smartcoin_cc_box').hide();
		$('div#smartcoin_bank_slip_box').fadeIn();
	});

	$('input#smartcoin_payment_option_cc').click(function(){
		$('div#smartcoin_bank_slip_box').hide();
		$('div#smartcoin_cc_box').fadeIn();
	});
};

var smartcoin_setting_payment_bank_slip = function() {
	$('#smartcoin-payment-bank-slip-form').submit(function(event) {
		$('#smartcoin-payment-bank-slip-form').hide();
		$('#smartcoin-ajax-loader-bank-slip').show();
		$('.smartcoin-submit-button').attr('disabled', 'disabled'); /* Disable the submit button to prevent repeated clicks */
		$('#smartcoin-payment-bank-slip-form').append('<input type="hidden" name="smartcoin_charge_type" value="bank_slip" />');
		$('#smartcoin-payment-bank-slip-form').get(0).submit();

		return false;
	});
};

var smartcoin_setting_payment_card_form = function(){
	/* Set SmartCoin's api key */
	SmartCoin.set_api_key(_smartcoin_api_key);

	$('form#smartcoin-payment-form').card({
		container: $('.smartcoin-card-wrapper'),
		numberInput: 'input.smartcoin-card-number',
    expiryInput: 'select.smartcoin-card-expiry-month,select.smartcoin-card-expiry-year',
    cvcInput: 'input.smartcoin-card-cvc',
    nameInput: 'input.smartcoin-card-name',
	});

	/* Determine the Credit Card Type */
	$('.smartcoin-card-number').keyup(function() {
		if ($(this).val().length >= 2) {
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
		else {
			$('.cc-icon').removeClass('enable');
			$('.cc-icon:not(.disable)').addClass('disable');
		}
	});

	$('#smartcoin-payment-form').submit(function(event) {
		var month = ($('.smartcoin-card-expiry-month option:selected').val()); // this is the easiest way to check selected in mobile browsers and this will work in desktop as well
		var year  = ($('.smartcoin-card-expiry-year option:selected').val()); // this is the easiest way to check selected in mobile browsers and this will work in desktop as well
		if (!$.payment.validateCardNumber($('.smartcoin-card-number').val()))
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-card').text() + ' ' + $('#smartcoin-please-fix').text());
		else if (!$('.smartcoin-card-name').val().length > 0)
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-name').text() + ' ' + $('#smartcoin-please-fix').text());
		else if (!$.payment.validateCardExpiry(month, year))
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-expiry').text() + ' ' + $('#smartcoin-please-fix').text());
		else if (!$.payment.validateCardCVC($('.smartcoin-card-cvc').val(),$.payment.cardType($('.smartcoin-card-number').val())))
			$('.smartcoin-payment-errors').text($('#smartcoin-wrong-cvc').text() + ' ' + $('#smartcoin-please-fix').text());
		else {
			$('.smartcoin-payment-errors').hide();
			$('#smartcoin-payment-form').hide();
			$('.smartcoin-card-wrapper').hide();
			$('#smartcoin-ajax-loader-cc').show();
			$('.smartcoin-submit-button').attr('disabled', 'disabled'); /* Disable the submit button to prevent repeated clicks */

			_user_id = ps_customer_email;
			smartcoin_token_params = {
				name: 			$('.smartcoin-card-name').val(),
				number: 		$('.smartcoin-card-number').val(),
				cvc: 			 $('.smartcoin-card-cvc').val(),
				exp_month:  month,
				exp_year: 	year,
			};

			/* Check if the billing address element are set and add them to the Token */
			if (typeof smartcoin_billing_address != 'undefined') {
				smartcoin_token_params.address_line_1 = smartcoin_billing_address.address1;
				smartcoin_token_params.address_cep = smartcoin_billing_address.postcode;
				smartcoin_token_params.address_city = smartcoin_billing_address.city;
				smartcoin_token_params.address_country = smartcoin_billing_address.country;

				if (typeof smartcoin_billing_address.address2 != 'undefined')
					smartcoin_token_params.address_district = smartcoin_billing_address.address2;
				if (typeof smartcoin_billing_address.state != 'undefined')
					smartcoin_token_params.address_state = smartcoin_billing_address.state;
			}

			SmartCoin.create_token(smartcoin_token_params, smartcoin_response_handler);

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
			url: baseDir + 'modules/smartcoin/ajax.php',
			data: 'action=delete_card&token=' + smartcoin_secure_key
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

};

var smartcoin_response_handler = function(response) {
	if (response.error) {
		$('.smartcoin-payment-errors').text(JSON.stringify(response)).fadeIn(1000);
		$('.smartcoin-submit-button').removeAttr('disabled');
		$('#smartcoin-payment-form').show();
		$('#smartcoin-ajax-loader').hide();
	}
	else {
		$('.smartcoin-payment-errors').hide();
		$('#smartcoin-payment-form').append('<input type="hidden" name="smartcoin_token" value="' + escape(response.id) + '" />');
		$('#smartcoin-payment-form').append('<input type="hidden" name="StripLastDigits" value="' + parseInt($('.smartcoin-card-number').val().slice(-4)) + '" />');
		$('#smartcoin-payment-form').get(0).submit();
	}
};

$(window).load(function() {
	smartcoin_form_interval = setInterval(smartcoin_settings_payments,2000);
});

$('#cgv').click(function(event){
	smartcoin_form_interval = setInterval(smartcoin_settings_payments,2000);
});

