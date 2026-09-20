<?php

declare(strict_types=1);

/**
 * Banner de consentimento de cookies.
 *
 * Nasce com `hidden`: sem JavaScript não há banner e não há medição — falha
 * fechada, ao contrário do `nav.js`, onde o menu precisa continuar usável sem
 * script. Quem revela o banner é o `consent.js`, e só quando não existe decisão
 * gravada no cookie `scd_consent`.
 *
 * Não é modal de propósito: bloquear a leitura da página para exigir uma
 * escolha cobraria caro em conversão, e a LGPD exige escolha livre, não
 * bloqueio.
 *
 * @var array<string, mixed> $config
 */
?>
  <section class="consent-banner" id="consent-banner" aria-label="Aviso de cookies" hidden>
    <div class="consent-banner-inner">
      <p class="consent-banner-text">
        Usamos cookies do Google Analytics para entender como o site é usado. Eles só são
        ativados se você aceitar, e a escolha pode ser mudada quando quiser, pelo rodapé.
        Detalhes na <a href="/privacidade.php">Política de Privacidade</a>.
      </p>
      <div class="consent-banner-actions">
        <button type="button" class="btn btn-outline" data-consent="denied">Recusar</button>
        <button type="button" class="btn btn-primary" data-consent="granted">Aceitar</button>
      </div>
    </div>
  </section>
