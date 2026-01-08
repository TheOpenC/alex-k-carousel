<?php
/**
 * Plugin Name: Alex K - Client Image Carousel
 * Description: Image Carousel for displaying documentation. 5 - 8 file Process bulk carousel jobs via admin AJAX ticks instead of WP-Cron. Fast version
 * Version: 0.3.3
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * CONFIG
 * ======================================================= */

function alexk_carousel_meta_key(): string { return 'alexk_include_in_carousel'; }
function alexk_carousel_widths(): array { return [320, 480, 768, 1024, 1400]; }

/**
 * Keep WP's own thumbnails minimal (optional).
 */
add_filter('intermediate_image_sizes_advanced', function($sizes) {
  $keep = ['thumbnail', 'medium'];
  return array_intersect_key($sizes, array_flip($keep));
});

/**
 * Prevent WP from creating the "-scaled" big-image variant.
 */
add_filter('big_image_size_threshold', '__return_false');


/* =========================================================
 * PATH HELPERS
 * ======================================================= */

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
    if ($file->isDir()) @rmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

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

function alexk_schedule_generate_derivatives(int $attachment_id): void {
  $hook = 'alexk_generate_derivatives_cron';
  $args = [$attachment_id];

  // Avoid duplicate jobs for the same attachment.
  if (wp_next_scheduled($hook, $args)) return;

  wp_schedule_single_event(time() + 5, $hook, $args);
}



/* =========================================================
 * ADMIN UI: Attachment checkbox + Media grid indicator
 * ======================================================= */

/**
 * Attachment edit sidebar checkbox (Media modal + attachment edit screen).
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
                  When checked, this media item is included in the carousel and responsive images are generated.
                </div>',
  ];

  return $form_fields;
}, 10, 2);

/**
 * Save handler for the checkbox.
 */
add_filter('attachment_fields_to_save', function($post, $attachment) {
  $key = alexk_carousel_meta_key();
  $new = (isset($attachment[$key]) && $attachment[$key] === '1') ? '1' : '0';

  $old = get_post_meta($post['ID'], $key, true);
  $old = ($old === '1') ? '1' : '0';

  update_post_meta($post['ID'], $key, $new);

  $id = (int)$post['ID'];
  $file = get_attached_file($id);
  $cancel_path = $file ? alexk_carousel_cancel_marker_path($id, $file) : null;

  if ($new !== $old) {
    if ($new === '1') {
      // Clear any prior cancel marker BEFORE generating.
      if ($cancel_path && file_exists($cancel_path)) @unlink($cancel_path);
      alexk_generate_carousel_derivatives_for_attachment($id);
    } else {
      // Create cancel marker so any in-flight generator stops ASAP.
      if ($cancel_path) @file_put_contents($cancel_path, (string)time());
      alexk_delete_carousel_derivatives_for_attachment($id);
    }
  }

  return $post;
}, 10, 2);


/**
 * ADMIN (List View): Bulk actions in Media Library table view (upload.php)
 * Adds "Add to carousel" + "Remove from carousel" to the Bulk Actions dropdown.
 * Runs server-side on submit (no wp.media JS involved).
 */

// Register the actions in the bulk dropdown (top + bottom).
add_filter('bulk_actions-upload', function (array $actions): array {
  $actions['alexk_add_to_carousel'] = 'Add to carousel';
  $actions['alexk_remove_from_carousel'] = 'Remove from carousel';
  return $actions;
});

// Handle the bulk action when user clicks "Apply".
add_filter('handle_bulk_actions-upload', function (string $redirect_url, string $action, array $post_ids): string {
  if ($action !== 'alexk_add_to_carousel' && $action !== 'alexk_remove_from_carousel') {
    return $redirect_url;
  }

  if (!current_user_can('upload_files')) {
    return $redirect_url;
  }

  // Keep long-ish batches from timing out as easily.
  if (function_exists('set_time_limit')) {
    @set_time_limit(60);
  }

  $key = alexk_carousel_meta_key();
  $updated = 0;

  foreach ($post_ids as $id) {
    $id = (int) $id;
    if ($id <= 0) continue;

    if ($action === 'alexk_add_to_carousel') {
      update_post_meta($id, $key, '1');
      alexk_schedule_generate_derivatives($id); // <- returns immediately

      $updated++;
    } else {
      update_post_meta($id, $key, '0');
      alexk_delete_carousel_derivatives_for_attachment($id);
      $updated++;
    }
  }

  // Add a query arg so we can show an admin notice on redirect.
  $redirect_url = add_query_arg([
    'alexk_list_bulk' => $action,
    'alexk_updated'   => $updated,
  ], $redirect_url);

  return $redirect_url;
}, 10, 3);

// Admin notice after redirect.
add_action('admin_notices', function () {
  global $pagenow;
  if ($pagenow !== 'upload.php') return;

  if (empty($_GET['alexk_list_bulk']) || !isset($_GET['alexk_updated'])) return;

  $action  = sanitize_text_field((string) $_GET['alexk_list_bulk']);
  $updated = (int) $_GET['alexk_updated'];

  if ($action === 'alexk_add_to_carousel') {
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo esc_html("Added {$updated} item(s) to the carousel.");
    echo '</p></div>';
  } elseif ($action === 'alexk_remove_from_carousel') {
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo esc_html("Removed {$updated} item(s) from the carousel.");
    echo '</p></div>';
  }
});

// list view column
// Media Library (List View) — add "Carousel" column.
add_filter('manage_media_columns', function (array $columns): array {
  // Put it after the "Title" column if possible.
  $out = [];
  foreach ($columns as $key => $label) {
    $out[$key] = $label;
    if ($key === 'title') {
      $out['alexk_carousel'] = 'Carousel';
    }
  }
  if (!isset($out['alexk_carousel'])) {
    $out['alexk_carousel'] = 'Carousel';
  }
  return $out;
});

// Media Library (List View) — render green dot for carousel items.
add_action('manage_media_custom_column', function (string $column_name, int $post_id): void {
  if ($column_name !== 'alexk_carousel') return;

  $key = alexk_carousel_meta_key();
  $val = get_post_meta($post_id, $key, true);

  if ((string)$val === '1') {
    echo '<span class="alexk-carousel-dot" aria-label="In carousel" title="In carousel"></span>';
  } else {
    echo '';
  }
}, 10, 2);





/**
 * Media Library grid "badge" support:
 * Expose a boolean for each attachment to JS via wp_prepare_attachment_for_js.
 */
add_filter('wp_prepare_attachment_for_js', function($response, $attachment, $meta) {
  $id = (int)($attachment->ID ?? 0);
  if ($id) {
    $response['alexk_in_carousel'] = (get_post_meta($id, alexk_carousel_meta_key(), true) === '1');
  } else {
    $response['alexk_in_carousel'] = false;
  }
  return $response;
}, 10, 3);


/* =========================================================
 * CLEANUP
 * ======================================================= */

add_action('delete_attachment', function($attachment_id) {
  alexk_delete_carousel_derivatives_for_attachment((int)$attachment_id);
});


/* =========================================================
 * DERIVATIVE GENERATION (no shell/exec)
 * ======================================================= */

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
  if (!is_dir($out_dir)) wp_mkdir_p($out_dir);
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

  // Bulk UI: initialize per-file progress (optional)
  $job = alexk_bulk_job_get();
  if (!empty($job['pending']) && !empty($job['started'])) {
    alexk_bulk_job_patch([
      'current_attachment_id' => $attachment_id,
      'current_filename'      => basename($file),
      'file_pending'          => count($widths),
      'file_done'             => 0,
    ]);
  }

  $largest_generated = 0;

  foreach ($widths as $w) {
    // Hard cancel: if user unchecked OR output folder deleted mid-run, abort.
    if (file_exists($cancel_path)) return;
    if (!is_dir($out_dir)) return;

    $w = (int)$w;
    if ($w <= 0) continue;
    if ($w > $native_max) continue; // no upscaling

    $out_webp = $out_dir . '/' . $stem . '-w' . $w . '.webp';
    $out_jpg  = $out_dir . '/' . $stem . '-w' . $w . '.jpg';

    $ok_webp = alexk_resize_and_write_no_mkdir($file, $out_webp, $w, 'webp', $cancel_path, $out_dir);
    $ok_jpg  = alexk_resize_and_write_no_mkdir($file, $out_jpg,  $w, 'jpg',  $cancel_path, $out_dir);

    if ($ok_webp || $ok_jpg) $largest_generated = max($largest_generated, $w);

    // Bulk UI: bump file_done once per width step
    $job = alexk_bulk_job_get();
    if (!empty($job['pending']) && !empty($job['started'])) {
      $done = (int)($job['file_done'] ?? 0);
      alexk_bulk_job_patch(['file_done' => $done + 1]);
    }
  }

  // Convenience siblings inside the folder (for a stable "fallback" filename)
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

  // Bulk UI: clear "current file" display once this attachment finishes
  $job = alexk_bulk_job_get();
  if (!empty($job['pending']) && !empty($job['started'])) {
    alexk_bulk_job_patch([
      'current_attachment_id' => 0,
      'current_filename'      => '',
      'file_pending'          => 0,
      'file_done'             => 0,
    ]);
  }
}

function alexk_delete_carousel_derivatives_for_attachment(int $attachment_id): void {
  $file = get_attached_file($attachment_id);
  if (!$file) return;

  $out_dir = alexk_carousel_output_dir_for_attachment($attachment_id, $file);
  if (!$out_dir) return;

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


/* =========================================================
 * FRONTEND: enqueue assets + shortcode
 * ======================================================= */

add_action('wp_enqueue_scripts', function () {
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
    wp_enqueue_style('alexk-carousel-page-reset', $base_url . $reset_rel, [], filemtime($base_path . $reset_rel));
  }
  if (file_exists($base_path . $css_rel)) {
    wp_enqueue_style('alexk-carousel-frontend', $base_url . $css_rel, ['alexk-carousel-page-reset'], filemtime($base_path . $css_rel));
  }
  if (file_exists($base_path . $js_rel)) {
    wp_enqueue_script('alexk-carousel-frontend', $base_url . $js_rel, [], filemtime($base_path . $js_rel), true);
  }
});

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
        <source type="image/webp" srcset="<?php echo esc_attr($items[0]['webp_srcset']); ?>" sizes="(max-width: 1400px) 100vw, 1400px">
      <?php endif; ?>
      <?php if (!empty($items[0]['jpg_srcset'])): ?>
        <source type="image/jpeg" srcset="<?php echo esc_attr($items[0]['jpg_srcset']); ?>" sizes="(max-width: 1400px) 100vw, 1400px">
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


/* =========================================================
 * ADMIN: enqueue bulk UI + styles
 * ======================================================= */

add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook !== 'upload.php') return;
  wp_enqueue_media();

  $css_handle = 'alexk-carousel-admin';
  $css_src = plugins_url('css/admin.css', __FILE__);
  wp_enqueue_style($css_handle, $css_src, [], '0.2.9');

  $js_handle = 'alexk-carousel-admin-bulk';
  $js_src    = plugins_url('js/admin-bulk.js', __FILE__);
  wp_enqueue_script($js_handle, $js_src, ['media-views'], '0.2.9', true);

  wp_add_inline_script($js_handle, 'window.ALEXK_BULK = ' . wp_json_encode([
    'nonce' => wp_create_nonce('alexk_bulk_add_to_carousel'),
  ]) . ';', 'before');
});


/* =========================================================
 * BULK QUEUE + PROGRESS (WP-Cron)
 * ======================================================= */

function alexk_bulk_job_key(): string { return 'alexk_bulk_job'; }

function alexk_bulk_job_set(array $job): void {
  update_option(alexk_bulk_job_key(), $job, false);
}

function alexk_bulk_job_get(): array {
  $job = get_option(alexk_bulk_job_key(), []);
  return is_array($job) ? $job : [];
}

function alexk_bulk_job_patch(array $patch): void {
  $job = alexk_bulk_job_get();
  if (!is_array($job)) $job = [];
  alexk_bulk_job_set(array_merge($job, $patch));
}
// Old version (cron)
// function alexk_bulk_job_clear(): void {
//   alexk_bulk_job_set([
//     'pending'               => 0,
//     'done'                  => 0,
//     'started'               => 0,
//     'mode'                  => '',
//     'current_attachment_id' => 0,
//     'current_filename'      => '',
//     'file_pending'          => 0,
//     'file_done'             => 0,
//   ]);
// }

function alexk_bulk_job_clear(): void {
  alexk_bulk_job_set([
    'pending'               => 0,
    'done'                  => 0,
    'total'                 => 0,
    'queue'                 => [],
    'started'               => 0,
    'mode'                  => '',
    'current_attachment_id' => 0,
    'current_filename'      => '',
    'file_pending'          => 0,
    'file_done'             => 0,
  ]);
}

/**
 * Cron worker: generate derivatives for one attachment
 */
add_action('alexk_do_generate_derivatives', function ($attachment_id) {
  $attachment_id = (int)$attachment_id;
  if (!$attachment_id) return;

  alexk_generate_carousel_derivatives_for_attachment($attachment_id);

  $job = alexk_bulk_job_get();
  if (!empty($job['pending'])) {
    $job['done'] = (int)($job['done'] ?? 0) + 1;

    if ((int)$job['done'] >= (int)$job['pending']) {
      alexk_bulk_job_clear();
      return;
    }

    alexk_bulk_job_set($job);
  }
}, 10, 1);

add_action('alexk_generate_derivatives_cron', function (int $attachment_id): void {
  $attachment_id = (int) $attachment_id;
  if ($attachment_id <= 0) return;

  // Only generate if still included.
  $key = alexk_carousel_meta_key();
  if ((string) get_post_meta($attachment_id, $key, true) !== '1') return;

  alexk_generate_carousel_derivatives_for_attachment($attachment_id);
});


/**
 * Cron worker: delete derivatives for one attachment
 */
add_action('alexk_do_delete_derivatives', function ($attachment_id) {
  $attachment_id = (int)$attachment_id;
  if (!$attachment_id) return;

  alexk_delete_carousel_derivatives_for_attachment($attachment_id);

  $job = alexk_bulk_job_get();
  if (!empty($job['pending'])) {
    $job['done'] = (int)($job['done'] ?? 0) + 1;

    if ((int)$job['done'] >= (int)$job['pending']) {
      alexk_bulk_job_clear();
      return;
    }

    alexk_bulk_job_set($job);
  }
}, 10, 1);


/* =========================================================
 * BULK AJAX: add/remove + job status
 * ======================================================= */

add_action('wp_ajax_alexk_bulk_add_to_carousel', function () {
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alexk_bulk_add_to_carousel')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if (!current_user_can('upload_files')) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  if (empty($_POST['ids'])) {
    wp_send_json_error(['message' => 'No attachment IDs provided'], 400);
  }

  $ids = array_filter(array_map('intval', explode(',', (string) $_POST['ids'])));
  // $queued = 0; OLD CRON
  $queued = count($ids);



  foreach ($ids as $attachment_id) {
    if (!$attachment_id) continue;
    if (get_post_type($attachment_id) !== 'attachment') continue;

    update_post_meta($attachment_id, alexk_carousel_meta_key(), '1');

    // Clear cancel marker BEFORE background generation
    $file = get_attached_file($attachment_id);
    if ($file) {
      $cancel_path = alexk_carousel_cancel_marker_path($attachment_id, $file);
      if ($cancel_path && file_exists($cancel_path)) @unlink($cancel_path);
    }

    $when = time() + 1;
    // OLD CRON
    // $scheduled = wp_schedule_single_event($when, 'alexk_do_generate_derivatives', [$attachment_id]);
    // if ($scheduled) $queued++;
    wp_schedule_single_event($when, 'alexk_do_generate_derivatives', [$attachment_id]);

  }
  // OLD CRON
  // alexk_bulk_job_set([
  //   'pending' => $queued,
  //   'done'    => 0,
  //   'started' => time(),
  //   'mode'    => 'add',
  //   'current_attachment_id' => 0,
  //   'current_filename'      => '',
  //   'file_pending'          => 0,
  //   'file_done'             => 0,
  // ]);

  alexk_bulk_job_set([
  'pending' => $queued,
  'done'    => 0,
  'total'   => $queued,
  'queue'   => array_values($ids),
  'started' => time(),
  'mode'    => 'add',
  'current_attachment_id' => 0,
  'current_filename'      => '',
  'file_pending'          => 0,
  'file_done'             => 0,
]);


  wp_send_json_success(['updated' => $queued]);
});

add_action('wp_ajax_alexk_bulk_remove_from_carousel', function () {
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alexk_bulk_add_to_carousel')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if (!current_user_can('upload_files')) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  if (empty($_POST['ids'])) {
    wp_send_json_error(['message' => 'No attachment IDs provided'], 400);
  }

  $ids = array_filter(array_map('intval', explode(',', (string) $_POST['ids'])));
  // $queued = 0; OLD CRON
  $queued = count($ids);


  foreach ($ids as $attachment_id) {
    if (!$attachment_id) continue;
    if (get_post_type($attachment_id) !== 'attachment') continue;

    update_post_meta($attachment_id, alexk_carousel_meta_key(), '0');

    // Create cancel marker so any in-flight generator stops ASAP
    $file = get_attached_file($attachment_id);
    if ($file) {
      $cancel_path = alexk_carousel_cancel_marker_path($attachment_id, $file);
      if ($cancel_path) @file_put_contents($cancel_path, (string) time());
    }

    $when = time() + 1;
    // ✅ correct hook for delete OLD CRON
    // $scheduled = wp_schedule_single_event($when, 'alexk_do_delete_derivatives', [$attachment_id]);
    // if ($scheduled) $queued++;
    wp_schedule_single_event($when, 'alexk_do_delete_derivatives', [$attachment_id]);

  }
  // OLD CRON
  // alexk_bulk_job_set([
  //   'pending' => $queued,
  //   'done'    => 0,
  //   'started' => time(),
  //   'mode'    => 'remove',
  //   'current_attachment_id' => 0,
  //   'current_filename'      => '',
  //   'file_pending'          => 0,
  //   'file_done'             => 0,
  // ]);

  alexk_bulk_job_set([
  'pending' => $queued,
  'done'    => 0,
  'total'   => $queued,
  'queue'   => array_values($ids),
  'started' => time(),
  'mode'    => 'remove',
  'current_attachment_id' => 0,
  'current_filename'      => '',
  'file_pending'          => 0,
  'file_done'             => 0,
]);


  wp_send_json_success(['updated' => $queued]);
});

// OLD CRON
// add_action('wp_ajax_alexk_bulk_job_status', function () {
//   if (!current_user_can('upload_files')) {
//     wp_send_json_error(['message' => 'Permission denied'], 403);
//   }

//   $job = alexk_bulk_job_get();

//   wp_send_json_success([
//     'pending'               => (int)($job['pending'] ?? 0),
//     'done'                  => (int)($job['done'] ?? 0),
//     'mode'                  => (string)($job['mode'] ?? ''),
//     'started'               => (int)($job['started'] ?? 0),

//     'current_attachment_id' => (int)($job['current_attachment_id'] ?? 0),
//     'current_filename'      => (string)($job['current_filename'] ?? ''),
//     'file_pending'          => (int)($job['file_pending'] ?? 0),
//     'file_done'             => (int)($job['file_done'] ?? 0),
//   ]);
// });
add_action('wp_ajax_alexk_bulk_job_status', function () {
  if (!current_user_can('upload_files')) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }

  $job = alexk_bulk_job_get();

  // Elementor-safe worker: advance 1 item per status poll
  $mode  = (string)($job['mode'] ?? '');
  $queue = $job['queue'] ?? [];
  $total = (int)($job['total'] ?? ($job['pending'] ?? 0));
  $batch = 5; // increase to 8 later if server can handle it


  // Slow, single file process version.
  // if (!empty($job['started']) && is_array($queue) && !empty($queue) && ($mode === 'add' || $mode === 'remove')) {
  //   $attachment_id = (int)array_shift($queue);

  //   if ($attachment_id > 0 && get_post_type($attachment_id) === 'attachment') {
  //     if ($mode === 'add') {
  //       alexk_generate_carousel_derivatives_for_attachment($attachment_id);
  //     } else {
  //       alexk_delete_carousel_derivatives_for_attachment($attachment_id);
  //     }
  //   }

  //   // update job
  //   $job['queue'] = array_values($queue);
  //   $job['done']  = (int)($job['done'] ?? 0) + 1;
  //   $job['pending'] = max(0, (int)($job['pending'] ?? 0) - 1);


  //   // finish?
  //   if ($total > 0 && (int)$job['done'] >= $total) {
  //     alexk_bulk_job_clear();
  //     $job = alexk_bulk_job_get(); // now cleared
  //   } else {
  //     alexk_bulk_job_set($job);
  //   }
  // }

  // Fast, 5 - 8 file version
  if (!empty($job['started']) && is_array($queue) && !empty($queue) && ($mode === 'add' || $mode === 'remove')) {

  for ($i = 0; $i < $batch; $i++) {
    if (empty($queue)) break;

    $attachment_id = (int)array_shift($queue);

    if ($attachment_id > 0 && get_post_type($attachment_id) === 'attachment') {
      if ($mode === 'add') {
        alexk_generate_carousel_derivatives_for_attachment($attachment_id);
      } else {
        alexk_delete_carousel_derivatives_for_attachment($attachment_id);
      }
    }

    $job['done']    = (int)($job['done'] ?? 0) + 1;
    $job['pending'] = max(0, (int)($job['pending'] ?? 0) - 1);

    // finish?
    if ($total > 0 && (int)$job['done'] >= $total) {
      alexk_bulk_job_clear();
      $job = alexk_bulk_job_get(); // now cleared
      $queue = [];
      break;
    }
  }

  // Persist remaining queue if job still active
  if (!empty($job['started'])) {
    $job['queue'] = array_values($queue);
    alexk_bulk_job_set($job);
  }
}


  $job = alexk_bulk_job_get();

  wp_send_json_success([
    'pending'               => (int)($job['pending'] ?? 0),
    'done'                  => (int)($job['done'] ?? 0),
    'total'                 => (int)($job['total'] ?? ($job['pending'] ?? 0)),
    'mode'                  => (string)($job['mode'] ?? ''),
    'started'               => (int)($job['started'] ?? 0),

    'current_attachment_id' => (int)($job['current_attachment_id'] ?? 0),
    'current_filename'      => (string)($job['current_filename'] ?? ''),
    'file_pending'          => (int)($job['file_pending'] ?? 0),
    'file_done'             => (int)($job['file_done'] ?? 0),
  ]);
});
