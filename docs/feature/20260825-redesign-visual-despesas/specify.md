# Specify — Redesign Visual das Telas de Despesas

> Feature: aplica a linguagem visual dos mockups fornecidos pelo usuário (`assets/images/01.png` a `06.png` + `site-full.png`, fora do repo) às 3 telas do CRUD de Despesas (`ExpenseManager.tsx`, `ExpenseView.tsx`, `ExpenseForm.tsx`) e ao wrapper `ExpensesEntry.tsx` — só polish visual (cor, forma, espaçamento, tipografia, sombra), sem alterar estrutura de layout, navegação ou comportamento. Origem: pedido novo do usuário nesta conversa ("aplique o impeccable polish no frontend com o mockup"), sem task/épico prévio em `03-tasks.md`.

Versão: 1.0 · Criado em: 20260825

---

## 1. Problema

O usuário forneceu um conjunto de mockups (`assets/images/01.png`–`06.png`, `site-full.png`) mostrando o CRUD de Despesas ("DESPESAS – CRUD: Criar, Visualizar, Editar e Excluir") com uma linguagem visual mais refinada que a implementação atual: cores de marca consistentes, formas arredondadas/pill, hierarquia tipográfica mais clara, sombras suaves, chips de status e avatares bem tratados. O usuário pediu explicitamente para usar essas imagens como referência e, ao ser questionado sobre escopo, confirmou: só as telas de Despesas, mantendo a estruturação (layout/grid/diálogos) e o funcionamento atuais — só os componentes visuais mudam.

## 2. Achados confirmados

### 2.1 Cor de marca já existe mas não está no tema global — hoje aplicada de forma ad-hoc

`frontend/src/theme.ts:6-9` define `palette.primary.main: '#1976d2'` (azul padrão do MUI, nunca trocado). A cor de marca real (`#17A37F`, verde) só existe em `frontend/src/theme/brandColors.ts` e é aplicada manualmente via `sx` em componentes específicos (`layouts/Sidebar.tsx`, `layouts/group/GroupHeader.tsx`, `layouts/BrandWordmark.tsx`, `components/BalanceCards.tsx`, `components/SettlementList.tsx`, `pages/Dashboard.tsx`, `pages/ExpenseManager.tsx` — só nos avatares). Resultado: botões (`variant="contained" color="primary"`), campos de texto focados, chips selecionados e o `ToggleButtonGroup` de filtro em `ExpenseManager.tsx` renderizam azul MUI padrão, não a cor de marca verde que aparece no mockup e já é usada no sidebar/header (que ficam fora do escopo desta feature porque já estão visualmente alinhados ao mockup).

### 2.2 Sidebar e header já estão alinhados ao mockup — não fazem parte do gap

`layouts/Sidebar.tsx:20-28` já usa `brandColors.primaryLight`/`brandColors.primary` para o item de navegação ativo (fundo claro + texto verde, igual ao mockup); `layouts/group/GroupHeader.tsx` já tem título em negrito, sino de notificação e avatar+nome no canto superior direito, igual ao mockup. Confirma que o gap visual está isolado nas 3 telas de Despesas, não no shell.

### 2.3 `ExpenseManager.tsx` (listagem) — estrutura hoje

`pages/ExpenseManager.tsx:260-500`: cabeçalho com botão "Nova Despesa" à direita, seletor de competência centralizado, busca (`TextField` retangular) + `ToggleButtonGroup` (Todas/Fixas/Variáveis, cantos levemente arredondados, não pill) acima de uma tabela (`TableContainer` + `Paper elevation={3}`, cantos com radius padrão do tema) com colunas Tipo/Despesa/Valor/Credor/Status/Ações. Coluna Ações mostra até 5 `IconButton` inline (ver detalhes, remover fixa, editar, excluir, marcar paga, desfazer pagamento — condicionais por `can*`, linhas 411-480). Chip de status já usa `color="success"`/`"warning"` (verde/laranja, igual ao mockup) mas com o radius padrão do tema, não pill. Grid principal de duas colunas (`Grid size={{ lg: 8 }}` tabela + `Grid size={{ lg: 4 }}` `SummarySidePanel`) não existe no mockup (que mostra só a tabela em largura total) — mantido como está, por instrução explícita do usuário.

### 2.4 `ExpenseView.tsx` (visualizar/editar) — estrutura hoje

`pages/ExpenseView.tsx`: modo visualização (267-297) é um único `Card` centralizado (`maxWidth: 560`) com título+chip de tipo, valor em destaque, data, credor, botões Editar/Voltar — não tem o card duplo (Valor/Descrição/Categoria à esquerda, Pago por/Dividido entre/Status à direita) nem o card "Participantes e status de pagamento" do mockup (imagem 02). Modo edição (188-264) é o mesmo `Card` único com campos empilhados verticalmente. Mantidos como um único bloco (sem duplicar em dois cards) por instrução do usuário — só espaçamento/tipografia/cor mudam dentro do bloco existente.

### 2.5 `ExpenseForm.tsx` (criar) — estrutura hoje

`pages/ExpenseForm.tsx:169-268`: mesmo padrão de `Card` único centralizado (`maxWidth: 560`) com campos empilhados verticalmente (Descrição, Valor, Tipo via `TextField select`, Data, Parcelas condicional, Pagador, checkboxes de participantes). O mockup (imagem 03/04) mostra tipo como toggle pill Fixa/Variável e um card "Resumo" lateral com valor por pessoa ao vivo — **não implementado nesta feature** (introduziria elemento novo, fora do escopo "sem alterar estruturação/funcionamento").

### 2.6 `ExpensesEntry.tsx` — sem tela própria, só redireciona

`pages/ExpensesEntry.tsx`: renderiza só `CircularProgress`/mensagem de erro/vazio antes de redirecionar para `/groups/:id/expenses`. Não corresponde a nenhum painel do mockup; herda a cor de marca automaticamente se ganhar o mesmo escopo de tema (achado 2.1), sem precisar de mudança própria.

### 2.7 Sem asset de ilustração para o estado vazio

O painel "7. LISTAGEM VAZIA" do mockup usa uma ilustração customizada (moeda/envelope estilizada) que não existe como arquivo no repositório nem foi fornecida separadamente — hoje `ExpenseManager.tsx:351-352` só mostra texto (`Nenhuma despesa encontrada...`). Sem o asset original, uma ilustração equivalente (ícone MUI em círculo com a paleta de marca) é a aproximação usada nesta feature, não um recorte do PNG do mockup.

## 3. Requisitos

- **R1**: Um tema MUI com escopo restrito às 3 telas de Despesas + `ExpensesEntry.tsx` (nested `ThemeProvider`, mesclando o tema global) troca a cor primária para `brandColors.primary` e ajusta radius/sombra de `Button`, `Chip`, `TextField`/`Select` (outline), `ToggleButtonGroup`, `Paper`/`Card`, `Dialog` para o padrão do mockup (pill em botões/chips/segmented control, cantos ~10-12px em cards/tabela, sombra suave). Sidebar/Header (fora do escopo) continuam com o tema global, inalterados.
- **R2**: `ExpenseManager.tsx` recebe: busca em formato pill com ícone de busca, `ToggleButtonGroup` em formato pill com estado ativo preenchido em verde, cabeçalho de tabela com fundo levemente acinzentado, linhas com hover sutil, chips de status em pill, botões de ação (ícones) com hover circular sutil — mesma lista de ações, mesmas condições `can*`, nenhum clique reordenado ou removido.
- **R3**: Estado vazio de `ExpenseManager.tsx` ganha um ícone ilustrativo (aproximação descrita no achado 2.7) centralizado acima da mensagem existente, sem alterar o texto.
- **R4**: `ExpenseView.tsx` (visualização e edição) ganha tipografia/espaçamento/cores revisados dentro do único `Card` existente (título+chip, valor em destaque com a cor de marca, rótulos com hierarquia mais clara), sem duplicar em dois cards nem adicionar/remover campos.
- **R5**: `ExpenseForm.tsx` ganha o mesmo tratamento visual dentro do `Card` único existente (toggle de tipo pode virar visualmente mais próximo de pill via o tema de R1, já que é o mesmo `ToggleButtonGroup`/`TextField select` usado hoje — sem introduzir o card "Resumo" lateral do mockup).
- **R6**: Diálogos existentes (detalhe rápido, remover despesa fixa, excluir despesa) e `Snackbar`s de sucesso/erro herdam o novo tema (radius, cor) sem mudança de conteúdo, campos ou fluxo.
- **R7**: Nenhuma rota, endpoint, prop, estado ou handler existente é adicionado, removido ou renomeado — toda mudança é limitada a `sx`/tema/JSX de apresentação.

## 4. Fora de escopo desta feature

- Alterar o tema MUI global (`theme.ts`) ou qualquer tela fora de Despesas (Resumo, Pagamentos, Participantes, Grupos, Login, etc.) — confirmado com o usuário: só as telas de Despesas.
- Alterar `layouts/Sidebar.tsx`, `layouts/group/GroupHeader.tsx`, `layouts/group/GroupSidebar.tsx` ou `layouts/simpleNavItems.ts` — já alinhados ao mockup (achado 2.2).
- Alterar `components/SummarySidePanel.tsx` (usado também em `GroupSummary.tsx`, fora de escopo) — herda o novo tema visualmente só quando renderizado dentro de `ExpenseManager.tsx`, sem mudança de código próprio.
- Reestruturar `ExpenseView.tsx`/`ExpenseForm.tsx` em dois cards ou grid de duas colunas, ou adicionar o card "Resumo" com valor por pessoa ao vivo — mudaria a estruturação, vetado explicitamente pelo usuário nesta conversa.
- Consolidar os ícones de ação da tabela em um menu "..." (kebab) como no mockup — mudaria o número de cliques para cada ação (funcionamento), vetado pela mesma instrução.
- Trocar a fonte tipográfica (o mockup sugere uma sans-serif geométrica diferente de Roboto) — fora do pedido, e mudança de fonte é uma decisão de identidade de marca mais ampla que cabe em outra conversa.
- Produzir/recortar assets de imagem a partir do PNG do mockup (sem ferramenta de imagem disponível nesta sessão) — estado vazio usa aproximação vetorial (ícone MUI), não recorte de pixel.
