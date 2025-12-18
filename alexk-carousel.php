<?php


/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Simple random image carousel for a client site.
 * Version: 0.1.2
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
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style(
        'alexk-carousel-admin',
        plugin_dir_url(__FILE__) . 'admin.css',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'admin.css')
    );
});
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
        'post_type'         => 'attachment',
        'post_mime_type'    => 'image',        
        'post_status'       => 'inherit',
        'fields'            => 'ids',        
        'posts_per_page'    => -1,
        'meta_key'          => '_alexk_include_in_carousel',        
        'meta_value'        => '1',
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
add_filter('attachment_fields_to_edit', 'alexk_add_carousel_checkbox_field', 10, 2);
function alexk_add_carousel_checkbox_field($form_fields, $post) {
    $value = get_post_meta($post->ID, '_alexk_include_in_carousel', true);

    $form_fields['alexk_include_in_carousel'] = array(
        'label' => 'Main Page Image Carousel',
        'input' => 'html',
        // 'html'  => sprintf(
        //     '<label><input type="checkbox" name="attachments[%d][alexk_include_in_carousel]" value="1" %s /> Include in carousel</label>',
        //     (int) $post->ID,
        //     checked($value, '1', false)

        // ),
        'html' => sprintf(
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
            checked($value, '1', false)    
    
        ),
    );

    return $form_fields;
}

add_filter('attachment_fields_to_save', 'alexk_save_carousel_checkbox_field', 10, 2);
function alexk_save_carousel_checkbox_field($post, $attachment) {
    $is_checked = isset($attachment['alexk_include_in_carousel']) && $attachment['alexk_include_in_carousel'] === '1';

    if ($is_checked) {
        update_post_meta($post['ID'], '_alexk_include_in_carousel', '1');
    } else {
        delete_post_meta($post['ID'], '_alexk_include_in_carousel');
    }

    return $post;
}
