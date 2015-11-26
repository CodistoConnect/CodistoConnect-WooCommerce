<?php
/**
 * @package Codisto_Connect
 * @version 1.0
 */
/*
Plugin Name: CodistoConnect
Plugin URI: http://wordpress.org/plugins/codistoconnect/
Description: List on ebay
Author: Luke Amery
Version: 1.0
Author URI: https://codisto.com/
*/

define('CODISTOCONNECT_VERSION', '1.0');

$merchantid = get_option('codisto_merchantid');

if(!$merchantid)
{
	// register merchant
	update_option('codisto_merchantid', '12822');
	update_option('codisto_key', 'x');
}

function codisto_query_vars()
{
	$vars[] = 'codisto';
	$vars[] = 'codisto-proxy-route';
	$vars[] = 'codisto-sync-route';
	return $vars;
}

function codisto_sync()
{
	global $wp;
	global $wpdb;

	if(strtolower($_SERVER['REQUEST_METHOD']) == 'get')
	{
		$type = $wp->query_vars['codisto-sync-route'];

		if($type == 'tax')
		{
			$rates = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}woocommerce_tax_rates` WHERE tax_rate_class = '' ORDER BY tax_rate_order");
			
			status_header('200 OK');
			header('Content-Type: application/json');
			header('Cache-Control: no-cache, no-store');
			header('Expires: on, 01 Jan 1970 00:00:00 GMT');
			header('Pragma: no-cache');
			echo wp_json_encode($rates);
		}
		else if($type == 'products')
		{
			$page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
			$count = isset($_GET['count']) ? (int)$_GET['count'] : 0;
			
			$products = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, (SELECT meta_value FROM `{$wpdb->prefix}postmeta` WHERE post_id = P.ID AND meta_key = '_sku') AS Code, post_title AS Name, CAST(COALESCE((SELECT meta_value FROM `{$wpdb->prefix}postmeta` WHERE post_id = P.ID AND meta_key = '_regular_price'), 0) AS DECIMAL) AS Price FROM `{$wpdb->prefix}posts` AS P WHERE post_type = 'product' AND post_status NOT IN ('auto-draft') ORDER BY ID LIMIT %d, %d",
				$page * $count,
				$count
			));
			
			foreach($products as $product)
			{
				$wc_product = wc_get_product($product->ID);

				$product->image = wp_get_attachment_image_src($wc_product->get_image_id())[0];
			}

			status_header('200 OK');
			header('Content-Type: application/json');
			header('Cache-Control: no-cache, no-store');
			header('Expires: on, 01 Jan 1970 00:00:00 GMT');
			header('Pragma: no-cache');
			echo wp_json_encode(array( 'products' => $products, 'total_count' => 1));
		}
	}
	else
	{
		
	}
}

function codisto_proxy()
{
	global $wp;
	
	if(isset($_GET['productid']))
	{
		wp_redirect(admin_url('post.php?post='.$_GET['productid'].'&action=edit#codisto_product_data'));
		exit;
	}
	
	$HostKey = get_option('codisto_key');
	
	if (!function_exists('getallheaders')) 
	{ 
	    function getallheaders() 
	    { 
	           $headers = ''; 
	       foreach ($_SERVER as $name => $value) 
	       { 
	           if (substr($name, 0, 5) == 'HTTP_') 
	           { 
	               $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; 
	           } 
	       } 
	       return $headers; 
	    } 
	} 

	$querystring = preg_replace('/q=[^&]*&?/', '', $_SERVER['QUERY_STRING']);
	$path = $wp->query_vars['codisto-proxy-route'] . (preg_match('/\/(?:\\?|$)/', $_SERVER['REQUEST_URI']) ? '/' : '');

	$remoteUrl = 'https://ui.codisto.com/' . get_option('codisto_merchantid') . '/'. $path . ($querystring ? '?'.$querystring : '');

	$adminUrl = admin_url('codisto/ebaytab/');
	
	$requestHeaders = array('X-Codisto-Version' => CODISTOCONNECT_VERSION, 'X-HostKey' => $HostKey, 'X-Admin-Base-Url' => $adminUrl);
	
	$incomingHeaders = getallheaders();
	
	foreach($incomingHeaders as $name => $value)
	{
		if(!in_array(trim(strtolower($name)), array('host', 'connection')))
			$requestHeaders[$name] = $value;
	}
	
	$httpOptions = array('method' => $_SERVER['REQUEST_METHOD'], 'headers' => $requestHeaders, 'sslverify' => 0, 'timeout' => 10, 'httpversion' => '1.0', 'compress' => true, 'decompress' => false, 'redirection' => 0 );
	
	if(strtolower($httpOptions['method']) == 'post')
	{
		$httpOptions['body'] = file_get_contents("php://input");
	}
	

	$response = wp_remote_request($remoteUrl, $httpOptions);
	
	if(is_wp_error($response))
	{
		echo 'error: '.htmlspecialchars($response->get_error_message());
	}
	else
	{
		status_header(wp_remote_retrieve_response_code($response));
		
		$filterHeaders = array('server', 'content-length', 'transfer-encoding', 'date', 'connection', 'x-storeviewmap');

		foreach(wp_remote_retrieve_headers($response) as $header => $value)
		{
			if(!in_array(strtolower($header), $filterHeaders, true))
			{
				if(is_array($value))
				{
					header($header.': '.$value[0], true); 
	
					for($i = 1; $i < count($value); $i++)
					{
						header($header.': '.$value[$i]);
					}
				}
				else
				{
					header($header.': '.$value, true);
				}
			}
		}
		
		echo wp_remote_retrieve_body($response);
	}
}

function codisto_parse()
{
	global $wp;

	if(! empty( $wp->query_vars['codisto'] ) &&
		in_array($wp->query_vars['codisto'], array('proxy','sync'), true))
	{
		$codistoMode = $wp->query_vars['codisto'];
		
		if($codistoMode == 'sync')
		{
			codisto_sync();
		}
		
		else if($codistoMode == 'proxy')
		{
			codisto_proxy();
		}

		exit;
	}
}

function codisto_ebay_tab()
{
	$adminUrl = admin_url('codisto/ebaytab/');
	
	echo "<style>";
	echo "#wpbody { position: absolute !important; left: 160px !important; right: 0px; bottom: 0px; top: 0px; }\n";
	echo ".folded #wpbody { left: 36px !important; }\n";
	echo "#wpbody-content { padding: 0px; height: 100%; }\n";
	echo "#wpfooter { display: none !important; }\n";
	echo "</style>";
	echo '<div style="width: 100%; height: 100%;"><iframe src="'.$adminUrl.'" frameborder="0" style="width: 100%; height: 100%; border-bottom: 1px solid #000;"></iframe></div>';

}

function codisto_settings()
{
	$adminUrl = admin_url('codisto/settings/');
	
	echo "<style>";
	echo "#wpbody { position: absolute !important; left: 160px !important; right: 0px; bottom: 45px; top: 0px; }\n";
	echo ".folded #wpbody { left: 36px !important; }\n";
	echo "#wpbody-content { padding: 0px; height: 100%; }\n";
	echo "#wpfooter { display: none !important; }\n";
	echo "</style>";
	echo '<div style="width: 100%; height: 100%;"><iframe class="codisto-settings" src="'.$adminUrl.'" frameborder="0" style="width: 100%; height: 100%; border-bottom: 1px solid #000;"></iframe></div>';
}

function codisto_bulk_edit()
{
	echo print_r(func_get_args(), true);	
}

function codisto_admin_menu()
{
	add_menu_page( 'eBay | Codisto', 'eBay | Codisto', 'edit_posts', 'codisto', 'codisto_ebay_tab', "dashicons-cart", '55.51' );
	
	add_submenu_page('codisto', 'Settings', 'Settings', 'edit_posts', 'codisto-settings', 'codisto_settings');	
}

$pingProducts = null;


function codisto_bulk_edit_save($product)
{
	global $pingProducts;
	
	if(!$pingProducts)
		$pingProducts[] = $product->id;
	
//	echo print_r(func_get_args(), true);
//	die();
}

function codisto_save($id)
{
	global $pingProducts;
	
	// TODO: check that post isn't a draft
	
	if(!$pingProducts)
		$pingProducts[] = $id;	
}

function codisto_signal_edits()
{
	global $pingProducts;
	
	
	if($pingProducts)
	{
		$response = wp_remote_post('https://api.codisto.com/12822', array(
		    'method'      => 'POST',
		    'timeout'     => 5,
		    'redirection' => 0,
		    'httpversion' => '1.0',
		    'blocking'    => true,
		    'headers'     => array('X-HostKey' => get_option('codisto_key') , 'Content-Type' => 'application/x-www-form-urlencoded' ),
		    'body'        => 'action=sync&productid=['.implode(',', $pingProducts).']'
		    )
		);
	}
}

function codisto_add_ebay_product_tab($tabs)
{

	$tabs['codisto'] = array(
							'label'  => 'eBay',
							'target' => 'codisto_product_data',
							'class'  => '',
						);
						
	return $tabs;

	
}

function codisto_ebay_product_tab_content()
{
	global $post;

	?>
				<div id="codisto_product_data" class="panel woocommerce_options_panel" style="padding: 8px;">
				<iframe id="codisto-control-panel" style="width: 100%;" src="<?php echo htmlspecialchars(admin_url('/codisto/ebaytab/product/'.$post->ID).'/') ?>" frameborder="0"></iframe>
				</div>
	<?php
}

function codisto_plugin_links($links)
{
	$action_links = array(
		'manage' => '<a href="' .admin_url('admin.php?page=codisto').'" title="Manage Listings">Manage eBay Listings</a>',
		'settings' => '<a href="' . admin_url( 'admin.php?page=codisto-settings' ) . '" title="Codisto Settings">Settings</a>',
	);

	return array_merge( $action_links, $links );
}

function codisto_init()
{
	add_filter('query_vars', 'codisto_query_vars', 0 );
	
	add_rewrite_rule('^codisto-sync(.*)?', 'index.php?codisto=sync&codisto-sync-route=$matches[1]', 'top' );

	$adminUrl = preg_replace('/\//', '\/', preg_replace('/\//', '', parse_url(admin_url(), PHP_URL_PATH), 1));

	add_rewrite_rule('^'.$adminUrl.'codisto\/(.*)?', 'index.php?codisto=proxy&codisto-proxy-route=$matches[1]', 'top' );
	
	add_action( 'parse_request', 'codisto_parse', 0 );	
	
	add_action( 'admin_menu', 'codisto_admin_menu');
	
	
	add_action('woocommerce_product_bulk_edit_save', 'codisto_bulk_edit_save');
	add_action('save_post', 'codisto_save');
	
	add_action('shutdown', 'codisto_signal_edits');
	
	add_filter('woocommerce_product_data_tabs', 'codisto_add_ebay_product_tab');
	add_action('woocommerce_product_data_panels', 'codisto_ebay_product_tab_content');
	
	
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'codisto_plugin_links' );
	
}

add_action('init', 'codisto_init');


