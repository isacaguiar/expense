# Implementation — Migração automática no deploy do backend

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260903

---

## 1. Desvios do fluxo padrão e pendências de gate humano

### 1.1 Pendências de gate humano (a IA para aqui — `00-constitution.md` §5.2)

1. **Incidente 2026-09-03 — encerrar agora, independe da feature.** Rodar no host de produção (`/home1/isacag00/novemax/expense/api/`):

   ```bash
   php artisan migrate:status   # deve listar 2026_09_02_000000_add_settled_at_... como Pending
   php artisan migrate --force
   ```

   Isso cria `ex_group_cycle_snapshots.settled_at` e derruba os 500 em `GET /api/groups/{id}/expenses/cycles` e `.../gross-debts`. Depois que a feature entrar em produção, essa migration já vai aparecer aplicada e o `SCRIPT_AFTER` só reporta "Nothing to migrate".

2. **Aplicar a emenda ao `00-constitution.md`** (TASK-273) — texto proposto em §1.2. Editar a Constitution é `✅ sempre` humano (§5.2). Ao aplicar: bump de `Versão` + `Última atualização` no cabeçalho do documento.

3. **Confirmar privilégio de DDL** do usuário do banco de produção (`isacag00_expense`): o `migrate` automático precisa rodar `ALTER TABLE` / `CREATE TABLE`. O `migrate` manual de 2026-09-01 rodou com esse usuário, então provavelmente já é o caso — confirmar antes de considerar o mecanismo confiável.

4. **TASK-275 — teste do caminho de falha.** Dispara `deploy-backend.yml` por `workflow_dispatch` numa branch, contra o environment `PROD`. É gate humano (aciona deploy). Detalhe do procedimento em `plan.md` §6.

5. **Merge em `dev`** (PR da feature) e **promoção `dev` → `main`** — gates humanos (§5.2). O merge em `main` dispara o 1º deploy com `migrate --force` ativo.

### 1.2 Texto proposto da emenda ao `00-constitution.md` (TASK-273 — não aplicado pela IA)

**§5.2 — tabela de gates.** Substituir a linha:

```
| Rodar migration em banco **compartilhado/produção** | ❌ | ✅ |
```

pelas duas linhas:

```
| Rodar migration **aditiva** (nova coluna nullable/com default, nova tabela) em banco compartilhado/produção | ✅ — aplicada pelo `deploy-backend.yml` (`SCRIPT_AFTER` → `php artisan migrate --force`) no deploy disparado pelo merge em `main` (ver `decisions/ADR-008`, emenda 2026-09-03) | — |
| Migration **destrutiva** (drop/rename/alterar tipo) em qualquer ambiente além do local | ❌ | ✅ — e, como o deploy aplicaria a migration sozinho ao mergear em `main`, o PR de promoção `dev` → `main` que a carrega precisa de aval humano explícito registrado |
```

(A 2ª linha já existe hoje logo abaixo da que sai; o efeito líquido é: a linha genérica "rodar migration em produção = gate humano" vira "aditiva = automática via pipeline", e a linha de destrutiva ganha a cláusula do PR de promoção. Se preferir não duplicar, fundir numa linha só "Migration destrutiva …" e deixar a aditiva coberta só pela primeira.)

**§3 — tabela de Stack, linha "Deploy backend".** De:

```
| Deploy backend | GitHub Actions → SSH/rsync porta 2222 (`easingthemes/ssh-deploy`) → `expense-api.novemax.com.br` — ver `decisions/ADR-008` |
```

para:

```
| Deploy backend | GitHub Actions → SSH/rsync porta 2222 (`easingthemes/ssh-deploy`), com `php artisan migrate --force` no `SCRIPT_AFTER` → `expense-api.novemax.com.br` — ver `decisions/ADR-008` |
```

**§4.2 (opcional).** A frase "Migration destrutiva … é ação com gate humano (Governança) antes de rodar em qualquer banco compartilhado" continua válida. Se quiser deixar explícito o novo default, acrescentar: "Migration aditiva em banco compartilhado/produção é aplicada automaticamente pelo pipeline de deploy (`ADR-008`); a destrutiva não — ver §5.2."

## 2. Log de implementação

Uma linha por task. Comando real + resultado obtido.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-271 | Concluída | 2026-09-03 | IA | Emenda a `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` — blocos `[Emenda 2026-09-03]` em Decisão, Consequências, Alternativas, Referências + linha `Emenda:` no cabeçalho. `git diff --stat` → só `ADR-008`. Índice `decisions/README.md` inalterado (formato de 3 colunas, sem data). | — |
| TASK-272 | Concluída (verificação de runtime pendente do deploy) | 2026-09-03 | IA | `.github/workflows/deploy-backend.yml` — `SCRIPT_AFTER` agora tem `php artisan migrate --force --no-interaction \|\| exit 1` entre `optimize:clear` e `config:cache`; `cd … \|\| exit 1`; `chmod … \|\| true`. Parse: `php -r` + `symfony/yaml` (`backend/vendor`) → `YAML VALID`, `SCRIPT_AFTER` parseado igual ao esperado. `git diff` restrito ao bloco `SCRIPT_AFTER`. | Runtime (job verde, migration aplicada, endpoints 200) a registrar aqui após o 1º deploy pós-merge. |
| TASK-274 | Concluída | 2026-09-03 | IA | `docs/sdd/04-implementation.md` §1, item de checklist de migration — acrescentada a cláusula do PR de promoção para migration destrutiva, citando `ADR-008` (emenda). `git diff` restrito a essa linha; sem bump de versão do documento. | Doc de processo fica levemente à frente da Constitution até a emenda de §1.2 ser aplicada (gate humano). |
| TASK-273 | Concluída (texto proposto; aplicação = gate humano) | 2026-09-03 | IA | Texto da emenda ao `00-constitution.md` §5.2/§3 (e opcional §4.2) redigido em §1.2 acima. `00-constitution.md` **não** tocado. | Aplicar = TASK do humano (§1.1 item 2). |
| TASK-275 | Pendente | — | humano | — | Gate humano — `workflow_dispatch` contra `PROD` (`plan.md` §6). |
