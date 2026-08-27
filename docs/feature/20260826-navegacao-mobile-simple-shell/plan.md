# Plan — Navegação mobile do SimpleShellLayout

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260826

---

## 1. Integração do MobileNavDrawer no SimpleShellLayout (specify §2.1, §2.2)

- `frontend/src/layouts/SimpleShellLayout.tsx` ganha `const [mobileNavOpen, setMobileNavOpen] = useState(false)`, no mesmo padrão já usado em `GroupShellLayout.tsx:24`.
- Importa `MobileNavDrawer` (`./MobileNavDrawer`, já existente) e o renderiza ao lado do `<Sidebar items={simpleNavItems(navigate)} />` já existente (linha 32): `<MobileNavDrawer items={simpleNavItems(navigate)} open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />`.
- Troca `onMenuClick={() => {}}` (linha 40, no-op) por `onMenuClick={() => setMobileNavOpen(true)}` na chamada de `GroupHeader`.
- Nenhuma mudança em `MobileNavDrawer.tsx`, `NavList.tsx` ou `GroupHeader.tsx` — os três já foram criados e testados pela feature `navegacao-mobile-group-shell` e já aceitam `GroupNavItem[]` genérico (o mesmo tipo que `simpleNavItems` já retorna, reaproveitado de `group/GroupSidebar.tsx`), sem exigir adaptação.
- Por quê essa abordagem e não outra: é a mesma integração já feita em `GroupShellLayout.tsx:24,61,69` (specify da feature anterior, plan.md §4), só trocando a fonte dos itens (`simpleNavItems(navigate)` em vez de `groupNavItems(groupId ?? '', navigate)`) — não há lógica nova a projetar, só replicar um padrão já validado em produção.

## 2. Estado atual em desktop inalterado (specify §2.3)

- Nenhuma mudança na seção acima altera o comportamento de `Sidebar.tsx:104-116` em desktop (`md` e acima) — a `Sidebar` do `SimpleShellLayout` continua renderizada como está hoje (linha 32), o `MobileNavDrawer` é apenas adicionado ao lado dela. Não há task própria para isso; é uma restrição verificada no teste existente `SimpleShellLayout.test.tsx`, que não deve quebrar.

## N. Ordem de execução

Item único (§1) — não há dependência entre múltiplos componentes a criar, diferente da feature anterior (que precisou extrair `NavList` e criar `MobileNavDrawer` do zero). Uma única task cobre a integração completa.
