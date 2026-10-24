# Bugfix — Alerta "ainda falta acertar" incoerente depois de pagamentos em ciclo fechado

Versão: 1.0 · Criado em: 20261024 · Branch: `fix/20261024-alerta-ciclo-fechado-apos-pagamento`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.
>
> Origem: relato do usuário em 2026-10-24 de aviso incoerente após pagamentos em ciclo fechado. A investigação (§1) foi feita primeiro, só por leitura; a correção (§2) foi aprovada pelo usuário no mesmo dia e implementada na branch acima. Verificações em §3.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção prevista é só no componente de apresentação `CycleClosingAlert.tsx`. Nenhuma rota, controller ou middleware muda e ninguém passa a poder fazer o que antes não podia. O componente já lê o `summary` que a tela recebe. Mesmo raciocínio do precedente `concluidos/202610/20261006-acerto-comprovante-sem-cycles-ago.md`. **Condição de escalação:** se a correção passar a exigir que o backend exponha algo que hoje não expõe, marcar esta caixa.
- [ ] **Migration ou contrato de API** — nenhum schema, rota, payload ou status code muda. O critério novo usa campos que o `summary` já devolve: `settlements[].confirmedAt` (`CycleSummaryService.php:341`), `settlements[].from_user_id` e `balances[].name`. **Condição de escalação:** se faltar um campo no `summary`, marcar esta caixa.
- [ ] **Causa raiz obscura / correção ampla** — causa confirmada por leitura de código (§1), em 1 arquivo de produção (`CycleClosingAlert.tsx`) mais o teste dele. Longe do limite de ~3 arquivos.
- [ ] **Decisão de produto/arquitetura** — o specify de origem (`docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/specify.md:156-158`) já aceitava "`settlements` sem confirmação" como critério de devedor; o desvio foi do `plan.md`. Para o estado "todos os acertos confirmados, ainda há despesa pendente", o usuário decidiu em 2026-10-24 que **o alerta simplesmente some**, sem mensagem nova. **Condição de escalação:** se o usuário voltar a querer um aviso informativo nesse estado, é texto e estado novos: marcar esta caixa e tratar em `/nova-feature` (a ideia está no item de backlog 100).

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** em ciclo fechado e ainda não selado, o aviso amarelo "O ciclo fechou e ainda falta acertar: …" continua listando pessoas cujo acerto já foi confirmado, e deixa de listar quem ainda deve quando os saldos se compensam. Quando todos os acertos já estão confirmados e só faltam despesas a marcar como pagas, ele continua cobrando os membros.
- **Reprodução:** a causa foi estabelecida por leitura do código e dos testes; a reprodução em execução foi feita como **testes em vermelho antes da correção** (§3: 6 dos 12 testes do alerta falhavam, entre eles os cenários 3, 4, 6 e 7). Passos para reproduzir à mão:
  1. Grupo com 3 membros (Ana, Bruno e Carla), ciclo fechado por data ou manualmente, uma despesa de Carla dividida entre os três: dois acertos pendentes (Ana→Carla, Bruno→Carla).
  2. Ana envia o comprovante do acerto (`POST .../settlements/confirm`, Pagamentos). O `summary` volta com `confirmedAt` preenchido no par da Ana e ela continua com saldo negativo.
  3. Abrir Home ou Despesas do mesmo ciclo: o aviso lista "Ana, Bruno".
  4. Bruno também confirma, mas a despesa de Carla ainda não foi marcada como paga: ciclo não selado (`totals.pending > 0`) e o aviso continua listando "Ana, Bruno".
- **Esperado vs. atual:**
  - Esperado: o aviso lista só quem tem acerto sem confirmação; sem acerto pendente, não aparece; ciclo quitado e selado não mostra cobrança.
  - Atual: lista quem tem saldo negativo, independentemente de pagamento ou confirmação.
- **Cenários (hoje × esperado):**

  | # | Cenário | Hoje | Esperado |
  |---|---|---|---|
  | 1 | Credor paga uma despesa; ainda há acertos pendentes | lista devedores por saldo | lista por acerto pendente |
  | 2 | Credor pagou tudo, devedores não confirmaram | lista os devedores (certo) | igual |
  | 3 | Devedor confirma 1 de 2 acertos | continua listando os dois — **bug** | só quem tem acerto pendente |
  | 4 | Todos os acertos confirmados, ainda há despesa pendente | lista todos — **bug** | alerta some (decisão do usuário) |
  | 5 | Ciclo quitado e selado | `null` (ok) | `null` (guarda de regressão) |
  | 6 | Dívida circular A→B→C→A: saldos 0, 3 acertos pendentes | **não avisa** | lista os 3 devedores |
  | 7 | Credor líquido que ainda deve a terceiro | não listado | listado (é `from_user_id` de um par) |
  | 8 | `closed_manually` (revisável) e `closed` por data | mesma lógica | mesma lógica |
  | 9 | Ação em ciclo anterior (`cyclesAgo > 0`) | reload preserva o ciclo | idem (teste de caracterização) |

- **Causa raiz:**
  - `frontend/src/components/CycleClosingAlert.tsx:50-59`: `debtors = balances.filter(b => b.balance < 0)` e o alerta renderiza se `cycleClosed && !cycle.settled && debtors.length > 0`. Só `cycle.settled` (ciclo selado) o cala.
  - `backend/app/Services/CycleSummaryService.php:238-271` (`compute`): `balances` soma o rateio de **todas** as despesas do ciclo, sem olhar `paid` (só pula `bornPaid`, `:246`) nem `SettlementConfirmation`. Marcar despesa como paga ou confirmar acerto não altera `balances`; é o desenho (comentário `:239-245`).
  - O critério de "quitado" do próprio backend é outro: `CycleSummaryService.php:371-398` (`summaryIsFullySettled`): `totals.pending == 0` e todo par de `settlements` com confirmação (`:389-394`). O `confirmedAt` chega decorado por `withConfirmations` (`:328-350`).
  - Origem do desvio: `specify.md:156-158` aceitava "`balances` com saldo negativo, **ou** `settlements` sem confirmação"; `plan.md:301-304` implementou só a primeira metade.
  - **Por que os testes não pegam:** `CycleClosingAlert.test.tsx:45-62` monta só `balances`; nenhum teste do arquivo tem `confirmedAt`. Nenhum teste de página afirma o alerta (busca por `CycleClosingAlert|falta acertar` em `ExpenseManager/GroupSummary/Payments.test.tsx` não acha nada, apesar da TASK-259 prometer um "assert leve").
- **Hipóteses descartadas ou separadas:**
  - *Selagem/ciclo quitado:* funciona. `ExpenseController.php:1065` (`pay`) e `:1352` (`confirmSettlement`) selam antes de responder; `summary()` sela de forma preguiçosa (`:696`) e devolve `settled=true` (`:678`, `:710`). `unpay` dessela (`:1140`). Ciclo quitado → alerta `null`.
  - *Reload (critério 4):* já atendido. `usePaymentActions.ts:121-124`, `:145-148`, `:183-186`, `Payments.tsx:180-184` e `ExpenseManager.tsx:236/259/282/333` chamam `reload()`. `useGroupCycle.ts:149-171` religa o efeito por `reloadToken` e preserva `cyclesAgo`; as ações mandam `cycles_ago`. Só o reload de *editar comprovante* tem teste (`usePaymentActions.test.tsx:263`, `Payments.test.tsx:886`, `ExpenseManager.test.tsx:1726`); pagar, desfazer e confirmar acerto não têm asserção de re-`GET`.
  - *Onde o alerta aparece:* `GroupSummary.tsx:56` e `ExpenseManager.tsx:646`. `Payments.tsx`, onde o devedor confirma o acerto, não renderiza o alerta. Cada tela monta seu próprio `useGroupCycle`, sem cache entre telas.
  - *Dados desatualizados (achados à parte, não causam o relato):* o alerta de `ExpenseManager.tsx:646` fica fora do ramo `loading` (`:659`) e `useGroupCycle.ts:152` não limpa `summary`, então o aviso antigo permanece durante o reload; e `useGroupCycle.ts:149-171` não descarta resposta fora de ordem (latente, **não reproduzido**). Vão para o backlog 101.

## 2. Correção

> Implementada em 2026-10-24, com aprovação do usuário.

- **O que muda e por quê:** o trecho `CycleClosingAlert.tsx:50-59` passa a derivar os devedores dos acertos pendentes, o mesmo critério par-a-par de `summaryIsFullySettled`:
  - `pending = settlements.filter(s => !s.confirmedAt)`;
  - devedores = `from_user_id` únicos de `pending`, na ordem do `summary`, com o nome vindo de `balances` (fallback `'Desconhecido'`, como `PayableSettlementList.tsx:47`);
  - o aviso `warning` só renderiza se `cycleClosed && !cycle.settled && pending.length > 0`, com o mesmo texto de hoje;
  - sem acerto pendente → `null` (cobre os critérios 3 e 5). O ramo pré-fechamento, o cálculo e a selagem do backend ficam intocados.
- **Arquivos tocados:** código de produção, só `frontend/src/components/CycleClosingAlert.tsx` (função `pendingDebtorNames` + uso no ramo pós-fechamento). Testes: `frontend/src/components/CycleClosingAlert.test.tsx`, `frontend/src/pages/ExpenseManager.test.tsx`, `frontend/src/hooks/useGroupCycle.test.tsx` e `backend/tests/Feature/CycleSummaryServiceTest.php` (só uma asserção nova). Backend de produção, contrato e migrations: intocados.
- **Teste de regressão:**
  - `CycleClosingAlert.test.tsx`: helper `summary()` ganhou `settlements` e `pending`; 12 testes, entre eles confirmação parcial, tudo confirmado com despesa pendente, dívida circular com saldos 0, credor líquido que ainda deve, devedor repetido, nome ausente (`Desconhecido`) e `closed_manually`. O teste antigo "lists the debtors…" foi reescrito porque só montava `balances` (critério errado).
  - `ExpenseManager.test.tsx` (novo `describe`): depois de pagar, o 2º `GET summary` preserva `cycles_ago` e troca o aviso; com todos os acertos confirmados não há aviso.
  - `useGroupCycle.test.tsx`: `reload()` preserva a competência e não repete o `focus-cycle`.
  - `CycleSummaryServiceTest`: `balances` não muda ao pagar a despesa nem ao confirmar o acerto (o invariante em que a correção se apoia).
- **Riscos / efeitos colaterais:** o aviso deixa de aparecer no estado "acertos confirmados, despesas pendentes"; é a decisão do usuário, e quem precisa agir é o credor, cujas despesas seguem como "Pendente" na lista. Um acerto pode referenciar usuário fora de `balances` (membro removido): por isso o fallback de nome. Nenhum outro efeito identificado.
- **Fora deste bug:** a melhoria visual (status acima do alerta, contraste, distinguir fechado / revisável / quitado) é comportamento novo → backlog 100. Os dados desatualizados → backlog 101.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-10-24 | `cd frontend && npx vitest run src/components/CycleClosingAlert.test.tsx` (testes novos, componente ainda sem a correção) | 6 falhando / 6 passando de 12: confirmação parcial, tudo confirmado, dívida circular, credor líquido, `Desconhecido` e `closed_manually` (cenários 3, 4, 6, 7 em vermelho) |
| 2026-10-24 | mesmo comando, com `CycleClosingAlert.tsx` corrigido | 12 passed (12) |
| 2026-10-24 | `git stash push frontend/src/components/CycleClosingAlert.tsx` + `npx vitest run src/pages/ExpenseManager.test.tsx -t "aviso de fechamento"`, depois `git stash pop` | com a lógica antiga os 2 testes de página novos falham; correção restaurada |
| 2026-10-24 | `cd frontend && npx vitest run src/hooks/useGroupCycle.test.tsx src/components/CycleClosingAlert.test.tsx src/pages/ExpenseManager.test.tsx` | 3 arquivos, 108 testes passed |
| 2026-10-24 | `cd frontend && npx tsc --noEmit` | sem erros (exit 0) |
| 2026-10-24 | `cd frontend && npx vitest run` (suíte completa, 1ª vez, com migração e build rodando em paralelo) | 6 falhas em `Payments.test.tsx` e `RegisterPage.test.tsx` (arquivos não tocados; ~15 s por teste = timeout por carga); isolados, `npx vitest run src/pages/Payments.test.tsx src/pages/RegisterPage.test.tsx` deu 66 passed |
| 2026-10-24 | `cd frontend && npx vitest run` (suíte completa, 2ª vez, sem carga) | 53 arquivos, 610 testes passed (exit 0) |
| 2026-10-24 | `cd frontend && npx vite build` | `built in 11.46s` (exit 0); o build reescreve `frontend/dist/index.html`, que é versionado (backlog 037): restaurado com `git checkout`, fora do commit |
| 2026-10-24 | `cd backend && php artisan test --filter=CycleSummaryServiceTest` (MySQL isolado 3307) | 32 passed (125 assertions), com o teste novo `balances do not change when an expense is paid or a settlement is confirmed` |
| 2026-10-24 | `cd backend && ./vendor/bin/pint --test tests/Feature/CycleSummaryServiceTest.php` | PASS |
| 2026-10-24 | Validação manual na stack isolada (backend 8002, Vite 3002, MySQL 3307), 3 grupos de setembro/2026, login como o credor | Parcial (Ana confirmou, Bruno não): Home mostra "falta acertar: Bruno." com o saldo da Ana ainda "R$ 100,00 a pagar". Tudo confirmado com despesa pendente: sem aviso. Dívida circular (saldos R$ 0,00): "falta acertar: Bruno, Carla, Ana." Em Despesas, "Marcar como paga" recarrega na mesma competência (setembro) e o aviso segue só com o Bruno; depois da confirmação dele e do pagamento, setembro fica "Definitivo" sem aviso e o app passa a abrir em outubro. Console do navegador sem erros. |
| 2026-10-24 | Desmontagem do ambiente de teste (após o "validado" do usuário) | Parados backend (8002) e Vite (3002); `backend/.env.testing` removido; container `mysql-expense-test` removido; aba do Chrome fechada; `git push -u origin fix/20261024-alerta-ciclo-fechado-apos-pagamento` e PR #234 aberto contra `dev` |

## Resolução
Concluído em: 2026-10-24
Branch: fix/20261024-alerta-ciclo-fechado-apos-pagamento
PR: https://github.com/isacaguiar/expense/pull/234
