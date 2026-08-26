# Plan — Navegação mobile do GroupShellLayout

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260826

---

## 1. Componente compartilhado de lista de navegação (specify §2.1)

- Extrair de `frontend/src/layouts/Sidebar.tsx` a renderização da lista de itens (o bloco `<List sx={{ px: 1.5 }}>{items.map(...)}</List>`, hoje em `Sidebar.tsx:121-125`) para um novo componente `frontend/src/layouts/NavList.tsx`, recebendo `items: GroupNavItem[]` e `onNavigate?: () => void`.
- `SidebarNavItem` (hoje interno a `Sidebar.tsx`) muda de arquivo junto (para `NavList.tsx`, já que é implementação de `NavList`) e passa a aceitar/repassar `onNavigate`, chamando-o no `onClick` dos itens com `to` (link) e `onAction` (botão) — não nos itens `children` (que só expandem/colapsam).
- `Sidebar.tsx` passa a importar e usar `NavList`, sem passar `onNavigate` (comportamento desktop não muda).
- Por quê essa abordagem e não outra: evita duplicar a lógica de item ativo/expansível (`containsActiveChild`, estado `open` por item) entre desktop e mobile — sidebar e drawer mostram exatamente os mesmos itens e devem se comportar de forma idêntica, só o container muda (`Box` fixo vs. `Drawer`).

## 2. Drawer mobile (specify §2.1)

- Novo componente `frontend/src/layouts/MobileNavDrawer.tsx`: props `items: GroupNavItem[]`, `open: boolean`, `onClose: () => void`.
- Renderiza `<Drawer variant="temporary" open={open} onClose={onClose}>` (MUI `Drawer`, já uma dependência do projeto via `@mui/material`) contendo `BrandWordmark` (mesmo cabeçalho visual da `Sidebar`) e `<NavList items={items} onNavigate={onClose} />` — fecha o drawer automaticamente ao navegar ou disparar uma ação (ex. "Sair").
- **Atualizado durante TASK-207**: a versão inicial usava `ModalProps={{ keepMounted: true }}` (otimização padrão do MUI para evitar remount a cada toggle). Ao integrar no `GroupShellLayout`, isso manteve os itens do drawer sempre no DOM (só ocultos visualmente/via `aria-hidden`), duplicando texto/links já renderizados pela `Sidebar` desktop e quebrando testes existentes de `GroupShellLayout.test.tsx` que usam `getByText`/`getByRole` sem escopo (que passam a encontrar 2 ocorrências de "Configurações"/"Sair"). Removido `keepMounted` — o `Drawer` volta ao padrão do MUI de desmontar o conteúdo quando fechado, eliminando a duplicação sem custo de teste quebrado; o custo de remount só ocorre ao abrir o menu mobile, aceitável para este caso de uso.
- Por quê essa abordagem e não outra: `Drawer` temporário é o padrão MUI para navegação mobile equivalente a uma sidebar desktop, e o app já usa MUI em todo o resto — não introduz dependência nova.

## 3. Botão hambúrguer no GroupHeader (specify §2.1)

- `frontend/src/layouts/group/GroupHeader.tsx` ganha nova prop obrigatória `onMenuClick: () => void`.
- Adiciona `IconButton aria-label="Abrir menu de navegação"` com `MenuIcon` (`@mui/icons-material/Menu`) antes do `Typography` de título, visível só abaixo de `md`: `sx={{ display: { xs: 'inline-flex', md: 'none' } }}` — espelha o breakpoint inverso do usado em `Sidebar.tsx:112` (`{ xs: 'none', md: 'flex' }`), garantindo que o botão só aparece exatamente quando a sidebar desktop está oculta.
- Por quê essa abordagem e não outra: mantém o padrão já usado no header (ícones de ação com `IconButton`), sem precisar de um componente de header novo.

## 4. Orquestração no GroupShellLayout (specify §2.2)

- `frontend/src/layouts/GroupShellLayout.tsx` ganha `const [mobileNavOpen, setMobileNavOpen] = useState(false)`.
- Passa `onMenuClick={() => setMobileNavOpen(true)}` para `GroupHeader` (linha ~59-65).
- Renderiza `<MobileNavDrawer items={groupNavItems(groupId ?? '', navigate)} open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />` ao lado do `<GroupSidebar groupId={groupId ?? ''} />` já existente (linha 57) — os itens já são calculados nessa mesma função em `activeItem` (linha 44), reaproveitados sem duplicar a lista.
- Como `GroupShellLayout` envolve o `<Outlet />` de todas as rotas do grupo (Resumo, Despesas, Participantes, Pagamentos — ver `groupNavItems` em `GroupSidebar.tsx:27-41`), essa única integração cobre todas elas simultaneamente, sem tocar em cada página.

## 5. Estado atual em desktop inalterado (specify §2.3)

- Nenhuma mudança nas seções acima altera `Sidebar.tsx:104-116` (o `Box` com `display: { xs: 'none', md: 'flex' }`) além da extração interna do item 1 — o comportamento visual em desktop (`md` e acima) permanece idêntico. Não há task própria para isso; é uma restrição verificada nos testes existentes de `Sidebar.test.tsx`, que não devem quebrar.

## N. Ordem de execução

Há dependência técnica direta entre os itens:

1. **§1 (NavList)** — pré-requisito de §2, já que `MobileNavDrawer` importa `NavList`.
2. **§2 (MobileNavDrawer)** — pré-requisito de §4, que o instancia.
3. **§3 (prop no GroupHeader)** — independente de §1/§2, pode ser feito em paralelo, mas é pré-requisito de §4, que passa a prop.
4. **§4 (integração no GroupShellLayout)** — depende de §2 e §3 completos; é o item que efetivamente liga tudo e resolve o problema descrito no specify.

Ordem de tasks em `tasks.md`: §1 → §2 → §3 → §4 (sequencial, mesmo §3 sendo tecnicamente independente de §1/§2 — mantém uma única cadeia simples de branches sem dois caminhos paralelos para uma feature deste tamanho).
