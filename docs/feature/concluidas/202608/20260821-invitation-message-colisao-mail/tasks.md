# Tasks — Invitation message colisão mail

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-128).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-129 | Renomear chave `message`→`inviteMessage` no e-mail de convite (controller + blade) e testar de ponta a ponta | backend | plan.md §1, §2 | antes do merge | Implementada na branch da feature |

## Critérios de aceite

- **TASK-129**: `POST /api/invitations` autenticado, com `message` preenchido → `200` (hoje retorna `500`). E-mail renderizado contém o texto da mensagem personalizada (não o objeto `Mail\Message`). `POST /api/invitations` sem `message` continua `200` (regressão). Teste automatizado em `backend/tests/Feature/InvitationControllerMailViewsTest.php` cobrindo o caso com `message` preenchido.
