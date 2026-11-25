# Tasks — Janela anti-assédio no aviso do complemento

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261125

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-687 | `Notifier::settlementComplementAdded` avisa no máximo uma vez por hora por autor, par e ciclo, e o controller passa os ids; o teste antigo vira o da janela | backend | plan.md §1, §2 e §3 (reescrita do teste) | nenhum | Pendente |
| TASK-688 | Cobrir a janela com os testes de borda (fim da janela, outro autor, outro ciclo, complemento intacto, falha do cache) e provar com mutantes | backend | plan.md §3 | nenhum | Pendente |
| TASK-689 | Registrar a promoção parcial no item 155 (só o ponto 4) e no README do backlog | doc | plan.md §5 | nenhum | Pendente |

Ordem: 687 em RED antes do código (o teste reescrito falha com o `Notifier` atual); 688 depois; 689 por último (código antes de doc). A 687 junta o `Notifier` e o chamador porque a assinatura nova quebra o controller. No fechamento da feature rodar a suíte cheia, o `security-reviewer` e o `pr-readiness-checker`. O item 155 passa a "Parcialmente promovido para TASK-687" na aprovação destas tasks e continua em `docs/backlog/` (pontos 1 e 3 abertos) mesmo depois da 689.

## Critérios de aceite

- **TASK-687**:
  - `Notifier.php`: constante `COMPLEMENT_NOTICE_WINDOW_MINUTES = 60`; função privada `complementNoticeKey(Group, mixed $cycleStart, int $debtorId, int $creditorId, int $authorId)` com prefixo `settlement-complement:` e a data do ciclo em `AAAA-MM-DD`; `settlementComplementAdded` ganha `int $debtorId, int $creditorId, int $authorId` no fim da assinatura e envolve o `fanOut` em `oncePer`, dentro do `guard`. O `data` do aviso não muda.
  - `SettlementDecisionController::complement` passa `$debtorId`, `$creditorId` e `$callerId`; lista de destinatários, resposta 201 e demais fluxos iguais.
  - `test_a_second_complement_notifies_again` vira `test_a_second_complement_within_the_window_does_not_notify_again`: dois complementos seguidos do mesmo autor → 2 entradas no histórico e **2** avisos (um por destinatário). Nasce em RED (com o `Notifier` atual dá 4) antes do código.
  - O arquivo `SettlementComplementTest.php` inteiro, `SettlementHistoryAuthorizationMatrixTest.php` e `NotifierTriggersTest.php` seguem verdes; `./vendor/bin/pint --test` limpo.
- **TASK-688**:
  - Cinco testes em `SettlementComplementTest.php`, seção dos avisos: (1) `test_after_the_window_the_next_complement_notifies_again` (`Carbon::setTestNow` com +61 min → 4 avisos; e +59 min → ainda 2); (2) `test_another_author_is_not_silenced_by_the_first` (dois responsáveis do mesmo devedor → 4 avisos); (3) `test_another_cycle_is_not_silenced` (`cycles_ago` diferente, mesmo autor e par → avisa de novo); (4) `test_the_window_never_blocks_the_complement_itself` (dentro da janela o 2º segue 201, com entrada e arquivo gravados); (5) `test_a_cache_failure_still_returns_201_and_logs` (cache lançando → 201, entrada gravada, 0 avisos, `Log::warning` do tipo `settlement_complement_added`, `Log::spy()` + `withArgs`).
  - O `setTestNow` é restaurado ao fim de cada teste (conferir o `tearDown`; sem vazamento para os outros testes do arquivo).
  - Mutantes derrubados por ao menos um teste cada, aplicados com backup e `cmp`, conferindo "mutante aplicado" e restaurando idêntico: (a) tirar o autor da chave; (b) tirar o ciclo da chave; (c) trocar 60 por `24 * 60`; (d) remover o `oncePer`.
  - Arquivo de teste inteiro verde e Pint limpo.
- **TASK-689**:
  - `docs/backlog/achados-informativos-da-revisao-de-seguranca-do-historico-de-comprovantes.md`: Status "Parcialmente promovido para TASK-687 e TASK-688 (ponto 4)"; o ponto 4 marcado como resolvido com a feature e o PR; pontos 1 e 3 seguem abertos; o arquivo **fica** em `docs/backlog/`.
  - `docs/backlog/README.md` (linha do 155): mesmo status, texto do ponto 4 ajustado; sem mover para "Concluídos".
  - Verificado com `grep` que nenhum link quebrou e que o item continua listado como aberto.
