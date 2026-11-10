# Bugfix — Dois testes do convite pendente fixam a validade em 10/11 e passaram a falhar

Versão: 1.0 · Criado em: 20261110 · Branch: `fix/20261110-testes-convite-com-data-fixa`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — não marcada: o assunto dos testes é convite de grupo, mas a correção só muda dado de teste (um `Carbon::setTestNow`); nenhuma rota, controller, middleware ou regra de autorização é tocada.
- [ ] **Migration ou contrato de API** — não marcada: nenhuma migration, nenhum formato de resposta muda.
- [ ] **Causa raiz obscura / correção ampla** — não marcada: causa raiz confirmada em código e correção em 2 arquivos de teste, 1 linha em cada.
- [ ] **Decisão de produto/arquitetura** — não marcada.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** `GroupInvitationControllerTest::test_the_payload_has_exactly_the_agreed_keys` e `MyInvitationControllerTest::test_the_payload_names_the_group_and_who_invited` falham desde as 12:00 UTC de 2026-11-10, com `TypeError: array_keys(): Argument #1 ($array) must be of type array, null given` e `ErrorException: Trying to access array offset on value of type null`. O CI de qualquer PR contra `dev` (`.github/workflows/ci-backend.yml:76`, `php artisan test` em `pull_request`) fica vermelho nesses dois testes.
- **Reprodução:** com o relógio da máquina depois de 2026-11-10 12:00 UTC, `cd backend && ./vendor/bin/phpunit --filter='test_the_payload_has_exactly_the_agreed_keys|test_the_payload_names_the_group_and_who_invited'`. Foi vista numa suíte inteira (`./vendor/bin/phpunit` → `Tests: 1195, Errors: 2`) em banco recém-migrado, na branch `feature/20261109-responsavel-do-devedor`, cujo diff não toca esses arquivos.
- **Esperado vs. atual:** esperado, a listagem devolve o convite pendente criado pelo teste e o teste confere o formato do payload; atual, a listagem vem vazia (`json(0)` é `null`).
- **Causa raiz:** os dois testes gravam o convite com `'expires_at' => '2026-11-10 12:00:00'` sem congelar o relógio (`backend/tests/Feature/GroupInvitationControllerTest.php:129` e `backend/tests/Feature/MyInvitationControllerTest.php:135`). `GroupInvitation::scopeOpen` só considera vivo o convite com `expires_at > now()` (`backend/app/Models/GroupInvitation.php:74-78`), e as duas listagens usam esse escopo (`GroupInvitationController.php:33`, `MyInvitationController.php:27`). Passada a data, o convite do teste já está vencido e some da lista. Os testes foram escritos em 2026-11-03 (TASK-573 a 576), quando 10/11 ainda era futuro; o resto dos testes dessas classes congela o relógio com `Carbon::setTestNow('2026-11-03 …')`. **O código de produção está certo**: convite vencido não deve aparecer.

## 2. Correção

- **O que muda e por quê:** no começo de cada um dos dois testes, `Carbon::setTestNow('2026-11-03 12:00:00');`. É o instante em que um convite de 7 dias (`GroupInvitation::VALIDITY_DAYS`) vence em `2026-11-10 12:00:00`, exatamente a validade que o teste já fixava, e é o padrão dos testes vizinhos. As duas classes já resetam o relógio no `tearDown` (`Carbon::setTestNow()`), então o congelamento não vaza. Nenhum valor esperado muda.
- **Arquivos tocados:** `backend/tests/Feature/GroupInvitationControllerTest.php` e `backend/tests/Feature/MyInvitationControllerTest.php`.
- **Teste de regressão:** os próprios dois testes. Antes da correção falham com a data de hoje; depois passam por construção, porque o resultado não depende mais do dia em que rodam.
- **Riscos / efeitos colaterais:** nenhum identificado. Varredura por outras datas fixas de validade em `backend/tests` (`expires_at` com literal): só estes dois não congelam o relógio; os demais (`MyInvitationControllerTest:105-106`, `GroupInvitationCreateTest`) já usam `Carbon::setTestNow`.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-11-10 | RED, antes da correção, relógio da máquina em 21:23 UTC: `cd backend && ./vendor/bin/phpunit --filter='test_the_payload_has_exactly_the_agreed_keys\|test_the_payload_names_the_group_and_who_invited'` | `Tests: 2, Assertions: 2, Errors: 2` (`TypeError: array_keys(): Argument #1 ($array) must be of type array, null given` e `ErrorException: Trying to access array offset on value of type null`) |
| 2026-11-10 | O mesmo comando, depois da correção | `OK (2 tests, 12 assertions)`: o bug não reproduz mais |
| 2026-11-10 | `cd backend && ./vendor/bin/phpunit --filter='GroupInvitationControllerTest\|MyInvitationControllerTest'` | `OK (82 tests, 314 assertions)` |
| 2026-11-10 | `cd backend && ./vendor/bin/pint --test` | `PASS` em 220 arquivos |
| 2026-11-10 | `cd backend && ./vendor/bin/phpunit` (suíte inteira, código da `dev`, MySQL descartável recém-migrado) | `OK (1123 tests, 6779 assertions)`, 2:21 |

O resultado dos dois testes deixou de depender do dia em que rodam: o relógio é congelado dentro deles e o `tearDown` das duas classes o desfaz. Nenhum valor esperado mudou. Triagem sem nenhuma caixa marcada.
