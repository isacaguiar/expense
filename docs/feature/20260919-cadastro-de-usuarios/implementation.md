# Implementation — Cadastro de usuários

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260919

---

## 1. Desvios do fluxo padrão

Nenhum desvio de fluxo. Duas observações de execução que valem para todas as tasks desta feature:

- **Testes de backend rodam contra o MySQL local** (`DatabaseTransactions`, não `RefreshDatabase` — as linhas de sqlite em `phpunit.xml` estão comentadas). A migration da TASK-282 precisa estar aplicada no banco local antes de rodar a suíte das tasks seguintes.
- **TASK-284 foi executada antes da TASK-283**, invertendo a ordem listada em `tasks.md`: o Service (283) envia o Mailable (284), então implementar o Service primeiro deixaria a branch num estado que não compila. O Mailable recebe o prazo por construtor em vez de ler a constante do Service, justamente para não depender dele.
- **O e-mail do código só sai localmente** via Mailpit/Mailhog (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`). Produção ainda não tem SMTP real — ver `plan.md` §8; é pendência humana, não task desta feature.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-282 | Concluída | 2026-09-19 | Claude | `php artisan migrate` DONE 88ms; `php artisan db:table ex_user_pre_create` mostra 12 colunas + índice único em email; `migrate:rollback --step=1` DONE 34ms e `migrate` de volta DONE 58ms; `UserPreCreate::count()` no tinker retorna 0; `pint --test` PASS nos 2 arquivos | Migration aditiva (tabela nova). `attempts` aparece como `boolean, unsigned` no `db:table` porque é `TINYINT UNSIGNED` — é o tipo pedido, só a etiqueta do Laravel que confunde. |
| TASK-284 | Concluída | 2026-09-19 | Claude | `php artisan test --filter=PreRegisterCodeMail` — 2 passed (4 assertions); `pint` limpo nos 2 arquivos | View renderizada de verdade via `render()` (sem `Mail::fake`), que é o que pega nome de view errado — mesmo motivo documentado em `InvitationControllerMailViewsTest`. |
