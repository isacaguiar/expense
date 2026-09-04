# Plan — Deploy do backend via SSH

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260831

---

## 1. Canal: `FTP-Deploy-Action` → `easingthemes/ssh-deploy` (`specify.md` §2.1)

No `.github/workflows/deploy-backend.yml`, **remover** o passo "🚀 Deploy via FTP":

```yaml
      - name: 🚀 Deploy via FTP
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.SFTP_HOST }}
          username: ${{ secrets.SFTP_USER }}
          password: ${{ secrets.SFTP_PASS }}
          protocol: ftps
          port: 21
          server-dir: /api/
          local-dir: build-laravel/
          log-level: minimal
```

e **colocar** no lugar (forma final, depois das correções — ver `implementation.md` §2):

```yaml
      - name: 🚀 Deploy via SSH (rsync)
        uses: easingthemes/ssh-deploy@v6.0.1
        with:
          SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
          REMOTE_HOST: ${{ secrets.SSH_HOST }}
          REMOTE_USER: ${{ secrets.SSH_USER }}
          REMOTE_PORT: ${{ secrets.SSH_PORT }}
          SSH_CMD_ARGS: "-o StrictHostKeyChecking=no -o IdentitiesOnly=yes -o PreferredAuthentications=publickey -o GSSAPIAuthentication=no"
          SOURCE: "build-laravel/"
          TARGET: ${{ secrets.SSH_TARGET }}
          ARGS: "-rltgoDzvO --delete"
          EXCLUDE: "/.git*, /tests, /storage/logs, /storage/app, /node_modules, /*.yml, /.env.example"
          SCRIPT_AFTER: |
            cd ${{ secrets.SSH_TARGET }}
            mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
            chmod -R ug+rwX storage bootstrap/cache
            php artisan optimize:clear
            php artisan config:cache
            php artisan route:cache
```

- **Pin**: `easingthemes/ssh-deploy@v6.0.1` (pin de patch, como `FTP-Deploy-Action@v4.3.5`). A tentativa inicial com a tag major flutuante `@v5` quebrou o deploy — `Error: Unable to resolve action easingthemes/ssh-deploy@v5, unable to find version v5` (a action já está na major `v6`; não há `v5` flutuante resolvível no runner). Lição: pin de patch para actions de terceiro.
- **`SSH_CMD_ARGS`**: `IdentitiesOnly=yes` (só a chave do `-i`) + `PreferredAuthentications=publickey` + `GSSAPIAuthentication=no` — sem isso o OpenSSH do runner Ubuntu tenta GSSAPI/agent/defaults antes da chave e estoura o `MaxAuthTries` baixo do HostGator (`Too many authentication failures`). Detalhe em §2.
- **`SOURCE: "build-laravel/"`** com barra final = envia o **conteúdo** da pasta, não a pasta.
- **`ARGS: "-rltgoDzvO --delete"`**: `r`/`l`/`t`/`g`/`o`/`D`/`z` + `O` (`--omit-dir-times`, evita erro de mtime de diretório em shared host). `--delete` espelha remoções (ver §4). Sem `p` — o host controla permissão via umask.
- **`SCRIPT_AFTER`** (roda no host após o rsync): cria `storage/logs` e as pastas de `storage/framework/*` (o build **e** o `EXCLUDE` tiram `storage/logs`, então ela nunca existiria no host → Laravel sem onde logar → HTTP 500), ajusta permissão, e refaz `config`/`route` cache **no destino** — o cache tem que ser gerado onde roda, com os paths reais.
- **Passo runner "🧠 Gerar caches Laravel" removido**: cacheava `config`/`route`/`view` com caminhos do runner (`/home/runner/...`), inválidos no host. O caching passou para o `SCRIPT_AFTER`.
- **O resto acima do passo não muda**: `checkout`, `setup-php`, `composer install --no-dev`, "🔐 Gerar arquivo .env", `key:generate`, "📦 Preparar diretório de build".
- **Por que a action empacotada e não `rsync` na mão**: troca de menor superfície (mesmos campos `SOURCE`/`TARGET`/`EXCLUDE`/`ARGS` da action de FTP), e `ssh-agent` + `known_hosts` já resolvidos internamente. `rsync` na mão + `shimataro/ssh-key-action` é o fallback imediato se a action falhar — mesmo `ARGS`, mesmo `EXCLUDE`, só que `run: rsync ... -e "ssh -p ${{ secrets.SSH_PORT }} -o StrictHostKeyChecking=accept-new"`.

## 2. Autenticação por chave (`specify.md` §2.2)

- **Gerar** (gate humano, máquina do dono): `ssh-keygen -t ed25519 -C "gha-deploy-backend expense" -f ./expense_deploy -N ""` — sem passphrase (CI não digita).
- **Pública** (`expense_deploy.pub`): cadastrar no cPanel → *SSH Access* → *Manage SSH Keys* → *Import Key* (colar o conteúdo), depois *Manage* → *Authorize*. Alternativa equivalente: `cat expense_deploy.pub >> ~/.ssh/authorized_keys` no host via SSH.
- **Privada** (`expense_deploy`, arquivo inteiro, incluindo as linhas `-----BEGIN OPENSSH PRIVATE KEY-----` / `-----END …-----` e a quebra final): vai para o secret `SSH_PRIVATE_KEY` (§3).
- **`known_hosts`**: `easingthemes/ssh-deploy` roda `ssh-keyscan` do host internamente — não é preciso secret de host key para a v1. Se depois quisermos travar o fingerprint, dá para pré-computar e injetar; fica fora do escopo.
- **`SSH_CMD_ARGS: "-o StrictHostKeyChecking=no -o IdentitiesOnly=yes"`**: o `-i <keyfile>` que a action passa não impede o `ssh` de oferecer antes as chaves do ssh-agent / defaults. O HostGator tem `MaxAuthTries` baixo (anti-brute-force) e derruba com `Too many authentication failures` antes de chegar na chave de deploy. `IdentitiesOnly=yes` limita o `ssh` à chave do `-i`. Mantém o `StrictHostKeyChecking=no` (default do input) porque o `known_hosts` não é pré-semeado.
- **Chave dedicada** (não reusar uma chave pessoal do dono): escopo de blast radius menor e revogação sem afetar o acesso humano.

## 3. Secrets no environment `PROD` (`specify.md` §2.3)

O job declara `environment: PROD` — os secrets vão **no environment `PROD`** (onde já vivem `SFTP_*` e `ENV_*`), não em repo-level.

| Secret | Valor | Como obter |
|---|---|---|
| `SSH_HOST` | `162.241.203.30` | resposta do HostGator |
| `SSH_PORT` | `2222` | resposta do HostGator |
| `SSH_USER` | usuário cPanel da conta | painel cPanel (canto superior / *Server Information*) |
| `SSH_PRIVATE_KEY` | conteúdo de `expense_deploy` | keygen (§2) |
| `SSH_TARGET` | caminho **absoluto** da pasta da app, com barra final — ex. `/home/<user>/expense/api/` | probe (§6): `ssh -p 2222 <user>@162.241.203.30 'readlink -f ~/expense/api; ls -la ~/expense'` |

- **`SSH_TARGET` como secret e não `env:`** — evita estampar `/home/<user>/…` no log público do Actions.
- **De onde vem o `/expense/api/`**: a conta FTP cai em `/expense/` e o backend é publicado em `server-dir: /api/` (`deploy-backend.yml` atual). A feature `20260829-deploy-topologia-unificada` fixou o docroot de `expense-api.novemax.com.br` em `/expense/api/public/`. Logo `SSH_TARGET` = a pasta `api` (um nível acima do `public/`), resolvida em caminho absoluto pelo probe.
- Criação dos 5 secrets = **gate humano** (`00-constitution.md` §5.2 "expor segredo").

## 4. `rsync --delete`: escopo e o risco dos comprovantes (`specify.md` §2.4)

`--delete` apaga no host tudo que não estiver em `build-laravel/`. Isso é **desejável** para código (some com vendor órfão após bump de dependência, com o `.ftp-deploy-sync-state.json` que a action de FTP deixava, com arquivos renomeados) — e **perigoso** para conteúdo gerado em produção.

- **Comprovantes de pagamento** ficam em `storage/app/comprovantes/{groupId}/*.ext` e `storage/app/comprovantes-settlements/*.ext`, disco `local` (`backend/config/filesystems.php` → `'local' => storage_path('app')`; `ADR-005`; `docs/feature/concluidas/202608/20260828-comprovante-storage-download/plan.md` §1). Vivem **só no filesystem do host**, não no repo. O `build-laravel/storage/app/` só tem `.gitignore`.
- Sem proteção, o **primeiro** deploy SSH com `--delete` apaga todos os comprovantes.
- **Decisão**: `EXCLUDE` inclui `/storage/app` — `rsync` não toca (nem envia, nem deleta) nada sob `storage/app` no host. Os diretórios de upload são criados sob demanda pelo Laravel (`storeAs`), então não dependem do deploy.
  - Efeito colateral aceito: `storage/app/.gitignore` e `storage/app/public/.gitignore` não são versionados pelo deploy. Num host novo, criar `storage/app/public` uma vez à mão se o disco `public` for usado (hoje o download serve pelo disco `local` via rota assinada — `public` é só legado).
- **`.env` NUNCA entra no `EXCLUDE`**: é gerado no passo "🔐 Gerar arquivo .env" dentro de `backend/`, o `rsync` local copia para `build-laravel/.env`, e precisa subir. Conferir que nenhum padrão do `EXCLUDE` casa com `.env` (os padrões são ancorados em `/` no começo; `/.env.example` não afeta `/.env`).
- **Demais exclusões** espelham o `--exclude` do passo "📦 Preparar diretório de build" (`.git*`, `tests`, `storage/logs`, `node_modules`, `*.yml`, `.env.example`). Como o `build-laravel/` já foi montado sem esses, no `rsync` eles são defesa contra `--delete` remover algo que exista no host e não no build (ex.: `storage/logs/`).
- **Verificação obrigatória no 1º deploy** (task de execução): após o deploy, `ssh` no host e confirmar que `SSH_TARGET/storage/app/comprovantes/` continua com o conteúdo de antes. Registrar no `implementation.md`.

## 5. ADR-008 (`specify.md` §2.7)

Escrito em `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` e indexado no `docs/sdd/decisions/README.md`. Status "Aceita". Cobre a troca de canal, a escolha da action, o risco `storage/app`, os `SFTP_*` órfãos e a pendência de atualizar a linha "Deploy backend" do `00-constitution.md` §3 (essa edição é gate humano à parte — **não** entra no PR desta feature).

## 6. Probe de pré-requisito (`specify.md` §2.6)

Gate humano, roda uma vez, antes de mergear:

```bash
ssh -p 2222 <user>@162.241.203.30 'which rsync && rsync --version | head -1; readlink -f ~/expense/api; ls -la ~/expense'
```

- `rsync` presente → segue a abordagem do §1.
- `rsync` ausente → cair para `appleboy/scp-action` (sem `--delete`; limpar destino é arriscado no shared host, então conviver com arquivos órfãos e abrir tarefa de limpeza manual). Registrar a troca no `plan.md` e no `ADR-008` (Consequências).
- A saída de `readlink -f ~/expense/api` e `ls -la ~/expense` fecha o valor do `SSH_TARGET` (§3).

Resultado do probe vai para o `implementation.md` §2.

## 7. Ordem de execução

Dependência técnica fraca. Ordem lógica:

1. **doc** — ADR-008 + índice (§5). *(já feito nesta etapa de planejamento)*
2. **infra** — reescrever o passo de deploy no `deploy-backend.yml` (§1). Autônomo; abre o PR contra `dev`.
3. **gate humano, fora do PR** — probe (§6), keygen + cadastro da pública (§2), criação dos 5 secrets (§3). Podem acontecer em paralelo à task 2; **têm** que estar prontos antes do merge em `main` (que dispara o deploy).
4. **verificação pós-merge** — primeiro `workflow_dispatch`/push em `main`, inspeção do log do `rsync` (transferência conclui, sem `ECONNREFUSED`), e a checagem de sobrevivência de `storage/app/comprovantes/` (§4). Registrar no `implementation.md`.
5. **gate humano, depois do 1º deploy verde** — remover secrets `SFTP_*` se nenhum outro workflow os usar (hoje `deploy-site`/`deploy-frontend` ainda usam → provavelmente **não** remover ainda) e rotacionar `SFTP_PASS`. Fora desta feature; fica anotado no `implementation.md` §1.

Gates (`00-constitution.md` §5.2): reescrever o workflow e abrir PR = autônomo. Criar secrets, editar a Constitution §3, merge em `dev` e em `main`, rotacionar `SFTP_PASS` = humano.
