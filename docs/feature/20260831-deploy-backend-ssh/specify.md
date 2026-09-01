# Specify — Deploy do backend via SSH

> Feature: troca o canal de publicação do `deploy-backend.yml` de FTP/FTPS (porta 21, modo passivo) para rsync sobre SSH (porta 2222, chave), eliminando a falha de deploy do backend. Pedido novo, encaminhado explicitamente pelo BFF `docs/bugfix/concluidos/20260829-deploy-backend-ftp-texto-puro.md` ("Se o HostGator liberar SSH: migrar de FTP para rsync/SSH → `/nova-feature` + ADR"). HostGator confirmou o acesso SSH em 2026-08-31.

Versão: 1.0 · Criado em: 20260831

---

## 1. Problema

O deploy do backend (`deploy-backend.yml`, job "Build Laravel app (backend)", passo "🚀 Deploy via FTP") **não completa**. Depois de o BFF de 2026-08-29 corrigir o texto puro (`protocol: ftp` → `ftps`), a transferência passou a cifrar o canal mas falha em seguida:

```
Error: Can't open data connection in passive mode: connect ECONNREFUSED 162.241.203.30:<porta passiva aleatória>
```

O FTP em modo passivo abre uma segunda conexão numa porta alta aleatória para os dados; o firewall / anti-brute-force do host compartilhado recusa essa porta. É limitação do protocolo FTP nesse host, não de configuração da action — qualquer solução dentro do FTP (faixa de portas passivas, `lftp` com retry) depende de o host afrouxar o firewall.

O BFF registrou o encaminhamento: **se o HostGator liberar SSH, migrar para rsync/SSH** (canal único na porta 22/2222, sem segunda conexão de dados, autenticação por chave). Em 2026-08-31 o HostGator confirmou:

- SSH liberado na **porta 2222** (não 22).
- Host: `162.241.203.30`.
- Usuário: o usuário cPanel da conta.
- Nenhuma chave pública cadastrada ainda.

Regra de segredos do `CLAUDE.md` raiz: credencial nunca em texto puro. SSH por par de chaves tira a senha (`SFTP_PASS`) do fluxo de deploy.

## 2. Requisitos

### 2.1 Canal de publicação: FTPS :21 → rsync sobre SSH :2222

No `deploy-backend.yml`, **só** o passo final de transferência muda: sai `SamKirkland/FTP-Deploy-Action@v4.3.5` (`protocol: ftps`, `port: 21`), entra `easingthemes/ssh-deploy` (rsync sobre SSH, `port: 2222`). Conexão única, sem modo passivo — elimina o `ECONNREFUSED`. Abordagem escolhida no brainstorming desta feature (padrão mais comum para cPanel/HostGator + GitHub Actions); alternativas (`rsync` na mão, `scp`/`sftp`) e o critério de fallback ficam no `plan.md`.

### 2.2 Autenticação por chave, sem senha no workflow

Par de chaves **ed25519 dedicado** a este deploy. Chave privada só como secret; chave pública cadastrada no host (cPanel → SSH Access → Manage/Import, ou `~/.ssh/authorized_keys`). O workflow não referencia mais `SFTP_PASS` nem nenhuma senha.

### 2.3 Secrets novos no environment `PROD`

`SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY` e `SSH_TARGET` (caminho absoluto do destino no host, ex. `/home/<user>/expense/api/` — como secret para não expor o home path em YAML/log). Valores preenchidos por **gate humano** (§2.8), não pela feature. Os `SFTP_HOST`/`SFTP_USER`/`SFTP_PASS` deixam de ser usados pelo `deploy-backend.yml` (ainda usados por `deploy-site.yml` e `deploy-frontend.yml` — ver §3).

### 2.4 Sincronização espelhada do artefato de build

`rsync` com `--delete` de `build-laravel/` (artefato já montado pelo passo "📦 Preparar diretório de build") para `<SSH_TARGET>`. Herdar os mesmos filtros de exclusão já aplicados na montagem do `build-laravel/` (`.git*`, `tests`, `storage/logs`, `node_modules`, `*.yml`, `.env.example`). O `.env` gerado no runner **tem** que subir — não pode entrar na lista de exclusão.

### 2.5 Passos de build inalterados

`checkout`, `setup-php`, `composer install --no-dev`, geração do `.env`, `php artisan key:generate`, `config/route/view:cache` e o `rsync` local para `build-laravel/` continuam idênticos. A feature toca um passo só.

### 2.6 Probe de pré-requisito: `rsync` no host

Confirmar que o shell SSH do host tem `rsync` (`ssh -p 2222 <user>@162.241.203.30 'which rsync && rsync --version'`). É quase certo num jailshell de cPanel, mas se estiver ausente a abordagem 2.1 não se aplica e cai no fallback documentado no `plan.md`. Ação de gate humano (§2.8), resultado registrado no `implementation.md`.

### 2.7 ADR-008

Registrar em `docs/sdd/decisions/ADR-008-*.md` a decisão de arquitetura: canal de deploy do backend passa de FTP para SSH/rsync; por que `easingthemes/ssh-deploy` e não as alternativas; consequências (novo par de chaves, novos secrets, `SFTP_*` órfãos no backend).

### 2.8 Pendências de gate humano (documentadas, não executadas pela feature)

Registrar em `implementation.md` §1 e avisar o dono:

1. Gerar o par de chaves ed25519 dedicado.
2. Cadastrar a chave pública no cPanel e autorizá-la.
3. Rodar o probe do §2.6.
4. Criar os 5 secrets do §2.3 no environment `PROD`.
5. Obter o caminho absoluto real do destino (`pwd` via SSH) para o `SSH_TARGET`.
6. Depois do primeiro deploy SSH verde: remover os secrets `SFTP_*` **se** nenhum outro workflow ainda os usar, e **rotacionar `SFTP_PASS`** (esteve em texto puro nos deploys anteriores ao `ftps`) — já era pendência aberta no BFF de 2026-08-29.

## 3. Fora de escopo desta feature

- `deploy-site.yml` e `deploy-frontend.yml` — continuam em FTP; migração deles é feature ou bug separado (o `deploy-site`/`deploy-frontend` hoje usam `protocol: ftp` em texto puro — item conhecido, não resolvido aqui).
- Remoção efetiva dos secrets `SFTP_*` e rotação de `SFTP_PASS` — ações de gate humano, apenas documentadas (§2.8), não feitas pela feature.
- Qualquer mudança no código Laravel: `APP_URL`, rotas, `URL::temporarySignedRoute`, OAuth, `config/cors.php` — tudo inalterado.
- Trocar o modelo para "servidor faz `git pull`" / cPanel Git Version Control com `.cpanel.yml`.
- Servir o backend na mesma origem do site (`expense.novemax.com.br/api`) — segue fora, como em `20260829-deploy-topologia-unificada`.
- Mudança nos passos de build do `deploy-backend.yml` (§2.5).
