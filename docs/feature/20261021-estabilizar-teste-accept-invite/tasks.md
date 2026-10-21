# Tasks — Estabilizar o teste do AcceptInvitePage

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261021

IDs a partir de TASK-409: o maior em qualquer branch do repositório é TASK-408 (feature `20261010-filtros-credor-devedores-status-despesas`, conferido em `origin/*` em 2026-10-21). Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-409 | Esperar o redirecionamento no teste de sucesso do convite (`AcceptInvitePage.test.tsx:96`) | frontend | plan.md §1, §2 e §3 (passos 1, 2 e 4) | nenhum | Pendente |
| TASK-410 | Esperar o redirecionamento no teste de sucesso do reset (`AcceptInvitePage.test.tsx:171`) e rodar a verificação final | frontend | plan.md §1, §3 (passos 3 a 5) e §4 | nenhum (merge da feature em `dev` é gate humano) | Pendente |

Dependência: as duas tasks mexem no mesmo arquivo e TASK-410 parte da TASK-409 já integrada (só ordem de merge; tecnicamente os dois testes são independentes, plan.md §5). Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: TASK-409 direto em `frontend/20261021-estabilizar-teste-accept-invite`; TASK-410 em `…-TASK-410`, integrada na branch da feature com `git merge --no-ff`, sem PR por task.

## Critérios de aceite

- **TASK-409**:
  - **Vermelho antes:** com o arquivo atual e a sonda temporária `await new Promise(r => setTimeout(r, 2300))` no começo do teste `:118`, `cd frontend && npx vitest run src/pages/AcceptInvitePage.test.tsx` falha **só** em `shows the backend error message when the token is invalid or expired`, com `expected "vi.fn()" to not be called at all, but actually been called 1 times`.
  - **Verde depois:** com a linha `await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'), { timeout: 3000 });` (e o comentário de uma linha) acrescentada ao fim do teste `:96`, o mesmo comando, ainda com a sonda, passa 9 de 9.
  - **Mutação:** remover essa linha deixa a sonda vermelha de novo (a linha é necessária).
  - **Estado final:** sonda removida; `git diff` do arquivo mostra só o comentário e o `waitFor` no `:96`; o `expect(mockNavigate).not.toHaveBeenCalled()` do `:131` continua; `git diff --stat` lista apenas `AcceptInvitePage.test.tsx` (componente intocado, R1).
  - `npx vitest run src/pages/AcceptInvitePage.test.tsx` sem a sonda: 9 de 9, em 3 execuções seguidas; `npx tsc --noEmit` sem erro.
- **TASK-410**:
  - **Vermelho antes:** com a sonda dupla temporária no teste `:193` (`await new Promise(r => setTimeout(r, 2300))` no começo e `expect(mockNavigate).not.toHaveBeenCalled()` no fim) e **sem** a espera no `:171`, o comando acima falha **só** em `shows the recovery alert (not the invite one) when email or token is missing`, por `navigate` chamado.
  - **Verde depois:** com o `waitFor` (e comentário) acrescentado ao fim do teste `:171`, ainda com a sonda, passa 9 de 9.
  - **Mutação:** remover essa linha deixa a sonda vermelha de novo.
  - **Estado final:** sonda removida; `git diff` mostra só as duas esperas e seus comentários (a da TASK-409 e a desta); nenhuma asserção existente removida ou afrouxada (R3); só `AcceptInvitePage.test.tsx` alterado.
  - **Verificação final (plan.md §4):** o arquivo isolado 9 de 9 em 3 execuções; `npx tsc --noEmit` sem erro; suíte completa `cd frontend && npx vitest run` 3 vezes com os workers padrão. Um teste do `AcceptInvitePage.test.tsx` que falhe em qualquer das 3 rodadas **reprova** a task. Uma falha em outro arquivo (por exemplo `RegisterPage.test.tsx` sob carga) não reprova, mas fica registrada no `implementation.md` com o resultado exato, como veio.
