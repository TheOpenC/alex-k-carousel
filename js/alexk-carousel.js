if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady);
} else {
  onDomReady();
}

function onDomReady() {
  const carousel = findCarousel();
  if (!carousel) return;

  const imgElement = findCarouselImage(carousel);
  if (!imgElement) return;

  const images = parseImagesData(carousel);
  if (images.length === 0) return;

  const state = createCarouselState(images);

  // PRELOAD
  showNextAndPreload(carousel, imgElement, state);
  carousel.addEventListener('click', onCarouselClick);

  // store state on the carousel so the click handler can access it
  carousel._alexkCarousel = { imgElement: imgElement, state: state };

  // Keyboard navigation (ArrowLeft / ArrowRight / Space)
  installKeyboardNavigation(carousel);
}


// PRELOAD
function onCarouselClick(event) {
  const carousel = event.currentTarget;
  const store = carousel._alexkCarousel;
  if (!store) return;

  showNextAndPreload(carousel, store.imgElement, store.state);
}


// NEW KEYBOARD CODE
function installKeyboardNavigation(carouselEl) {
  // Install once per page load
  if (document.__alexkCarouselKeyboardNavInstalled) return;
  document.__alexkCarouselKeyboardNavInstalled = true;

  document.addEventListener(
    'keydown',
    (event) => {
      const store = carouselEl?._alexkCarousel;
      if (!store) return;

      // Don't steal keys while typing or using modifiers
      if (isTypingContext(event)) return;

      const key = event.key;

      // Forward-only navigation: ArrowRight and Spacebar advance
      if (key === 'ArrowRight' || key === ' ') {
        // Spacebar normally scrolls the page — stop that
        if (key === ' ') event.preventDefault();
        showNextAndPreload(carouselEl, store.imgElement, store.state);
        return;
      }
    // No backwards keyboard nav by design. Only forward movement
    },
    { passive: false }
  );
}

function isTypingContext(event) {
  if (!event) return true;
  if (event.altKey || event.ctrlKey || event.metaKey) return true;

  const t = event.target;
  if (!t || !(t instanceof Element)) return false;

  if (t.matches('input, textarea, select, button')) return true;
  if (t.isContentEditable) return true;

  // Also ignore if we're inside any editable element
  const editableParent = t.closest('[contenteditable="true"]');
  if (editableParent) return true;

  return false;
} // Keyboard advance code ends here

function createCarouselState(images) {
  return {
    allImages: images.slice(),
    deck: [],
    lastShown: null,
  };
}

function getNextImage(state) {
  if (!state) return null;

  // Refill + reshuffle when empty
  // PRELOAD
  ensureDeckReady(state);

  const next = state.deck.pop();
  if (next && next.fallback) state.lastShown = next.fallback;
  return next;
}

// PRELOAD
function showNextAndPreload(carouselEl, imgElement, state) {
  const current = getNextImage(state);
  if (!current) return;

  updateCarouselImage(imgElement, current);

  const next = peekNextImage(state);
  if (next) preloadImageForCarousel(carouselEl, next);
}

function peekNextImage(state) {
  if (!state) return null;

  ensureDeckReady(state);

  if (!state.deck || state.deck.length === 0) return null;
  return state.deck[state.deck.length - 1]; // peek (since getNextImage pops)
}

function ensureDeckReady(state) {
  // Refill + reshuffle when empty (same logic as getNextImage)
  if (!state.deck || state.deck.length === 0) {
    let tries = 0;

    do {
      state.deck = state.allImages.slice();
      shuffleInPlace(state.deck);
      tries += 1;

      if (tries > 10) break;
    } while (
      state.lastShown &&
      state.deck.length > 1 &&
      state.deck[state.deck.length - 1].fallback === state.lastShown
    );
  }
}

function preloadImageForCarousel(carouselEl, imageObj) {
  // Remove any previous preload so we only ever preload ONE “next” image
  const existing = document.getElementById('alexk-carousel-preload-next');
  if (existing) existing.remove();

  const link = document.createElement('link');
  link.id = 'alexk-carousel-preload-next';
  link.rel = 'preload';
  link.as = 'image';

  // Always set an href
  link.href = imageObj.fallback;

  // If the browser supports responsive preload, give it the best candidates
  const sizes =
    imageObj.sizes ||
    (carouselEl && carouselEl.querySelector('img') ? carouselEl.querySelector('img').sizes : '') ||
    '100vw';

  if (imageObj.webp_srcset) {
    link.setAttribute('imagesrcset', imageObj.webp_srcset);
    link.setAttribute('imagesizes', sizes);
    link.type = 'image/webp';
  } else if (imageObj.jpg_srcset) {
    link.setAttribute('imagesrcset', imageObj.jpg_srcset);
    link.setAttribute('imagesizes', sizes);
    link.type = 'image/jpeg';
  }

  document.head.appendChild(link);
} // END OF PRELOAD CODE

function findCarousel() {
  return document.querySelector('[data-images]');
}

function findCarouselImage(carousel) {
  return carousel.querySelector('img');
}

function parseImagesData(carousel) {
  const raw = carousel.getAttribute('data-images');
  if (!raw) return [];

  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    return [];
  }
}

function shuffleInPlace(array) {
  // Shuffles IN PLACE (mutates array)
  for (let i = array.length - 1; i > 0; i--) {
    const j = getRandomInt(0, i);

    const temp = array[i];
    array[i] = array[j];
    array[j] = temp;
  }
}

function getRandomInt(min, max) {
  const range = max - min + 1;
  return Math.floor(Math.random() * range) + min;
}

function updateCarouselImage(imgElement, imageObj) {
  if (!imgElement) return;
  if (!imageObj) return;

  const pictureEl = imgElement.closest('picture');
  if (!pictureEl) return;

  const webpSource = pictureEl.querySelector('source[type="image/webp"]');
  const jpegSource = pictureEl.querySelector('source[type="image/jpeg"]');

  // Update WebP <source>
  if (webpSource && imageObj.webp_srcset) {
    webpSource.srcset = imageObj.webp_srcset;
    webpSource.sizes = imageObj.sizes || '100vw';
  }

  // Update JPEG <source>
  if (jpegSource && imageObj.jpg_srcset) {
    jpegSource.srcset = imageObj.jpg_srcset;
    jpegSource.sizes = imageObj.sizes || '100vw';
  }

  // Update <img> fallback
  imgElement.src = imageObj.fallback || imgElement.src;

  if (typeof imageObj.alt === 'string') imgElement.alt = imageObj.alt;

  // Optional: keep img sizes aligned with sources
  imgElement.sizes = imageObj.sizes || '100vw';
}

// Safari bug: drag-selection on large images can leave a detached
// selection paint layer (ghost highlight). We prevent selection
// gestures inside the carousel to avoid the WebKit bug.

/* =========================
   Safari ghost-selection guard (carousel only)
   Prevent Safari from entering buggy selection paint state.
   ========================= */
(function () {
  const carousel = document.querySelector(".alexk-carousel");
  if (!carousel) return;

  // Safari detection (good enough for this narrow fix)
  const ua = navigator.userAgent;
  const isSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Android/.test(ua);
  if (!isSafari) return;

  // Prevent drag-selection and image dragging inside carousel
  const killSelection = (e) => {
    // Only left-click drag / selection gestures
    if (e.type === "mousedown" && e.button !== 0) return;

    // Prevent Safari from creating a selection paint layer
    e.preventDefault();

    // Clear any phantom selection ranges (even if selection is empty)
    try { window.getSelection()?.removeAllRanges(); } catch {}
  };

  // Stop selection lifecycle events
  carousel.addEventListener("selectstart", killSelection, { passive: false });
  carousel.addEventListener("mousedown", killSelection, { passive: false });
  carousel.addEventListener("dragstart", killSelection, { passive: false });

  // Extra: ensure images aren't draggable
  carousel.querySelectorAll("img").forEach((img) => {
    img.setAttribute("draggable", "false");
  });
})();


