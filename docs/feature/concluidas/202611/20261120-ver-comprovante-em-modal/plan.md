# Plan — Ver o comprovante em um modal

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui. Só frontend: nenhum endpoint, rota ou migration muda.

Versão: 1.0 · Criado em: 20261120

Decisões do specify já aprovadas por ele em 2026-11-20 (recomendações): **D1-B** (os 8 pontos de link, em tasks por arquivo), **D2-A** ("Abrir em nova aba" no modal), **D3-A** (só `object-fit: contain`), **D4-A** (erro com instrução, sem renovar URL), **D5-A** (o "Ver foto atual" do `PaymentProofDialog` fica em nova aba).

---

## 1. Componente `ProofViewerDialog` (specify §2.1)

- Novo `frontend/src/components/ProofViewerDialog.tsx`, no padrão de `RefuseReasonDialog`: `Dialog` MUI, props `{ open: boolean; url: string | null; title?: string; onClose: () => void }`.
- Conteúdo: `<img src={url} alt="Comprovante de pagamento">` com `maxWidth: '100%'`, `maxHeight: calc(100dvh - 180px)` e `objectFit: 'contain'` (nunca rolagem horizontal). `fullScreen` abaixo de `sm` por `useMediaQuery(theme.breakpoints.down('sm'))`; acima, `maxWidth="md"` e `fullWidth`.
- Estados: **carregando** (`CircularProgress` até o `onLoad`) e **erro** (`onError` troca a imagem por `Alert` "Não foi possível carregar o comprovante. Atualize a página."). Os dois voltam ao início a cada abertura e a cada URL nova (`useEffect` em `[open, url]`), como o `RefuseReasonDialog` zera o motivo.
- Ações: **"Abrir em nova aba"** (`Button` `href={url}` `target="_blank"` `rel="noreferrer"`, D2-A) e **"Fechar"**. `Esc` e clique fora fecham (padrão do `Dialog`). O foco volta ao gatilho porque o `Dialog` do MUI restaura o foco ao elemento que o abriu; não se escreve código para isso, só se testa.
- Por que `<img>` e não `<iframe>`/`<object>`: o comprovante é sempre imagem (upload só aceita JPG/PNG/GIF/WEBP desde a `v1.8.0`); dentro de `<img>` um SVG antigo não executa script, e o CSP `sandbox` da rota só vale para documento aberto direto, não para `<img>` (conferido na validação da foto de perfil, `v1.8.1`).

## 2. Gancho `useProofViewer` e gatilho com link progressivo (specify §2.1 e §2.2)

- Novo `frontend/src/hooks/useProofViewer.tsx`: devolve `{ proofLinkProps, proofViewer }`.
  - `proofLinkProps(url)` devolve `{ href: url, onClick, 'aria-haspopup': 'dialog' }`. O `onClick` faz `preventDefault` e abre o modal **só em clique simples** (botão principal, sem `ctrl`/`meta`/`shift`/`alt`); com modificador, deixa o navegador abrir em outra aba, como o link de hoje.
  - `proofViewer` é o `<ProofViewerDialog>` pronto para ser renderizado **uma vez** por tela/lista (um estado `{ url, open }` dentro do gancho).
- Por que manter `href` em vez de trocar por `button`: preserva o papel `link` (leitor de tela e testes existentes), o botão do meio e o `ctrl+clique` continuam funcionando, e sem JavaScript o link ainda abre o arquivo. `target="_blank"` e `rel` saem dos gatilhos (o `onClick` assume o clique simples; o "Abrir em nova aba" fica dentro do modal).
- O gancho é a peça compartilhada que cada tela usa; assim não há 8 cópias do estado.

## 3. Aplicação por tela (specify §2.2, D1-B)

Um passo por arquivo, cada um com `useProofViewer()` e `{proofViewer}` renderizado uma vez:

- **PayableSettlementList** (`components/PayableSettlementList.tsx`): chip "Comprovante enviado" (`:269`, trocar `target`/`rel` por `proofLinkProps(settlement.confirmedProofUrl)`) e link "Ver comprovante recusado" (`:371`). O ramo sem URL do chip não muda.
- **ReceivableList** (`components/ReceivableList.tsx`): chip "Comprovante enviado" (`:57`), no objeto de props condicional já existente.
- **SettlementProofHistory** (`components/SettlementProofHistory.tsx:88`): "Ver comprovante" do histórico.
- **ExpenseManager** (`pages/ExpenseManager.tsx`): `IconButton` "Ver comprovante" (`:404`) e link do detalhe (`:801`); um único gancho na página.
- **ExpenseView** (`pages/ExpenseView.tsx:561`) e **Payments** (`pages/Payments.tsx:358`): um link cada.
- **Fica como está (D5-A):** "Ver foto atual" em `PaymentProofDialog.tsx:181`, em nova aba, para não abrir modal sobre modal.

## 4. Testes existentes que mudam (declarado de antemão)

Os testes antigos afirmam `target="_blank"` e `href` destes links. Como o `href` continua, só as asserções de `target` mudam e passam a afirmar que o clique abre o diálogo:

- `PayableSettlementList.refusedProof.test.tsx:51`, `ReceivableList.test.tsx:30`, `SettlementProofHistory.test.tsx:100` (afirmam `target`).
- Os que só leem `href` seguem como estão: `PayableSettlementList.test.tsx:113`, `.decision.test.tsx:82`, `GroupPositionCard.test.tsx:262`.
- `PaymentProofDialog.test.tsx:115` (`target`) **não muda** (D5-A).
- Cada tela ganha um teste: clicar abre `role="dialog"` com a imagem da URL; `ctrl+clique` não abre; fechar devolve o foco ao gatilho. Mutante por tela: voltar ao `target="_blank"` derruba o teste.

## 5. Medição de layout (specify §2.3)

- Com a stack de validação (Vite 3001 + backend 8001, banco 3307), medir o modal em **375, 768, 1024, 1280 e 1440 px** (sem rolagem horizontal, imagem inteira visível, `fullScreen` em 375) e com uma URL vencida (erro). Registrar no `implementation.md`.

## 6. Documentação (SDD)

- `docs/sdd/05-context-frontend.md` ganha uma linha sobre `ProofViewerDialog`/`useProofViewer` como padrão para exibir comprovante; `01-specify.md` ganha uma frase de comportamento (versão sobe). Sem ADR: não é decisão de stack nem de arquitetura.

## 7. Ordem de execução

1. Componente e gancho (§1 e §2) primeiro: as telas dependem deles.
2. As telas (§3) são independentes entre si; ordem por uso: chips de acerto (PayableSettlementList, ReceivableList), histórico, depois Despesas, Visualização e Pagamentos.
3. Medição (§5), documentação (§6), `pr-readiness-checker`, validação local dele, PR único contra `dev`.
