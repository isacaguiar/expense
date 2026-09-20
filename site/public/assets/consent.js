/**
 * Consentimento de cookies e carregamento condicional do Google Analytics.
 *
 * O `header.php` já declarou o estado padrão do Consent Mode v2 como negado e
 * publicou `window.SCD_GA_ID`. Este arquivo é quem decide se o `gtag.js` chega
 * a ser carregado: sem um "aceitar" explícito, nenhuma requisição sai para o
 * Google e nenhum cookie de analytics é criado.
 *
 * Falha fechada de propósito: se este script não carregar, não há banner nem
 * medição. É o oposto do `nav.js`, onde o menu precisa sobreviver sem script —
 * lá o custo de falhar fechado seria uma navegação inacessível; aqui falhar
 * aberto seria medir sem consentimento.
 *
 * O cookie `scd_consent` é lido também pelo app React em /app, que roda na
 * mesma origem (ver `frontend/src/analytics/consent.ts`). Mudar o nome do
 * cookie ou os valores aceitos aqui exige mudar lá também: são deploys
 * separados, sem código compartilhado.
 */
(function () {
  'use strict';

  var COOKIE = 'scd_consent';

  // 180 dias: consentimento renovável, não eterno — depois disso perguntamos
  // de novo.
  var MAX_AGE = 60 * 60 * 24 * 180;

  var banner = document.getElementById('consent-banner');

  // Sem o bloco do `header.php` não há Consent Mode declarado; carregar o
  // analytics assim mesmo seria medir a partir de um estado indefinido.
  if (typeof window.gtag !== 'function') {
    return;
  }

  function decision() {
    var match = document.cookie.match(/(?:^|;\s*)scd_consent=(granted|denied)/);
    return match ? match[1] : null;
  }

  function remember(value) {
    // `Secure` só em https: no servidor local o site roda em http e o cookie
    // precisa ser gravado do mesmo jeito, senão a escolha não sobrevive.
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie =
      COOKIE + '=' + value + '; Path=/; Max-Age=' + MAX_AGE + '; SameSite=Lax' + secure;
  }

  function loadAnalytics() {
    var id = window.SCD_GA_ID;

    if (!id || window.SCD_GA_LOADED) {
      return;
    }

    window.SCD_GA_LOADED = true;

    window.gtag('consent', 'update', { analytics_storage: 'granted' });

    var script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(script);

    window.gtag('js', new Date());
    window.gtag('config', id);
  }

  function show() {
    if (banner) {
      banner.hidden = false;
    }
  }

  function hide() {
    if (banner) {
      banner.hidden = true;
    }
  }

  function choose(value) {
    remember(value);
    hide();

    if (value === 'granted') {
      loadAnalytics();
      return;
    }

    // Numa primeira visita isto é redundante (o padrão já é negado), mas é o
    // que faz a revogação valer na hora, sem esperar o próximo carregamento.
    window.gtag('consent', 'update', { analytics_storage: 'denied' });
  }

  if (banner) {
    banner.addEventListener('click', function (event) {
      var button = event.target.closest('[data-consent]');

      if (button) {
        choose(button.getAttribute('data-consent'));
      }
    });
  }

  var current = decision();

  if (current === 'granted') {
    loadAnalytics();
  } else if (current === null) {
    show();
  }

  // Usado pelo botão "Preferências de cookies" do rodapé, que reabre a escolha.
  window.scdConsent = { open: show, decision: decision };
})();
