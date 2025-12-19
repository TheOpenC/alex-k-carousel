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
