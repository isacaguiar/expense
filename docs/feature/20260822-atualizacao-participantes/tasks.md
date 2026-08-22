# Tasks — Convite de Participante por E-mail

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-189 | Gerar token de convite dedicado (TTL 2 dias) em `GroupMemberController::store`, substituindo `Password::getRepository()` | backend | plan.md §3 | antes do merge | Concluída |
| TASK-190 | Corrigir `UserInvitedMail` para renderizar `email.invitation` (em vez da view inexistente) citando quem convidou e o grupo | backend | plan.md §2 | antes do merge | Concluída |
| TASK-191 | Adaptar `InvitationController::verify` para validar o novo token de convite (cache `invitation-token:{email}`) | backend | plan.md §4 | antes do merge | Concluída |
| TASK-192 | Remover `InvitationController::invite` e a rota `POST /invitations` (fluxo duplicado sem uso no frontend) | backend | plan.md §1 | antes do merge | Concluída |
| TASK-193 | Criar página `/aceitar-convite` para o convidado definir senha, consumindo `POST /invitations/verify` | frontend | plan.md §4 | antes do merge | Pendente |

## Critérios de aceite

- **TASK-189**: teste automatizado chama `POST /groups/{id}/members` com e-mail de usuário inexistente e confirma, via `Cache::has('invitation-token:'.$email)` (ou equivalente com `Cache::put` espionado), que o token foi armazenado com TTL de ~2 dias — não mais via `Password::getRepository()`.
- **TASK-190**: teste automatizado com `Mail::fake()` dispara o convite e verifica que `UserInvitedMail` é enviado sem erro de view ausente, com `inviterName` e `groupName` corretos nas variáveis passadas à view `email.invitation`.
- **TASK-191**: teste automatizado cobre `POST /invitations/verify` com token de convite válido (200, senha definida) e com token inválido/expirado (401) — usando o novo cache `invitation-token:{email}`, não mais `Hash::check` contra a senha temporária.
- **TASK-192**: `php artisan route:list` não lista mais `POST /invitations`; chamada a essa URL retorna 404; testes que cobriam `InvitationController::invite` removidos ou adaptados.
- **TASK-193**: teste de componente (React Testing Library) navega para `/aceitar-convite?email=...&token=...`, preenche o formulário de senha, confirma o POST para `/api/invitations/verify` e o redirecionamento para `/` com mensagem de sucesso após 200; cobre também o caso de erro (token inválido) exibindo mensagem sem redirecionar.
