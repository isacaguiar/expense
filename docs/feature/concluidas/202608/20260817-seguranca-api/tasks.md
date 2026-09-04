# Tasks — Segurança da API

> IDs preservados de `docs/sdd/03-tasks.md` (Épico B original) para manter rastreabilidade com branches/commits já abertos. Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição do formato.

Versão: 1.2 · Última atualização: 2026-08-21

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-011 | Mover `GET /pix/generate` para dentro do grupo `jwt.auth`, restringir geração ao próprio usuário ou a alguém com quem ele compartilhe grupo | backend | plan.md §1 | antes do merge | **Mergeada em `dev`** (PR #1, `backend/TASK-011-pix-auth`) |
| TASK-012 | Checagem de membership em `GroupController@show/update/destroy` | backend | plan.md §2 | antes do merge | **Mergeada em `dev`** (PR #2, `backend/TASK-012-group-membership`) |
| TASK-013 | Confirmar/rotacionar credenciais expostas (jasypt, Google OAuth) | infra | plan.md §3 | **sim — humano faz a rotação** | Pendente (achado de arquivo já não reproduz; rotação não confirmada) |
| TASK-014 | Corrigir `working-directory: backend-php` → `backend` em `deploy-backend.yml` | infra | plan.md §4 | antes do deploy | Pendente |
| TASK-015 | Remover log de credenciais em texto puro em `AuthController::login` | backend | plan.md §5 | antes do merge | **Mergeada em `dev`** (PR #3, `backend/TASK-015-no-plaintext-log`) |
| TASK-016 | Checagem de membership em `GroupMemberController@store` | backend | plan.md §6 | antes do merge (PR feature → `dev`) | Em andamento |

## Critérios de aceite

- **TASK-011**: chamar `GET /pix/generate` sem token → `401`. Chamar autenticado pedindo o Pix de alguém que não compartilha grupo → `403`. Chamar autenticado pedindo o próprio Pix ou o de alguém do mesmo grupo → `200` com QR/copia-e-cola.
- **TASK-012**: `GET/PUT/DELETE /groups/{id}` de um grupo do qual o usuário autenticado não é membro → não deve retornar/alterar dado do grupo.
- **TASK-013**: repositório sem segredo em texto puro (checagem por grep); confirmação humana registrada de que as credenciais antigas foram invalidadas.
- **TASK-014**: workflow aponta para `backend`; próximo deploy manual validado por humano.
- **TASK-015**: nenhuma chamada a `Log::*` no fluxo de login recebe o array `$credentials` bruto.
- **TASK-016**: `POST /groups/{id}/members` de um grupo do qual o usuário autenticado não é membro → `404`, nenhum membro adicionado, nenhum usuário novo criado, nenhum e-mail de convite disparado. Membro legítimo do grupo continua conseguindo adicionar membro normalmente (`201`).
