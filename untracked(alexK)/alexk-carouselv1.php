<?php
/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Simple image shuffle carousel for Alex Kwartler. Responsive images generated upon inclusion in image carousel.
 * Version: 0.1.0
 * Author: Drew Dudak
 * Text Domain: alexk-carousel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('admin_init', function () {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('alexk_img_engine_checked') ) return;

    $editor = wp_get_image_editor( __DIR__ . '/README.md' ); // dummy; will fail, but we can still read the class availability
    $has_imagick = class_exists('Imagick');
    $has_gd = extension_loaded('gd');

    error_log('ALEXK engine check: Imagick=' . ($has_imagick ? 'yes':'no') . ' GD=' . ($has_gd ? 'yes':'no'));

    update_option('alexk_img_engine_checked', 1);
});

/**
 * Disable WordPress intermediate image generation for PNG uploads.
 * We only want the ORIGINAL upload; carousel derivatives are opt-in.
 */
add_filter( 'intermediate_image_sizes_advanced', function ( $sizes, $metadata ) {
    if ( empty( $metadata['file'] ) ) {
        return $sizes;
    }

    $ext = strtolower( pathinfo( $metadata['file'], PATHINFO_EXTENSION ) );

    if ( $ext === 'png' ) {
        return [];
    }

    return $sizes;
}, 10, 2 );

/**
 * Basic plugin constants (keeps paths/URLs consistent and readable).
 */
define( 'ALEXK_CAROUSEL_VERSION', '0.1.0' );

define( 'ALEXK_CAROUSEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALEXK_CAROUSEL_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Enqueue frontend assets (CSS + JS) for the carousel.
 * Uses filemtime() so browsers auto-refresh when you change files.
 */
function alexk_enqueue_frontend_assets() {

	$use_filemtime = defined( 'WP_DEBUG' ) && WP_DEBUG;

	$css_rel  = 'css/frontend.css';
	$css_file = ALEXK_CAROUSEL_PATH . $css_rel;

	wp_enqueue_style(
		'alexk-carousel-frontend',
		ALEXK_CAROUSEL_URL . $css_rel,
		[],
		( $use_filemtime && file_exists( $css_file ) )
			? filemtime( $css_file )
			: ALEXK_CAROUSEL_VERSION
	);

	$js_rel  = 'js/alexk-carousel.js';
	$js_file = ALEXK_CAROUSEL_PATH . $js_rel;

	wp_enqueue_script(
		'alexk-carousel-js',
		ALEXK_CAROUSEL_URL . $js_rel,
		[],
		( $use_filemtime && file_exists( $js_file ) )
			? filemtime( $js_file )
			: ALEXK_CAROUSEL_VERSION,
		true
	);
}

add_action( 'wp_enqueue_scripts', 'alexk_enqueue_frontend_assets' );

/**
 * ======================================================
 * IMPORTANT CHANGE (Jan 2026)
 * We do NOT generate any responsive derivatives on upload anymore.
 * Upload should store ONLY the original.
 *
 * Derivatives are generated ONLY when the user checks
 * "Add to carousel" in the Media Library.
 * ======================================================
 */

/**
 * Generate responsive siblings for a single attachment.
 * - Writes next to the original upload.
 * - Skips files that already exist.
 * - Logs errors only.
 */
function alexk_generate_carousel_derivatives_for_attachment( $attachment_id ) {
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}

	$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$allowed_exts = [ 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'gif', 'bmp', 'heic', 'heif', 'webp', 'avif' ];
	if ( ! in_array( $ext, $allowed_exts, true ) ) {
		return;
	}

	$script = ALEXK_CAROUSEL_PATH . 'bin/convert-one.sh';
	if ( ! file_exists( $script ) ) {
		error_log( 'ALEXK generate: convert-one.sh missing at: ' . $script );
		return;
	}

	if ( ! function_exists( 'exec' ) ) {
		error_log( 'ALEXK generate: exec() not available in PHP.' );
		return;
	}

	$max_edge = 1400;
	$env      = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin MAGICK_BIN=/usr/local/bin/magick';
	$cmd      = $env . ' /bin/bash ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( (string) $max_edge ) . ' 2>&1';

	$output = [];
	$code   = 0;
	exec( $cmd, $output, $code );

	if ( $code !== 0 ) {
		error_log( 'ALEXK generate: convert-one.sh exit=' . $code . ' attachment_id=' . $attachment_id );
		if ( ! empty( $output ) ) {
			error_log( "ALEXK generate: output:\n" . implode( "\n", $output ) );
		}
	}
}

/**
 * Delete responsive siblings for a single attachment.
 * Deletes only files we create:
 *   <stem>-w{320,480,768,1024,1400}.{jpg,webp}
 * and (optionally) <stem>.jpg / <stem>.webp ONLY if they are NOT the original upload.
 */
function alexk_delete_carousel_derivatives_for_attachment( $attachment_id ) {
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}

	$dir  = dirname( $file );
	$base = basename( $file );
	$stem = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $base );
	$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

	$widths = [ 320, 480, 768, 1024, 1400 ];
	foreach ( $widths as $w ) {
		$webp = $dir . '/' . $stem . '-w' . $w . '.webp';
		$jpg  = $dir . '/' . $stem . '-w' . $w . '.jpg';
		if ( file_exists( $webp ) ) {
			@unlink( $webp );
		}
		if ( file_exists( $jpg ) ) {
			@unlink( $jpg );
		}
	}

	// Only delete convenience siblings if they are NOT the original.
	// If the original is already .jpg/.jpeg, never delete <stem>.jpg.
	// If the original is already .webp, never delete <stem>.webp.
	if ( $ext !== 'webp' ) {
		$maybe_webp = $dir . '/' . $stem . '.webp';
		if ( file_exists( $maybe_webp ) ) {
			@unlink( $maybe_webp );
		}
	}
	if ( $ext !== 'jpg' && $ext !== 'jpeg' ) {
		$maybe_jpg = $dir . '/' . $stem . '.jpg';
		if ( file_exists( $maybe_jpg ) ) {
			@unlink( $maybe_jpg );
		}
	}
}


/**
 * If an attachment is permanently deleted from the Media Library,
 * also delete our generated carousel derivatives from disk.
 *
 * NOTE: This runs inside wp_delete_attachment() *before* WordPress deletes
 * the original file, so we can still derive the stem/path safely.
 */
function alexk_cleanup_carousel_derivatives_on_attachment_delete( $attachment_id ) {
    alexk_delete_carousel_derivatives_for_attachment( $attachment_id );
}
add_action( 'delete_attachment', 'alexk_cleanup_carousel_derivatives_on_attachment_delete', 10, 1 );


/**
 * ======================================================
 * Carousel helpers: derive sibling URLs next to original
 * ======================================================
 */
function alexk_derive_sibling_url( $original_url, $new_ext ) {
	return preg_replace( '/\.[a-zA-Z0-9]+$/', '.' . $new_ext, $original_url );
}

function alexk_sibling_file_exists_from_url( $url ) {
	$upload_dir = wp_get_upload_dir();
	if ( empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) {
		return false;
	}

	if ( strpos( $url, $upload_dir['baseurl'] ) !== 0 ) {
		return false;
	}

	$relative = ltrim( substr( $url, strlen( $upload_dir['baseurl'] ) ), '/' );
	$path     = trailingslashit( $upload_dir['basedir'] ) . $relative;

    error_log("ALEXK sibling check url=$url path=$path exists=" . (file_exists($path) ? 'yes' : 'no'));


	return file_exists( $path ) && filesize( $path ) > 0;
}

/**
 * Shortcode: [alexk_carousel]
 * Outputs a <picture> so the browser chooses:
 *   WebP (lossless sibling) -> fallback original (JPEG/PNG/etc)
 */
function alexk_carousel_shortcode( $atts = [] ) {
	$atts = shortcode_atts(
		[
			'ids'     => '',
			'limit'   => 1, // 0 = no limit, > 0 = cap pool size
			'shuffle' => 1,
		],
		$atts,
		'alexk_carousel'
	);

	$limit   = max( 0, (int) $atts['limit'] );
	$shuffle = ( (int) $atts['shuffle'] === 1 );

    $widths = [320, 480, 768, 1024, 1400];


	/* 1) Build image ID list */
	if ( ! empty( $atts['ids'] ) ) {
		$ids = explode( ',', $atts['ids'] );
		$ids = array_map( 'trim', $ids );
		$ids = array_map( 'intval', $ids );
		$ids = array_filter( $ids );
		$ids = array_values( $ids );
	} else {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => '_alexk_include_in_carousel',
				'meta_value'     => '1',
			]
		);
	}

	if ( empty( $ids ) ) {
		return '<div class="alexk-carousel__empty">No images selected for the carousel.</div>';
	}

	/* 2) Shuffle + limit pool */
	if ( $shuffle ) {
		shuffle( $ids );
	}
	if ( $limit > 0 ) {
		$ids = array_slice( $ids, 0, $limit );
	}

	/* 3) Build image payload */
	$images = [];

	foreach ( $ids as $id ) {
		$src = wp_get_attachment_url( $id ); // original PNG (final fallback)
        if ( ! $src ) {
	        continue;
        }

        $widths = [ 320, 480, 768, 1024, 1400 ];

        $webp_parts = [];
        $jpg_parts  = [];

        foreach ( $widths as $w ) {
	        $webp_url = preg_replace( '/\.[a-zA-Z0-9]+$/', '-w' . $w . '.webp', $src );
	        if ( alexk_sibling_file_exists_from_url( $webp_url ) ) {
	    	    $webp_parts[] = esc_url_raw( $webp_url ) . ' ' . $w . 'w';
	        }

	        $jpg_url = preg_replace( '/\.[a-zA-Z0-9]+$/', '-w' . $w . '.jpg', $src );
	        if ( alexk_sibling_file_exists_from_url( $jpg_url ) ) {
	        	$jpg_parts[] = esc_url_raw( $jpg_url ) . ' ' . $w . 'w';
	        }
        }

        $images[] = [
        	'src'         => esc_url_raw( $src ),                 // PNG fallback
        	'webp_srcset' => implode( ', ', $webp_parts ),        // your responsive webp set
        	'jpg_srcset'  => implode( ', ', $jpg_parts ),         // your responsive jpg set
        	'sizes'       => '90vw',
        	'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: '',
        ];

	}

	if ( empty( $images ) ) {
	return '<div class="alexk-carousel__empty">No valid images found.</div>';
    }

    /* 4) Initial image (server) */
    $first       = $images[0];
    $data_images = wp_json_encode( $images );

    /* 5) Output */
    $html  = '<div class="alexk-carousel" data-images=\'' . esc_attr( $data_images ) . '\'>';
    $html .= '<button type="button" class="alexk-carousel__btn" aria-label="Show another image">';
    $html .= '<picture class="alexk-carousel__picture">';

    if ( ! empty( $first['webp_srcset'] ) ) {
    	$html .= '<source type="image/webp" srcset="' . esc_attr( $first['webp_srcset'] ) . '" sizes="' . esc_attr( $first['sizes'] ) . '">';
    }

    $html .= '<img class="alexk-carousel__img" src="' . esc_url( $first['src'] ) . '"';

    if ( ! empty( $first['jpg_srcset'] ) ) {
    	$html .= ' srcset="' . esc_attr( $first['jpg_srcset'] ) . '"';
    }

    if ( ! empty( $first['sizes'] ) ) {
    	$html .= ' sizes="' . esc_attr( $first['sizes'] ) . '"';
    }

    $html .= ' alt="' . esc_attr( $first['alt'] ) . '" loading="lazy" decoding="async" />';
    $html .= '</picture>';
    $html .= '</button>';
    $html .= '</div>';

    return $html;

}
add_shortcode( 'alexk_carousel', 'alexk_carousel_shortcode' );


// ======================================================
// Media Library: "Include in carousel" checkbox
// ======================================================
add_filter( 'attachment_fields_to_edit', 'alexk_add_carousel_checkbox_field', 10, 2 );
function alexk_add_carousel_checkbox_field( $form_fields, $post ) {
	$value = get_post_meta( $post->ID, '_alexk_include_in_carousel', true );

	$form_fields['alexk_include_in_carousel'] = [
		'label' => 'Main Page Image Carousel',
		'input' => 'html',
		'html'  => sprintf(
			'<div class="alexk-carousel-rightside-container">
				<label class="alexk-carousel-rightside-label">
					<input class="carousel-checkbox"
						type="checkbox"
						name="attachments[%d][alexk_include_in_carousel]"
						value="1"
						%s
					/>
					Add to carousel.
				</label>
				<div class="alexk-carousel-checkbox-details">
					When checked, this controls whether an image appears on the main page carousel.
				</div>
			</div>',
			(int) $post->ID,
			checked( $value, '1', false )
		),
	];

	return $form_fields;
}

add_filter( 'attachment_fields_to_save', 'alexk_save_carousel_checkbox_field', 10, 2 );
function alexk_save_carousel_checkbox_field( $post, $attachment ) {
	$attachment_id = (int) $post['ID'];
	$was_checked   = get_post_meta( $attachment_id, '_alexk_include_in_carousel', true ) === '1';
	$is_checked    = isset( $attachment['alexk_include_in_carousel'] ) && $attachment['alexk_include_in_carousel'] === '1';

	// Transition: unchecked -> checked
	if ( ! $was_checked && $is_checked ) {
		update_post_meta( $attachment_id, '_alexk_include_in_carousel', '1' );
		alexk_generate_carousel_derivatives_for_attachment( $attachment_id );
		return $post;
	}

	// Transition: checked -> unchecked
	if ( $was_checked && ! $is_checked ) {
		delete_post_meta( $attachment_id, '_alexk_include_in_carousel' );
		alexk_delete_carousel_derivatives_for_attachment( $attachment_id );
		return $post;
	}

	// No transition (keep current state, do nothing else)
	if ( $is_checked ) {
		update_post_meta( $attachment_id, '_alexk_include_in_carousel', '1' );
	} else {
		delete_post_meta( $attachment_id, '_alexk_include_in_carousel' );
	}

	return $post;
}
