# Bugfix — Acerto do devedor: comprovante de ciclo anterior recusado por falta de `cycles_ago`

Versão: 1.0 · Criado em: 20261006 · Branch: `fix/20261006-acerto-comprovante-sem-cycles-ago`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.
>
> Origem: relato do usuário em 2026-10-06 ("permitir o pagamento ao credor antes do pagamento da despesa após fechar o mês — atualmente só libera após o pagamento de todas as despesas"), com capturas de tela de produção. A análise mostrou que a regra pedida **já existe** e que o que trava é este defeito de cliente.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção prevista é só de cliente: acrescentar `cycles_ago` ao formulário do envio de comprovante do acerto. Ninguém passa a poder fazer o que antes não podia, o backend não é tocado e o armazenamento do comprovante (disco privado, URL assinada) não muda. O comprovante é dado financeiro, mas o fluxo de acesso a ele fica idêntico. **Condição de escalação:** se a correção passar a mudar *quem* pode confirmar um acerto (por exemplo, o credor ou o criador do grupo enviando em nome do devedor), isso é regra de autorização: marcar esta caixa e abrir `/nova-feature`.
- [ ] **Migration ou contrato de API** — nenhum schema, rota, payload ou status code muda. `cycles_ago` já é parte do contrato de `POST /api/groups/{id}/settlements/confirm`: o backend o valida (`ExpenseController.php:1039`) e tem teste dedicado (`SettlementConfirmationControllerTest.php:266`). O cliente só passa a usá-lo.
- [ ] **Causa raiz obscura / correção ampla** — causa confirmada e reproduzida (§1), em 1 arquivo de produção e 1 linha (`Payments.tsx`). Nenhum outro chamador do endpoint (`git grep 'settlements/confirm'` em `frontend/src` só acha `Payments.tsx` e o teste dele).
- [ ] **Decisão de produto/arquitetura** — o comportamento correto já está decidido: `docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/specify.md` §2.2 manda aceitar o acerto com a competência fechada e diz que `confirmSettlement` "também aceita `cycles_ago`". Falta o cliente mandar.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** o devedor abre Pagamentos num ciclo anterior já fechado (ex.: 01–30 de setembro, com o selo "Ciclo fechado"), clica em "Enviar comprovante", anexa o comprovante do Pix e confirma. O diálogo mostra o erro "O acerto só pode ser confirmado depois que a competência é fechada." e o comprovante não é gravado. Visto em produção em 2026-10-06 (capturas do usuário: ciclo 01–30 Set., "Ciclo fechado", R$ 9.333,02 a pagar).
- **Reprodução:**
  1. Backend de teste com um grupo de dois membros e uma despesa de setembro/2026 não paga, em que o credor pagou e os dois participam (o acerto do devedor é R$ 100,00).
  2. Entrar como o devedor numa data em que setembro já fechou por data (o teste rodou em 06/10/2026) e abrir `/app/groups/1/payments`. A tela abre em 01–30 Set., "Ciclo fechado", despesa "Pendente", com "Pagar com Pix" e "Enviar comprovante".
  3. "Enviar comprovante" → anexar uma imagem → "Confirmar".
  4. O `POST /api/groups/1/settlements/confirm` responde `422` e o diálogo mostra a mensagem acima.
  - Reproduzido em 2026-10-06 num navegador real contra backend local; o mesmo `422` sai de um `curl` sem `cycles_ago`.
- **Esperado vs. atual:** esperado, o comprovante do ciclo de setembro ser aceito (`200`), o acerto ficar "Comprovante enviado" e a despesa seguir "Pendente" até o credor marcá-la. Atual, `422`.
- **O relato original não é a regra:** o pedido dizia que o pagamento ao credor "só libera após o pagamento de todas as despesas". Não é isso: os acertos (`settlements`) são calculados com todas as despesas do ciclo, pagas ou não (`ExpenseController.php:1203-1236`, comentário "NÃO filtrar por `$entry['paid']`"), `confirmSettlement` só exige ciclo fechado e não selado (`:1048-1057`) e `canConfirm` da tela idem (`Payments.tsx:313-316`). O que o usuário viu foi a mensagem do `422` abaixo, que fala em competência fechada, não em despesas pagas.
- **Causa raiz:**
  - `frontend/src/pages/Payments.tsx:154-178` (em `dev`), `confirmSettlementPayment`: o `FormData` leva só `to_user_id` e `comprovante` (`:161-163`). `cyclesAgo` já existe na tela (`:48`, vindo de `useGroupCycle`) e é repassado a `usePaymentActions` para pagar/desfazer despesa (`usePaymentActions.ts:69-76`), mas este envio não o usa.
  - `backend/app/Http/Controllers/ExpenseController.php:1042`: sem `cycles_ago` o backend assume `0`, o ciclo **corrente** (outubro, `open`), e `:1048-1053` recusa porque ele não está fechado. O ciclo que a tela mostra (setembro) nunca chega ao backend.
  - **Por que os testes não pegam:** `Payments.test.tsx` conferia só `to_user_id` e `comprovante`, e o ciclo do teste era o corrente. Mesma classe do bug 068 (cadastro): o teste não fixava o contrato com o backend.
  - **Efeito prático:** de ciclo anterior o devedor nunca conseguia acertar pela tela; só do mês corrente e depois de alguém fechar a competência manualmente.
  - **Fora do escopo, conferido:** "Pagar com Pix" (`handleSelectSettlement`, `Payments.tsx:118-132`) só abre o QR do credor e não chama o endpoint de confirmação, então não depende de ciclo.

## 2. Correção

- **O que muda e por quê:** `confirmSettlementPayment` passa a acrescentar `cycles_ago` (o `cyclesAgo` da tela) ao `FormData`, como `handlePay`/`handleUnpay` já fazem. O backend então avalia o ciclo que o usuário está vendo.
- **Arquivos tocados:** `frontend/src/pages/Payments.tsx` (1 linha mais comentário), `frontend/src/pages/Payments.test.tsx` (1 teste novo, 1 asserção a mais no teste existente e um parâmetro opcional no helper `mockGetResponses`), e esta documentação (`docs/bugfix/`).
- **Teste de regressão:** `Payments.test.tsx`, "sends the cycles_ago of the cycle on screen when the debtor confirms a past closed cycle": o mock do `focus-cycle` abre a tela no ciclo 1 (setembro, fechado) e o teste exige `cycles_ago === '1'` no `FormData`. O teste existente do ciclo corrente passa a exigir `'0'`. Falham sem a correção (`expected null to be '0'` e `'1'`); mandar um valor fixo `'0'` derruba só o teste do ciclo anterior.
- **Riscos / efeitos colaterais:** nenhum identificado. O parâmetro é opcional e validado no backend (`nullable|integer|min:0`); no ciclo corrente o valor enviado é `0`, o mesmo default de antes. O caminho de sucesso não muda.
- **Não faz parte deste bug:** nos 5 dias de carência depois da virada do mês o ciclo segue `open` e o acerto fica indisponível (`BillingCycle::GRACE_DAYS`, feature `pagamento-ciclo-fechado` §2.9). Liberar antes é mudança de regra, não correção: backlog se o usuário quiser.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-10-06 | `curl -X POST .../api/groups/1/settlements/confirm` como devedor, com um PNG real e **sem** `cycles_ago` (backend de teste em MySQL descartável na 3307, grupo semeado com despesa de setembro/2026) | `HTTP 422` `{"error":"O acerto só pode ser confirmado depois que a competência é fechada."}`. Causa raiz confirmada no backend |
| 2026-10-06 | Reprodução no navegador (painel do Claude), `Payments.tsx` na versão de `dev`: login como devedor, `/app/groups/1/payments` (abre em 01–30 Set., "Ciclo fechado"), "Enviar comprovante", imagem anexada, "Confirmar" | `POST /api/groups/1/settlements/confirm → 422`; o diálogo mostra "O acerto só pode ser confirmado depois que a competência é fechada.", igual à captura de produção |
| 2026-10-06 | **RED** — `cd frontend && npx vitest run src/pages/Payments.test.tsx`, testes novos e `Payments.tsx` ainda sem a correção | `2 failed, 6 passed`. Falham os dois que olham `cycles_ago`, com `AssertionError: expected null to be '0'` e `expected null to be '1'` (o campo não existe no `FormData`) |
| 2026-10-06 | **GREEN** — o mesmo comando, depois de `form.append('cycles_ago', String(cyclesAgo))` | `8 passed (8)` |
| 2026-10-06 | Mutação: trocar `String(cyclesAgo)` por `'0'` fixo e rodar `Payments.test.tsx` | `1 failed, 7 passed`: cai só o teste do ciclo anterior (o do ciclo corrente seguia passando). Arquivo restaurado a partir de cópia |
| 2026-10-06 | `cd frontend && npx tsc --noEmit` | Exit 0, sem erros |
| 2026-10-06 | Verificação no navegador com a correção: mesmo cenário, página recarregada, "Enviar comprovante" → imagem → "Confirmar" | `POST /api/groups/1/settlements/confirm → 200 OK`; toast "Comprovante enviado."; o acerto mostra o chip "Comprovante enviado" e o botão "Reenviar comprovante"; a despesa **segue "Pendente"**. Comprova que o devedor acerta com o credor antes de a despesa ser marcada como paga |
| 2026-10-06 | `cd frontend && npx vitest run` (suíte completa, 1ª tentativa, 4 workers), com ~650 MB de RAM livre de 8 GB na máquina | Dezenas de testes de arquivos que este bugfix não toca (Login, Register, ExpenseForm, AcceptInvite) estouraram o timeout em ~15 s. Interrompida e **descartada**: atribuída à pressão de memória, não a regressão (a rodada seguinte, com o mesmo código, passou inteira) |
| 2026-10-06 | `git rebase origin/dev` (branch ainda não publicado; `dev` avançou com o #205) | Sem conflitos; `Payments*` e o `README` do BFF não foram tocados pelo #205 |
| 2026-10-06 | `cd frontend && npx vitest run --maxWorkers=2` (suíte completa sobre o branch rebaseado em `dev` `715b884a38`) | `Test Files 45 passed (45)`, `Tests 335 passed (335)`, exit 0 |
| 2026-10-06 | Desmontagem do ambiente de teste | Parados backend (8001) e frontend (3001); `backend/.env.testing` removido; container `mysql-expense-test` removido; `git status` só com os arquivos do bugfix e os dois itens não rastreados de outra frente |
