#!/bin/bash
# ============================================================
# convert-one.sh
# Called by WordPress on upload.
# Creates sibling .webp (lossless) + .jpg next to original.
#
# Fix: LocalWP sets MAGICK_CODER_MODULE_PATH to its own coders dir.
# We OVERRIDE it with the correct coders dir from the magick binary.
# ============================================================

set -e
IFS=$'\n\t'

SRC="${1:-}"
MAX_EDGE="${2:-1400}"

if [[ -z "$SRC" || ! -f "$SRC" ]]; then
  echo "ERROR: missing source file: $SRC" >&2
  exit 2
fi

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
CODER_PATH="$("$MAGICK_BIN" -list configure | awk -F: '
  $1 ~ /^CODER_PATH$/ {
    v=$2; sub(/^[ \t]+/,"",v); sub(/[ \t]+$/,"",v);
    print v; exit
  }')"

# CODER_PATH can be a list separated by ":" or ";". Use the first entry.
FIRST_CODER_PATH="${CODER_PATH%%[:;]*}"

if [[ -n "$FIRST_CODER_PATH" && -d "$FIRST_CODER_PATH" ]]; then
  export MAGICK_CODER_MODULE_PATH="$FIRST_CODER_PATH"
fi

DIR="$(cd "$(dirname "$SRC")" && pwd)"
BASE="$(basename "$SRC")"
STEM="${BASE%.*}"

OUT_WEBP="$DIR/$STEM.webp"
OUT_JPG="$DIR/$STEM.jpg"

# WebP (lossless)
if ! "$MAGICK_BIN" "$SRC" \
  -auto-orient \
  -resize "${MAX_EDGE}x${MAX_EDGE}>" \
  -define webp:lossless=true \
  -define webp:method=6 \
  -define webp:alpha-quality=100 \
  "$OUT_WEBP"
then
  echo "ERROR: magick failed reading source." >&2
  echo "MAGICK_BIN=$MAGICK_BIN" >&2
  echo "PATH=$PATH" >&2
  echo "CODER_PATH=$CODER_PATH" >&2
  echo "MAGICK_CODER_MODULE_PATH=${MAGICK_CODER_MODULE_PATH:-<unset>}" >&2
  "$MAGICK_BIN" -version >&2 || true
  exit 10
fi

# JPEG fallback
"$MAGICK_BIN" "$SRC" \
  -auto-orient \
  -resize "${MAX_EDGE}x${MAX_EDGE}>" \
  -sampling-factor 4:4:4 \
  -quality 92 \
  "$OUT_JPG"

echo "OK WEBP: $OUT_WEBP"
echo "OK JPG:  $OUT_JPG"
exit 0
