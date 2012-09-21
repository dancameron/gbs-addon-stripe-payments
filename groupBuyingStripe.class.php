<?php

class Group_Buying_Stripe extends Group_Buying_Credit_Card_Processors {

	const API_ID_OPTION = 'gb_stripe_username';
	const PAYMENT_METHOD = 'Credit (Stripe Direct Payments)';
	protected static $instance;
	private $api_id = '';

	protected static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {

		parent::__construct();
		$this->api_id = get_option( self::API_ID_OPTION, '' );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Stripe' ) );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$stripe = $this->setup_stripe( $checkout, $purchase );

		if ( self::DEBUG ) error_log( '----------Response----------' . print_r( $stripe, TRUE ) );
		
		if ( FALSE === $stripe ) {
			return FALSE;
		}

		/*
		 * Purchase since payment was successful above.
		 */
		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $purchase->get_total( $this->get_payment_method() ), // TODO CHANGE to NVP_DATA Match
				'data' => array(
					'api_response' => $stripe,
					'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		return $payment;
	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}


	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $response, self::MESSAGE_STATUS_ERROR );
		} else {
			error_log( $response );
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to Authorize as an Authorization request
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function setup_stripe( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$user = get_userdata( $purchase->get_user() );

		require_once 'lib/Stripe.php';
		Stripe::setApiKey( $this->api_id );
		try {
			$charge = Stripe_Charge::create(array(
				"amount" => self::convert_money_to_cents( sprintf( '%0.2f', $purchase->get_total( $this->get_payment_method() ) ) ),
				"currency" => "usd",
				"card" => array(
						'number' => $this->cc_cache['cc_number'],
						'exp_month' => $this->cc_cache['cc_expiration_month'],
						'exp_year' => substr( $this->cc_cache['cc_expiration_year'], -2 ),
						'cvc' => $this->cc_cache['cc_cvv'],
						'name' => $checkout->cache['billing']['first_name'] . ' ' . $checkout->cache['billing']['last_name'],
					),
				"description" => $purchase->get_id())
			);
			return $charge;
		} catch (Exception $e) {
			self::set_error_messages( $e->getMessage() );
			return FALSE;
		}
	}

	private function convert_money_to_cents( $value ) {
		// strip out commas
		$value = preg_replace( "/\,/i", "", $value );
		// strip out all but numbers, dash, and dot
		$value = preg_replace( "/([^0-9\.\-])/i", "", $value );
		// make sure we are dealing with a proper number now, no +.4393 or 3...304 or 76.5895,94
		if ( !is_numeric( $value ) ) {
			return 0.00;
		}
		// convert to a float explicitly
		$value = (float)$value;
		return round( $value, 2 )*100;
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section( $section, self::__( 'stripe' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_ID_OPTION );
		add_settings_field( self::API_ID_OPTION, self::__( 'Token' ), array( $this, 'display_api_id_field' ), $page, $section );
	}

	public function display_api_id_field() {
		echo '<input type="text" name="'.self::API_ID_OPTION.'" value="'.$this->api_id.'" size="80" />';
		echo '<p class="description">Your Live or Test Token</p>';
	}

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}
}
Group_Buying_Stripe::register();


	
