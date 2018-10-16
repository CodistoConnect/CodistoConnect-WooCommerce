<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<?php if ( isset( $_GET['updated'] ) ) : ?>
<div id="message" class="updated notice is-dismissible"><p><?php _e( 'File edited successfully.' ) ?></p></div>
<?php endif ?>


<div class="wrap">
	<h1>WordPress multi-site is currently not supported by Codisto LINQ</h1>
	<p>Please <a target="_blank" href="https://get.codisto.help/hc/en-us/articles/360000514195-WordPress-WooCommerce-Multi-site-and-Codisto-LINQ">click here for more information</a>.</p>
</div>


<?php
include(ABSPATH . 'wp-admin/admin-footer.php' );
