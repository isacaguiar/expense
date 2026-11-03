# Bugfix — `GET /api/expenses` está registrado, mas `ExpenseController@index` não existe (500)

Versão: 1.0 · Criado em: 20261103 · Branch: `fix/20261103-get-expenses-sem-index-no-controller`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.
>
> Origem: item 132 do backlog (`docs/backlog/get-expenses-registrado-sem-index-no-controller.md`), achado ao atualizar a Constitution §2.4 e o `01-specify.md` na TASK-559 da feature `20261030-saida-e-remocao-de-membro`. Encaminhamento escolhido pelo mantenedor em 2026-11-02: BFF, corrigindo com `->except('index')` (e não implementando um `index`).

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção só tira do `apiResource` uma rota que nunca chega ao controller (o método não existe). Nenhuma regra de acesso, middleware, Pix ou dado financeiro muda, e ninguém passa a ver nem a poder fazer algo que antes não podia: `GET /api/expenses` já é inalcançável hoje (500). A mudança está em `routes/api.php`, na rota de despesas, mas só reduz a superfície.
- [ ] **Migration ou contrato de API** — sem migration. O status de `GET /api/expenses` passa de 500 para 405, numa rota sem nenhum cliente (o frontend usa só `POST /api/expenses` e `/api/expenses/{id}`; o `app/` Flutter ainda não existe). As demais rotas do recurso (`store`, `show`, `update`, `destroy`) não mudam, e nada que respondia 2xx passa a responder outra coisa.
- [ ] **Causa raiz obscura / correção ampla** — causa lida no código e reproduzida (§1), 1 linha de produção em 1 arquivo (`routes/api.php`) mais o teste novo. Conferi que é a **única** rota registrada sem método: das 58 rotas `Controller@método` do app, as outras todas apontam para método existente (a rota do pacote Sanctum, cuja classe fica em `vendor/`, não conta).
- [ ] **Decisão de produto/arquitetura** — o item deixava duas saídas (remover a rota ou implementar um `index`); o mantenedor escolheu remover em 2026-11-02, que é também o que a Constitution §2.4 e §2.5 já prescrevem (toda rota registrada tem método). Implementar um `index` seria feature nova e fica fora deste bugfix.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** `GET /api/expenses` com um token válido responde **500** (`BadMethodCallException`) em vez de um erro de rota inexistente.
- **Reprodução** (feita em 2026-11-02, no banco de teste, **numa transação desfeita**, sem gravar nada): criar um usuário com a factory, gerar o token com `auth('api')->login($user)` e despachar `GET /api/expenses` com `Authorization: Bearer <token>` e `Accept: application/json`. Resultado: status 500, corpo `Method App\Http\Controllers\ExpenseController::index does not exist.`, e `method_exists(ExpenseController::class, 'index')` é `false`. O corpo com a mensagem aparece porque o ambiente de teste tem `APP_DEBUG` ligado; em produção ele é `false` e a resposta não expõe o caminho do arquivo, mas o status continua 500.
- **Esperado vs. atual:** esperado, uma rota que a API não oferece responder 405 (o mesmo URI aceita `POST`), como qualquer rota inexistente; atual, 500, que parece falha do servidor, entra nos logs como erro e é acionável por qualquer usuário autenticado.
- **Causa raiz:** `backend/routes/api.php:60` registra `Route::apiResource('expenses', ExpenseController::class)`, que cria as cinco rotas `index`, `store`, `show`, `update` e `destroy`. O `ExpenseController` tem `store` (`:521`), `show` (`:118`), `update` (`:198`), `destroy` (`:453`) e `indexByGroup` (`:32`), mas **não tem `index`**. A listagem de despesas é por grupo (`GET /api/groups/{id}/expenses`, `indexByGroup`), e `GET /api/expenses` não tem implementação nenhuma no controller. Quebra a Constitution §2.4 (toda rota registrada tem método) e é um resquício do `TASK-016` do Épico C, que previa "implementar ou remover do `apiResource` os verbos não suportados".

## 2. Correção

- **O que muda e por quê:** `routes/api.php:60` passa a `Route::apiResource('expenses', ExpenseController::class)->except('index');`, que tira só a rota sem método. `GET /api/expenses` passa a responder 405, porque o URI continua existindo para `POST`. Nenhum controller muda.
- **Arquivos tocados:** `backend/routes/api.php` (1 linha de código e 2 de comentário), `backend/tests/Feature/RoutesPointToExistingControllerMethodsTest.php` (novo, sem banco), este arquivo e o índice `docs/bugfix/README.md`, e o fechamento do item 132 do backlog (`docs/backlog/concluidos/` e `docs/backlog/README.md`).
- **Teste de regressão:** `RoutesPointToExistingControllerMethodsTest`, dois casos. O **genérico** percorre as rotas registradas e, para toda ação `App\Http\Controllers\X@método`, afirma `method_exists`, listando as quebradas na mensagem: guarda a regra da Constitution §2.4 para qualquer rota futura, não só esta. O **específico** afirma que `GET /api/expenses` sem token responde 405 (hoje responde 401: a rota casa e o `jwt.auth` barra antes de chegar ao controller).
- **Riscos / efeitos colaterais:** nenhum identificado. Nada que funcionava deixa de funcionar (a rota já dava 500) e não há cliente que a chame. O teste genérico passa a apontar, no futuro, qualquer `apiResource` com método faltando, que é o efeito desejado; um controller "stub" legítimo registrado em rota ativa também seria apontado.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-11-03 | `cd backend && php artisan test --filter RoutesPointToExistingControllerMethodsTest`, com o teste novo e **antes** da correção (RED, sem banco) | `Tests: 2 failed (3 assertions)`: o caso genérico lista `GET\|HEAD api/expenses -> App\Http\Controllers\ExpenseController@index` (a única rota quebrada) e o específico recebe `401` no lugar de `405` |
| 2026-11-03 | o mesmo comando, depois de acrescentar `->except('index')` em `routes/api.php` | `Tests: 2 passed (3 assertions)` |
| 2026-11-03 | `cd backend && php artisan route:list --path=api/expenses --json`, depois da correção | restam `POST api/expenses`, `GET api/expenses/{expense}`, `PUT\|PATCH api/expenses/{expense}` e `DELETE api/expenses/{expense}`; `expenses.index` não existe mais |
| 2026-11-03 | mutação, uma por vez, rodando o mesmo comando (script que restaura os arquivos e confere que voltaram idênticos): (a) tirar o `->except('index')`; (b) rota quebrada de volta e o `method_exists` do teste trocado por `true`; (c) `->except('show')` no lugar de `->except('index')` | (a) `2 failed`; (b) `1 failed, 1 passed`: com o caso genérico inerte, o específico sozinho pega; (c) `2 failed`. **3 de 3 pegas**; arquivos restaurados idênticos |
| 2026-11-03 | reprodução real, a mesma de 2026-11-02: no banco de teste, em transação desfeita, usuário da factory com token válido e `GET /api/expenses` com `Accept: application/json`, depois da correção | `status=405`, `The GET method is not supported for route api/expenses. Supported methods: POST.` (antes: `500`, `BadMethodCallException`) |
| 2026-11-03 | `cd backend && ./vendor/bin/pint --test` | `PASS ... 205 files` (204 mais o teste novo) |
| 2026-11-03 | `cd backend && php artisan migrate:fresh --env=testing --force` (MySQL de teste recriado em `127.0.0.1:3307`, banco `ex-db`) e depois `php artisan test` (suíte inteira) | migração com 35 `DONE`, saída 0; `Tests: 981 passed (6012 assertions)`, `Duration: 144.94s`. Antes eram 979 (6009): +2 testes e +3 asserções, todos os testes existentes sem edição |
| 2026-11-03 | `git diff --stat -- frontend backend/app` | vazio: só `backend/routes/api.php` (1 linha de código e 2 de comentário), o teste novo e documentação |
