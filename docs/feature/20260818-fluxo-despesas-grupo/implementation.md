# Implementation — Fluxo de Despesas do Grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260818

---

## 1. Desvios do fluxo padrão (se houver)

Feature segue `04-implementation.md` sem exceção (branch por task a partir de `dev`, PR contra `dev`). Único ponto de atenção: as tasks de backend (TASK-034, TASK-036) exigem o container `mysql-expense-db` (`docker-compose.yml`) rodando localmente para validação manual — não é um desvio de processo, só uma dependência de ambiente a lembrar antes de rodar `php artisan tinker`/testes manuais.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-033 | PR aberto | 2026-08-18 | Claude (IA) | `npx tsc --noEmit` — sem erros. Validação manual no browser: login com usuário de teste (`isac@email.com`, id 1, membro dos grupos 1 e 8), navegação para `/groups/1/expenses` — `read_network_requests` confirma `GET http://localhost:8000/api/groups/1/expenses?year=2026&month=8` disparando de verdade (404, esperado — endpoint só existe a partir da TASK-034), enquanto antes do fix a chamada nunca acontecia (`if (!groupId) return;` cortava antes). | Para o teste manual, foi necessário subir o container `mysql-expense-db` (estava parado) e definir uma senha conhecida (`senha123`) no usuário de teste local (id 1) via `tinker`, só no banco de dev local — não é dado de produção. |
