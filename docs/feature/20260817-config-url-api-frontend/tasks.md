# Tasks — Configuração de URL da API e Auth Guard no Frontend Web

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-027` — maior ID já usado no projeto antes desta feature: `TASK-026` (`docs/feature/20260817-migracao-frontend-expo/tasks.md`).

Versão: 1.0 · Criado em: 20260817

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-027 | Introduzir `VITE_API_BASE_URL` (`frontend/src/config.ts` + `.env`/`.env.example`) e substituir a URL da API hardcoded em todas as chamadas de `frontend/src/pages` e em `frontend/src/api.ts` | frontend | plan.md §1 | nenhum | Concluída (PR #4 mergeado em `main`) |
| TASK-028 | Criar guard de rota (`RequireAuth`) e aplicá-lo às rotas privadas de `frontend/src/App.tsx`, redirecionando para `/` quando não houver token de acesso | frontend | plan.md §2 | nenhum | PR aberto |
| TASK-029 | Redirecionar automaticamente para `/` quando uma chamada autenticada retornar 401 em `GroupList.tsx` e `ExpenseManager.tsx`, em vez de só exibir mensagem de erro | frontend | plan.md §3 | nenhum | Pendente |

## Critérios de aceite

- **TASK-027**: alterar `VITE_API_BASE_URL` em `frontend/.env` e reiniciar `npm run dev` muda o host chamado por todas as páginas sem editar nenhum arquivo `.tsx`; `grep -rn "http://localhost:8000\|http://localhost:8080" frontend/src` não retorna nenhuma ocorrência fora do bloco comentado em `LoginPage.tsx:22-30` (fora de escopo, `specify.md` §3).
- **TASK-028**: acessar `/dashboard` (ou qualquer rota dentro de `InternalLayout`) diretamente pela URL sem `accessToken` em `localStorage` redireciona para `/`; com `accessToken` presente, a rota renderiza normalmente.
- **TASK-029**: em `GroupList` e em `ExpenseManager`, uma resposta 401 da API (ex.: token expirado/inválido) navega automaticamente para `/`, sem exigir reload manual da página.
