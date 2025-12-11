<?php


/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Simple random image carousel for a client site.
 * Version: 0.1.0
 * Author: Drew Dudak
 * Text Domain: alexk-carousel
 */

if (!defined('ABSPATH')) {
    // Abort if this file is loaded directly.
    exit;
}

/**
 * Temporary test hook so we can confirm the plugin is active.
 * We will remove this once real functionality is in place.
 */
function alexk_carousel_dev_notice() {
    echo '<p style="opacity:0.6; text-align:center; margin-top:1rem;">Alex K Image Carousel plugin is active (dev mode).</p>';
}
add_action('admin_footer', 'alexk_carousel_dev_notice');

/**
 * Shortcode: [alexk_carousel]
 * For now: just proves the plugin can render on the front-end.
 */
function alexk_carousel_shortcode($atts = []) {
    // Later we'll accept options like count, category, etc.
    $atts = shortcode_atts([
        'message' => 'Alex K Carousel shortcode is working.',
    ], $atts, 'alexk_carousel');

    // Always return a string from shortcodes (don’t echo).
    $safe_message = esc_html($atts['message']);

    return '<div class="alexk-carousel">' . $safe_message . '</div>';
}

add_shortcode('alexk_carousel', 'alexk_carousel_shortcode');
