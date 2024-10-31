(function( $ ) {

	if ( recebimento_facil_woocommerce.page == 'settings' ) {

		// Settings saved
		jQuery( '#woocommerce_' + recebimento_facil_woocommerce.id + '_settings_saved' ).val( '1' );

		// Hide extra fields
		if ( $( '#' + recebimento_facil_woocommerce.id + '_hide_extra_fields' ).length ) {
			// Hide extra fields if there are errors on Entity or Subentity
			jQuery( '#recebimentofacil_leftbar_settings table.form-table tr:nth-child(n+8)' ).hide();
			jQuery( '#recebimentofacil_leftbar_settings .mb_hide_extra_fields' ).hide();
		}

	}

	if ( recebimento_facil_woocommerce.page == 'order' ) {

		// Check status
		$( '#multicaixa_check_ref_status' ).on( 'click', function() {
			$( '#' + recebimento_facil_woocommerce.id + ' input' ).prop( 'disabled' , true );
			setTimeout( function() {
				var data = {
					order_id: recebimento_facil_woocommerce.order_id
				};
				$.ajax({
					beforeSend: function (xhr) {
						xhr.setRequestHeader( 'X-WP-Nonce', $( '#multicaixa_rest_auth_nonce' ).val() );
					},
					url: recebimento_facil_woocommerce.check_ref_status_url,
					type: 'POST',
					data: JSON.stringify( data ),
					contentType: 'application/json; charset=utf-8',
					dataType: 'json',
					async: false,
					success: function( response ) {
						$( '#' + recebimento_facil_woocommerce.id + ' input' ).prop( 'disabled' , false );
						alert( response.description );
						if ( response.reload ) {
							window.location.reload();
						}
					},
					error: function( response ) {
						$( '#' + recebimento_facil_woocommerce.id + ' input' ).prop( 'disabled' , false );
						alert( response.description );
					}
				});
			}, 200 );
		});

		// Simulate
		$( '#multicaixa_simulate_payment' ).on( 'click', function() {
			if ( confirm( recebimento_facil_woocommerce.msg_testing_tool ) ) {
				$( '#' + recebimento_facil_woocommerce.id + ' input' ).prop( 'disabled' , true );
				setTimeout( function() {
					var data = {
						request: {
							authentication: recebimento_facil_woocommerce.notification_token,
							data: {
								entityID:           $( '#order_mc_details_ent' ).val(),
								referenceID:        $( '#order_mc_details_ref' ).val(),
								sourceID:           $( '#order_mc_details_source_id' ).val(),
								paymentID:          $( '#order_mc_details_payment_id' ).val(),
								paymentExecutionID: 'test-' + Date.now(),
								paymentDateTime:    new Date().toISOString().substring( 0, 19 ).replace( 'T', ' ' ),
								paymentAmount:      $( '#order_mc_details_val' ).val(),
								simulation:         1,
							}
						}
					};
					$.ajax({
						url:         recebimento_facil_woocommerce.notify_url,
						type:        'POST',
						data:        JSON.stringify( data ),
						contentType: 'application/json; charset=utf-8',
						dataType:    'json',
						async:       false,
						success: function( response ) {
							$( '#' + recebimento_facil_woocommerce.id + ' input' ).prop( 'disabled' , false );
							response = response.response; // Because we have response root element response ¯\_(ツ)_/¯
							if ( response && response.code == '200' ) {
								alert( recebimento_facil_woocommerce.msg_page_reload );
								window.location.reload();
							} else {
								var error = recebimento_facil_woocommerce.msg_unknown_error;
								if ( response && response.description ) {
									error = recebimento_facil_woocommerce.msg_error + ': ' + response.description;
								}
								alert( error );
							}
						},
						error: function( response ) {
							$( '#' + recebimento_facil_woocommerce.id + ' input' ).prop( 'disabled' , false );
							var error = recebimento_facil_woocommerce.msg_unknown_error;
							if ( response && response.responseJSON.description ) {
								error = recebimento_facil_woocommerce.msg_error + ': ' + response.responseJSON.description;
							}
							alert( error );
						}
					});
				}, 200 );
			}
		});

	}

})( jQuery );
