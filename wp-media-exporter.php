<?php
/**
 * Plugin Name: WP Media Exporter
 * Plugin URI: https://github.com/Azanumer/wp-media-exporter
 * Description: Export part or all of the Media Library to a downloadable ZIP — filter by date range, file type or search, with batched building so large libraries never time out.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-media-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WME_VERSION', '1.0.0' );
define( 'WME_BATCH', 100 );
define( 'WME_OPTION', 'wme_exports' );

/**
 * Directory for in-progress and finished exports (inside uploads).
 */
function wme_export_dir() {
    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'media-exports';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        // Block directory listing / direct guessing of other exports.
        file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
    }
    return $dir;
}

/**
 * Delete exports older than 24 hours.
 */
function wme_prune_old_exports() {
    $dir = wme_export_dir();
    foreach ( glob( $dir . '/*.zip' ) as $file ) {
        if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
            unlink( $file );
        }
    }
    $exports = get_option( WME_OPTION, array() );
    if ( is_array( $exports ) ) {
        $kept = array();
        foreach ( $exports as $token => $meta ) {
            if ( isset( $meta['created'] ) && $meta['created'] > time() - DAY_IN_SECONDS ) {
                $kept[ $token ] = $meta;
            }
        }
        if ( count( $kept ) !== count( $exports ) ) {
            update_option( WME_OPTION, $kept );
        }
    }
}

/**
 * Query attachment IDs matching the filters.
 *
 * @return int[] Attachment post IDs, oldest first.
 */
function wme_matching_attachments( $args ) {
    $query_args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'ASC',
    );
    if ( ! empty( $args['mime'] ) ) {
        $query_args['post_mime_type'] = $args['mime'];
    }
    if ( ! empty( $args['s'] ) ) {
        $query_args['s'] = $args['s'];
    }
    if ( ! empty( $args['after'] ) || ! empty( $args['before'] ) ) {
        $query_args['date_query'] = array(
            array(
                'after'     => $args['after'],
                'before'    => $args['before'],
                'inclusive' => true,
            ),
        );
    }
    $query = new WP_Query( $query_args );
    return array_map( 'intval', $query->posts );
}

/**
 * MIME groups for the filter dropdown.
 */
function wme_mime_groups() {
    return array(
        ''            => __( 'All file types', 'wp-media-exporter' ),
        'image'       => __( 'Images', 'wp-media-exporter' ),
        'video'       => __( 'Videos', 'wp-media-exporter' ),
        'audio'       => __( 'Audio', 'wp-media-exporter' ),
        'application' => __( 'Documents & other files', 'wp-media-exporter' ),
    );
}

/**
 * Tools page.
 */
function wme_admin_menu() {
    add_management_page(
        __( 'Media Exporter', 'wp-media-exporter' ),
        __( 'Media Exporter', 'wp-media-exporter' ),
        'upload_files',
        'wme-media-exporter',
        'wme_tools_page'
    );
}
add_action( 'admin_menu', 'wme_admin_menu' );

function wme_tools_page() {
    if ( ! current_user_can( 'upload_files' ) ) {
        return;
    }
    wme_prune_old_exports();

    $upload = wp_upload_dir();
    $base   = trailingslashit( $upload['basedir'] );

    echo '<div class="wrap"><h1>' . esc_html__( 'Media Exporter', 'wp-media-exporter' ) . '</h1>';
    echo '<p>' . esc_html__( 'Build a ZIP of Media Library files and download it. Exports are kept for 24 hours, then deleted automatically.', 'wp-media-exporter' ) . '</p>';

    // Start a new export.
    if ( isset( $_POST['wme_start'] ) ) {
        check_admin_referer( 'wme_start' );
        $filters = array(
            'mime'   => isset( $_POST['wme_mime'] ) ? sanitize_text_field( wp_unslash( $_POST['wme_mime'] ) ) : '',
            's'      => isset( $_POST['wme_s'] ) ? sanitize_text_field( wp_unslash( $_POST['wme_s'] ) ) : '',
            'after'  => isset( $_POST['wme_after'] ) ? sanitize_text_field( wp_unslash( $_POST['wme_after'] ) ) : '',
            'before' => isset( $_POST['wme_before'] ) ? sanitize_text_field( wp_unslash( $_POST['wme_before'] ) ) : '',
        );
        if ( ! array_key_exists( $filters['mime'], wme_mime_groups() ) ) {
            $filters['mime'] = '';
        }
        $ids = wme_matching_attachments( $filters );

        if ( empty( $ids ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No media files match those filters.', 'wp-media-exporter' ) . '</p></div>';
        } elseif ( ! class_exists( 'ZipArchive' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'ZipArchive is not available on this server — the export cannot be built.', 'wp-media-exporter' ) . '</p></div>';
        } else {
            $token = wp_generate_password( 16, false );
            $zip   = new ZipArchive();
            $path  = wme_export_dir() . '/media-export-' . $token . '.zip';
            if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not create the ZIP file in the uploads directory.', 'wp-media-exporter' ) . '</p></div>';
            } else {
                $zip->close();
                $exports = get_option( WME_OPTION, array() );
                if ( ! is_array( $exports ) ) {
                    $exports = array();
                }
                $exports[ $token ] = array(
                    'ids'     => $ids,
                    'done'    => 0,
                    'added'   => 0,
                    'skipped' => 0,
                    'created' => time(),
                    'filters' => $filters,
                );
                update_option( WME_OPTION, $exports );
                wp_safe_redirect( add_query_arg( array( 'wme_build' => $token ), admin_url( 'tools.php?page=wme-media-exporter' ) ) );
                exit;
            }
        }
    }

    // Batched build step (auto-continues via meta refresh).
    if ( isset( $_GET['wme_build'] ) ) {
        $token   = sanitize_key( wp_unslash( $_GET['wme_build'] ) );
        $exports = get_option( WME_OPTION, array() );
        if ( isset( $exports[ $token ] ) ) {
            $meta = $exports[ $token ];
            $zip  = new ZipArchive();
            $path = wme_export_dir() . '/media-export-' . $token . '.zip';
            if ( true !== $zip->open( $path ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not open the in-progress ZIP file.', 'wp-media-exporter' ) . '</p></div>';
            } else {
                $batch = array_slice( $meta['ids'], $meta['done'], WME_BATCH );
                foreach ( $batch as $id ) {
                    $file = get_attached_file( $id );
                    if ( ! $file || ! file_exists( $file ) ) {
                        $meta['skipped']++;
                        continue;
                    }
                    // Keep uploads-relative paths inside the ZIP; fall back
                    // to the bare filename for files outside uploads.
                    $relative = ( 0 === strpos( $file, $base ) ) ? substr( $file, strlen( $base ) ) : wp_basename( $file );
                    if ( false === $zip->addFile( $file, $relative ) ) {
                        $meta['skipped']++;
                    } else {
                        $meta['added']++;
                    }
                }
                $meta['done'] += count( $batch );
                $zip->close();
                $exports[ $token ] = $meta;
                update_option( WME_OPTION, $exports );

                $total = count( $meta['ids'] );
                if ( $meta['done'] < $total ) {
                    printf(
                        '<p>%s</p>',
                        esc_html( sprintf(
                            /* translators: 1: files processed, 2: total files. */
                            __( 'Building ZIP… %1$d of %2$d files processed. This page refreshes automatically.', 'wp-media-exporter' ),
                            $meta['done'],
                            $total
                        ) )
                    );
                    printf(
                        '<meta http-equiv="refresh" content="1;url=%s">',
                        esc_url( add_query_arg( array( 'wme_build' => $token ), admin_url( 'tools.php?page=wme-media-exporter' ) ) )
                    );
                    echo '</div>';
                    return;
                }

                $url = trailingslashit( $upload['baseurl'] ) . 'media-exports/media-export-' . $token . '.zip';
                echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
                    /* translators: 1: files added, 2: files skipped. */
                    __( 'Export ready: %1$d files added, %2$d skipped (missing or unreadable).', 'wp-media-exporter' ),
                    $meta['added'],
                    $meta['skipped']
                ) ) . '</p></div>';
                printf(
                    '<p><a class="button button-primary" href="%s" download>%s</a></p>',
                    esc_url( $url ),
                    esc_html__( 'Download ZIP', 'wp-media-exporter' )
                );
            }
        }
    }

    // Filter form.
    echo '<form method="post"><h2>' . esc_html__( 'New export', 'wp-media-exporter' ) . '</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="wme_mime">' . esc_html__( 'File type', 'wp-media-exporter' ) . '</label></th><td><select name="wme_mime" id="wme_mime">';
    foreach ( wme_mime_groups() as $value => $label ) {
        printf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $label ) );
    }
    echo '</select></td></tr>';
    printf(
        '<tr><th scope="row"><label for="wme_after">%s</label></th><td><input type="date" name="wme_after" id="wme_after"> %s <input type="date" name="wme_before"></td></tr>',
        esc_html__( 'Uploaded between', 'wp-media-exporter' ),
        esc_html__( 'and', 'wp-media-exporter' )
    );
    printf(
        '<tr><th scope="row"><label for="wme_s">%s</label></th><td><input type="search" name="wme_s" id="wme_s" class="regular-text" placeholder="%s"></td></tr>',
        esc_html__( 'Search titles', 'wp-media-exporter' ),
        esc_attr__( 'e.g. product', 'wp-media-exporter' )
    );
    echo '</tbody></table>';
    wp_nonce_field( 'wme_start' );
    submit_button( __( 'Build export ZIP', 'wp-media-exporter' ), 'primary', 'wme_start' );
    echo '</form>';

    // Recent exports.
    $exports = get_option( WME_OPTION, array() );
    if ( is_array( $exports ) && ! empty( $exports ) ) {
        echo '<h2>' . esc_html__( 'Recent exports', 'wp-media-exporter' ) . '</h2><ul>';
        foreach ( array_reverse( $exports, true ) as $token => $meta ) {
            $file = wme_export_dir() . '/media-export-' . $token . '.zip';
            if ( ! file_exists( $file ) ) {
                continue;
            }
            $url = trailingslashit( $upload['baseurl'] ) . 'media-exports/media-export-' . $token . '.zip';
            printf(
                '<li><a href="%s" download>%s</a> — %s</li>',
                esc_url( $url ),
                esc_html( wp_basename( $file ) ),
                esc_html( sprintf(
                    /* translators: 1: files added, 2: date. */
                    __( '%1$d files, created %2$s', 'wp-media-exporter' ),
                    isset( $meta['added'] ) ? (int) $meta['added'] : 0,
                    date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), isset( $meta['created'] ) ? (int) $meta['created'] : time() )
                ) )
            );
        }
        echo '</ul>';
    }

    echo '</div>';
}
