# Implementation — Cadastro de usuários

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260919

---

## 1. Desvios do fluxo padrão

Nenhum desvio de fluxo. Duas observações de execução que valem para todas as tasks desta feature:

- **Testes de backend rodam contra o MySQL local** (`DatabaseTransactions`, não `RefreshDatabase` — as linhas de sqlite em `phpunit.xml` estão comentadas). A migration da TASK-282 precisa estar aplicada no banco local antes de rodar a suíte das tasks seguintes.
- **O e-mail do código só sai localmente** via Mailpit/Mailhog (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`). Produção ainda não tem SMTP real — ver `plan.md` §8; é pendência humana, não task desta feature.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| — | — | — | — | — | (nenhuma task executada ainda) |
