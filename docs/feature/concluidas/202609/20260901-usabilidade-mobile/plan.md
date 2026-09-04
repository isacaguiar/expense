# Plan — Usabilidade Mobile das Telas Internas

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260901

---

## 0. Decisões transversais

Valem para todos os itens abaixo; não viram task sozinhas (exceto 0.3).

- **0.1 — Estratégia de breakpoint.** Sempre que o CSS resolver, usar a sintaxe responsiva do `sx` (`{ xs, sm, md }`) — não re-renderiza, funciona nos testes sem mock. Só usar `useMediaQuery(theme.breakpoints.down('sm'))` quando for preciso trocar a *árvore* renderizada (tabela ↔ cartões, item F1). Ponto de corte: `sm` (< 600px → layout de cartão; só telefones. Tablet em retrato mantém a tabela).
- **0.2 — Sem dependência nova.** Tudo sai do `@mui/material` já instalado (`useMediaQuery`, `Stack`, `Card`, `sx` responsivo). Não introduzir lib de layout/responsividade.
- **0.3 — Polyfill de `matchMedia` nos testes.** `frontend/src/setupTests.ts` só importa o jest-dom. jsdom não implementa `window.matchMedia`; hoje `useMediaQuery` degrada para `false` (= layout desktop), o que mantém os testes atuais passando, mas impede testar o ramo mobile. Adicionar um polyfill mínimo de `matchMedia` em `setupTests.ts` (mock configurável de `matches`) — pré-requisito do item F1. É a única mudança fora de `frontend/src/pages` e `frontend/src/layouts`.
- **0.4 — Linguagem visual inalterada.** Nenhuma cor, fonte, `elevation`, `borderRadius` ou token de `theme/brandColors.ts` muda. Só estrutura/fluxo/espaçamento.
- **0.5 — Verificação por task.** Cada task é verificada no browser em viewport 375×812 (e uma passada rápida em 768). As telas internas exigem sessão autenticada real — o backend precisa estar no ar e o login feito pelo usuário (o executor não digita senha). Screenshot antes/depois anexado ao PR da feature.

## 1. F1 — Listagens de Grupos e Despesas com layout de cartão no mobile

`frontend/src/pages/Dashboard.tsx:172-253` e `frontend/src/pages/ExpenseManager.tsx:398-544`.

- **Troca de árvore, não CSS duplicado.** `const compact = useMediaQuery(theme.breakpoints.down('sm'))` (< 600px, só telefones — tablet em retrato fica na tabela, decisão do usuário). `compact` → renderiza a lista de cartões; senão → a `<Table>` atual, intocada. Evita manter duas cópias da marcação de linha no DOM ao mesmo tempo.
- **Fonte de dados e handlers únicos.** O componente já calcula tudo (grupos/despesas, permissões, expand state). Extrair, dentro do próprio arquivo, um helper de ações por linha (`renderRowActions(group)` / `renderExpenseActions(exp)`) usado tanto pela célula `Ações` da tabela quanto pelo rodapé do cartão — para tabela e cartão nunca divergirem em comportamento. Nenhuma lógica nova de negócio.
- **Mapa de cartão — Grupos (`Dashboard`):** um `<Card variant="outlined">` por grupo. Topo: nome (bold, clicável → mesmo destino da linha) + chevron de expandir. Corpo: `Responsável: {creator.email}`, `Integrantes: {n}`. Rodapé: os mesmos `IconButton` de ação (editar, participantes, despesas, excluir) numa `Box` com `flexWrap`. Painel expansível (`Dashboard.tsx:241-247`) renderizado abaixo do cartão quando aberto, como hoje.
- **Mapa de cartão — Despesas (`ExpenseManager`):** um `<Card variant="outlined">` por despesa, no mesmo espírito do que `Payments.tsx:222-249` já faz. Topo: ícone de tipo + descrição (bold) + `Chip` de status. Corpo: `Valor: R$ {value}`, `Credor: {payerName}`. Rodapé: o mesmo menu/ícones de ação de `ExpenseManager.tsx:455-539`, com os mesmos `canEdit`/`canDelete`.
- **`<TableContainer>` ganha `sx={{ overflowX: 'auto' }}` explícito** (já é o default, mas deixa a intenção clara): entre `sm` e `lg` a tabela ainda aparece e o container pode ser estreito (tablet em retrato, ~600–840px para 5–6 colunas) — a rolagem fica contida na tabela, não na página.
- **Testes.** `Dashboard.test.tsx:111-113` afirma `columnheader` — com `matchMedia` retornando "no match" por padrão (mesmo com o polyfill 0.3), a tabela continua sendo o default nos testes atuais, que seguem verdes. Adicionar casos novos que setam `matchMedia({ matches: true })` e verificam: os cartões aparecem, as ações do cartão disparam os mesmos handlers, o expand do grupo funciona. `ExpenseManager.test.tsx` idem.

## 2. F2 — Cabeçalho do grupo não transborda em telas estreitas

`frontend/src/layouts/group/GroupHeader.tsx:55-86`.

- **Cluster da direita passa a poder quebrar linha:** no `<Box>` da linha 55, `flexWrap: 'wrap'`, `justifyContent: 'flex-end'`, `rowGap: 1`.
- **Nome do usuário some no `xs`:** o `<Typography>` da linha 84 recebe `sx={{ display: { xs: 'none', sm: 'block' } }}`. O `<Avatar>` (linha 72) fica sempre — é a âncora de identidade e já é compacto (32px).
- **`<Select>` de grupo com largura elástica:** `sx={{ minWidth: { xs: 132, sm: 180 }, maxWidth: { xs: 200, sm: 'none' } }}`. O `MuiSelect-select` já aplica `text-overflow: ellipsis` quando a largura é limitada, então nome de grupo longo trunca em vez de empurrar a linha.
- **Sem novo breakpoint no `<Box>` externo (linha 30)** — ele já tem `flexWrap: 'wrap'`; com o cluster interno também quebrável, título fica na 1ª linha e controles na 2ª quando não couberem.
- **Aceite:** em qualquer rota `/groups/:id/*` a 375px, `document.documentElement.scrollWidth === clientWidth` (sem scroll horizontal). Vale para o `GroupHeader` reusado pelo `SimpleShellLayout` (lá o `<Select>` não renderiza — `groups=[]`).

## 3. F3 — Seletor de competência não corta o intervalo de datas

`frontend/src/pages/ExpenseManager.tsx:298-315` e `frontend/src/pages/Payments.tsx:192-202`.

- O formato de data já é curto nos dois (`ExpenseManager` usa `formatCycleBoundary` = `{ day:'2-digit', month:'short' }`, `Payments` idem) — não mexer no formato.
- **O rótulo passa a ceder espaço:** o `<Typography variant="h6">` do meio recebe `sx={{ flexGrow: 1, minWidth: 0, textAlign: 'center', fontSize: { xs: '1rem', md: '1.25rem' } }}`. Assim ele encolhe a fonte no `xs` e ocupa só o espaço entre as duas `IconButton` de seta, sem empurrar largura.
- Nada além dessas duas telas muda. É a única alteração de F3.

## 4. F4 — Barra de ações do ExpenseManager quebra linha

`frontend/src/pages/ExpenseManager.tsx:266-296`.

- No `<Box>` do cabeçalho (linha 267): `flexWrap: 'wrap'`, `rowGap: 1`. Com só dois botões ("Fechar mês", "Nova Despesa") isso é defensivo — se um rótulo crescer ou entrar um terceiro botão, quebra em vez de estourar.
- Mudança isolada de uma linha de `sx`; task própria por tocar região e preocupação distintas de F3, mesmo sendo no mesmo arquivo.

## 5. F5 — Ritmo vertical do conteúdo no mobile

`frontend/src/layouts/SimpleShellLayout.tsx:36` e `frontend/src/layouts/GroupShellLayout.tsx:62`.

- `<Container ... sx={{ mt: 4, mb: 4 }}>` → `sx={{ mt: { xs: 2, md: 4 }, mb: { xs: 2, md: 4 } }}` nos dois arquivos (mudança idêntica).
- `maxWidth` do `<Container>` fica como está (default `lg`).

## 6. F6 — Verificar as telas não auditadas ao vivo

`ExpenseView`, `ExpenseForm` (lista "quem participa desta despesa?"), `GroupMembersForm`, `PixPaymentDialog` e os diálogos de confirmação de `ExpenseManager`/`Payments`.

- **Não é mudança de código a priori** — é uma passada de verificação em 375px com sessão autenticada real.
- Saída: ou "tudo ok em 375px" registrado no `implementation.md`, ou uma/mais tasks novas em `tasks.md` (`TASK-0xx`) para o que quebrar, seguindo os mesmos padrões de F1–F5 (nada de redesign).
- Fica por último: assim já se verifica junto o efeito das mudanças de F1–F5 nas telas vizinhas.

## 7. Ordem de execução

Sem dependência técnica entre F1–F6 (arquivos/regiões distintos). Uma exceção: **0.3 (polyfill `matchMedia`) precede F1**. Ordenação em `tasks.md` por leverage × esforço:

1. **F2** — menor esforço, afeta todas as telas de grupo (tira o scroll horizontal geral).
2. **F5** — trivial, dois arquivos.
3. **F4** — trivial, um `sx`.
4. **F3** — pequeno, duas telas.
5. **0.3 + F1** — maior esforço (troca de árvore + testes dos dois lados); o polyfill entra na mesma task ou imediatamente antes.
6. **F6** — verificação final, antes de fechar o PR da feature.

Tudo numa única branch de feature `frontend/20260901-usabilidade-mobile` (ADR-003): a 1ª task nasce nela; as demais em sub-branches `...-TASK-0xx` com merge local `--no-ff`, sem PR intermediário. Um PR só, da branch da feature → `dev`.
