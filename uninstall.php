<?php
/**
 * Uninstall: remove export records. ZIP files in uploads/media-exports
 * are left on disk (they auto-expire after 24 hours via the plugin).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wme_exports' );
