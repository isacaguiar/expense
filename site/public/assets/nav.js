/**
 * Menu de navegação no celular.
 *
 * Progressive enhancement deliberado: o CSS só esconde a lista de navegação
 * quando este arquivo marca `data-nav` no <html>. Se o script não carregar,
 * o menu aparece empilhado e continua utilizável — ao contrário do
 * comportamento anterior, em que o CSS escondia a navegação de forma
 * incondicional abaixo de 640px, sem nenhuma alternativa.
 */
(function () {
  'use strict';

  var root = document.documentElement;
  var toggle = document.querySelector('.nav-toggle');
  var list = document.getElementById('main-nav-list');

  if (!toggle || !list) {
    return;
  }

  // A partir daqui o CSS pode colapsar com segurança.
  root.setAttribute('data-nav', 'collapsed');

  function setOpen(open) {
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Fechar menu de navegação' : 'Abrir menu de navegação');
    root.setAttribute('data-nav', open ? 'open' : 'collapsed');
  }

  toggle.addEventListener('click', function () {
    setOpen(toggle.getAttribute('aria-expanded') !== 'true');
  });

  // Navegar para uma âncora da mesma página não recarrega o documento, então o
  // menu ficaria aberto por cima do conteúdo.
  list.addEventListener('click', function (event) {
    if (event.target.closest('a')) {
      setOpen(false);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
      setOpen(false);
      toggle.focus();
    }
  });
})();
