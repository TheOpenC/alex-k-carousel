#!/usr/bin/env bash
# ============================================================
# MediaConverterApp — convert-media.sh
#
# Goal (COLOR-FAITHFUL):
#   Normalize any input image to a canonical sRGB pixel state
#   (like Photopea), then encode:
#     - WebP (LOSSLESS)  -> pixel-faithful
#     - JPEG (fallback)  -> broadly compatible
#
# Output:
#   input/ (or user-chosen folder) ->
#   output/MM_DD_YYYY/FOLDER_##/{webp,jpeg}/
#
# Logging:
#   conversion-log.txt (append-only)
#   [MM_DD_YYYY]
#       FOLDER_##
#           filename.ext ⏳
#           filename.ext ✅ WebP, ✅ JPEG
#           Errors:
#               HH:MM:SS PM
#               WEBP: ...
#               JPEG: ...
#
# Success is determined by actual outputs:
#   file exists + non-zero size
# ============================================================

set -u
set -o pipefail
IFS=$'\n\t'

# --------------------------
# Paths
# --------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_ROOT="$SCRIPT_DIR/output"
LOG_FILE="$SCRIPT_DIR/conversion-log.txt"

# Defaults (overridable by args)
DEFAULT_INPUT_DIR="$SCRIPT_DIR/input"
DEFAULT_MAX_EDGE=1400

# Args:
#   ./convert-media.sh "/path/to/input-folder" 1400
INPUT_DIR="${1:-$DEFAULT_INPUT_DIR}"
MAX_EDGE="${2:-$DEFAULT_MAX_EDGE}"

# Expand "~" if present
INPUT_DIR="${INPUT_DIR/#\~/$HOME}"

# --------------------------
# Settings
# --------------------------
WEBP_COMPRESSION_LEVEL=6    # 0..6 (6 = best compression, slower)
JPEG_Q=2                   # ffmpeg -q:v (lower = better)
SCALE_FLAGS="lanczos"

# --------------------------
# Helpers
# --------------------------
today_folder() { date "+%m_%d_%Y"; }

mkdirp() { mkdir -p "$1"; }

file_ok() { [[ -f "$1" && -s "$1" ]]; }

# Longest edge -> MAX_EDGE, preserve aspect, no upscaling
scale_filter() {
  local max_edge="$1"
  echo "scale='if(gt(iw,ih),min(iw,${max_edge}),-2)':'if(gt(iw,ih),-2,min(ih,${max_edge}))':flags=${SCALE_FLAGS}"
}

log_line() { echo "$1" >> "$LOG_FILE"; }

append_error_block() {
  local label="$1"
  local err="$2"

  [[ -z "$err" ]] && return 0

  log_line "        Errors:"
  log_line "            $(date '+%I:%M:%S %p')"

  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    log_line "            ${label}: ${line}"
  done <<< "$err"
}

# Replace "        filename ⏳" with the final status line (literal match, unicode-safe)
replace_pending_line() {
  local filename="$1"
  local replacement="$2"
  local search="        ${filename} ⏳"

  SEARCH="$search" REPL="$replacement" perl -i -pe '
    BEGIN { $s=$ENV{SEARCH}; $r=$ENV{REPL}; our $done=0; }
    if (!$done && $_ eq "$s\n") { $_ = "$r\n"; $done=1; }
  ' "$LOG_FILE"
}

next_run_folder() {
  local base="$1"
  local n=1
  while :; do
    local candidate
    candidate="$(printf "FOLDER_%02d" "$n")"
    if [[ ! -d "$base/$candidate" ]]; then
      echo "$candidate"
      return 0
    fi
    n=$((n + 1))
  done
}

# --------------------------
# Color normalization (Photopea-like)
# Requires ImageMagick: `magick`
# --------------------------
HAS_MAGICK=0
if command -v magick >/dev/null 2>&1; then
  HAS_MAGICK=1
fi

normalize_to_srgb() {
  local src="$1"
  local out="$2"

  # If ImageMagick isn't installed, fall back to "as-is" (less reliable color-wise)
  if [[ "$HAS_MAGICK" -ne 1 ]]; then
    cp -f "$src" "$out"
    return 0
  fi

  # Canonicalize:
  # -auto-orient: apply EXIF orientation
  # -colorspace sRGB: convert from embedded profile / gamma into sRGB appearance
  # -strip: remove profile/gamma metadata after pixels are normalized
  magick "$src" \
    -auto-orient \
    -colorspace sRGB \
    -strip \
    "$out"
}

# --------------------------
# Encoders (boring on purpose)
# Color correctness is handled BEFORE encoding.
# --------------------------
encode_webp() {
  local src="$1" out="$2"
  ffmpeg -hide_banner -loglevel error -y \
    -i "$src" \
    -vf "$(scale_filter "$MAX_EDGE")" \
    -map_metadata 0 \
    -c:v libwebp \
    -lossless 1 \
    -compression_level "$WEBP_COMPRESSION_LEVEL" \
    "$out" 2>&1 || true
}

encode_jpeg() {
  local src="$1" out="$2"
  ffmpeg -hide_banner -loglevel error -y \
    -i "$src" \
    -vf "$(scale_filter "$MAX_EDGE")" \
    -map_metadata 0 \
    -q:v "$JPEG_Q" \
    -pix_fmt yuvj420p \
    "$out" 2>&1 || true
}

# --------------------------
# Validate input
# --------------------------
if [[ ! -d "$INPUT_DIR" ]]; then
  echo "Input folder not found: $INPUT_DIR"
  exit 1
fi

if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "ffmpeg is not installed or not on PATH."
  echo "Install via Homebrew: brew install ffmpeg"
  exit 1
fi

if [[ "$HAS_MAGICK" -ne 1 ]]; then
  echo "WARNING: ImageMagick (magick) not found."
  echo "Color will NOT be Photopea-faithful without it."
  echo "Install via Homebrew: brew install imagemagick"
fi

# --------------------------
# Setup output folders
# --------------------------
DATE_DIR="$OUTPUT_ROOT/$(today_folder)"
RUN_FOLDER="$(next_run_folder "$DATE_DIR")"
RUN_DIR="$DATE_DIR/$RUN_FOLDER"

WEBP_DIR="$RUN_DIR/webp"
JPEG_DIR="$RUN_DIR/jpeg"

mkdirp "$WEBP_DIR"
mkdirp "$JPEG_DIR"

echo "Input:  $INPUT_DIR"
echo "Output: $DATE_DIR"
echo "Run:    $RUN_FOLDER"
echo "Log:    $LOG_FILE"

# Log header (append-only)
log_line ""
log_line "[$(today_folder)]"
log_line "    $RUN_FOLDER"

# --------------------------
# Main loop (supports spaces/unicode)
# --------------------------
shopt -s nullglob
found_any=0

# Accept many common image types (add more if needed)
# NOTE: We normalize via ImageMagick, so it can read more than ffmpeg alone.
while IFS= read -r -d '' src; do
  found_any=1

  base="$(basename "$src")"
  stem="${base%.*}"

  echo "Found: $base"

  # Pending line
  log_line "        $base ⏳"

  out_webp="$WEBP_DIR/$stem.webp"
  out_jpeg="$JPEG_DIR/$stem.jpg"

  # Normalize to sRGB into a temp PNG for stable color across formats
  tmp_srgb="$(mktemp -t srgb_XXXXXX).png"
  normalize_to_srgb "$src" "$tmp_srgb"

  # Encode
  err_webp="$(encode_webp "$tmp_srgb" "$out_webp")"
  err_jpeg="$(encode_jpeg "$tmp_srgb" "$out_jpeg")"

  # Cleanup temp
  rm -f "$tmp_srgb"

  # Success checks (file exists + non-zero)
  ok_webp=0; ok_jpeg=0
  file_ok "$out_webp" && ok_webp=1
  file_ok "$out_jpeg" && ok_jpeg=1

  # Replace pending line with final status
  status="        $base "
  [[ $ok_webp -eq 1 ]] && status+="✅ WebP" || status+="❌ WebP"
  status+=", "
  [[ $ok_jpeg -eq 1 ]] && status+="✅ JPEG" || status+="❌ JPEG"

  replace_pending_line "$base" "$status"

  # Optional Errors block only if failures occurred AND stderr exists
  any_fail=0
  [[ $ok_webp -eq 0 && -n "$err_webp" ]] && any_fail=1
  [[ $ok_jpeg -eq 0 && -n "$err_jpeg" ]] && any_fail=1

  if [[ "$any_fail" -eq 1 ]]; then
    [[ $ok_webp -eq 0 ]] && append_error_block "WEBP" "$err_webp"
    [[ $ok_jpeg -eq 0 ]] && append_error_block "JPEG" "$err_jpeg"
  fi

done < <(find "$INPUT_DIR" -type f \( \
  -iname "*.png"  -o -iname "*.jpg"  -o -iname "*.jpeg" -o \
  -iname "*.tif"  -o -iname "*.tiff" -o -iname "*.gif"  -o \
  -iname "*.bmp"  -o -iname "*.heic" -o -iname "*.heif" -o \
  -iname "*.webp" -o -iname "*.avif" \
\) -print0)

if [[ "$found_any" -eq 0 ]]; then
  echo "No files found in input folder."
fi
