document.addEventListener('DOMContentLoaded', onDomReady);

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
  const imageDeck = images.slice();   // copy (we will mutate the imageDeck)
  shuffleInPlace(imageDeck);

  return {
    imageDeck: imageDeck,
    index: 0
  };
}

function getNextImage(state) {
  if (state.imageDeck.length === 0) return null;

  if (state.index >= state.imageDeck.length) {
    shuffleInPlace(state.imageDeck);
    state.index = 0;
  }

  const value = state.imageDeck[state.index];
  state.index += 1;
  return value;
}

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

  // Prefer updating the <picture> source if it exists
  const pictureEl = imgElement.closest('picture');
  const sourceEl = pictureEl ? pictureEl.querySelector('source[type="image/webp"]') : null;

  // Update WebP candidate set
  if (sourceEl && imageObj.webp_srcset) {
    sourceEl.srcset = imageObj.webp_srcset;
    sourceEl.sizes = imageObj.sizes || '90vw';
  }

  // Update fallback <img> (JPEG srcset), keep original src as final fallback
  imgElement.src = imageObj.src;

  if (imageObj.jpg_srcset) {
    imgElement.srcset = imageObj.jpg_srcset;
  } else {
    // If no jpg srcset provided, clear it so the browser doesn't stick to an old one
    imgElement.removeAttribute('srcset');
  }

  imgElement.sizes = imageObj.sizes || '90vw';

  if (typeof imageObj.alt === 'string') imgElement.alt = imageObj.alt;
}


