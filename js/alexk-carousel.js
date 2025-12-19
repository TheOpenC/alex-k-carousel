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

  // Your data-images objects definitely have "src"
  imgElement.src = imageObj.src;

  // Only set these if present (your JSON shows these exist)
  if (imageObj.srcset) imgElement.srcset = imageObj.srcset;
  if (imageObj.sizes) imgElement.sizes = imageObj.sizes;
  if (typeof imageObj.alt === 'string') imgElement.alt = imageObj.alt;
}

