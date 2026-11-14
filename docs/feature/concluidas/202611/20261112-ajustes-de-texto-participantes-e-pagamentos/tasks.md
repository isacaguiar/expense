# Tasks — Ajustes de texto em Participantes e Pagamentos

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261112

**Status: concluída. Aprovada em 2026-11-12 ("aprovo"); TASK-610 a 613 concluídas e entregues no PR #277, mergeado por ele em `dev` em 2026-11-14 (merge `2fe4bac4c9`, CI verde); pasta arquivada em 2026-11-13 (ADR-009).** Specify v1.1 (aprovado em 2026-11-12, D1 a D4 e D6 dele, D5-A) e plan v1.1 (aprovado em 2026-11-12) foram commitados na branch `feature/20261112-ajustes-de-texto-participantes-e-pagamentos`.

IDs a partir de **TASK-610**: o maior em uso era TASK-609 (feature `20261110-quem-paga-se-declara-e-credor-recusa`), conferido em 2026-11-12 em `docs/sdd/03-tasks.md`, em todos os `docs/feature/**/tasks.md` e em `git log --all`.

Escopo: só `frontend/` e documentação. Esta feature **não** nasce de um item de backlog (pedido direto dele, ao responder as decisões abertas da feature anterior), então não há item a marcar como "Promovido".

**Ordem (plan §6).** Participantes primeiro (610), depois o arranjo novo da linha, ainda sem ligar na tela (611), a ligação com a migração dos testes e a medição (612) e a documentação por último (613). A 612 depende da 611; as demais são independentes entre si.

**Regras de execução.** **Parar após cada task e perguntar ao usuário se segue.** Cada task é teste primeiro (teste que falha pelo motivo certo, implementação, teste verde, commit local direto na branch da feature, sem sub-branch). **Testes antigos só são editados onde o texto mudou de propósito** (plan, restrições globais): os 41 pontos em 4 arquivos da TASK-612 e o teste de selos de `GroupMembersForm.responsible.test.tsx:146-159` da TASK-610; todo teste novo entra em arquivo novo. Arquivos do frontend são CRLF: editar com a ferramenta de edição, **não** com `sed -i`. Suíte inteira do front só no fim e **sozinha** (nenhuma outra suíte `vitest` rodando, RAM livre conferida). Antes do push, a validação local dele e a autorização; push só com refspec explícito; um PR único contra `dev`; merge, promoção e tag são dele.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-610 | Tirar os selos "Paga por X" da linha própria em Participantes | frontend | plan.md §1 | nenhum | Concluída |
| TASK-611 | Criar o arranjo `'route'` da linha de acerto em `PayableSettlementList`, com o helper de teste e as âncoras | frontend | plan.md §2 | nenhum | Concluída |
| TASK-612 | Ligar o arranjo `'route'` no painel de abas de Pagamentos e migrar os testes que leem a frase | frontend | plan.md §3 | nenhum | Concluída |
| TASK-613 | Documentar as duas telas em `docs/sdd/01-specify.md` v1.5 | doc | plan.md §4 | nenhum | Concluída |

## Critérios de aceite

- **TASK-610**:
  - Em `GroupMembersForm.tsx`, o selo "Paga por {nome}" só é desenhado quando `user.id !== currentUserId`; o texto `Você paga por: A, B` não muda. Na linha do usuário logado com dependentes: o texto aparece e **nenhum** elemento "Paga por …" (`/^Paga por/`) existe dentro da linha. Na linha de outro membro com dependentes: um selo por dependente, como hoje. Com a logada e outro membro pagando por alguém na **mesma tela**, cada linha segue a sua regra.
  - Em arquivo novo `GroupMembersForm.badges.test.tsx`, os três casos acima. Em `GroupMembersForm.responsible.test.tsx` muda **só** o teste de `:146-159` (hoje espera os selos na linha da Ana, a logada): passa a esperar que a linha própria não tem selo e a mostrar `Você paga por`, mantendo as asserções das linhas dos outros (`:157-158`). `GroupMembersForm.paysFor.test.tsx` (inclusive `:161`) segue verde **sem edição**.
  - **Layout medido no navegador em 375, 768, 1024, 1280 e 1440 px**, com a linha própria no conteúdo máximo (dois "Quem paga por você", dois "Você paga por", os três botões): sem rolagem horizontal, botões dentro do card, e-mail legível. Na TASK-607 o avatar ficava sozinho numa linha em 1024 e 375 px por causa dos selos; **conferir e registrar** se isso some. Os números vão ao `implementation.md`.
  - `npx tsc --noEmit` limpo; `vitest related` dos arquivos tocados; **mutation check** (R1): tirar a condição `user.id !== currentUserId`, invertê-la (selos só na linha própria) e tirar o `Você paga por`; todos os mutantes morrem.

- **TASK-611**:
  - `PayableSettlementList` aceita `rowLayout?: 'sentence' | 'route'`, **padrão `'sentence'`**; sem a propriedade (ou com `'sentence'`) o topo do cartão é idêntico a hoje, e `PayableSettlementList.test.tsx`, `.decision.test.tsx`, `.responsible.test.tsx`, os testes do `GroupPositionCard` e do `MyPanel` seguem verdes **sem edição**.
  - Com `'route'`, cada cartão tem: os dois avatares nas pontas; na primeira linha, o pagador, **uma** seta (`ArrowForwardIcon`) e o credor, com a seta **junto do pagador** e nenhuma seta solta antes do avatar do credor; o valor "R$ N" num elemento **abaixo** dessa linha, em negrito e vermelho (`error.main`); nenhuma das palavras "deve pagar" e nenhum "a" entre o valor e o credor; `data-testid` `settlement-card`, `settlement-payer`, `settlement-payee` e `settlement-amount` (só no `'route'`).
  - Nota, selos e botões aparecem **iguais** nos dois arranjos para cada papel × estado do plan §3 (dataset novo, em arquivo novo `PayableSettlementList.route.test.tsx`).
  - `settlementLines(root?: HTMLElement): string[]` em `frontend/src/components/settlementLines.testHelper.ts` devolve, na ordem do DOM, `"Pagador → Credor R$ 0,00"` por `settlement-card`, com teste próprio (a ordem e o formato). O Painel tem um teste de caracterização novo (`GroupPositionCard.rowLayout.test.tsx`) que fixa que ele **continua** mostrando "deve pagar … a".
  - `tsc` limpo; `vitest related`; **mutation check** (R2): voltar "deve pagar" no `'route'`, deixar a seta solta além da seta entre os nomes, pôr o valor antes dos nomes, padrão `'route'` no lugar de `'sentence'`, trocar a ordem no helper, tirar o `data-testid` do valor; todos os mutantes morrem. Ainda **não** liga na tela (a 612 liga).

- **TASK-612**:
  - `PaymentSettlementsPanel` passa `rowLayout="route"` para as **duas** abas; `Payments.tsx` e o Painel não mudam.
  - Os 41 pontos de teste em `PaymentSettlementsPanel.test.tsx` (13), `PaymentSettlementsPanel.responsible.test.tsx` (7), `Payments.test.tsx` (15) e `Payments.responsible.test.tsx` (6) leem por `settlementLines()` e esperam `"Pagador → Credor R$ N"` em vez de `"Pagador deve pagar R$ N a Credor"`; **nada mais** nesses arquivos muda, e não resta "deve pagar" (a frase) neles. As asserções do selo "Deve pagar a você" seguem valendo sem edição.
  - Em arquivo novo `PaymentSettlementsPanel.rowLayout.test.tsx`: as duas abas usam o arranjo novo (sem "deve pagar" no `tabpanel`); o comprovante aguardando aceite continua na aba "Valores pagos" (D2); o selo "Deve pagar a você" e os demais seguem (R4); a ordem das linhas continua verificada.
  - **Layout medido no navegador em 375, 768, 1024, 1280 e 1440 px**, nas duas abas, com nomes de 25 a 40 caracteres: sem rolagem horizontal; os dois avatares nas pontas; valor abaixo do bloco dos nomes e, quando o pagador não quebra, com a mesma borda esquerda dele (`getBoundingClientRect`); a seta nunca começa uma linha nem fica sozinha. Os números vão ao `implementation.md`.
  - `tsc` limpo; `vitest related` dos arquivos tocados mais `PayableSettlementList*`, `GroupPositionCard` e `MyPanel`; **mutation check**: o painel sem `rowLayout="route"`, a ordem trocada no helper e o valor sem o `data-testid`; todos os mutantes morrem.

- **TASK-613**:
  - `docs/sdd/01-specify.md` v1.5: em §3.3, no bloco "Quem paga por quem", o selo `Paga por {dependente}` passa a existir **só nas linhas dos outros membros** (na própria linha só o texto `Você paga por`); no bloco "Quem paga se declara e o credor decide", em "Telas", a linha de acerto de Pagamentos (`pagador → credor` com o valor abaixo, nas duas abas, o Painel como antes) e o "Limite conhecido" do comprovante aguardando aceite vira decisão ("fica em 'Valores pagos'").
  - `git diff --stat` não lista `00-constitution.md`; cada afirmação conferida no código; fim de linha do arquivo sem mistura (CRLF).

## Fechamento da feature (depois da TASK-613)

Suíte inteira do back não se aplica (só frontend); do front: `npx tsc --noEmit` e `npx vitest run --maxWorkers=1` **sozinha**, conferindo que rodaram todos os arquivos (hoje 92 + os novos); `pr-readiness-checker`; **validação local dele** (Participantes e Pagamentos, app aberta e logada no Chrome dele) e a autorização do push; push com refspec; PR único contra `dev`; depois do merge, arquivar a pasta (ADR-009) e apagar a branch local.
