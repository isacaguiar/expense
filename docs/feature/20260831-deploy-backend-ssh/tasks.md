# Tasks — Deploy do backend via SSH

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260831

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-232 | Escrever `ADR-008` (troca do canal de deploy do backend FTP → SSH/rsync) e indexá-lo no `decisions/README.md` | doc | plan.md §5 | nenhum | Concluída (2026-08-31) |
| TASK-233 | Reescrever o passo de publicação do `deploy-backend.yml`: remover `SamKirkland/FTP-Deploy-Action`, adicionar `easingthemes/ssh-deploy` (rsync sobre SSH :2222, `--delete`, `EXCLUDE` com `/storage/app`) | infra | plan.md §1, §4 | antes do deploy (merge em `main`) | Concluída (2026-08-31) |
| TASK-234 | Provisionar o acesso SSH de deploy (execução humana): rodar o probe, gerar o par ed25519 dedicado, cadastrar a chave pública no cPanel, criar os 5 secrets `SSH_*` no environment `PROD` | infra | plan.md §2, §3, §6 | antes do deploy (merge em `main`); toca criação de segredo | Concluída (2026-08-31) |

## Critérios de aceite

- **TASK-232**: `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` existe com Status "Aceita" e as seções do formato de `decisions/README.md` (Contexto, Decisão, Consequências, Alternativas consideradas, Referências). O índice em `docs/sdd/decisions/README.md` tem a linha `ADR-008`. As referências cruzadas resolvem: BFF `20260829-deploy-backend-ftp-texto-puro.md`, `00-constitution.md` §3/§5.1/§5.2/§6.1, `ADR-005`, `ADR-006`.

- **TASK-233**: em `.github/workflows/deploy-backend.yml` —
  - o passo `- name: 🚀 Deploy via FTP` (com `SamKirkland/FTP-Deploy-Action@v4.3.5`) **não existe mais**;
  - existe um passo `- name: 🚀 Deploy via SSH (rsync)` com `uses: easingthemes/ssh-deploy@v5` (tag major, como `checkout@v4`/`setup-php@v2` no mesmo repo), `SOURCE: "build-laravel/"`, `TARGET: ${{ secrets.SSH_TARGET }}`, `REMOTE_HOST/USER/PORT` vindos de `secrets.SSH_HOST/SSH_USER/SSH_PORT`, `SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}`, `ARGS: "-rltgoDzvO --delete"`;
  - o `EXCLUDE` contém `/storage/app`, `/storage/logs` e `/.env.example`, e **não** contém `/.env` nem padrão que case com `.env` na raiz;
  - o arquivo não referencia mais `SFTP_HOST`/`SFTP_USER`/`SFTP_PASS`/`protocol:`/`server-dir:`/`port: 21`;
  - `git diff` mostra alteração **só** no bloco do passo de deploy — os passos de build (checkout → "📦 Preparar diretório de build") ficam byte a byte iguais;
  - o YAML faz parse sem erro e sem tabs (`python -c "import yaml; yaml.safe_load(open('.github/workflows/deploy-backend.yml'))"` ou equivalente).

- **TASK-234**: registrado no `implementation.md` §2 —
  - saída do probe colada: `which rsync` + `rsync --version | head -1` + `readlink -f ~/expense/api` + `ls -la ~/expense` (via `ssh -p 2222 <user>@162.241.203.30 '…'`);
  - se `rsync` **não** existir no host: `plan.md` §1/§6 e `ADR-008` (Consequências) atualizados para o fallback `appleboy/scp-action` sem `--delete`; caso exista, nada a alterar;
  - par `expense_deploy` / `expense_deploy.pub` gerado (ed25519, `-N ""`); a pública consta em cPanel → SSH Access → Manage SSH Keys como **Authorized**;
  - em GitHub → Settings → Environments → `PROD`, existem os secrets `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_TARGET` (conferidos por nome/contagem — o valor não é exibível);
  - o valor de `SSH_TARGET` é caminho absoluto e termina em `/api/` (com barra final).

> Dependência: TASK-233 e TASK-234 são independentes entre si e podem correr em paralelo. **Ambas** têm que estar prontas antes do merge em `main` (que dispara o deploy). TASK-232 não bloqueia nenhuma.
