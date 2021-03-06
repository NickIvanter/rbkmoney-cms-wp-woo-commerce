<?php
/*
Plugin Name: WooCommerce RBKmoney Payment Gateway
Plugin URI: https://www.rbk.money
Description: RBKmoney Payment gateway for woocommerce
Version: 1.0.5
Author: RBKmoney
Author URI: https://www.rbk.money
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Add custom action links
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'rbkmoney_action_links' );
function rbkmoney_action_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rbkmoney', 'rbkmoney' ) . '">' . __( 'Настройки', 'rbkmoney' ) . '</a>',
	);

	// Merge our new link with the default ones
	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_row_meta', 'custom_plugin_row_meta', 10, 2 );

function custom_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, plugin_basename( __FILE__ ) ) !== false ) {
		$new_links = array(
			'docs'     => '<a href="https://rbkmoney.github.io/docs/" target="_blank">' . __( 'Документация', 'rbkmoney' ) . '</a>',
			'docs_api' => '<a href="https://rbkmoney.github.io/api/" target="_blank">' . __( 'Документация по API', 'rbkmoney' ) . '</a>'
		);
		$links     = array_merge( $links, $new_links );
	}

	return $links;
}

add_action( 'plugins_loaded', 'rbkmoney_add_gateway_class', 0 );
function rbkmoney_add_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	/**
	 * RBKmoney Payment Gateway
	 *
	 * Provides an RBKmoney Payment Gateway
	 *
	 * @class       WC_RBKmoney_Gateway
	 * @extends     WC_Payment_Gateway
	 * @version     1.0.5
	 * @package     WooCommerce/Classes/Payment
	 * @author      RBKmoney
	 *
	 * @see https://docs.woocommerce.com/document/payment-gateway-api/
	 */
	class WC_Gateway_RBKmoney extends WC_Payment_Gateway {

		/** @var bool Whether or not logging is enabled */
		public static $log_enabled = false;

		/** @var WC_Logger Logger instance */
		public static $log = false;

		// ------------------------------------------------------------------------ 
		// Constants
		// ------------------------------------------------------------------------

		const GATEWAY_NAME = 'RBKmoney';
		const PLUGIN_VERSION = '1.0.5';

		/**
		 * URL-s
		 */
		const PAYMENT_FORM_URL = 'https://checkout.rbk.money/checkout.js';
		const API_URL = 'https://api.rbk.money/v2/';

		/**
		 * HTTP METHOD
		 */
		const HTTP_METHOD_POST = 'POST';

		/**
		 * Create invoice settings
		 */

		const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
		const CREATE_INVOICE_DUE_DATE_DEFAULT_VALUE = 24;

		/**
		 * HTTP status code
		 */
		const HTTP_CODE_OK = 'HTTP/1.1 200 OK';
		const HTTP_CODE_CREATED = 'HTTP/1.1 201 CREATED';
		const HTTP_CODE_CREATED_NUMBER = 201;
		const HTTP_CODE_BAD_REQUEST = 'HTTP/1.1 400 BAD REQUEST';

		/**
		 * Constants for Callback
		 */
		const SIGNATURE = 'HTTP_CONTENT_SIGNATURE';
		const SIGNATURE_PATTERN = "/alg=(\S+);\sdigest=/";

		const EVENT_TYPE = 'eventType';

		// EVENT TYPE INVOICE
		const EVENT_TYPE_INVOICE_CREATED = 'InvoiceCreated';
		const EVENT_TYPE_INVOICE_PAID = 'InvoicePaid';
		const EVENT_TYPE_INVOICE_CANCELLED = 'InvoiceCancelled';
		const EVENT_TYPE_INVOICE_FULFILLED = 'InvoiceFulfilled';

		// EVENT TYPE PAYMENT
		const EVENT_TYPE_PAYMENT_STARTED = 'PaymentStarted';
		const EVENT_TYPE_PAYMENT_PROCESSED = 'PaymentProcessed';
		const EVENT_TYPE_PAYMENT_CAPTURED = 'PaymentCaptured';
		const EVENT_TYPE_PAYMENT_CANCELLED = 'PaymentCancelled';
		const EVENT_TYPE_PAYMENT_FAILED = 'PaymentFailed';

		const INVOICE = 'invoice';
		const INVOICE_ID = 'id';
		const INVOICE_SHOP_ID = 'shopID';
		const INVOICE_METADATA = 'metadata';
		const INVOICE_STATUS = 'status';
		const INVOICE_AMOUNT = 'amount';

		const ORDER_ID = 'order_id';

		/**
		 * Openssl verify
		 */
		const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;


		/**
		 * Constructor for the gateway
		 */
		public function __construct() {
			/**
			 * The unique ID for this gateway
			 */
			$this->id = "rbkmoney";

			/**
			 * Title used on the front side at the checkout page
			 */
			$this->title = $this->get_option( 'title' );

			/**
			 * Payment method description for the frontend
			 */
			$this->description = $this->get_option( 'description' );

			/**
			 * The link to the image displayed next to the method’s title on the checkout page
			 * — this is optional and doesn’t need to be set.
			 */
			$this->icon = apply_filters( 'woocommerce_offline_icon', '' );

			/**
			 * This should be false for our simple gateway, but can be set to true
			 * if you create a direct payment gateway that will have fields,
			 * such as credit card fields.
			 */
			$this->has_fields = false;

			/**
			 * The title of the payment method for the admin page
			 */
			$this->method_title = __( static::GATEWAY_NAME, $this->id );

			/**
			 * The description for the payment method shown to the admins
			 */
			$this->method_description = __( 'Платежный модуль RBKmoney', $this->id );

			$this->debug = 'yes' === $this->get_option( 'debug', 'no' );

			self::$log_enabled = $this->debug;

			$this->payment_page = add_query_arg( 'wc-api', 'checkout_rbk', home_url( '/' ) );
			add_action( 'woocommerce_api_checkout_rbk', array( $this, 'display_payment_form' ) );

			/**
			 * Once we’ve set these variables, the constructor will need a few other functions.
			 * We’ll have to initialize the form fields and settings.
			 */
			$this->init_form_fields();
			$this->init_settings();


			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
			// Save options
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );
			// Payment listener/API hook
			add_action( 'woocommerce_api_' . $this->id . '_callback', array( $this, 'callback_handler' ) );
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {

			$order_id = wc_get_order_id_by_order_key( $_GET['key'] );

			// Reduce stock levels
			wc_reduce_stock_levels( $order_id );

			// Remove cart
			WC()->cart->empty_cart();

			if ( ! isset( $_GET['status'] ) || $_GET['status'] != 'success' ) {
				return;
			}

			echo '<p><strong>Оплата принята</strong>.</p>';
			echo '<p>Пожалуйста, проверьте почту: мы отправили подтверждение и инструкции для входа в личный кабинет.</p>';

			/** @var WC_Abstract_Order $order */
			$order = wc_get_order( $order_id );

			if ( $user = get_user_by( 'email', $order->get_billing_email() ) ) {
				$user_id = $user->ID;
			} else {
				$user_id = wc_create_new_customer( $order->get_billing_email(), '', '', array(
					'first_name'   => $order->get_billing_first_name(),
					'last_name'    => $order->get_billing_last_name(),
					'display_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				) );

				if ( is_wp_error( $user_id ) ) {
					throw new Exception( $user_id->get_error_message() );
				}
			}

			/**
			 * Unless the billing email belongs to an administrator,
			 * authenticate user without requiring password.
			 */
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				wc_set_customer_auth_cookie( $user_id );

				/*
				 * Link the order to the user_id.
				 */
				wc_update_new_customer_past_orders( $user_id );

				echo '<p><strong>Уже можно приступать к обучению</strong>.</p>';
			}

			echo '<p><a href="' . get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) . '" class="button">Перейти в личный кабинет</a><p>';
		}

		/**
		 * Redirection to hosted payment page
		 *
		 **/
		public function display_payment_form() {

			$session_handler = new WC_Session_Handler();

			$order_id = wc_get_order_id_by_order_key( $_GET['key'] );

			if ( empty( $order_id ) ) {
				$this->log( 'Empty OrderId' );
				wc_add_notice( 'Во время обработки заказа произошла ошибка.', 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}

			/** @var WC_Abstract_Order $order */
			$order = wc_get_order( $order_id );

			try {
				$invoice_id   = $session_handler->get( $order_id . "invoice_id" );
				$access_token = $session_handler->get( $order_id . "access_token" );

				if ( empty( $invoice_id ) ) {
					$response = $this->_create_invoice( $order );

					$invoice_id = $response["invoice"]["id"];
					$session_handler->set( $order_id . "invoice_id", $invoice_id );

					$access_token = $response["invoiceAccessToken"]["payload"];
					$session_handler->set( $order_id . "access_token", $access_token );
				}
			} catch ( Exception $ex ) {
				echo __( 'Что-то пошло не так! Мы уже знаем и работаем над этим!', $this->id );
				$this->log( "Ошибка при создании инвойса" . ' ' . wc_print_r( $ex, true ) );
				exit();
			}

			$order_description = '';

			$order_items = $order->get_items( array( 'line_item' ) );

			foreach ( $order_items as $item_id => $order_item ) {

				if ( ! empty( $order_description ) ) {
					$order_description .= ', ';
				}
				$order_description .= $order_item->get_name();
			}

			$form_company_name = $this->get_option( 'form_company_name' );
			$company_name      = ! empty( $form_company_name ) ? $form_company_name : '';

			$form_css_button = $this->get_option( 'form_css_button' );
			$style           = ! empty( $form_css_button ) ? '<style>' . $form_css_button . '</style>' : '';


			$html = "
			<script src=\"" . static::PAYMENT_FORM_URL . "\"></script>
			<script src='" . home_url( 'wp-includes/js/jquery/jquery.js' ) . "'></script>
			<script>

			var checkout;

			window.onload = function () {			    
			    checkout = RbkmoneyCheckout.configure({
                    invoiceID: '" . $invoice_id . "',
    				invoiceAccessToken: '" . $access_token . "',
    				name: '" . $company_name . "',
    				description: '" . $order_description . "',
    				email: '" . $order->get_billing_email() . "',
    				popupMode: true,
    				googlePay: false,
    				samsungPay: false,
    				requireCardHolder: false,
    				
    				opened: function () {
        				console.log('Checkout opened');
    				},
    				closed: function () {
        				console.log('Checkout closed');
    				},
    				finished: function () {
        				console.log('Payment completed successfully');	
        				window.location.replace('" . $this->get_return_url( $order ) . '&status=success' . "');
    				}
				});
			};
			
			var number_of_polls = 0;
			
			function poll_for_payment() {
				jQuery('#waiting').css('display', 'block');
				setTimeout(function() {
					number_of_polls++;
			        jQuery.ajax({
						type : 'post',
						data : { 'action' : 'rbk_payment_status', 'order_id' : '" . $order_id .  "' },
        			    url : '" . admin_url( 'admin-ajax.php' ) . "',
        			    success: function( response ) {
							if( response.status == 'processing' ) {
								console.log('Order status = processing');
        			            window.location.replace('" . $this->get_return_url( $order ) . "&status=success&polls=' + number_of_polls);
            				} else {
							    console.log('Completed poll, scheduling another: ' + number_of_polls);
							    jQuery('#count').html(number_of_polls);
							    if ( number_of_polls < 1800 ) { // 30 minutes
							    	poll_for_payment();
							    } else {
							        window.location.replace('" . $order->get_checkout_payment_url() . "');
							    }
            				}
            			}
    				});
				}, 1000);
			}
			
			</script>
			
			<meta name='viewport' content='width=device-width, initial-scale=1'>
			
			<div>
			<button style='margin: 50px auto; display: block;' id='rbkmoney-button' onclick='event.preventDefault(); checkout.open(); setTimeout(poll_for_payment, 5000);'>Перейти к оплате</button>
			<p id='waiting' style='text-align: center; font-size: 1.4rem; display: none;'>Ожидаем подтверждение оплаты от банка. Не закрывайте это окно.</p>
			<p id='count' style='text-align: center; font-size: 1.4rem;'></p>
			</div>
			";

			echo $style . $html;
			exit();
		}

		/**
		 * Return handler for Hosted Payments.
		 * e.g. ?wc-api=rbkmoney_callback
		 */
		public function callback_handler() {
			$content = file_get_contents( 'php://input' );
			$logs    = array(
				'content' => $content,
				'method'  => $_SERVER['REQUEST_METHOD'],
			);

			if ( empty( $_SERVER[ static::SIGNATURE ] ) ) {
				$message = __( 'Отсутствует подпись уведомления для Webhook', $this->id );
				$this->output( $message, $logs );
			}
			$logs['signature'] = $_SERVER[ static::SIGNATURE ];

			$signature_from_header = $this->get_signature_from_header( $_SERVER[ static::SIGNATURE ] );
			$decoded_signature     = $this->url_safe_b64decode( $signature_from_header );
			$public_key            = $this->_get_public_key();
			if ( ! $this->verification_signature( $content, $decoded_signature, $public_key ) ) {
				$message = __( 'Несоответствие сигнатуры уведомления для Webhook', $this->id );
				$this->output( $message, $logs );
			}

			$required_fields = [ static::INVOICE, static::EVENT_TYPE ];
			$data            = json_decode( $content, true );

			foreach ( $required_fields as $field ) {
				if ( empty( $data[ $field ] ) ) {
					$message = __( 'Одно или несколько обязательный полей отсутствуют', $this->id );
					$this->output( $message, $logs );
				}
			}

			$current_shop_id = $this->get_option( 'shop_id' );
			if ( $data[ static::INVOICE ][ static::INVOICE_SHOP_ID ] != $current_shop_id ) {
				$message = static::INVOICE_SHOP_ID . __( ' отсутствует', $this->id );
				$this->output( $message, $logs );
			}

			if ( empty( $data[ static::INVOICE ][ static::INVOICE_METADATA ][ static::ORDER_ID ] ) ) {
				$message = static::ORDER_ID . __( ' отсутствует', $this->id );
				$this->output( $message, $logs );
			}

			$order_id = $data[ static::INVOICE ][ static::INVOICE_METADATA ][ static::ORDER_ID ];
			$order    = wc_get_order( $order_id );

			if ( empty( $order ) ) {
				$message = __( 'Заказ ', $this->id ) . $order_id . __( ' отсутствует', $this->id );
				$this->output( $message, $logs );
			}

			if ( ! empty( $order_info['total'] ) ) {
				$order_amount   = (int) $this->_prepare_amount( $order->get_data()['total'] );
				$invoice_amount = (int) $data[ static::INVOICE ][ static::INVOICE_AMOUNT ];
				if ( $order_amount != $invoice_amount ) {
					$message = __( 'Полученная сумма не соответствует сумме заказа', $this->id );
					$this->output( $message, $logs );
				}
			}

			$allowed_event_types  = [ static::EVENT_TYPE_INVOICE_PAID, static::EVENT_TYPE_INVOICE_CANCELLED ];
			$not_allowed_statuses = [ 'completed', 'cancelled' ];
			if ( ! in_array( $order->status, $not_allowed_statuses ) && in_array( $data[ static::EVENT_TYPE ], $allowed_event_types ) ) {

				if ( $data[ static::EVENT_TYPE ] == static::EVENT_TYPE_INVOICE_PAID ) {
					$order->add_order_note( sprintf( __( 'Платеж подтвержден', $this->id ) . '(invoice ID: %1$s)', $data[ static::INVOICE ][ static::INVOICE_ID ] ) );
					$order->payment_complete( $data[ static::INVOICE ][ static::INVOICE_ID ] );
					$message = __( 'Платеж подтвержден', $this->id ) . ', invoice ID: ' . $data[ static::INVOICE ][ static::INVOICE_ID ];
				}

				if ( $data[ static::EVENT_TYPE ] == static::EVENT_TYPE_INVOICE_CANCELLED ) {
					$order->update_status( 'cancelled', sprintf( __( 'Платеж отменен', $this->id ) . '(invoice ID: %1$s)', $data[ static::INVOICE ][ static::INVOICE_ID ] ) );
					$message = __( 'Платеж отменен', $this->id ) . ', invoice ID: ' . $data[ static::INVOICE ][ static::INVOICE_ID ];
				}

				$this->output( $message, $logs, self::HTTP_CODE_OK );
			}

			exit();
		}

		private function _get_public_key() {
			$callback_public_key = $this->get_option( 'callback_public_key' );

			return trim( $callback_public_key );
		}

		private function output( $message, &$logs, $header = self::HTTP_CODE_BAD_REQUEST ) {
			header( $header );
			$response = array( 'message' => $message );
			$this->log( $message . ' ' . wc_print_r( $logs, true ) );
			echo json_encode( $response );
			exit();
		}

		public function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wptexturize( $this->description ) );
			}
		}

		/**
		 * Initialise Gateway Settings Form Fields
		 */
		function init_form_fields() {
			$this->form_fields = array(

				'enabled' => array(
					'title'   => __( 'Включить/Выключить', $this->id ),
					'type'    => 'checkbox',
					'label'   => __( 'Включить прием платежей RBKmoney', $this->id ),
					'default' => 'yes'
				),

				'title' => array(
					'title'       => __( 'Заголовок', $this->id ),
					'type'        => 'text',
					'description' => __( 'Это заголовок RBKmoney, который метод оплаты видит во время проверки.', $this->id ),
					'default'     => __( static::GATEWAY_NAME, $this->id ),
					'desc_tip'    => true,
				),

				'description' => array(
					'title'       => __( 'Описание', $this->id ),
					'type'        => 'textarea',
					'description' => __( '', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'shop_id' => array(
					'title'       => __( 'Shop ID', $this->id ),
					'type'        => 'text',
					'description' => __( 'Shop ID магазина в системе RBKmoney', $this->id ),
					'default'     => __( '1', $this->id ),
					'desc_tip'    => true,
				),

				'private_key' => array(
					'title'       => __( 'Приватный ключ', $this->id ),
					'type'        => 'textarea',
					'description' => __( 'Приватный ключ для проведения оплаты', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'notify_url' => array(
					'title'       => __( 'URL для уведомлений', $this->id ),
					'type'        => 'text',
					'description' => __( 'Этот адрес для добавления Webhook-ов в ЛК RBKmoney', $this->id ),
					'default'     => __( 'http(s)://your-site/?wc-api=rbkmoney_callback', $this->id ),
					'desc_tip'    => true,
				),

				'callback_public_key' => array(
					'title'       => __( 'Публичный ключ', $this->id ),
					'type'        => 'textarea',
					'description' => __( 'Публичный ключ для авторизации уведомлений о статусе оплаты', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'form_css_button' => array(
					'title'       => __( 'Стилизация кнопки оплаты', $this->id ),
					'type'        => 'textarea',
					'description' => __( 'Стилизация кнопки оплаты', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'form_company_name' => array(
					'title'       => __( 'Название компании в платежной форме', $this->id ),
					'type'        => 'text',
					'description' => __( 'Название компании в платежной форме', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'form_button_label' => array(
					'title'       => __( 'Значение кнопки в платежной форме', $this->id ),
					'type'        => 'text',
					'description' => __( 'Значение кнопки в платежной форме', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'form_description' => array(
					'title'       => __( 'Описание в платежной форме', $this->id ),
					'type'        => 'text',
					'description' => __( 'Описание в платежной форме', $this->id ),
					'default'     => __( '', $this->id ),
					'desc_tip'    => true,
				),

				'status_start_payment' => array(
					'title'       => __( 'Статус заказа при инициализации платежа', $this->id ),
					'description' => __( 'Статус устанавливается после подтверждения заказа', $this->id ),
					'type'        => 'select',
					'default'     => __( 'pending', $this->id ),
					'options'     =>
						array(
							'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
							'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
							'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' )
						),
					'desc_tip'    => true,
				),

				'status_order_paid' => array(
					'title'       => __( 'Статус заказа после оплаты', $this->id ),
					'description' => __( 'Статус устанавливается после успешной оплаты заказа', $this->id ),
					'type'        => 'select',
					'default'     => __( 'completed', $this->id ),
					'options'     =>
						array(
							'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
							'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
							'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' )
						),
					'desc_tip'    => true,
				),

				'order_lifetime' => array(
					'title'       => __( 'Время жизни заказа в часах', $this->id ),
					'description' => __( 'Время жизни заказа (в часах)', $this->id ),
					'type'        => 'number',
					'default'     => __( '24', $this->id ),
					'desc_tip'    => true,
				),

				'debug' => array(
					'title'       => __( 'Журнал отладки', $this->id ),
					'type'        => 'checkbox',
					'label'       => __( 'Включить логирование', $this->id ),
					'default'     => 'no',
					'description' => sprintf( __( 'Журнал событий: %s', $this->id ), '<code>' . WC_Log_Handler_File::get_log_file_path( $this->id ) . '</code>' ),
				),

			);

		}


		/**
		 * Process Payment.
		 *
		 * Process the payment. Override this in your gateway. When implemented, this should.
		 * return the success and redirect in an array. e.g:
		 *
		 *        return array(
		 *            'result'   => 'success',
		 *            'redirect' => $this->get_return_url( $order )
		 *        );
		 *
		 * @param int $order_id
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			return array(
				'result'   => 'success',
				'redirect' => $this->payment_page . '&key=' . $order->get_order_key()
			);
		}

		private function _create_invoice( $order ) {
			$shop_id = $this->get_option( 'shop_id' );
			$data    = [
				'shopID'      => $shop_id,
				'amount'      => $this->_prepare_amount( $order->order_total ),
				'metadata'    => $this->_prepare_metadata( $order ),
				'dueDate'     => $this->_prepare_due_date(),
				'currency'    => $order->currency,
				'product'     => __( 'Заказ № ', $this->id ) . $order->id . '',
				'cart'        => $this->_prepare_cart( $order ),
				'description' => '',
			];

			$url      = $this->_prepare_api_url( 'processing/invoices' );
			$response = $this->send( $url, $this->_get_headers(), json_encode( $data ) );

			if ( $response['http_code'] != static::HTTP_CODE_CREATED_NUMBER ) {
				$message = __( 'Произошла ошибка при создании инвойса', $this->id );
				throw new Exception( $message );
			}

			return json_decode( $response['body'], true );
		}

		private function send( $url, $headers = [], $data = '' ) {
			$logs    = array(
				'url'     => $url,
				'headers' => $headers,
				'data'    => $data,
			);
			$message = '';

			if ( empty( $url ) ) {
				$message = 'Не передан обязательный параметр url';
				$this->log( $message . wc_print_r( $logs, true ), 'error' );
				throw new Exception( $message );
			}

			$curl = curl_init( $url );
			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $data );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

			$body       = curl_exec( $curl );
			$info       = curl_getinfo( $curl );
			$curl_errno = curl_errno( $curl );

			$response['http_code'] = $info['http_code'];
			$response['body']      = $body;
			$response['error']     = $curl_errno;

			curl_close( $curl );

			$logs['response'] = $response;
			$this->log( $message . wc_print_r( $logs, true ) );

			return $response;
		}


		/**
		 * Prepare cart
		 *
		 * @param $order
		 *
		 * @return array
		 */
		private function _prepare_cart( $order ) {
			$items    = $this->_prepare_items_for_cart( $order );
			$shipping = $this->_prepare_shipping_for_cart( $order );

			return array_merge( $shipping, $items );
		}

		/**
		 * Prepare items for cart
		 *
		 * @param $order
		 *
		 * @return array
		 */
		private function _prepare_items_for_cart( $order ) {
			$lines = array();
			$items = $order->get_items();

			foreach ( $items as $product ) {
				$item             = array();
				$item['product']  = $product['name'];
				$item['quantity'] = (int) $product['qty'];

				$amount = ( $product['line_total'] / $product['qty'] ) + ( $product['line_tax'] / $product['qty'] );
				$amount = round( $amount, 2 );
				if ( $amount <= 0 ) {
					continue;
				}
				$item['price'] = $this->_prepare_amount( $amount );

				$tax         = $product['line_tax'] / $product['line_total'] * 100;
				$product_tax = (int) $tax;

				if ( ! empty( $product_tax ) ) {

					$tax_rate = $this->_get_tax_rate( $product_tax );
					if ( $tax_rate != null ) {
						$tax_mode        = array(
							'type' => 'InvoiceLineTaxVAT',
							'rate' => $tax_rate,
						);
						$item['taxMode'] = $tax_mode;
					}

				}
				$lines[] = $item;
			}

			return $lines;
		}

		/**
		 * Prepare shipping for cart
		 *
		 * @param $order
		 *
		 * @return array
		 */
		private function _prepare_shipping_for_cart( $order ) {
			$shipping    = $order->get_items( 'shipping' );
			$hasShipping = (bool) count( $shipping );

			$lines = array();
			if ( $hasShipping ) {

				$shipping_key = key( $shipping );

				$item             = array();
				$item['product']  = $shipping[ $shipping_key ]['name'];
				$item['quantity'] = (int) 1;

				$amount = $order->get_total_shipping() + $order->get_shipping_tax();
				if ( $amount <= 0 ) {
					return $lines;
				}
				$item['price'] = $this->_prepare_amount( $amount );

				// 0.18 * 100
				$tax          = $order->get_shipping_tax() / $order->get_total_shipping() * 100;
				$shipping_tax = (int) $tax;

				if ( ! empty( $tax ) ) {

					$tax_rate = $this->_get_tax_rate( $shipping_tax );
					if ( $tax_rate != null ) {
						$tax_mode        = array(
							'type' => 'InvoiceLineTaxVAT',
							'rate' => $tax_rate,
						);
						$item['taxMode'] = $tax_mode;
					}

				}
				$lines[] = $item;
			}

			return $lines;
		}

		/**
		 * Get tax rate
		 *
		 * @param $rate
		 *
		 * @return null|string
		 */
		private function _get_tax_rate( $rate ) {
			switch ( $rate ) {
				// VAT check at the rate 0%;
				case 0:
					return '0%';
					break;
				// VAT check at the rate 10%;
				case 10:
					return '10%';
					break;
				// VAT check at the rate 18%;
				case 18:
					return '18%';
					break;
				default: # — without VAT;
					return null;
					break;
			}
		}

		private function _prepare_api_url( $path = '', $query_params = [] ) {
			$url = rtrim( static::API_URL, '/' ) . '/' . $path;
			if ( ! empty( $query_params ) ) {
				$url .= '?' . http_build_query( $query_params );
			}

			return $url;
		}

		private function _prepare_amount( $amount ) {
			return $amount * 100;
		}

		private function _prepare_metadata( $order ) {
			global $wp_version;

			return [
				'cms'            => 'wordpress',
				'module'         => 'wp-woo-commerce',
				'plugin'         => 'rbkmoney_payment',
				'plugin_version' => static::PLUGIN_VERSION,
				'wordpress'      => $wp_version,
				'woo_commerce'   => $order->version,
				'order_id'       => $order->id,
			];
		}

		private function _prepare_due_date() {
			date_default_timezone_set( 'UTC' );
			$order_lifetime = $this->get_option( 'order_lifetime' );
			if ( empty( $order_lifetime ) ) {
				$order_lifetime = static::CREATE_INVOICE_DUE_DATE_DEFAULT_VALUE;
			}

			return date( static::CREATE_INVOICE_TEMPLATE_DUE_DATE, strtotime( '+' . $order_lifetime . 'hour' ) );
		}

		private function _get_headers() {
			$private_key = $this->get_option( 'private_key' );

			$headers   = [];
			$headers[] = 'X-Request-ID: ' . uniqid();
			$headers[] = 'Authorization: Bearer ' . trim( $private_key );
			$headers[] = 'Content-type: application/json; charset=utf-8';
			$headers[] = 'Accept: application/json';

			return $headers;
		}

		public function url_safe_b64decode( $string ) {
			return base64_decode( strtr( $string, '-_,', '+/=' ) );
		}

		function get_signature_from_header( $contentSignature ) {
			$signature = preg_replace( static::SIGNATURE_PATTERN, '', $contentSignature );

			if ( empty( $signature ) ) {
				throw new Exception( __( 'Сигнатура отсутствует', $this->id ) );
			}

			return $signature;
		}

		public function verification_signature( $data, $signature, $public_key ) {
			if ( empty( $data ) || empty( $signature ) || empty( $public_key ) ) {
				return false;
			}
			$public_key_id = openssl_get_publickey( $public_key );
			if ( empty( $public_key_id ) ) {
				return false;
			}
			$verify = openssl_verify( $data, $signature, $public_key_id, OPENSSL_ALGO_SHA256 );

			return ( $verify == 1 );
		}

		public static function log( $message, $level = 'info' ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = new WC_Logger();
				}
				self::$log->log( $level, $message, array( 'source' => static::GATEWAY_NAME ) );
			}
		}

	}

	/**
	 * Add Gateway class to all payment gateway methods
	 */
	function add_rbkmoney_gateway( $methods ) {
		$methods[] = 'WC_Gateway_RBKmoney';

		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'add_rbkmoney_gateway' );

	/**
	 * We are forced to define this outside of the Payment Gateway class because the Gateway is not
	 * instantiated during AJAX calls.
	 */
	function ajax_rbk_payment_status() {

		$order_id = filter_input( INPUT_POST, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		if ( empty( $order_id ) ) {
			error_log('ajax_rbk_payment_status() called without order_id');
			return;
		}

		$order = wc_get_order( $order_id );

		wp_send_json( array( 'status' => $order->get_status() ) );
	}
	add_action( 'wp_ajax_rbk_payment_status', 'ajax_rbk_payment_status' );
	add_action( 'wp_ajax_nopriv_rbk_payment_status', 'ajax_rbk_payment_status' );
}
