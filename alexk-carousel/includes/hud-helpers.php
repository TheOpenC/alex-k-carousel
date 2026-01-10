<?php
if (!defined('ABSPATH')) exit;

/**
 * HUD helpers
 * Pure functions used by the HUD runner and queue builder.
 *
 * Required by:
 * - includes/hud-ajax.php  (calls alexk_build_queue_from_attachment_ids)
 * - includes/hud-runner.php (calls alexk_build_one_derivative)
 */

/**
 * Build a queue of work items: ONE item per output file.
 * This makes progress truthful: total = count(queue), done++ per completed item.
 *
 * NOTE:
 * This is tailored to your existing generator behavior:
 * - outputs: {stem}-w{w}.webp and {stem}-w{w}.jpg
 * - plus "carousel" siblings copied from the largest width:
 *   {stem}-carousel.webp and {stem}-carousel.jpg
 */
function alexk_build_queue_from_attachment_ids(array $attachment_ids): array {
  $queue = [];

  foreach ($attachment_ids as $attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) continue;

    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) continue;

    // Match your generator exclusions
    $mime = get_post_mime_type($attachment_id);
    if (!$mime || strpos($mime, 'image/') !== 0) continue;

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, ['svg', 'pdf'], true)) continue;

    // Use your existing helpers for output dir + cancel marker
    if (!function_exists('alexk_carousel_output_dir_for_attachment')) continue;
    if (!function_exists('alexk_carousel_cancel_marker_path')) continue;
    if (!function_exists('alexk_carousel_widths')) continue;

    $out_dir = alexk_carousel_output_dir_for_attachment($attachment_id, $file);
    if (!$out_dir) continue;

    $cancel_path = alexk_carousel_cancel_marker_path($attachment_id, $file);
    if (!$cancel_path) continue;

    // If canceled already, skip building queue
    if (file_exists($cancel_path)) continue;

    $base = basename($file);
    $stem = preg_replace('/\.[^.]+$/', '', $base);

    $info = @getimagesize($file);
    if (!$info || empty($info[0]) || empty($info[1])) continue;

    $native_w = (int) $info[0];
    $native_h = (int) $info[1];
    $native_max = max($native_w, $native_h);
    if ($native_max <= 0) continue;

    // Match your widths logic (include native if smaller than max list)
    $widths = alexk_carousel_widths();
    $max_list = max($widths);

    if ($native_max < $max_list) {
      $widths[] = $native_max;
      $widths = array_values(array_unique($widths));
      sort($widths);
    }

    // Filter widths: no upscaling
    $widths = array_values(array_filter($widths, function ($w) use ($native_max) {
      $w = (int) $w;
      return $w > 0 && $w <= $native_max;
    }));

    if (empty($widths)) continue;

    $largest = max($widths);

    // One item per output file (width × 2 formats)
    foreach ($widths as $w) {
      $w = (int) $w;

      $queue[] = [
        'type'          => 'render',
        'attachment_id' => $attachment_id,
        'src'           => $file,
        'out_dir'       => $out_dir,
        'cancel_path'   => $cancel_path,
        'stem'          => $stem,
        'width'         => $w,
        'format'        => 'webp',
        'dest'          => $out_dir . '/' . $stem . '-w' . $w . '.webp',
        'variant'       => 'webp-w' . $w,
      ];

      $queue[] = [
        'type'          => 'render',
        'attachment_id' => $attachment_id,
        'src'           => $file,
        'out_dir'       => $out_dir,
        'cancel_path'   => $cancel_path,
        'stem'          => $stem,
        'width'         => $w,
        'format'        => 'jpg',
        'dest'          => $out_dir . '/' . $stem . '-w' . $w . '.jpg',
        'variant'       => 'jpg-w' . $w,
      ];
    }

    // Explicit queue items for carousel siblings (truthful progress)
    $queue[] = [
      'type'          => 'copy',
      'attachment_id' => $attachment_id,
      'src'           => $out_dir . '/' . $stem . '-w' . $largest . '.webp',
      'out_dir'       => $out_dir,
      'cancel_path'   => $cancel_path,
      'stem'          => $stem,
      'dest'          => $out_dir . '/' . $stem . '-carousel.webp',
      'variant'       => 'copy-carousel-webp',
    ];

    $queue[] = [
      'type'          => 'copy',
      'attachment_id' => $attachment_id,
      'src'           => $out_dir . '/' . $stem . '-w' . $largest . '.jpg',
      'out_dir'       => $out_dir,
      'cancel_path'   => $cancel_path,
      'stem'          => $stem,
      'dest'          => $out_dir . '/' . $stem . '-carousel.jpg',
      'variant'       => 'copy-carousel-jpg',
    ];
  }

  return $queue;
}

/**
 * Execute ONE work item.
 * Return true on success, or a string error message on failure.
 *
 * This is what the HUD runner calls for each queue item.
 */
function alexk_build_one_derivative(array $item) {
  $type = (string) ($item['type'] ?? '');
  $attachment_id = (int) ($item['attachment_id'] ?? 0);

  $src = (string) ($item['src'] ?? '');
  $dest = (string) ($item['dest'] ?? '');
  $out_dir = (string) ($item['out_dir'] ?? '');
  $cancel_path = (string) ($item['cancel_path'] ?? '');

  if ($attachment_id <= 0) return 'bad attachment_id';
  if ($dest === '') return 'missing dest';
  if ($out_dir === '') return 'missing out_dir';
  if ($cancel_path === '') return 'missing cancel_path';

  // Hard cancel + safety
  if (file_exists($cancel_path)) return 'canceled';

  // Create output dir if needed (same behavior as your generator)
  if (!is_dir($out_dir)) {
    wp_mkdir_p($out_dir);
  }
  if (!is_dir($out_dir)) return 'failed to create output dir';

  // Idempotent: if already exists, treat as success
  if (file_exists($dest)) return true;

  if ($type === 'render') {
    if ($src === '' || !file_exists($src)) return 'source missing';

    $w = (int) ($item['width'] ?? 0);
    $format = (string) ($item['format'] ?? '');
    if ($w <= 0) return 'bad width';
    if ($format !== 'webp' && $format !== 'jpg') return 'bad format';

    if (!function_exists('alexk_resize_and_write_no_mkdir')) {
      return 'missing function alexk_resize_and_write_no_mkdir';
    }

    $ok = alexk_resize_and_write_no_mkdir($src, $dest, $w, $format, $cancel_path, $out_dir);
    return $ok ? true : "render failed ({$format} {$w})";
  }

  if ($type === 'copy') {
  if ($src === '' || !file_exists($src)) return 'copy source missing (largest derivative not found)';
  $ok = @copy($src, $dest);
  return $ok ? true : 'copy failed';
}

if ($type === 'delete_attachment') {
  $attachment_id = (int)($item['attachment_id'] ?? 0);
  if ($attachment_id <= 0) return 'bad attachment_id for delete';
  if (!function_exists('alexk_delete_carousel_derivatives_for_attachment')) {
    return 'missing function alexk_delete_carousel_derivatives_for_attachment';
  }
  alexk_delete_carousel_derivatives_for_attachment($attachment_id);
  return true;
}

return 'unknown item type';

}
