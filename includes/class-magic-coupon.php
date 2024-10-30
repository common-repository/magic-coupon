<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Magic_Coupon class
 */
class Magic_Coupon {

	/* Variables */
	public $url_parameter                    = 'mcoupon';
	public $default_cookie_duration          = 30; //in minutes
	public $html_message_action_hook         = 'woocommerce_single_product_summary';
	public $html_message_action_priority     = 15;
	public $woocommerce_subscriptions_active = false;

	/* Current coupon */
	public $coupon   = false;
	public $validity = 0;
	
	/* Constructor */
	public function __construct() {
		$this->init_hooks();
	}
	
	/* Init hooks */
	public function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_vars' ) );
		add_action( 'init', array( $this, 'check_mcoupon' ), 11 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'add_to_cart' ), PHP_INT_MAX, 6 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'after_cart_item_quantity_update' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'woocommerce_coupon_data_tabs' ) );
		add_action( 'woocommerce_coupon_data_panels', array( $this, 'woocommerce_coupon_data_panels' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'woocommerce_coupon_options_save' ), 10, 2 );
		add_action( 'wp', array( $this, 'init_html_message_action' ) );
	}

	/* Load textdomain */
	public function load_textdomain() {
		load_plugin_textdomain( 'magic-coupon' );
	}

	/* Init vars */
	public function init_vars() {
		//If someone wants to change the GET and COOKIE name
		$this->url_parameter = apply_filters( 'magic_coupon_url_parameter', $this->url_parameter );
		//Or the HTML position and priority
		$this->html_message_action_hook = apply_filters( 'magic_coupon_html_message_action_hook', $this->html_message_action_hook );
		$this->html_message_action_priority = apply_filters( 'magic_coupon_html_message_action_priority', $this->html_message_action_priority );
		//WC Subscriptions
		$this->woocommerce_subscriptions_active = class_exists( 'WC_Subscriptions' );
	}

	/* Check URL for coupon */
	public function check_mcoupon() {
		//Check URL
		if ( isset( $_GET[ $this->url_parameter ] ) && trim( $_GET[ $this->url_parameter ] ) != '' ) {
			$mcoupon = sanitize_text_field( trim( $_GET[ $this->url_parameter ] ) );
			$this->process_mcoupon( $mcoupon, true );
		} else {
			//Check cookie
			if ( isset( $_COOKIE[ $this->url_parameter ] ) ) {
				$this->process_mcoupon( sanitize_text_field( $_COOKIE[ $this->url_parameter ] ) );
			}
		}
	}
	public function process_mcoupon( $mcoupon, $from_get = false ) {
		if ( $coupon_id = wc_get_coupon_id_by_code( $mcoupon ) ) {
			$coupon = new WC_Coupon( $mcoupon );
			if ( $this->coupon_is_valid( $coupon ) ) {
				// Set coupon
				$this->coupon = $coupon;
				// Set cookie
				if ( $from_get ) {
					//$this->validity will be set inside set_cookie
					$this->set_cookie( $mcoupon );
				} else {
					//On cookie, set validity
					$this->validity = isset( $_COOKIE[ $this->url_parameter.'_validity' ] ) ? $_COOKIE[ $this->url_parameter.'_validity' ] : 0;
				}
				unset( $coupon );
				// Set actions
				$this->add_get_price_filter();
				$this->add_on_sale_filter();
				// Disable server side cache on some plugins
				$this->set_nocache();
			} else {
				$this->unset_cookie();
			}
		}
	}

	/* Add / remove filters */
	public function add_get_price_filter() {
		add_filter( 'woocommerce_product_get_price', array( $this, 'manipulate_get_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'manipulate_get_price' ), 10, 2 );
		//add_filter( 'woocommerce_get_variation_price', array( $this, 'manipulate_get_variation_price' ), 10, 4 ); //https://woocommerce.wordpress.com/2015/09/14/caching-and-dynamic-pricing-upcoming-changes-to-the-get_variation_prices-method/
		add_filter( 'woocommerce_variation_prices', array( $this, 'variation_prices' ), 10, 3 );
		//WooCommerce Tiered Price Table - https://wordpress.org/plugins/tier-pricing-table/
		add_filter( 'tier_pricing_table/price/product_price_rules', array( $this, 'tier_pricing_table_price_product_price_rules' ), 10, 3 );
	}
	public function add_on_sale_filter() {
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'manipulate_is_on_sale' ), 10, 2 );
	}
	public function remove_on_sale_filter() {
		remove_filter( 'woocommerce_product_is_on_sale', array( $this, 'manipulate_is_on_sale' ), 10, 2 );
	}

	/* Set constants to prevent caching by some plugins */
	public static function set_nocache() {
		wc_maybe_define_constant( 'DONOTCACHEPAGE', true );
		wc_maybe_define_constant( 'DONOTCACHEOBJECT', true );
		wc_maybe_define_constant( 'DONOTCACHEDB', true );
		nocache_headers();
	}

	/* Valid coupon - generic */
	public function coupon_is_valid( $coupon ) {
		//Checks based on class-wc-discounts.php
		if ( $coupon ) {
			//Exists
			if ( ! $coupon->get_id() && ! $coupon->get_virtual() )
				return false;
			//Enabled for us?
			if ( $coupon->get_meta( 'magic_coupon_enable' ) != 'yes' )
				return false;
			//Coupon amount
			if ( $coupon->get_amount() <= 0 )
				return false;
			//Expired?
			if ( $coupon->get_date_expires() && current_time( 'timestamp', true ) > $coupon->get_date_expires()->getTimestamp() )
				return false;
			//Usage limit
			if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() )
				return false;
			//Usage limit per user - maybe doesn't make sense?
			$user_id = get_current_user_id();
			if ( $user_id && $coupon->get_usage_limit_per_user() > 0 && $coupon->get_data_store() ) {
				$date_store  = $coupon->get_data_store();
				$usage_count = $date_store->get_usage_by_user_id( $coupon, $user_id );
				if ( $usage_count >= $coupon->get_usage_limit_per_user() )
					return false;
			}
			//Discount for product? //We can accept all types of coupons I gues...
			//if ( ! in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_product' ) ) )
			//	return false;
			//Minimum amount and maximum amount doesn't make a lot of sense here because the product is still not on the cart
			//OK then
			return apply_filters( 'magic_coupon_coupon_is_valid', true, $coupon );
		}
		return false;
	}

	/* Valid coupon for product? */
	public function coupon_is_valid_for_product( $coupon, $product_id ) {
		if ( $coupon ) {

			if ( ! $this->coupon_is_valid( $coupon ) )
				return false;

			$product      = wc_get_product( $product_id );
			$variation    = null;
			$variation_id = null;
			//Variation?
			if ( $product_parent_id = $product->get_parent_id() ) {
				$variation    = $product;
				$product      = wc_get_product( $product_parent_id );
				$product_id   = $product->get_id();
				$variation_id = $variation->get_id();
			}

			if (
				$this->woocommerce_subscriptions_active
				&&
				in_array(
					$coupon->get_discount_type(),
					array(
						'recurring_fee',
						'recurring_percent',
						//'sign_up_fee',
						//'sign_up_fee_percent',
						//'renewal_fee',
						//'renewal_percent',
						//'renewal_cart',
						//'initial_cart'
					)//
				)
			) {
				// Single Subscription - no support for Variable Subscriptions yet
				if ( ! is_a( $product, 'WC_Product_Subscription' ) ) {
					return false;
				}
			}
	
			//All checks based on class-wc-discounts.php

			//Categories - Check product belongs to category
			$product_cats = null;
			if ( count( $coupon->get_product_categories() ) ) {
				$product_cats = wc_get_product_cat_ids( $product_id );
				if ( ! count( array_intersect( $product_cats, $coupon->get_product_categories() ) ) > 0 ) {
					return false;
				}
			}
			//Excluded Categories - Check product does not belongs to category
			if ( count( $coupon->get_excluded_product_categories() ) ) {
				if ( ! $product_cats ) $product_cats = wc_get_product_cat_ids( $product_id );
				if ( count( array_intersect( $product_cats, $coupon->get_excluded_product_categories() ) ) > 0 ) {
					return false;
				}
			}
	
			//Variation?
			if ( $variation ) {
	
				//It's a variation

				//On sale? - Variation on sale
				if ( $coupon->get_exclude_sale_items() ) {
					$this->remove_on_sale_filter();
					if ( $variation->is_on_sale() ) {
						$this->add_on_sale_filter();
						return false;
					} else {
						$this->add_on_sale_filter();
					}
				}
				//Product - Check variation is allowed
				if ( count( $coupon->get_product_ids() ) > 0 ) {
					if ( ! in_array( intval( $variation_id ), $coupon->get_product_ids() ) ) {
						//Check product
						if ( ! in_array( intval( $product_id ), $coupon->get_product_ids() ) ) {
							return false;
						}
					}
				}
				//Excluded product - Check if variation is excluded
				if ( count( $coupon->get_excluded_product_ids() ) > 0 ) {
					if ( in_array( intval( $variation_id ), $coupon->get_excluded_product_ids() ) ) {
						return false;
					} else {
						if ( in_array( intval( $product_id ), $coupon->get_excluded_product_ids() ) ) {
							return false;
						}
					}
				}
	
			} else {

				//It's a single product

				//On sale?
				if ( $coupon->get_exclude_sale_items() ) {
					$this->remove_on_sale_filter();
					if ( $product->is_on_sale() ) {
						$this->add_on_sale_filter();
						return false;
					} else {
						$this->add_on_sale_filter();
					}
				}
				//Product - Check product is allowed
				if ( count( $coupon->get_product_ids() ) > 0 ) {
					if ( ! in_array( intval( $product_id ), $coupon->get_product_ids() ) ) {
						return false;
					}
				}
				//Excluded product - Check if product is excluded
				if ( count( $coupon->get_excluded_product_ids() ) > 0 ) {
					if ( in_array( intval( $product_id ), $coupon->get_excluded_product_ids() ) ) {
						return false;
					}
				}
	
			}
			
			//OK then
			return apply_filters( 'magic_coupon_coupon_is_valid_for_product', true, $coupon, $product_id );

		}

		return false;
	}

	/* Valid location? */
	private function is_valid_location() {
		if (
			//No discount on cart - to avoid duplication
			is_cart()
			||
			//No discount on checkout - to avoid duplication
			is_checkout()
			||
			//No discount on WP Admin
			( is_admin() && ! wp_doing_ajax()  )
			//Anywhere else?
		) {
			return false;
		}
		return true;
	}

	/* Calculate discounted price */
	private function calculate_discounted_price( $base_price, $product, $variation_id = null ) {
		if ( is_numeric( $base_price ) && floatval( $base_price ) > 0 ) {
			$coupon_amount = $this->coupon->get_amount();
			$discount = null;
			switch( $this->coupon->get_discount_type() ) {
				case 'percent':
				case 'recurring_percent':
					$discount = $base_price * ( $coupon_amount / 100 );
					break;
				case 'fixed_product':
				case 'recurring_fee':
					$discount = $coupon_amount;
					break;
				//“Percentage Coupon per Product for WooCommerce” compatibility
				case 'percent_per_product':
					if ( function_exists( 'Woo_Product_Percentage_Coupon' ) ) {
						$discount = Woo_Product_Percentage_Coupon()->get_discount_amount( $base_price, $this->coupon, $product, $variation_id ? wc_get_product( $variation_id ) : null );
					}
					break;
					$discount = $coupon_amount;
					break;
			}
			if ( $discount ) $base_price = $discount < $base_price ? $base_price - $discount : 0;
		}
		return $base_price;
	}

	/* Manipulate price */
	public function manipulate_get_price( $base_price, $_product ) {
		if ( $this->is_valid_location() ) {
			if ( $this->coupon_is_valid_for_product( $this->coupon, $_product->get_id() ) ) {
				$base_price = $this->calculate_discounted_price( $base_price, $_product );
			}
		}
		return $base_price;
	}

	/* Manipulate variation prices - after cache/transient */
	public function variation_prices( $prices, $product, $for_display ) {
		if ( $this->is_valid_location() ) {
			if ( is_array( $prices ) && isset( $prices['price'] ) && is_array( $prices['price'] ) ) {
				foreach ( $prices['price'] as $variation_id => $price ) {
					if ( $this->coupon_is_valid_for_product( $this->coupon, $variation_id ) ) {
						$prices['price'][$variation_id] = $this->calculate_discounted_price( floatval( $price ), $product, $variation_id );
					}
				}
			}
		}
		return $prices;
	}

	/* Manipulate on sale? */
	public function manipulate_is_on_sale( $is_on_sale, $product ) {
		if ( $this->is_valid_location() ) {
			if ( $this->coupon_is_valid_for_product( $this->coupon, $product->get_id() ) ) {
				$is_on_sale = true;
			}
		}
		//Apply filter specific to the product and a global one with the proper arguments
		return apply_filters( 'magic_coupon_product_'.$product->get_id().'_is_on_sale', apply_filters( 'magic_coupon_product_is_on_sale', $is_on_sale, $product, $this->coupon ) );
	}

	/* Manipulate WooCommerce Tiered Price Table */
	public function tier_pricing_table_price_product_price_rules( $rules, $product_id, $type ) {
		if ( $this->is_valid_location() ) {
			if ( $this->coupon_is_valid_for_product( $this->coupon, $product_id ) ) {
				foreach ( $rules as $qty => $price ) {
					$rules[ $qty ] = $this->calculate_discounted_price( $price, wc_get_product( $product_id ) );
				}
			}
		}
		return $rules;
	}

	/* Set cookie */
	public function set_cookie( $mcoupon ) {
		$duration = ( intval( $this->coupon->get_meta( 'magic_coupon_cookie_minutes' ) ) > 0 ? intval( $this->coupon->get_meta( 'magic_coupon_cookie_minutes' ) ) : $this->default_cookie_duration ) * 60;
		setcookie( $this->url_parameter, $mcoupon, time() + $duration, COOKIEPATH, COOKIE_DOMAIN );
		setcookie( $this->url_parameter.'_validity', time() + $duration, time() + $duration, COOKIEPATH, COOKIE_DOMAIN );
		//On get, set validity
		$this->validity = time() + $duration;
	}

	/* Unset cookie */
	public function unset_cookie() {
		setcookie( $this->url_parameter, '', time() - 1, COOKIEPATH, COOKIE_DOMAIN );
		setcookie( $this->url_parameter.'_validity', '', time() - 1, COOKIEPATH, COOKIE_DOMAIN );
	}

	/* Add to cart */
	public function add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		//Is checking for $variation_id necessary?
		if ( $this->coupon ) {
			if ( $this->coupon_is_valid_for_product( $this->coupon, $product_id ) ) {
				if( ! in_array( $this->coupon->get_code(), WC()->cart->get_applied_coupons() ) ) {
					WC()->cart->add_discount( $this->coupon->get_code() );
				}
			}
		}
		return $cart_item_key;
	}

	/* Update cart quantity */
	public function after_cart_item_quantity_update( $cart_item_key, $quantity, $old_quantity ) {
		if ( $this->coupon ) {
			if ( $quantity > $old_quantity ) {
				$item = WC()->cart->get_cart_item( $cart_item_key );
				$product_id = isset( $item['variation_id'] ) && intval( $item['variation_id'] ) > 0 ? $item['variation_id'] : $item['product_id'];
				if ( $this->coupon_is_valid_for_product( $this->coupon, $product_id ) ) {
					if( ! in_array( $this->coupon->get_code(), WC()->cart->get_applied_coupons() ) ) {
						WC()->cart->add_discount( $this->coupon->get_code() );
					}
				}
			}
		}
	}

	/* HTML message */
	public function init_html_message_action() {
		if ( ( is_product() || apply_filters( 'magic_coupon_show_html_message_outside_product_page', false ) ) && $this->coupon ) {
			global $post;
			if ( $this->coupon_is_valid_for_product( $this->coupon, $post->ID ) && $this->coupon->get_meta( 'magic_coupon_html_message' ) != '' ) {
				add_action( $this->html_message_action_hook, array( $this, 'html_message' ), $this->html_message_action_priority );
				add_shortcode( 'magic_coupon_html_message', array( $this, 'html_message_shortcode' ) );
			} else {
				add_shortcode( 'magic_coupon_html_message', function() {
					return '';
				} );
			}
		} else {
			add_shortcode( 'magic_coupon_html_message', function() {
				return '';
			} );
		}
	}
	public function html_message() {
		if ( is_product() ) global $product;
		//Hours or minutes
		$hours_minutes = '';
		$hours = $this->validity > 0 ? ( $this->validity - time() ) / 60 / 60 : '';
		if ( ! empty( $hours ) ) {
			if ( $hours > 1 ) {
				//hours
				$hours_minutes = sprintf(
					__( '%d hours', 'magic-coupon' ),
					round( $hours, 0 )
				);
			} else {
				//minutes
				$minutes = $hours * 60;
				$hours_minutes = sprintf(
					__( '%d minutes', 'magic-coupon' ),
					round( $minutes, 0 )
				);
			}
		}
		//Tags must use internal variables so that we can set them from both cookie and get
		$replace_tags = apply_filters( 'magic_coupon_html_message_replace_tags', array(
			'product_id'                    => is_product() ? $product->get_id() : '',
			'coupon'                        => $this->coupon->get_code(),
			'cookie_expire_timestamp'       => $this->validity > 0 ? $this->validity : '',
			'cookie_validity_minutes'       => $this->validity > 0 ? round( ( $this->validity - time() ) / 60 ) : '',
			'cookie_validity_hours_minutes' => $hours_minutes
		), $this->coupon, $this->validity );
		$message = trim( $this->coupon->get_meta( 'magic_coupon_html_message' ) );
		//We'll replace two times because of tags that can be used by other external tags, like shortcodes
		for ( $i = 1; $i <= 2 ; $i++ ) {
			foreach ( $replace_tags as $tag => $value ) {
				$message = str_replace( '{'.$tag.'}', $value, $message );
			}
		}
		echo apply_filters( 'the_content',  $message );
	}
	public function html_message_shortcode() {
		ob_start();
		$this->html_message();
		return ob_get_clean();
	}

	/* Admin - Add coupon options */
	public function woocommerce_coupon_data_tabs( $coupon_data_tabs ) {
		$coupon_data_tabs['magic_coupon'] = array(
			'label'  => __( 'Magic coupon', 'magic-coupon' ),
			'target' => 'magic_coupon_coupon_data',
			'class'  => 'magic_coupon_coupon_data',
		);
		return $coupon_data_tabs;
	}
	public function woocommerce_coupon_data_panels( $coupon_id, $coupon ) {
		?>
		<div id="magic_coupon_coupon_data" class="panel woocommerce_options_panel">
			<?php
				//Enable?
				$copy_button = '
				<br/>
				<button class="button button-small magic_coupon_show_hide" id="magic_coupon_copy">'.__( 'Copy shop URL with coupon', 'magic-coupon' ).'</button>
				<span id="magic_coupon_copy_text"></span>
				<span id="magic_coupon_copy_success">'.__( 'URL copied to clipboard', 'magic-coupon' ).'</span>';
				woocommerce_wp_checkbox(
					array(
						'id'          => 'magic_coupon_enable',
						'label'       => __( 'Enable', 'magic-coupon' ),
						'description' => sprintf(
											__( 'Check this box to be able to add this coupon via URL (with parameter %s=couponcode) and show product prices reflecting this coupon discounts', 'magic-coupon' ),
											$this->url_parameter
										).$copy_button,
						'value'       => wc_bool_to_string( $coupon->get_meta( 'magic_coupon_enable', true, 'edit' ) ),
					)
				);
				//Minutes in cookie
				$magic_coupon_cookie_minutes = intval( $coupon->get_meta( 'magic_coupon_cookie_minutes', true, 'edit' ) );
				woocommerce_wp_text_input(
					array(
						'id'                => 'magic_coupon_cookie_minutes',
						'label'             => __( 'Cookie minutes', 'magic-coupon' ),
						'placeholder'       => $this->default_cookie_duration,
						'description'       => sprintf( __( 'How many minutes to keep the coupon in a cookie to show product prices reflecting this coupon discounts and automatically apply it to the cart (default: %d minutes)', 'magic-coupon' ), $this->default_cookie_duration ),
						'type'              => 'number',
						'desc_tip'          => true,
						'class'             => 'short',
						'wrapper_class'     => 'magic_coupon_show_hide',
						'custom_attributes' => array(
							'step' => 1,
							'min'  => 1,
						),
						'value'             => $magic_coupon_cookie_minutes > 0 ? $magic_coupon_cookie_minutes : '',
					)
				);
				//HTML message on the product page
				woocommerce_wp_textarea_input (
					array(
						'id'                => 'magic_coupon_html_message',
						'label'             => __( 'HTML message on the product page', 'magic-coupon' ),
						'description'       => __( 'Optional HTML message to show on the product page, below the product price (the action hook and priority can be overridden with filters, check the FAQs)', 'magic-coupon' ),
						'value'             => $coupon->get_meta( 'magic_coupon_html_message', true, 'edit' ),
						'desc_tip'          => true,
						'wrapper_class'     => 'magic_coupon_show_hide',
					)
				)
			?>
			<script type="text/javascript">
			jQuery( function( $ ) {
				$( document ).ready(function() {
					//Hide copy URL spans
					$( '#magic_coupon_copy_text' ).hide();
					$( '#magic_coupon_copy_success' ).hide();
					//Show / hide
					function magic_coupon_toggle_fields() {
						if ( $( '#magic_coupon_enable' ).is( ':checked' ) ) {
							$( '.magic_coupon_show_hide' ).show();
						} else {
							$( '.magic_coupon_show_hide' ).hide();
						}
					}
					magic_coupon_toggle_fields();
					$( '#magic_coupon_enable' ).change( function() {
						magic_coupon_toggle_fields();
					} );
					//Update coupon URL
					function magic_coupon_update_url() {
						var url = '<?php echo add_query_arg(
							array( $this->url_parameter => '%coupon%' ),
							get_permalink( wc_get_page_id( 'shop' ) )
						); ?>';
						url = url.replace( '%coupon%', $.trim( $( 'input[name=post_title]#title' ).val() ) );
						$( '#magic_coupon_copy_text' ).html( url );
					}
					magic_coupon_update_url();
					//Copy
					$( '#magic_coupon_copy' ).click( function( ev ) {
						ev.preventDefault();
						magic_coupon_update_url();
						if ( magic_coupon_copyToClipboard( document.getElementById( 'magic_coupon_copy_text' ) ) ) {
							$( '#magic_coupon_copy_success' ).fadeIn();
							setTimeout( function() {
								$( '#magic_coupon_copy_success' ).fadeOut();
							}, 3000 );
						} else {
							
						}
					} );
					function magic_coupon_copyToClipboard( elem ) {
						  // create hidden text element, if it doesn't already exist
					    var targetId = "_hiddenCopyText_";
					    var isInput = elem.tagName === "INPUT" || elem.tagName === "TEXTAREA";
					    var origSelectionStart, origSelectionEnd;
					    if (isInput) {
					        // can just use the original source element for the selection and copy
					        target = elem;
					        origSelectionStart = elem.selectionStart;
					        origSelectionEnd = elem.selectionEnd;
					    } else {
					        // must use a temporary form element for the selection and copy
					        target = document.getElementById(targetId);
					        if (!target) {
					            var target = document.createElement("textarea");
					            target.style.position = "absolute";
					            target.style.left = "-9999px";
					            target.style.top = "0";
					            target.id = targetId;
					            document.body.appendChild(target);
					        }
					        target.textContent = elem.textContent;
					    }
					    // select the content
					    var currentFocus = document.activeElement;
					    target.focus();
					    target.setSelectionRange(0, target.value.length);
					    
					    // copy the selection
					    var succeed;
					    try {
					    	  succeed = document.execCommand("copy");
					    } catch(e) {
					        succeed = false;
					    }
					    // restore original focus
					    if (currentFocus && typeof currentFocus.focus === "function") {
					        currentFocus.focus();
					    }
					    
					    if (isInput) {
					        // restore prior selection
					        elem.setSelectionRange(origSelectionStart, origSelectionEnd);
					    } else {
					        // clear temporary content
					        target.textContent = "";
					    }
					    console.log( succeed );
					    return succeed;
					}
				} );
			} );
			</script>
		</div>
		<?php
	}
	public function woocommerce_coupon_options_save( $post_id, $post ) {
		$coupon = new WC_Coupon( $post_id );
		$coupon->update_meta_data( 'magic_coupon_enable', isset( $_POST['magic_coupon_enable'] ) ? 'yes' : 'no' );
		$coupon->update_meta_data( 'magic_coupon_cookie_minutes', intval( $_POST['magic_coupon_cookie_minutes'] ) );
		$coupon->update_meta_data( 'magic_coupon_html_message', stripslashes_deep( trim( $_POST['magic_coupon_html_message'] ) ) );
		$coupon->save();
	}

}

/* If you’re reading this you must know what you’re doing ;-) Greetings from sunny Portugal! */