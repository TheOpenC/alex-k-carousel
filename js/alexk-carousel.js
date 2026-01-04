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

  updateCarouselImage(imgElement, getNextImage(state));
  carousel.addEventListener('click', onCarouselClick);

  // store state on the carousel so the click handler can access it
  carousel._alexkCarousel = { imgElement: imgElement, state: state };
}

function onCarouselClick(event) {
  const carousel = event.currentTarget;
  const store = carousel._alexkCarousel;
  if (!store) return;

  updateCarouselImage(store.imgElement, getNextImage(store.state));
}

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
  if (!state.deck || state.deck.length === 0) {
    let tries = 0;

    do {
      state.deck = state.allImages.slice();
      shuffleInPlace(state.deck);
      tries += 1;

      // Break if we somehow can't avoid it (e.g., only 1 image)
      if (tries > 10) break;

      // If the next candidate (last element, since we pop) equals lastShown, reshuffle
    } while (
      state.lastShown &&
      state.deck.length > 1 &&
      state.deck[state.deck.length - 1].fallback === state.lastShown
    );
  }

  const next = state.deck.pop();
  if (next && next.fallback) state.lastShown = next.fallback;
  return next;
}

// function createCarouselState(images) {
//   const imageDeck = images.slice();   // copy (we will mutate the imageDeck)
//   shuffleInPlace(imageDeck);

//   return {
//     imageDeck: imageDeck,
//     index: 0
//   };
// }

// function getNextImage(state) {
//   if (state.imageDeck.length === 0) return null;

//   if (state.index >= state.imageDeck.length) {
//     shuffleInPlace(state.imageDeck);
//     state.index = 0;
//   }

//   const value = state.imageDeck[state.index];
//   state.index += 1;
//   return value;
// }

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


// function updateCarouselImage(imgElement, imageObj) {
//   if (!imgElement) return;
//   if (!imageObj) return;

//   // Prefer updating the <picture> source if it exists
//   const pictureEl = imgElement.closest('picture');
//   const sourceEl = pictureEl ? pictureEl.querySelector('source[type="image/webp"]') : null;

//   // Update WebP candidate set
//   if (sourceEl && imageObj.webp_srcset) {
//     sourceEl.srcset = imageObj.webp_srcset;
//     sourceEl.sizes = imageObj.sizes || '90vw';
//   }

//   // Update fallback <img> (JPEG srcset), keep original src as final fallback
//   imgElement.src = imageObj.src;

//   if (imageObj.jpg_srcset) {
//     imgElement.srcset = imageObj.jpg_srcset;
//   } else {
//     // If no jpg srcset provided, clear it so the browser doesn't stick to an old one
//     imgElement.removeAttribute('srcset');
//   }

//   imgElement.sizes = imageObj.sizes || '90vw';

//   if (typeof imageObj.alt === 'string') imgElement.alt = imageObj.alt;
// }


