# Plan — Migração automática no deploy do backend

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260903

---

## 1. `migrate --force` no `SCRIPT_AFTER` do `deploy-backend.yml` (`specify.md` §2.1)

Estado atual do passo (`.github/workflows/deploy-backend.yml:97-103`):

```yaml
          SCRIPT_AFTER: |
            cd ${{ secrets.SSH_TARGET }}
            mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
            chmod -R ug+rwX storage bootstrap/cache
            php artisan optimize:clear
            php artisan config:cache
            php artisan route:cache
```

Forma proposta:

```yaml
          SCRIPT_AFTER: |
            cd ${{ secrets.SSH_TARGET }} || exit 1
            mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
            chmod -R ug+rwX storage bootstrap/cache || true
            php artisan optimize:clear
            php artisan migrate --force --no-interaction || exit 1
            php artisan config:cache
            php artisan route:cache
```

- **Posição**: logo depois de `optimize:clear` (que limpa cache de config/rota/view — `migrate` roda com config fresca lida do `.env`) e **antes** de `config:cache`/`route:cache` (`specify.md` §2.1). Se a migration falhar, o deploy para antes de gerar cache — nada de cachear em cima de um schema meio-aplicado.
- **`--force`**: obrigatório — com `APP_ENV=production` o `migrate` aborta pedindo confirmação interativa, que não existe no shell do `SCRIPT_AFTER`. `--no-interaction` é redundante com `--force` aqui, mantido explícito.
- **`|| exit 1` só na linha do `migrate`** (e no `cd`): encerra o script na hora se o `migrate` retornar ≠ 0 — `config:cache`/`route:cache` não rodam e o script sai não-zero (ver §2). Não se usa `set -e` global para não mudar o comportamento das outras linhas.
- **`chmod ... || true`**: preserva o comportamento de hoje (erro de `chmod` em arquivo de outro dono no shared host é ignorado) — sem isso, com o script mais rígido, um `chmod` parcial abortaria o deploy à toa.
- **`php` no PATH**: o `SCRIPT_AFTER` atual já roda `php artisan optimize:clear`/`config:cache`/`route:cache` com sucesso em produção (backend no ar desde 2026-09-01), então `php` resolve no shell não-interativo do jailshell. Ainda assim, o log do 1º deploy com o passo novo é ponto de verificação (§6).
- **Nada mais no workflow muda**: `checkout`, `setup-php`, `composer install --no-dev`, geração do `.env`, `key:generate`, montagem do `build-laravel/`, e o próprio passo `easingthemes/ssh-deploy` (rsync, `EXCLUDE`, `ARGS`) — tudo intacto. `ADR-008` não é reaberto.

## 2. Falha da migration falha o job (`specify.md` §2.2)

O objetivo: `migrate --force` com exit ≠ 0 ⇒ job do Actions **vermelho**, deploy considerado falho.

- **Mecanismo primário**: o `|| exit 1` do §1 faz o script remoto do `SCRIPT_AFTER` terminar com código ≠ 0. `easingthemes/ssh-deploy` roda esse script via `ssh` — o exit code do `ssh` é o da última linha executada; com `|| exit 1` na linha do `migrate`, a última linha executada num cenário de falha é o próprio `exit 1`.
- **Risco conhecido**: se a versão fixada da action **não** propagar o exit code do `SCRIPT_AFTER` para o passo (engolir a falha), o job ficaria verde mesmo com `migrate` quebrado — o pior cenário, idêntico ao de hoje. Isso **tem que ser verificado** no 1º deploy (§6), não presumido.
- **Fallback se a action engolir o exit code**: tirar o `migrate` do `SCRIPT_AFTER` e rodá-lo num passo dedicado logo após o de deploy:

  ```yaml
        - name: 🗃️ Migrations (produção)
          uses: appleboy/ssh-action@v1.2.0
          with:
            host: ${{ secrets.SSH_HOST }}
            username: ${{ secrets.SSH_USER }}
            port: ${{ secrets.SSH_PORT }}
            key: ${{ secrets.SSH_PRIVATE_KEY }}
            script_stop: true
            script: |
              cd ${{ secrets.SSH_TARGET }}
              php artisan migrate --force --no-interaction
  ```

  `appleboy/ssh-action` com `script_stop: true` falha o passo no primeiro comando com erro — comportamento garantido. Custo: mais uma action de terceiro no CI (pin por patch), e o `config:cache`/`route:cache` do `SCRIPT_AFTER` passariam a rodar **antes** desse passo (ordem invertida em relação ao §1) — aceitável, porque `config:cache` não depende de schema; se quiser manter a ordem "migrate antes de cache", move-se também os dois `*:cache` para esse passo dedicado. Decidir só se o teste do §6 mostrar que precisa.
- **Janela "código novo + schema velho"**: o rsync roda antes do `SCRIPT_AFTER`, então se o `migrate` falha o host fica com código novo e schema antigo até intervenção manual. É inerente a deploy sem blue-green e está aceito no `specify.md` §2.2/§2.4 — a mitigação é a migration falhar cedo e ruidosamente (job vermelho + alerta), não meio-aplicar em silêncio. Rollback automático está fora de escopo (`specify.md` §3).

## 3. Barra contra migration destrutiva: convenção humana (`specify.md` §2.3)

Sem check no pipeline. A regra "migration destrutiva (`->drop*()`, `->renameColumn()`, `->change()`, `Schema::drop*`, `DB::statement` de DDL destrutivo) não entra em `main` sem aval humano explícito registrado no PR de promoção `dev` → `main`" fica documentada em três lugares:

1. **Emenda ao `ADR-008`** (§4) — no corpo da decisão e nas consequências.
2. **Emenda ao `00-constitution.md`** §5.2 / §3 (§5) — gate humano.
3. **`docs/sdd/04-implementation.md` §1**, checklist "antes de integrar": a linha que hoje é *"Se a task envolve migration: é aditiva? Se destrutiva, o gate humano da task foi respeitado…"* ganha um complemento — *"…e, como o deploy agora aplica migrations automaticamente ao mergear em `main`, uma migration destrutiva só vai para `main` com o aval humano explícito registrado no PR de promoção"*.

Descartado o check automático (grep de padrões destrutivos em migrations pendentes que falha o deploy): peça a mais no pipeline, detecção heurística com falso positivo/negativo, volume atual de migrations não justifica. Se algum dia passar batido, vira item de `docs/backlog/` para reavaliar.

## 4. Emenda ao ADR-008 (`specify.md` §2.4)

Editar `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` (não criar ADR novo — mesma área: o que o `deploy-backend.yml` faz no host via `SCRIPT_AFTER`; o ADR já discute esse passo em "Consequências"). Acrescentar:

- **Na "Decisão"**: o `SCRIPT_AFTER` passa a rodar `php artisan migrate --force --no-interaction`, antes de `config:cache`/`route:cache`, com `|| exit 1` para falhar o deploy em erro de migration.
- **Em "Consequências"**:
  - Código e schema de produção passam a subir juntos no mesmo deploy — fecha a janela que causou o incidente de 2026-09-03 (`settled_at` ausente → 500 em `cycleHistory`/`grossDebts`).
  - Perde-se o ponto de inspeção humana antes de cada `migrate` em produção (era gate de `§5.2`).
  - Nova janela de risco: `migrate` falhando depois do rsync deixa host com código novo + schema velho até intervenção manual; sem rollback automático.
  - Migration destrutiva passa a depender do reviewer do PR de promoção (convenção, §3) — não há trava técnica.
  - Depende de o usuário do banco de produção ter privilégio de DDL (`ALTER TABLE` etc.).
  - `00-constitution.md` §5.2 (linha "Rodar migration em banco compartilhado/produção") e §3 (linha "Deploy backend") ficam desatualizadas — **editar a Constitution é gate humano à parte** (§5), não entra no PR desta feature.
- **Em "Alternativas consideradas"**: guarda fail-fast (check read-only que só aborta o deploy com migration pendente, mantendo `migrate` manual); manter 100% manual (status quo, é a causa do incidente); check automático de migration destrutiva no pipeline (preterido por convenção humana).
- Atualizar a nota/data do ADR-008 no índice `docs/sdd/decisions/README.md` se o formato do índice pedir.

## 5. Emenda ao `00-constitution.md` — gate humano (`specify.md` §2.5)

**Não executada pela feature** — editar a Constitution é `✅ sempre` humano (`00-constitution.md` §5.2). A feature entrega o **texto proposto** da emenda em `implementation.md` §1, para um passo humano aplicar (com bump de versão + data), como já foi feito para o §3 no `ADR-008`.

- **§5.2, tabela de gates** — a linha:

  | Rodar migration em banco **compartilhado/produção** | ❌ | ✅ |

  passa a (proposta):

  | Rodar migration **aditiva** em banco compartilhado/produção | ✅ — aplicada pelo `deploy-backend.yml` (`SCRIPT_AFTER`, `migrate --force`) no deploy disparado pelo merge em `main` | — |
  | Rodar migration **destrutiva** (drop/rename/alterar tipo) em qualquer ambiente além do local | ❌ | ✅ — o PR de promoção `dev` → `main` que a carrega precisa de aval humano explícito registrado (o pipeline aplicaria sozinho) |

  (a 2ª linha já existe em §5.2; o acréscimo é a cláusula sobre o PR de promoção.)
- **§3, linha "Deploy backend"** — acrescentar que o deploy aplica migrations: *"GitHub Actions → SSH/rsync porta 2222 (`easingthemes/ssh-deploy`), com `migrate --force` no `SCRIPT_AFTER` → `expense-api.novemax.com.br` — ver `decisions/ADR-008`"*.
- **§4.2** (migrations aditivas por padrão; destrutiva = gate humano) — não muda; continua sendo a base da convenção do §3 desta feature.

## 6. Verificação (`specify.md` §2.2, §2.6)

- **1º deploy com o passo novo** (após merge em `main`): no log do job, confirmar — (a) `php artisan migrate --force` executou e reportou as migrations aplicadas (ou "Nothing to migrate"); (b) `php` resolveu no shell (sem `command not found`); (c) `config:cache`/`route:cache` rodaram depois; (d) job verde. Registrar no `implementation.md` §2.
- **Caminho de falha** (o mais importante): confirmar que `migrate` com erro derruba o job. Como não dá para depender de uma falha real acontecer, o teste é deliberado numa branch de `dev`: uma migration proposital que quebra (ex.: `DB::statement('SELECT 1/0')` no `up()`), deploy dessa branch para um ambiente/execução controlada **ou** um `workflow_dispatch` apontando para ela, e verificar que o job fica vermelho no passo do `migrate`. Se ficar verde → aplicar o fallback do §2 (passo `appleboy/ssh-action`). Descartar a migration de teste depois. Registrar resultado no `implementation.md` §2.
- **Sanidade dos endpoints do incidente**: depois do 1º deploy verde com a migration `2026_09_02_000000_add_settled_at...` aplicada, `GET /api/groups/{id}/expenses/cycles` e `.../gross-debts` respondem 200. (Se o incidente já tiver sido fechado à mão antes — §7.0 — a migration aparece como já aplicada e o passo é só "Nothing to migrate".)

## 7. Ordem de execução

Dependência técnica fraca entre os itens; o incidente é urgente e independente.

0. **Gate humano, imediato e fora desta feature** — rodar `php artisan migrate --force` no host de produção para criar `settled_at` e derrubar os 500 (`specify.md` §2.6.1). Não espera nada do resto.
1. **doc — emenda ao `ADR-008`** (§4) + índice. Autônomo.
2. **infra — editar o `SCRIPT_AFTER` do `deploy-backend.yml`** (§1 + §2, forma primária com `|| exit 1`). Autônomo; entra no PR da feature contra `dev`.
3. **doc — texto proposto da emenda à Constitution** (§5) em `implementation.md` §1 + complemento no checklist de `docs/sdd/04-implementation.md` §1 (§3). Redigir é autônomo; **aplicar na Constitution é humano**.
4. **gate humano, antes do merge em `main`** — confirmar privilégio de DDL do usuário do banco de produção (`specify.md` §2.6.3); aplicar a emenda à Constitution (passo 3).
5. **verificação pós-merge** (§6) — 1º deploy: job verde, migration aplicada, endpoints do incidente em 200. Registrar no `implementation.md` §2.
6. **verificação do caminho de falha** (§6) — teste deliberado da migration que quebra; decidir sobre o fallback do §2. Registrar no `implementation.md` §2.

Gates (`00-constitution.md` §5.2): editar workflow e docs + abrir PR contra `dev` = autônomo. Rodar `migrate` no host (passo 0), editar a Constitution, merge em `dev` e em `main` = humano.
