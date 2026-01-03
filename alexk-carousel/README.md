# alex-k-carousel
Application for Alex K's website.
This tool allows Alex to easily tag artwork media (documentation) to be included in a shuffled carousel. The carousel will exist on his landing page as the primary source of content for his website. As time goes on, more images will be added or removed to Alex's liking. 

# File Type Support

The Alex K Image Carousel plugin supports files that WordPress can reliably convert into still images on this hosting environment. The carousel is intentionally limited to formats that can be processed without shell access or external conversion tools, ensuring consistent behavior on Elementor Hosting.

# Raster Image Formats

The following raster image formats are fully supported and automatically converted into responsive JPEG and WebP images for carousel use:

JPG / JPEG
PNG
GIF (first frame only; animations are not preserved)
WebP
TIFF / TIF

TIFF files are supported as input but are not web-native formats. These files are always converted to JPEG and WebP before being used in the carousel. If a TIFF contains multiple pages, only the first page is used.

# Modern Image Formats (Conditional Support)

The following formats are supported when the server environment allows them:

HEIC
HEIF

Support for these formats depends on the availability of Imagick with HEIC/HEIF support and the WordPress version in use. If conversion is not possible on the host, the plugin safely skips generation for these files.

# Document Rasterization

The plugin supports limited document-to-image conversion in cases where WordPress and the server can reliably rasterize the file.

PDF

Only the first page of a PDF is used. The page is rasterized into an image and then processed using the same responsive image pipeline as standard raster images. Multi-page PDFs are not supported by design.

# Uploadable but Not Carousel-Convertible

The following file types may be uploaded to WordPress, but cannot be reliably converted into still images without shell tools or external software. These files are intentionally excluded from carousel generation:

DOC, DOCX
XLS, XLSX
PPT, PPTX, PPS, PPSX
KEY
ODT
TXT

These files may appear in the Media Library but will not be included in the carousel even if the carousel checkbox is enabled.

# Explicitly Excluded Formats (currently. Multi media format handling in future updates)

The following formats are excluded by design:

SVG
All video formats
All audio formats

SVG files are excluded due to security concerns and the lack of reliable rasterization in this environment. Video and audio formats are outside the scope of the current plugin and may be addressed in a future release.

Summary Rule

The carousel supports any file WordPress can be rasterized into a still image on this hosting environment. Raster images and PDFs are supported. Other document, vector, video, and audio formats are uploadable in WordPress but are not eligible for carousel display.