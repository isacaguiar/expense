# Plan — Configuração de URL da API e Auth Guard no Frontend Web

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260817

---

## 1. URL da API via variável de ambiente (specify §2.1)

- Criar `frontend/src/config.ts`, exportando `export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'` — usa a API nativa de env vars do Vite (`import.meta.env`, já disponível sem dependência nova), com fallback igual ao valor hoje hardcoded para não quebrar o `npm run dev` de quem não configurar `.env`.
- Criar `frontend/.env.example` documentando `VITE_API_BASE_URL=http://localhost:8000`, e `frontend/.env` local (não versionado) com o mesmo valor — adicionar `.env` (mantendo `.env.example` fora do ignore) em `frontend/.gitignore`, que hoje não trata arquivos `.env` (só tem `node_modules/`, `dist/`, `build/`).
- Substituir a string literal `'http://localhost:8000'` por `${API_BASE_URL}` (import de `../config`) em cada chamada identificada no achado 2.1: `Dashboard.tsx:33`, `GroupForm.tsx:37,70,72`, `GroupMembersForm.tsx:42,43,64,69`, `ExpenseManager.tsx:80,138`, `GroupList.tsx:41`, `LoginPage.tsx:32`.
- Corrigir também `frontend/src/api.ts:4` (`baseURL: 'http://localhost:8080'`) para usar `API_BASE_URL` — o arquivo continua sem ser importado em lugar nenhum (consolidar o client é `TASK-022` de `docs/feature/20260817-migracao-frontend-expo/`, fora de escopo aqui), mas corrigir o valor evita um segundo padrão divergente (8080 vs 8000) sobrevivendo no código.
- **Não** criar um client HTTP central nem alterar a forma como cada página chama `axios`/`fetch` — só o valor da URL muda de string literal para a constante importada. Motivo: consolidar o client é escopo da `TASK-022` já planejada; misturar as duas mudanças tornaria o diff desta feature maior que o necessário e sem gate de aprovação que cubra a decisão de arquitetura do client.
- `LoginPage.tsx:22-30` (bloco comentado, achado 2.3) não é tocado — está fora de escopo (`specify.md` §3).

## 2. Guard de rota na entrada (specify §2.2, parte 1)

- Criar `frontend/src/components/RequireAuth.tsx`: componente que lê `localStorage.getItem('accessToken')` de forma síncrona (mesmo padrão já usado em todas as páginas hoje — não introduz `AsyncStorage`/`AuthContext`, que é escopo da `TASK-023`) e renderiza `<Outlet />` se houver token, ou `<Navigate to="/" replace />` se não houver.
- Encaixar em `frontend/src/App.tsx`: envolver o grupo de rotas privadas (hoje `<Route element={<InternalLayout />}>`, `App.tsx:18-29`) com uma rota pai usando `RequireAuth` como `element`, mantendo `InternalLayout` como filho — ou seja, `RequireAuth` decide se a árvore de rotas privadas renderiza; `InternalLayout` continua só cuidando do layout visual (Navbar + `Outlet`).
- Por que um wrapper de rota e não checagem em cada página: é exatamente o que o achado 2.2 e a descrição do item 004 do backlog propõem, e evita reimplementar a checagem em cada tela nova (mesmo raciocínio do item original).

## 3. Redirecionamento em 401 durante a sessão (specify §2.2, parte 2)

- Em `GroupList.tsx:45-50` e `ExpenseManager.tsx:88-96` (catch das chamadas `axios`), quando `err.response?.status === 401`, chamar `navigate('/', { replace: true })` em vez de (ou antes de) popular a mensagem de erro — cobre o caso de token expirar/tornar-se inválido no meio da sessão (o guard do item 2 só verifica na entrada da rota, não durante chamadas subsequentes).
- Outras páginas com chamadas autenticadas (`Dashboard.tsx`, `GroupForm.tsx`, `GroupMembersForm.tsx`) não tratam 401 de forma diferenciada hoje (`Dashboard.tsx:37` simplesmente ignora erro e zera a lista) — não fazem parte do achado 2.2 nem desta feature; ficam com o comportamento atual, coberto apenas pelo guard de entrada do item 2.

## 4. Ordem de execução

Sem dependência técnica entre os itens 1, 2 e 3 — tocam arquivos e preocupações diferentes (configuração de URL vs. autenticação) e nenhum depende do outro para funcionar isoladamente. Ordem sugerida em `tasks.md`: item 1 primeiro (mudança mecânica, menor risco, toca mais arquivos), depois item 2 (guard de entrada, a peça central do achado 2.2), por fim item 3 (ajuste pontual em 2 arquivos que já existem desde o item 2).
