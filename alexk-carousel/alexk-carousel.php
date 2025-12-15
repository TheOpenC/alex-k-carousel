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
    $atts = shortcode_atts([
        'ids' => '',
        'limit' => 10,
        'shuffle' => 1,
    ], $atts, 'alexk_carousel'); // guarantees the key exists ( no undefined index)

$limit = max(1, (int) $atts['limit']);
$shuffle = (int) $atts['shuffle'] === 1;


// 1) Build $ids first
$ids_string = $atts['ids'];
$ids = explode(',', $ids_string); // split strings into an array
$ids = array_map('trim', $ids); // remove whitespace
$ids = array_map('intval', $ids); //convert to integer
$ids = array_filter($ids); // removes junk ( 0s for empty entries)
$ids = array_values($ids); // makes the array clean for looping

// 2) Guard if empty
if (empty($ids)){
    return '<div class="alexk-carousel__empty">No ids provided.</div>';
};

// 3) Shuffle + limit safely
if ($shuffle) shuffle($ids);
$ids = array_slice($ids, 0, $limit);


$html = '<div class="alexk-carousel">';

foreach ($ids as $id) {
    $img = wp_get_attachment_image($id, 'large', false, ['loading' => 'lazy']);
    if ($img) {
        $html .= '<div class="alexk-carousel__slide">' . $img . '</div>';
    }
}

$html .= '</div>';

return $html;
}

add_shortcode('alexk_carousel', 'alexk_carousel_shortcode');

function alexk_debug_time() {
    return 'Rendered at: ' . current_time('H:i:s');
}

add_shortcode('alexk_time', 'alexk_debug_time');