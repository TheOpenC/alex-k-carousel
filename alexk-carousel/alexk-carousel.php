<?php
// File: wp-content/plugins/client-carousel/client-carousel.php

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
