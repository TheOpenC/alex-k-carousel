#!/usr/bin/env bash
# ============================================================
# MediaConverterApp — convert-media.sh (PRODUCTION)
#
# Reliable "Photopea-like" color behavior:
# - Use ImageMagick for decode + ICC/gamma + resize + encode.
# - WebP is LOSSLESS (primary).
# - JPEG is fallback.
# - AVIF disabled (stability + color policy).
#
# Output:
#   output/MM_DD_YYYY/FOLDER_##/{webp,jpeg}/
# Log:
#   conversion-log.txt (append-only), per file:
#     filename.ext ⏳
#     filename.ext ✅ WebP, ✅ JPEG
#     Errors:
#         HH:MM:SS PM
#         WEBP: ...
#         JPEG: ...
# ============================================================

set -u
set -o pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_ROOT="$SCRIPT_DIR/output"
LOG_FILE="$SCRIPT_DIR/conversion-log.txt"

DEFAULT_INPUT_DIR="$SCRIPT_DIR/input"
DEFAULT_MAX_EDGE=1400

INPUT_DIR="${1:-$DEFAULT_INPUT_DIR}"
MAX_EDGE="${2:-$DEFAULT_MAX_EDGE}"
INPUT_DIR="${INPUT_DIR/#\~/$HOME}"

today_folder() { date "+%m_%d_%Y"; }
mkdirp() { mkdir -p "$1"; }
file_ok() { [[ -f "$1" && -s "$1" ]]; }
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

# --- Requirements ---
if [[ ! -d "$INPUT_DIR" ]]; then
  echo "Input folder not found: $INPUT_DIR"
  exit 1
fi

if ! command -v magick >/dev/null 2>&1; then
  echo "ImageMagick not found. Install:"
  echo "  brew install imagemagick"
  exit 1
fi

# --- Setup output ---
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

log_line ""
log_line "[$(today_folder)]"
log_line "    $RUN_FOLDER"

# --- Encode with ImageMagick (color-managed decode) ---
encode_webp_lossless() {
  local src="$1" out="$2"
  # -auto-orient: fixes rotation
  # -resize WxH>: caps longest edge, no upscaling
  # Keep ICC unless you *want* stripping; Photopea-like behavior relies on profiles.
  magick "$src" \
    -auto-orient \
    -resize "${MAX_EDGE}x${MAX_EDGE}>" \
    -define webp:lossless=true \
    -define webp:method=6 \
    -define webp:alpha-quality=100 \
    "$out"
}

encode_jpeg_fallback() {
  local src="$1" out="$2"
  # Quality high; 4:4:4 sampling to avoid color smearing on art
  magick "$src" \
    -auto-orient \
    -resize "${MAX_EDGE}x${MAX_EDGE}>" \
    -sampling-factor 4:4:4 \
    -quality 92 \
    "$out"
}

shopt -s nullglob
found_any=0

while IFS= read -r -d '' src; do
  found_any=1

  base="$(basename "$src")"
  stem="${base%.*}"

  echo "Found: $base"
  log_line "        $base ⏳"

  out_webp="$WEBP_DIR/$stem.webp"
  out_jpeg="$JPEG_DIR/$stem.jpg"

  err_webp=""
  err_jpeg=""

  # WEBP
  if ! err_webp="$(encode_webp_lossless "$src" "$out_webp" 2>&1)"; then
    : # err_webp already captured
  fi

  # JPEG
  if ! err_jpeg="$(encode_jpeg_fallback "$src" "$out_jpeg" 2>&1)"; then
    : # err_jpeg already captured
  fi

  ok_webp=0; ok_jpeg=0
  file_ok "$out_webp" && ok_webp=1
  file_ok "$out_jpeg" && ok_jpeg=1

  status="        $base "
  [[ $ok_webp -eq 1 ]] && status+="✅ WebP" || status+="❌ WebP"
  status+=", "
  [[ $ok_jpeg -eq 1 ]] && status+="✅ JPEG" || status+="❌ JPEG"

  replace_pending_line "$base" "$status"

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
