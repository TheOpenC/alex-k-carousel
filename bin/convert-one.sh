#!/bin/bash
# ============================================================
# convert-one-responsive.sh
# Called by WordPress on upload.
#
# Creates sibling responsive sets (color-managed):
#   <stem>-w320.webp ... <stem>-w1400.webp
#   <stem>-w320.jpg  ... <stem>-w1400.jpg
#
# Also writes convenience siblings:
#   <stem>.webp and <stem>.jpg (copies of the max size)
#
# Key behavior:
# - Convert from ORIGINAL upload only.
# - Normalize into standard sRGB, then strip profiles.
# - Bypass WP's resized PNGs entirely.
# - Works around LocalWP ImageMagick env overrides.
# ============================================================

set -e
IFS=$'\n\t'

SRC="${1:-}"
MAX_EDGE="${2:-1400}"

if [[ -z "$SRC" || ! -f "$SRC" ]]; then
  echo "ERROR: missing source file: $SRC" >&2
  exit 2
fi

# Width set (defaults requested)
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

# CODER_PATH can be a list separated by ":" or ";". Use the first entry.
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
# (If SRGB profile file isn't found, we still strip to keep outputs consistent.)
if [[ "$USE_SRGB_PROFILE" -eq 1 ]]; then
  COLOR_ARGS=( -profile "$SRGB_ICC" -strip )
else
  COLOR_ARGS=( -strip )
fi

# Resize helper: constrain to width/height max, but do not upscale.
# We use "${w}x${w}>" which preserves aspect ratio and caps the longest edge.

fail_debug() {
  echo "ERROR: magick failed." >&2
  echo "MAGICK_BIN=$MAGICK_BIN" >&2
  echo "PATH=$PATH" >&2
  echo "CODER_PATH=$CODER_PATH" >&2
  echo "MAGICK_CODER_MODULE_PATH=${MAGICK_CODER_MODULE_PATH:-<unset>}" >&2
  echo "SRGB_ICC=$SRGB_ICC (exists=$USE_SRGB_PROFILE)" >&2
  "$MAGICK_BIN" -version >&2 || true
}

# Generate responsive sets
for w in "${WIDTHS[@]}"; do
  # Respect MAX_EDGE (if caller set lower than our max list)
  if [[ "$w" -gt "$MAX_EDGE" ]]; then
    continue
  fi

  OUT_WEBP="$DIR/${STEM}-w${w}.webp"
  OUT_JPG="$DIR/${STEM}-w${w}.jpg"

  # WebP (lossless)
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

  # JPEG fallback
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

  echo "OK WEBP: $OUT_WEBP"
  echo "OK JPG:  $OUT_JPG"
done

# Convenience siblings: copy the largest generated size to <stem>.webp/.jpg
# (keeps your existing PHP logic working while you update srcset)
MAX_W=0
for w in "${WIDTHS[@]}"; do
  if [[ "$w" -le "$MAX_EDGE" ]]; then
    MAX_W="$w"
  fi
done

if [[ "$MAX_W" -gt 0 ]]; then
  SRC_WEBP="$DIR/${STEM}-w${MAX_W}.webp"
  SRC_JPG="$DIR/${STEM}-w${MAX_W}.jpg"
  if [[ -f "$SRC_WEBP" ]]; then
    cp -f "$SRC_WEBP" "$DIR/${STEM}.webp"
  fi
  if [[ -f "$SRC_JPG" ]]; then
    cp -f "$SRC_JPG" "$DIR/${STEM}.jpg"
  fi
fi

echo "DONE: responsive derivatives created."
exit 0
