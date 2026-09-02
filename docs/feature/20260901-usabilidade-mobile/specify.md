# Specify — Usabilidade Mobile das Telas Internas

> Feature: deixar as telas internas do `frontend/` (React web) confortáveis de usar em smartphone — toque, largura, sem scroll horizontal — sem mexer na linguagem visual. Pedido novo do usuário (acesso pelo celular), sem épico correspondente em `docs/sdd/03-tasks.md`. Nasce da auditoria feita logo após o bugfix `docs/bugfix/concluidos/20260901-frontend-meta-viewport-mobile.md` (PR #126), que adicionou a `<meta name="viewport">` ausente e fez os breakpoints do MUI voltarem a valer no celular.

Versão: 1.0 · Criado em: 20260901

---

## 1. Problema

O `frontend/` nunca teve a `<meta name="viewport">` — no celular tudo renderizava como página desktop encolhida. O bugfix `20260901-frontend-meta-viewport-mobile` (PR #126) corrigiu isso; com ele, o shell de navegação (`Sidebar` some em `xs`, `MobileNavDrawer` + hambúrguer entram) e as telas construídas com `Grid`/`Card`/`List` (`CycleDetailPanel` — Home e Relatórios, `Payments`, formulários) já se adaptam ao celular.

Uma auditoria em viewport 375×812 (2026-09-01, registrada abaixo) confirmou que **seis pontos das telas internas ainda não são confortáveis no celular**. Os dois mais graves:

- as telas de **listagem de Grupos** (`Dashboard`) e **listagem de Despesas** (`ExpenseManager`) usam `<Table>` de 5–6 colunas sem layout alternativo — no celular viram uma tabela que rola de lado, com células espremidas;
- o **cabeçalho do grupo** (`GroupHeader`) tem um cluster de controles que soma mais que a largura da tela → scroll horizontal em todas as rotas `/groups/:id/*`.

O objetivo desta feature é **usabilidade**: alvos de toque adequados, conteúdo cabendo na largura, sem scroll horizontal, texto legível sem zoom. **Não é redesign visual** — a paleta, a tipografia e os componentes atuais (`@mui/material`, `theme/brandColors.ts`, `theme/DespesasThemeScope.tsx`) permanecem. Padrão de referência já existente no próprio projeto: `Payments.tsx` renderiza as despesas como `Card` empilhado em `Stack`, não como tabela.

## 2. Achados confirmados

Auditoria de código + verificação ao vivo em viewport 375px no dev server. "Ao vivo" = confirmado no navegador; "código" = inferido do source, ainda não visto rodando (as telas internas exigem sessão autenticada, que não foi possível montar na auditoria).

### 2.1 F1 — Tabelas de listagem não têm layout de cartão no mobile (severidade ALTA)

`frontend/src/pages/Dashboard.tsx:172-253` (lista de grupos: colunas *checkbox, Nome, Responsável, Integrantes, Ações*) e `frontend/src/pages/ExpenseManager.tsx:398-544` (lista de despesas: *Tipo, Despesa, Valor, Credor, Status, Ações*) usam `<TableContainer>`/`<Table>`. O `<TableContainer>` do MUI tem `overflow-x: auto`, então a página não estoura — mas no celular o usuário precisa rolar a tabela lateralmente e as células ficam espremidas. São telas centrais e de uso frequente. Método (código): a lista some abaixo de `sm` e dá lugar a `Card`/`List` empilhados (as mesmas colunas viram linhas dentro do card), mantendo a `<Table>` a partir de `md`.

### 2.2 F2 — Cabeçalho do grupo transborda a largura em telas estreitas (severidade ALTA; provável, não confirmado ao vivo)

`frontend/src/layouts/group/GroupHeader.tsx:55-86`. O `<Box>` externo tem `flexWrap: 'wrap'`, mas o cluster da direita (`<Select>` de grupo com `minWidth: 180` + `IconButton` de notificações + `Avatar` + `Typography` com o nome do usuário) é uma linha flex **sem** wrap, somando ~358px — maior que a largura útil do `<Container>` em 375px. Resultado esperado: scroll horizontal em todas as telas `/groups/:id/*` (Home, Despesas, Participantes, Pagamentos, Relatórios). Método (código): permitir wrap no cluster e/ou ocultar o nome do usuário em `xs` (`display: { xs: 'none', sm: 'block' }`) e/ou reduzir o `minWidth` do `<Select>` no mobile.

### 2.3 F3 — Seletor de competência pode cortar o intervalo de datas (severidade MÉDIA)

`frontend/src/pages/ExpenseManager.tsx:299-315` e `frontend/src/pages/Payments.tsx:192-202`. Layout `‹ [Typography variant="h6" — intervalo de datas] ›` centralizado, sem wrap. Com `variant="h6"` (1.25rem) e um intervalo longo (ex.: "1 de setembro – 30 de setembro" no `ExpenseManager`) pode não caber em 375px. Método (código): `variant` responsivo (`{ xs: 'subtitle2', md: 'h6' }`) ou formato de data curto no mobile.

### 2.4 F4 — Barra de ações do ExpenseManager sem wrap (severidade BAIXA)

`frontend/src/pages/ExpenseManager.tsx:266-296`. "Fechar mês" + "Nova Despesa" lado a lado com `gap: 2`, sem `flexWrap`. Fica no limite em 375px; qualquer mudança de rótulo/idioma estoura. Método (código): `flexWrap: 'wrap'` no `<Box>` do cabeçalho (defensivo, barato).

### 2.5 F5 — Respiro vertical fixo generoso no mobile (severidade BAIXA)

`frontend/src/layouts/SimpleShellLayout.tsx:36` e `frontend/src/layouts/GroupShellLayout.tsx:62` usam `<Container ... sx={{ mt: 4, mb: 4 }}>` (32px fixos). No celular é espaço desperdiçado no topo. Método (código): `mt`/`mb` responsivo (`{ xs: 2, md: 4 }`).

### 2.6 F6 — Telas não verificadas ao vivo (severidade a confirmar)

`frontend/src/pages/ExpenseView.tsx`, `frontend/src/pages/ExpenseForm.tsx` (lista de checkboxes "quem participa desta despesa?"), `frontend/src/pages/GroupMembersForm.tsx`, `frontend/src/components/PixPaymentDialog.tsx` e os diálogos de confirmação de `ExpenseManager`/`Payments`. A análise de código não apontou problema (cards com `maxWidth` que encolhem abaixo do limite; diálogos com `fullWidth maxWidth="xs"`), mas nenhuma dessas telas foi vista rodando em 375px. Método: percorrer cada uma em viewport 375px (com sessão autenticada real) durante a implementação e abrir task só para o que de fato quebrar.

## 3. Fora de escopo desta feature

- **Redesign visual / nova identidade.** Só responsividade e usabilidade; paleta, tipografia e componentes atuais permanecem.
- **Tela de login e shell de navegação.** `LoginPage`/`login/*`, `Sidebar`, `MobileNavDrawer`, hambúrguer do `GroupHeader` — já verificados ao vivo e ok no bugfix `20260901-frontend-meta-viewport-mobile`. F2 trata só do *cluster de controles à direita* do `GroupHeader`, não da navegação.
- **App Expo (`expense/app`).** Esta feature é exclusivamente `expense/frontend` (web).
- **Itens de backlog 036 e 037** (`<html lang="en">` + `<title>` estático; `frontend/dist/index.html` versionado) — reais, mas não são usabilidade mobile.
- **Novo endpoint ou campo de API.** Se algum layout de cartão no mobile precisar de um dado que a API não devolve hoje, isso é mudança de API (backend) e sai desta feature (ver `05-context-frontend.md` §"Convenções fixas").
- **Paginação / virtualização / performance de listas longas.** É outra preocupação; aqui o alvo é layout.
- **`prefers-color-scheme` / tema escuro.** O app é `mode: 'light'` fixo hoje; não muda aqui.
