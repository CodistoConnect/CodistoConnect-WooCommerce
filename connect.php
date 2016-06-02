<?php
/**
 * @package MarketPlace Connect by Codisto
 * @version 1.2.26
 */
/*
Plugin Name: MarketPlace Connect by Codisto
Plugin URI: http://wordpress.org/plugins/codistoconnect/
Description: WooCommerce eBay Integration - Convert a WooCommerce store into a fully integrated eBay store in minutes
Author: Codisto
Version: 1.2.26
Author URI: https://codisto.com/
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

include_once( ABSPATH . 'wp-admin/includes/file.php' );
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

define('CODISTOCONNECT_VERSION', '1.2.26');
define('CODISTOCONNECT_RESELLERKEY', '');


if( ! class_exists('CodistoConnect') ) :


final class CodistoConnect {

	private $ping = null;

	protected static $_instance = null;

	public function query_vars($vars)
	{
		$vars[] = 'codisto';
		$vars[] = 'codisto-proxy-route';
		$vars[] = 'codisto-sync-route';
		return $vars;
	}

	public function nocache_headers($headers)
	{
		if(isset($_GET['page']) &&
			substr($_GET['page'], 0, 7) === 'codisto' &&
			$_GET['page'] !== 'codisto-templates')
		{
			$headers = array(
				'Cache-Control' => 'private, max-age=0',
				'Expires' => gmdate( "D, d M Y H:i:s", time() - 300 ) . " GMT"
			);
		}

		return $headers;
	}

	public function check_hash()
	{
		if(!isset($_SERVER['HTTP_X_CODISTONONCE']) ||
			!isset($_SERVER['HTTP_X_CODISTOKEY']))
		{
			status_header('400 Security Error');
			header('Content-Type: application/json');
			header('Cache-Control: no-cache, no-store');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
			header('Pragma: no-cache');
			echo $this->json_encode(array( 'ack' => 'error', 'message' => 'Security Error - Missing Headers' ));
			return false;
		}

		$r = get_option('codisto_key') . $_SERVER['HTTP_X_CODISTONONCE'];
		$base = hash('sha256', $r, true);
		$checkHash = base64_encode($base);
		if(!hash_equals($_SERVER['HTTP_X_CODISTOKEY'], $checkHash))
		{
			status_header('400 Security Error');
			header('Content-Type: application/json');
			header('Cache-Control: no-cache, no-store');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
			header('Pragma: no-cache');
			echo $this->json_encode(array( 'ack' => 'error', 'message' => 'Security Error' ));
			return false;
		}

		return true;
	}

	public function order_set_date($order_data)
	{
		return $order_data;
	}

	private function json_encode($arg)
	{
		if(function_exists('wp_json_encode'))
		{
			return wp_json_encode($arg);
		}
		else if(function_exists('json_encode'))
		{
			return json_encode($arg);
		}
		else
		{
			throw new Exception('PHP missing json library - please upgrade php or wordpress');
		}
	}

	private function get_product($id)
	{
		if(function_exists('wc_get_product'))
		{
			return wc_get_product($id);
		}
		else if(function_exists('get_product'))
		{
			return get_product($id);
		}
		else
		{
			throw new Exception('WooCommerce wc_get_product function is missing - please reinstall or activate WooCommerce');
		}
	}

	private function files_in_dir($dir, $prefix = '')
	{
		$dir = rtrim($dir, '\\/');
		$result = array();

		try
		{
			if(is_dir($dir))
			{
				$scan = @scandir($dir);

				if($scan !== false)
				{
					foreach ($scan as $f) {
						if ($f !== '.' and $f !== '..') {
							if (is_dir("$dir/$f")) {
								$result = array_merge($result, $this->files_in_dir("$dir/$f", "$f/"));
							} else {
								$result[] = $prefix.$f;
							}
						}
					}
				}
			}
		}
		catch(Exception $e)
		{

		}

		return $result;
	}

	public function sync()
	{
		global $wp;
		global $wpdb;

		set_time_limit(0);

		@ini_set('display_errors', '1');

		@ini_set('zlib.output_compression', 'Off');
		@ini_set('output_buffering', 'Off');
		@ini_set('output_handler', '');

		if( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) )))
		{
			status_header('500 Config Error');
			header('Content-Type: application/json');
			header('Cache-Control: no-cache, no-store');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
			header('Pragma: no-cache');
			echo $this->json_encode(array( 'ack' => 'failed', 'message' => 'WooCommerce Deactivated' ));
			exit();
		}

		$type = $wp->query_vars['codisto-sync-route'];
		if(strtolower($_SERVER['REQUEST_METHOD']) == 'get')
		{
			if($type == 'test' ||
				($type == 'sync' && preg_match('/\/sync\/testHash\?/', $_SERVER['REQUEST_URI'])))
			{
				if(!$this->check_hash())
				{
					exit();
				}

				if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
				{
					@ini_set('zlib.output_compression_level', 9);
					@ob_start("ob_gzhandler");
				}
				status_header('200 OK');
				header('Content-Type: application/json');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $this->json_encode(array( 'ack' => 'ok' ));
			}
			else if($type === 'settings')
			{
				if(!$this->check_hash())
				{
					exit();
				}

				$logo_url = get_header_image();

				if(function_exists('site_logo'))
				{
					$logo = site_logo()->logo;
					$logo_id = get_theme_mod( 'custom_logo' );
					$logo_id = $logo_id ? $logo_id : $logo['id'];

					if($logo_id)
					{
						$logo_url = wp_get_attachment_image_src($logo_id, 'full');
						$logo_url = $logo_url[0];
					}
				}

				$currency = get_option('woocommerce_currency');

				$dimension_unit = get_option('woocommerce_dimension_unit');

				$weight_unit = get_option('woocommerce_weight_unit');

				$default_location = explode(':', get_option('woocommerce_default_country'));

				$country_code = isset($default_location[0]) ? $default_location[0] : '';
				$state_code = isset($default_location[1]) ? $default_location[1] : '';

				$response = array( 'ack' => 'ok',
					'logo' => $logo_url,
					'currency' => $currency,
					'dimension_unit' => $dimension_unit,
					'weight_unit' => $weight_unit,
				 	'country_code' => $country_code,
				 	'state_code' => $state_code );

				if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
				{
					@ini_set('zlib.output_compression_level', 9);
					@ob_start("ob_gzhandler");
				}
				status_header('200 OK');
				header('Content-Type: application/json');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $this->json_encode($response);
			}
			else if($type === 'tax')
			{
				if(!$this->check_hash())
				{
					exit();
				}

				$rates = $wpdb->get_results("SELECT tax_rate_country AS country, tax_rate_state AS state, tax_rate AS rate, tax_rate_name AS name, tax_rate_class AS class, tax_rate_order AS sequence, tax_rate_priority AS priority FROM `{$wpdb->prefix}woocommerce_tax_rates` ORDER BY tax_rate_order");

				$response = array( 'ack' => 'ok', 'tax_rates' => $rates );

				if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
				{
					@ini_set('zlib.output_compression_level', 9);
					@ob_start("ob_gzhandler");
				}
				status_header('200 OK');
				header('Content-Type: application/json');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $this->json_encode($response);
			}
			else if($type === 'products')
			{
				if(!$this->check_hash())
				{
					exit();
				}

				$page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
				$count = isset($_GET['count']) ? (int)$_GET['count'] : 0;

				$product_ids = isset($_GET['product_ids']) ? json_decode( wp_unslash( $_GET['product_ids'] ) ) : null;

				if(!is_null($product_ids))
				{
					if(!is_array($product_ids))
						$product_ids = array($product_ids);

					$product_ids = array_filter($product_ids, create_function('$v', 'return is_numeric($v);'));

					if(!isset($_GET['count']))
					{
						$count = count($product_ids);
					}
				}

				$products = $wpdb->get_results( $wpdb->prepare(
						"SELECT id AS id ".
						"FROM `{$wpdb->prefix}posts` AS P ".
						"WHERE post_type = 'product' ".
						"		AND post_status IN ('publish', 'future', 'pending', 'private') ".
						"	".(is_array($product_ids) ? 'AND id IN ('.implode(',', $product_ids).')' : '')."".
						"ORDER BY ID LIMIT %d, %d",
					$page * $count,
					$count
				));

				if(!is_array($product_ids) &&
						$page === 0)
				{
					$total_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}posts` WHERE post_type = 'product' AND post_status IN ('publish', 'future', 'pending', 'private')");
				}

				$acf_installed = function_exists('acf');

				foreach($products as $product)
				{
					$wc_product = $this->get_product($product->id);

					$categoryproduct = $wc_product->get_categories();

					$product->sku = $wc_product->get_sku();
					$product->name = html_entity_decode(apply_filters( 'woocommerce_product_title', $wc_product->post->post_title, $wc_product ), ENT_COMPAT | ENT_HTML401, 'UTF-8');
					$product->enabled = $wc_product->is_purchasable() && ($wc_product->managing_stock() || $wc_product->is_in_stock());
					$product->price = $wc_product->get_price_excluding_tax();
					$product->listprice = floatval($wc_product->get_regular_price());
					$product->is_taxable = $wc_product->is_taxable();
					$product->tax_class = $wc_product->get_tax_class();
					$product->stock_control = $wc_product->managing_stock();
					$product->stock_level = $wc_product->get_stock_quantity();
					if( method_exists( $wc_product, 'get_type' ) )
					{
						$product->type = $wc_product->get_type();
					}
					else
					{
						$product->type = $wc_product->product_type;
					}
					$product->description = apply_filters('the_content', $wc_product->post->post_content);
					$product->short_description = apply_filters('the_content', $wc_product->post->post_excerpt);

					if( method_exists( $wc_product, 'get_width' ) )
					{
						$product->width = $wc_product->get_width();
						if(!is_numeric($product->width))
							unset($product->width);
						$product->height = $wc_product->get_height();
						if(!is_numeric($product->height))
							unset($product->height);
						$product->length = $wc_product->get_length();
						if(!is_numeric($product->length))
							unset($product->length);
					}
					else
					{
						$product->length = $wc_product->length;
						$product->width = $wc_product->width;
						$product->height = $wc_product->height;
					}

					$product->weight = $wc_product->get_weight();
					if(!is_numeric($product->weight))
						unset($product->weight);

					if($product->is_taxable && 'yes' === get_option( 'woocommerce_prices_include_tax' ))
					{
						$tax_rates = WC_Tax::get_base_tax_rates( $product->tax_class );
						$taxes = WC_Tax::calc_tax( $product->listprice , $tax_rates, true );
						$product->listprice = $product->listprice - array_sum( $taxes );
					}

					if($product->type == 'variable')
					{
						$product->skus = array();

						foreach($wc_product->get_children() as $child_id)
						{
							$child_product = $wc_product->get_child($child_id);

							$img = wp_get_attachment_image_src($child_product->get_image_id(), 'full');
							$img = $img[0];

							$child_product_data = array(
												'id' => $child_id,
												'sku' => $child_product->get_sku(),
												'enabled' => $wc_product->is_purchasable() && ($wc_product->managing_stock() || $wc_product->is_in_stock()),
												'price' => $child_product->get_price_excluding_tax(),
												'listprice' => $child_product->get_regular_price(),
												'is_taxable' => $child_product->is_taxable(),
												'tax_class' => $child_product->get_tax_class(),
												'stock_control' => $child_product->managing_stock(),
												'stock_level' => $child_product->get_stock_quantity(),
												'images' => array(array( 'source' => $img, 'sequence' => 0))
											);

							$attributes = array();

							foreach($child_product->get_variation_attributes() as $name => $value)
							{
								$name = preg_replace('/^attribute_pa_/', '', $name);

								$attributes[] = array( 'name' => $name, 'value' => $value );
							}

							$child_product_data['attributes'] = $attributes;

							$product->skus[] = $child_product_data;
						}
					}
					else if($product->type == 'grouped')
					{
						$product->skus = array();

						foreach($wc_product->get_children() as $child_id)
						{
							$child_product = $wc_product->get_child($child_id);

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

					$product_categories = get_the_terms($product->id, 'product_cat');

					if(is_array($product_categories))
					{
						$sequence = 0;
						foreach($product_categories as $category)
						{
							$product->categories[] = array( 'category_id' => $category->term_id, 'sequence' => $sequence );

							$sequence++;
						}
					}

					$image_sequence = 1;
					$product->images = array();

					$imagesUsed = array();

					$primaryimage_path = wp_get_attachment_image_src($wc_product->get_image_id(), 'full');
					$primaryimage_path = $primaryimage_path[0];

					if($primaryimage_path)
					{
						$product->images[] = array( 'source' => $primaryimage_path, 'sequence' => 0 );

						$imagesUsed[$primaryimage_path] = true;

						foreach($wc_product->get_gallery_attachment_ids() as $image_id)
						{
							$image_path = wp_get_attachment_image_src($image_id, 'full');
							$image_path = $image_path[0];

							if(!array_key_exists($image_path, $imagesUsed))
							{
								$product->images[] = array( 'source' => $image_path, 'sequence' => $image_sequence );

								$imagesUsed[$image_path] = true;

								$image_sequence++;
							}
						}
					}

					$product->attributes = array();

					$attributesUsed = array();

					foreach($wc_product->get_attributes() as $attribute)
					{
						if(!$attribute['is_variation'] && !$attribute['is_taxonomy'])
						{
							if(!array_key_exists($attribute['name'], $attributesUsed))
							{
								$attributesUsed[$attribute['name']] = true;

								$product->attributes[] = array( 'name' => $attribute['name'], 'value' => $attribute['value'] );
							}
						}
					}

					foreach(get_post_custom_keys($product->id) as $attribute)
					{
						if(!(substr($attribute, 0, 1) === '_' ||
							substr($attribute, 0, 3) === 'pa_'))
						{
							if(!array_key_exists($attribute, $attributesUsed))
							{
								$attributesUsed[$attribute] = true;

								$value = get_post_meta($product->id, $attribute, false);
								if(is_array($value))
								{
									if(count($value) === 1)
										$value = $value[0];
									else
										$value = implode(',', $value);
								}

								$product->attributes[] = array( 'name' => $attribute, 'value' => $value );
							}
						}
					}

					// acf

					if($acf_installed)
					{
						if( function_exists( 'get_field_objects' ) )
						{
							$fields = get_field_objects( $product->id );
							if( is_array( $fields ) )
							{
								foreach( $fields as $field )
								{
									if( $field['type'] == 'image' )
									{
										$image_path = $field['value']['url'];

										if( !array_key_exists( $image_path, $imagesUsed ) )
										{
											$product->images[] = array( 'source' => $image_path, 'sequence' => $image_sequence );

											$imagesUsed[$image_path] = true;

											$image_sequence++;
										}
									}

									else if( $field['type'] == 'gallery' )
									{
										$gallery = $field['value'];

										if( is_array( $gallery ) )
										{
											foreach( $gallery as $image )
											{
												$image_path = $image['url'];

												if( !array_key_exists( $image_path, $imagesUsed ) )
												{
													$product->images[] = array( 'source' => $image_path, 'sequence' => $image_sequence );

													$imagesUsed[$image_path] = true;

													$image_sequence++;
												}
											}
										}
									}

									else if( in_array( $field['type'],
												array(	'textarea',
														'wysiwyg',
														'text',
														'number',
														'select',
														'radio',
														'checkbox',
														'true_false' ) ) )
									{
										if( !array_key_exists( $field['label'], $attributesUsed ) )
										{
											$attributesUsed[$field['label']] = true;

											$value = $field['value'];
											if( is_array( $value ) )
											{
												if( count( $value ) === 1)
													$value = $value[0];
												else
													$value = implode(',', $value);
											}

											$product->attributes[] = array( 'name' => $field['name'], 'value' => $value );
										}
									}

									if( !$product->description )
									{
										if( in_array( $field['type'], array( 'textarea', 'wysiwyg' ) ) &&
												$field['name'] == 'description' )
										{
											$product->description = $field['value'];
										}
									}

								}
							}
						}
					}
				}

				$response = array( 'ack' => 'ok', 'products' => $products );
				if(isset($total_count))
					$response['total_count'] = $total_count;

				if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
				{
					@ini_set('zlib.output_compression_level', 9);
					@ob_start("ob_gzhandler");
				}
				status_header('200 OK');
				header('Content-Type: application/json');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $this->json_encode($response);
			}
			else if($type === 'categories')
			{
				if(!$this->check_hash())
				{
					exit();
				}

				$categories = get_categories(array( 'taxonomy' => 'product_cat', 'orderby' => 'term_order', 'hide_empty' => 0 ));

				$result = array();

				foreach($categories as $category)
				{
					$result[] = array(
								'category_id' => $category->term_id,
								'name' => $category->name,
								'parent_id' => $category->parent
							);
				}

				$response = array( 'ack' => 'ok', 'categories' => $result, 'total_count' => count($categories));

				if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
				{
					@ini_set('zlib.output_compression_level', 9);
					@ob_start("ob_gzhandler");
				}
				status_header('200 OK');
				header('Content-Type: application/json');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $this->json_encode($response);
			}
			else if($type === 'orders')
			{
				if(!$this->check_hash())
				{
					exit();
				}

				$page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
				$count = isset($_GET['count']) ? (int)$_GET['count'] : 0;

				$orders = $wpdb->get_results( $wpdb->prepare(
					"SELECT (SELECT meta_value FROM `{$wpdb->prefix}postmeta` WHERE post_id = P.id AND meta_key = '_codisto_orderid') AS id, ID AS post_id, post_status AS status FROM `{$wpdb->prefix}posts` AS P WHERE post_type = 'shop_order' AND ID IN (SELECT post_id FROM `{$wpdb->prefix}postmeta` WHERE meta_key = '_codisto_orderid') ORDER BY ID LIMIT %d, %d",
					$page * $count,
					$count
				));

				if($page == 0)
				{
					$total_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}posts` AS P WHERE post_type = 'shop_order' AND ID IN (SELECT post_id FROM `{$wpdb->prefix}postmeta` WHERE meta_key = '_codisto_orderid')");
				}

				$order_data = array();

				foreach($orders as $order)
				{
					$ship_date = get_post_meta( $order->post_id, '_date_shipped', true );
					if($ship_date)
					{
						if(is_numeric($ship_date))
						{
							$ship_date = date('Y-m-d H:i:s', $ship_date);
						}

						$order->ship_date = $ship_date;
					}

					$carrier = get_post_meta( $order->post_id, '_tracking_provider', true);
					if($carrier)
					{
						if($carrier === 'custom')
						{
							$carrier = get_post_meta( $order->post_id, '_custom_tracking_provider', true);
						}

						if($carrier)
						{
							$order->carrier = $carrier;
						}
					}

					$tracking_number = get_post_meta( $order->post_id, '_tracking_number', true);
					if($tracking_number)
					{
						$order->track_number = $tracking_number;
					}

					unset($order->post_id);

					$order_data[] = $order;
				}

				$response = array( 'ack' => 'ok', 'orders' => $order_data );
				if(isset($total_count))
					$response['total_count'] = $total_count;

				if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
				{
					@ini_set('zlib.output_compression_level', 9);
					@ob_start("ob_gzhandler");
				}
				status_header('200 OK');
				header('Content-Type: application/json');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $this->json_encode($response);
			}
			else if($type == 'sync')
			{
				if($_SERVER['HTTP_X_ACTION'] === 'TEMPLATE')
				{
					if(!$this->check_hash())
					{
						exit();
					}

					$ebayDesignDir = WP_CONTENT_DIR . '/ebay/';

					$merchantid = (int)$_GET['merchantid'];
					if(!$merchantid)
						$merchantid = 0;

					$templatedb = get_temp_dir() . '/ebay-template-'.$merchantid.'.db';

					$db = new PDO('sqlite:' . $templatedb);
					$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
					$db->setAttribute(PDO::ATTR_TIMEOUT, 60);

					$db->exec('PRAGMA synchronous=0');
					$db->exec('PRAGMA temp_store=2');
					$db->exec('PRAGMA page_size=65536');
					$db->exec('PRAGMA encoding=\'UTF-8\'');
					$db->exec('PRAGMA cache_size=15000');
					$db->exec('PRAGMA soft_heap_limit=67108864');
					$db->exec('PRAGMA journal_mode=MEMORY');

					$db->exec('BEGIN EXCLUSIVE TRANSACTION');
					$db->exec('CREATE TABLE IF NOT EXISTS File(Name text NOT NULL PRIMARY KEY, Content blob NOT NULL, LastModified datetime NOT NULL, Changed bit NOT NULL DEFAULT -1)');
					$db->exec('COMMIT TRANSACTION');

					if(isset($_GET['markreceived']))
					{
						$update = $db->prepare('UPDATE File SET LastModified = ? WHERE Name = ?');

						$files = $db->query('SELECT Name FROM File WHERE Changed != 0');
						$files->execute();

						$db->exec('BEGIN EXCLUSIVE TRANSACTION');

						while($row = $files->fetch())
						{
							$stat = stat( WP_CONTENT_DIR . '/ebay/'.$row['Name'] );

							$lastModified = strftime('%Y-%m-%d %H:%M:%S', $stat['mtime']);

							$update->bindParam(1, $lastModified);
							$update->bindParam(2, $row['Name']);
							$update->execute();
						}

						$db->exec('UPDATE File SET Changed = 0');
						$db->exec('COMMIT TRANSACTION');
						$db = null;

						header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
						header('Cache-Control', 'no-cache, must-revalidate', true);
						header('Pragma', 'no-cache', true);
						echo $this->json_encode(array( 'ack' => 'ok' ));
						exit;
					}
					else
					{
						$insert = $db->prepare('INSERT OR IGNORE INTO File(Name, Content, LastModified) VALUES (?, ?, ?)');
						$update = $db->prepare('UPDATE File SET Content = ?, Changed = -1 WHERE Name = ? AND LastModified != ?');

						$filelist = $this->files_in_dir( $ebayDesignDir );

						$db->exec('BEGIN EXCLUSIVE TRANSACTION');

						foreach ($filelist as $key => $name)
						{
							try
							{
								$fileName = $ebayDesignDir.$name;

								if(!in_array($name, array('README')))
								{
									$content = @file_get_contents($fileName);
									if($content !== false)
									{
										$stat = stat($fileName);

										$lastModified = strftime('%Y-%m-%d %H:%M:%S', $stat['mtime']);

										$update->bindParam(1, $content);
										$update->bindParam(2, $name);
										$update->bindParam(3, $lastModified);
										$update->execute();

										if($update->rowCount() == 0)
										{
											$insert->bindParam(1, $name);
											$insert->bindParam(2, $content);
											$insert->bindParam(3, $lastModified);
											$insert->execute();
										}
									}
								}
							}
							catch(Exception $e)
							{

							}
						}
						$db->exec('COMMIT TRANSACTION');

						$tmpDb = wp_tempnam();

						$db = new PDO('sqlite:'.$tmpDb);
						$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
						$db->exec('PRAGMA synchronous=0');
						$db->exec('PRAGMA temp_store=2');
						$db->exec('PRAGMA page_size=512');
						$db->exec('PRAGMA encoding=\'UTF-8\'');
						$db->exec('PRAGMA cache_size=15000');
						$db->exec('PRAGMA soft_heap_limit=67108864');
						$db->exec('PRAGMA journal_mode=OFF');
						$db->exec('ATTACH DATABASE \''.$templatedb.'\' AS Source');
						$db->exec('CREATE TABLE File AS SELECT * FROM Source.File WHERE Changed != 0');
						$db->exec('DETACH DATABASE Source');
						$db->exec('VACUUM');

						$fileCountStmt = $db->query('SELECT COUNT(*) AS fileCount FROM File');
						$fileCountStmt->execute();
						$fileCountRow = $fileCountStmt->fetch();
						$fileCount = $fileCountRow['fileCount'];
						$db = null;

						if($fileCount == 0)
						{
							status_header(204, 'No Content');
							header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
							header('Cache-Control: no-cache, must-revalidate');
							header('Pragma: no-cache');
						}
						else
						{
							header('Cache-Control: no-cache, must-revalidate'); //HTTP 1.1
							header('Pragma: no-cache'); //HTTP 1.0
							header('Expires: Thu, 01 Jan 1970 00:00:00 GMT'); // Date in the past
							header('Content-Type: application/octet-stream');
							header('Content-Disposition: attachment; filename=' . basename($tmpDb));

							if(strtolower(ini_get('zlib.output_compression')) == 'off')
							{
								header('Content-Length: ' . filesize($tmpDb));
							}

							while(ob_get_level() > 0)
							{
								if(!@ob_end_clean())
									break;
							}

							flush();

							readfile($tmpDb);
						}
						unlink($tmpDb);
						exit();
					}
				}
			}
		}
		else
		{
			if($type === 'createorder')
			{
				if(!$this->check_hash())
				{
					exit();
				}

				try
				{
					if(preg_match('/(?:^|,|\s)gzip(?:$|,|\s)/', $_SERVER['HTTP_ACCEPT_ENCODING']))
					{
						@ini_set('zlib.output_compression_level', 9);
						@ob_start("ob_gzhandler");
					}

					$xml = simplexml_load_string(file_get_contents('php://input'));

					$ordercontent = $xml->entry->content->children('http://api.codisto.com/schemas/2009/');

					$wpdb->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
					$wpdb->query('START TRANSACTION');

					$billing_address = $ordercontent->orderaddresses->orderaddress[0];
					$shipping_address = $ordercontent->orderaddresses->orderaddress[1];

					$billing_first_name = $billing_last_name = '';
					if(strpos($billing_address->name, ' ') !== false) {
						$billing_name = explode(' ', $billing_address->name, 2);
						$billing_first_name = $billing_name[0];
						$billing_last_name = $billing_name[1];
					} else {
						$billing_first_name = (string)$billing_address->name;
					}

					$billing_country_code = (string)$billing_address->countrycode;
					$billing_division = (string)$billing_address->division;

					$billing_states = WC()->countries->get_states( $billing_country_code );

					if($billing_states)
					{
						$billing_division_match = preg_replace('/\s+/', '', strtolower($billing_division));

						foreach($billing_states as $state_code => $state_name)
						{
							if( preg_replace('/\s+/', '', strtolower($state_name)) == $billing_division_match )
							{
								$billing_division = $state_code;
								break;
							}
						}
					}

					$shipping_first_name = $shipping_last_name = '';
					if(strpos($shipping_address->name, ' ') !== false) {
						$shipping_name = explode(' ', $shipping_address->name, 2);
						$shipping_first_name = $shipping_name[0];
						$shipping_last_name = $shipping_name[1];
					} else {
						$shipping_first_name = (string)$shipping_address->name;
					}

					$shipping_country_code = (string)$shipping_address->countrycode;
					$shipping_division = (string)$shipping_address->division;

					if($billing_country_code === $shipping_country_code)
					{
						$shipping_states = $billing_states;
					}
					else
					{
						$shipping_states = WC()->countries->get_states( $shipping_country_code );
					}

					if($shipping_states)
					{
						$shipping_division_match = preg_replace('/\s+/', '', strtolower($shipping_division));

						foreach($shipping_states as $state_code => $state_name)
						{
							if(preg_replace('/\s+/', '', strtolower($state_name)) == $shipping_division_match )
							{
								$shipping_division = $state_code;
								break;
							}
						}
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

					$email = (string)$billing_address->email;
					if(!$email)
						$email = (string)$shipping_address->email;

					if($email)
					{
						$user = get_user_by('email', $email);
						if(!$user)
						{
							$username = (string)$ordercontent->ebayusername;
							if(!$username)
							{
								$username = current( explode( '@', $email ) );
							}

							if($username)
								$username = sanitize_user($username);

							if(username_exists($username))
							{
								$counter = 1;
								$newusername = $username.$counter;

								while(username_exists($newusername))
								{
									$counter++;
									$newusername = $username.$counter;
								}

								$username = $newusername;
							}

							$password = wp_generate_password();

							$customer_data = apply_filters( 'woocommerce_new_customer_data',
													array(
													'user_login' => $username,
													'user_pass'  => $password,
													'user_email' => $email,
													'role'	   => 'customer'));

							$customer_id = wp_insert_user( $customer_data );

							foreach($address_data as $key => $value)
							{
								update_user_meta($customer_id, $key, $value);
							}

							do_action( 'woocommerce_created_customer', $customer_id, $customer_data, true );
						}
						else
						{
							$customer_id = $user->ID;
						}
					}
					else
					{
						$customer_id = 0;
					}

					$customer_note = @count($ordercontent->instructions) ? strval($ordercontent->instructions) : '';

					$order_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->prefix}posts` AS P WHERE ID IN (SELECT post_id FROM `{$wpdb->prefix}postmeta` WHERE meta_key = '_codisto_orderid' AND meta_value = %d)", (int)$ordercontent->orderid));

					$shipping = 0;
					$shipping_tax = 0;
					$cart_discount = 0;
					$cart_discount_tax = 0;
					$total = (float)$ordercontent->ordertotal;
					$tax = 0;

					if(!$order_id)
					{
						$new_order_data_callback = array( $this, 'order_set_date' );

						add_filter( 'woocommerce_new_order_data', $new_order_data_callback, 1, 1 );

						$order = wc_create_order( array( 'customer_id' => $customer_id, 'customer_note' => $customer_note, 'created_via' => 'eBay' ) );

						remove_filter( 'woocommerce_new_order_data', $new_order_data_callback );

						$order_id = $order->id;

						update_post_meta( $order_id, '_codisto_orderid', (int)$ordercontent->orderid);
						update_post_meta( $order_id, '_codisto_ebayuser', (string)$ordercontent->ebayusername);
						update_post_meta( $order_id, '_order_currency', (string)$ordercontent->transactcurrency);
						update_post_meta( $order_id, '_customer_ip_address', '-' );
						delete_post_meta( $order_id, '_prices_include_tax' );

						do_action( 'woocommerce_new_order', $order_id );

						foreach($ordercontent->orderlines->orderline as $orderline)
						{
							if($orderline->productcode[0] != 'FREIGHT')
							{
								$productcode = (string)$orderline->productcode;
								if($productcode == null)
									$productcode = '';
								$productname = (string)$orderline->productname;
								if($productname == null)
									$productname = '';

								$product_id = $orderline->externalreference[0];
								if($product_id != null)
								{
									$product_id = intval($product_id);
								}

								$variation_id = 0;

								if( get_post_type( $product_id ) === 'product_variation' )
								{
									$variation_id = $product_id;
									$product_id = wp_get_post_parent_id( $variant_id );

									if(!is_numeric($product_id) || $product_id === 0)
									{
										$product_id = 0;
										$variation_id = 0;
									}
								}

								$qty = (int)$orderline->quantity[0];

								$item_id = wc_add_order_item( $order_id, array(

									'order_item_name' => $productname,
									'order_item_type' => 'line_item'

								) );

								wc_add_order_item_meta( $item_id, '_qty', $qty );

								if(!is_null($product_id) && $product_id !== 0)
								{
									wc_add_order_item_meta( $item_id, '_product_id', $product_id );
									wc_add_order_item_meta( $item_id, '_variation_id', $variation_id );
									wc_add_order_item_meta( $item_id, '_tax_class', '' );
								}
								else
								{
									wc_add_order_item_meta( $item_id, '_product_id', 0 );
									wc_add_order_item_meta( $item_id, '_variation_id', 0);
									wc_add_order_item_meta( $item_id, '_tax_class', '' );
								}

								$line_total = wc_format_decimal( (float)$orderline->linetotalinctax );
								$line_total_tax = wc_format_decimal( (float)$orderline->linetotalinctax - (float)$orderline->linetotal );

								wc_add_order_item_meta( $item_id, '_line_subtotal',	 $line_total );
								wc_add_order_item_meta( $item_id, '_line_total',		$line_total );
								wc_add_order_item_meta( $item_id, '_line_subtotal_tax', $line_total_tax );
								wc_add_order_item_meta( $item_id, '_line_tax',		  $line_total_tax );
								wc_add_order_item_meta( $item_id, '_line_tax_data',		array( 'total' => array( 1 => $line_total_tax ), 'subtotal' => array( 1 => $line_total_tax ) ) );

								//do_action( 'woocommerce_order_add_product', $order_id, $item_id, $product, $qty, $args );

								$tax += $line_total_tax;
							}
							else
							{
								$item_id = wc_add_order_item( $order_id, array(
											'order_item_name' 		=> (string)$orderline->productname,
											'order_item_type' 		=> 'shipping'
										) );

								wc_add_order_item_meta( $item_id, 'cost', wc_format_decimal( (float)$orderline->linetotalinctax) );

								$shipping += (real)$orderline->linetotal;
								$shipping_tax += (real)$orderline->linetotalinctax - (real)$orderline->linetotal;
							}
						}

						if($ordercontent->paymentstatus == 'complete')
						{
							$transaction_id = (string)$ordercontent->orderpayments[0]->orderpayment->transactionid;

							if($transaction_id)
							{
								update_post_meta( $order_id, '_payment_method', 'paypal' );
								update_post_meta( $order_id, '_payment_method_title', __( 'PayPal', 'woocommerce' ) );
							}
							else
							{
								update_post_meta( $order_id, '_payment_method', 'bacs' );
								update_post_meta( $order_id, '_payment_method_title', __( 'BACS', 'woocommerce' ) );
							}

							$order->payment_complete($transaction_id);
						}
					}
					else
					{
						$order = wc_get_order( $order_id );

						foreach($ordercontent->orderlines->orderline as $orderline)
						{
							if($orderline->productcode[0] != 'FREIGHT')
							{
								$line_total = wc_format_decimal( (float)$orderline->linetotalinctax );
								$line_total_tax = wc_format_decimal( (float)$orderline->linetotalinctax - (float)$orderline->linetotal );

								$tax += $line_total_tax;
							}
							else
							{
								$order->remove_order_items( 'shipping' );

								$item_id = wc_add_order_item( $order_id, array(
											'order_item_name' 		=> (string)$orderline->productname,
											'order_item_type' 		=> 'shipping'
										) );

								wc_add_order_item_meta( $item_id, 'cost', wc_format_decimal( (float)$orderline->linetotalinctax) );

								$shipping += (real)$orderline->linetotal;
								$shipping_tax += (real)$orderline->linetotalinctax - (real)$orderline->linetotal;
							}
						}
					}

					foreach($address_data as $key => $value)
					{
						update_post_meta($order_id, '_'.$key, $value);
					}

					$order->remove_order_items( 'tax' );
					$order->add_tax( 1, $tax, $shipping_tax );

					$order->set_total( $shipping, 'shipping' );
					$order->set_total( $shipping_tax, 'shipping_tax' );
					$order->set_total( $cart_discount, 'cart_discount' );
					$order->set_total( $cart_discount_tax, 'cart_discount_tax' );
					$order->set_total( $tax, 'tax' );
					$order->set_total( $total, 'total');

					if($ordercontent->orderstate == 'cancelled')
					{
						if(!$order->has_status('cancelled'))
							$order->cancel_order();
					}
					else if($ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing')
					{
						if($ordercontent->paymentstatus == 'complete')
						{
							if(!$order->has_status('processing'))
								$order->update_status('processing');
						}
						else
						{
							if(!$order->has_status('pending'))
								$order->update_status('pending');
						}
					}
					else if($ordercontent->orderstate == 'complete')
					{
						if(!$order->has_status('completed'))
							$order->update_status('completed');
					}

					$wpdb->query('COMMIT');

					$response = array( 'ack' => 'ok', 'orderid' => $order_id );

					status_header('200 OK');
					header('Content-Type: application/json');
					header('Cache-Control: no-cache, no-store');
					header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
					header('Pragma: no-cache');
					echo $this->json_encode($response);
				}
				catch(Exception $e)
				{
					$wpdb->query('ROLLBACK');

					$response = array( 'ack' => 'failed', 'message' => $e->getMessage() .'  '.$e->getFile().' '.$e->getLine()  );

					status_header('200 OK');
					header('Content-Type: application/json');
					header('Cache-Control: no-cache, no-store');
					header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
					header('Pragma: no-cache');
					echo $this->json_encode($response);
				}
			}
			else if($type == 'sync')
			{
				if($_SERVER['HTTP_X_ACTION'] === 'TEMPLATE')
				{
					if(!$this->check_hash())
					{
						exit();
					}

					$ebayDesignDir = WP_CONTENT_DIR . '/ebay/';

					$tmpPath = wp_tempnam();

					@file_put_contents($tmpPath, file_get_contents('php://input'));

					$db = new PDO('sqlite:' . $tmpPath);
					$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

					$db->exec('PRAGMA synchronous=0');
					$db->exec('PRAGMA temp_store=2');
					$db->exec('PRAGMA page_size=65536');
					$db->exec('PRAGMA encoding=\'UTF-8\'');
					$db->exec('PRAGMA cache_size=15000');
					$db->exec('PRAGMA soft_heap_limit=67108864');
					$db->exec('PRAGMA journal_mode=MEMORY');

					$files = $db->prepare('SELECT Name, Content FROM File');
					$files->execute();

					$files->bindColumn(1, $name);
					$files->bindColumn(2, $content);

					while($files->fetch())
					{
						$fileName = $ebayDesignDir.$name;

						if(strpos($name, '..') === false)
						{
							if(!file_exists($fileName))
							{
								$dir = dirname($fileName);

								if(!is_dir($dir))
								{
									mkdir($dir.'/', 0755, true);
								}

								@file_put_contents($fileName, $content);
							}
						}
					}

					$db = null;
					unlink($tmpPath);

					status_header('200 OK');
					header('Content-Type: application/json');
					header('Cache-Control: no-cache, no-store');
					header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
					header('Pragma: no-cache');
					echo $this->json_encode( array( 'ack' => 'ok' ) );
				}
			}
			else if($type == 'index/calc')
			{
				$product_ids = array();
				$quantities = array();

				for($i = 0; ; $i++)
				{
					if(!isset($_POST['PRODUCTCODE('.$i.')']))
						break;

					$productid = (int)$_POST['PRODUCTID('.$i.')'];
					if(!$productid)
					{
						$productcode = $_POST['PRODUCTCODE('.$i.')'];
						$productid = wc_get_product_id_by_sku( $productcode );
					}

					$productqty = $_POST['PRODUCTQUANTITY('.$i.')'];
					if(!$productqty && $productqty != 0)
						$productqty = 1;

					WC()->cart->add_to_cart( $productid, $productqty );

				}

				WC()->customer->set_location($_POST['COUNTRYCODE'], $_POST['DIVISION'], $_POST['POSTALCODE'], $_POST['PLACE']);
				WC()->customer->set_shipping_location($_POST['COUNTRYCODE'], $_POST['DIVISION'], $_POST['POSTALCODE'], $_POST['PLACE']);
				WC()->cart->calculate_totals();
				WC()->cart->calculate_shipping();

				$response = '';

				$idx = 0;
				$methods = WC()->shipping()->get_shipping_methods();
				foreach($methods as $method)
				{
					if(file_exists(plugin_dir_path( __FILE__ ).'shipping/'.$method->id))
					{
						include( plugin_dir_path( __FILE__ ).'shipping/'.$method->id );
					}
					else
					{
						foreach($method->rates as $method => $rate)
						{
							$method_name = $rate->get_label();
							if(!$method_name)
								$method_name = 'Shipping';

							$method_cost = $rate->cost;
							if(is_numeric($method_cost))
							{
								$response .= ($idx > 0 ? '&' : '').'FREIGHTNAME('.$idx.')='.rawurlencode($method_name).'&FREIGHTCHARGEINCTAX('.$idx.')='.number_format((float)$method_cost, 2, '.', '');

								$idx++;
							}
						}
					}
				}

				status_header('200 OK');
				header('Content-Type: text/plain');
				header('Cache-Control: no-cache, no-store');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				header('Pragma: no-cache');
				echo $response;
				die();
			}
		}
	}

	public function order_is_editable($editable, $order)
	{
		$codisto_order_id = get_post_meta( $order->id, '_codisto_orderid', true);
		if(is_numeric($codisto_order_id) && $codisto_order_id !== 0)
		{
			return false;
		}

		return $editable;
	}

	public function order_buttons($order)
	{
		$codisto_order_id = get_post_meta( $order->id, '_codisto_orderid', true);
		if(is_numeric($codisto_order_id) && $codisto_order_id !== 0)
		{
			$ebay_user = get_post_meta($order->id, '_codisto_ebayuser', true);

			?>
			<p class="form-field form-field-wide codisto-order-buttons">
			<a href="<?php echo htmlspecialchars(admin_url('codisto/ebaysale?orderid='.$codisto_order_id)) ?>" target="codisto!sale" class="button"><?php _e('eBay Order') ?> &rarr;</a>
			<a href="<?php echo htmlspecialchars(admin_url('codisto/ebayuser?orderid='.$codisto_order_id)) ?>" target="codisto!user" class="button"><?php _e('eBay User') ?><?php echo $ebay_user ? ' : '.htmlspecialchars($ebay_user) : ''; ?> &rarr;</a>
			</p>
			<?php
		}
	}

	public function proxy()
	{
		global $wp;

		set_time_limit(0);

		@ini_set('zlib.output_compression', 'Off');
		@ini_set('output_buffering', 'Off');
		@ini_set('output_handler', '');

		if(isset($_GET['productid']))
		{
			wp_redirect(admin_url('post.php?post='.urlencode( wp_unslash( $_GET['productid'] ) ).'&action=edit#codisto_product_data'));
			exit;
		}

		$HostKey = get_option('codisto_key');

		if (!function_exists('getallheaders'))
		{
			 function getallheaders()
			 {
				$headers = array();
				foreach ($_SERVER as $name => $value)
				{
					if (substr($name, 0, 5) == 'HTTP_')
					{
						$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
					}
					else if( $name == 'CONTENT_TYPE' )
					{
						$headers['Content-Type'] = $value;
					}
					else if( $name == 'CONTENT_LENGTH' )
					{
						$headers['Content-Length'] = $value;
					}
				}
				return $headers;
			 }
		}

		$querystring = preg_replace('/q=[^&]*&/', '', $_SERVER['QUERY_STRING']);
		$path = $wp->query_vars['codisto-proxy-route'] . (preg_match('/\/(?:\\?|$)/', $_SERVER['REQUEST_URI']) ? '/' : '');


		$storeId = '0';
		$merchantid = get_option('codisto_merchantid');

		if(isset($_GET['merchantid']))
		{
			$merchantid = (int)$_GET['merchantid'];
		}
		else
		{
			$storematch = array();

			if(preg_match('/^ebaytab\/(\d+)\/(\d+)(?:\/|$)/', $path, $storematch))
			{
				$storeId = (int)$storematch[1];
				$merchantid = (int)$storematch[2];

				$path = preg_replace('/(^ebaytab\/)(\d+\/?)(\d+\/?)/', '$1', $path);
			}
			if(preg_match('/^ebaytab\/(\d+)(?:\/|$)/', $path, $storematch))
			{
				if(isset($storematch[2]))
					$merchantid = (int)$storematch[2];

				$path = preg_replace('/(^ebaytab\/)(\d+\/?)/', '$1', $path);
			}
		}

		if(!$merchantid)
		{
			status_header('404 Not Found');
			header('Content-Type: text/html');
			header('Cache-Control: no-cache, no-store');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
			header('Pragma: no-cache');
			?>
			<h1>Resource Not Found</h1>
			<?php
			return;
		}

		$remoteUrl = 'https://ui.codisto.com/' . $merchantid . '/'. $path . ($querystring ? '?'.$querystring : '');

		$adminUrl = admin_url('codisto/ebaytab/'.$storeId.'/'.$merchantid.'/');

		$requestHeaders = array('X-Codisto-Cart' => 'woocommerce', 'X-Codisto-Version' => CODISTOCONNECT_VERSION, 'X-HostKey' => $HostKey, 'X-Admin-Base-Url' => $adminUrl);

		$incomingHeaders = getallheaders();

		$headerfilter = array('host', 'connection');

		$acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'];
		$zlibEnabled = strtoupper(ini_get('zlib.output_compression'));
		if(!$acceptEncoding || ($zlibEnabled == 1 || $zlibEnabled == 'ON'))
		{
			$decompress = true;
		}
		else
		{
			$decompress = false;
			$headerfilter[] = 'accept-encoding';
		}

		foreach($incomingHeaders as $name => $value)
		{
			if(!in_array(trim(strtolower($name)), $headerfilter))
				$requestHeaders[$name] = $value;
		}

		$httpOptions = array(
						'method' => $_SERVER['REQUEST_METHOD'],
						'headers' => $requestHeaders,
						'timeout' => 60,
						'httpversion' => '1.0',
						'decompress' => $decompress,
						'compress' => true,
						'redirection' => 0
					);

		$upload_dir = wp_upload_dir();
		$certPath = $upload_dir['basedir'].'/codisto.crt';
		if(file_exists($certPath))
		{
			$httpOptions['sslcertificates'] = $certPath;
		}

		if(strtolower($httpOptions['method']) == 'post')
		{
			$httpOptions['body'] = file_get_contents("php://input");
		}

		for($retry = 0; ; $retry++)
		{
			$response = wp_remote_request($remoteUrl, $httpOptions);

			if(is_wp_error($response))
			{
				if($retry >= 3)
				{
					status_header(500);
					echo '<h1>Error processing request</h1> <p>'.htmlspecialchars($response->get_error_message()).'</p>';
					return;
				}

				if($response->get_error_code() == 'http_request_failed')
				{
					$certResponse = wp_remote_get('http://ui.codisto.com/codisto.crt');

					if(!is_wp_error($certResponse))
					{
						@file_put_contents($certPath, $certResponse['body']);
						$httpOptions['sslcertificates'] = $certPath;
						continue;
					}
				}

				sleep(2);
				continue;
			}

			break;
		}

		status_header(wp_remote_retrieve_response_code($response));

		$filterHeaders = array('server', 'content-length', 'transfer-encoding', 'date', 'connection', 'x-storeviewmap');
		if($httpOptions['decompress'] == true)
			$filterHeaders[] = 'content-encoding';

		@header_remove( 'Last-Modified' );
		@header_remove( 'Pragma' );
		@header_remove( 'Cache-Control' );
		@header_remove( 'Expires' );
		@header_remove( 'Content-Encoding' );

		foreach(wp_remote_retrieve_headers($response) as $header => $value)
		{
			if(!in_array(strtolower($header), $filterHeaders, true))
			{
				if(is_array($value))
				{
					header($header.': '.$value[0], true);

					for($i = 1; $i < count($value); $i++)
					{
						header($header.': '.$value[$i], false);
					}
				}
				else
				{
					header($header.': '.$value, true);
				}
			}
		}

		file_put_contents('php://output', wp_remote_retrieve_body( $response ));
		exit();
	}

	public function parse()
	{
		global $wp;

		if(! empty( $wp->query_vars['codisto'] ) &&
			in_array($wp->query_vars['codisto'], array('proxy','sync'), true))
		{
			$codistoMode = $wp->query_vars['codisto'];

			if($codistoMode == 'sync')
			{
				$this->sync();
			}

			else if($codistoMode == 'proxy')
			{
				if( current_user_can( 'manage_woocommerce' ) )
				{
					$this->proxy();
				}
				else
				{
					auth_redirect();
				}
			}

			exit;
		}
	}

	private function reseller_key()
	{
		return CODISTOCONNECT_RESELLERKEY;
	}

	public function create_account()
	{
		if($_SERVER['REQUEST_METHOD'] === 'POST')
		{
			if($_POST['method'] == 'email')
			{
				$httpOptions = array(
								'method' => 'POST',
								'headers' => array( 'Content-Type' => 'application/json' ),
								'timeout' => 60,
								'httpversion' => '1.0',
								'redirection' => 0,
								'body' => $this->json_encode( array (

									'type' => 'woocommerce',
									'version' => get_bloginfo( 'version' ),
									'url' => get_site_url(),
									'email' => wp_unslash( $_POST['email'] ),
									'storename' => get_option('blogdescription') ,
									'resellerkey' => $this->reseller_key(),
									'codistoversion' => CODISTOCONNECT_VERSION

								) )
							);

				$response = wp_remote_request('https://ui.codisto.com/create', $httpOptions);

				$result = json_decode( wp_remote_retrieve_body( $response ), true );

				if(!$result || !$response) {

					$postdata = array (
					    'type' => 'woocommerce',
					    'version' => get_bloginfo( 'version' ),
					    'url' => get_site_url(),
					    'email' => wp_unslash( $_POST['email'] ),
					    'storename' => get_option('blogdescription') ,
					    'resellerkey' => $this->reseller_key(),
					    'codistoversion' => CODISTOCONNECT_VERSION
					);
					$str = json_encode( $postdata );

					$curl = curl_init();
					curl_setopt_array( $curl, array(
					    CURLOPT_RETURNTRANSFER => 1,
					    CURLOPT_URL => 'https://ui.codisto.com/create',
					    CURLOPT_POST => 1,
					    CURLOPT_POSTFIELDS => $str,
					    CURLOPT_HTTPHEADER => array(
					        'Content-Type: application/json',
					        'Content-Length: ' . strlen($str)
					    )
					));
					$response = curl_exec( $curl );
					curl_close( $curl );

					$result = json_decode( $response, true );

				}

				update_option( 'codisto_merchantid' , 	$result['merchantid'] );
				update_option( 'codisto_key',			$result['hostkey'] );

				wp_cache_flush();

				wp_redirect('admin.php?page=codisto');
			}
			else
			{
				wp_redirect('https://ui.codisto.com/register?finalurl='.
						urlencode(admin_url('admin-post.php?action=codisto_create')).
						'&type=woocommerce'.
						'&version='.urlencode(get_bloginfo( 'version' )).
						'&url='.urlencode(get_site_url()).
						'&storename='.urlencode(get_option('blogdescription')).
						'&storecurrency='.urlencode(get_option('woocommerce_currency')).
						'&resellerkey='.urlencode($this->reseller_key()).
						'&codistoversion='.urlencode(CODISTOCONNECT_VERSION));
			}
		}
		else
		{
			$regtoken = '';
			if(isset($_GET['regtoken']))
			{
				$regtoken = wp_unslash( $_GET['regtoken'] );
			}
			else
			{
				$query = array();
				parse_str( $_SERVER['QUERY_STRING'], $query );

				if(isset($query['regtoken']))
				{
					$regtoken = $query['regtoken'];
				}
			}

			$httpOptions = array(
							'method' => 'POST',
							'headers' => array( 'Content-Type' => 'application/json' ),
							'timeout' => 60,
							'httpversion' => '1.0',
							'redirection' => 0,
							'body' => $this->json_encode( array (

								'regtoken' => $regtoken

							) )
						);

			$response = wp_remote_request('https://ui.codisto.com/create', $httpOptions);

			$result = json_decode( wp_remote_retrieve_body( $response ), true );

			update_option( 'codisto_merchantid' , 	$result['merchantid'] );
			update_option( 'codisto_key',			$result['hostkey'] );

			wp_cache_flush();

			wp_redirect('admin.php?page=codisto');
		}
		exit();
	}

	public function update_template()
	{
		if ( !current_user_can('edit_themes') )
			wp_die('<p>'.__('You do not have sufficient permissions to edit templates for this site.').'</p>');

		check_admin_referer( 'edit-ebay-template' );

		$filename = wp_unslash( $_POST['file'] );
		$content = wp_unslash( $_POST['newcontent'] );

		$file = WP_CONTENT_DIR . '/ebay/' . $filename;

		@mkdir( basename( $file ), 0755, true );

		$updated = false;

		$f = fopen( $file, 'w' );
		if( $f !== false)
		{
			fwrite( $f, $content );
			fclose( $f );

			$updated = true;
		}

		wp_redirect( admin_url( 'admin.php?page=codisto-templates&file='.urlencode($filename).($updated ? '&updated=true' : '' ) ) );
		exit();
	}

	private function admin_tab($url, $tabclass)
	{
		$merchantid = get_option('codisto_merchantid');

		if(!is_numeric($merchantid))
		{
			$email = get_option( 'admin_email' );

			$paypal_settings = get_option('woocommerce_paypal_settings');
			if(is_array($paypal_settings))
			{
				$email = $paypal_settings['email'];
			}

			?>
			<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:500,900,700,400">
			<style>

			</style>

				<iframe id="dummy-data" frameborder="0" src="https://codisto.com/xpressgriddemo/"></iframe>
				<div id="dummy-data-overlay"></div>
				<div id="create-account-modal">
				<h1>Codisto Connect - Account Creation</h1>
				<form action="<?php echo htmlspecialchars(admin_url('admin-post.php')); ?>" method="post">
					<input type="hidden" name="action" value="codisto_create"/>

					<div class="option active">
						<label>
							<input type="radio" name="method" checked="checked" value="ebay">
							<div style="display: inline-block;">
								<img style="height: 20px;" src="https://d31wxntiwn0x96.cloudfront.net/connect/29137/ebaytab/images/ebay.png" scale="0">
								<div style="padding-top: 6px;">Link your eBay account to create an account automatically</div>
							</div>
						</label>
					</div>

					<div class="or">
					<strong>OR</strong>
					</div>

					<div class="option">
						<label>
							<input type="radio" name="method" value="email">
							<div style="display: inline-block;">
								<input type="text" name="email" value="<?php echo htmlspecialchars( $email ) ?>" size="40">
								<div style="padding-top: 10px;">Use your email address (you can link eBay later)</div>
							</div>
						</label>
					</div>

					<div class="next">
						<button class="button button-primary">Next</button>
					</div>

				</form>
				<div class="footer">
						Once you create an account we will begin synchronizing your catalog data.<br>
				  		Sit tight, this may take several minutes depending on the size of your catalog.<br>
						When completed, you'll have the world's best eBay integration at your fingertips.<br><br/>
						You'll be able to:
						 	<ul>
								<li>Sync in real-time between WooCommerce &amp; eBay</li>
								<li>have Codisto auto-categorize your products into eBay categories</li>
								<li>Access our sophisticated template engine for amazing listings</li>
								<li>and lots more…</li>
							</ul>
				</div>
				</div>
				<script>
				jQuery(function($) {

					$("#create-account-modal").on("click", ".option", function(e) {

						$("#create-account-modal .option").removeClass("active");
						$(this).addClass("active").find("INPUT[type=radio]").attr("checked", "checked");

					});

				});
				</script>


			<?php
		}
		else
		{
			?>
			<div id="codisto-container">
				<iframe class="<?php echo $tabclass ?>" src="<?php echo htmlspecialchars($url)?>" frameborder="0"></iframe>
			</div>
			<?php
		}
	}

	public function ebay_tab()
	{
		$adminUrl = admin_url('codisto/ebaytab/0/'.get_option('codisto_merchantid').'/');

		$this->admin_tab($adminUrl, 'codisto-bulk-editor');
	}

	public function orders()
	{
		$adminUrl = admin_url('codisto/ebaytab/0/'.get_option('codisto_merchantid').'/orders/');

		$this->admin_tab($adminUrl, 'codisto-bulk-editor');
	}

	public function categories()
	{
		$adminUrl = admin_url('codisto/ebaytab/0/'.get_option('codisto_merchantid').'/categories/');

		$this->admin_tab($adminUrl, 'codisto-bulk-editor');
	}

	public function attributes()
	{
		$adminUrl = admin_url('codisto/ebaytab/0/'.get_option('codisto_merchantid').'/attributemapping/');

		$this->admin_tab($adminUrl, 'codisto-attributemapping');
	}

	public function import()
	{
		$adminUrl = admin_url('codisto/ebaytab/0/'.get_option('codisto_merchantid').'/importlistings/');

		$this->admin_tab($adminUrl, 'codisto-bulk-editor');
	}

	public function templates()
	{
		include 'templates.php';
	}

	public function settings()
	{
		$adminUrl = admin_url('codisto/settings/');

		$this->admin_tab($adminUrl, 'codisto-settings');
	}

	public function admin_menu()
	{
		if ( current_user_can( 'manage_woocommerce' ) )
		{
			add_menu_page( __('eBay | Codisto'), __('eBay | Codisto'), 'edit_posts', 'codisto', array( $this, 'ebay_tab' ), 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhRE9DVFlQRSBzdmcgUFVCTElDICItLy9XM0MvL0RURCBTVkcgMS4xLy9FTiIgDQogICJodHRwOi8vd3d3LnczLm9yZy9HcmFwaGljcy9TVkcvMS4xL0RURC9zdmcxMS5kdGQiPg0KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiDQoJIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8cGF0aCBzdHlsZT0iZmlsbDojOTk5OTk5OyIgZD0iTTE3LDBIM0MxLjMsMCwwLDEuMywwLDN2MTRjMCwxLjYsMS4zLDMsMywzaDE0YzEuNywwLDMtMS40LDMtM1YzQzIwLDEuMywxOC43LDAsMTcsMHogTTkuMywxNC4xDQoJYzAuNCwwLjUsMC45LDAuNywxLjYsMC43YzAuNywwLDEuMy0wLjMsMS45LTAuOWwyLjcsMi43Yy0xLjIsMS4yLTIuOCwxLjktNC42LDEuOWMtMS45LDAtMy40LTAuNi00LjctMS45Yy0wLjgtMC44LTEuMy0xLjgtMS41LTMNCgljLTAuMS0wLjctMC4yLTEuOS0wLjItMy42czAuMS0yLjksMC4yLTMuNmMwLjItMS4yLDAuNy0yLjMsMS41LTNDNy41LDIuMSw5LDEuNSwxMC45LDEuNWMxLjksMCwzLjQsMC42LDQuNiwxLjlsLTIuNywyLjcNCgljLTAuNi0wLjYtMS4yLTAuOS0xLjktMC45Yy0wLjcsMC0xLjIsMC4yLTEuNiwwLjdDOC44LDYuNCw4LjYsNy44LDguNiwxMEM4LjYsMTIuMiw4LjgsMTMuNiw5LjMsMTQuMXoiLz4NCjwvc3ZnPg0K', '55.501' );

			$pages = array();

			$pages[] = add_submenu_page('codisto', __('Listings'), __('Listings'), 'edit_posts', 'codisto', array( $this, 'ebay_tab' ) );
			$pages[] = add_submenu_page('codisto', __('Orders'), __('Orders'), 'edit_posts', 'codisto-orders', array( $this, 'orders' ) );
			$pages[] = add_submenu_page('codisto', __('Categories'), __('Categories'), 'edit_posts', 'codisto-categories', array( $this, 'categories' ) );
			$pages[] = add_submenu_page('codisto', __('Attributes'), __('Attributes'), 'edit_posts', 'codisto-attributes', array( $this, 'attributes' ) );
			$pages[] = add_submenu_page('codisto', __('Import Listings'), __('Import Listings'), 'edit_posts', 'codisto-import', array( $this, 'import' ) );
			$pages[] = add_submenu_page('codisto', __('Templates'), __('Templates'), 'edit_posts', 'codisto-templates', array( $this, 'templates' ) );
			$pages[] = add_submenu_page('codisto', __('Settings'), __('Settings'), 'edit_posts', 'codisto-settings', array( $this, 'settings' ) );

			foreach($pages as $page)
			{
				add_action( "admin_print_styles-{$page}", array( $this, 'admin_styles' ) );
			}
		}
	}

	public function admin_body_class($classes)
	{
		if(isset($_GET['page']))
		{
			$page = wp_unslash( $_GET['page'] );

			if(substr( $page, 0, 7 ) === 'codisto')
			{
				if($page === 'codisto')
				{
					return "$classes codisto";
				}
				else if($page === 'codisto-templates')
				{
					return "$classes $page";
				}

				return "$classes codisto $page";
			}
		}

		return $classes;
	}

	public function admin_styles()
	{
		wp_enqueue_style( 'codisto-style' );
	}

	public function bulk_edit_save($product)
	{
		if(!$this->ping)
		{
			$this->ping = array();
		}

		if(!isset($this->ping['products']))
		{
			$this->ping['products'] = array();
		}

		$pingProducts = $this->ping['products'];

		if(!in_array( $product->id, $pingProducts ) )
		{
			$pingProducts[] = $product->id;
		}

		$this->ping['products'] = $pingProducts;
	}

	public function option_save($value)
	{
		if(!$this->ping)
		{
			$this->ping = array();
		}

		return $value;
	}

	public function post_save($id, $post)
	{
		if($post->post_type == 'product')
		{
			if(!$this->ping)
			{
				$this->ping = array();
			}

			if(!isset($this->ping['products']))
			{
				$this->ping['products'] = array();
			}

			$pingProducts = $this->ping['products'];

			if(!in_array( $id, $pingProducts ) )
			{
				$pingProducts[] = $id;
			}

			$this->ping['products'] = $pingProducts;
		}
	}

	public function order_reduce_stock($order)
	{
		$product_ids = array();

		foreach ( $order->get_items() as $item )
		{
			if ( $item['product_id'] > 0 )
			{
				if(is_string( get_post_status( $item['product_id'] ) ) )
				{
					$product_ids[] = $item['product_id'];
				}
			}
		}

		if(count($product_ids) > 0)
		{
			if(!$this->ping)
			{
				$this->ping = array();
			}

			if(!isset($this->ping['products']))
			{
				$this->ping['products'] = array();
			}

			$pingProducts = $this->ping['products'];

			foreach($product_ids as $id)
			{
				if(!in_array($id, $pingProducts))
				{
					$pingProducts[] = $id;
				}
			}

			$this->ping['products'] = $pingProducts;
		}
	}

	public function signal_edits()
	{
		if(is_array($this->ping) &&
			isset($this->ping['products']))
		{
			$response = wp_remote_post('https://api.codisto.com/'.get_option('codisto_merchantid'), array(
				  'method'		=> 'POST',
				  'timeout'		=> 5,
				  'redirection' => 0,
				  'httpversion' => '1.0',
				  'blocking'	=> true,
				  'headers'		=> array('X-HostKey' => get_option('codisto_key') , 'Content-Type' => 'application/x-www-form-urlencoded' ),
				  'body'		=> 'action=sync&productid=['.implode(',', $this->ping['products']).']'
				  )
			);
		}

		else if(is_array($this->ping))
		{
			$response = wp_remote_post('https://api.codisto.com/'.get_option('codisto_merchantid'), array(
				  'method'		=> 'POST',
				  'timeout'		=> 5,
				  'redirection' => 0,
				  'httpversion' => '1.0',
				  'blocking'	=> true,
				  'headers'		=> array('X-HostKey' => get_option('codisto_key') , 'Content-Type' => 'application/x-www-form-urlencoded' ),
				  'body'		=> 'action=sync'
				  )
			);
		}
	}

	public function add_ebay_product_tab($tabs)
	{
		$tabs['codisto'] = array(
								'label'	=> 'eBay',
								'target' => 'codisto_product_data',
								'class'	=> '',
							);

		return $tabs;
	}

	public function ebay_product_tab_content()
	{
		global $post;

		?>
			<div id="codisto_product_data" class="panel woocommerce_options_panel" style="padding: 8px;">
			<iframe id="codisto-control-panel" style="width: 100%;" src="<?php echo htmlspecialchars(admin_url('/codisto/ebaytab/product/'.$post->ID).'/'); ?>" frameborder="0"></iframe>
			</div>
		<?php
	}

	public function plugin_links($links)
	{
		$action_links = array(
			'listings' => '<a href="' . admin_url( 'admin.php?page=codisto' ) . '" title="'.htmlspecialchars(__('Manage eBay Listings')).'">'.htmlspecialchars(__('Manage eBay Listings')).'</a>',
			'settings' => '<a href="' . admin_url( 'admin.php?page=codisto-settings' ) . '" title="'.htmlspecialchars(__('Codisto Settings')).'">'.htmlspecialchars(__('Settings')).'</a>'
		);

		return array_merge( $action_links, $links );
	}

	public function init_plugin()
	{
		$homeUrl = preg_replace('/^https?:\/\//', '', trim(home_url()));
		$siteUrl = preg_replace('/^https?:\/\//', '', trim(site_url()));
		$adminUrl = preg_replace('/^https?:\/\//', '', trim(admin_url()));

		$syncUrl = str_replace($homeUrl, '', $siteUrl);
		$syncUrl .= (substr($syncUrl, -1) == '/' ? '' : '/');

		// synchronisation end point
		add_rewrite_rule(
			'^'.preg_quote(ltrim($syncUrl, '/'), '/').'codisto-sync\/(.*)?',
			'index.php?codisto=sync&codisto-sync-route=$matches[1]',
			'top' );

		$adminUrl = str_replace($homeUrl, '', $adminUrl);
		$adminUrl .= (substr($adminUrl, -1) == '/' ? '' : '/');

		// proxy end point
		add_rewrite_rule(
			'^'.preg_quote(ltrim($adminUrl, '/'), '/').'codisto\/(.*)?',
			'index.php?codisto=proxy&codisto-proxy-route=$matches[1]',
			'top'
		);

		wp_register_style( 'codisto-style', plugins_url('styles.css', __FILE__) );

		add_filter( 'query_vars', 							array( $this, 'query_vars' ) );
		add_filter( 'nocache_headers',						array( $this, 'nocache_headers' ) );
		add_action( 'parse_request',						array( $this, 'parse' ), 0 );
		add_action( 'admin_post_codisto_create',			array( $this, 'create_account' ) );
		add_action( 'admin_post_codisto_update_template',	array( $this, 'update_template' ) );
		add_action( 'admin_menu',							array( $this, 'admin_menu' ) );
		add_filter( 'admin_body_class', 					array( $this, 'admin_body_class' ) );
		add_action(	'woocommerce_product_bulk_edit_save', 	array( $this, 'bulk_edit_save' ) );
		add_action( 'save_post',							array( $this, 'post_save' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs',		array( $this, 'add_ebay_product_tab' ) );
		add_action( 'woocommerce_product_data_panels',		array( $this, 'ebay_product_tab_content' ) );
		add_filter( 'wc_order_is_editable',					array( $this, 'order_is_editable' ), 10, 2 );
		add_action( 'woocommerce_reduce_order_stock',		array( $this, 'order_reduce_stock' ) );
		add_action( 'woocommerce_admin_order_data_after_'.
						'order_details', 					array( $this, 'order_buttons' ) );
		add_action(	'woocommerce_admin_settings_sanitize_'.
					'	option_woocommerce_currency',		array( $this, 'option_save') );
		add_filter( 'plugin_action_links_'.
						plugin_basename( __FILE__ ),		array( $this, 'plugin_links' ) );
		add_action( 'shutdown',								array( $this, 'signal_edits' ) );
	}

	public static function init() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();

			add_action('init', array( self::$_instance, 'init_plugin' ) );

			if(preg_match('/\/codisto-sync\//', $_SERVER['REQUEST_URI']))
			{
				$_POST['aelia_cs_currency'] = get_option('woocommerce_currency');
			}
		}
		return self::$_instance;
	}
}

function codisto_activate()
{
	$homeUrl = preg_replace('/^https?:\/\//', '', trim(home_url()));
	$siteUrl = preg_replace('/^https?:\/\//', '', trim(site_url()));
	$adminUrl = preg_replace('/^https?:\/\//', '', trim(admin_url()));

	$syncUrl = str_replace($homeUrl, '', $siteUrl);
	$syncUrl .= (substr($syncUrl, -1) == '/' ? '' : '/');

	// synchronisation end point
	add_rewrite_rule(
		'^'.preg_quote(ltrim($syncUrl, '/'), '/').'codisto-sync\/(.*)?',
		'index.php?codisto=sync&codisto-sync-route=$matches[1]',
		'top' );

	$adminUrl = str_replace($homeUrl, '', $adminUrl);
	$adminUrl .= (substr($adminUrl, -1) == '/' ? '' : '/');

	// proxy end point
	add_rewrite_rule(
		'^'.preg_quote(ltrim($adminUrl, '/'), '/').'codisto\/(.*)?',
		'index.php?codisto=proxy&codisto-proxy-route=$matches[1]',
		'top'
	);

	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'codisto_activate' );

endif;

CodistoConnect::init();
