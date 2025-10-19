(function () {
  function onClick(e) {
    const a = e.target.closest('.js-mpclub-subscribe');
    if (!a) return;
    e.preventDefault();
    const url = a.href + (a.href.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    fetch(url, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(resp => {
        if (!resp || !resp.success) {
          if (window.$ && $.growl) $.growl.error({ message: (resp && resp.message) || 'Erreur lors de l’ajout.' });
          return;
        }
        if (window.prestashop && prestashop.emit) {
          prestashop.emit('updateCart', {
            reason: { linkAction: 'add-to-cart', idProduct: resp.id_product, idProductAttribute: resp.id_product_attribute },
            resp: resp
          });
        }
        if (window.$ && $.growl && resp.message) $.growl.notice({ message: resp.message });
      })
      .catch(() => { if (window.$ && $.growl) $.growl.error({ message: 'Erreur réseau lors de l’ajout.' }); });
  }
  document.addEventListener('click', onClick);
})();
