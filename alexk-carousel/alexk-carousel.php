<?php
/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Adds "Include in carousel" checkbox and generates responsive JPG+WebP files in a per-image folder (Elementor-safe; no shell). Includes robust cancel to prevent folder reappearing on mid-run uncheck.
 * Version: 0.2.7
 */

if (!defined('ABSPATH')) exit;

// Only keep WP thumbnails alongside the original.
add_filter('intermediate_image_sizes_advanced', function($sizes) {
  $keep = ['thumbnail', 'medium']; // adjust if you want ONLY thumbnail
  return array_intersect_key($sizes, array_flip($keep));
});

// Prevent WP from creating the "-scaled" big image variant.
add_filter('big_image_size_threshold', '__return_false');

function alexk_carousel_widths(): array { return [320, 480, 768, 1024, 1400]; }
function alexk_carousel_meta_key(): string { return 'alexk_include_in_carousel'; }

// Cancellation is done via a filesystem marker OUTSIDE the output folder.
// This avoids WP meta caching and still works across concurrent requests.
function alexk_carousel_cancel_marker_path(int $attachment_id, string $file_path = ''): ?string {
  if ($file_path === '') $file_path = get_attached_file($attachment_id);
  if (!$file_path) return null;

  $dir  = wp_normalize_path(dirname($file_path));
  $base = basename($file_path);
  $stem = preg_replace('/\.[^.]+$/', '', $base);

  // Hidden cancel marker next to original, not inside output folder.
  return $dir . '/.' . $stem . '_' . $attachment_id . '.alexk_cancel';
}

function alexk_carousel_output_dir_for_attachment(int $attachment_id, string $file_path = ''): ?string {
  if ($file_path === '') $file_path = get_attached_file($attachment_id);
  if (!$file_path) return null;

  $dir  = wp_normalize_path(dirname($file_path));
  $base = basename($file_path);
  $stem = preg_replace('/\.[^.]+$/', '', $base);

  // Folder name: {stem}_{id}
  return $dir . '/' . $stem . '_' . $attachment_id;
}

function alexk_carousel_rmdir_recursive(string $dir): void {
  if (!is_dir($dir)) return;
  $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($ri as $file) {
    /** @var SplFileInfo $file */
    $path = $file->getPathname();
    if ($file->isDir()) {
      @rmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

/**
 * ADMIN: checkbox UI (Media)
 */
add_filter('attachment_fields_to_edit', function($form_fields, $post) {
  $key = alexk_carousel_meta_key();
  $val = get_post_meta($post->ID, $key, true);
  $checked = ($val === '1') ? 'checked' : '';

  $form_fields[$key] = [
    'label' => 'Include in carousel',
    'input' => 'html',
    'html'  => '<label class="alexk-carousel-rightside-label">
                  <input type="checkbox" class="carousel-checkbox" name="attachments['.$post->ID.']['.$key.']" value="1" '.$checked.' />
                  Include in carousel
                </label>
                <div class="alexk-carousel-checkbox-details">
                  When checked, generates responsive images and includes file in the image carousel queue.
                </div>',
  ];

  return $form_fields;
}, 10, 2);

/**
 * Save handler
 */
add_filter('attachment_fields_to_save', function($post, $attachment) {
  $key = alexk_carousel_meta_key();
  $new = isset($attachment[$key]) && $attachment[$key] === '1' ? '1' : '0';
  $old = get_post_meta($post['ID'], $key, true);
  $old = ($old === '1') ? '1' : '0';

  update_post_meta($post['ID'], $key, $new);

  $id = (int)$post['ID'];
  $file = get_attached_file($id);

  // Cancel marker path is derived from file; if file missing, just return.
  $cancel_path = $file ? alexk_carousel_cancel_marker_path($id, $file) : null;

  if ($new !== $old) {
    if ($new === '1') {
      // Clear any prior cancel marker BEFORE generating.
      if ($cancel_path && file_exists($cancel_path)) {
        @unlink($cancel_path);
      }
      alexk_generate_carousel_derivatives_for_attachment($id);
    } else {
      // Create cancel marker so any in-flight generator stops ASAP.
      if ($cancel_path) {
        @file_put_contents($cancel_path, (string)time());
      }
      alexk_delete_carousel_derivatives_for_attachment($id);
    }
  }

  return $post;
}, 10, 2);

/**
 * Cleanup on permanent delete
 */
add_action('delete_attachment', function($attachment_id) {
  alexk_delete_carousel_derivatives_for_attachment((int)$attachment_id);
});

/**
 * Derivative generation (no shell/exec)
 */
function alexk_generate_carousel_derivatives_for_attachment(int $attachment_id): void {
  $file = get_attached_file($attachment_id);
  if (!$file || !file_exists($file)) return;

  $mime = get_post_mime_type($attachment_id);
  if (!$mime || strpos($mime, 'image/') !== 0) return;

  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  if (in_array($ext, ['svg', 'pdf'], true)) return;

  $out_dir = alexk_carousel_output_dir_for_attachment($attachment_id, $file);
  if (!$out_dir) return;

  $cancel_path = alexk_carousel_cancel_marker_path($attachment_id, $file);
  if (!$cancel_path) return;

  // If canceled already, do nothing.
  if (file_exists($cancel_path)) return;

  // Create output dir ONCE. After this point, we never mkdir again.
  if (!is_dir($out_dir)) {
    wp_mkdir_p($out_dir);
  }
  if (!is_dir($out_dir)) return;

  $base = basename($file);
  $stem = preg_replace('/\.[^.]+$/', '', $base);

  $info = @getimagesize($file);
  if (!$info || empty($info[0]) || empty($info[1])) return;
  $native_w = (int)$info[0];
  $native_h = (int)$info[1];
  $native_max = max($native_w, $native_h);
  if ($native_max <= 0) return;

  $widths = alexk_carousel_widths();
  $max_list = max($widths);
  if ($native_max < $max_list) {
    $widths[] = $native_max; // include native if smaller than 1400
    $widths = array_values(array_unique($widths));
    sort($widths);
  }

  $largest_generated = 0;

  foreach ($widths as $w) {
    // Hard cancel: if user unchecked, OR output folder was deleted mid-run, abort.
    if (file_exists($cancel_path)) return;
    if (!is_dir($out_dir)) return;

    $w = (int)$w;
    if ($w <= 0) continue;
    if ($w > $native_max) continue; // no upscaling

    $out_webp = $out_dir . '/' . $stem . '-w' . $w . '.webp';
    $out_jpg  = $out_dir . '/' . $stem . '-w' . $w . '.jpg';

    // If folder deleted after our is_dir check, these should fail rather than recreate.
    $ok_webp = alexk_resize_and_write_no_mkdir($file, $out_webp, $w, 'webp', $cancel_path, $out_dir);
    $ok_jpg  = alexk_resize_and_write_no_mkdir($file, $out_jpg,  $w, 'jpg',  $cancel_path, $out_dir);

    if ($ok_webp || $ok_jpg) $largest_generated = max($largest_generated, $w);
  }

  // Convenience siblings inside the folder
  if ($largest_generated > 0) {
    if (file_exists($cancel_path)) return;
    if (!is_dir($out_dir)) return;

    $src_webp = $out_dir . '/' . $stem . '-w' . $largest_generated . '.webp';
    $src_jpg  = $out_dir . '/' . $stem . '-w' . $largest_generated . '.jpg';

    $dst_webp = $out_dir . '/' . $stem . '-carousel.webp';
    $dst_jpg  = $out_dir . '/' . $stem . '-carousel.jpg';

    if (file_exists($src_webp)) @copy($src_webp, $dst_webp);
    if (file_exists($src_jpg))  @copy($src_jpg,  $dst_jpg);
  }
}

function alexk_delete_carousel_derivatives_for_attachment(int $attachment_id): void {
  $file = get_attached_file($attachment_id);
  if (!$file) return;

  $out_dir = alexk_carousel_output_dir_for_attachment($attachment_id, $file);
  if (!$out_dir) return;

  // Delete only our folder; originals and WP thumbs remain untouched.
  alexk_carousel_rmdir_recursive($out_dir);
}

/**
 * Resize/write (Imagick first) — IMPORTANT: never mkdir here.
 */
function alexk_resize_and_write_no_mkdir(string $src, string $dst, int $max_edge, string $format, string $cancel_path, string $out_dir): bool {
  if (file_exists($cancel_path)) return false;
  if (!is_dir($out_dir)) return false;

  if (extension_loaded('imagick')) {
    $ok = alexk_imagick_resize_and_write_no_mkdir($src, $dst, $max_edge, $format, $cancel_path, $out_dir);
    if ($ok) return true;
  }
  return alexk_wp_editor_resize_and_write_no_mkdir($src, $dst, $max_edge, $format, $cancel_path, $out_dir);
}

function alexk_imagick_resize_and_write_no_mkdir(string $src, string $dst, int $max_edge, string $format, string $cancel_path, string $out_dir): bool {
  try {
    if (file_exists($cancel_path)) return false;
    if (!is_dir($out_dir)) return false;

    $im = new Imagick();
    $im->readImage($src);

    if (method_exists($im, 'autoOrient')) $im->autoOrient();
    if (defined('Imagick::COLORSPACE_SRGB')) @ $im->setImageColorspace(Imagick::COLORSPACE_SRGB);

    $w = $im->getImageWidth();
    $h = $im->getImageHeight();
    if ($w <= 0 || $h <= 0) return false;

    $long = max($w, $h);
    if ($max_edge > $long) $max_edge = $long; // no upscale

    $scale = $max_edge / $long;
    $new_w = max(1, (int)round($w * $scale));
    $new_h = max(1, (int)round($h * $scale));

    $im->resizeImage($new_w, $new_h, Imagick::FILTER_LANCZOS, 1, true);
    @ $im->stripImage();

    if (file_exists($cancel_path)) return false;
    if (!is_dir($out_dir)) return false;

    if ($format === 'webp') {
      $im->setImageFormat('webp');
      $im->setImageCompressionQuality(100);
    } else {
      $im->setImageFormat('jpeg');
      $im->setImageCompressionQuality(92);
      @ $im->setOption('jpeg:sampling-factor', '4:4:4');
    }

    // Do NOT mkdir here. If folder was deleted, write should fail.
    $ok = $im->writeImage($dst);
    $im->clear();
    $im->destroy();
    return (bool)$ok;
  } catch (Throwable $e) {
    return false;
  }
}

function alexk_wp_editor_resize_and_write_no_mkdir(string $src, string $dst, int $max_edge, string $format, string $cancel_path, string $out_dir): bool {
  if (file_exists($cancel_path)) return false;
  if (!is_dir($out_dir)) return false;

  $editor = wp_get_image_editor($src);
  if (is_wp_error($editor)) return false;

  $size = $editor->get_size();
  if (empty($size['width']) || empty($size['height'])) return false;

  $w = (int)$size['width'];
  $h = (int)$size['height'];
  $long = max($w, $h);
  if ($long <= 0) return false;

  if ($max_edge > $long) $max_edge = $long;

  $res = $editor->resize($max_edge, $max_edge, false);
  if (is_wp_error($res)) return false;

  if (file_exists($cancel_path)) return false;
  if (!is_dir($out_dir)) return false;

  if ($format === 'webp') {
    $editor->set_quality(100);
    $saved = $editor->save($dst, 'image/webp');
  } else {
    $editor->set_quality(92);
    $saved = $editor->save($dst, 'image/jpeg');
  }

  
  return (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path']));
}

/** ==========================================
 * Frontend assets (loads JS/CSS for the carousel)
 * ENQUEUE OF JS / CSS FILES
 * ========================================== */

add_action('wp_enqueue_scripts', function () {
  // Only load assets on pages that actually contain the shortcode.
  if (!is_singular()) return;

  $post = get_post();
  if (!$post) return;

  if (!has_shortcode($post->post_content, 'alexk_carousel')) return;

  $base_url  = plugin_dir_url(__FILE__);
  $base_path = plugin_dir_path(__FILE__);

  $reset_rel = 'css/reset.css';
  $css_rel   = 'css/frontend.css';
  $js_rel    = 'js/alexk-carousel.js';

  

    if (file_exists($base_path . $reset_rel)) {
      wp_enqueue_style('alexk-carousel-page-reset', $base_url . $reset_rel, [], filemtime($base_path .  $reset_rel)
      );

      // remove later. css file enqueue confirmation:
      wp_add_inline_style(
        'alexk-carousel-frontend',
        '/* alexk-carousel frontend css loaded */'
        );
    
    }
  
  

  if (file_exists($base_path . $css_rel)) {
    wp_enqueue_style('alexk-carousel-frontend', $base_url . $css_rel, ['alexk-carousel-page-reset'], filemtime($base_path . $css_rel));
  }

  if (file_exists($base_path . $js_rel)) {
    wp_enqueue_script('alexk-carousel-frontend', $base_url . $js_rel, [], filemtime($base_path . $js_rel), true);
  }
});




/**
 * Shortcode output
 */
add_shortcode('alexk_carousel', function($atts = []) {
  $q = new WP_Query([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => -1,
    'meta_key'       => alexk_carousel_meta_key(),
    'meta_value'     => '1',
  ]);

  if (!$q->have_posts()) return '';

  $items = [];
  while ($q->have_posts()) {
    $q->the_post();
    $id = get_the_ID();
    $file = get_attached_file($id);
    if (!$file) continue;

    $base = basename($file);
    $stem = preg_replace('/\.[^.]+$/', '', $base);

    $out_dir = alexk_carousel_output_dir_for_attachment($id, $file);
    if (!$out_dir || !is_dir($out_dir)) continue;

    $webp_srcset = [];
    $jpg_srcset  = [];

    foreach (alexk_carousel_widths() as $w) {
      $p_webp = $out_dir . '/' . $stem . '-w' . $w . '.webp';
      $p_jpg  = $out_dir . '/' . $stem . '-w' . $w . '.jpg';

      if (file_exists($p_webp)) $webp_srcset[] = alexk_path_to_upload_url($p_webp) . " {$w}w";
      if (file_exists($p_jpg))  $jpg_srcset[]  = alexk_path_to_upload_url($p_jpg)  . " {$w}w";
    }

    if (empty($webp_srcset) && empty($jpg_srcset)) continue;

    $fallback = !empty($jpg_srcset)
      ? alexk_path_to_upload_url($out_dir . '/' . $stem . '-carousel.jpg')
      : alexk_path_to_upload_url($out_dir . '/' . $stem . '-carousel.webp');

    $items[] = [
      'webp_srcset' => implode(', ', $webp_srcset),
      'jpg_srcset'  => implode(', ', $jpg_srcset),
      'fallback'    => esc_url($fallback),
      'alt'         => esc_attr(get_post_meta($id, '_wp_attachment_image_alt', true)),
    ];
  }
  wp_reset_postdata();

  if (empty($items)) return '';

  ob_start(); ?>
<div class="alexk-carousel-page">
  <div class="alexk-carousel" data-images="<?php echo esc_attr(wp_json_encode($items)); ?>">
    <picture class="alexk-carousel-picture">
      <?php if (!empty($items[0]['webp_srcset'])): ?>
        <source type="image/webp" srcset="<?php echo esc_attr($items[0]['webp_srcset']); ?>" 
        sizes="(max-width: 1400px) 100vw, 1400px">
      <?php endif; ?>

      <?php if (!empty($items[0]['jpg_srcset'])): ?>
        <source type="image/jpeg" srcset="<?php echo esc_attr($items[0]['jpg_srcset']); ?>" 
        sizes="(max-width: 1400px) 100vw, 1400px">
      <?php endif; ?>

      <img class="alexk-carousel-image"
           src="<?php echo $items[0]['fallback']; ?>"
           alt="<?php echo $items[0]['alt']; ?>"
           sizes="(max-width: 1400px) 100vw, 1400px"
           loading="lazy"
           decoding="async">
    </picture>
  </div>
</div>
<?php
return ob_get_clean();

});

function alexk_path_to_upload_url(string $abs_path): string {
  $uploads = wp_get_upload_dir();
  $basedir = wp_normalize_path($uploads['basedir'] ?? '');
  $baseurl = $uploads['baseurl'] ?? '';
  $abs_path = wp_normalize_path($abs_path);

  if ($basedir && strpos($abs_path, $basedir) === 0) {
    $rel = ltrim(substr($abs_path, strlen($basedir)), '/');
    return trailingslashit($baseurl) . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
  }
  return '';
}
