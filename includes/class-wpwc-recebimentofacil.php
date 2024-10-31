<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Our main class
 */
final class WPWC_RecebimentoFacil {

	/* Variables */
	public $id                  = 'recebimento_facil_woocommerce';
	public $version             = false;
	public $wpml_active         = false;
	private $last_error         = '';
	public $out_link_utm        = '';
	public $multicaixa_settings = null;
	public $test_mode           = false;
	public $soap_url_create_ref = 'https://spf-webservices.bancoeconomico.ao:8443/soa-infra/services/SPF/WSI_PaymentRefCreate/WSI_PaymentRefCreate?WSDL';
	public $soap_url_cancel_ref = 'https://spf-webservices.bancoeconomico.ao:8443/soa-infra/services/SPF/WSI_PaymentRefCancel/WSI_PaymentRefCancel?WSDL';
	public $soap_url_query_ref  = 'https://spf-webservices.bancoeconomico.ao:8443/soa-infra/services/SPF/WSI_PaymentRefDetailsQuery/WSI_PaymentRefDetailsQuery?WSDL';
	public $be_email            = 'be_suporte_recebimentofacil@bancoeconomico.ao';
	public $min_amount          = 0.01;
	public $max_amount          = 99999999.99;

	/* Single instance */
	protected static $instance = null;

	/* Constructor */
	public function __construct() {
		// Version
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data   = get_plugin_data( RECEBIMENTOFACIL_PLUGIN_FILE );
		$this->version = $plugin_data['Version'];
		// Variables
		$this->wpml_active         = function_exists( 'icl_object_id' ) && function_exists( 'icl_register_string' );
		$this->out_link_utm        = '?utm_source='.rawurlencode( esc_url( home_url( '/' ) ) ).'&amp;utm_medium=link&amp;utm_campaign=recebimento_facil_woocommerce';
		$this->multicaixa_settings = $this->get_payment_gateway_settings();
		$this->test_mode           = apply_filters( 'wpwc_recebimentofacil_test_mode', false );
		if ( $this->test_mode ) {
			$this->soap_url_create_ref = 'https://spf-webservices-uat.bancoeconomico.ao:7443/soa-infra/services/SPF/WSI_PaymentRefCreate/WSI_PaymentRefCreate?wsdl';
			$this->soap_url_cancel_ref = 'https://spf-webservices-uat.bancoeconomico.ao:7443/soa-infra/services/SPF/WSI_PaymentRefCancel/WSI_PaymentRefCancel?wsdl';
			$this->soap_url_query_ref  = 'https://spf-webservices-uat.bancoeconomico.ao:7443/soa-infra/services/SPF/WSI_PaymentRefDetailsQuery/WSI_PaymentRefDetailsQuery?wsdl';
		}
		$this->notify_url           = $this->rest_api_get_payment_notification_url();
		$this->notify_token         = $this->get_notification_token();
		$this->check_ref_status_url = $this->rest_api_get_check_ref_status_url();
		$this->icon                 = plugins_url( 'images/icon_multicaixa_48.png', RECEBIMENTOFACIL_PLUGIN_FILE );
		$this->icon_svg             = plugin_dir_path( RECEBIMENTOFACIL_PLUGIN_FILE ).'images/icon_multicaixa_48.svg';
		$this->banner               = plugins_url( 'images/banner_multicaixa.png', RECEBIMENTOFACIL_PLUGIN_FILE );
		$this->banner_svg           = plugin_dir_path( RECEBIMENTOFACIL_PLUGIN_FILE ).'images/banner_multicaixa.svg';
		// Hooks
		$this->init_hooks();
	}

	/* Ensures only one instance of our plugin is loaded or can be loaded */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* Get payment gateway settings */
	public function get_payment_gateway_settings() {
		return get_option( 'woocommerce_'.$this->id.'_settings', array() );
	}

	/* Get or generate notification token */
	private function get_notification_token() {
		if ( empty( $this->multicaixa_settings['notification_token'] ) ) {
			$this->multicaixa_settings['notification_token'] = md5( home_url().wp_rand( 10000, 99999 ) );
			update_option( 'woocommerce_'.$this->id.'_settings', $this->multicaixa_settings );
		}
		return $this->multicaixa_settings['notification_token'];
	}

	/* Hooks */
	private function init_hooks() {
		/* Plugin settings */
		add_filter( 'plugin_action_links_'.plugin_basename( RECEBIMENTOFACIL_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
		/* Gateway */
		add_filter( 'woocommerce_payment_gateways', array( $this, 'woocommerce_add_payment_gateways' ) );
		/* REST API */
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		/* Metabox */
		add_action( 'add_meta_boxes', array( $this, 'order_metabox' ) ); // Not HPOS compatible yet
		/* Search orders */
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'shop_order_search_fields' ) );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'order_data_store_cpt_get_orders_query' ), 10, 2 ); // Not HPOS compatible yet
		/* Cancel ref */
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_cancel_ref' ), 10, 4 );
		/* Admin Scripts and CSS */
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}

	/* Add settings link to plugin actions */
	public function add_settings_link( $links ) {
		$action_links = array(
			'wpwc_recebimentofacil_settings' => '<a href="admin.php?page=wc-settings&amp;tab=checkout&amp;section='.$this->id.'">' . __( 'Multicaixa settings', 'recebimento-facil-multicaixa-for-woocommerce' ) . '</a>',
		);
		return array_merge( $action_links, $links );
	}

	/* Add to WooCommerce */
	public function woocommerce_add_payment_gateways( $methods ) {
		$methods[] = 'WPWC_RecebimentoFacil_WooCommerce';
		return $methods;
	}

	/* Right sidebar on payment gateway settings - Outside payment gateway class because we might implement other payment methods */
	public function admin_right_bar() {
		?>
		<div id="recebimentofacil_rightbar">
			<h4><?php esc_html_e( 'Commercial information', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</h4>
			<p>
				<?php
				$title = sprintf(
					/* translators: %s: Institution name */
					__( 'Please contact %s', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'Banco Económico'
				)
				?>
				<a href="https://www.bancoeconomico.ao/pt/empresas/servicos/recebimento-facil/<?php echo esc_attr( $this->out_link_utm ); ?>" title="<?php echo esc_attr( $title ); ?>" target="_blank"><img src="<?php echo esc_url( plugins_url( 'images/banco_economico.svg', RECEBIMENTOFACIL_PLUGIN_FILE ) ); ?>" width="200"/></a>
			</p>
			<h4><?php esc_html_e( 'Technical support', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</h4>
			<?php
			$title = sprintf(
				/* translators: %s: Institution name */
				__( 'Please contact %s', 'recebimento-facil-multicaixa-for-woocommerce' ),
				'IDN PRO'
			)
			?>
			<p><a href="https://idn.co.ao/<?php echo esc_attr( $this->out_link_utm ); ?>" title="<?php echo esc_attr( $title ); ?>" target="_blank"><img src="<?php echo esc_url( plugins_url( 'images/idn.svg', RECEBIMENTOFACIL_PLUGIN_FILE ) ); ?>" width="150"/></a></p>
			<p><a href="https://wordpress.org/support/plugin/recebimento-facil-multicaixa-for-woocommerce/" target="_blank"><?php esc_html_e( 'Request support here', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></a>
			<h4><?php esc_html_e( 'Please rate our plugin at WordPress.org', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</h4>
			<a href="https://wordpress.org/support/view/plugin-reviews/recebimento-facil-multicaixa-for-woocommerce?filter=5#postform" target="_blank" style="text-align: center; display: block;">
				<div class="star-rating"><div class="star star-full"></div><div class="star star-full"></div><div class="star star-full"></div><div class="star star-full"></div><div class="star star-full"></div></div>
			</a>
			<div class="clear"></div>
		</div>
		<?php
	}

	/* Format Multicaixa reference - Outside payment gateway class because we might need it even if the gateway is not initiated */
	public function format_multicaixa_ref( $ref ) {
		return apply_filters( 'wpwc_recebimentofacil_format_ref', $ref );
		// return apply_filters( 'wpwc_recebimentofacil_format_ref', trim( chunk_split( trim( $ref ), 3, ' ' ) ) );
	}

	/* Format Multicaixa validity date - Outside payment gateway class because we might need it even if the gateway is not initiated */
	public function format_multicaixa_validity_date( $date ) {
		return $date;
	}

	/* Get Multicaixa order details - To be used by get_ref() or other places, like the metabox */
	private function get_multicaixa_order_details( $order_id ) {
		$order  = wc_get_order( $order_id );
		$ent    = $order->get_meta( '_'.$this->id.'_ent' );
		$ref    = $order->get_meta( '_'.$this->id.'_ref' );
		$val    = $order->get_meta( '_'.$this->id.'_val' );
		$exp    = $order->get_meta( '_'.$this->id.'_exp' );
		$cancel = $order->get_meta( '_'.$this->id.'_cancelled' );
		if ( ! empty( $ent ) && ! empty( $ref ) && ! empty( $val ) ) {
			return array(
				'ent'    => $ent,
				'ref'    => $ref,
				'val'    => $val,
				'exp'    => $exp,
				'cancel' => $cancel,
			);
		}
		return false;
	}

	/* Allow searching orders by Multicaixa reference */
	public function shop_order_search_fields( $search_fields ) {
		// Ref
		$search_fields[] = '_'.$this->id.'_ref';
		return $search_fields;
	}

	/* Filter to be able to use wc_get_orders with our Multicaixa references */
	public function order_data_store_cpt_get_orders_query( $query, $query_vars ) {
		// Multicaixa - Entity
		if ( ! empty( $query_vars[ '_'.$this->id.'_ent' ] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_'.$this->id.'_ent',
				'value' => esc_attr( $query_vars[ '_'.$this->id.'_ent' ] ),
			);
		}
		// Multicaixa - Reference
		if ( ! empty( $query_vars[ '_'.$this->id.'_ref' ] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_'.$this->id.'_ref',
				'value' => esc_attr( $query_vars[ '_'.$this->id.'_ref' ] ),
			);
		}
		// Multicaixa - Source ID
		if ( ! empty( $query_vars[ '_'.$this->id.'_source_id' ] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_'.$this->id.'_source_id',
				'value' => esc_attr( $query_vars[ '_'.$this->id.'_source_id' ] ),
			);
		}
		// Multicaixa - Payment ID
		if ( ! empty( $query_vars[ '_'.$this->id.'_payment_id' ] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_'.$this->id.'_payment_id',
				'value' => esc_attr( $query_vars[ '_'.$this->id.'_payment_id' ] ),
			);
		}
		return $query;
	}

	/* SOAP Utils */
	private function get_soap_client_params() {
		$params = array(
			'soap_version' => SOAP_1_1,
			'compression'  => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
			'encoding'     => 'UTF-8',
			'trace'        => 1,
			'exceptions'   => true,
			'cache_wsdl'   => WSDL_CACHE_NONE,
			'features'     => SOAP_SINGLE_ELEMENT_ARRAYS,
		);
		if ( apply_filters( 'wpwc_recebimentofacil_ignore_ssl_validation', false ) ) { // Not advisable but might be needed in test mode
			$params['stream_context'] = stream_context_create(
				array(
					'ssl' => array(
						'verify_peer'      => false,
						'verify_peer_name' => false,
					),
				)
			);
		}
		return $params;
	}
	/* SOAP Utils - Security header */
	private function get_soap_security_header_xml() {
		return '<wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
			<wsse:UsernameToken wsu:Id="soaAuth">
				<wsse:Username>'.trim( $this->multicaixa_settings['username'] ).'</wsse:Username>
				<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">'.trim( $this->multicaixa_settings['password'] ).'</wsse:Password>
				<wsu:Created>'.date_i18n( 'c' ).'</wsu:Created>
			</wsse:UsernameToken>
		</wsse:Security>';
	}
	/* SOAP Utils - Header params */
	private function get_soap_header_params( $msgid ) {
		return array(
			'SOURCE'          => trim( $this->multicaixa_settings['source'] ),
			'MSGID'           => $msgid,
			'USERID'          => trim( $this->multicaixa_settings['userid'] ),
			'BRANCH'          => '000',
			'PASSWORD'        => '', // Email 2022-09-20
			'INVOKETIMESTAMP' => date_i18n( 'c' ),
		);
	}
	/* SOAP Utils - Error */
	private function get_soap_error( $e, $base_error ) {
		$translate_errors = array(
			'WS-Security security header' => __( 'Authentication error. The shop administrator needs to insert the proper credentials.', 'recebimento-facil-multicaixa-for-woocommerce' ),
		);
		$error            = $base_error.' '.$e->getMessage();
		$found            = false;
		foreach ( $translate_errors as $key => $temp ) {
			if ( stristr( $e->getMessage(), $key ) ) {
				$found  = true;
				$error .= ' - '.$temp;
				break;
			}
		}
		if ( ! $found ) $error .= ' - '.__( 'The shop administrator needs to make sure all the configurations are correctly set.', 'recebimento-facil-multicaixa-for-woocommerce' );
		return $error;
	}

	/* Get the ref - Outside the main class because we might need to access by other means */
	public function get_ref( $order_id, $force_change = false ) {
		$order = wc_get_order( $order_id );
		if (
			( $order->get_currency() == 'AOA' )
			&&
			( $order->get_payment_method() == $this->id )
		) {
			if (
				! $force_change
				&&
				$order_mc_details = $this->get_multicaixa_order_details( $order->get_id() )
			) {
				return array(
					'ent' => $order_mc_details['ent'],
					'ref' => $order_mc_details['ref'],
					'val' => $order_mc_details['val'],
					'exp' => $order_mc_details['exp'],
				);
			} else {
				$error = false;
				// Value ok?
				if ( $this->get_order_total_to_pay( $order ) < $this->min_amount ) { // FIX
					$error = sprintf(
						__( 'It’s not possible to use %1$s to pay values under %2$s.', 'recebimento-facil-multicaixa-for-woocommerce' ),
						'Multicaixa',
						wc_price( $this->min_amount, array( 'currency' => 'AOA' ) )
					);
				}
				if ( $this->get_order_total_to_pay( $order ) > $this->max_amount ) { // FIX
					$error = sprintf(
						__( 'It’s not possible to use %1$s to pay values above %2$s.', 'recebimento-facil-multicaixa-for-woocommerce' ),
						'Multicaixa',
						wc_price( $this->max_amount, array( 'currency' => 'AOA' ) )
					);
				}
				if ( ! $error ) {
					// Get the ref
					if (
						trim( $this->multicaixa_settings['source'] ) != ''
						&&
						trim( $this->multicaixa_settings['userid'] ) != ''
						&&
						trim( $this->multicaixa_settings['username'] ) != ''
						&&
						trim( $this->multicaixa_settings['password'] ) != ''
						&&
						trim( $this->multicaixa_settings['token'] ) != ''
						&&
						strlen( trim( $this->multicaixa_settings['ent'] ) ) == 5
						&&
						intval( $this->multicaixa_settings['ent'] ) > 0
					) {
						$msg_and_source_id = $order->get_id().'_'.time().'_'.wp_rand( 100, 999 );
						try {
							// SOAP Client
							$soap_client = new SoapClient( $this->soap_url_create_ref, $this->get_soap_client_params() );
							// Security header
							$soap_var_header = new SoapVar( $this->get_soap_security_header_xml(), XSD_ANYXML, null, null, null );
							$header          = new SoapHeader(
								'http://www.bancoeconomico.ao/soa/paymentref', // Namespace
								'HEADER',
								$soap_var_header,
								false // mustunderstand
							);
							$soap_client->__setSoapHeaders( $header );
							// Request params
							$params = array(
								'HEADER' => $this->get_soap_header_params( $msg_and_source_id ),
								'BODY'   =>
									array(
										'Payment' =>
											array(
												'AUTHTOKEN' => trim( $this->multicaixa_settings['token'] ),
												'ENTITYID' => trim( $this->multicaixa_settings['ent'] ),
												'PRODUCT_NO' => '1',
												'SOURCE_ID' => $msg_and_source_id,
												'AMOUNT'   => $this->get_order_total_to_pay( $order ),
												'END_DATE' => date_i18n( 'Y-m-d', strtotime( '+'.intval( $this->multicaixa_settings['ref_validity'] ).' days' ) ),
												'CUSTOMER_NAME' => trim( trim( $order->get_billing_first_name() ).' '.trim( $order->get_billing_last_name() ) ),
												'PHONE_NUMBER' => $order->get_billing_phone(), // Even this one should not be sent
												// 'REFERENCE'  => '',
												// 'START_DATE' => $START_DATE,
												// 'TAX_RATE'   => '0',
												// 'ADDRESS'    => '',
												// 'TAX_ID'     => '',
												// 'EMAIL'      => $order->get_billing_email(), // Not sent for privacy reasons
											),
									),
							);
							// Request
							$result = $soap_client->paymentRefCreate_execute( $params );
							// Check
							if ( isset( $result->HEADER->MSGSTAT ) ) {
								if ( $result->HEADER->MSGSTAT == 'SUCCESS' ) {
									$ent = $result->BODY->Payment_Details->ENTITY_ID;
									$ref = $result->BODY->Payment_Details->REFERENCE;
									$val = floatval( $result->BODY->Payment_Details->AMOUNT );
									$exp = substr( trim( $result->BODY->Payment_Details->END_DATE ), 0, 10 );
									$order->update_meta_data( '_'.$this->id.'_payment_id', $result->BODY->Payment_Details->PAYMENT_ID );
									$order->update_meta_data( '_'.$this->id.'_source_id', $result->BODY->Payment_Details->SOURCE_ID );
									$order->update_meta_data( '_'.$this->id.'_ent', $ent );
									$order->update_meta_data( '_'.$this->id.'_ref', $ref );
									$order->update_meta_data( '_'.$this->id.'_val', $val );
									$order->update_meta_data( '_'.$this->id.'_exp', $exp );
									$order->save();
									$return = array(
										'ent' => $ent,
										'ref' => $ref,
										'val' => $val,
										'exp' => $exp,
									);
									$this->debug_log( 'Order '.$order_id.' - Payment reference successfully generated: '.wp_json_encode( $return ) );
									return $return;
								} else {
									// Should we look into the BODY like in the Query call?
									$body = (array) $result->BODY;
									if ( isset( $body['List-Error-Response']->ERROR[0]->EDESC ) ) {
										throw new SoapFault( 'SoapCall', $body['List-Error-Response']->ERROR[0]->EDESC );
									}
									throw new SoapFault( 'SoapCall', __( 'Unknown error', 'recebimento-facil-multicaixa-for-woocommerce' ) );
								}
							} else {
								throw new SoapFault( 'SoapCall', __( 'No description available', 'recebimento-facil-multicaixa-for-woocommerce' ) );
							}
						} catch ( SoapFault $e ) {
							$error = $this->get_soap_error( $e, __( 'Error contacting Banco Económico system to create Multicaixa Payment:', 'recebimento-facil-multicaixa-for-woocommerce' ) );
						}
					} else {
						$error = __( 'Configuration error. This payment method is disabled because required information (Entity, User ID, Password or Token) was not set by the shop administrator.', 'recebimento-facil-multicaixa-for-woocommerce' );
					}
				}
				if ( $error ) {
					wc_add_notice( $error, 'error' );
					$this->debug_log( 'Order '.$order_id.' - '.$error, 'error' );
					return;
				}
			}
		}
	}

	/* Check payment status */
	private function check_ref_status( $order_id ) {
		$order = wc_get_order( $order_id );
		if (
			( $order->get_currency() == 'AOA' )
			&&
			( $order->get_payment_method() == $this->id )
		) {
			if (
				trim( $this->multicaixa_settings['source'] ) != ''
				&&
				trim( $this->multicaixa_settings['userid'] ) != ''
				&&
				trim( $this->multicaixa_settings['username'] ) != ''
				&&
				trim( $this->multicaixa_settings['password'] ) != ''
				&&
				trim( $this->multicaixa_settings['token'] ) != ''
				&&
				strlen( trim( $this->multicaixa_settings['ent'] ) ) == 5
				&&
				intval( $this->multicaixa_settings['ent'] ) > 0
			) {
				$msg_and_source_id = $order->get_id().'_'.time().'_'.wp_rand( 100, 999 );
				try {
					$this->debug_log( 'Order '.$order_id.' - Checking status manually' );
					// SOAP Client
					$soap_client = new SoapClient( $this->soap_url_query_ref, $this->get_soap_client_params() );
					// Security header
					$soap_var_header = new SoapVar( $this->get_soap_security_header_xml(), XSD_ANYXML, null, null, null );
					$header          = new SoapHeader(
						'http://www.bancoeconomico.ao/soa/paymentref', // Namespace
						'HEADER',
						$soap_var_header,
						false // mustunderstand
					);
					$soap_client->__setSoapHeaders( $header );
					// Request params
					$params = array(
						'HEADER' => $this->get_soap_header_params( $msg_and_source_id ),
						'BODY'   =>
							array(
								'Payment' =>
									array(
										'AUTHTOKEN'     => trim( $this->multicaixa_settings['token'] ),
										'ENTITYID'      => trim( $this->multicaixa_settings['ent'] ),
										'PaymentIdList' => array(
											'PAYMENT_ID' => $order->get_meta( '_'.$this->id.'_payment_id' ),
										),
										'SOURCE_ID'     => $msg_and_source_id,
									),
							),
					);
					// Request
					$result = $soap_client->paymentRefDetailsQuery_execute( $params );
					// Check
					if ( isset( $result->HEADER->MSGSTAT ) ) {
						// if ( $result->HEADER->MSGSTAT == 'SUCCESS' ) { // 2022-11-04: we're getting 'SUCCESS' and not SUCCESS
						if ( stristr( $result->HEADER->MSGSTAT, 'SUCCESS' ) ) {
							$status = $result->BODY->Payment_List->Payment_Details[0]->Status;
							$this->debug_log( 'Order '.$order_id.' - '.$status );
							return array(
								'success' => 1,
								'message' => $status,
								'details' => $result->BODY->Payment_List->Payment_Details[0],
							);
						} else {
							// Convert to array because "List-Error-Response"
							$body = (array) $result->BODY;
							if ( isset( $body['List-Error-Response']->ERROR[0]->EDESC ) ) {
								throw new SoapFault( 'SoapCall', $body['List-Error-Response']->ERROR[0]->EDESC );
							}
							throw new SoapFault( 'SoapCall', __( 'Unknown error', 'recebimento-facil-multicaixa-for-woocommerce' ) );
						}
					}
					throw new SoapFault( 'SoapCall', __( 'Unknown error', 'recebimento-facil-multicaixa-for-woocommerce' ) );
				} catch ( SoapFault $e ) {
					$error = $this->get_soap_error( $e, __( 'Error contacting Banco Económico system to query Multicaixa Payment:', 'recebimento-facil-multicaixa-for-woocommerce' ) );
					$this->debug_log( 'Order '.$order_id.' - '.$error );
					return array(
						'success' => 0,
						'message' => $error,
						'details' => null,
					);
				}
			} else {
				// Config error
			}
		} else {
			return array(
				'success' => 0,
				'message' => __( 'Order not found', 'recebimento-facil-multicaixa-for-woocommerce' ),
			);
		}
	}

	/* Maybe cancel payment reference */
	public function maybe_cancel_ref( $order_id, $from, $to, $order ) {
		if ( $to == 'cancelled' ) {
			if ( $order = wc_get_order( $order_id ) ) {
				if (
					( $order->get_currency() == 'AOA' )
					&&
					( $order->get_payment_method() == $this->id )
				) {
					if ( $this->cancel_ref( $order_id ) ) {
						$order->update_meta_data( '_'.$this->id.'_cancelled', date_i18n( 'Y-m-d H:i' ) );
						$order->save();
						$order->add_order_note( __( 'Multicaixa payment reference cancelled', 'recebimento-facil-multicaixa-for-woocommerce' ) );
					}
				}
			}
		}
	}
	/* Cancel payment reference */
	private function cancel_ref( $order_id ) {
		$order = wc_get_order( $order_id );
		if (
			( $order->get_currency() == 'AOA' )
			&&
			( $order->get_payment_method() == $this->id )
		) {
			if (
				trim( $this->multicaixa_settings['source'] ) != ''
				&&
				trim( $this->multicaixa_settings['userid'] ) != ''
				&&
				trim( $this->multicaixa_settings['username'] ) != ''
				&&
				trim( $this->multicaixa_settings['password'] ) != ''
				&&
				trim( $this->multicaixa_settings['token'] ) != ''
				&&
				strlen( trim( $this->multicaixa_settings['ent'] ) ) == 5
				&&
				intval( $this->multicaixa_settings['ent'] ) > 0
			) {
				$msg_and_source_id = $order->get_id().'_'.time().'_'.wp_rand( 100, 999 );
				try {
					// SOAP Client
					$soap_client = new SoapClient( $this->soap_url_cancel_ref, $this->get_soap_client_params() );
					// Security header
					$soap_var_header = new SoapVar( $this->get_soap_security_header_xml(), XSD_ANYXML, null, null, null );
					$header          = new SoapHeader(
						'http://www.bancoeconomico.ao/soa/paymentref', // Namespace
						'HEADER',
						$soap_var_header,
						false // mustunderstand
					);
					$soap_client->__setSoapHeaders( $header );
					// Request params
					$params = array(
						'HEADER' => $this->get_soap_header_params( $msg_and_source_id ),
						'BODY'   =>
							array(
								'Payment' =>
									array(
										'AUTHTOKEN'     => trim( $this->multicaixa_settings['token'] ),
										'ENTITYID'      => trim( $this->multicaixa_settings['ent'] ),
										'PaymentIdList' => array(
											'PAYMENT_ID' => $order->get_meta( '_'.$this->id.'_payment_id' ),
										),
										'SOURCE_ID'     => $msg_and_source_id,
									),
							),
					);
					// Request
					$result = $soap_client->paymentRefCancel_execute( $params );
					// Check
					if ( isset( $result->HEADER->MSGSTAT ) ) {
						// if ( $result->HEADER->MSGSTAT == 'SUCCESS' ) { // 2022-11-04: we're getting 'SUCCESS' and not SUCCESS
						if ( stristr( $result->HEADER->MSGSTAT, 'SUCCESS' ) ) {
							$status = $result->BODY->Payment_List->Payment_Details[0]->Status;
							if ( $status == 'CANCELED' ) {
								$this->debug_log( 'Order '.$order_id.' - Payment reference successfully canceled' );
								return true;
							} else {
								throw new SoapFault( 'SoapCall', 'Got status '.$status );
							}
						} else {
							// Convert to array because "List-Error-Response"
							$body = (array) $result->BODY;
							if ( isset( $body['List-Error-Response']->ERROR[0]->EDESC ) ) {
								throw new SoapFault( 'SoapCall', $body['List-Error-Response']->ERROR[0]->EDESC );
							}
							throw new SoapFault( 'SoapCall', __( 'Unknown error', 'recebimento-facil-multicaixa-for-woocommerce' ) );
						}
					}
					throw new SoapFault( 'SoapCall', __( 'Unknown error', 'recebimento-facil-multicaixa-for-woocommerce' ) );
				} catch ( SoapFault $e ) {
					$error = $this->get_soap_error( $e, __( 'Error contacting Banco Económico system to cancel Multicaixa Payment:', 'recebimento-facil-multicaixa-for-woocommerce' ) );
					$this->debug_log( 'Order '.$order_id.' - Payment reference not successfully canceled - '.$error );
					return false;
				}
			} else {
				// Config error
			}
		}
	}

	/* Get total to pay */
	public function get_order_total_to_pay( $order ) {
		$order_total_to_pay = $order->get_total();
		/*
		if ( $this->wc_deposits_active ) {
			// Has deposit
			if ( $order->get_meta( '_wc_deposits_order_has_deposit' ) == 'yes' ) {
				// First payment?
				if ( $order->get_meta( '_wc_deposits_deposit_paid' ) != 'yes' && $order->get_status() != 'partially-paid' ) {
					$order_total_to_pay = floatval( $order->get_meta( '_wc_deposits_deposit_amount' ) );
				} else {
					// Second payment
					$order_total_to_pay = floatval( $order->get_meta( '_wc_deposits_second_payment' ) );
				}
			}
		}
		*/
		return $order_total_to_pay;
	}

	/* REST API - Payment notifications and check status */
	public function rest_api_init() {
		register_rest_route( $this->id.'/v1', '/payment_notification/', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_api_payment_notification' ),
			'permission_callback' => function() {
				return true;
			},
		) );
		register_rest_route( $this->id.'/v1', '/check_ref_status/', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_api_check_ref_status' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_woocommerce' );
			},
		) );
	}

	/* REST API - Payment notifications URL */
	public function rest_api_get_payment_notification_url() {
		return rest_url( $this->id.'/v1/payment_notification/' );
	}
	/* REST API - Payment notifications */
	public function rest_api_payment_notification( WP_REST_Request $request ) {
		$ip    = '';
		$agent = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		$this->debug_log( 'Payment notification started from IP: ' . $ip . ', User agent: ' . $agent );
		$response = array(
			'code'        => 500, // Should only happen if the request has no valid JSON
			'description' => 'FAILURE',
			'info'        => 'Invalid request',
			'runtime'     => '',
		);
		if ( $json = $request->get_json_params() ) {
			$this->debug_log( 'JSON Request: '.wp_json_encode( $json ) );
			if ( isset( $json['request']['authentication'] ) && isset( $json['request']['data'] ) ) {
				// Validate token
				if ( $json['request']['authentication'] == trim( $this->multicaixa_settings['notification_token'] ) ) {
					$data = $json['request']['data'];
					if (
						isset( $data['entityID'] ) && trim( $data['entityID'] ) != ''
						&&
						isset( $data['referenceID'] ) && trim( $data['referenceID'] ) != ''
						&&
						isset( $data['sourceID'] ) && trim( $data['sourceID'] ) != ''
						&&
						isset( $data['paymentID'] ) && trim( $data['paymentID'] ) != ''
						&&
						isset( $data['paymentExecutionID'] ) && trim( $data['paymentExecutionID'] ) != ''
						&&
						isset( $data['paymentDateTime'] ) && trim( $data['paymentDateTime'] ) != ''
						&&
						isset( $data['paymentAmount'] ) && floatval( $data['paymentAmount'] ) > 0
					) {
						if ( isset( $data['simulation'] ) && intval( $data['simulation'] ) == 1 ) $this->debug_log( 'This is a simulated payment notification from the shop backoffice' );
						$args = array(
							'type'                      => array( 'shop_order' ),
							// 'status'                    => apply_filters( 'wpwc_recebimentofacil_valid_callback_pending_status', array( 'on-hold', 'pending' ) ), // Check below
							'limit'                     => -1,
							'_'.$this->id.'_ent'        => trim( $data['entityID'] ),
							'_'.$this->id.'_ref'        => trim( $data['referenceID'] ),
							'_'.$this->id.'_source_id'  => trim( $data['sourceID'] ),
							'_'.$this->id.'_payment_id' => trim( $data['paymentID'] ),
							'payment_method'            => $this->id,
						);
						$orders = wc_get_orders( $args );
						if ( count( $orders ) > 0 ) {
							if ( count( $orders ) == 1 ) {
								$order = $orders[0];
								$this->debug_log( 'Order '.$order->get_id().' found' );
								if ( $order->has_status( apply_filters( 'wpwc_recebimentofacil_valid_callback_pending_status', array( 'on-hold', 'pending' ) ) ) ) {
									if ( round( floatval( $data['paymentAmount'] ), 2 ) == round( $this->get_order_total_to_pay( $order ), 2 ) ) {
										$order->add_order_note( __( 'Multicaixa payment received', 'recebimento-facil-multicaixa-for-woocommerce' ).' - PaymentExecutionID: '.trim( $data['paymentExecutionID'] ).' - paymentDateTime: '.trim( $data['paymentDateTime'] ) );
										$order->payment_complete( trim( $data['paymentExecutionID'] ) );
										$response['code']        = 200;
										$response['description'] = 'SUCCESS';
										$response['info']        = 'Order '.$order->get_id().' set as paid';
										$this->debug_log( $response['description'] );
									} else {
										$response['code']        = 404;
										$response['description'] = 'WARNING';
										$response['info']        = 'Order '.$order->get_id().' found but value does not match - From request: '.round( floatval( $data['paymentAmount'] ), 2 ).' - Order value: '.round( $this->get_order_total_to_pay( $order ), 2 );
									}
								} else {
									$response['code']        = 404;
									$response['description'] = 'WARNING';
									$response['info']        = 'Order '.$order->get_id().' found but status ('.$order->get_status().') does not match';
								}
							} else {
								$response['code']        = 404;
								$response['description'] = 'WARNING';
								$response['info']        = 'More than on order found';
							}
						} else {
							$response['code']        = 404; // Changed from 500 to 404 on v1.3.1
							$response['description'] = 'WARNING';
							$response['info']        = 'Order not found';
						}
					} else {
						$response['code']        = 422;
						$response['description'] = 'WARNING';
						$response['info']        = 'Data missing (data)';
					}
				} else {
					$response['code']        = 403;
					$response['description'] = 'WARNING';
					$response['info']        = 'Invalid token';
				}
			} else {
				$response['code']        = 403;
				$response['description'] = 'WARNING';
				$response['info']       .= ' - Data missing (authentication and data)';
			}
		}
		$time_diff           = microtime( true ) - RECEBIMENTOFACIL_START_TIME;
		$time_diff           = explode( '.', $time_diff );
		$response['runtime'] = gmdate( 'H:i:s', $time_diff[0] ) . '.' . $time_diff[1];
		$response            = array(
			'response' => $response,
		);
		$this->debug_log( 'Payment notification ended - response sent: '.wp_json_encode( $response ) );
		return new WP_REST_Response( $response, $response['response']['code'] );
	}

	/* REST API - Payment notifications and check status URL */
	public function rest_api_get_check_ref_status_url() {
		return rest_url( $this->id.'/v1/check_ref_status/' );
	}
	/* REST API - Payment notifications and check status */
	public function rest_api_check_ref_status( WP_REST_Request $request ) {
		$response = array(
			'code'        => 500,
			'description' => 'Invalid request',
			'reload'      => false,
		);
		if ( $json = $request->get_json_params() ) {
			if ( isset( $json['order_id'] ) && intval( $json['order_id'] ) > 0 ) {
				$status                  = $this->check_ref_status( intval( $json['order_id'] ) );
				$response['code']        = 200;
				$response['description'] = $this->translate_ref_status( $status['message'] );
				// Set as paid?
				if ( $status['message'] == 'PAID' ) {
					$order = wc_get_order( intval( $json['order_id'] ) );
					if ( $order->has_status( apply_filters( 'wpwc_recebimentofacil_valid_callback_pending_status', array( 'on-hold', 'pending' ) ) ) ) {
						if ( round( floatval( $status['details']->AMOUNT ), 2 ) == round( $this->get_order_total_to_pay( $order ), 2 ) ) {
							$order->add_order_note( __( 'Multicaixa payment received', 'recebimento-facil-multicaixa-for-woocommerce' ).' - PAYMENT_ID: '.trim( $status['details']->PAYMENT_ID ).' ('.__( 'manually checked', 'recebimento-facil-multicaixa-for-woocommerce' ).')' );
							$order->payment_complete( trim( $status['details']->PAYMENT_ID ) );
							$response['reload']       = true;
							$response['description'] .= ' - '.__( 'This page will now reload. If the order is not set as paid and processing (or completed, if it only contains virtual and downloadable products) please check the debug logs.', 'recebimento-facil-multicaixa-for-woocommerce' );
							$this->debug_log( 'Order '.$order->get_id().' set as paid' );
						}
					}
				}
			} else {
				$response['code']         = 403;
				$response['description'] .= ' - Data missing (order id)';
			}
		}
		return new WP_REST_Response( $response, $response['code'] );
	}
	/* REST API - Translate status */
	private function translate_ref_status( $status ) {
		switch ( $status ) {
			case 'ACTIVE':
				return __( 'The reference is still active and not paid for', 'recebimento-facil-multicaixa-for-woocommerce' );
			case 'INACTIVE':
				return __( 'The reference is inactive', 'recebimento-facil-multicaixa-for-woocommerce' );
			case 'EXPIRED':
				return __( 'The reference is expired and was not paid for', 'recebimento-facil-multicaixa-for-woocommerce' );
			case 'CANCELED':
				return __( 'The reference is canceled', 'recebimento-facil-multicaixa-for-woocommerce' );
			case 'PAID':
				return __( 'The reference is paid', 'recebimento-facil-multicaixa-for-woocommerce' );
			case 'ERROR':
				return __( 'Could not get the reference status', 'recebimento-facil-multicaixa-for-woocommerce' );
		}
		return $status;
	}

	/* Get banner HTML */
	public function get_banner_html( $allow_svg = true ) {
		if ( apply_filters( 'wpwc_recebimentofacil_use_svg', true ) && $allow_svg ) {
			$src = 'data:image/svg+xml;base64,'.base64_encode( file_get_contents( WPWC_RecebimentoFacil()->banner_svg ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$src = WPWC_RecebimentoFacil()->banner;
		}
		return '<img src="'.esc_attr( $src ).'" alt="'.esc_attr( __( 'Recebimento Fácil - Multicaixa', 'recebimento-facil-multicaixa-for-woocommerce' ) ).'" title="'.esc_attr( __( 'Recebimento Fácil - Multicaixa', 'recebimento-facil-multicaixa-for-woocommerce' ) ).'" width="400" height="76" style="max-width: 200px; height: auto;"/>';
	}
	/* Get icon HTML */
	public function get_icon_html( $allow_svg = true ) {
		if ( apply_filters( 'wpwc_recebimentofacil_use_svg', true ) && $allow_svg ) {
			$src = 'data:image/svg+xml;base64,'.base64_encode( file_get_contents( WPWC_RecebimentoFacil()->icon_svg ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$src = WPWC_RecebimentoFacil()->icon;
		}
		return '<img src="'.esc_attr( $src ).'" alt="'.esc_attr( __( 'Recebimento Fácil - Multicaixa', 'recebimento-facil-multicaixa-for-woocommerce' ) ).'" title="'.esc_attr( __( 'Recebimento Fácil - Multicaixa', 'recebimento-facil-multicaixa-for-woocommerce' ) ).'" width="48" height="48" style="max-width: 24px; height: auto;"/>';
	}

	/* Order metabox to show Multicaixa payment details - This will need to change when the order is no longer a WP post */
	public function order_metabox() {
		add_meta_box( $this->id, __( 'Recebimento Fácil', 'recebimento-facil-multicaixa-for-woocommerce' ), array( $this, 'order_metabox_html' ), 'shop_order', 'side', 'core' );
	}
	/* Order metabox html */
	public function order_metabox_html( $post ) {
		$order = wc_get_order( $post->ID );
		if ( $order->get_payment_method() == $this->id ) {
			if (
				$order_mc_details = $this->get_multicaixa_order_details( $order->get_id() )
			) {
				?>
				<p style="text-align: center;">
					<?php
					// echo wp_kses_post( $this->get_banner_html() );
					// wp_kses_post kills our base 64 encoded SVG image, but get_banner_html returns secure and controlled HTML
					echo $this->get_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</p>
				<p>
					<?php esc_html_e( 'Entity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <?php echo esc_html( $order_mc_details['ent'] ); ?>
					<br/>
					<?php esc_html_e( 'Reference', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <?php echo esc_html( $order_mc_details['ref'] ); ?>
					<br/>
					<?php esc_html_e( 'Value', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <?php echo wp_kses_post( wc_price( $order_mc_details['val'], array( 'currency' => $order->get_currency() ) ) ); ?>
					<br/>
					<?php esc_html_e( 'Validity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <?php echo esc_html( $order_mc_details['exp'] ); ?>
				</p>
				<?php
				if ( $order->has_status( apply_filters( 'wpwc_recebimentofacil_valid_callback_pending_status', array( 'on-hold', 'pending' ) ) ) ) {
					?>
					<p><strong><?php esc_html_e( 'Awaiting Multicaixa payment.', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></strong></p>
					<p style="text-align: center;">
						<input type="hidden" id="multicaixa_rest_auth_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"/>
						<input type="button" class="button" id="multicaixa_check_ref_status" value="<?php echo esc_attr( __( 'Check payment status', 'recebimento-facil-multicaixa-for-woocommerce' ) ); ?>"/>
					</p>
					<?php
					if ( $this->test_mode || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
						?>
						<input id="order_mc_details_ent" value="<?php echo esc_attr( $order_mc_details['ent'] ); ?>" type="hidden"/>
						<input id="order_mc_details_ref" value="<?php echo esc_attr( $order_mc_details['ref'] ); ?>" type="hidden"/>
						<input id="order_mc_details_val" value="<?php echo esc_attr( $order_mc_details['val'] ); ?>" type="hidden"/>
						<input id="order_mc_details_source_id" value="<?php echo esc_attr( $order->get_meta( '_'.$this->id.'_source_id' ) ); ?>" type="hidden"/>
						<input id="order_mc_details_payment_id" value="<?php echo esc_attr( $order->get_meta( '_'.$this->id.'_payment_id' ) ); ?>" type="hidden"/>
						<p style="text-align: center;">
							<input type="button" class="button" id="multicaixa_simulate_payment" value="<?php echo esc_attr( __( 'Simulate payment', 'recebimento-facil-multicaixa-for-woocommerce' ) ); ?>"/>
						</p>
						<?php
					}
				} else {
					if ( $date_paid = $order->get_date_paid() ) {
						$date_paid = sprintf(
							'%1$s %2$s',
							wc_format_datetime( $date_paid, 'Y-m-d' ),
							wc_format_datetime( $date_paid, 'H:i' )
						);
						?>
						<p>
							<strong>
								<?php esc_html_e( 'Paid', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <?php echo esc_html( $date_paid ); ?>
							</strong>
						</p>
						<?php
					}
					if ( ! empty( $order_mc_details['cancel'] ) ) {
						?>
						<p>
							<strong>
								<?php esc_html_e( 'Canceled', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <?php echo esc_html( $order_mc_details['cancel'] ); ?>
							</strong>
						</p>
						<?php
					}
				}
			} else {
				?>
				<p><?php esc_html_e( 'No details available', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></p>
				<p><?php esc_html_e( 'This must be an error because the payment method of this order is Multicaixa', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></p>
				<?php
			}
		} else {
			?>
			<p><?php esc_html_e( 'This order does not have Multicaixa as the payment gateway.', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></p>
			<style type="text/css">
				#<?php echo esc_html( $this->id ); ?> {
					display: none;
				}
			</style>
			<?php
			if ( $order_mc_details = $this->get_multicaixa_order_details( $order->get_id() ) ) {
				foreach ( $order_mc_details as $key => $value ) {
					$order->delete_meta_data( '_'.$this->id.'_'.$key );
				}
				$order->save();
			}
		}
	}

	/* Admin scripts and CSS */
	public function admin_scripts() {
		$screen  = get_current_screen();
		$load_js = false;
		if (
			$screen
			&&
			( $screen->id == 'woocommerce_page_wc-settings' )
		) {
			if (
				( ! empty( $_GET['section'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&&
				( $_GET['section'] == 'recebimento_facil_woocommerce' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) {
				$load_js  = true;
				$localize = array(
					'id'   => $this->id,
					'page' => 'settings',
				);
			}
		} elseif (
			$screen
			&&
			( $screen->id == 'shop_order' )
		) { // non HPOS compatible
			if (
				( ! empty( $_GET['post'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&&
				intval( $_GET['post'] ) > 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) {
				if ( $order = wc_get_order( intval( $_GET['post'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if (
						( $order->get_payment_method() == $this->id )
						&&
						$order->has_status( apply_filters( 'wpwc_recebimentofacil_valid_callback_pending_status', array( 'on-hold', 'pending' ) ) )
					) {
						$load_js  = true;
						$localize = array(
							'id'                   => $this->id,
							'page'                 => 'order',
							'order_id'             => $order->get_id(),
							'check_ref_status_url' => $this->check_ref_status_url,
							'notification_token'   => $this->multicaixa_settings['notification_token'],
							'notify_url'           => $this->notify_url,
							'msg_testing_tool'     => __( 'This is a testing tool and will set the order as paid. Are you sure you want to proceed?', 'recebimento-facil-multicaixa-for-woocommerce' ),
							'msg_page_reload'      => __( 'This page will now reload. If the order is not set as paid and processing (or completed, if it only contains virtual and downloadable products) please check the debug logs.', 'recebimento-facil-multicaixa-for-woocommerce' ),
							'msg_unknown_error'    => __( 'Unknown error', 'recebimento-facil-multicaixa-for-woocommerce' ),
							'msg_error'            => __( 'Error', 'recebimento-facil-multicaixa-for-woocommerce' ),
						);
					}
				}
			}
		}
		if ( $load_js ) {
			wp_enqueue_script( $this->id . '_admin_js', plugins_url( 'assets/admin.js', RECEBIMENTOFACIL_PLUGIN_FILE ), array( 'jquery' ), $this->version.( WP_DEBUG ? '.'.wp_rand( 0, 99999 ) : '' ), true );
			wp_localize_script( $this->id . '_admin_js', $this->id, $localize );
		}
	}


	/* Debug / Log */
	public function debug_log( $message, $level = 'debug' ) {
		if ( ! isset( $this->log ) ) $this->log = wc_get_logger(); // Init log
		$this->log->$level( $message, array( 'source' => $this->id ) );
	}

}
