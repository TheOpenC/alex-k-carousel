#!/bin/bash
# Key behavior:
# - Convert from ORIGINAL upload only (never WP-generated intermediates).
# - Normalize into standard sRGB, then strip profiles for consistency.
# - Works around LocalWP ImageMagick environment overrides.
# - Generates / deletes responsive images upon carousel check / uncheck.
# - Only generates widths <= the oriented source width (never "lying" filenames).
# - If the source is smaller than the ladder max, the native width is used as the largest output.
# - Ladder sizes for responsive images: 320, 480, 768, 1024, 1400
# __________________________________________________
# Supported input formats (via ImageMagick):
#   jpg, jpeg, png, webp, tif, tiff, gif, bmp
#
# Conditionally supported (depends on system delegates):
#   heic, heif, avif, svg, pdf
#
# Not supported:
#   RAW camera formats, animated formats (GIF, WebP), video, vector-native workflows
#
# Notes:
# - Animated images are flattened to the first frame.
# - Images are never upscaled beyond their native dimensions.
# - Output formats are JPG and WebP only.
# ============================================================


set -e
IFS=$'\n\t'

SRC="${1:-}"
MAX_EDGE="${2:-1400}"

if [[ -z "$SRC" || ! -f "$SRC" ]]; then
  echo "ERROR: missing source file: $SRC" >&2
  exit 2
fi

# Width ladder
WIDTHS=(320 480 768 1024 1400)

# Force ImageMagick binary (prefer env override from PHP)
MAGICK_BIN="${MAGICK_BIN:-/usr/local/bin/magick}"
if [[ ! -x "$MAGICK_BIN" ]]; then
  if [[ -x "/opt/homebrew/bin/magick" ]]; then
    MAGICK_BIN="/opt/homebrew/bin/magick"
  elif command -v magick >/dev/null 2>&1; then
    MAGICK_BIN="$(command -v magick)"
  fi
fi
if [[ -z "$MAGICK_BIN" || ! -x "$MAGICK_BIN" ]]; then
  echo "ERROR: magick not found/executable. MAGICK_BIN=$MAGICK_BIN PATH=$PATH" >&2
  exit 3
fi

# --- CRITICAL: wipe LocalWP's ImageMagick environment overrides ---
unset MAGICK_CODER_MODULE_PATH MAGICK_HOME MAGICK_CONFIGURE_PATH DYLD_LIBRARY_PATH

# Ask *this* magick where its CODER_PATH is, then force it.
CODER_PATH="$($MAGICK_BIN -list configure | awk -F: '
  $1 ~ /^CODER_PATH$/ {
    v=$2; sub(/^[ \t]+/,"",v); sub(/[ \t]+$/,"",v);
    print v; exit
  }')"

FIRST_CODER_PATH="${CODER_PATH%%[:;]*}"
if [[ -n "$FIRST_CODER_PATH" && -d "$FIRST_CODER_PATH" ]]; then
  export MAGICK_CODER_MODULE_PATH="$FIRST_CODER_PATH"
fi

# sRGB profile path (macOS)
SRGB_ICC="/System/Library/ColorSync/Profiles/sRGB Profile.icc"
USE_SRGB_PROFILE=0
if [[ -f "$SRGB_ICC" ]]; then
  USE_SRGB_PROFILE=1
fi

DIR="$(cd "$(dirname "$SRC")" && pwd)"
BASE="$(basename "$SRC")"
STEM="${BASE%.*}"

# Shared IM args: normalize to sRGB if profile exists, then strip.
if [[ "$USE_SRGB_PROFILE" -eq 1 ]]; then
  COLOR_ARGS=( -profile "$SRGB_ICC" -strip )
else
  COLOR_ARGS=( -strip )
fi

fail_debug() {
  echo "ERROR: magick failed." >&2
  echo "MAGICK_BIN=$MAGICK_BIN" >&2
  echo "PATH=$PATH" >&2
  echo "CODER_PATH=$CODER_PATH" >&2
  echo "MAGICK_CODER_MODULE_PATH=${MAGICK_CODER_MODULE_PATH:-<unset>}" >&2
  echo "SRGB_ICC=$SRGB_ICC (exists=$USE_SRGB_PROFILE)" >&2
  "$MAGICK_BIN" -version >&2 || true
}

# --- Determine oriented source width (so we never generate "-w1024" for a 440px-wide image) ---
ORIENT_W="$(
  "$MAGICK_BIN" "$SRC" -auto-orient -format "%w" info: 2>/dev/null || true
)"

if [[ -z "$ORIENT_W" || ! "$ORIENT_W" =~ ^[0-9]+$ || "$ORIENT_W" -lt 1 ]]; then
  echo "ERROR: could not determine oriented width for: $SRC (got '$ORIENT_W')" >&2
  fail_debug
  exit 4
fi

# Cap by MAX_EDGE
if [[ "$ORIENT_W" -gt "$MAX_EDGE" ]]; then
  ORIENT_W="$MAX_EDGE"
fi

# Build target widths:
# - include ladder widths <= oriented width
# - if oriented width is smaller than ladder max, append native width (if not already present)
TARGET_WIDTHS=()
for w in "${WIDTHS[@]}"; do
  if [[ "$w" -le "$MAX_EDGE" && "$w" -le "$ORIENT_W" ]]; then
    TARGET_WIDTHS+=( "$w" )
  fi
done

# Append native width if it isn't already in the ladder list and is > 0
need_native=1
for w in "${TARGET_WIDTHS[@]}"; do
  if [[ "$w" -eq "$ORIENT_W" ]]; then
    need_native=0
    break
  fi
done
if [[ "$need_native" -eq 1 ]]; then
  TARGET_WIDTHS+=( "$ORIENT_W" )
fi

# Generate responsive sets
for w in "${TARGET_WIDTHS[@]}"; do
  OUT_WEBP="$DIR/${STEM}-w${w}.webp"
  OUT_JPG="$DIR/${STEM}-w${w}.jpg"

  # Skip if already exists (idempotent)
  if [[ -f "$OUT_WEBP" && -f "$OUT_JPG" ]]; then
    echo "SKIP (exists): w${w}"
    continue
  fi

  # WebP (lossless)
  if [[ ! -f "$OUT_WEBP" ]]; then
    if ! "$MAGICK_BIN" "$SRC" \
      -auto-orient \
      "${COLOR_ARGS[@]}" \
      -resize "${w}x${w}>" \
      -define webp:lossless=true \
      -define webp:method=6 \
      -define webp:alpha-quality=100 \
      "$OUT_WEBP"; then
      fail_debug
      exit 10
    fi
    echo "OK WEBP: $OUT_WEBP"
  else
    echo "SKIP WEBP (exists): $OUT_WEBP"
  fi

  # JPEG fallback
  if [[ ! -f "$OUT_JPG" ]]; then
    if ! "$MAGICK_BIN" "$SRC" \
      -auto-orient \
      "${COLOR_ARGS[@]}" \
      -resize "${w}x${w}>" \
      -sampling-factor 4:4:4 \
      -quality 92 \
      "$OUT_JPG"; then
      fail_debug
      exit 11
    fi
    echo "OK JPG:  $OUT_JPG"
  else
    echo "SKIP JPG (exists): $OUT_JPG"
  fi
done

# Convenience siblings: copy the *largest generated* size to <stem>.webp/.jpg
# IMPORTANT: do NOT overwrite the original upload.
MAX_W="${TARGET_WIDTHS[-1]}"
ORIG_EXT="${BASE##*.}"
ORIG_EXT="${ORIG_EXT,,}"

SRC_WEBP="$DIR/${STEM}-w${MAX_W}.webp"
SRC_JPG="$DIR/${STEM}-w${MAX_W}.jpg"

if [[ -f "$SRC_WEBP" && "$ORIG_EXT" != "webp" ]]; then
  cp -f "$SRC_WEBP" "$DIR/${STEM}.webp"
fi
if [[ -f "$SRC_JPG" && "$ORIG_EXT" != "jpg" && "$ORIG_EXT" != "jpeg" ]]; then
  cp -f "$SRC_JPG" "$DIR/${STEM}.jpg"
fi

echo "DONE: responsive derivatives created (max_w=${MAX_W})."
exit 0
