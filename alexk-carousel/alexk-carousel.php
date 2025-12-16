<?php


/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Simple random image carousel for a client site.
 * Version: 0.1.1
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
        'limit' => 1, // number of images to display. This is a default value, not law. Overwritten by page editor
        'shuffle' => 1,
    ], $atts, 'alexk_carousel'); // guarantees the key exists ( no undefined index)

$limit = max(1, (int) $atts['limit']);
$shuffle = (int) $atts['shuffle'] === 1;


// 1) Build $ids first from shortcode OR from selected attachment meta

$ids = array();

if (!empty($atts['ids'])) {
    // ids were explicitly provided -> parse them
    $ids_string = $atts['ids'];    
    $ids = explode(',', $ids_string); // js split
    $ids = array_map('trim', $ids); // js trim whitespace
    $ids = array_map('intval', $ids); // Number
    $ids = array_filter($ids); 
    $ids = array_values($ids);    
} else {
    // no ids provided -> fetch selected images from Media Library meta
    $ids = get_posts(array(
        'post_type'     => 'attachment',
        'post_mime_type'=> 'image',        
        'post_status'   => 'inherit',
        'fields'        => 'ids',        
        'post_per_page' => -1,
        'meta_key'      => '_alexk_include_in_carousel',        
        'meta_value'    => '1',
    ));
}

// 2) Guard if still empty (no ids provided AND no images selected)
if (empty($ids)) {
    return '<div class="alexk-carousel__empty">No images selected for the carousel.</div>';
};

// 3) Shuffle + limit safely
if ($shuffle) shuffle($ids);
$ids = array_slice($ids, 0, $limit);


$html = '<div class="alexk-carousel">';

foreach ($ids as $id) { //loops through 
    $img = wp_get_attachment_image($id, 'large', false, ['loading' => 'lazy']); // generate img tag (srcset)
    if ($img) { // skips any ID that isn't an image
        $html .= '<div class="alexk-carousel__slide">' . $img . '</div>';
    }
}

$html .= '</div>'; 

return $html; // returns a string of html
}

add_shortcode('alexk_carousel', 'alexk_carousel_shortcode');

function alexk_debug_time() {
    return 'Rendered at: ' . current_time('H:i:s');
}

add_shortcode('alexk_time', 'alexk_debug_time');

// ======================================================
// Media Library: "Include in carousel" checkbox
// ======================================================
