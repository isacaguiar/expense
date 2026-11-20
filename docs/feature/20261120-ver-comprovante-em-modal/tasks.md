# Tasks — Ver o comprovante em um modal

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261120

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-653 | Criar o componente `ProofViewerDialog` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-654 | Criar o gancho `useProofViewer` com o gatilho de link progressivo | frontend | plan.md §2 | nenhum | Concluída |
| TASK-655 | Abrir o comprovante em modal em `PayableSettlementList` (chip e "recusado") | frontend | plan.md §3 e §4 | nenhum | Concluída |
| TASK-656 | Abrir o comprovante em modal em `ReceivableList` | frontend | plan.md §3 e §4 | nenhum | Concluída |
| TASK-657 | Abrir o comprovante em modal em `SettlementProofHistory` | frontend | plan.md §3 e §4 | nenhum | Concluída |
| TASK-658 | Abrir o comprovante em modal em `ExpenseManager` (ação da lista e detalhe) | frontend | plan.md §3 | nenhum | Concluída |
| TASK-659 | Abrir o comprovante em modal em `ExpenseView` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-660 | Abrir o comprovante em modal em `Payments` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-661 | Medir o modal em 5 larguras e com URL vencida na stack de validação | frontend | plan.md §5 | nenhum | Concluída |
| TASK-662 | Documentar o padrão no `05-context-frontend.md` e no `01-specify.md` | doc | plan.md §6 | nenhum | Pendente |

Ordem: 653, 654, depois 655 a 660 (independentes entre si, nessa ordem de uso), 661 e 662. A 654 só depois da 653; as telas só depois da 654.

## Critérios de aceite

- **TASK-653**: `ProofViewerDialog` (`open`, `url`, `title?`, `onClose`) renderiza `role="dialog"` com `<img>` de `src` igual à URL e `alt="Comprovante de pagamento"`; mostra o carregamento até o `onLoad`; no `onError` troca a imagem por "Não foi possível carregar o comprovante. Atualize a página."; tem o botão "Fechar" e o link "Abrir em nova aba" (`href` da URL, `target="_blank"`); `Esc` e "Fechar" chamam `onClose`; reabrir ou trocar a URL volta ao estado de carregamento; testes novos em `ProofViewerDialog.test.tsx`; mutante (tirar o ramo de erro) derruba o teste; `tsc --noEmit` limpo e `vitest` do arquivo verde.
- **TASK-654**: `useProofViewer` devolve `proofLinkProps(url)` (`href`, `onClick`, `aria-haspopup="dialog"`) e `proofViewer`; clique simples chama `preventDefault` e abre o diálogo com a URL; clique com `ctrl`, `meta`, `shift` ou `alt`, ou com botão que não seja o principal, não abre e não chama `preventDefault`; fechar leva `open` a `false`; testes novos em `useProofViewer.test.tsx`; mutante (ignorar os modificadores) derruba o teste.
- **TASK-655**: o chip "Comprovante enviado" e o link "Ver comprovante recusado" mantêm o `href` e o papel `link`, deixam de ter `target="_blank"` e, ao clicar, abrem o diálogo com a imagem da URL; o chip sem URL continua sem link; `PayableSettlementList.refusedProof.test.tsx:51` passa a afirmar o diálogo em vez de `target`; testes novos de abrir, `ctrl+clique` não abrir e foco voltando ao gatilho ao fechar; `.test.tsx`, `.decision.test.tsx` e `GroupPositionCard.test.tsx` passam sem edição; mutante (voltar ao `target="_blank"`) derruba o teste novo.
- **TASK-656**: o chip "Comprovante enviado" do credor segue com `href`, perde o `target`, abre o diálogo ao clicar; `ReceivableList.test.tsx:30` passa a afirmar o diálogo; sem URL segue sem link; mutante derruba o teste.
- **TASK-657**: "Ver comprovante" do histórico segue com `href`, perde o `target`, abre o diálogo; só aparece quando o arquivo existe (como hoje); `SettlementProofHistory.test.tsx:100` passa a afirmar o diálogo; mutante derruba o teste.
- **TASK-658**: o botão "Ver comprovante" da lista e o link do detalhe da despesa abrem o diálogo com o `paymentProofUrl`; só aparecem com `paid` e URL, como hoje; teste novo para cada um; mutante derruba os testes.
- **TASK-659**: o link "Ver comprovante" de `ExpenseView` abre o diálogo; só aparece com `proofUrl`; teste novo; mutante derruba o teste.
- **TASK-660**: o link "Ver comprovante" de `Payments` abre o diálogo com o `paymentProofUrl`; só aparece com `paid` e URL; teste novo; mutante derruba o teste.
- **TASK-661**: com a stack de validação (Vite 3001, backend 8001, banco 3307) e uma despesa com comprovante, o modal é medido em 375, 768, 1024, 1280 e 1440 px: sem rolagem horizontal (`scrollWidth <= clientWidth`), imagem inteira visível, `fullScreen` em 375; com URL vencida (assinatura alterada) aparece a mensagem de erro; resultado numérico registrado no `implementation.md`.
- **TASK-662**: `05-context-frontend.md` cita `ProofViewerDialog` e `useProofViewer` como padrão para exibir comprovante; `01-specify.md` descreve o comportamento (modal, "Abrir em nova aba", erro para URL vencida) e a versão sobe; links relativos conferidos.

## Fechamento (skill `promover-backlog`, passos 5.7 e 6)

Depois das tasks: suíte cheia do front (`vitest` com 1 worker, sem outra sessão rodando), `tsc --noEmit`, `pr-readiness-checker` no HEAD final, **validação local dele** (clicar no comprovante nas telas e ver o modal, `Esc`, "Abrir em nova aba", celular), `Resolução` no item 158 e movê-lo para `concluidos/`, um único PR contra `dev` (merge é dele). Não há gate de migration nem de contrato: nenhuma rota muda.
