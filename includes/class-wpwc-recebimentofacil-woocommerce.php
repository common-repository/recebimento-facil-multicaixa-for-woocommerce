<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Recebimento Fácil - Banco Económico Class
 */
if ( ! class_exists( 'WPWC_RecebimentoFacil_WooCommerce' ) ) {

	/**
	 * Payment gateway class
	 */
	class WPWC_RecebimentoFacil_WooCommerce extends WC_Payment_Gateway {

		/* Single instance */
		protected static $instance = null;
		public static $instances   = 0;

		/**
		 * Constructor for your payment class
		 *
		 * @access public
		 * @return void
		 */
		public function __construct() {
			self::$instances++;
			$this->id = WPWC_RecebimentoFacil()->id;
			// Logs
			$this->log         = false;
			$this->debug       = ( $this->get_option( 'debug' ) == 'yes' ? true : false );
			$this->debug_email = $this->get_option( 'debug_email' );
			// Check version and upgrade
			$this->version = WPWC_RecebimentoFacil()->version;
			$this->upgrade();
			// Other
			$this->has_fields         = false;
			$this->method_title       = __( 'Multicaixa payment by reference - Banco Económico', 'recebimento-facil-multicaixa-for-woocommerce' );
			$this->method_description = __( 'This plugin allows customers with an Angolan bank account to pay WooCommerce orders using Multicaixa (Pag. por Referência) through the Banco Económico payment gateway.', 'recebimento-facil-multicaixa-for-woocommerce' );

			// Plugin options and settings
			$this->init_form_fields();
			$this->init_settings();
			// User settings
			$this->title              = $this->get_option( 'title' );
			$this->description        = $this->get_option( 'description' );
			$this->extra_instructions = $this->get_option( 'extra_instructions' );
			$this->source             = $this->get_option( 'source' );
			$this->userid             = $this->get_option( 'userid' );
			$this->username           = $this->get_option( 'username' );
			$this->password           = $this->get_option( 'password' );
			$this->token              = $this->get_option( 'token' );
			$this->ent                = $this->get_option( 'ent' );
			$this->only_angola        = ( $this->get_option( 'only_angola' ) == 'yes' ? true : false );
			$this->only_above         = $this->get_option( 'only_above' );
			$this->only_below         = $this->get_option( 'only_below' );
			$this->settings_saved     = $this->get_option( 'settings_saved' );
			// Actions and filters
			if ( self::$instances == 1 ) { // Avoid duplicate actions and filters if it's initiated more than once (if WooCommerce loads after us)
				add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array( $this, 'process_admin_options' ) );
				if ( WPWC_RecebimentoFacil()->wpml_active ) add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'register_wpml_strings' ) );
				add_action( 'woocommerce_thankyou_'.$this->id, array( $this, 'thankyou' ) );
				add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details_after_order_table' ), 9 );
				add_filter( 'woocommerce_available_payment_gateways', array( $this, 'disable_if_currency_not_kwanza' ) );
				// Customer Emails
				add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
				// Limit
				add_filter( 'woocommerce_available_payment_gateways', array( $this, 'woocommerce_available_payment_gateways' ), 11 );
				// Method title in sandbox mode
				if ( WPWC_RecebimentoFacil()->test_mode ) {
					$this->title .= ' - SANDBOX (TEST MODE)';
				}
			}
			// Ensures only one instance of our plugin is loaded or can be loaded - works if WooCommerce loads the payment gateways before we do
			if ( is_null( self::$instance ) ) {
				self::$instance = $this;
			}
		}

		/* Ensures only one instance of our plugin is loaded or can be loaded */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/* Upgrades (if needed) */
		private function upgrade() {
			if ( $this->get_option( 'version' ) < $this->version ) {
				// Upgrade
				$this->debug_log( 'Upgrade to '.$this->version.' started' );
				// Upgrade on the database - Risky?
				$temp                            = get_option( 'woocommerce_'.$this->id.'_settings', '' );
				if ( ! is_array( $temp ) ) $temp = array();
				$temp['version']                 = $this->version;
				update_option( 'woocommerce_'.$this->id.'_settings', $temp );
				$this->debug_log( 'Upgrade to '.$this->version.' finished' );
			}
		}

		/* WPML compatibility */
		public function register_wpml_strings() {
			// These are already registered by WooCommerce Multilingual
			/*
			$to_register=array(
				'title',
				'description',
			);
			*/
			$to_register = apply_filters( 'wpwc_recebimentofacil_wpml_strings', array(
				'extra_instructions',
			) );
			foreach ( $to_register as $string ) {
				icl_register_string( $this->id, $this->id.'_'.$string, $this->settings[ $string ] );
			}
		}

		/* Initialise Gateway Settings Form Fields */
		public function init_form_fields() {

			$validity = array();
			for ( $i = 0; $i <= 30; $i++ ) {
				$validity[ $i ] = sprintf(
					/* translators: %s : number of days */
					_n( 'Today + %s day', 'Today + %s days', $i, 'recebimento-facil-multicaixa-for-woocommerce' ),
					$i
				);
				if ( $i == 0 ) $validity[ $i ] .= ' - '.__( 'End of same day (not recommended)', 'recebimento-facil-multicaixa-for-woocommerce' );
				if ( $i == 3 ) $validity[ $i ] .= ' - '.__( 'Minimum recommended', 'recebimento-facil-multicaixa-for-woocommerce' );
			}

			$this->form_fields = array(
				'enabled'            => array(
					'title'   => __( 'Enable/Disable', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable "Multicaixa payment by reference"', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'default' => 'no',
				),
				'username'           => array(
					'title'       => __( 'Security Username', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'The authentication Security Username provided by Banco Económico.', 'recebimento-facil-multicaixa-for-woocommerce' ),
				),
				'password'           => array(
					'title'       => __( 'Security Password', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'The authentication Security Password provided by Banco Económico.', 'recebimento-facil-multicaixa-for-woocommerce' ),
				),
				'source'             => array(
					'title'       => __( 'Header Source', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'The Header Source provided by Banco Económico.', 'recebimento-facil-multicaixa-for-woocommerce' ).'<br/>'.(
						WPWC_RecebimentoFacil()->test_mode
						?
						'<strong class="recebimentofacil_error">'.__( 'Test mode enabled.', 'recebimento-facil-multicaixa-for-woocommerce' ).'</strong>'
						:
						'<strong class="recebimentofacil_ok" style="display: none;">'.__( 'Live mode enabled.', 'recebimento-facil-multicaixa-for-woocommerce' ).'</strong>'
					),
				),
				'userid'             => array(
					'title'       => __( 'Header User ID', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'The Header User ID provided by Banco Económico.', 'recebimento-facil-multicaixa-for-woocommerce' ),
				),
				'token'              => array(
					'title'       => __( 'Token', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'The authentication Token provided by Banco Económico.', 'recebimento-facil-multicaixa-for-woocommerce' ),
				),
				'ent'                => array(
					'title'             => __( 'Entity', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'              => 'number',
					'description'       => __( 'Entity provided by Banco Económico.', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'default'           => '',
					'css'               => 'width: 80px;',
					'custom_attributes' => array(
						'maxlength' => 5,
						'size'      => 5,
						'max'       => 99999,
					),
				),
				'title'              => array(
					'title'       => __( 'Title', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'default'     => $this->get_method_title(),
				),
				'description'        => array(
					'title'       => __( 'Description', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'default'     => $this->get_method_description(),
				),
				'extra_instructions' => array(
					'title'       => __( 'Extra instructions', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the text which the user sees below the payment details on the "Thank you" page and "New order" email.', 'recebimento-facil-multicaixa-for-woocommerce' )
									.(
										WPWC_RecebimentoFacil()->wpml_active
										?
											' '
											.
											sprintf(
												__( 'You should translate this string in %sWPML - String Translation%s after saving the settings', 'recebimento-facil-multicaixa-for-woocommerce' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText
												'<a href="admin.php?page=wpml-string-translation%2Fmenu%2Fstring-translation.php&context=recebimento_facil_woocommerce" target="_blank">',
												'</a>'
											)
										:
											''
									),
					'default'     => __( 'The receipt issued by the Multicaixa ATM is a proof of payment. Keep it.', 'recebimento-facil-multicaixa-for-woocommerce' ),
				),
				'ref_validity'       => array(
					'title'       => __( 'Reference validity', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'select',
					'description' => __( 'The number of days the reference should be valid for payment.', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'options'     => $validity,
					'default'     => 7,
				),
				'only_angola'        => array(
					'title'   => __( 'Only for Angolan customers?', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable only for customers whose address is in Angola', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'default' => 'no',
				),
				'only_above'         => array(
					'title'             => __( 'Only for orders above', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'              => 'number',
					'description'       => __( 'Enable only for orders above x Kz (exclusive). Leave blank (or zero) to allow for any order value.', 'recebimento-facil-multicaixa-for-woocommerce' ).
									' <br/> '.sprintf(
										/* translators: %1$s: value from, %2$s: value to */
										__( 'By design, Multicaixa only allows payments from %1$s to %2$s (inclusive). You can use this option to further limit this range.', 'recebimento-facil-multicaixa-for-woocommerce' ),
										wc_price( WPWC_RecebimentoFacil()->min_amount ),
										wc_price( WPWC_RecebimentoFacil()->max_amount )
									),
					'default'           => '',
					'custom_attributes' => array(
						'maxlength' => strlen( intval( WPWC_RecebimentoFacil()->max_amount ) ),
						'min'       => intval( WPWC_RecebimentoFacil()->min_amount ),
						'max'       => intval( WPWC_RecebimentoFacil()->max_amount ),
					),
				),
				'only_below'         => array(
					'title'             => __( 'Only for orders below', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'              => 'number',
					'description'       => __( 'Enable only for orders below x Kz (exclusive). Leave blank (or zero) to allow for any order value.', 'recebimento-facil-multicaixa-for-woocommerce' ).
									' <br/> '.sprintf(
										/* translators: %1$s: value from, %2$s: value to */
										__( 'By design, Multicaixa only allows payments from %1$s to %2$s (inclusive). You can use this option to further limit this range.', 'recebimento-facil-multicaixa-for-woocommerce' ),
										wc_price( WPWC_RecebimentoFacil()->min_amount ),
										wc_price( WPWC_RecebimentoFacil()->max_amount )
									),
					'default'           => '',
					'custom_attributes' => array(
						'maxlength' => strlen( intval( WPWC_RecebimentoFacil()->max_amount ) ),
						'min'       => intval( WPWC_RecebimentoFacil()->min_amount ),
						'max'       => intval( WPWC_RecebimentoFacil()->max_amount ),
					),
				),
				'debug'              => array(
					'title'       => __( 'Debug Log', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'recebimento-facil-multicaixa-for-woocommerce' ),
					'default'     => 'yes',
					'description' => sprintf(
						__( 'Log plugin events in %s', 'recebimento-facil-multicaixa-for-woocommerce' ),
						( defined( 'WC_LOG_HANDLER' ) && 'WC_Log_Handler_DB' === WC_LOG_HANDLER )
						?
						'<a href="admin.php?page=wc-status&tab=logs&source='.esc_attr( $this->id ).'" target="_blank">'.__( 'WooCommerce &gt; Status &gt; Logs', 'recebimento-facil-multicaixa-for-woocommerce' ).'</a>'
						:
						'<code>'.wc_get_log_file_path( $this->id ).'</code>'
					),
				),
				'settings_saved'     => array(
					'title'   => '',
					'type'    => 'hidden',
					'default' => 0,
				),
			);
			// Allow other plugins to add settings fields
			$this->form_fields = array_merge( $this->form_fields, apply_filters( 'wpwc_recebimentofacil_settings_fields', array() ) );

		}

		/* Admin HTML */
		public function admin_options() {
			$title       = esc_html( $this->get_method_title() );
			$is_aoa      = trim( get_woocommerce_currency() ) == 'AOA';
			$is_ssl      = is_ssl();
			$soap_active = class_exists( 'SOAPClient' );
			?>
			<div id="recebimentofacil_leftbar">
				<?php WPWC_RecebimentoFacil()->admin_right_bar(); ?>
				<div id="recebimentofacil_leftbar_settings">
					<h2>
						<?php
						// echo wp_kses_post( WPWC_RecebimentoFacil()->get_banner_html() );
						// wp_kses_post kills our base 64 encoded SVG image, but get_banner_html returns secure and controlled HTML
						echo WPWC_RecebimentoFacil()->get_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<br/>
						<?php echo esc_html( $title ); ?>
						<small>v.<?php echo esc_html( $this->version ); ?></small>
						<?php if ( function_exists( 'wc_back_link' ) ) wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>
					</h2>
					<?php echo wp_kses_post( wpautop( $this->get_method_description() ) ); ?>
					<p><strong><?php esc_html_e( 'In order to use this plugin you need to:', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></strong></p>
					<!-- TODO: checkmarks when done each step -->
					<ul class="recebimentofacil_leftbar_list">
						<li>
							<?php
							printf(
								wp_kses_post( __( 'Set WooCommerce currency to <strong>Angolan Kwanza</strong> %1$s', 'recebimento-facil-multicaixa-for-woocommerce' ) ),
								'<a href="admin.php?page=wc-settings&amp;tab=general">&gt;&gt;</a>'
							);
							?>
						</li>
						<?php if ( $is_aoa ) { ?>
							<li><?php esc_html_e( 'Use SSL/HTPS on your website', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></li>
							<li><?php echo wp_kses_post( __( 'Make sure TCP port 8443 is open on the firewall for outbound communication to <strong>spf-webservices.bancoeconomico.ao</strong> (ask your hosting company to check this)', 'recebimento-facil-multicaixa-for-woocommerce' ) ); ?></li>
							<?php if ( $is_ssl ) { ?>
								<li>
									<?php
									printf(
										wp_kses_post( __( 'Sign a contract with %s. and fill out all the details (<strong>Security Username, Security Password, Header Source, Header User ID, Token and Entity</strong>) provided by <strong>Banco Económico</strong> in the fields below.', 'recebimento-facil-multicaixa-for-woocommerce' ) ),
										'<strong><a href="https://www.bancoeconomico.ao/'.esc_attr( WPWC_RecebimentoFacil()->out_link_utm ).'" target="_blank">Banco Económico</a></strong>'
									);
									?>
									<ul class="recebimentofacil_leftbar_list">
										<li>
											<?php
											printf(
												esc_html__( 'To know more about this service, please go to %s', 'recebimento-facil-multicaixa-for-woocommerce' ),
												'<a href="https://www.bancoeconomico.ao/pt/empresas/servicos/recebimento-facil/'.esc_attr( WPWC_RecebimentoFacil()->out_link_utm ).'" target="_blank">https://www.bancoeconomico.ao/pt/empresas/servicos/recebimento-facil/</a>'
											);
											?>
										</li>
										<li> <?php esc_html_e( 'Inform Banco Económico about your server IP address so that they can allow requests from it. If you\'re not sure about the IP, ask your hosting company for it.', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
										</li>
										<li><?php esc_html_e( 'Make sure you never use the same details on more than one website/system or the payment notification will fail (additional accounts should be requested to the bank if needed)', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></li>
									</ul>
								</li>
								<li>
									<?php
									printf(
										esc_html__( 'Request the callback activation, in order to get automatic payment confirmations on WooCommerce, by sending an email to %1$s indicating:', 'recebimento-facil-multicaixa-for-woocommerce' ),
										'<strong><a href="mailto:'.esc_attr( WPWC_RecebimentoFacil()->be_email ).'">'.esc_html( WPWC_RecebimentoFacil()->be_email ).'</a></strong>'
									);
									?>
									<ul class="recebimentofacil_leftbar_list">
										<li><?php esc_html_e( 'User ID', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <strong><?php echo esc_html( $this->userid ); ?></strong></li>
										<li><?php esc_html_e( 'Entity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <strong><?php echo esc_html( $this->ent ); ?></strong></li>
										<li><?php esc_html_e( 'Payment notification URL', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <strong><?php echo esc_url( WPWC_RecebimentoFacil()->notify_url ); ?></strong></li>
										<li><?php esc_html_e( 'Payment notification token', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>: <strong><?php echo esc_html( $this->get_option( 'notification_token' ) ); ?></strong></li>
									</ul>
								</li>
							<?php } ?>
						<?php } ?>
					</ul>
					<?php
					if ( ! ( $is_aoa && $is_ssl && $soap_active ) ) {
						if ( ! $is_aoa ) {
							?>
							<div id="message" class="error">
								<p>
									<strong>
										<?php esc_html_e( 'ERROR!', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
										<?php
										printf(
											esc_html__( 'Set WooCommerce currency to Angolan Kwanza %1$s', 'recebimento-facil-multicaixa-for-woocommerce' ),
											'<a href="admin.php?page=wc-settings&amp;tab=general">'.esc_html__( 'here', 'recebimento-facil-multicaixa-for-woocommerce' ).'</a>.'
										);
										?>
									</strong>
								</p>
							</div>
							<?php
						} elseif ( ! $is_ssl ) {
							?>
							<div id="message" class="error">
								<p>
									<strong>
										<?php esc_html_e( 'ERROR!', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
										<?php
										printf(
											esc_html__( 'You need to use SSL/HTTP on your website', 'recebimento-facil-multicaixa-for-woocommerce' ),
											'<a href="admin.php?page=wc-settings&amp;tab=general">'.esc_html__( 'here', 'recebimento-facil-multicaixa-for-woocommerce' ).'</a>.'
										);
										?>
									</strong>
								</p>
							</div>
							<?php
						} elseif ( ! $soap_active ) {
							?>
							<div id="message" class="error">
								<p>
									<strong>
										<?php esc_html_e( 'ERROR!', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
										<?php
										printf(
											esc_html__( 'You need to enable SOAP support on PHP - please contact your hosting company', 'recebimento-facil-multicaixa-for-woocommerce' ),
											'<a href="admin.php?page=wc-settings&amp;tab=general">'.esc_html__( 'here', 'recebimento-facil-multicaixa-for-woocommerce' ).'</a>.'
										);
										?>
									</strong>
								</p>
							</div>
							<?php
						}
					} else {
						$hide_extra_fields = false;
						if ( // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf
							trim( strlen( $this->ent ) ) == 5
							&&
							intval( $this->ent ) > 0
							&&
							trim( $this->source ) != ''
							&&
							trim( $this->userid ) != ''
							&&
							trim( $this->username ) != ''
							&&
							trim( $this->password ) != ''
							&&
							trim( $this->token ) != ''
						) {
							// OK
						} else {
							$hide_extra_fields = true;
							?>
							<div id="message" class="error">
								<p id="<?php echo esc_attr( $this->id ); ?>_hide_extra_fields"><strong><?php esc_html_e( 'Set the Source, User ID, Password, Token and Entity provided by Banco Económico and Save changes to set other plugin options.', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></strong></p>
							</div>
							<?php
						}
						?>
						<hr/>
						<table class="form-table">
							<?php $this->generate_settings_html(); ?>
						</table>
					<?php } ?>
				</div>
			</div>
			<div class="clear"></div>
			<style type="text/css">
				#recebimentofacil_rightbar {
					display: none;
				}
				@media (min-width: 961px) {
					#recebimentofacil_leftbar {
						height: auto;
						overflow: hidden;
					}
					#recebimentofacil_leftbar_settings {
						width: auto;
						overflow: hidden;
					}
					#recebimentofacil_rightbar {
						display: block;
						float: right;
						width: 200px;
						max-width: 20%;
						margin-left: 20px;
						padding: 15px;
						background-color: #fff;
					}
					#recebimentofacil_rightbar h4:first-child {
						margin-top: 0px;
					}
					#recebimentofacil_rightbar p {
					}
					#recebimentofacil_rightbar p img {
						max-width: 100%;
						height: auto;
						display: block;
						margin:  auto;
					}
				}
				.recebimentofacil_leftbar_list {
					list-style-type: disc;
					list-style-position: inside;
				}
				.recebimentofacil_leftbar_list li {
					margin-left: 1.5em;
				}
				.recebimentofacil_error {
					color: #dc3232;
				}
				.recebimentofacil_ok {
					color: #46b450;
				}
			</style>
			<?php
			do_action( 'wpwc_recebimentofacil_after_settings' );
		}

		/* Icon HTML */
		public function get_icon() {
			return apply_filters( 'woocommerce_gateway_icon', WPWC_RecebimentoFacil()->get_icon_html() );
		}

		/* Thank you page */
		public function thankyou( $order_id ) {
			if ( is_object( $order_id ) ) {
				$order    = $order_id;
				$order_id = $order->get_id();
			} else {
				$order = wc_get_order( $order_id );
			}
			if ( $this->id === $order->get_payment_method() ) {
				if ( $order->has_status( 'on-hold' ) || $order->has_status( 'pending' ) ) {
					$ref = WPWC_RecebimentoFacil()->get_ref( $order_id );
					if ( is_array( $ref ) ) {
						// wp_kses_post kills our base 64 encoded SVG image and CSS, but thankyou_instructions_table_html returns secure and controlled html
						// echo wp_kses_post( $this->thankyou_instructions_table_html( $ref['ent'], $ref['ref'], $ref['val'], $ref['exp'], $order_id ) );
						echo $this->thankyou_instructions_table_html( $ref['ent'], $ref['ref'], $ref['val'], $ref['exp'], $order_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						?>
						<p><strong><?php esc_html_e( 'Error getting Multicaixa payment details', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>.</strong></p>
						<?php
						if ( is_string( $ref ) ) {
							?>
							<p><?php echo esc_html( $ref ); ?></p>
							<?php
						}
					}
				} else {
					// Processing - Not needed
					/*
					if ( $order->has_status( 'processing' ) && !is_wc_endpoint_url( 'view-order') ) {
						echo $this->email_instructions_payment_received( $order_id );
					}
					*/
				}
			}
		}
		/* Thank you table */
		private function thankyou_instructions_table_html( $ent, $ref, $order_total, $end_datetime, $order_id ) {
			$alt                = ( WPWC_RecebimentoFacil()->wpml_active ? icl_t( $this->id, $this->id.'_title', $this->title ) : $this->title );
			$extra_instructions = ( WPWC_RecebimentoFacil()->wpml_active ? icl_t( $this->id, $this->id.'_extra_instructions', $this->extra_instructions ) : $this->extra_instructions );
			ob_start();
			?>
			<style type="text/css">
				table.<?php echo esc_html( $this->id ); ?>_table {
					width: auto !important;
					margin: auto;
					margin-top: 2em;
					margin-bottom: 2em;
				}
				table.<?php echo esc_html( $this->id ); ?>_table td,
				table.<?php echo esc_html( $this->id ); ?>_table th {
					background-color: #FFFFFF;
					color: #000000;
					padding: 10px;
					vertical-align: middle;
					white-space: nowrap;
				}
				@media only screen and (max-width: 450px)  {
					table.<?php echo esc_html( $this->id ); ?>_table td,
					table.<?php echo esc_html( $this->id ); ?>_table th {
						white-space: normal;
					}
				}
				table.<?php echo esc_html( $this->id ); ?>_table th {
					text-align: center;
					font-weight: bold;
				}
				table.<?php echo esc_html( $this->id ); ?>_table th img {
					margin: auto;
					margin-top: 10px;
					max-width: 200px;
				}
			</style>
			<table class="<?php echo esc_html( $this->id ); ?>_table" cellpadding="0" cellspacing="0">
				<tr>
					<th colspan="2">
						<?php esc_html_e( 'Payment instructions', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
						<br/>
						<?php
						// echo wp_kses_post( WPWC_RecebimentoFacil()->get_banner_html() );
						// wp_kses_post kills our base 64 encoded SVG image, but get_banner_html returns secure and controlled HTML
						echo WPWC_RecebimentoFacil()->get_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</th>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Entity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td><?php echo esc_html( $ent ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Reference', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td><?php echo esc_html( WPWC_RecebimentoFacil()->format_multicaixa_ref( $ref ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Value', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td><?php echo esc_html( $order_total ); ?> Kz</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Validity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td><?php echo esc_html( WPWC_RecebimentoFacil()->format_multicaixa_validity_date( $end_datetime ) ); ?></td>
				</tr>
				<tr>
					<td colspan="2" style="font-size: small;"><?php echo wp_kses_post( nl2br( $extra_instructions ) ); ?></td>
				</tr>
			</table>
			<?php
			return apply_filters( 'wpwc_recebimentofacil_thankyou_instructions_table_html', ob_get_clean(), $ent, $ref, $order_total, $end_datetime, $order_id );
		}
		/* Thank you page action */
		public function order_details_after_order_table( $order ) {
			if ( is_wc_endpoint_url( 'view-order' ) ) {
				$this->thankyou( $order );
			}
		}




		/* Email instructions */
		public function email_instructions( $order, $sent_to_admin, $plain_text ) {
			// Avoid duplicate email instructions on some edge cases
			$send = false;
			if ( ( $sent_to_admin ) ) {
				$send = true;
			} else {
				if ( ( ! $sent_to_admin ) ) {
					$send = true;
				}
			}
			// Send
			if ( $send ) {
				$order_id = $order->get_id();
				// Go
				if ( $this->id === $order->get_payment_method() ) {
					// On Hold or pending
					if ( $order->has_status( 'on-hold' ) || $order->has_status( 'pending' ) || $order->has_status( 'partially-paid' ) ) {
						// if ( WPWC_RecebimentoFacil()->wc_deposits_active && $order->get_status() == 'partially-paid' ) {
							// WooCommerce deposits - No instructions
						// } else {
						$ref = WPWC_RecebimentoFacil()->get_ref( $order_id );
						if ( is_array( $ref ) ) {
							if ( apply_filters( 'wpwc_recebimentofacil_email_instructions_pending_send', true, $order_id ) ) {
								echo wp_kses_post( $this->email_instructions_table_html( $ref['ent'], $ref['ref'], $ref['val'], $ref['exp'], $order_id ) );
							}
						} else {
							?>
							<p><strong><?php esc_html_e( 'Error getting Multicaixa payment details', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>.</strong></p>
							<?php
							if ( is_string( $ref ) ) {
								?>
								<p><?php echo esc_html( $ref ); ?></p>
								<?php
							}
						}
						// }
					} else {
						// Processing
						if ( $order->has_status( 'processing' ) ) {
							if ( apply_filters( 'wpwc_recebimentofacil_email_instructions_payment_received_send', true, $order_id ) ) {
								echo wp_kses_post( $this->email_instructions_payment_received( $order_id ) );
							}
						}
					}
				}
			}
		}
		/* Email instructions table */
		private function email_instructions_table_html( $ent, $ref, $order_total, $end_datetime, $order_id ) {
			$alt                = ( WPWC_RecebimentoFacil()->wpml_active ? icl_t( $this->id, $this->id.'_title', $this->title ) : $this->title );
			$extra_instructions = ( WPWC_RecebimentoFacil()->wpml_active ? icl_t( $this->id, $this->id.'_extra_instructions', $this->extra_instructions ) : $this->extra_instructions );
			ob_start();
			?>
			<table cellpadding="10" cellspacing="0" align="center" border="0" style="margin: auto; margin-top: 2em; margin-bottom: 2em; border-collapse: collapse; border: 1px solid #0B3258; border-radius: 4px !important; background-color: #FFFFFF;">
				<tr>
					<td style="border: 1px solid #0B3258; border-top-right-radius: 4px !important; border-top-left-radius: 4px !important; text-align: center; color: #000000; font-weight: bold;" colspan="2">
						<?php esc_html_e( 'Payment instructions', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
						<br/>
						<?php echo wp_kses_post( WPWC_RecebimentoFacil()->get_banner_html( false ) ); ?>
					</td>
				</tr>
				<tr>
					<td style="border: 1px solid #0B3258; color: #000000;"><?php esc_html_e( 'Entity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td style="border: 1px solid #0B3258; color: #000000; white-space: nowrap;"><?php echo esc_html( $ent ); ?></td>
				</tr>
				<tr>
					<td style="border: 1px solid #0B3258; color: #000000;"><?php esc_html_e( 'Reference', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td style="border: 1px solid #0B3258; color: #000000; white-space: nowrap;"><?php echo esc_html( WPWC_RecebimentoFacil()->format_multicaixa_ref( $ref ) ); ?></td>
				</tr>
				<tr>
					<td style="border: 1px solid #0B3258; color: #000000;"><?php esc_html_e( 'Value', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td style="border: 1px solid #0B3258; color: #000000; white-space: nowrap;"><?php echo esc_html( $order_total ); ?> Kz</td>
				</tr>
				<tr>
					<td style="border: 1px solid #0B3258; color: #000000;"><?php esc_html_e( 'Validity', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>:</td>
					<td style="border: 1px solid #0B3258; color: #000000; white-space: nowrap;"><?php echo esc_html( WPWC_RecebimentoFacil()->format_multicaixa_validity_date( $end_datetime ) ); ?></td>
				</tr>
				<tr>
					<td style="font-size: x-small; border: 1px solid #0B3258; border-bottom-right-radius: 4px !important; border-bottom-left-radius: 4px !important; color: #000000; text-align: center;" colspan="2"><?php echo wp_kses_post( nl2br( $extra_instructions ) ); ?></td>
				</tr>
			</table>
			<?php
			return apply_filters( 'wpwc_recebimentofacil_email_instructions_table_html', ob_get_clean(), $ent, $ref, $order_total, $end_datetime, $order_id );
		}
		/* Email instructions payment received */
		private function email_instructions_payment_received( $order_id ) {
			$alt = ( WPWC_RecebimentoFacil()->wpml_active ? icl_t( $this->id, $this->id.'_title', $this->title ) : $this->title );
			ob_start();
			?>
			<p style="text-align: center; margin: auto; margin-top: 2em; margin-bottom: 2em;">
				<?php echo wp_kses_post( WPWC_RecebimentoFacil()->get_banner_html( false ) ); ?>
				<br/>
				<strong><?php esc_html_e( 'Multicaixa payment received.', 'recebimento-facil-multicaixa-for-woocommerce' ); ?></strong>
				<br/>
				<?php esc_html_e( 'We will now process your order.', 'recebimento-facil-multicaixa-for-woocommerce' ); ?>
			</p>
			<?php
			return apply_filters( 'wpwc_recebimentofacil_email_instructions_payment_received', ob_get_clean(), $order_id );
		}

		/* Process it */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$ref   = WPWC_RecebimentoFacil()->get_ref( $order_id );
			if ( is_array( $ref ) ) {
				// Mark as on-hold
				if ( $order->get_total() > 0 ) {
					if ( apply_filters( 'wpwc_recebimentofacil_set_on_hold', true, $order->get_id() ) ) $order->update_status( 'on-hold', __( 'Waiting for the Multicaixa payment.', 'recebimento-facil-multicaixa-for-woocommerce' ) );
				} else {
					$order->payment_complete();
				}
				// Remove cart
				if ( isset( WC()->cart ) ) {
					WC()->cart->empty_cart();
				}
				// Empty awaiting payment session
				unset( WC()->session->order_awaiting_payment );
				// Return thankyou redirect
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			} else {
				wc_add_notice( (string) $ref, 'error' );
			}
		}

		/* Just for Kz */
		public function disable_if_currency_not_kwanza( $available_gateways ) {
			if ( isset( $available_gateways[ $this->id ] ) ) {
				if ( trim( get_woocommerce_currency() ) != 'AOA' ) unset( $available_gateways[ $this->id ] );
			}
			return $available_gateways;
		}

		/* Limit usage */
		public function woocommerce_available_payment_gateways( $available_gateways ) {
			if ( isset( $available_gateways[ $this->id ] ) ) {
				// Order total or cart total?
				$value_to_pay = null;
				$pay_slug     = get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' );
				$order_id     = absint( get_query_var( $pay_slug ) );
				if ( $order_id > 0 ) {
					// Pay screen on My Account
					$order        = wc_get_order( $order_id );
					$value_to_pay = WPWC_RecebimentoFacil()->get_order_total_to_pay( $order );
				} else {
					// Checkout?
					if ( ! is_null( WC()->cart ) ) {
						$value_to_pay = WC()->cart->total; // We're not checking if we're paying just a deposit...
					} else {
						// No cart? Where are we? We shouldn't unset our payment gateway
					}
				}
				// Test it
				if (
					// Should we also check for credentials being set?
					( $this->only_angola && WC()->customer && WC()->customer->get_billing_country() != 'AO' && WC()->customer->get_shipping_country() != 'AO' )
					||
					( floatval( $this->only_above ) > 0 && floatval( $this->only_above ) > $value_to_pay )
					||
					( floatval( $this->only_below ) > 0 && floatval( $this->only_below ) < $value_to_pay )
				) {
					unset( $available_gateways[ $this->id ] );
				}
			}
			return $available_gateways;
		}

		/* Debug / Log */
		private function debug_log( $message, $level = 'debug' ) {
			WPWC_RecebimentoFacil()->debug_log( $message, $level );
		}

	}
}

/* Main class */
function WPWC_RecebimentoFacil() {
	return \IDN\WPWC_RecebimentoFacil\WPWC_RecebimentoFacil();
}
