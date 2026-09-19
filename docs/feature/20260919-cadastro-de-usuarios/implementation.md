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
| TASK-283 | Concluída | 2026-09-19 | Claude | `php artisan test --filter=PreRegistrationService` — 12 passed (50 assertions); `pint` limpo | Dois achados durante a execução, ambos corrigidos e cobertos: (1) o `increment('attempts')` estava dentro do `DB::transaction`, e o rollback da exceção desfazia a contagem — o limite de 5 tentativas nunca seria atingido; só a criação do User ficou na transação. (2) O teste extraía o código com `\d{6}` solto e casava com as cores hex do CSS (`#128468`, `#999999`) antes do código — agora ancora no span `.code`. |
| TASK-285 | Concluída | 2026-09-19 | Claude | `php artisan test --filter=PreRegisterControllerTest` — 11 passed (50 assertions); `php artisan test` (suíte inteira) — 355 passed (1169 assertions); `pint --test` PASS nos 6 arquivos da task; `php artisan route:list --path=pre-register` mostra as 3 rotas | `pint` sem argumento reformatou 8 arquivos pré-existentes (line ending, chaves) que esta task não toca — revertidos com `git checkout --`, conforme "não corrigir de passagem" de `06-context-backend.md`. No teste HTTP o middleware `ThrottleRequests` é desligado: com `CACHE_DRIVER=array` a contagem acumula entre métodos do mesmo arquivo e os últimos testes falhariam com 429; o limite que a regra garante (cooldown por e-mail) é exercitado e vem do Service. |
| TASK-286 | Concluída | 2026-09-19 | Claude | `npx tsc --noEmit` exit 0; `npx vitest run src/pages/RegisterPage.test.tsx` — 8 passed; `npx vitest run` (suíte inteira) — 39 arquivos, 258 passed; verificado no browser em `http://localhost:3000/app/cadastro` (split renderiza, 6 campos, sem erro de console) e `/app/` segue idêntico com os defaults do painel | `LoginBrandingPanel` ganhou props opcionais (`headline`/`highlight`/`description`) com os textos do login como default — o `LoginPage` não mudou de comportamento. |
