<?php
/**
 * Plugin Name: Codisto Channel Cloud
 * Plugin URI: http://wordpress.org/plugins/codistoconnect/
 * Description: Sell multichannel on Google, Amazon, eBay & Walmart direct from WooCommerce. Create listings & sync products, inventory & orders directly from WooCommerce
 * Author: Codisto
 * Author URI: https://codisto.com/
 * Version: 1.3.65
 * Text Domain: codisto-linq
 * Woo: 3545890:ba4772797f6c2c68c5b8e0b1c7f0c4e2
 * WC requires at least: 2.0.0
 * WC tested up to: 6.3.1
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Codisto LINQ by Codisto
 * @version 1.3.65
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'CODISTOCONNECT_VERSION', '1.3.65' );
define( 'CODISTOCONNECT_RESELLERKEY', '' );

if ( ! class_exists( 'CodistoConnect' ) ) :

final class CodistoConnect {

	private $ping = null;

	protected static $_instance = null;

	/**
	* method callback for query_vars filter
	*
	* @param array $vars array appended to with query variables to match
	* @return array passed in $vars argument
	*/
	public function query_vars( $vars ) {

		$vars[] = 'codisto';
		$vars[] = 'codisto-proxy-route';
		$vars[] = 'codisto-sync-route';
		return $vars;
	}

	/**
	* method callback for nocache_headers filter
	*
	* @param array $headers array with current no-cache headers
	* @return array resultant no-cache headers
	*/
	public function nocache_headers( $headers ) {

		if ( isset( $_GET['page'] ) &&
			substr( $_GET['page'], 0, 7 ) === 'codisto' &&
			$_GET['page'] !== 'codisto-templates' ) {
			$headers = array(
				'Cache-Control' => 'private, max-age=0',
				'Expires' => gmdate( 'D, d M Y H:i:s', time() - 300 ) . ' GMT'
			);
		}

		return $headers;
	}

	/**
	* checks incoming request to see if satisfies shared key auth
	*
	* @return bool true for valid request, false for invalid request
	*/
	private function check_hash() {

		if ( ! isset( $_SERVER['HTTP_X_CODISTONONCE'] ) ||
			! isset( $_SERVER['HTTP_X_CODISTOKEY'] ) ) {
			$this->sendHttpHeaders(
				'400 Security Error',
				array(
					'Content-Type' => 'application/json',
					'Cache-Control' => 'no-cache, no-store',
					'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
					'Pragma' => 'no-cache'
				)
			);

			echo $this->json_encode( array( 'ack' => 'error', 'message' => 'Security Error - Missing Headers' ) );
			return false;
		}

		$r = get_option( 'codisto_key' ) . $_SERVER['HTTP_X_CODISTONONCE'];
		$base = hash( 'sha256', $r, true );
		$checkHash = base64_encode( $base );
		if ( ! hash_equals( $_SERVER['HTTP_X_CODISTOKEY'], $checkHash ) ) {
			$this->sendHttpHeaders(
				'400 Security Error',
				array(
					'Content-Type' => 'application/json',
					'Cache-Control' => 'no-cache, no-store',
					'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
					'Pragma' => 'no-cache'
				)
			);

			echo $this->json_encode( array( 'ack' => 'error', 'message' => 'Security Error' ) );
			return false;
		}

		return true;
	}

	/**
	* filter for woocommerce woocommerce_new_order_data
	*
	* @param array $order_data data for new order as presented to filter
	* @return array $order_data as passed in
	*/
	public function order_set_date( $order_data ) {

		// force order date

		return $order_data;
	}

	/**
	* filter for woocommerce order emails
	*
	* @param bool $enabled flag for enabled status
	* @param object $object wc_email object
	* @return bool $enabled as false
	*/

	public function inhibit_order_emails( $enabled, $order ) {

		if($enabled && $order) {

			$orderId = $order->get_id();

			if( get_post_meta( $orderId, '_codisto_orderid' ) ) {

				return false;

			}

		}

		return $enabled;

	}

	/**
	* common http status and header output function
	*
	* @param integer $status the http status to send
	* @param array $headers an array of headers to send
	*/
	private function sendHttpHeaders( $status, $headers ) {

		if ( defined( 'ADVANCEDCACHEPROBLEM' ) &&
			false == strpos( $_SERVER['REQUEST_URI'], 'wp-admin') ) {
			$_SERVER['REQUEST_URI'] = '/wp-admin'.$_SERVER['REQUEST_URI'];
		}

		$statusheader = preg_split('/ /', $status, 2);
		status_header( intval($statusheader[0]), isset($statusheader[1]) ? $statusheader[1] : '' );
		foreach ( $headers as $header => $value ) {
			header( $header.': '.$value );
		}
	}

	/**
	* provides a forward / backward compatible json_encode
	*
	* @param any $arg value to encode
	* @return string json encdoed arg
	*/
	private function json_encode( $arg ) {
		if ( function_exists( 'wp_json_encode') ) {
			return wp_json_encode( $arg );
		} elseif ( function_exists( 'json_encode' ) ) {
			return json_encode( $arg );
		} else {
			throw new Exception( __( 'PHP missing json library - please upgrade php or wordpress', 'codisto-linq' ) );
		}
	}

	/**
	* helper function for retrieving a product from an id that caters to different versions of woocommerce
	*
	* @param integer $id product id to retrieve
	* @return object woocommerce product object
	*/
	private function get_product( $id ) {
		if ( function_exists( 'wc_get_product') ) {
			return wc_get_product( $id );
		} elseif ( function_exists( 'get_product') ) {
			return get_product( $id );
		} else {
			throw new Exception( __( 'WooCommerce wc_get_product function is missing - please reinstall or activate WooCommerce', 'codisto-linq' ) );
		}
	}

	/**
	* recursively scan a directory returning an array of all files contained within
	*
	* @param string $dir path to scan
	* @param string Optional. $prefix is used to prepend a path to each path in the output array
	* @return array array of files within directory passed as input
	*/
	private function files_in_dir( $dir, $prefix = '' ) {
		$dir = rtrim( $dir, '\\/' );
		$result = array();

		try {
			if ( is_dir( $dir ) ) {
				$scan = @scandir( $dir );

				if ( $scan !== false ) {
					foreach ( $scan as $f ) {
						if ( $f !== '.' and $f !== '..' ) {
							if ( is_dir( "$dir/$f" ) ) {
								$result = array_merge( $result, $this->files_in_dir( "$dir/$f", "$f/" ) );
							} else {
								$result[] = $prefix.$f;
							}
						}
					}
				}
			}

		} catch( Exception $e ) {

		}

		return $result;
	}

	/**
	* sync handler
	*
	* the end point that allows synchronisation of catalog, ebay template and order data
	* this function deliberately calls exit after emitting output to avoid the commnucations to the client
	* being fouled by other code that assumes it can harmlessly inject, for example html comments
	*/
	public function sync() {

		global $wp;
		global $wpdb;
		$wpdbsiteprefix = $wpdb->get_blog_prefix(get_current_blog_id());

		error_reporting( E_ERROR | E_PARSE );
		set_time_limit( 0 );

		@ini_set( 'display_errors', '1' );

		@ini_set( 'zlib.output_compression', 'Off' );
		@ini_set( 'output_buffering', 'Off' );
		@ini_set( 'output_handler', '' );

		while( ob_get_level() > 1 ) {
			@ob_end_clean();
		}
		if ( ob_get_level() > 0 ) {
			@ob_clean();
		}

		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			$this->sendHttpHeaders(
				'500 Config Error',
				array(
					'Content-Type' => 'application/json',
					'Cache-Control' => 'no-cache, no-store',
					'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
					'Pragma' => 'no-cache'
				)
			);

			echo $this->json_encode( array( 'ack' => 'failed', 'message' => 'WooCommerce Deactivated' ) );
			exit();
		}

		// simulate admin context for sync of prices so appropriate filters run
		require_once( ABSPATH . 'wp-admin/includes/admin.php' );
		set_current_screen( 'dashboard' );

		$type = $wp->query_vars['codisto-sync-route'];
		if ( strtolower( $_SERVER['REQUEST_METHOD'] ) == 'get' ) {
			if ( $type == 'test' ||
				( $type == 'sync' && preg_match( '/\/sync\/testHash\?/', $_SERVER['REQUEST_URI'] ) )
			) {
				if ( ! $this->check_hash() ) {
					exit();
				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);

				echo $this->json_encode( array( 'ack' => 'ok' ) );
			} elseif ( $type === 'settings' ) {

				if ( ! $this->check_hash() ) {
					exit();
				}

				$logo_url = get_header_image();

				if ( function_exists( 'site_logo' ) ) {
					$logo = site_logo()->logo;
					$logo_id = get_theme_mod( 'custom_logo' );
					$logo_id = $logo_id ? $logo_id : $logo['id'];

					if ( $logo_id ) {
						$logo_url = wp_get_attachment_image_src( $logo_id, 'full' );
						$logo_url = $logo_url[0];
					}
				}

				$currency = get_option( 'woocommerce_currency' );

				$dimension_unit = get_option( 'woocommerce_dimension_unit' );

				$weight_unit = get_option( 'woocommerce_weight_unit' );

				$default_location = explode( ':', get_option( 'woocommerce_default_country' ) );

				$country_code = isset( $default_location[0] ) ? $default_location[0] : '';
				$state_code = isset( $default_location[1] ) ? $default_location[1] : '';

				$shipping_tax_class = get_option( 'woocommerce_shipping_tax_class' );

				$blogdescription = preg_replace( '/[\x0C\x0D]/', ' ', preg_replace( '/[\x00-\x1F\x7F]/', '', get_option( 'blogdescription' ) ) );

				$response = array(
					'ack' => 'ok',
					'store_name' => $blogdescription,
					'logo' => $logo_url,
					'currency' => $currency,
					'dimension_unit' => $dimension_unit,
					'weight_unit' => $weight_unit,
					'country_code' => $country_code,
					'state_code' => $state_code,
					'shipping_tax_class' => $shipping_tax_class,
					'version' => CODISTOCONNECT_VERSION
				);

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);

				echo $this->json_encode( $response );
				exit();

			} elseif ( $type === 'tax' ) {

				if ( ! $this->check_hash() ) {
					exit();
				}

				$tax_enabled = true;
				if ( function_exists( 'wc_tax_enabled' ) ) {
					$tax_enabled = wc_tax_enabled();
				} else {
					$tax_enabled = get_option( 'woocommerce_calc_taxes' ) === 'yes';
				}

				if ( $tax_enabled ) {
					$rates = $wpdb->get_results( "SELECT tax_rate_country AS country, tax_rate_state AS state, tax_rate AS rate, tax_rate_name AS name, tax_rate_class AS class, tax_rate_order AS sequence, tax_rate_priority AS priority FROM `{$wpdbsiteprefix}woocommerce_tax_rates` ORDER BY tax_rate_order" );
				} else {
					$rates = array();
				}

				$response = array( 'ack' => 'ok', 'tax_rates' => $rates );

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);

				echo $this->json_encode( $response );
				exit();

			} elseif ( $type === 'products' ) {

				if ( ! $this->check_hash() ) {
					exit();
				}

				$page = isset( $_GET['page'] ) ? (int)$_GET['page'] : 0;
				$count = isset( $_GET['count'] ) ? (int)$_GET['count'] : 0;

				$product_ids = isset( $_GET['product_ids'] ) ? json_decode( wp_unslash( $_GET['product_ids'] ) ) : null;

				if ( ! is_null( $product_ids ) ) {
					if ( ! is_array( $product_ids ) ) {
						$product_ids = array( $product_ids );
					}

					$product_ids = array_filter( $product_ids, "is_numeric");

					if ( ! isset( $_GET['count'] ) ) {
						$count = count( $product_ids );
					}
				}

				$products = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id AS id ".
						"FROM `{$wpdbsiteprefix}posts` AS P ".
						"WHERE post_type = 'product' ".
						"		AND post_status IN ('publish', 'future', 'pending', 'private') ".
						"	".( is_array( $product_ids ) ? 'AND id IN ('.implode( ',', $product_ids ).')' : '' )."".
						"ORDER BY ID LIMIT %d, %d",
					$page * $count,
					$count
					)
				);

				if ( ! is_array( $product_ids )
					&& $page === 0
				) {
					$total_count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdbsiteprefix}posts` WHERE post_type = 'product' AND post_status IN ('publish', 'future', 'pending', 'private')" );
				}

				$acf_installed = function_exists( 'acf' );

				foreach ( $products as $product ) {

					$wc_product = $this->get_product( $product->id );

					if(!is_object($wc_product)) {
						continue;
					}

					$categoryproduct = $wc_product->get_categories();

					$product->sku = $wc_product->get_sku();
					$product->name = html_entity_decode( apply_filters( 'woocommerce_product_title', $wc_product->post->post_title, $wc_product ), ENT_COMPAT | ENT_HTML401, 'UTF-8' );
					$product->enabled = $wc_product->is_purchasable() && ( $wc_product->managing_stock() || $wc_product->is_in_stock() );
					$product->price = $wc_product->get_price_excluding_tax();
					$product->listprice = floatval( $wc_product->get_regular_price() );
					$product->is_taxable = $wc_product->is_taxable();
					$product->tax_class = $wc_product->get_tax_class();
					$product->stock_control = $wc_product->managing_stock();
					$product->stock_level = $wc_product->get_stock_quantity();
					if ( method_exists( $wc_product, 'get_type' ) ) {
						$product->type = $wc_product->get_type();
					} else {
						$product->type = $wc_product->product_type;
					}
					$product->description = apply_filters( 'the_content', $wc_product->post->post_content );
					$product->short_description = apply_filters( 'the_content', $wc_product->post->post_excerpt );

					if ( method_exists( $wc_product, 'get_width' ) ) {
						$product->width = $wc_product->get_width();
						if ( ! is_numeric( $product->width ) ) {
							unset( $product->width );
						}
						$product->height = $wc_product->get_height();
						if ( ! is_numeric( $product->height ) ) {
							unset( $product->height );
						}
						$product->length = $wc_product->get_length();
						if ( ! is_numeric( $product->length ) ) {
							unset( $product->length );
						}
					} else {
						$product->length = $wc_product->length;
						$product->width = $wc_product->width;
						$product->height = $wc_product->height;
					}

					$product->weight = $wc_product->get_weight();
					if ( ! is_numeric( $product->weight ) ) {
						unset( $product->weight );
					}

					if (
						$product->is_taxable
						&& 'yes' === get_option( 'woocommerce_prices_include_tax' )
					) {
						$tax_rates = WC_Tax::get_shop_base_rate( $product->tax_class );
						$taxes = WC_Tax::calc_tax( $product->listprice , $tax_rates, true );
						$product->listprice = $product->listprice - array_sum( $taxes );
					}

					if ( $product->type == 'variable' ) {
						$product->skus = array();

						foreach ( $wc_product->get_children() as $child_id ) {

							$child_product = $wc_product->get_child( $child_id );

							if(!is_object($child_product)) {
								continue;
							}

							$img = wp_get_attachment_image_src( $child_product->get_image_id(), 'full' );
							$img = $img[0];

							$child_product_data = array(
												'id' => $child_id,
												'sku' => $child_product->get_sku(),
												'enabled' => $wc_product->is_purchasable() && ( $wc_product->managing_stock() || $wc_product->is_in_stock() ),
												'price' => $child_product->get_price_excluding_tax(),
												'listprice' => $child_product->get_regular_price(),
												'is_taxable' => $child_product->is_taxable(),
												'tax_class' => $child_product->get_tax_class(),
												'stock_control' => $child_product->managing_stock(),
												'stock_level' => $child_product->get_stock_quantity(),
												'images' => array( array( 'source' => $img, 'sequence' => 0 ) ),
												'weight' => $child_product->get_weight()
											);

							$attributes = array();

							$termsmap = array();
							$names = array();

							foreach ( $child_product->get_variation_attributes() as $name => $value ) {

								$name = preg_replace( '/(pa_)?attribute_/', '', $name );

								if ( ! isset( $names[$name] ) ) {
									$names[$name] = true;
									$terms = get_terms( array( 'taxonomy' => $name ) );
									if ( $terms ) {
										foreach ( $terms as $term ) {
											$termsmap[$term->slug] = $term->name;
										}
									}
								}

								if ( $value && ( gettype( $value ) == 'string' || gettype( $value ) == 'integer' ) ) {
									if ( array_key_exists( $value, $termsmap ) ) {
										$newvalue = $termsmap[$value];
									} else {
										$newvalue = $value;
									}
								} else {
									$newvalue = '';
								}

								$name = wc_attribute_label( $name, $child_product );

								$attributes[] = array( 'name' => $name, 'value' => $newvalue, 'slug' => $value );

							}

							foreach ( get_post_custom_keys( $child_product->variation_id) as $attribute ) {

								if ( ! ( in_array(
										$attribute,
										array(
											'_sku',
											'_weight', '_length', '_width', '_height', '_thumbnail_id', '_virtual', '_downloadable', '_regular_price',
											'_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_price',
											'_download_limit', '_download_expiry', '_file_paths', '_manage_stock', '_stock_status',
											'_downloadable_files', '_variation_description', '_tax_class', '_tax_status',
											'_stock', '_default_attributes', '_product_attributes', '_file_path', '_backorders'
										)
									)
									|| substr( $attribute, 0, 4 ) === '_wp_'
									|| substr( $attribute, 0, 13 ) === 'attribute_pa_' )
								) {

									$value = get_post_meta( $child_product->variation_id, $attribute, false );
									if ( is_array( $value ) ) {
										if ( count( $value ) === 1 ) {
											$value = $value[0];
										} else {
											$value = implode( ',', $value );
										}
									}

									$attributes[] = array( 'name' => $attribute, 'value' => $value, 'custom' => true );
								}
							}

							$child_product_data['attributes'] = $attributes;

							$product->skus[] = $child_product_data;
						}

						$productvariant = array();
						$variationattrs = get_post_meta( $product->id, '_product_attributes', true );
						$attribute_keys  = array_keys( $variationattrs );
						$attribute_total = sizeof( $attribute_keys );

						for ( $i = 0; $i < $attribute_total; $i ++ ) {
							$attribute = $variationattrs[ $attribute_keys[ $i ] ];

							$name = wc_attribute_label( $attribute['name'] );
							if ( $attribute['is_taxonomy'] ) {
								$valmap = array();
								$terms = get_terms( array( 'taxonomy' => $attribute['name'] ) );
								foreach ( $terms as $term ) {
									$valmap[] = $term->name;
								}
								$value = implode( '|', $valmap );

							} else {

								$value = $attribute['value'];
							}
							$sequence = $attribute['position'];

							$productvariant[] = array( 'name' => $name, 'values' => $value, 'sequence' => $sequence );
						}

						$product->variantvalues = $productvariant;

						$attrs = array();

						foreach ( $wc_product->get_variation_attributes() as $name => $value ) {

							$name = preg_replace( '/(pa_)?attribute_/', '', $name );

							if ( ! isset( $names[$name] ) ) {
								$names[$name] = true;
								$terms = get_terms( array( 'taxonomy' => $name ) );
								if ( $terms ) {
									foreach ( $terms as $term ) {
										$termsmap[$term->slug] = $term->name;
									}
								}
							}

							if ( $value && ( gettype( $value ) == 'string' || gettype( $value ) == 'integer' ) ) {
								if ( array_key_exists( $value, $termsmap ) ) {
									$newvalue = $termsmap[$value];
								} else {
									$newvalue = $value;
								}
							} else {
								$newvalue = '';
							}

							$name = wc_attribute_label( $name, $child_product );

							$attrs[] = array( 'name' => $name, 'value' => $newvalue, 'slug' => $value );
						}

						$product->options = $attrs;

					} elseif ( $product->type == 'grouped' ) {
						$product->skus = array();

						foreach ( $wc_product->get_children() as $child_id ) {

							$child_product = $wc_product->get_child( $child_id );

							if(!is_object($child_product)) {
								continue;
							}

							$child_product_data = array(
												'id' => $child_id,
												'price' => $child_product->get_price_excluding_tax(),
												'sku' => $child_product->get_sku(),
												'name' => $child_product->get_title()
											);

							$product->skus[] = $child_product_data;
						}
					}

					$product->categories = array();
					$product_categories = get_the_terms( $product->id, 'product_cat' );

					if ( is_array( $product_categories ) ) {
						$sequence = 0;
						foreach ( $product_categories as $category ) {

							$product->categories[] = array( 'category_id' => $category->term_id, 'sequence' => $sequence );

							$sequence++;
						}
					}

					$product->tags = array();
					$product_tags = get_the_terms( $product->id, 'product_tag' );

					if ( is_array( $product_tags ) ) {
						$sequence = 0;
						foreach ( $product_tags as $tag ) {
							$product->tags[] = array( 'tag' => $tag->name, 'sequence' => $sequence );
							$sequence++;
						}
					}

					$image_sequence = 1;
					$product->images = array();

					$imagesUsed = array();

					$primaryimage_path = wp_get_attachment_image_src( $wc_product->get_image_id(), 'full' );
					$primaryimage_path = $primaryimage_path[0];

					if ( $primaryimage_path ) {
						$product->images[] = array( 'source' => $primaryimage_path, 'sequence' => 0 );

						$imagesUsed[$primaryimage_path] = true;

						foreach ( $wc_product->get_gallery_attachment_ids() as $image_id ) {

							$image_path = wp_get_attachment_image_src( $image_id, 'full' );
							$image_path = $image_path[0];

							if ( ! array_key_exists( $image_path, $imagesUsed ) ) {

								$product->images[] = array( 'source' => $image_path, 'sequence' => $image_sequence );

								$imagesUsed[$image_path] = true;

								$image_sequence++;
							}
						}
					}

					$product->attributes = array();

					$attributesUsed = array();

					foreach ( $wc_product->get_attributes() as $attribute ) {

						if ( $product->type == 'simple' || ! $attribute['is_variation'] ) {
							if ( ! array_key_exists( $attribute['name'], $attributesUsed ) ) {
								$attributesUsed[$attribute['name']] = true;

								$attributeName = wc_attribute_label( $attribute['name'] );

								if ( ! $attribute['is_taxonomy'] ) {
									$product->attributes[] = array( 'name' => $attributeName, 'value' => $attribute['value'] );
								} else {
									$attributeValue = implode( ', ', wc_get_product_terms( $product->id, $attribute['name'], array( 'fields' => 'names' ) ) );

									$product->attributes[] = array( 'name' => $attributeName, 'value' => $attributeValue );
								}
							}
						}
					}

					foreach ( get_post_custom_keys( $product->id ) as $attribute ) {

						if ( ! ( substr( $attribute, 0, 1 ) === '_' ||
							substr( $attribute, 0, 3 ) === 'pa_' ) ) {

							if ( ! array_key_exists( $attribute, $attributesUsed ) ) {
								$attributesUsed[$attribute] = true;

								$value = get_post_meta( $product->id, $attribute, false );
								if ( is_array( $value ) ) {

									if ( count( $value ) === 1 ) {
										$value = $value[0];
									} else {
										$value = implode( ',', $value );
									}
								}
								$product->attributes[] = array( 'name' => $attribute, 'value' => $value );
							}
						} elseif ( $attribute === '_woocommerce_gpf_data' &&
							is_array($value) &&
							isset($value['gtin']) ) {
							$product->attributes[] = array( 'name' => '_woocommerce_gpf_data.gtin', 'value' => $value['gtin'] );
					 	}

					}

					// acf

					if ( $acf_installed ) {

						if ( function_exists( 'get_field_objects' ) ) {

							$fields = get_field_objects( $product->id );
							if ( is_array( $fields ) ) {

								foreach ( $fields as $field ) {

									if ( $field['type'] == 'image' ) {

										$image_path = $field['value']['url'];

										if ( !array_key_exists( $image_path, $imagesUsed ) ) {

											$product->images[] = array( 'source' => $image_path, 'sequence' => $image_sequence );

											$imagesUsed[$image_path] = true;

											$image_sequence++;
										}

									} elseif ( $field['type'] == 'gallery' ) {
										$gallery = $field['value'];

										if ( is_array( $gallery ) ) {

											foreach ( $gallery as $image ) {

												$image_path = $image['url'];

												if ( !array_key_exists( $image_path, $imagesUsed ) ) {

													$product->images[] = array( 'source' => $image_path, 'sequence' => $image_sequence );

													$imagesUsed[$image_path] = true;

													$image_sequence++;
												}
											}
										}
									}

									elseif ( in_array(
											$field['type'],
											array(
												'textarea',
												'wysiwyg',
												'text',
												'number',
												'select',
												'radio',
												'checkbox',
												'true_false'
											)
										)
									) {

										if ( !array_key_exists( $field['label'], $attributesUsed ) ) {

											$attributesUsed[$field['label']] = true;

											$value = $field['value'];
											if ( is_array( $value ) ) {

												if ( count( $value ) === 1) {
													$value = $value[0];
												} else {
													$value = implode( ',', $value );
												}
											}

											$product->attributes[] = array( 'name' => $field['name'], 'value' => $value );
										}
									}

									if ( !$product->description ) {

										if ( in_array( $field['type'], array( 'textarea', 'wysiwyg' ) ) &&
												$field['name'] == 'description' ) {
											$product->description = $field['value'];
										}
									}

								}
							}
						}
					}
				}

				$response = array( 'ack' => 'ok', 'products' => $products );
				if ( isset( $total_count ) ) {
					$response['total_count'] = $total_count;
				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);

				echo $this->json_encode( $response );
				exit();

			} elseif ( $type === 'categories' ) {

				if ( ! $this->check_hash() ) {

					exit();
				}

				$categories = get_categories( array( 'taxonomy' => 'product_cat', 'orderby' => 'term_order', 'hide_empty' => 0 ) );

				$result = array();

				foreach ( $categories as $category ) {

					$result[] = array(
								'category_id' => $category->term_id,
								'name' => $category->name,
								'parent_id' => $category->parent
							);
				}

				$response = array( 'ack' => 'ok', 'categories' => $result, 'total_count' => count( $categories ) );

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);

				echo $this->json_encode( $response );
				exit();

			} elseif ( $type === 'orders' ) {

				if ( ! $this->check_hash() ) {
					exit();
				}

				$page = isset( $_GET['page'] ) ? (int)$_GET['page'] : 0;
				$count = isset( $_GET['count'] ) ? (int)$_GET['count'] : 0;
				$merchantid = isset( $_GET['merchantid'] ) ? (int)$_GET['merchantid'] : 0;

				$orders = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT (".
							"SELECT meta_value FROM `{$wpdbsiteprefix}postmeta` WHERE post_id = P.id AND meta_key = '_codisto_orderid' AND ".
								"(".
									"EXISTS ( SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND meta_value = %d AND post_id = P.id ) ".
									"OR NOT EXISTS ( SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND post_id = P.id ) ".
								")".
							") AS id, ".
						" ID AS post_id, post_status AS status FROM `{$wpdbsiteprefix}posts` AS P".
						" WHERE post_type = 'shop_order'".
						" AND post_date > DATE_SUB( CURRENT_TIMESTAMP(), INTERVAL 90 DAY )".
						" AND ID IN (".
							"SELECT post_id FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_orderid' AND (".
								"EXISTS ( SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND meta_value = %d AND post_id = P.id ) ".
								"OR NOT EXISTS ( SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND post_id = P.id ) ".
							")".
						") ORDER BY ID LIMIT %d, %d",
						$merchantid,
						$merchantid,
						$page * $count,
						$count
					)
				);

				if ( $page == 0 ) {
					$total_count = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM `{$wpdbsiteprefix}posts` AS P WHERE post_type = 'shop_order' AND post_date > DATE_SUB( CURRENT_TIMESTAMP(), INTERVAL 90 DAY ) AND ID IN ( SELECT post_id FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_orderid' AND ( EXISTS ( SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND meta_value = %d AND post_id = P.id ) OR NOT EXISTS (SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND post_id = P.id )))",
							$merchantid
						)
					);
				}

				$order_data = array();

				foreach ( $orders as $order ) {

					$tracking_items = get_post_meta( $order->post_id, '_wc_shipment_tracking_items', true );
					$tracking_item = $tracking_items[0];

					if ( $tracking_items && class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
						$shipmenttracking = WC_Shipment_Tracking_Actions::get_instance();
						$formatted = $shipmenttracking->get_formatted_tracking_item( $order->post_id, $tracking_item );

						if ( $tracking_item['date_shipped'] ) {

							if ( is_numeric( $tracking_item['date_shipped'] ) ) {
								$ship_date = date( 'Y-m-d H:i:s', $tracking_item['date_shipped'] );
							}

							$order->ship_date = $tracking_item['date_shipped'];

						}

						if ( $formatted['formatted_tracking_provider'] ) {

							$order->carrier = $formatted['formatted_tracking_provider'];

						}

						if ( $tracking_item['tracking_number'] ) {

							$order->track_number = $tracking_item['tracking_number'];

						}

					} elseif ($tracking_items && (class_exists('WC_Advanced_Shipment_Tracking_Actions') || class_exists('AST_Pro_Actions'))) {

						if ( $tracking_item['date_shipped'] ) {
							$order->ship_date = date('Y-m-d H:i:s', $tracking_item['date_shipped']);
						}

						if ( $tracking_item['tracking_provider'] ) {
							$order->carrier = $tracking_item['tracking_provider'];
						}

						if ( $tracking_item['tracking_number'] ) {
							$order->track_number = $tracking_item['tracking_number'];
						}

					} else {

						$tracking_object = get_post_meta( $order->post_id, 'wf_wc_shipment_source', true );
						if( $tracking_object
							&& is_array( $tracking_object )
							&& isset( $tracking_object['shipment_id_cs'] ) ) {

							$ship_date = date( 'Y-m-d H:i:s', strtotime( $tracking_object['order_date'] ) );
							if( $ship_date ) {

								$order->ship_date = $ship_date;

							}

							$carrier = $tracking_object['shipping_service'];
							if( $carrier ) {

								$order->carrier = $carrier;

							}

							$tracking_number = $tracking_object['shipment_id_cs'];
							if( $tracking_number ) {

								$order->track_number = $tracking_number;

							}

						}  else {

							$ship_date = get_post_meta( $order->post_id, '_date_shipped', true );
							if ( $ship_date ) {
								if ( is_numeric( $ship_date ) ) {
									$ship_date = date( 'Y-m-d H:i:s', $ship_date );
								}

								$order->ship_date = $ship_date;
							}

							$carrier = get_post_meta( $order->post_id, '_tracking_provider', true);
							if ( $carrier ) {
								if ( $carrier === 'custom' ) {
									$carrier = get_post_meta( $order->post_id, '_custom_tracking_provider', true );
								}

							} else {

								$carrier = get_post_meta( $order->post_id, '_wcst_order_trackname', true);

							}
							if($carrier)
							{
								$order->carrier = $carrier;
							}

							$tracking_number = get_post_meta( $order->post_id, '_tracking_number', true);
							if ( !$tracking_number ) {
								$tracking_number = get_post_meta( $order->post_id, '_wcst_order_trackno', true );
							}
							if($tracking_number)
							{
								$order->track_number = $tracking_number;
							}
						}
					}

					unset( $order->post_id );

					$order_data[] = $order;
				}

				$response = array( 'ack' => 'ok', 'orders' => $order_data );
				if ( isset( $total_count ) ) {
					$response['total_count'] = $total_count;
				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);

				echo $this->json_encode( $response );
				exit();

			} elseif ( $type == 'sync' ) {

				if ( $_SERVER['HTTP_X_ACTION'] === 'TEMPLATE' ) {

					if ( ! $this->check_hash() ) {
						exit();
					}

					$ebayDesignDir = WP_CONTENT_DIR . '/ebay/';

					$merchantid = (int)$_GET['merchantid'];
					if ( ! $merchantid ) {
						$merchantid = 0;
					}

					$templatedb = get_temp_dir() . '/ebay-template-'.$merchantid.'.db';

					if ( isset( $_GET['markreceived'] ) ) {

						$this->sendHttpHeaders(
							'200 OK',
							array(
								'Content-Type' => 'application/json',
								'Cache-Control' => 'no-cache, must-revalidate',
								'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
								'Pragma' => 'no-cache'
							)
						);

						echo $this->json_encode( array( 'ack' => 'ok' ) );
						exit();

					} else {

						$filelist = $this->files_in_dir( $ebayDesignDir );

						$filestozip = array();

						foreach ( $filelist as $key => $name ) {
							try {

								$fileName = $ebayDesignDir.$name;

								if ( ! in_array( $name, array( 'README' ) ) ) {

									array_push($filestozip, $fileName);

								}

							} catch( Exception $e ) {

							}
						}

						if ( sizeof( $filestozip ) == 0 ) {

							$this->sendHttpHeaders(
								'204 No Content',
								array(
									'Cache-Control' => 'no-cache, must-revalidate',
									'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
									'Pragma' => 'no-cache'
								)
							);

						} else {

							require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );

							$tmpfile = wp_tempnam();
							$zipfile = new PclZip( $tmpfile );
							$zipsuccess = $zipfile->create( $filestozip , PCLZIP_OPT_REMOVE_PATH, $ebayDesignDir );
							if ($zipsuccess) {
								$headers = array(
									'Cache-Control' => 'no-cache, must-revalidate',
									'Pragma' => 'no-cache',
									'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
									'X-Codisto-Content-Type' => 'application/zip',
									'Content-Type' => 'application/zip, application/octet-stream',
									'Content-Disposition' => 'attachment; filename=' . basename( $zipfile ),
									'Content-Length' => filesize( $tmpfile )
								);

								$this->sendHttpHeaders( '200 OK', $headers );

								while( ob_get_level() > 0 ) {
									if ( ! @ob_end_clean() )
										break;
								}

								flush();

								readfile( $tmpfile );
							} else {
								$this->sendHttpHeaders(
									'200 OK',
									array(
										'Content-Type' => 'application/json',
										'Cache-Control' => 'no-cache, no-store',
										'X-Codisto-Content-Type' => 'application/json',
										'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
										'Pragma' => 'no-cache'
									)
								);
								echo $this->json_encode( array('error'=>$zipfile->errorInfo(true)) );
							}

						}

						unlink( $tmpfile );

						exit();

					}
				}

			} elseif ( $type == "sites" ) {

				$response = array( 'ack' => 'ok' );

				if( is_multisite() ) {

					$sites = array();

					$sitelist = get_sites();
					foreach( $sitelist as $site ) {

						$sites[] = get_object_vars( $site );

					}

					$response['sites'] = $sites;

				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'X-Codisto-Content-Type' => 'application/json',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( $response );
				exit();

			} elseif ( $type == "siteverification" ) {

				$response = array( 'ack' => 'ok' );

				$siteverification = get_option( 'codisto_site_verification' );

				if( $siteverification ) {

					$response['siteverification'] = $siteverification;

				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'X-Codisto-Content-Type' => 'application/json',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( $response );
				exit();

			} elseif ( $type == "paymentmethods" ) {

				$response = array( 'ack' => 'ok' );

				$gateways = WC()->payment_gateways->payment_gateways();

				$paymentmethods = array();

				foreach( $gateways as $paymentmethod ) {

					$paymentmethods[] = get_object_vars( $paymentmethod );

				}

				$response['paymentmethods'] = $paymentmethods;

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'X-Codisto-Content-Type' => 'application/json',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( $response );
				exit();

			} elseif ( $type == "shipping" ) {

				$response = array( 'ack' => 'ok' );

				$shippingmethodlist = WC()->shipping->get_shipping_methods();

				$shippingmethods = array();

				foreach( $shippingmethodlist as $shippingmethod ) {

					$shippingmethods[] = get_object_vars( $shippingmethod );

				}

				$response['shippingmethods'] = $shippingmethods;

				$zoneslist = WC_Shipping_Zones::get_zones();

				$shippingzones = array();

				foreach( $zoneslist as $zone ) {

					$shippingzones[] = get_object_vars( $zone );

				}

				$response['shippingzones'] = $shippingzones;

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'X-Codisto-Content-Type' => 'application/json',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( $response );
				exit();

			} elseif ( $type == "conversiontracking" ) {

				$response = array( 'ack' => 'ok' );

				$conversiontracking = get_option( 'codisto_conversion_tracking' );

				if( $conversiontracking ) {

					$response['conversiontracking'] = $conversiontracking;

				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'X-Codisto-Content-Type' => 'application/json',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( $response );
				exit();

			}

		} else {

			if ( $type === 'createorder' ) {

				if ( ! $this->check_hash() ) {
					exit();
				}

				try {

					$xml = simplexml_load_string( file_get_contents( 'php://input' ) );

					$ordercontent = $xml->entry->content->children( 'http://api.codisto.com/schemas/2009/' );

					$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' );
					$wpdb->query( 'START TRANSACTION' );

					$billing_address = $ordercontent->orderaddresses->orderaddress[0];
					$shipping_address = $ordercontent->orderaddresses->orderaddress[1];

					$billing_first_name = $billing_last_name = '';
					if ( strpos( $billing_address->name, ' ') !== false ) {
						$billing_name = explode( ' ', $billing_address->name, 2 );
						$billing_first_name = $billing_name[0];
						$billing_last_name = $billing_name[1];
					} else {
						$billing_first_name = (string)$billing_address->name;
					}

					$billing_country_code = (string)$billing_address->countrycode;
					$billing_division = (string)$billing_address->division;

					$billing_states = WC()->countries->get_states( $billing_country_code );

					if ( $billing_states ) {
						$billing_division_match = preg_replace( '/\s+/', '', strtolower( $billing_division ) );

						foreach ( $billing_states as $state_code => $state_name ) {
							if ( preg_replace( '/\s+/', '', strtolower( $state_name ) ) == $billing_division_match ) {
								$billing_division = $state_code;
								break;
							}
						}
					}

					$shipping_first_name = $shipping_last_name = '';
					if ( strpos( $shipping_address->name, ' ' ) !== false ) {
						$shipping_name = explode( ' ', $shipping_address->name, 2 );
						$shipping_first_name = $shipping_name[0];
						$shipping_last_name = $shipping_name[1];
					} else {
						$shipping_first_name = (string)$shipping_address->name;
					}

					$shipping_country_code = (string)$shipping_address->countrycode;
					$shipping_division = (string)$shipping_address->division;

					if ( $billing_country_code === $shipping_country_code ) {
						$shipping_states = $billing_states;
					} else {
						$shipping_states = WC()->countries->get_states( $shipping_country_code );
					}

					if ( $shipping_states ) {
						$shipping_division_match = preg_replace( '/\s+/', '', strtolower( $shipping_division ) );

						foreach ( $shipping_states as $state_code => $state_name ) {
							if ( preg_replace( '/\s+/', '', strtolower( $state_name ) ) == $shipping_division_match ) {
								$shipping_division = $state_code;
								break;
							}
						}
					}

					$amazonorderid = (string)$ordercontent->amazonorderid;
					if ( ! $amazonorderid ) {
						$amazonorderid = '';
					}

					$amazonfulfillmentchannel = (string)$ordercontent->amazonfulfillmentchannel;
					if ( ! $amazonfulfillmentchannel ) {
						$amazonfulfillmentchannel = '';
					}

					$ebayusername = (string)$ordercontent->ebayusername;
					if ( ! $ebayusername ) {
						$ebayusername = '';
					}

					$ebaysalesrecordnumber = (string)$ordercontent->ebaysalesrecordnumber;
					if ( ! $ebaysalesrecordnumber ) {
						$ebaysalesrecordnumber = '';
					}

					$ebaytransactionid = (string)$ordercontent->ebaytransactionid;
					if ( ! $ebaytransactionid ) {
						$ebaytransactionid = '';
					}

					$address_data = array(
								'billing_first_name'	=> $billing_first_name,
								'billing_last_name'		=> $billing_last_name,
								'billing_company'		=> (string)$billing_address->companyname,
								'billing_address_1'		=> (string)$billing_address->address1,
								'billing_address_2'		=> (string)$billing_address->address2,
								'billing_city'			=> (string)$billing_address->place,
								'billing_postcode'		=> (string)$billing_address->postalcode,
								'billing_state'			=> $billing_division,
								'billing_country'		=> $billing_country_code,
								'billing_email'			=> (string)$billing_address->email,
								'billing_phone'			=> (string)$billing_address->phone,
								'shipping_first_name'	=> $shipping_first_name,
								'shipping_last_name'	=> $shipping_last_name,
								'shipping_company'		=> (string)$shipping_address->companyname,
								'shipping_address_1'	=> (string)$shipping_address->address1,
								'shipping_address_2'	=> (string)$shipping_address->address2,
								'shipping_city'			=> (string)$shipping_address->place,
								'shipping_postcode'		=> (string)$shipping_address->postalcode,
								'shipping_state'		=> $shipping_division,
								'shipping_country'		=> $shipping_country_code,
								'shipping_email'		=> (string)$shipping_address->email,
								'shipping_phone'		=> (string)$shipping_address->phone,
							);

					$order_id = null;

					if ( isset( $ordercontent->wooneworderpush )
						&& $ordercontent->wooneworderpush != null
						&& (string)$ordercontent->wooneworderpush == 'true' ) {

						if(!empty( $ordercontent->orderid )
							&& !empty( $ordercontent->ordernumber )
							&& intval( $ordercontent->orderid ) !== intval( $ordercontent->ordernumber ) ) {

							$order_id_sql = "SELECT post_id AS ID FROM `{$wpdbsiteprefix}postmeta` " .
							"WHERE post_id = %d AND (meta_key = '_codisto_merchantid' AND meta_value = %d) " .
							"LIMIT 1";

							$order_id = $wpdb->get_var( $wpdb->prepare( $order_id_sql, (int) $ordercontent->ordernumber, (int) $ordercontent->merchantid ) );

						}

						if(!$order_id) {

							$order_id_sql = "SELECT PM.post_id as ID FROM `{$wpdbsiteprefix}postmeta` AS PM " .
							"INNER JOIN `{$wpdbsiteprefix}postmeta` AS PM2 ON " .
							"(PM2.post_id = PM.post_id AND PM2.meta_key = '_codisto_merchantid' AND PM2.meta_value = %d) " .
							"WHERE (PM.meta_key = '_codisto_orderid' AND PM.meta_value = %d) " .
							"LIMIT 1";

							$order_id = $wpdb->get_var( $wpdb->prepare( $order_id_sql, (int) $ordercontent->merchantid, (int) $ordercontent->orderid ) );
						}

					} else {

						$order_id_sql = "SELECT ID FROM `{$wpdbsiteprefix}posts` AS P WHERE EXISTS (SELECT 1 FROM `{$wpdbsiteprefix}postmeta` " .
						" WHERE meta_key = '_codisto_orderid' AND meta_value = %d AND post_id = P.ID ) " .
						" AND (".
							" EXISTS (SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND meta_value = %d AND post_id = P.ID)" .
							" OR NOT EXISTS (SELECT 1 FROM `{$wpdbsiteprefix}postmeta` WHERE meta_key = '_codisto_merchantid' AND post_id = P.ID)"
						.")" .
						" LIMIT 1";

						$order_id = $wpdb->get_var( $wpdb->prepare( $order_id_sql, (int)$ordercontent->orderid, (int)$ordercontent->merchantid ) );

					}

					$email = (string)$billing_address->email;
					if ( ! $email ) {
						$email = (string)$shipping_address->email;
					}

					if ( $email ) {

						$userid = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$wpdbsiteprefix}users` WHERE user_email = %s", $email ) );
						if ( ! $userid &&  ! $order_id && (true === apply_filters( 'woocommerce_create_account_default_checked', false )) ) {
							$username = $ebayusername;
							if ( ! $username ) {
								$username = current( explode( '@', $email ) );
							}

							if ( $username ) {
								$username = sanitize_user( $username );
							}

							if ( username_exists( $username ) ) {
								$counter = 1;
								$newusername = $username.$counter;

								while( username_exists( $newusername ) ) {
									$counter++;
									$newusername = $username.$counter;
								}

								$username = $newusername;
							}

							$password = wp_generate_password();

							$customer_data = apply_filters(
								'woocommerce_new_customer_data',
								array(
									'user_login' => $username,
									'user_pass'  => $password,
									'user_email' => $email,
									'role'	   => 'customer'
								)
							);

							$customer_id = wp_insert_user( $customer_data );

							foreach ( $address_data as $key => $value ) {
								update_user_meta( $customer_id, $key, $value );
							}

							do_action( 'woocommerce_created_customer', $customer_id, $customer_data, true );
						} else {
							$customer_id = $userid;
						}
					} else {
						$customer_id = 0;
					}

					$customer_note = @count( $ordercontent->instructions ) ? strval( $ordercontent->instructions ) : '';
					$merchant_note = @count( $ordercontent->merchantinstructions ) ? strval( $ordercontent->merchantinstructions ) : '';

					$adjustStock = @count( $ordercontent->adjuststock ) ? ( ( $ordercontent->adjuststock == 'false' ) ? false : true ) : true;

					$shipping = 0;
					$shipping_tax = 0;
					$cart_discount = 0;
					$cart_discount_tax = 0;
					$total = (float)$ordercontent->defaultcurrencytotal;
					$tax = 0;

					if ( ! $order_id ) {

						$new_order_data_callback = array( $this, 'order_set_date' );

						add_filter( 'woocommerce_new_order_data', $new_order_data_callback, 1, 1 );

						$createdby = 'eBay';
						if ( $amazonorderid ) {
							$createdby = 'Amazon';
						}

						$order = wc_create_order( array( 'customer_id' => $customer_id, 'customer_note' => $customer_note, 'created_via' => $createdby ) );

						remove_filter( 'woocommerce_new_order_data', $new_order_data_callback );

						$order_id = $order->get_id();

						update_post_meta( $order_id, '_codisto_orderid', (int)$ordercontent->orderid );
						update_post_meta( $order_id, '_codisto_merchantid', (int)$ordercontent->merchantid );

						if ( $amazonorderid ) {
							update_post_meta( $order_id, '_codisto_amazonorderid', $amazonorderid );
						}
						if ( $amazonfulfillmentchannel ) {
							update_post_meta( $order_id, '_codisto_amazonfulfillmentchannel', $amazonfulfillmentchannel );
						}

						if ( $ebayusername ) {
							update_post_meta( $order_id, '_codisto_ebayusername', $ebayusername );
						}

						if ( $ebaysalesrecordnumber ) {
							update_post_meta( $order_id, '_codisto_ebaysalesrecordnumber', $ebaysalesrecordnumber );
						}

						if ( $ebaytransactionid ) {
							update_post_meta( $order_id, '_codisto_ebaytransactionid', $ebaytransactionid );
						}

						$defaultcurrency = @count( $ordercontent->defaultcurrency ) ? (string)$ordercontent->defaultcurrency : (string)$ordercontent->transactcurrency;

						update_post_meta( $order_id, '_order_currency', $defaultcurrency );
						update_post_meta( $order_id, '_customer_ip_address', '-' );
						delete_post_meta( $order_id, '_prices_include_tax' );

						do_action( 'woocommerce_new_order', $order_id, $order );

						foreach ( $ordercontent->orderlines->orderline as $orderline ) {
							if ( $orderline->productcode[0] != 'FREIGHT' ) {
								$productcode = (string)$orderline->productcode;
								if ( $productcode == null ) {
									$productcode = '';
								}
								$productname = (string)$orderline->productname;
								if ( $productname == null ) {
									$productname = '';
								}

								$product_id = $orderline->externalreference[0];
								if ( $product_id != null ) {
									$product_id = intval( $product_id );
								}

								$variation_id = 0;

								if ( get_post_type( $product_id ) === 'product_variation' ) {
									$variation_id = $product_id;
									$product_id = wp_get_post_parent_id( $variation_id );

									if ( ! is_numeric( $product_id ) || $product_id === 0 ) {
										$product_id = 0;
										$variation_id = 0;
									}
								}

								$qty = (int)$orderline->quantity[0];

								$item_id = wc_add_order_item(
									$order_id,
									array(
										'order_item_name' => $productname,
										'order_item_type' => 'line_item'
									)
								);

								wc_add_order_item_meta( $item_id, '_qty', $qty );

								if ( ! is_null( $product_id ) && $product_id !== 0 ) {
									wc_add_order_item_meta( $item_id, '_product_id', $product_id );
									wc_add_order_item_meta( $item_id, '_variation_id', $variation_id );
									wc_add_order_item_meta( $item_id, '_tax_class', '' );
								} else {
									wc_add_order_item_meta( $item_id, '_product_id', 0 );
									wc_add_order_item_meta( $item_id, '_variation_id', 0 );
									wc_add_order_item_meta( $item_id, '_tax_class', '' );
								}

								$line_total = wc_format_decimal( (float)$orderline->defaultcurrencylinetotal );
								$line_total_tax = wc_format_decimal( (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal );

								wc_add_order_item_meta( $item_id, '_line_subtotal',	 $line_total );
								wc_add_order_item_meta( $item_id, '_line_total',		$line_total );
								wc_add_order_item_meta( $item_id, '_line_subtotal_tax', $line_total_tax );
								wc_add_order_item_meta( $item_id, '_line_tax',		  $line_total_tax );
								wc_add_order_item_meta( $item_id, '_line_tax_data',		array( 'total' => array( 1 => $line_total_tax ), 'subtotal' => array( 1 => $line_total_tax ) ) );

								$tax += $line_total_tax;

							} else {
								$method_id = (string)$orderline->productcode;
								if ( $method_id == null ) {
									$method_id = '';
								}
								$item_id = wc_add_order_item(
									$order_id,
									array(
										'order_item_name' 		=> (string)$orderline->productname,
										'order_item_type' 		=> 'shipping'
									)
								);

								wc_add_order_item_meta($item_id, 'method_id', $method_id);
								wc_add_order_item_meta( $item_id, 'cost', wc_format_decimal( (float)$orderline->defaultcurrencylinetotal) );
								wc_add_order_item_meta( $item_id, 'total_tax', wc_format_decimal( (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal) );

								$shipping_tax_array = array (
									'total' => array (
										1=> (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal,
									)
								);

								wc_add_order_item_meta( $item_id, 'taxes', $shipping_tax_array);
								$shipping += (float)$orderline->defaultcurrencylinetotal;
								$shipping_tax += (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal;
							}
						}

						if ( $ordercontent->paymentstatus == 'complete' ) {
							$transaction_id = (string)$ordercontent->orderpayments[0]->orderpayment->transactionid;
							$paymentmethod = (string)$ordercontent->orderpayments[0]->orderpayment->paymentmethod;

							if ( $transaction_id  && preg_match('/paypal/i',$paymentmethod)) {
								update_post_meta( $order_id, '_payment_method', 'paypal' );
								update_post_meta( $order_id, '_payment_method_title', __( 'PayPal', 'woocommerce' ) );

								update_post_meta( $order_id, '_transaction_id', $transaction_id );
							} else {
								update_post_meta( $order_id, '_payment_method', 'bacs' );
								update_post_meta( $order_id, '_payment_method_title', __( 'BACS', 'woocommerce' ) );
							}

							// payment_complete
							add_post_meta( $order_id, '_paid_date', current_time( 'mysql' ), true );
							if ( $adjustStock && !get_post_meta( $order_id, '_order_stock_reduced', true ) ) {
								wc_maybe_reduce_stock_levels( $order_id );
							}
						}

						if ( $merchant_note ) {
							$order->add_order_note( $merchant_note, 0 );
						}

					} else {
						$order = wc_get_order( $order_id );

						if( is_object( $order ) ) {

							foreach ( $ordercontent->orderlines->orderline as $orderline ) {
								if ( $orderline->productcode[0] != 'FREIGHT' ) {
									$line_total = wc_format_decimal( (float)$orderline->defaultcurrencylinetotal );
									$line_total_tax = wc_format_decimal( (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal );

									$tax += $line_total_tax;
								} else {
									$order->remove_order_items( 'shipping' );

									$item_id = wc_add_order_item(
										$order_id,
										array(
											'order_item_name' 		=> (string)$orderline->productname,
											'order_item_type' 		=> 'shipping'
										)
									);

									wc_add_order_item_meta( $item_id, 'cost', wc_format_decimal( (float)$orderline->defaultcurrencylinetotal) );
									wc_add_order_item_meta( $item_id, 'total_tax', wc_format_decimal( (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal) );

									$shipping_tax_array = array (
										'total' => array (
											1=> (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal,
										)
									);

									wc_add_order_item_meta( $item_id, 'taxes', $shipping_tax_array);
									$shipping += (float)$orderline->defaultcurrencylinetotal;
									$shipping_tax += (float)$orderline->defaultcurrencylinetotalinctax - (float)$orderline->defaultcurrencylinetotal;
								}
							}

							if ( $ordercontent->paymentstatus == 'complete' ) {
								$transaction_id = (string)$ordercontent->orderpayments[0]->orderpayment->transactionid;
								$paymentmethod = (string)$ordercontent->orderpayments[0]->orderpayment->paymentmethod;

								if ( $transaction_id  && preg_match('/paypal/i',$paymentmethod)) {
									update_post_meta( $order_id, '_payment_method', 'paypal' );
									update_post_meta( $order_id, '_payment_method_title', __( 'PayPal', 'woocommerce' ) );

									update_post_meta( $order_id, '_transaction_id', $transaction_id );
								} else {
									update_post_meta( $order_id, '_payment_method', 'bacs' );
									update_post_meta( $order_id, '_payment_method_title', __( 'BACS', 'woocommerce' ) );
								}

								// payment_complete
								add_post_meta( $order_id, '_paid_date', current_time( 'mysql' ), true );
								if ( $adjustStock && ! get_post_meta( $order_id, '_order_stock_reduced', true ) ) {
									wc_maybe_reduce_stock_levels( $order_id );
								}
							}
						}
					}

					if( is_object( $order ) ) {

						foreach ( $address_data as $key => $value ) {
							update_post_meta( $order_id, '_'.$key, $value );
						}

						$order->remove_order_items( 'tax' );
						$order->add_tax( 1, $tax, $shipping_tax );

						$order->set_total( $shipping, 'shipping' );
						$order->set_total( $shipping_tax, 'shipping_tax' );
						$order->set_total( $cart_discount, 'cart_discount' );
						$order->set_total( $cart_discount_tax, 'cart_discount_tax' );
						$order->set_total( $tax, 'tax' );
						$order->set_total( $total, 'total');

						if ( $ordercontent->orderstate == 'cancelled' ) {
							if ( ! $order->has_status( 'cancelled' ) ) {
								// update_status
								$order->set_status( 'cancelled' );
								$update_post_data  = array(
									'ID'		 	=> $order_id,
									'post_status'	=> 'wc-cancelled',
									'post_date'		=> current_time( 'mysql', 0 ),
									'post_date_gmt' => current_time( 'mysql', 1 )
								);
								wp_update_post( $update_post_data );

								$order->decrease_coupon_usage_counts();

								wc_delete_shop_order_transients( $order_id );
							}
						} elseif ( $ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing' ) {

							if ( $ordercontent->paymentstatus == 'complete' ) {
								if ( ! $order->has_status( 'processing' ) && ! $order->has_status( 'completed' )) {

									// update_status
									$order->set_status( 'processing' );
									$update_post_data  = array(
										'ID'		 	=> $order_id,
										'post_status'	=> 'wc-processing',
										'post_date'		=> current_time( 'mysql', 0 ),
										'post_date_gmt' => current_time( 'mysql', 1 )
									);
									wp_update_post( $update_post_data );
								}
							} else {
								if ( ! $order->has_status( 'pending' ) ) {
									// update_status
									$order->set_status( 'pending' );
									$update_post_data  = array(
										'ID'		 	=> $order_id,
										'post_status'	=> 'wc-pending',
										'post_date'		=> current_time( 'mysql', 0 ),
										'post_date_gmt' => current_time( 'mysql', 1 )
									);
									wp_update_post( $update_post_data );
								}
							}

						} elseif ( $ordercontent->orderstate == 'complete' ) {

							if ( ! $order->has_status( 'completed' ) ) {
								// update_status
								$order->set_status( 'completed' );
								$update_post_data  = array(
									'ID'		 	=> $order_id,
									'post_status'	=> 'wc-completed',
									'post_date'		=> current_time( 'mysql', 0 ),
									'post_date_gmt' => current_time( 'mysql', 1 )
								);
								wp_update_post( $update_post_data );

								$order->record_product_sales();

								$order->increase_coupon_usage_counts();

								update_post_meta( $order_id, '_completed_date', current_time( 'mysql' ) );

								wc_delete_shop_order_transients( $order_id );
							}

						}

						$order->save();

					}

					$wpdb->query( 'COMMIT' );

					$response = array( 'ack' => 'ok', 'orderid' => $order_id );

					$this->sendHttpHeaders(
						'200 OK',
						array(
							'Content-Type' => 'application/json',
							'Cache-Control' => 'no-cache, no-store',
							'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
							'Pragma' => 'no-cache'
						)
					);
					echo $this->json_encode( $response );
					exit();

				} catch( Exception $e ) {
					$wpdb->query( 'ROLLBACK' );

					$response = array( 'ack' => 'failed', 'message' => $e->getMessage() .'  '.$e->getFile().' '.$e->getLine()  );

					$this->sendHttpHeaders(
						'200 OK',
						array(
							'Content-Type' => 'application/json',
							'Cache-Control' => 'no-cache, no-store',
							'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
							'Pragma' => 'no-cache'
						)
					);
					echo $this->json_encode( $response );
					exit();
				}

			} elseif ( $type == 'sync' ) {

				if ( $_SERVER['HTTP_X_ACTION'] === 'TEMPLATE' ) {
					if ( ! $this->check_hash() ) {
						exit();
					}

					$ebayDesignDir = WP_CONTENT_DIR . '/ebay/';

					$tmpPath = wp_tempnam();

					@file_put_contents( $tmpPath, file_get_contents( 'php://input' ) );

					$db = new PDO( 'sqlite:' . $tmpPath );
					$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

					$db->exec( 'PRAGMA synchronous=0' );
					$db->exec( 'PRAGMA temp_store=2' );
					$db->exec( 'PRAGMA page_size=65536' );
					$db->exec( 'PRAGMA encoding=\'UTF-8\'' );
					$db->exec( 'PRAGMA cache_size=15000' );
					$db->exec( 'PRAGMA soft_heap_limit=67108864' );
					$db->exec( 'PRAGMA journal_mode=MEMORY' );

					$files = $db->prepare( 'SELECT Name, Content FROM File' );
					$files->execute();

					$files->bindColumn( 1, $name );
					$files->bindColumn( 2, $content );

					while ( $files->fetch() ) {
						$fileName = $ebayDesignDir.$name;

						if ( strpos( $name, '..' ) === false ) {
							if ( ! file_exists( $fileName ) ) {
								$dir = dirname( $fileName );

								if ( ! is_dir( $dir ) ) {
									mkdir( $dir.'/', 0755, true );
								}

								@file_put_contents( $fileName, $content );
							}
						}
					}

					$db = null;
					unlink( $tmpPath );

					$this->sendHttpHeaders(
						'200 OK',
						array(
							'Content-Type' => 'application/json',
							'Cache-Control' => 'no-cache, no-store',
							'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
							'Pragma' => 'no-cache'
						)
					);
					echo $this->json_encode( array( 'ack' => 'ok' ) );
					exit();
				}

			} elseif ( $type == 'index/calc' ) {

				$product_ids = array();
				$quantities = array();

				for ( $i = 0; ; $i++ ) {
					if ( ! isset( $_POST['PRODUCTCODE('.$i.')'] ) ) {
						break;
					}

					$productid = (int)$_POST['PRODUCTID('.$i.')'];
					if ( ! $productid ) {
						$productcode = $_POST['PRODUCTCODE('.$i.')'];
						$productid = wc_get_product_id_by_sku( $productcode );
					}

					$productqty = $_POST['PRODUCTQUANTITY('.$i.')'];
					if ( ! $productqty && $productqty != 0 ) {
						$productqty = 1;
					}

					WC()->cart->add_to_cart( $productid, $productqty );
				}

				WC()->customer->set_location( $_POST['COUNTRYCODE'], $_POST['DIVISION'], $_POST['POSTALCODE'], $_POST['PLACE'] );
				WC()->customer->set_shipping_location( $_POST['COUNTRYCODE'], $_POST['DIVISION'], $_POST['POSTALCODE'], $_POST['PLACE'] );
				WC()->cart->calculate_totals();
				WC()->cart->calculate_shipping();

				$response = '';

				$idx = 0;
				$methods = WC()->shipping()->get_shipping_methods();
				foreach ( $methods as $method ) {
					if ( file_exists( plugin_dir_path( __FILE__ ).'shipping/'.$method->id ) ) {
						include( plugin_dir_path( __FILE__ ).'shipping/'.$method->id );
					} else {
						foreach ( $method->rates as $method => $rate ) {
							$method_name = $rate->get_label();
							if ( ! $method_name ) {
								$method_name = 'Shipping';
							}

							$method_cost = $rate->cost;
							if ( is_numeric( $method_cost) ) {
								if ( isset( $rate->taxes ) && is_array( $rate->taxes ) ) {
									foreach ( $rate->taxes as $tax ) {
										if ( is_numeric( $tax ) ) {
											$method_cost += $tax;
										}
									}
								}

								$response .= ($idx > 0 ? '&' : '').'FREIGHTNAME('.$idx.')='.rawurlencode( $method_name ).'&FREIGHTCHARGEINCTAX('.$idx.')='.number_format( (float)$method_cost, 2, '.', '' );

								$idx++;
							}
						}
					}
				}

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $response;
				exit();

			} elseif ( $type == "siteverification" ) {

				update_option( 'codisto_site_verification' , file_get_contents( 'php://input' ) );

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( array( 'ack' => 'ok' ) );
				exit();

			} elseif ( $type == "conversiontracking" ) {

				$conversiontracking = intval( get_option( 'codisto_conversion_tracking' ) ) + 1;

				update_option( 'codisto_conversion_tracking' , strval( $conversiontracking ) );

				$upload_dir = wp_upload_dir();
				$conversion_tracking_file = '/codisto/conversion-tracking.js';
				$conversion_tracking_path = $upload_dir['basedir'].$conversion_tracking_file;

				wp_mkdir_p( dirname( $conversion_tracking_path ) );

				file_put_contents( $conversion_tracking_path, file_get_contents( 'php://input' ) );

				$this->sendHttpHeaders(
					'200 OK',
					array(
						'Content-Type' => 'application/json',
						'Cache-Control' => 'no-cache, no-store',
						'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
						'Pragma' => 'no-cache'
					)
				);
				echo $this->json_encode( array( 'ack' => 'ok' ) );
				exit();

			}


		}
	}

	/**
	* wc_order_is_editable filter hook handler used to block edit of marketplace sourced orders
	*
	* @param boolean $editable current state of orders editable status
	* @param object $order the order object to test for editability
	* @return boolean status to whether the order can be edited
	*/
	public function order_is_editable( $editable, $order ) {
		$codisto_order_id = get_post_meta( $order->get_id(), '_codisto_orderid', true);
		if ( is_numeric( $codisto_order_id ) && $codisto_order_id !== 0 ) {
			return false;
		}

		return $editable;
	}

	/**
	* woocommerce_admin_order_data_after_order_details filter hook handler used to place
	* marketplace specific buttons onto an order if an order is sourced from a marketplace
	*
	* @param object $order that the buttons are to be rendered for
	*/
	public function order_buttons( $order ) {
		$codisto_order_id = get_post_meta( $order->get_id(), '_codisto_orderid', true );
		if ( is_numeric( $codisto_order_id ) && $codisto_order_id !== 0 ) {
			$ebay_user = get_post_meta( $order->get_id(), '_codisto_ebayusername', true );
			$merchantid = get_post_meta( $order->get_id(), '_codisto_merchantid', true );
			if ( $ebay_user ) {
				?>
				<p class="form-field form-field-wide codisto-order-buttons">
				<a href="<?php echo htmlspecialchars( admin_url( 'codisto/ebaysale?orderid='.$codisto_order_id.'&merchantid='.$merchantid ) ) ?>" target="codisto!sale" class="button"><?php esc_html_e( 'eBay Order', 'codisto-linq' ) ?> &rarr;</a>
				<a href="<?php echo htmlspecialchars( admin_url( 'codisto/ebayuser?orderid='.$codisto_order_id.'&merchantid='.$merchantid) ) ?>" target="codisto!user" class="button"><?php esc_html_e( 'eBay User', 'codisto-linq' ) ?><?php echo $ebay_user ? ' : '.htmlspecialchars( $ebay_user ) : ''; ?> &rarr;</a>
				</p>
				<?php
			}
			$amazon_order = get_post_meta( $order->get_id(), '_codisto_amazonorderid', true );
			if ( $amazon_order ) {
				?>
				<p class="form-field form-field-wide codisto-order-buttons">
				<a href="<?php echo htmlspecialchars( admin_url( 'codisto/amazonsale?orderid='.$codisto_order_id.'&merchantid='.$merchantid ) ) ?>" target="codisto!sale" class="button"><?php esc_html_e( 'Amazon Order', 'codisto-linq' ) ?> &rarr;</a>
				</p>
				<?php
			}
		}
	}

	/**
	* proxy is used to translate local requests to the wordpress instance that represent
	* requests for UI and proxies those requests from the server back to Codisto
	*
	*/
	public function proxy() {
		global $wp;

		error_reporting( E_ERROR | E_PARSE );
		set_time_limit( 0 );

		@ini_set( 'zlib.output_compression', 'Off' );
		@ini_set( 'output_buffering', 'Off' );
		@ini_set( 'output_handler', '' );

		while( ob_get_level() > 1 ) {
			@ob_end_clean();
		}
		if ( ob_get_level() > 0 ) {
			@ob_clean();
		}

		if ( isset( $_GET['productid'] ) ) {
			wp_redirect( admin_url( 'post.php?post='.urlencode( wp_unslash( $_GET['productid'] ) ).'&action=edit#codisto_product_data' ) );
			exit;
		}

		$HostKey = get_option( 'codisto_key' );

		if ( ! function_exists( 'getallheaders' ) ) {
			 function getallheaders() {
				$headers = array();
				foreach ( $_SERVER as $name => $value ) {
					if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
						$headers[str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) )] = $value;
					} elseif ( $name == 'CONTENT_TYPE' ) {
						$headers['Content-Type'] = $value;
					} elseif ( $name == 'CONTENT_LENGTH' ) {
						$headers['Content-Length'] = $value;
					}
				}
				return $headers;
			 }
		}

		$querystring = preg_replace( '/q=[^&]*&/', '', $_SERVER['QUERY_STRING'] );
		$path = $wp->query_vars['codisto-proxy-route'] . ( preg_match( '/\/(?:\\?|$)/', $_SERVER['REQUEST_URI'] ) ? '/' : '' );

		$storeId = '0';
		$merchantid = get_option( 'codisto_merchantid' );

		if ( isset( $_GET['merchantid'] ) ) {
			$merchantid = (int)$_GET['merchantid'];
		} else {
			$storematch = array();

			if ( preg_match( '/^ebaytab\/(\d+)\/(\d+)(?:\/|$)/', $path, $storematch ) ) {
				$storeId = (int)$storematch[1];
				$merchantid = (int)$storematch[2];

				$path = preg_replace( '/(^ebaytab\/)(\d+\/?)(\d+\/?)/', '$1', $path );
			}
			if ( preg_match( '/^ebaytab\/(\d+)(?:\/|$)/', $path, $storematch ) ) {
				if ( isset( $storematch[2] ) ) {
					$merchantid = (int)$storematch[2];
				}

				$path = preg_replace( '/(^ebaytab\/)(\d+\/?)/', '$1', $path );
			}
		}

		if ( ! $merchantid ) {
			$this->sendHttpHeaders(
				'404 Not Found',
				array(
					'Content-Type' => 'text/html',
					'Cache-Control' => 'no-cache, no-store',
					'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
					'Pragma' => 'no-cache'
				)
			);
			?>
			<h1>Resource Not Found</h1>
			<?php
			exit();
		}

		$remoteUrl = 'https://ui.codisto.com/' . $merchantid . '/'. $path . ( $querystring ? '?'.$querystring : '' );

		$adminUrl = admin_url( 'codisto/ebaytab/'.$storeId.'/'.$merchantid.'/' );

		$requestHeaders = array(
							'X-Codisto-Cart' => 'woocommerce',
							'X-Codisto-Version' => CODISTOCONNECT_VERSION,
							'X-HostKey' => $HostKey,
							'X-Admin-Base-Url' => $adminUrl,
							'Accept-Encoding' => ''
						);

		$incomingHeaders = getallheaders();

		$headerfilter = array(
			'host',
			'connection',
			'accept-encoding'
		);
		if ( $_SERVER['X-LSCACHE'] == 'on' ) {
			$headerfilter[] = 'if-none-match';
		}
		foreach ( $incomingHeaders as $name => $value ) {
			if ( ! in_array( trim( strtolower( $name ) ), $headerfilter ) ) {
				$requestHeaders[$name] = $value;
			}
		}

		$httpOptions = array(
						'method' => $_SERVER['REQUEST_METHOD'],
						'headers' => $requestHeaders,
						'timeout' => 60,
						'httpversion' => '1.0',
						'decompress' => false,
						'redirection' => 0
					);

		$upload_dir = wp_upload_dir();

		if ( is_multisite() ) {
			$certPath = $upload_dir['basedir'].'/sites/'.get_current_blog_id().'/codisto.crt';
		} else {
			$certPath = $upload_dir['basedir'].'/codisto.crt';
		}

		if ( file_exists( $certPath ) ) {
			$httpOptions['sslcertificates'] = $certPath;
		}

		if ( strtolower( $httpOptions['method'] ) == 'post' ) {
			$httpOptions['body'] = file_get_contents( 'php://input' );
		}

		for ( $retry = 0; ; $retry++ ) {

			$response = wp_remote_request( $remoteUrl, $httpOptions );

			if ( is_wp_error( $response ) ) {
				if ( $retry >= 3 ) {
					$this->sendHttpHeaders(
						'500 Server Error',
						array(
							'Content-Type' => 'text/html',
							'Cache-Control' => 'no-cache, no-store',
							'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
							'Pragma' => 'no-cache'
						)
					);
					echo '<h1>Error processing request</h1> <p>'.htmlspecialchars( $response->get_error_message() ).'</p>';
					exit();
				}

				if ( $httpOptions['sslcertificates']
				 	&& strpos( $response->get_error_message(), 'cURL error 77' ) !== false ) {

					@file_put_contents( $certPath, '' );
					unset( $httpOptions['sslcertificates'] );
					continue;

				}

				if ( $response->get_error_code() == 'http_request_failed' ) {
					$certResponse = wp_remote_get( 'http://ui.codisto.com/codisto.crt' );

					if ( ! is_wp_error( $certResponse ) ) {
						@file_put_contents( $certPath, $certResponse['body'] );
						$httpOptions['sslcertificates'] = $certPath;
						continue;
					}
				}

				sleep(2);
				continue;
			}

			break;
		}

		if ( defined( 'ADVANCEDCACHEPROBLEM' ) &&
			false == strpos( $_SERVER['REQUEST_URI'], 'wp-admin') ) {
			$_SERVER['REQUEST_URI'] = '/wp-admin'.$_SERVER['REQUEST_URI'];
		}

		status_header( wp_remote_retrieve_response_code( $response ) );

		$filterHeaders = array( 'server', 'content-length', 'transfer-encoding', 'date', 'connection', 'x-storeviewmap', 'content-encoding' );

		if ( function_exists( 'header_remove' ) ) {
			@header_remove( 'Last-Modified' );
			@header_remove( 'Pragma' );
			@header_remove( 'Cache-Control' );
			@header_remove( 'Expires' );
			@header_remove( 'Content-Encoding' );
		}

		foreach ( wp_remote_retrieve_headers( $response ) as $header => $value ) {

			if ( ! in_array( strtolower( $header ), $filterHeaders, true ) ) {
				if ( is_array( $value ) ) {
					header( $header.': '.$value[0], true );

					for ( $i = 1; $i < count( $value ); $i++ ) {
						header( $header.': '.$value[$i], false );
					}
				} else {
					header( $header.': '.$value, true );
				}
			}
		}

		file_put_contents( 'php://output', wp_remote_retrieve_body( $response ) );
		exit();
	}

	/**
	* parse_request hook handler routes requests to proxy or sync via captured
	* query vars
	*
	*/
	public function parse() {

		global $wp;

		if ( ! empty( $wp->query_vars['codisto'] ) &&
			in_array( $wp->query_vars['codisto'], array( 'proxy','sync' ), true ) ) {
			$codistoMode = $wp->query_vars['codisto'];

			if ( $codistoMode == 'sync' ) {
				$this->sync();
			} elseif ( $codistoMode == 'proxy' ) {
				if ( current_user_can( 'manage_woocommerce' ) ) {
					$this->proxy();
				} else {
					auth_redirect();
				}
			}

			exit;
		}
	}

	/**
	* used for affiliate marketing when the plugin is distributed by an affiliate partner
	*
	* @return string reseller key, the entity that has distributed the extension
	*/
	private function reseller_key() {
		return CODISTOCONNECT_RESELLERKEY;
	}

	/**
	* POST handler for create account on codisto servers for this woocommerce instance
	*
	*/
	public function create_account() {

		$blogversion = preg_replace( '/[\x0C\x0D]/', ' ', preg_replace( '/[\x00-\x1F\x7F]/', '', get_bloginfo( 'version' ) ) );
		$blogurl = preg_replace( '/[\x0C\x0D]/', ' ', preg_replace( '/[\x00-\x1F\x7F]/', '', get_site_url() ) );
		$blogdescription = preg_replace( '/[\x0C\x0D]/', ' ', preg_replace( '/[\x00-\x1F\x7F]/', '', get_option( 'blogdescription' ) ) );

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

			check_admin_referer( 'codisto-create' );

			if ( $_POST['method'] == 'email' ) {

				$signupemail = wp_unslash( $_POST['email'] );
				$signupcountry = wp_unslash( $_POST['countrycode'] );
				$signupphone = wp_unslash( $_POST['phone'] );

				$httpOptions = array(
								'method' => 'POST',
								'headers' => array( 'Content-Type' => 'application/json' ),
								'timeout' => 60,
								'httpversion' => '1.0',
								'redirection' => 0,
								'body' => $this->json_encode(
									array (
										'type' => 'woocommerce',
										'version' => $blogversion,
										'url' => $blogurl,
										'email' => $signupemail,
										'phone' => $signupphone,
										'country' => $signupcountry,
										'storename' => $blogdescription ,
										'resellerkey' => $this->reseller_key(),
										'codistoversion' => CODISTOCONNECT_VERSION
									)
								)
							);

				$response = wp_remote_request( 'https://ui.codisto.com/create', $httpOptions );

				if ( $response ) {

					$result = json_decode( wp_remote_retrieve_body( $response ), true );

				} else {

					$postdata = array (
						'type' => 'woocommerce',
						'version' => $blogversion,
						'url' => $blogurl,
						'email' => $signupemail,
						'phone' => $signupphone,
						'country' => $signupcountry,
						'storename' => $blogdescription,
						'resellerkey' => $this->reseller_key(),
						'codistoversion' => CODISTOCONNECT_VERSION
					);
					$str = $this->json_encode( $postdata );

					$curl = curl_init();
					curl_setopt_array(
						$curl,
						array(
							CURLOPT_RETURNTRANSFER => 1,
							CURLOPT_URL => 'https://ui.codisto.com/create',
							CURLOPT_POST => 1,
							CURLOPT_POSTFIELDS => $str,
							CURLOPT_HTTPHEADER => array(
								'Content-Type: application/json',
								'Content-Length: ' . strlen( $str )
							)
						)
					);
					$response = curl_exec( $curl );
					curl_close( $curl );

					$result = json_decode( $response, true );

				}

				update_option( 'codisto_merchantid' , 	$result['merchantid'] );
				update_option( 'codisto_key',			$result['hostkey'] );

				wp_cache_flush();

				wp_redirect( 'admin.php?page=codisto' );

			} else {

				$blogdescription = preg_replace( '/[\x0C\x0D]/', ' ', preg_replace( '/[\x00-\x1F\x7F]/', '', get_option( 'blogdescription' ) ) );

				wp_redirect(
					'https://ui.codisto.com/register?finalurl='.
					urlencode( admin_url( 'admin-post.php?action=codisto_create&_wpnonce='.urlencode( wp_create_nonce( 'codisto-create' ) ) ) ).
					'&type=woocommerce'.
					'&version='.urlencode( $blogversion ).
					'&url='.urlencode( $blogurl ).
					'&storename='.urlencode( $blogdescription ).
					'&storecurrency='.urlencode( get_option( 'woocommerce_currency' ) ).
					'&resellerkey='.urlencode( $this->reseller_key() ).
					'&codistoversion='.urlencode( CODISTOCONNECT_VERSION )
				);
			}

		} else {

			if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'codisto-create') ) {
				wp_die( '<p>'.esc_html__( 'URL Security Check has failed, please start the process again.', 'codisto-linq' ).'</p>' );
			}

			$regtoken = '';
			if ( isset($_GET['regtoken'] ) ) {
				$regtoken = wp_unslash( $_GET['regtoken'] );
			} else {
				$query = array();
				parse_str( $_SERVER['QUERY_STRING'], $query );

				if ( isset( $query['regtoken'] ) ) {
					$regtoken = $query['regtoken'];
				}
			}

			$httpOptions = array(
				'method' => 'POST',
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 60,
				'httpversion' => '1.0',
				'redirection' => 0,
				'body' => $this->json_encode(
					array (
						'regtoken' => $regtoken
					)
				)
			);

			$response = wp_remote_request( 'https://ui.codisto.com/create', $httpOptions );

			if ( $response ) {

				$result = json_decode( wp_remote_retrieve_body( $response ), true );

			} else {

				$postdata =  array (
					'regtoken' => $regtoken
				);

				$str = $this->json_encode( $postdata );

				$curl = curl_init();
				curl_setopt_array(
					$curl,
					array(
						CURLOPT_RETURNTRANSFER => 1,
						CURLOPT_URL => 'https://ui.codisto.com/create',
						CURLOPT_POST => 1,
						CURLOPT_POSTFIELDS => $str,
						CURLOPT_HTTPHEADER => array(
							'Content-Type: application/json',
							'Content-Length: ' . strlen( $str )
						)
					)
				);

				$response = curl_exec( $curl );
				curl_close( $curl );

				$result = json_decode( $response, true );

			}

			update_option( 'codisto_merchantid' , 	$result['merchantid'] );
			update_option( 'codisto_key',			$result['hostkey'] );

			wp_cache_flush();

			wp_redirect( 'admin.php?page=codisto' );
		}
		exit();
	}

	/**
	* POST handler for saving edits to templates
	*
	*/
	public function update_template() {

		if ( !current_user_can( 'edit_themes' ) ) {
			wp_die( '<p>'.esc_html__( 'You do not have sufficient permissions to edit templates for this site.', 'codisto-linq' ).'</p>' );
		}

		check_admin_referer( 'edit-ebay-template' );

		$filename = wp_unslash( $_POST['file'] );
		$filename = preg_replace('/[^ -~]+|[\\/:"*?<>|]+/', '', $filename);

		$content = wp_unslash( $_POST['newcontent'] );

		$file = WP_CONTENT_DIR . '/ebay/' . $filename;

		@mkdir( basename( $file ), 0755, true );

		$updated = false;

		$f = fopen( $file, 'w' );
		if ( $f !== false) {
			fwrite( $f, $content );
			fclose( $f );

			$updated = true;
		}

		wp_redirect( admin_url( 'admin.php?page=codisto-templates&file='.urlencode( $filename ).( $updated ? '&updated=true' : '' ) ) );
		exit();
	}

	/**
	* common function used to render a proxied codisto page that checks
	* for a valid registered Codisto account
	*
	* @param string $url used to render an iframe to hold the locally proxied content
	* @param string $tabclass used to apply a css class to the iframe for specialised frame styling
	*/
	private function admin_tab( $url, $tabclass ) {

		$merchantid = get_option( 'codisto_merchantid' );

		if ( ! is_numeric( $merchantid ) ) {

			$email = get_option( 'admin_email' );

			$paypal_settings = get_option( 'woocommerce_paypal_settings' );
			if ( is_array( $paypal_settings ) ) {
				$email = $paypal_settings['email'];
			}

			?>
			<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:500,900,700,400">
			<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">

			<iframe id="dummy-data" frameborder="0" src="https://codisto.com/xpressgriddemo/ebayedit/"></iframe>
			<div id="dummy-data-overlay"></div>
			<div id="create-account-modal">
				<img style="float:right; margin-top:26px; margin-right:15px;" height="30" src="https://codisto.com/images/codistodarkgrey.png">
				<h1>Create your Account</h1>
				<div class="body">
					<form id="codisto-form" action="<?php echo htmlspecialchars( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<p>To get started, enter your email address.</p>
						<p>Your email address and phone number will be used to communicate important account information and to
							provide a better support experience for any enquiries with your Codisto account.</p>

						<?php wp_nonce_field( 'codisto-create' ); ?>
						<input type="hidden" name="action" value="codisto_create"/>
						<input type="hidden" name="method" value="email"/>

						<div>
							<label for="email"><i class="material-icons">email</i></label> <input type="email" id="email" name="email" required placeholder="Enter Your Email Address" size="40">
							<div class="help-text email-help-text" data-defaultmessage="Email is required" data-invalidmessage="Please enter a valid email"></div>
						</div>
						<div>
							<label for="emailconfirm"><i class="material-icons">email</i></label> <input type="email" id="emailconfirm" name="emailconfirm" required placeholder="Confirm Your Email Address" size="40">
							<div class="help-text emailconfirm-help-text" data-defaultmessage="Confirm Email is required" data-invalidmessage="Please enter a valid confirm email"></div>
						</div>

						<div>
							<label for="phone"><i class="material-icons">phone_in_talk</i></label> <input type="tel" id="phone" name="phone" required placeholder="Enter your Phone Number (incl. country code)" size="40">
							<div class="help-text phone-help-text" data-defaultmessage="Phone is required" data-invalidmessage="Please enter a valid phone number"></div>
						</div>

						<div class="selection">
							<label for="countrycode"><i class="material-icons">language</i></label> <div class="select-html-wrapper"></div>
							<br/>
							This is important for creating your initial store defaults.
							<br/>
							<br/>
						</div>

						<div class="next">
							<button type="submit" class="button btn-lg">Continue <i class="material-icons">keyboard_arrow_right</i></button>
						</div>
						<div class="error-message">
							<strong>Your email addresses do not match.</strong>
						</div>

					</form>
				</div>
				<div class="footer">
					Once you create an account we will begin synchronizing your catalog data.<br>
					Sit tight, this may take several minutes depending on the size of your catalog.<br>
					When completed, you'll have the world's best eBay & Amazon integration at your fingertips.<br>
				</div>

			</div>

			<?php

		} else {

			?>
			<div id="codisto-container">
				<iframe class="<?php echo $tabclass ?>" src="<?php echo htmlspecialchars( $url )?>" frameborder="0"></iframe>
			</div>
			<?php

		}
	}

	/**
	* renders the 'home' tab
	*
	*/
	public function ebay_tab() {
		$adminUrl = admin_url( 'codisto/ebaytab/0/'.get_option( 'codisto_merchantid' ).'/' );

		$this->admin_tab( $adminUrl, 'codisto-bulk-editor' );
	}

	/**
	* renders the 'listings' tab
	*
	*/
	public function listings() {
		$adminUrl = admin_url( 'codisto/ebaytab/0/'.get_option( 'codisto_merchantid' ).'/listings/' );

		$this->admin_tab( $adminUrl, 'codisto-bulk-editor' );
	}

	/**
	* renders the 'analytics' tab
	*
	*/
	public function analytics() {
		$adminUrl = admin_url( 'codisto/ebaytab/0/'.get_option( 'codisto_merchantid' ).'/analytics/' );

		$this->admin_tab( $adminUrl, 'codisto-bulk-editor' );
	}

	/**
	* renders the 'orders' tab
	*
	*/
	public function orders() {
		$adminUrl = admin_url( 'codisto/ebaytab/0/'.get_option( 'codisto_merchantid' ).'/orders/' );

		$this->admin_tab( $adminUrl, 'codisto-bulk-editor' );
	}

	/**
	* renders the 'account' tab
	*
	*/
	public function account() {
		$adminUrl = admin_url( 'codisto/ebaytab/0/'.get_option( 'codisto_merchantid' ).'/account/' );

		$this->admin_tab( $adminUrl, 'codisto-account' );
	}

	/**
	* renders the 'settings' tab
	*
	*/
	public function settings() {

		$adminUrl = admin_url( 'codisto/settings/' );

		$this->admin_tab( $adminUrl, 'codisto-settings' );
	}


	/**
	* implements the templates link
	*
	*/
	public function templates() {
		include 'templates.php';
	}

	/**
	* renders support message for multisite instances
	*
	*/
	public function multisite() {
		include 'multisite.php';
	}

	/**
	* admin_menu hook handler used to add the codisto menu entries to the
	* wordpress admin menu
	*
	*/
	public function admin_menu() {

		if ( current_user_can( 'manage_woocommerce' ) ) {

			$mainpage = 'codisto';
			$type = 'ebay_tab';

			add_menu_page( __( 'Channel Cloud', 'codisto-linq' ), __( 'Channel Cloud', 'codisto-linq' ), 'edit_posts', $mainpage, array( $this, $type ), 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgd2lkdGg9IjE4cHgiIGhlaWdodD0iMThweCIgdmlld0JveD0iMCAwIDE3MjUuMDAwMDAwIDE3MjUuMDAwMDAwIiBwcmVzZXJ2ZUFzcGVjdFJhdGlvPSJ4TWlkWU1pZCBtZWV0Ij4gPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMC4wMDAwMDAsMTcyNS4wMDAwMDApIHNjYWxlKDAuMTAwMDAwLC0wLjEwMDAwMCkiIGZpbGw9IiMwMDAwMDAiIHN0cm9rZT0ibm9uZSI+PHBhdGggZD0iTTgzNDAgMTcyNDAgYy0yMTk1IC03MCAtNDI1MyAtOTYyIC01ODEwIC0yNTIwIC0xNDYzIC0xNDYyIC0yMzM3IC0zMzU5IC0yNTAwIC01NDI1IC0yOSAtMzYyIC0yOSAtOTcwIDAgLTEzMzUgMTM4IC0xNzc0IDgwNyAtMzQzOCAxOTMxIC00ODA1IDM1MyAtNDMxIDc4NCAtODYwIDEyMTQgLTEyMTEgMTM2MiAtMTExMiAzMDIzIC0xNzc3IDQ3ODAgLTE5MTQgMzczIC0yOSA5NjAgLTI5IDEzMzUgMCAxNzU3IDEzNSAzNDIyIDgwMSA0Nzg1IDE5MTQgNDU0IDM3MCA5MDYgODI3IDEyNzcgMTI4OCAxNDcgMTgyIDMzNiA0NDEgNDcxIDY0NSAxMTUgMTc0IDMxNyA1MDcgMzE3IDUyMyAwIDYgMyAxMCA4IDEwIDQgMCAxMiAxMCAxOCAyMyAxOCAzOSA4OSAxNzIgOTQgMTc3IDUgNSA3NiAxNDggMTY5IDMzOSAyMyA0NiA0MSA5MCA0MSA5OCAwIDcgNSAxMyAxMCAxMyA2IDAgMTAgNyAxMCAxNSAwIDggNCAyMyAxMCAzMyA1IDkgMjEgNDQgMzUgNzcgMTUgMzMgMzAgNjggMzYgNzcgNSAxMCA5IDIyIDkgMjggMCA1IDEyIDM1IDI2IDY3IDQ3IDEwNSA1NCAxMjQgNTQgMTM4IDAgOCAzIDE1IDggMTUgNCAwIDE1IDI4IDI2IDYzIDEwIDM0IDI0IDcxIDMxIDgyIDcgMTEgMTYgMzYgMjAgNTUgNCAxOSAxMSA0MCAxNSA0NSA0IDYgMTEgMjYgMTUgNDUgNCAxOSAxMSAzNyAxNCA0MCA3IDUgMjEgNTAgNTcgMTgwIDkgMzAgMTkgNjAgMjQgNjUgNCA2IDE1IDQ0IDI0IDg1IDEwIDQxIDIxIDc5IDI2IDg1IDExIDEzIDEzMSA1MzEgMTY5IDcyNSAxNjMgODQ5IDE5OCAxNzY0IDEwMCAyNjMwIC0yNjMgMjMyOSAtMTQ2OCA0NDQ2IC0zMzQ5IDU4ODIgLTczMyA1NTkgLTE1ODcgMTAxMiAtMjQ2NSAxMzA2IC03NjQgMjU3IC0xNjAwIDQxMSAtMjM2NSA0MzcgLTMyMSAxMSAtNDQyIDEyIC02NzAgNXogbS0yOTI1IC0yNjYwIGM2NzEgLTQxIDEyMTQgLTIzMCAxNjk0IC01OTAgNDg1IC0zNjQgODI1IC03NjYgMTY1NiAtMTk2NSAyNzggLTQwMSA5NjggLTE0NDggMTEyMCAtMTcwMCAyMTcgLTM1OCA0MjcgLTg0NCA1MTEgLTExNzUgMTE0IC00NTUgMTEyIC03OTYgLTEwIC0xNDEwIC0zOSAtMTk5IC0xNTAgLTU0MCAtMjQzIC03NDYgLTEyNCAtMjc2IC0yOTQgLTU0NSAtNDkyIC03NzcgLTQyIC00OSAtODggLTEwMiAtMTAyIC0xMTkgbC0yNSAtMzAgLTQxMyA2MjIgLTQxMiA2MjIgMzQgODIgYzU1IDEzMCAxMTggMzY3IDE1MyA1ODEgMjUgMTU1IDE1IDU0MCAtMjAgNzMxIC05MyA1MDkgLTI5NiA5MDcgLTEwMDcgMTk3NCAtNTU3IDgzNiAtMTAyMCAxNDU4IC0xMzUxIDE4MTMgLTQ0NyA0ODAgLTk2NSA3MDUgLTE1MzYgNjY3IC03NzEgLTUxIC0xNDM2IC00ODcgLTE3ODYgLTExNzAgLTk3IC0xODggLTE2MiAtMzg1IC0xODUgLTU2MyAtMjcgLTE5NCAtOSAtNTA2IDQwIC03MDIgMTA5IC00MzcgMzk0IC05NjIgMTA3NSAtMTk3MiA4MjQgLTEyMjQgMTI1MyAtMTc2NiAxNjcyIC0yMTExIDE5MSAtMTU4IDQwMSAtMjYwIDY4NyAtMzM1IGw5MCAtMjMgMTM4IC0yMDUgYzc3IC0xMTIgMjg1IC00MjEgNDYzIC02ODYgbDMyNCAtNDgxIC02MyAtMTEgYy00NjcgLTgxIC05MDggLTc3IC0xMzUzIDE0IC03NDMgMTUzIC0xMzQ5IDUzNSAtMTkxNCAxMjA5IC0yODggMzQ0IC04MzkgMTExMiAtMTQ0MSAyMDExIC01MDEgNzQ3IC03MzQgMTEzOSAtOTEyIDE1MzUgLTE1NiAzNDUgLTIzNCA2MDQgLTI4MyA5NDUgLTIxIDE0MyAtMjQgNTA1IC02IDY2MCAxMzQgMTEyNiA2ODcgMjAxNyAxNjUyIDI2NjAgNjQ4IDQzMiAxMjU0IDYyNyAyMDIwIDY1MyAzMCAxIDEzMiAtMyAyMjUgLTh6IG00ODYwIC0yNjMwIGM2NzEgLTQxIDEyMTQgLTIzMCAxNjk0IC01OTAgMzUzIC0yNjQgNjM0IC01NjAgMTAxMyAtMTA2NSAzMjEgLTQyOSAxMjE3IC0xNzIxIDEyMTAgLTE3NDYgLTEgLTUgNzggLTEyNSAxNzYgLTI2NyAyNTggLTM3MyAzMzggLTQ5OSA1MTUgLTgxMyAxMTEgLTE5NyAyMzggLTQ3NSAyODcgLTYyOSA0NSAtMTQwIDcxIC0yMjkgNzYgLTI1NCAzIC0xNyAxNCAtNjYgMjUgLTEwOCA1NiAtMjIyIDg0IC01MTQgNzQgLTc2MyAtNCAtODggLTkgLTE2MiAtMTAgLTE2NSAtMyAtNSAtMTUgLTkwIC0zMSAtMjE1IC0zIC0yNyAtMTAgLTcwIC0xNSAtOTUgLTUgLTI1IC0xNCAtNzAgLTE5IC0xMDAgLTMzIC0xNjkgLTE3MSAtNjIwIC0xOTAgLTYyMCAtNSAwIC0xMSAtMTQgLTE1IC0zMCAtNCAtMTcgLTExIC0zMCAtMTYgLTMwIC02IDAgLTggLTMgLTYgLTcgMyAtNSAtMyAtMjQgLTEzIC00MyAtMTEgLTE5IC0yNCAtNDYgLTMwIC02MCAtMTkgLTQ0IC04NSAtMTc1IC05MCAtMTgwIC0zIC0zIC0xMyAtMjEgLTIzIC00MCAtMzQgLTYzIC01MiAtOTUgLTU4IC0xMDAgLTMgLTMgLTE0IC0yMSAtMjUgLTQwIC0xMCAtMTkgLTIxIC0zNyAtMjQgLTQwIC0zIC0zIC0zMSAtNDMgLTYyIC05MCAtNjIgLTk0IC0yNDQgLTMxMiAtMzYxIC00MzQgLTQzIC00NCAtNzcgLTgzIC03NyAtODcgMCAtNCAtNDggLTUwIC0xMDcgLTEwMyAtNTIwIC00NjIgLTExMjUgLTc4OCAtMTcyOCAtOTMxIC02NTggLTE1NiAtMTM2NSAtMTEzIC0yMDA0IDEyMyAtNTAxIDE4NCAtOTg3IDU0OSAtMTQyMSAxMDY2IC0zMTQgMzc0IC03NTYgOTk1IC0xNDQxIDIwMjMgLTQ4NCA3MjcgLTU5MyA4OTkgLTc0NyAxMTg4IC0yNTYgNDgwIC0zODUgODQ5IC00NDggMTI4MCAtMjEgMTQzIC0yNCA1MDUgLTYgNjYwIDg1IDcxMyAzNDAgMTMzNiA3NTggMTg1MiAxMjQgMTUzIDIwMSAyNDAgMjA3IDIzMyAyIC0zIDE4MSAtMjc2IDM5NyAtNjA4IGwzOTMgLTYwNCAtMjIgLTM2IGMtOTIgLTE1NiAtMTY4IC0zMzMgLTIxMCAtNDk0IC00MSAtMTU0IC01MSAtMjQxIC01MSAtNDM1IDAgLTQ2MSAxMjYgLTgyMCA1MjAgLTE0ODQgMzQgLTU3IDY1IC0xMDYgNjkgLTEwOSAzIC0zIDEyIC0xNyAxOCAtMzEgMjcgLTYzIDQ1OCAtNzI0IDcwNiAtMTA4NCA3MjggLTEwNTkgMTA5NiAtMTUxMyAxNDg1IC0xODMzIDE1OSAtMTMxIDMyNSAtMjIwIDU0MSAtMjkxIDIyOSAtNzYgMjkzIC04NSA1NzEgLTg2IDIxMiAwIDI2MSAzIDM2MSAyMyAzMTEgNTkgNTg4IDE3MCA4MzEgMzMzIDE0NiA5OSAzMjUgMjU3IDQ0MCAzOTAgODcgMTAwIDE2OCAyMDQgMTY4IDIxNSAwIDMgMTAgMjAgMjMgMzcgODggMTI0IDIxMSA0MDggMjUyIDU4NCA5IDM3IDIwIDg0IDI1IDEwNCAzNSAxMzEgNDEgNDgxIDEwIDYzNyAtMjYgMTMxIC0xMDIgMzQ3IC0xODggNTMyIC0xNiAzNiAtNDggMTA0IC03MCAxNTEgLTc2IDE2NCAtMzYxIDYzOCAtNDE5IDY5NiAtMTIgMTMgLTgxIDExNiAtMTU0IDIzMCAtNDYxIDcyNiAtMTEwMiAxNjI2IC0xNDc5IDIwNzggLTQxNSA0OTggLTc3NSA3NDYgLTEyMjkgODQ5IC02MiAxMyAtMTE0IDI2IC0xMTYgMjggLTIzIDI4IC04NTUgMTM1MSAtODUyIDEzNTUgMTggMTcgMzIyIDYxIDQ5NyA3MiAxOTcgMTIgMjI4IDEyIDQxNSAxeiIvPiA8L2c+IDwvc3ZnPg==', '55.501' );

			$pages = array();

			$pages[] = add_submenu_page( 'codisto', __( 'Home', 'codisto-linq' ), __( 'Home', 'codisto-linq' ), 'edit_posts', 'codisto', array( $this, 'ebay_tab' ) );
			$pages[] = add_submenu_page( 'codisto', __( 'Listings', 'codisto-linq' ), __( 'Listings', 'codisto-linq' ), 'edit_posts', 'codisto-listings', array( $this, 'listings' ) );
			$pages[] = add_submenu_page( 'codisto', __( 'Orders', 'codisto-linq' ), __( 'Orders', 'codisto-linq' ), 'edit_posts', 'codisto-orders', array( $this, 'orders' ) );
			$pages[] = add_submenu_page( 'codisto', __( 'Analytics', 'codisto-linq' ), __( 'Analytics', 'codisto-linq' ), 'edit_posts', 'codisto-analytics', array( $this, 'analytics' ) );
			$pages[] = add_submenu_page( 'codisto', __( 'Settings', 'codisto-linq' ), __( 'Settings', 'codisto-linq' ), 'edit_posts', 'codisto-settings', array( $this, 'settings' ) );
			$pages[] = add_submenu_page( 'codisto', __( 'Account', 'codisto-linq' ), __( 'Account', 'codisto-linq' ), 'edit_posts', 'codisto-account', array( $this, 'account' ) );
			$pages[] = add_submenu_page( 'codisto', __( 'eBay Templates', 'codisto-linq' ), __( 'eBay Templates', 'codisto-linq' ), 'edit_posts', 'codisto-templates', array( $this, 'templates' ) );

		}
	}

	/**
	* admin_body_class hook handler used to add a class to the page body
	* to perform specific styling - mostly of the embedded iframe for proxied
	* content
	*
	* @param array $classes the set of classes to be applied to the body
	* @return array the classes array mutated in the function passed as input
	*/
	public function admin_body_class( $classes ) {
		if ( isset($_GET['page'] ) ) {
			$page = wp_unslash( $_GET['page'] );

			if ( substr( $page, 0, 7 ) === 'codisto' ) {
				if ( $page === 'codisto' ) {
					return "$classes codisto";
				} elseif ( $page === 'codisto-templates' ) {
					return "$classes $page";
				} elseif ( $page === 'codisto-multisite' ) {
					return "$classes $page";
				}

				return "$classes codisto $page";
			}
		}

		return $classes;
	}

	/**
	* admin_scripts hook used to apply the codisto admin css+js
	*
	* @param string $hook the top level plugin page
	*/
	public function admin_scripts( $hook ) {

		if ( preg_match ( '/codisto(?:-orders|-categories|-attributes|-import|-templates|-settings|-account|-listings|-analytics|)$/', $hook ) ) {

			wp_enqueue_style( 'codisto-style' );
			wp_enqueue_script( 'codisto-script' );

		}

	}

	/**
	* woocommerce_product_bulk_edit_save hook handler
	* used to notify bulk changes to products to codisto
	*
	* @param object $product object being bulk saved
	*/
	public function bulk_edit_save( $product ) {

		if ( ! $this->ping ) {
			$this->ping = array();
		}

		if ( ! isset($this->ping['products'] ) ) {
			$this->ping['products'] = array();
		}

		$pingProducts = $this->ping['products'];

		if ( ! in_array( $product->id, $pingProducts ) ) {
			$pingProducts[] = $product->id;
		}

		$this->ping['products'] = $pingProducts;
	}

	/**
	* woocommerce_admin_settings_sanitize_option_woocommerce_currency hook handler
	* used to notify changes to currency setting to codisto
	*
	* @param string $value currency value that is being set
	* @return string the value input unchanged
	*/
	public function option_save( $value ) {

		if ( ! $this->ping ) {
			$this->ping = array();
		}

		return $value;
	}

	/**
	* save_post hook handler used to notify changes to products to codisto
	*
	* @param integer $id of the product
	* @param object $post object that represents the post (which is checked to be a product)
	*/
	public function product_save( $id, $post ) {

		if ( $post->post_type == 'product' ) {
			if ( ! $this->ping ) {
				$this->ping = array();
			}

			if ( ! isset($this->ping['products'] ) ) {
				$this->ping['products'] = array();
			}

			$pingProducts = $this->ping['products'];

			if ( ! in_array( $id, $pingProducts ) ) {
				$pingProducts[] = $id;
			}

			$this->ping['products'] = $pingProducts;
		}
	}

	/**
	* woocommerce_reduce_order_stock hook handler used to notify stock changes
	* to codisto
	*
	* @param object $order object that is having it's contained orders stock reduced
	*/
	public function order_reduce_stock( $order ) {

		$product_ids = array();

		foreach ( $order->get_items() as $item ) {
			if ( $item['product_id'] > 0 ) {
				if ( is_string( get_post_status( $item['product_id'] ) ) ) {
					$product_ids[] = $item['product_id'];
				}
			}
		}

		if ( count( $product_ids ) > 0) {
			if ( ! $this->ping ) {
				$this->ping = array();
			}

			if ( ! isset( $this->ping['products'] ) ) {
				$this->ping['products'] = array();
			}

			$pingProducts = $this->ping['products'];

			foreach ( $product_ids as $id ) {
				if ( ! in_array( $id, $pingProducts ) ) {
					$pingProducts[] = $id;
				}
			}

			$this->ping['products'] = $pingProducts;
		}
	}

	/**
	* takes collected set of signals during post handling and transmits to codisto
	*
	* this runs within the shutdown hook to avoid standard stalling admin processing
	*/
	public function signal_edits() {

		if ( is_array( $this->ping ) &&
			isset( $this->ping['products'] ) ) {

			$response = wp_remote_post(
				'https://api.codisto.com/'.get_option( 'codisto_merchantid' ),
				array(
					'method'		=> 'POST',
					'timeout'		=> 5,
					'redirection' => 0,
					'httpversion' => '1.0',
					'blocking'	=> true,
					'headers'		=> array( 'X-HostKey' => get_option( 'codisto_key' ) , 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'		=> 'action=sync&productid=['.implode( ',', $this->ping['products'] ).']'
				)
			);

		} elseif (is_array( $this->ping ) ) {

			$response = wp_remote_post(
				'https://api.codisto.com/'.get_option( 'codisto_merchantid' ),
				array(
					'method'		=> 'POST',
					'timeout'		=> 5,
					'redirection' => 0,
					'httpversion' => '1.0',
					'blocking'	=> true,
					'headers'		=> array( 'X-HostKey' => get_option( 'codisto_key' ) , 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'		=> 'action=sync'
				)
			);

		}
	}

	/**
	* emits site verification tags
	*
	*/
	public function site_verification() {

		$site_verification = get_option('codisto_site_verification');
		if( $site_verification ) {
			echo $site_verification;
		}

	}

	/**
	* enqueues conversion tracking script for 'offsite' advertising campaigns
	*
	*/
	public function conversion_tracking() {

		$upload_dir = wp_upload_dir();
		$conversion_tracking_file = '/codisto/conversion-tracking.js';
		$conversion_tracking_path = $upload_dir['basedir'].$conversion_tracking_file;

		$conversion_tracking = get_option('codisto_conversion_tracking');

		if( $conversion_tracking
			&& file_exists($conversion_tracking_path) ) {

			$conversion_tracking_url = $upload_dir['baseurl'].$conversion_tracking_file;

			wp_enqueue_script( 'codisto-conversion-tracking' , $conversion_tracking_url, array() , $conversion_tracking );
		}

	}

	/***
	* emits conversion information into the checkout completion page
	*
	*/
	public function conversion_emit( $order_id ) {

		$order = new WC_Order( $order_id );

		$conversiondata = 'window.CodistoConversion = { transaction_id : '.$order_id.', value : '.($order->get_total() ? $order->get_total() : 0).', currency : "'.get_woocommerce_currency().'"};';

		wp_add_inline_script( 'codisto-conversion-tracking', $conversiondata );

	}


	/**
	* woocommerce_product_data_tabs hook handler used to render marketplace product tab
	*
	* @param array $tabs current set of tabs for the product page
	* @return array mutated tabs array to render the contained tabs on the woo product page
	*/
	public function add_ebay_product_tab( $tabs ) {

		$tabs['codisto'] = array(
								'label'	=> __( 'Channel Cloud', 'codisto-linq' ),
								'target' => 'codisto_product_data',
								'class'	=> '',
							);

		return $tabs;
	}

	/**
	* woocommerce_product_data_panels hook handler used to render marketplace product info
	*
	*/
	public function ebay_product_tab_content() {

		global $post;

		?>
			<div id="codisto_product_data" class="panel woocommerce_options_panel" style="padding: 8px;">
			<iframe id="codisto-control-panel" style="width: 100%;" src="<?php echo htmlspecialchars( admin_url( '/codisto/ebaytab/product/'. $post->ID ).'/' ); ?>" frameborder="0"></iframe>
			</div>
		<?php
	}

	/**
	* plugin_action_links hook handler to render helpful links in plugin page
	*
	* @param array $links for plugin
	* @return array passed through $links array
	*/
	public function plugin_links( $links ) {

		$action_links = array(
			'listings' => '<a href="' . admin_url( 'admin.php?page=codisto' ) . '" title="'.esc_html__( 'Manage Google, Amazon, eBay & Walmart Listings', 'codisto-linq' ).'">'.esc_html__( 'Manage Google, Amazon, eBay & Walmart Listings', 'codisto-linq' ).'</a>',
			'settings' => '<a href="' . admin_url( 'admin.php?page=codisto-settings' ) . '" title="'.esc_html__( 'Codisto Settings', 'codisto-linq' ).'">'.esc_html__( 'Settings', 'codisto-linq' ).'</a>'
		);

		return array_merge( $action_links, $links );
	}

	/**
	* admin_notices hook handler to render post installation transient notice
	*
	*/
	function admin_notice_info() {

		if ( get_transient( 'codisto-admin-notice' ) ){
			$class = 'notice notice-info is-dismissible';
			printf( '<div class="%1$s"><p>'.esc_html__( 'Codisto LINQ Successfully Activated!', 'codisto-linq' ).' '.
			wp_kses(
				__('<a class="button action" href="admin.php?page=codisto">Click here</a> to get started.' ),
				array(
					'a' => array(
						'class' => array(),
						'href' => array()
					)
				)
			).'</p></div>', esc_attr( $class ) );
		}
	}

	/**
	* plugin initialisation
	*
	*/
	public function init_plugin() {

		$homeUrl = preg_replace( '/^https?:\/\//', '', trim( home_url() ) );
		$siteUrl = preg_replace( '/^https?:\/\//', '', trim( site_url() ) );
		$adminUrl = preg_replace( '/^https?:\/\//', '', trim( admin_url() ) );

		$syncUrl = str_replace( $homeUrl, '', $siteUrl );
		$syncUrl .= ( substr( $syncUrl, -1 ) == '/' ? '' : '/' );

		// synchronisation end point
		add_rewrite_rule(
			'^'.preg_quote( ltrim( $syncUrl, '/' ), '/' ).'codisto-sync\/(.*)?',
			'index.php?codisto=sync&codisto-sync-route=$matches[1]',
			'top' );

		$adminUrl = str_replace( $homeUrl, '', $adminUrl );
		$adminUrl .= ( substr( $adminUrl, -1 ) == '/' ? '' : '/' );

		// proxy end point
		add_rewrite_rule(
			'^'.preg_quote( ltrim( $adminUrl, '/'), '/').'codisto\/(.*)?',
			'index.php?codisto=proxy&codisto-proxy-route=$matches[1]',
			'top'
		);

		wp_register_style( 'codisto-style', plugins_url( 'styles.css', __FILE__ ) );
		wp_register_script( 'codisto-script', plugins_url( 'admin.js', __FILE__ ) );

		add_filter( 'query_vars', 							array( $this, 'query_vars' ) );
		add_filter( 'nocache_headers',						array( $this, 'nocache_headers' ) );
		add_action( 'parse_request',						array( $this, 'parse' ), 0 );
		add_action( 'admin_post_codisto_create',			array( $this, 'create_account' ) );
		add_action( 'admin_post_codisto_update_template',	array( $this, 'update_template' ) );
		add_action( 'admin_enqueue_scripts', 				array( $this, 'admin_scripts' ) );
		add_action( 'admin_menu',							array( $this, 'admin_menu' ) );
		add_action( 'admin_notices', 						array( $this, 'admin_notice_info' ) );
		add_filter( 'admin_body_class', 					array( $this, 'admin_body_class' ) );
		add_action(	'woocommerce_product_bulk_edit_save', 	array( $this, 'bulk_edit_save' ) );
		add_action(	'woocommerce_before_product_object_save', 	array( $this, 'product_save' ), 10, 2 );
		add_action( 'save_post',							array( $this, 'product_save' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs',		array( $this, 'add_ebay_product_tab' ) );
		add_action( 'woocommerce_product_data_panels',		array( $this, 'ebay_product_tab_content' ) );
		add_filter( 'wc_order_is_editable',					array( $this, 'order_is_editable' ), 10, 2 );
		add_action( 'woocommerce_reduce_order_stock',		array( $this, 'order_reduce_stock' ) );
		add_filter( 'woocommerce_email_enabled_new_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_cancelled_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_invoice',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_note',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_refunded_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_failed_order',	array( $this, 'inhibit_order_emails' ), 10, 2 );
		add_action(
			'woocommerce_admin_order_data_after_order_details',
			array( $this, 'order_buttons' )
		);
		add_action(
			'woocommerce_admin_settings_sanitize_option_woocommerce_currency',
			array( $this, 'option_save')
		);
		add_filter(
			'plugin_action_links_'.plugin_basename( __FILE__ ),
			array( $this, 'plugin_links' )
		);
		add_action( 'shutdown',								array( $this, 'signal_edits' ) );
		add_action( 'wp_head',								array( $this, 'site_verification' ) );
		add_action( 'wp_enqueue_scripts',					array( $this, 'conversion_tracking' ) );
		add_action( 'woocommerce_thankyou',					array( $this, 'conversion_emit' ) );

	}

	/**
	* static init method for the plugin, registers the activation hook
	* setups up the init_plugin action
	*
	* handles extra kludges to make the sync end point work for various
	* third party extensions
	*
	*/
	public static function init() {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();

			register_activation_hook( __FILE__, array( 'CodistoConnect', 'activate' ) );
			add_action( 'init', array( self::$_instance, 'init_plugin' ) );

			if ( preg_match( '/\/codisto-sync\//', $_SERVER['REQUEST_URI'] ) ) {

				// force aelia currency switcher to
				$_POST['aelia_cs_currency'] = get_option('woocommerce_currency');

			}
		}

		return self::$_instance;
	}

	/**
	* acivation hook handler - used to setup the admin notice as a transient
	* and install rewrite rules for the sync and proxy end points
	*
	*/
	public static function activate() {

		$homeUrl = preg_replace( '/^https?:\/\//', '', trim( home_url() ) );
		$siteUrl = preg_replace( '/^https?:\/\//', '', trim( site_url() ) );
		$adminUrl = preg_replace( '/^https?:\/\//', '', trim( admin_url() ) );

		$syncUrl = str_replace( $homeUrl, '', $siteUrl );
		$syncUrl .= ( substr( $syncUrl, -1 ) == '/' ? '' : '/' );

		// synchronisation end point
		add_rewrite_rule(
			'^'.preg_quote( ltrim( $syncUrl, '/' ), '/' ).'codisto-sync\/(.*)?',
			'index.php?codisto=sync&codisto-sync-route=$matches[1]',
			'top'
		);

		$adminUrl = str_replace( $homeUrl, '', $adminUrl );
		$adminUrl .= ( substr( $adminUrl, -1 ) == '/' ? '' : '/' );

		// proxy end point
		add_rewrite_rule(
			'^'.preg_quote( ltrim( $adminUrl, '/' ), '/' ).'codisto\/(.*)?',
			'index.php?codisto=proxy&codisto-proxy-route=$matches[1]',
			'top'
		);

		set_transient( 'codisto-admin-notice', true, 20 );

		flush_rewrite_rules();

	}
}

endif;

CodistoConnect::init();
