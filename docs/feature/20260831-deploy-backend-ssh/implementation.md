# Implementation — Deploy do backend via SSH

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260831

PR: [#106](https://github.com/isacaguiar/expense/pull/106) — `infra/20260831-deploy-backend-ssh` → `dev` (aberto 2026-08-31). Merge em `dev` e promoção `dev` → `main` = gate humano; o push em `main` dispara o deploy.

---

## 1. Desvios do fluxo padrão / pendências

Feature de infra (CI). Verificação da TASK-233 = parse de YAML + `git diff` no escopo; sem suíte automatizada que exercite deploy. Sem backend PHP nem frontend tocados. TASK-234 é 100% execução humana (probe, chaves, secrets).

**Achado durante o probe (TASK-234) — versão de PHP do `public/.htaccess`:**
o `/home1/isacag00/novemax/expense/api/public/.htaccess` no host contém só o bloco de comentário `# php -- BEGIN cPanel-generated handler` dizendo que o domínio **herda o pacote PHP** (nenhuma diretiva ativa). O `rsync` vai sobrescrever esse arquivo com o `backend/public/.htaccess` do repo (só rewrite do Laravel). Sem perda funcional hoje. **Mas**: se algum dia a versão de PHP for fixada no *MultiPHP Manager* do cPanel, o painel injeta um `AddHandler …ea-phpXX` nesse mesmo `public/.htaccess`, e o deploy seguinte apaga (o `rsync` sincroniza o arquivo). Se precisar fixar a versão, o bloco `AddHandler` tem que entrar no `backend/public/.htaccess` versionado. Verificar a versão de PHP servida logo após o 1º deploy (`expense-api.novemax.com.br` respondendo sem erro de versão).

**Pendências fora do PR desta feature:**

1. **`00-constitution.md` §3** — a linha "Deploy backend | GitHub Actions → FTP (`scd.novemax.com.br`)" fica desatualizada; passa a "GitHub Actions → SSH/rsync (porta 2222)". Editar a Constitution é **gate humano** (§5.2) → diff aprovado à parte, com bump de `Versão`/data. Não entra no PR da feature.
2. **Verificação do 1º deploy** (após merge em `main`): inspecionar o log do passo "🚀 Deploy via SSH (rsync)" — a transferência conclui e **não** aparece `ECONNREFUSED`. Em seguida, `ssh -p 2222 isacag00@162.241.203.30 'ls -la /home1/isacag00/novemax/expense/api/storage/app/comprovantes'` e confirmar que os comprovantes de produção sobreviveram ao `--delete`; e `curl -I https://expense-api.novemax.com.br` respondendo (o `api/public/` hoje não tem `index.php` — o deploy é que popula). Registrar em §2.
3. **Após o 1º deploy SSH verde**: `deploy-site.yml` e `deploy-frontend.yml` **ainda usam** `SFTP_HOST`/`SFTP_USER`/`SFTP_PASS` → **não** remover esses secrets agora. A rotação de `SFTP_PASS` (esteve em texto puro nos deploys anteriores ao `ftps`, pendência aberta no BFF `20260829-deploy-backend-ftp-texto-puro.md`) segue como gate 100% humano, independente desta feature.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-232 | Concluída | 2026-08-31 | IA (etapa de planejamento) | Criados `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` (Status "Aceita", 5 seções do formato) e a linha de índice em `docs/sdd/decisions/README.md` | ADR escrito junto com `plan.md`, antes da branch da feature. Sujeito à revisão humana no checklist pré-PR. |
| TASK-234 | Concluída | 2026-08-31 | Dono (execução humana) | Probe `ssh -p 2222 isacag00@162.241.203.30 '…'` → login **por chave OK** (sem senha); `which rsync` → `/usr/bin/rsync`, `rsync 3.2.5`; home = `/home1/isacag00`; Laravel em `/home1/isacag00/novemax/expense/api/` (faltando `artisan`/`.env`/`public/index.php` — FTP quebrado); `uapi DomainInfo single_domain_data domain=expense-api.novemax.com.br` → `documentroot: /home1/isacag00/novemax/expense/api/public` (já correto, sem pendência de painel). Par ed25519 dedicado gerado, pública autorizada no cPanel, arquivos removidos do repo. 5 secrets criados no environment `PROD`: `SSH_HOST=162.241.203.30`, `SSH_PORT=2222`, `SSH_USER=isacag00`, `SSH_PRIVATE_KEY`, `SSH_TARGET=/home1/isacag00/novemax/expense/api/`. | `rsync` presente → abordagem A confirmada, sem fallback `scp`. |
| TASK-233 | Concluída | 2026-08-31 | IA (branch `infra/20260831-deploy-backend-ssh`) | `.github/workflows/deploy-backend.yml`: passo "🚀 Deploy via FTP" (`SamKirkland/FTP-Deploy-Action@v4.3.5`) substituído por "🚀 Deploy via SSH (rsync)" (`easingthemes/ssh-deploy`, `SOURCE: "build-laravel/"`, `TARGET: ${{ secrets.SSH_TARGET }}`, `REMOTE_HOST/USER/PORT` de `secrets.SSH_*`, `ARGS: "-rltgoDzvO --delete"`, `EXCLUDE` com `/storage/app` e `/storage/logs`). `.gitignore` += `expense_deploy*`, `id_ed25519*`, `id_rsa*`, `*.ppk`. Validação: `npx --yes js-yaml .github/workflows/deploy-backend.yml` → parseia; `git diff` toca só o bloco do passo de deploy — passos de build byte a byte iguais. | Sem `pint`/`phpunit`/`tsc` (não tocou `backend/` nem `frontend/`). |
| TASK-233 (correção) | Concluída | 2026-08-31 | IA (branch `fix/20260831-ssh-deploy-action-v6`) | 1º deploy real (merge em `main`, PR #105) falhou no passo SSH: `Error: Unable to resolve action easingthemes/ssh-deploy@v5, unable to find version v5`. Causa: pin errado — a action está na major `v6` (`refs/tags/v6` confirmado na API do GitHub; release corrente `v6.0.1`), sem `v5` flutuante resolvível. Correção: `@v5` → `@v6.0.1` (pin de patch). Inputs de `v6` idênticos aos usados (`action.yml` de `v6`: `SSH_PRIVATE_KEY`, `REMOTE_HOST/USER/PORT`, `SOURCE`, `TARGET`, `ARGS`, `EXCLUDE`). `npx --yes js-yaml` → parseia; último step `easingthemes/ssh-deploy@v6.0.1`. | Deploy anterior nunca chegou a transferir arquivo — sem efeito em produção. |
