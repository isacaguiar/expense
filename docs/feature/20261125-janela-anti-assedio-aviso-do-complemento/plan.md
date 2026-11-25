# Plan — Janela anti-assédio no aviso do complemento

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261125

---

## 1. Janela no aviso (specify R1, R2, R3, D1, D2, D3, D7)

Arquivo: `backend/app/Support/Notifier.php`.

- **Constante nova** `COMPLEMENT_NOTICE_WINDOW_MINUTES = 60` (D3-B), ao lado de `LINK_NOTICE_WINDOW_MINUTES`. Constante separada: a janela do vínculo (24 h) e a do complemento têm razões diferentes e não devem mudar juntas.
- **Chave nova** `complementNoticeKey(Group $group, mixed $cycleStart, int $debtorId, int $creditorId, int $authorId): string` → `"settlement-complement:{grupo}:{AAAA-MM-DD do ciclo}:{devedor}:{credor}:{autor}"` (D2-A). Função própria e não reuso de `linkNoticeKey`: o formato do vínculo é `payment-responsible:…` com `responsibleId` e `type`, e um prefixo distinto impede colisão entre os dois avisos no mesmo cache. A data sai de `Carbon::parse($cycleStart)->toDateString()` (o chamador passa `$entry->cycle_start`, que pode ser `Carbon` ou string).
- **`settlementComplementAdded`** ganha `int $debtorId, int $creditorId, int $authorId` e envolve o `fanOut` em `self::oncePer(chave, COMPLEMENT_NOTICE_WINDOW_MINUTES, fn () => …)`, dentro do `guard` que já existe. A assinatura atual é `$group, $recipientIds, $authorName, $debtorName, $amount, $cycleStart`; os três ids novos entram **no fim**, depois de `$cycleStart`, sem reordenar os existentes. O único chamador (o controller) é atualizado junto.
- **Por que `oncePer` e não código novo:** já faz `Cache::add` (marca por N minutos), desfaz a marca se o envio falha (`forget`) e fica dentro de `guard`, então uma falha do cache vira log e o complemento segue 201 (R3). Os dois avisos saem num único `fanOut`, logo juntos ou nenhum (R2). O `data` do aviso não muda (R4).
- **Janela só do aviso (D7-A):** nada em `SettlementConfirmationService`, `SettlementProofHistoryService` ou no teto de 10 é tocado. O complemento é sempre gravado.
- **Limite conhecido, aceito:** `Cache::add` não é atômico no driver de arquivo (já documentado em `oncePer`): dois pedidos no mesmo milissegundo podem passar, custando um aviso a mais, nunca um a menos. Em produção o driver é o de `.env`; não muda aqui.

## 2. Chamador (specify R1)

Arquivo: `backend/app/Http/Controllers/SettlementDecisionController.php`, método `complement` (chamada em ~214).

- Passa `$debtorId`, `$creditorId` e `$callerId` (já existem no método) à nova assinatura. Nenhuma outra mudança: a lista de destinatários e o 201 ficam como estão.

## 3. Testes (specify D6-A)

Arquivo: `backend/tests/Feature/SettlementComplementTest.php`, seção "os avisos".

- **Reescrever** `test_a_second_complement_notifies_again` (linha ~558) para `test_a_second_complement_within_the_window_does_not_notify_again`: dois complementos seguidos → 2 entradas no histórico e **2** avisos (um por destinatário), não 4.
- **Novos:**
  - `test_after_the_window_the_next_complement_notifies_again`: `Carbon::setTestNow` +61 min → 4 avisos. O arquivo já usa `setTestNow`; restaurar no `tearDown` se ainda não restaura (conferir).
  - `test_another_author_is_not_silenced_by_the_first`: dois responsáveis do mesmo devedor → 4 avisos (D2: a chave leva o autor).
  - `test_another_cycle_is_not_silenced`: mesmo autor e par, `cycles_ago` diferente → avisa de novo (a chave leva o ciclo).
  - `test_the_window_never_blocks_the_complement_itself`: dentro da janela o segundo continua 201, com entrada e arquivo gravados (D7-A).
  - `test_a_cache_failure_still_returns_201_and_logs`: o cache lança → 201, entrada gravada, 0 avisos, `Log::warning` do tipo `settlement_complement_added`. Mesma técnica do teste de falha do `Notifier` já existente (`Log::spy()` + `withArgs`).
- **Mutantes** (procedimento já usado nas features anteriores: backup + `cmp`, confirmar "mutante aplicado", restaurar): (a) tirar o autor da chave → `another_author` falha; (b) tirar o ciclo da chave → `another_cycle` falha; (c) trocar 60 por 24*60 → `after_the_window` falha; (d) remover o `oncePer` → o teste da janela falha.
- Os demais testes de aviso do arquivo (destinatários, `data` sem justificativa, rótulo do ciclo, recusa sem aviso) não mudam e devem seguir verdes.

## 4. Sem mudança em rota, front e contrato (specify D4-B)

- Sem `throttle` novo, sem limiter nomeado, sem 429. Rota e `useSettlementComplement` ficam intactos. Segurança: a revisão do `security-reviewer` no fechamento confere só o `Notifier` e o chamador (nada de auth novo).

## 5. Docs e backlog

- `docs/backlog/achados-informativos-da-revisao-de-seguranca-do-historico-de-comprovantes.md`: no fechamento, Status "Parcialmente promovido para TASK-xxx" (só o ponto 4), seção do ponto 4 marcada como resolvida com o PR; **o item permanece em `docs/backlog/`** com os pontos 1 e 3 abertos. Linha do README (`docs/backlog/README.md`) atualizada igual.
- ADR-009 (arquivar a pasta em `docs/feature/concluidas/202611/`) só depois do merge dele.

## 6. Ordem de execução

Há dependência técnica entre 1, 2 e 3: a assinatura nova do `Notifier` quebra o chamador, então o código (1 e 2) vai numa task só e fica verde com o teste de regressão antigo reescrito; os testes novos e os mutantes (3) vêm numa task seguinte. O fechamento (5) é a última. Sem tasks de front.
