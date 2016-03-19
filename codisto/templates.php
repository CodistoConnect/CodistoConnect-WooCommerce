<?php
if ( !current_user_can('edit_themes') )
	wp_die('<p>'.__('You do not have sufficient permissions to edit templates for this site.').'</p>');

$file = WP_CONTENT_DIR . '/ebay/default.html';
$content = file_get_contents($file);
?>

<div class="wrap">
    <h1>default.html
        <span style="font-size: 12px;"><a style="color: #888;" target="codisto!preview" href="codisto/ebaytab/templatepreview">Preview <span style="font-size: inherit; vertical-align: inherit; width: inherit;" class="dashicons dashicons-external"></span></a></span>
    </h1>
</div>

<div id="templateside">
    <h2>Listing Templates</h2>
    <ul>
        <li><a href="admin.php?page=codisto-templates&amp;file=default.html">default.html</a></li>
    </ul>
</div>


<form name="template" id="template" action="admin.php?page=codisto-templates" method="post">
	<?php wp_nonce_field( 'edit-ebay-template' ); ?>
		<div><textarea cols="70" rows="30" name="newcontent" id="newcontent" aria-describedby="newcontent-description"><?php echo htmlspecialchars($content) ?></textarea>
		<input type="hidden" name="action" value="update" />
		<input type="hidden" name="file" value="file" />
		</div>

        <div>
<?php
	if ( is_writeable( WP_CONTENT_DIR . '/ebay/default.html' ) ) :
		submit_button( __( 'Update File' ), 'primary', 'submit', true );
	else : ?>
<p><em><?php _e('You need to make this file writable before you can save your changes. See <a href="https://codex.wordpress.org/Changing_File_Permissions">the Codex</a> for more information.'); ?></em></p>
<?php endif; ?>
		</div>
	</form>

<?php
include(ABSPATH . 'wp-admin/admin-footer.php' );
