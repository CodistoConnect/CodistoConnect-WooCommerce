<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !current_user_can('edit_themes') ) {
	wp_die('<p>'.esc_html__('You do not have sufficient permissions to edit templates for this site.', 'codisto-linq' ).'</p>');
}

if ( isset( $_GET['file'] ) ) {

	$filename = wp_unslash( $_GET['file'] );
	$filename = preg_replace('/[^ -~]+|[\\/:"*?<>|]+/', '', $filename);

} else {

	$filename = 'default.html';
}
$file = WP_CONTENT_DIR . '/ebay/' . $filename;

if( file_exists( $file ) ) {

	$content = @file_get_contents($file);
	if(!$content) {
		$content = '';
	}

} else {

	$content = '';
}
?>

<?php if ( isset( $_GET['updated'] ) ) : ?>
<div id="message" class="updated notice is-dismissible"><p><?php esc_html_e( 'File edited successfully.', 'codisto-linq' ) ?></p></div>
<?php endif ?>


<div class="wrap">
	<h1><?php echo htmlspecialchars( $filename === '_new' ? __( 'New Template File', 'codisto-linq' ) : $filename ); ?>
		<span style="font-size: 12px;"><a style="color: #888;" target="codisto!preview" href="codisto/ebaytab/templatepreview">Preview <span style="font-size: inherit; vertical-align: inherit; width: inherit;" class="dashicons dashicons-external"></span></a></span>
	</h1>
</div>

<div id="templateside">
	<h2>Listing Templates</h2>

	<?php

	if( is_dir( WP_CONTENT_DIR . '/ebay/' ) ) {
		$files = scandir( WP_CONTENT_DIR . '/ebay/' );
	} else {
		$files = array();
	}

	?>

	<ul class="file-list">
		<?php foreach ( $files as $list_file ) {
			if ( $list_file[0] === '.' ) {
				continue;
			}

			if( is_dir( WP_CONTENT_DIR . '/ebay/' . $list_file ) ) {
				continue;
			}
		?>
			<li><a href="<?php echo htmlspecialchars(admin_url('admin.php?page=codisto-templates&file='.urlencode($list_file))); ?>"><span <?php echo $list_file === $filename ? 'class="highlight"' : ''?>><?php echo htmlspecialchars($list_file) ?></span></a></li>
		<?php } ?>
		<li><button class="button button-primary new-template"><span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'New Template', 'codisto-linq' ); ?></button></li>
	</ul>
</div>


<form name="template" id="template" action="<?php echo htmlspecialchars(admin_url('admin-post.php')); ?>" method="post">
	<?php wp_nonce_field( 'edit-ebay-template' ); ?>
	<input type="hidden" name="action" value="codisto_update_template" />
	<?php if($filename !== '_new') { ?>
	<input type="hidden" name="file" value="<?php echo htmlspecialchars($filename); ?>" />
	<?php } ?>

	<?php if($filename === '_new') { ?>

		<br/>
		<div>
			<label for="filename">New File Name</label> <input id="filename" type="text" name="file" value=""/>
		</div><br/>

	<?php } ?>

	<div>
		<textarea cols="70" rows="30" name="newcontent" id="newcontent" aria-describedby="newcontent-description"><?php echo htmlspecialchars($content) ?></textarea>
	</div>

	<div>
	<?php
		if ( $filename !== '_new' ) {

			if ( is_writeable( $file ) ) {
				submit_button( __( 'Update Template', 'codisto-linq' ), 'primary', 'submit', true );
			} else {
				?>
				<p><em><?php echo
					wp_kses(
						__('You need to make this file writable before you can save your changes. See <a href="https://codex.wordpress.org/Changing_File_Permissions">the Codex</a> for more information.', 'codisto-linq'),
						array(
							'a' => array(
								'class' => array(),
								'href' => array()
							)
						)
					); ?></em></p>
				<?php
			}

		} else {

			submit_button( __( 'Create New Template', 'codisto-linq' ), 'primary', 'submit', true );

		} ?>
	</div>
</form>

<?php
include(ABSPATH . 'wp-admin/admin-footer.php' );
