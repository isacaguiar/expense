# Implementation — Usabilidade Mobile das Telas Internas

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260901

---

## 1. Desvios do fluxo padrão (se houver)

- **Branch topologia.** Todas as tasks (TASK-236..242) foram implementadas em commits diretos
  na branch da feature `frontend/20260901-usabilidade-mobile` — um commit por task — em vez de
  sub-branches `...-TASK-0xx` com merge `--no-ff` (ADR-003 §1.4). Motivo: o usuário pediu
  execução contínua das 8 tasks num único passo, sem check-in por task; as sub-branches só
  serviriam para um boundary de review que foi explicitamente dispensado. Histórico continua
  rastreável (um commit `TASK-0xx:` por task).
- **Branch base.** A feature nasceu de `fix/20260901-frontend-meta-viewport-mobile` (PR #126,
  ainda aberto), não de `dev` — porque a verificação em viewport mobile precisa da `<meta
  viewport>` daquele bugfix presente. Quando #126 mesclar em `dev`, o PR desta feita mostra
  só os commits próprios.
- **TASK-243 (verificação ao vivo).** Não concluída neste passo — exige backend no ar +
  login real (o executor não digita senha). Ver §2.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-236 | Concluída | 2026-09-02 | IA (Claude) | `vitest run GroupHeader.test.tsx` 5/5 · `tsc --noEmit` sem erro | `GroupHeader.tsx`: cluster direito com `flexWrap`+`rowGap`; nome do usuário `display:{xs:'none',sm:'block'}`; `<Select>` `minWidth:{xs:132,sm:180}`/`maxWidth:{xs:200}`. +4 casos de teste. |
| TASK-237 | Concluída | 2026-09-02 | IA (Claude) | `vitest run SimpleShellLayout.test.tsx GroupShellLayout.test.tsx` 14/14 · `tsc` sem erro | `<Container>` dos dois shells: `mt/mb:{xs:2,md:4}`. |
| TASK-238 | Concluída | 2026-09-02 | IA (Claude) | `vitest run ExpenseManager.test.tsx` 35/35 · `tsc` sem erro | `ExpenseManager.tsx` cabeçalho: `flexWrap:'wrap'` + `rowGap`. |
| TASK-239 | Concluída | 2026-09-02 | IA (Claude) | `vitest run ExpenseManager.test.tsx Payments.test.tsx` 42/42 · `tsc` sem erro | Rótulo do seletor de competência (`ExpenseManager` + `Payments`): `flexGrow:1`, `minWidth:0`, `textAlign:center`, `fontSize:{xs:'1rem',md:'1.25rem'}`. |
| TASK-240 | Concluída | 2026-09-02 | IA (Claude) | `npm test` 178/178 (2ª rodada; 1ª teve 1 flake conhecido em `AcceptInvitePage.test.tsx` sob carga, sem relação — ver `vite.config.js`) | `setupTests.ts`: polyfill de `window.matchMedia` (default `matches:false`; teste sobrescreve via `vi.stubGlobal`). |
| TASK-241 | Concluída | 2026-09-02 | IA (Claude) | `vitest run Dashboard.test.tsx` 19/19 · `tsc` sem erro | `Dashboard.tsx`: `useMediaQuery(down('sm'))` → `<Card>` por grupo; helpers `renderExpandToggle`/`renderGroupActions`/`renderMembers`/`groupNameLink` compartilhados com a tabela. 16 casos atuais (ramo tabela) verdes + 3 novos (ramo cartão). `<TableContainer>` com `overflowX:'auto'`. |
| TASK-242 | Concluída | 2026-09-02 | IA (Claude) | `vitest run ExpenseManager.test.tsx` 37/37 · `npm test` 183/183 · `tsc` sem erro | `ExpenseManager.tsx`: `useMediaQuery(down('sm'))` → `<Card>` por despesa; helpers `renderTypeIcon`/`renderExpenseActions`/`formatValue` compartilhados com a tabela. 35 casos atuais (já agnósticos de layout) verdes + 2 novos (ramo cartão). `<TableContainer>` com `overflowX:'auto'`. |
| TASK-243 | **Pendente — aguarda sessão** | 2026-09-02 | — | Regressão do login em 375×812: OK (layout intacto, sem scroll horizontal). Backend responde (401 sem token) → está no ar. | Verificação ao vivo em 375px de `Dashboard` (cartões), `GroupHeader` (`/groups/:id/*` sem scroll horizontal), seletor de competência, e das telas de F6 (`ExpenseView`, `ExpenseForm`, `GroupMembersForm`, `PixPaymentDialog`, diálogos) exige login real. Handoff para o usuário. Abrir `TASK-244+` para o que a passada revelar. |

### Verificações de nível de feature (04-implementation §1.5)

| Comando | Resultado |
|---|---|
| `cd frontend && npx tsc --noEmit` | sem erros |
| `cd frontend && npm test` | 27 arquivos, 183 testes — verde |
| `cd frontend && npm run build` | (rodar antes do PR) |
| Browser 375×812 — tela de login | sem regressão: layout mobile intacto, sem scroll horizontal, sem erro de console novo |
| Browser 375×812 — telas internas autenticadas | **pendente (TASK-243)** — precisa de backend + login real |
