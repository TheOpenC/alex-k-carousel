# alex-k-carousel
Application for Alex K's website.
This tool allows Alex to easily tag artwork media (documentation) to be included in a shuffled carousel. The carousel will exist on his landing page as the primary source of content for his website. As time goes on, more images will be added or removed to Alex's liking. 

====================
## File Type Support
====================

The Alex K Image Carousel plugin supports files that WordPress can reliably convert into still images on this hosting environment. The carousel is intentionally limited to formats that can be processed without shell access or external conversion tools, ensuring consistent behavior on Elementor Hosting.

### Raster Image Formats

The following raster image formats are fully supported and automatically converted into responsive JPEG and WebP images for carousel use:

* JPG / JPEG
* PNG
* GIF (first frame only; animations are not preserved)
* WebP
* TIFF / TIF

TIFF files are supported as input but are not web-native formats. These files are always converted to JPEG and WebP before being used in the carousel. If a TIFF contains multiple pages, only the first page is used.

### Modern Image Formats (Conditional Support)

The following formats are supported when the server environment allows them:

* HEIC
* HEIF

Support for these formats depends on the availability of Imagick with HEIC/HEIF support and the WordPress version in use. If conversion is not possible on the host, the plugin safely skips generation for these files.

### Document Rasterization

The plugin supports limited document-to-image conversion in cases where WordPress and the server can reliably rasterize the file.

* PDF

Only the first page of a PDF is used. The page is rasterized into an image and then processed using the same responsive image pipeline as standard raster images. Multi-page PDFs are not supported by design.

### Uploadable but Not Carousel-Convertible

The following file types may be uploaded to WordPress, but cannot be reliably converted into still images without shell tools or external software. These files are intentionally excluded from carousel generation:

* DOC, DOCX
* XLS, XLSX
* PPT, PPTX, PPS, PPSX
* KEY
* ODT
* TXT

These files may appear in the Media Library but will not be included in the carousel even if the carousel checkbox is enabled.

### Explicitly Excluded Formats

The following formats are excluded by design:

* SVG
* All video formats
* All audio formats

SVG files are excluded due to security concerns and the lack of reliable rasterization in this environment. Video and audio formats are outside the scope of the current plugin and may be addressed in a future release.

### Summary Rule

The carousel supports any file WordPress can be rasterized into a still image on this hosting environment. Raster images and PDFs are supported. Other document, vector, video, and audio formats are uploadable in WordPress but are not eligible for carousel display.

---

When you’re ready, the **next logical README section** is either:

* “Known Limitations” (atomic generation, hosting constraints), or
* “Future Considerations” (video support, SVG opt-in, poster images)

Say which one you want to tackle next and we’ll keep it clean and minimal.


=======================
# Future Considerations
=======================

The current version of the Alex K Image Carousel is intentionally focused on still images. The following features are possible future extensions but are not part of the current implementation.

### Video Support

Future versions of the carousel may support video files as carousel items. This would introduce a mixed media carousel capable of displaying both still images and video elements.

Potential constraints for video support include:

* No transcoding or resizing of video files
* Limited to web-safe formats (e.g. MP4)
* Videos rendered using native `<video>` elements
* Optional or required poster images for previews
* Fixed playback duration or looped playback for carousel timing

Video support would require changes to frontend rendering, carousel timing logic, and accessibility handling, and is therefore considered a separate feature set rather than a minor extension.

### SVG Support

SVG files are currently excluded due to security considerations and inconsistent rasterization behavior. A future opt-in mode may allow SVG support if:

* SVG uploads are explicitly enabled and sanitized
* SVGs are rasterized to still images before carousel use
* Clear security and trust assumptions are documented

SVG support would remain disabled by default.

### Expanded Document Rasterization

Support for additional document formats (such as presentations or text documents) could be explored in the future if a safe, non-shell-based conversion method becomes available on managed hosting platforms. At present, reliable conversion of these formats requires external tools that are not accessible in the current hosting environment.

### Deferred or Background Generation

Image generation currently occurs synchronously during user actions to ensure predictable behavior. Future versions may explore deferred generation using background processing or queued jobs if hosting constraints allow for it. Any such change would prioritize reliability and transparency over speed.

### Media-Type Awareness

A future internal abstraction may distinguish between different carousel item types (e.g. image vs video). This would allow the carousel to evolve without breaking existing image-only behavior and would keep the current image pipeline stable.
---

===================
# Known Limitations
===================

The Alex K Image Carousel is designed to be reliable and predictable within the constraints of WordPress and managed hosting environments. The following limitations are intentional and help ensure consistent behavior.

### Atomic Image Generation

Image generation runs as a single, uninterrupted process. Once generation begins, it completes fully before responding to additional changes.

If a carousel image is unchecked while generation is in progress, cleanup occurs on the next save rather than immediately. This avoids race conditions inherent to PHP request handling and ensures filesystem integrity.

### Hosting Constraints

The plugin is designed to work on managed hosting platforms such as Elementor Hosting. As a result:

* Shell access is not used
* External binaries (e.g. ffmpeg, LibreOffice) are not required
* Image processing relies on Imagick or WordPress Image Editor only

These constraints limit which file types can be converted and prevent advanced media transformations.

### No Video Support (Current Version)

Video files are not supported in the current version of the carousel. While WordPress accepts video uploads, the carousel is built around a still-image pipeline and does not include video playback logic, poster generation, or media timing controls.

### Limited Document Support

Only PDFs are supported for document rasterization, and only the first page is used. Other document formats may be uploadable in WordPress but are not eligible for carousel display.

### Environment-Dependent Format Support

Support for certain image formats (such as HEIC or HEIF) depends on the server environment and available image libraries. When conversion is not possible, the plugin safely skips generation without affecting other carousel items.

---
==============================================
## WordPress Media Support vs Carousel Support
==============================================

WordPress supports a wide range of media file types across images, audio, and video. The Alex K Image Carousel plugin intentionally supports only a subset of these formats based on its design goals and hosting constraints.

This section clarifies the distinction.

---

## Media File Types Supported by WordPress

WordPress allows the following file types to be uploaded to the Media Library by default.

### Audio Formats

* MP3 (`.mp3`)
* Ogg (`.ogg`)
* WAV (`.wav`)

### Video Formats

* MP4 / M4V (MPEG-4)
* MPG
* MOV (QuickTime)
* AVI
* OGV (Ogg)
* WMV (Windows Media Video)
* 3GP (3GPP)
* 3G2 (3GPP2)
* VTT (text captions for video)

These formats are fully supported by WordPress for storage and playback using native media players.

---

## Carousel Media Support (Current Version)

The Alex K Image Carousel is designed as a **still-image carousel**. While WordPress supports audio and video uploads, these media types are **not included in the carousel** in the current version.

### Not Supported in the Carousel

* All audio formats
* All video formats
* Caption-only files (e.g. `.vtt`)

These files may exist in the Media Library and function normally within WordPress, but they are ignored by the carousel system even if the carousel checkbox is enabled.

---

## Rationale

The carousel’s image pipeline is optimized for:

* High-quality still images
* Predictable, responsive rendering
* Compatibility with managed hosting environments
* No reliance on shell tools or external binaries

Supporting audio or video would require a different rendering model, timing logic, and accessibility considerations. These features are intentionally deferred to a future version.

---

## Future Direction

Audio and video support may be added in a future release as part of a dedicated **media carousel** feature. Any such addition would be explicitly designed and documented rather than implicitly included.


