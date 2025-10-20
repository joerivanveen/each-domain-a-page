<?php
// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die();
}

// remove settings
delete_option( 'ruigehond007' );
// remove favicons
delete_post_meta_by_key( '_ruigehond007_favicons' );
