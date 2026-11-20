# Implementation — Ver o comprovante em um modal

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261120

---

## 1. Desvios do fluxo padrão

Nenhum. Feature só de frontend, sem migration nem mudança de contrato.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-653 | Concluída | 2026-11-20 | Claude | RED: `npx vitest run src/components/ProofViewerDialog.test.tsx` sem o componente (falha de import). GREEN: 8 testes passam. `npx tsc --noEmit` limpo. Mutantes mortos: sem o ramo de erro (2 falham), sem o `key` da URL (1 falha), sem o `target="_blank"` do "Abrir em nova aba" (1 falha). | Imagem com `opacity` 0 até o `onLoad` (continua acessível ao leitor de tela e aos testes); `ref` cobre imagem já em cache. Tela cheia abaixo de `sm` vem do `useMediaQuery`, medida só na TASK-661. |
| TASK-654 | Concluída | 2026-11-20 | Claude | RED: `npx vitest run src/hooks/useProofViewer.test.tsx` sem o módulo (falha de import). GREEN: 9 testes passam. `npx tsc --noEmit` limpo. Mutantes mortos: sem os modificadores (4 falham), sem a checagem do botão (1 falha), sem `preventDefault` (1 falha). | `proofLinkProps` devolve `href`, `onClick` e `aria-haspopup="dialog"`; sem `target`. A URL fica no estado ao fechar para a imagem não sumir durante a animação de saída. O foco volta ao link (testado). |
| TASK-655 | Concluída | 2026-11-20 | Claude | RED: `npx vitest run src/components/PayableSettlementList.proofModal.test.tsx` com 3 de 4 falhando (o de `ctrl+clique` passa antes por não haver modal). GREEN: `npx vitest run src/components/PayableSettlementList src/components/GroupPositionCard` = 9 arquivos, 130 testes passam; `npx tsc --noEmit` limpo. Mutantes mortos: chip de volta a `target="_blank"` (2 falham), "Ver comprovante recusado" de volta a `target="_blank"` (2 falham), sem `{proofViewer}` (3 falham). | Único teste antigo editado: `PayableSettlementList.refusedProof.test.tsx` (asserções de `target`/`rel` viraram `not.toHaveAttribute('target')`), como declarado no plan §4. `PayableSettlementList.test.tsx`, `.decision.test.tsx` e `GroupPositionCard.test.tsx` passaram sem edição. O gancho fica antes do retorno da lista vazia. |
| TASK-656 | Concluída | 2026-11-20 | Claude | RED: `npx vitest run src/components/ReceivableList.test.tsx` com 2 falhando (a asserção antiga de `target` editada e o teste novo do diálogo). GREEN: `npx vitest run src/components/ReceivableList src/components/GroupPositionCard` = 3 arquivos, 32 testes passam; `npx tsc --noEmit` limpo. Mutantes mortos: chip de volta a `target="_blank"` (2 falham), sem `{proofViewer}` (1 falha). | Único teste antigo editado: `ReceivableList.test.tsx` (asserções de `target`/`rel` viraram `not.toHaveAttribute('target')`), como declarado no plan §4. O teste novo cobre `ctrl+clique` sem abrir, clique abrindo com a URL certa e foco voltando ao chip. O ramo sem URL segue sem link (teste existente). |
