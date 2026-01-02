<?php
/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Simple image shuffle carousel for Alex Kwartler.
 * Version: 0.2.1
 * Author: Drew Dudak
 * Text Domain: alexk-carousel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic plugin constants (keeps paths/URLs consistent and readable).
 */
define( 'ALEXK_CAROUSEL_VERSION', '0.2.1' );
define( 'ALEXK_CAROUSEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALEXK_CAROUSEL_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Enqueue frontend assets (CSS + JS) for the carousel.
 * Uses filemtime() so browsers auto-refresh when you change files.
 */
function alexk_enqueue_frontend_assets() {
	$css_rel  = 'css/frontend.css';
	$css_file = ALEXK_CAROUSEL_PATH . $css_rel;

	wp_enqueue_style(
		'alexk-carousel-frontend',
		ALEXK_CAROUSEL_URL . $css_rel,
		[],
		file_exists( $css_file ) ? filemtime( $css_file ) : ALEXK_CAROUSEL_VERSION
	);

	$js_rel  = 'js/alexk-carousel.js';
	$js_file = ALEXK_CAROUSEL_PATH . $js_rel;

	wp_enqueue_script(
		'alexk-carousel-js',
		ALEXK_CAROUSEL_URL . $js_rel,
		[],
		file_exists( $js_file ) ? filemtime( $js_file ) : ALEXK_CAROUSEL_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'alexk_enqueue_frontend_assets' );

/**
 * ======================================================
 * Upload Hook: generate sibling .webp + .jpg on upload
 * (NO extra Media Library items; files are written beside original)
 *
 * This calls: ALEXK_CAROUSEL_PATH . 'bin/convert-one.sh'
 * and forces a sane PATH so Homebrew's `magick` is found.
 * ======================================================
 */
add_filter( 'wp_generate_attachment_metadata', 'alexk_generate_carousel_derivatives_on_upload', 20, 2 );
function alexk_generate_carousel_derivatives_on_upload( $metadata, $attachment_id ) {

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return $metadata;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return $metadata;
	}

	$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$allowed_exts = [ 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'gif', 'bmp', 'heic', 'heif', 'webp', 'avif' ];
	if ( ! in_array( $ext, $allowed_exts, true ) ) {
		return $metadata;
	}

	$script = ALEXK_CAROUSEL_PATH . 'bin/convert-one.sh';
	if ( ! file_exists( $script ) ) {
		error_log( 'ALEXK convert-one.sh missing at: ' . $script );
		return $metadata;
	}

	if ( ! function_exists( 'exec' ) ) {
		error_log( 'ALEXK exec() not available in PHP.' );
		return $metadata;
	}

	$max_edge = 1400;

// Your machine is Intel (x86_64), so Homebrew magick is almost always here:
$magick = '/usr/local/bin/magick';

// Force PATH + force which magick to use
$env = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin MAGICK_BIN=/usr/local/bin/magick';


// Run via bash
$cmd = $env . ' /bin/bash ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg((string) $max_edge) . ' 2>&1';


	$output = [];
	$code   = 0;
	exec( $cmd, $output, $code );

	// If conversion fails, log why (but don't break uploads).
	if ( $code !== 0 ) {
		error_log( 'ALEXK convert-one.sh exit=' . $code );
		if ( ! empty( $output ) ) {
			error_log( "ALEXK convert-one.sh output:\n" . implode( "\n", $output ) );
		}
	}

	return $metadata;
}

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
		$src = wp_get_attachment_url( $id ); // full/original file
		if ( ! $src ) {
			continue;
		}

		$webp_url = alexk_derive_sibling_url( $src, 'webp' );
		$has_webp = alexk_sibling_file_exists_from_url( $webp_url );

		$images[] = [
			'src'    => '', // esc_url_raw( $src ),
			'webp'   => $has_webp ? esc_url_raw( $webp_url ) : '',
			'srcset' => '', // wp_get_attachment_image_srcset( $id, 'large' ) ?: '',
			'sizes'  => wp_get_attachment_image_sizes( $id, 'large' ) ?: '',
			'alt'    => get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: '',
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

	if ( ! empty( $first['webp'] ) ) {
		$html .= '<source type="image/webp" srcset="' . esc_url( $first['webp'] ) . '">';
	}

	$html .= '<img class="alexk-carousel__img" src="' . esc_url( $first['src'] ) . '"';

	if ( ! empty( $first['srcset'] ) ) {
		$html .= ' srcset="' . esc_attr( $first['srcset'] ) . '"';
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
	$is_checked = isset( $attachment['alexk_include_in_carousel'] ) && $attachment['alexk_include_in_carousel'] === '1';

	if ( $is_checked ) {
		update_post_meta( $post['ID'], '_alexk_include_in_carousel', '1' );
	} else {
		delete_post_meta( $post['ID'], '_alexk_include_in_carousel' );
	}

	return $post;
}
