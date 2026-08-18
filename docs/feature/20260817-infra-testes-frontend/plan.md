# Plan — Infraestrutura de Testes no Frontend Web

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260817

---

## 1. Instalar e configurar Vitest + Testing Library (specify §2.1, §2.2)

- Adicionar a `frontend/package.json` (`devDependencies`): `vitest`, `jsdom` (ambiente DOM para os testes), `@testing-library/react`, `@testing-library/jest-dom` (matchers como `toBeInTheDocument`), `@testing-library/user-event`. Não usar Jest — Vitest reaproveita o `vite.config.js` já existente (mesmo resolver de módulos, mesmo `@vitejs/plugin-react`), sem bundler paralelo a manter, e já é o padrão de fato para projetos Vite.
- Estender `frontend/vite.config.js` com um bloco `test: { environment: 'jsdom', globals: true, setupFiles: ['./src/setupTests.ts'] }` dentro do `defineConfig` existente. `globals: true` habilita `describe`/`it`/`expect` sem import manual em cada arquivo — reduz boilerplate e é a convenção mais comum em projetos Vitest.
- Criar `frontend/src/setupTests.ts` com `import '@testing-library/jest-dom/vitest';` — registra os matchers do `jest-dom` e já traz a tipagem certa para o `expect` do Vitest (evita configurar tipos manualmente).
- Adicionar a `frontend/tsconfig.json` → `compilerOptions.types`: `["vite/client", "vitest/globals"]` (hoje esse campo não existe no `tsconfig.json`; `vite/client` já é necessário desde a TASK-027 para `import.meta.env` — juntar os dois evita `tsc --noEmit` reclamar de `describe`/`it`/`expect` não declarados nos arquivos de teste, já que eles ficam dentro de `src/` e entram no `include` do `tsconfig`).
- Adicionar scripts a `frontend/package.json`: `"test": "vitest run"` (execução única, para CI/checklist pré-PR) e `"test:watch": "vitest"` (modo interativo para desenvolvimento).
- Não integrar com CI nesta feature (`specify.md` §3) — só a infraestrutura local, rodável via `npm test`.

## 2. Teste de `RequireAuth` (specify §2.3)

- Criar `frontend/src/components/RequireAuth.test.tsx`. Renderiza `RequireAuth` dentro de um `MemoryRouter` com duas rotas (uma pública simulando o login em `/`, outra protegida atrás de `<Route element={<RequireAuth />}>`), controlando `localStorage.getItem('accessToken')` antes de cada teste (`localStorage.clear()` no `beforeEach`).
- Dois casos, direto dos critérios de aceite já validados manualmente na TASK-028 (`docs/feature/20260817-config-url-api-frontend/tasks.md`): sem token, a rota pública (login) é o que renderiza; com token presente, o conteúdo protegido renderiza.
- Por que esse componente primeiro: é a peça mais isolada (não depende de `axios`/API), então serve pra validar que a infraestrutura (§1) funciona de ponta a ponta antes de introduzir mock de rede no item 3.

## 3. Teste do redirect em 401 (`GroupList`) (specify §2.3)

- Criar `frontend/src/pages/GroupList.test.tsx`. Usa `vi.mock('axios')` para substituir o módulo, e `vi.mock('react-router-dom', ...)` (reexportando os membros reais via `importOriginal` e sobrescrevendo só `useNavigate` por um `vi.fn()`) para capturar a navegação sem precisar de um `MemoryRouter` real controlando histórico.
- Configura `axios.get` mockado para rejeitar com `{ response: { status: 401 } }` (mesmo formato de erro que a API real devolve, confirmado na validação manual da TASK-029) e afirma que o `navigate` mockado foi chamado com `('/', { replace: true })` — mesmo comportamento já validado manualmente no browser durante a TASK-029.
- Só `GroupList` (não também `ExpenseManager`) nesta feature: o padrão de teste (mock de `axios` + mock de `useNavigate`) é o mesmo nos dois arquivos — repetir para `ExpenseManager` não ensina nada novo sobre a infraestrutura, e o item de backlog 002 pede a infraestrutura funcionando com exemplos, não cobertura completa (`specify.md` §3).

## 4. Ordem de execução

Item 1 é pré-requisito dos itens 2 e 3 (não dá pra escrever/rodar teste sem o Vitest configurado). Itens 2 e 3 não dependem um do outro — ordem sugerida em `tasks.md`: 2 antes de 3, porque `RequireAuth` não precisa de mock de `axios`/`react-router-dom`, servindo de validação mais simples de que a infraestrutura do item 1 está funcionando antes de introduzir os mocks mais elaborados do item 3.
