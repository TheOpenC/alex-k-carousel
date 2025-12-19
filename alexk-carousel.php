<?php


/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Simple random image carousel for a client site.
 * Version: 0.1.4
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
// ************************
// Enqueue Logic // CSS 
// ************************

// admin.css (image menu + checkbox styling)
add_action('admin_footer', 'alexk_carousel_dev_notice');
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style(
        'alexk-carousel-admin',
        plugin_dir_url(__FILE__) . 'admin.css',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'admin.css')
    );
});

// frontend.css landing page styling
function alexk_enqueue_frontend_styles() {
    wp_enqueue_style(
        'alexk-frontend',
        plugin_dir_url(__FILE__) . 'css/frontend.css',
        [],
        '1.0'
    );
}
add_action('wp_enqueue_scripts', 'alexk_enqueue_frontend_styles');

/**
 * Shortcode: [alexk_carousel]
 * For now: just proves the plugin can render on the front-end.
 */
function alexk_carousel_shortcode($atts = []) {
    $atts = shortcode_atts([
        'ids'       => '',    
        'limit'     => 1, // 0 = no limit, > 0 = cap pool size  
        'shuffle'   => 1,
    ], $atts, 'alexk_carousel'); 

$limit = max(0, (int) $atts['limit']);
$shuffle = ((int) $atts['shuffle'] === 1);

        /* =========================
        1) Build image ID list
        ========================= */


if (!empty($atts['ids'])) {
    $ids_string =    
    $ids = explode(',', $atts['ids']); // js split
    $ids = array_map('trim', $ids); // js trim whitespace
    $ids = array_map('intval', $ids); // Number
    $ids = array_filter($ids); 
    $ids = array_values($ids);    
} else {
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

if (empty($ids)) {
    return '<div class="alexk-carousel__empty">No images selected for the carousel.</div>';
};

    /* =========================
       2) Shuffle + limit pool
       ========================= */

if ($shuffle) {
    shuffle($ids);
}

if ($limit > 0) {
    $ids = array_slice($ids, 0, $limit);
}


    /* =========================
       3) Build image payload
       ========================= */

    $images = [];

foreach ($ids as $id) { 
    $src = wp_get_attachment_image_url($id, 'large');
    if (!$src) continue;

    $images[] = [
        'src'      => esc_url_raw($src),  
        'srcset'   => wp_get_attachment_image_srcset($id, 'large') ?: '',
        'sizes'    => wp_get_attachment_image_sizes($id, 'large') ?: '',
        'alt'      => get_post_meta($id, '_wp_attachment_image_alt', true) ?: '',
    ];



    if (empty($images)) {
        return '<div class="alexk-carousel__empty">No valid images found.</div>';
    }
}

    /* =========================
       4) Initial image (server)
       ========================= */
    $first = $images[0];
    $data_images = wp_json_encode($images);

    /* =========================
       5) Output ONE image
       ========================= */

    $html = '<div class="alexk-carousel" data-images=\'' . esc_attr($data_images) . '\'>'; 
    $html .= ' <button type="button" class="alexk-carousel__btn" aria-label="Show another image">';
    $html .= ' <img clas="alexk-carousel__img" src="' . esc_url($first['src']) . '"';

    if (!empty($first['srcset'])) {
        $html .= ' srcset="' . esc_attr($first['srcset']) . '"';
    }
    if (!empty($first['sizes'])) {
        $html .= ' sizes="' . esc_attr($first['sizes']) . '"';
    }

    $html .= ' alt="' . esc_attr($first['alt']) . '" loading="lazy" decoding="async" />';
    $html .= ' </button>';
    $html .= '</div>';

    return $html;
}

add_shortcode('alexk_carousel', 'alexk_carousel_shortcode');



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
