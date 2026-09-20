# Tasks — Cadastro de usuários

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260919

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-282 | Criar migration e model da tabela `ex_user_pre_create` | backend | plan.md §1 | nenhum | Concluída |
| TASK-283 | Implementar `PreRegistrationService` com geração, reenvio e confirmação de código | backend | plan.md §2 | nenhum | Concluída |
| TASK-284 | Criar o Mailable e a view do e-mail com o código | backend | plan.md §5 | nenhum | Concluída |
| TASK-285 | Expor os três endpoints públicos de pré-cadastro (controller, FormRequests, rotas) | backend | plan.md §3, §4 | nenhum | Concluída |
| TASK-286 | Criar a página `/cadastro` com a etapa 1 (formulário) | frontend | plan.md §6 | nenhum | Concluída |
| TASK-287 | Implementar a etapa 2 (código, reenvio) e o login automático | frontend | plan.md §6, plan.md §4 | nenhum | Concluída |
| TASK-288 | Ligar os botões "Cadastre-se" do card de login e do site institucional | frontend | plan.md §7 | nenhum | Concluída |
| TASK-289 | Atualizar `01-specify.md` §2 e §3.1 com a tabela e o fluxo novos | doc | specify.md §2 | nenhum | Concluída |
| TASK-290 | Vincular a confirmação a quem submeteu o formulário (handle opaco) e limitar reenvios | backend | plan.md §10 | nenhum | Concluída |
| TASK-291 | Endurecer `confirm()`: tentativas atômicas, colisão de e-mail e limpeza do material sensível | backend | plan.md §10 | nenhum | Concluída |

## Critérios de aceite

- **TASK-282**: `php artisan migrate` roda limpo no banco local e a tabela `ex_user_pre_create` existe com as 9 colunas de `plan.md` §1 (conferir no Adminer ou via `php artisan db:table ex_user_pre_create`). `php artisan migrate:rollback --step=1` desfaz sem erro. O model `UserPreCreate` resolve a tabela — `UserPreCreate::count()` responde 0 no tinker sem exceção.

- **TASK-283**: `php artisan test --filter=PreRegistrationService` verde, cobrindo: código gerado tem 6 dígitos; `confirm` com código certo cria o `User` com `email_verified_at` preenchido, `whatsapp` gravado e `Hash::check($senhaOriginal, $user->password)` verdadeiro; código errado incrementa `attempts` sem criar usuário; 5ª tentativa errada bloqueia; `travelTo(+16 min)` faz o código expirar; linha com `consumed_at` não é reaproveitada; `resend` dentro de 60 s é recusado e fora de 60 s gera código diferente e zera `attempts`.

- **TASK-284**: `php artisan test --filter=PreRegisterCodeMail` verde, renderizando a Blade de verdade (sem `Mail::fake`, com `MAIL_MAILER=array`, como `tests/Feature/InvitationControllerMailViewsTest.php`) — pega nome de view errado. O corpo renderizado contém o código e o prazo de 15 minutos.

- **TASK-285**: `php artisan test --filter=PreRegisterController` verde, cobrindo por HTTP: `POST /api/pre-register` com e-mail que não confere → 422; senha que não confere → 422; e-mail já existente em `ex_users` → 422; telefone em formato inválido → 422; sem telefone → 200; happy path → 200 e linha gravada; segunda chamada dentro de 60 s → 429. `POST /api/pre-register/verify` com código certo → 201 com `access_token` que funciona em `GET /api/me`. Nenhuma resposta contém `name`, `whatsapp`, `password` ou o código.

- **TASK-286**: com backend e frontend rodando, navegar direto para `/app/cadastro` mostra a página com painel de marca à esquerda e os 6 campos à direita. Submeter com e-mails divergentes mostra erro no campo "Confirmar e-mail" (não um alerta genérico). Submeter válido chama `POST /api/pre-register` (conferir na aba Network) e avança para a etapa do código. `npx tsc --noEmit` sem erro e `npx vitest run` verde.

- **TASK-287**: digitar o código recebido no Mailpit (`localhost:8025`) cria o usuário, grava `accessToken` no `localStorage` e navega para `/meus-grupos` já autenticado (o dashboard carrega sem redirecionar para `/`). Código errado mostra erro e mantém a etapa. "Reenviar código" fica desabilitado por 60 s com contador visível. `npx vitest run` verde com o teste novo de `RegisterPage`.

- **TASK-288**: no card de login, "Cadastre-se" navega para `/app/cadastro` sem recarregar a página. `frontend/src/pages/LoginPage.test.tsx` passa a afirmar o destino novo e `npx vitest run` fica verde. Servindo o site (`php -S localhost:8080 -t site/public`), os três CTAs (`nav.php:23`, `index.php:60`, `index.php:127`) apontam para `/app/cadastro`.

- **TASK-289**: `docs/sdd/01-specify.md` §2 lista `ex_user_pre_create` no glossário e §3.1 descreve o fluxo de auto-cadastro ao lado dos fluxos de convite existentes, com a versão do documento incrementada. Nenhuma afirmação do arquivo contradiz o código mergeado nesta feature.

- **TASK-290**: `php artisan test --filter=PreRegistration` e `--filter=PreRegisterController` verdes com casos novos provando que (a) `POST /pre-register` devolve um `handle`; (b) `verify` com o `handle` errado é recusado sem gastar tentativa, mesmo com o código certo; (c) um pré-cadastro sobrescrito por um terceiro invalida o `handle` anterior, de modo que o código que chegou na caixa da vítima não confirma a senha do atacante; (d) `resend` sem o `handle` correto é recusado; (e) o reenvio nº 6 do mesmo pré-cadastro responde 429.

- **TASK-291**: `php artisan test --filter=PreRegistration` verde com casos novos provando que (a) `attempts` é reservado atomicamente antes do `Hash::check` — a 6ª chamada é recusada mesmo com o código certo; (b) se o `User` daquele e-mail nascer entre o `start()` e o `confirm()` (via `POST /register` ou convite), o `confirm()` responde 422 com mensagem neutra em vez de estourar `QueryException` (que interpola os bindings, incluindo o hash da senha, na mensagem logada — conferido em `vendor/laravel/framework/src/Illuminate/Database/QueryException.php:66`); (c) depois do consumo, `password`, `code_hash` e `handle_hash` da linha estão vazios; (d) falha no envio do e-mail não deixa a pessoa presa no cooldown.
