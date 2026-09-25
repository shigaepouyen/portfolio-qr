import PhotoSwipeLightbox from './vendor/photoswipe-lightbox.esm.min.js';

const gallery = document.getElementById('gallery');

if (gallery) {
  const lightbox = new PhotoSwipeLightbox({
    gallery: '#gallery',
    children: 'a',
    pswpModule: () => import('./vendor/photoswipe.esm.min.js'),
    bgOpacity: 1,
    showHideAnimationType: 'fade',
    showAnimationDuration: 220,
    hideAnimationDuration: 180,
    zoom: false,
    counter: true,
    preload: [1, 2],
    padding: { top: 24, bottom: 56, left: 0, right: 0 },
    closeTitle: 'Fermer',
    arrowPrevTitle: 'Précédente',
    arrowNextTitle: 'Suivante',
    errorMsg: 'Image indisponible',
  });

  // Légende discrète sous la photo.
  lightbox.on('uiRegister', () => {
    lightbox.pswp.ui.registerElement({
      name: 'caption',
      order: 9,
      isButton: false,
      appendTo: 'root',
      onInit: (el, pswp) => {
        pswp.on('change', () => {
          const a = pswp.currSlide?.data?.element;
          el.textContent = a?.dataset.caption || '';
        });
      },
    });
  });

  lightbox.init();
}
