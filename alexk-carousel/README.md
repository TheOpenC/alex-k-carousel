# Alex K – Client Image Carousel

This plugin adds a curated image carousel system to WordPress by extending the Media Library and generating dedicated image assets for frontend use.

It is designed to work in Elementor-hosted environments while remaining independent of themes and page builders.

---

## Overview

The plugin allows specific media files to be explicitly included in a carousel. Only those files receive additional processing and are used for frontend display.

The system is built to be predictable for non-technical users and explicit for developers, with a clear separation between admin state, file generation, and frontend output.

---

## Media Library Integration

Each media item includes an “Include in Carousel” control in the Media Library.

The control appears both in the Media Library modal and on the attachment edit page. It does not affect WordPress thumbnails or other uses of the image.

Carousel membership is represented by a green dot indicator. If the green dot is present, the file is included in the carousel. If it is not present, the file is not included.

The green dot reflects confirmed server-side state and is treated as the authoritative source of truth. Checkbox state is synchronized to match the green dot and is not considered authoritative on its own.

---

## Bulk Actions and Admin UI Extensions

The plugin extends the Media Library with custom bulk actions for adding and removing files from the carousel.

Bulk actions execute server-side updates, update the green dot immediately, and reconcile checkbox state as part of the same execution flow. Page refreshes are not required.

Additional admin UI logic ensures that visual state does not drift from actual data. Indicators are updated only after successful operations, and stale or misleading UI states are avoided.

---

## Image Processing and ImageMagick

Image processing is handled using ImageMagick via PHP (Imagick), rather than shell scripts or WordPress’s default thumbnail system.

This approach is used because managed hosting environments, including Elementor hosting, do not allow shell execution. ImageMagick provides control over resizing, format conversion, and color handling, and allows processing to be limited strictly to images included in the carousel.

---

## Image Generation Behavior

When a file is added to the carousel, the original image is analyzed and a set of dedicated derivatives is generated.

The following formats are produced:

* JPEG for broad compatibility
* WebP as the primary modern format

Files are written to a per-attachment directory that is separate from WordPress-generated thumbnails. Existing WordPress thumbnail sizes are not reused or modified.

---

## Color and Metadata Handling

Image generation prioritizes visual fidelity and consistency.

Color profiles are preserved where possible, metadata relevant to color accuracy is retained, and compression is applied conservatively. The goal is to avoid unexpected color shifts or degradation rather than to aggressively minimize file size.

---

## Supported File Types

The plugin supports the following file types:

* JPG
* PNG
* TIFF
* GIF (first frame only)
* PDF (first page rendered to an image)

Unsupported or non-image files are ignored safely and do not interrupt bulk operations.

---

## Frontend Output

Carousel output is rendered via shortcode.

Images are delivered using semantic HTML with picture elements, providing WebP sources where supported and JPEG fallbacks otherwise. Images are displayed using object-fit contain to preserve aspect ratio and avoid cropping.

Image order is randomized on each page load. There is no autoplay or timing-based slideshow behavior.

---

## Flattened Theme Structure

The site uses a deliberately flattened theme structure to keep control over layout, styling, and behavior.

The theme provides only the minimum structure required for WordPress to operate. Presentation and interaction logic are handled explicitly through CSS and JavaScript, rather than inherited through theme layers.

This reduces conflicts between theme styles, plugins, and page builders, and avoids implicit behavior that can accumulate over time in more complex themes.

For the carousel, this means:

* No theme-imposed image sizing or cropping
* No inherited typography or layout assumptions
* No reliance on theme-specific wrappers or classes

The carousel’s markup and styles are fully controlled by the plugin’s frontend assets, resulting in consistent behavior regardless of the active theme.

---

## Relationship to Elementor

Elementor is used for page composition, but the theme does not depend on Elementor’s theme layer or global styles.

The carousel does not use Elementor widgets and does not inherit Elementor styling. Elementor’s role is limited to page layout, not component logic.

Because of this separation, the site can be migrated away from Elementor without requiring changes to the carousel plugin or its generated assets.

---

## Theme and Builder Independence

The plugin does not assume any specific theme structure and does not rely on page builder internals.

It can be used with Elementor, other page builders, or custom or default WordPress themes. Admin behavior, file generation, and frontend output remain stable across theme changes and site migrations.

---

## Design Approach

The system is built around a single authoritative state for carousel membership, immediate and reliable admin feedback, and explicit file generation.

The emphasis is on predictable behavior, minimal coupling, and avoiding hidden automation that is difficult to reason about or maintain.

---
