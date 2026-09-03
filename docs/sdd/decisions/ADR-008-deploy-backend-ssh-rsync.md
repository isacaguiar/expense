# ADR-008: Deploy do backend via SSH/rsync em vez de FTP

Status: Aceita
Data: 2026-08-31
Emenda: 2026-09-03 — o `SCRIPT_AFTER` passa a rodar `php artisan migrate --force` (feature `docs/feature/20260903-deploy-backend-migracao-automatica/`). Blocos marcados **[Emenda 2026-09-03]** abaixo.

## Contexto

O `deploy-backend.yml` publica o Laravel com `SamKirkland/FTP-Deploy-Action`. O
BFF `docs/bugfix/concluidos/20260829-deploy-backend-ftp-texto-puro.md` já tirou o
texto puro (`protocol: ftp` → `ftps`), mas a transferência **não completa**:

```
Error: Can't open data connection in passive mode: connect ECONNREFUSED 162.241.203.30:<porta alta aleatória>
```

O FTP em modo passivo abre uma segunda conexão numa porta alta aleatória para os
dados; o firewall / anti-brute-force do host compartilhado recusa essa porta.
É limitação do protocolo FTP nesse host — qualquer saída dentro do FTP (faixa de
portas passivas fixa, `lftp` com retry) depende de o provedor afrouxar o firewall.

Aquele BFF registrou o encaminhamento condicional: *"Se o HostGator liberar SSH:
migrar de FTP para rsync/SSH (porta 22, chave) → `/nova-feature` + ADR (canal
único, sem modo passivo)."* Em 2026-08-31 o HostGator confirmou:

- SSH liberado na **porta 2222** (não 22).
- Host `162.241.203.30`, usuário = usuário cPanel da conta.
- Nenhuma chave pública cadastrada ainda.

`00-constitution.md` §3 trava a linha "Deploy backend | GitHub Actions → FTP".
Trocar o canal é decisão de Governança (§5.1) — não correção silenciosa —, por
isso este ADR.

Nota: `ADR-006` (2026-08-29) assumiu "hospedagem compartilhada, **sem SSH**" como
restrição de projeto. O SSH agora disponível **não** reabre o ADR-006, mas
destrava a evolução que ele adiou (fila durável com `queue:work` por cron do
cPanel) — fora do escopo desta decisão.

## Decisão

O canal de publicação do **backend** passa de FTP/FTPS (porta 21, modo passivo)
para **rsync sobre SSH** (porta 2222), via a action **`easingthemes/ssh-deploy`**,
fixada por tag de patch (estilo `FTP-Deploy-Action@v4.3.5`).

- **Autenticação por par de chaves ed25519 dedicado** a este deploy: privada só
  como secret `SSH_PRIVATE_KEY`; pública cadastrada no cPanel (SSH Access). Nenhuma
  senha no workflow.
- **Secrets novos no environment `PROD`**: `SSH_HOST`, `SSH_PORT`, `SSH_USER`,
  `SSH_PRIVATE_KEY`, `SSH_TARGET` (caminho absoluto da pasta da app no host, como
  secret para não estampar o home path no log). Preenchimento = gate humano.
- **Escopo: só `deploy-backend.yml`.** `deploy-site.yml` e `deploy-frontend.yml`
  seguem em FTP por ora (funcionam; não são urgentes).
- **Sincronização espelhada**: `rsync` com `--delete` de `build-laravel/` →
  `SSH_TARGET`, com `EXCLUDE` cobrindo os mesmos filtros do passo de montagem do
  `build-laravel/` **e** `storage/app` (uploads de produção — ver Consequências).
- **Só o passo final de transferência muda.** Setup PHP, `composer install`,
  geração do `.env`, `key:generate`, caches e o `rsync` local para `build-laravel/`
  ficam idênticos.
- **[Emenda 2026-09-03] O `SCRIPT_AFTER` aplica migrations.** Entre
  `php artisan optimize:clear` e `php artisan config:cache`/`route:cache`, roda
  `php artisan migrate --force --no-interaction || exit 1`. O `|| exit 1` encerra
  o script — e o deploy — se a migration falhar, sem gerar cache em cima de um
  schema meio-aplicado. `--force` é obrigatório: com `APP_ENV=production` o
  `migrate` aborta pedindo confirmação interativa, que não existe no shell do
  `SCRIPT_AFTER`. Antes desta emenda, aplicar migration em produção era passo
  manual por SSH e **gate humano** (`00-constitution.md` §5.2) — o que abriu a
  janela do incidente de 2026-09-03 (código lendo `ex_group_cycle_snapshots.settled_at`
  no ar sem a migration `2026_09_02_000000_add_settled_at…` ter rodado → HTTP 500
  em `cycleHistory`/`grossDebts`). Ver Consequências e Alternativas.

- Elimina o modo passivo → resolve o `ECONNREFUSED`; o deploy do backend volta a
  completar. Canal único (uma conexão TCP na 2222), autenticado por chave.
- `SFTP_PASS` sai do fluxo de deploy do backend. Continua em uso por
  `deploy-site.yml` / `deploy-frontend.yml` até eles migrarem — só depois disso é
  possível remover os secrets `SFTP_*` e **rotacionar `SFTP_PASS`** (esteve em
  texto puro nos deploys anteriores ao `ftps`). Rotação/remoção = gate 100% humano
  (§5.2, §6.1); já era pendência aberta no BFF de 2026-08-29.
- **Novo material sensível**: um par de chaves SSH dedicado. Comprometimento da
  privada = shell na conta de hospedagem — resposta é trocar a chave no cPanel e
  no secret. A privada nunca sai do secret; a pública pode ser recadastrada à
  vontade.
- **`rsync --delete` é destrutivo por design**: apaga no host tudo que não está no
  `build-laravel/`. Os **comprovantes de pagamento** vivem em
  `storage/app/comprovantes/{groupId}/…` e `storage/app/comprovantes-settlements/…`
  no disco `local` (`config/filesystems.php`; `ADR-005`), **só no filesystem do
  host**, fora do repo. Sem `storage/app` no `EXCLUDE`, o primeiro deploy SSH
  apaga todos os comprovantes. Por isso `storage/app` entra no `EXCLUDE` e a
  sobrevivência de `SSH_TARGET/storage/app/comprovantes/` é ponto de verificação
  obrigatório do primeiro deploy. `.env` **nunca** entra no `EXCLUDE` (é gerado no
  runner e precisa subir).
- Dependência de `rsync` no shell do host (jailshell de cPanel normalmente tem).
  Confirmada por probe antes do merge; se ausente, cai para `scp`/`sftp` (sem sync
  incremental nem mirror-delete) — decisão fica no `plan.md`, não reabre este ADR.
- Mais uma action de terceiro no supply chain de CI (`easingthemes/ssh-deploy`),
  além da `SamKirkland/FTP-Deploy-Action` que permanece nos outros dois workflows.
  Pin por tag de patch (ou SHA) reduz o risco.
- `00-constitution.md` §3, linha "Deploy backend", fica desatualizada — passa a
  ser "GitHub Actions → SSH/rsync (porta 2222)". **Editar a Constitution é gate
  humano (§5.2)**: este ADR registra a decisão; a linha do §3 é atualizada num
  passo humano à parte (bump de versão + data).
- O canal SSH da conta, uma vez estabelecido, pode ser reusado depois (ex.:
  `queue:work` disparado por cron do cPanel — evolução citada no ADR-006) sem novo
  ADR, desde que continue sendo "deploy/infra pela mesma conta via SSH".

**[Emenda 2026-09-03] — `migrate --force` no `SCRIPT_AFTER`:**

- **Código e schema de produção passam a subir juntos** no mesmo deploy. Fecha a
  janela em que um merge em `main` publicava código que referencia schema ainda
  não migrado (causa do incidente de 2026-09-03).
- **Perde-se o ponto de inspeção humana** antes de cada `migrate` em produção —
  era o gate de `00-constitution.md` §5.2. A contrapartida é a migration falhar
  cedo e ruidosamente (job vermelho) em vez de o código quebrar em runtime.
- **Nova janela de risco**: o `rsync` roda antes do `SCRIPT_AFTER`, então se o
  `migrate` falhar o host fica com código novo + schema velho até intervenção
  manual. Sem rollback automático (fora de escopo). Mitigação: `|| exit 1` +
  alerta de job vermelho.
- **Migration destrutiva** (`drop`/`rename`/alterar tipo) continua exigindo aval
  humano — por **convenção**, não por trava técnica: o reviewer do PR de promoção
  `dev` → `main` é responsável por barrar (registrado em `00-constitution.md`
  §5.2/§4.2 e no checklist de `docs/sdd/04-implementation.md` §1). Descartado um
  check automático no pipeline — ver Alternativas.
- **Depende de o usuário do banco de produção ter privilégio de DDL** (`ALTER
  TABLE` etc.). O `migrate` manual de 2026-09-01 rodou com esse usuário, então na
  prática já é o caso; confirmado como pendência de gate humano na feature.
- **`00-constitution.md` §5.2** (linha "Rodar migration em banco compartilhado/
  produção") e **§3** (linha "Deploy backend") ficam desatualizadas. **Editar a
  Constitution é gate humano** (§5.2): esta emenda registra a decisão; as linhas
  do §5.2/§3 são atualizadas num passo humano à parte (bump de versão + data),
  como já foi feito para o §3 quando este ADR foi criado.

## Alternativas consideradas

- **Continuar no FTP com faixa de portas passivas fixa / `lftp` + retry inline**:
  fica refém de o provedor abrir a faixa passiva e afrouxar o anti-hammer, e
  mantém o canal duplo (controle + dados) frágil. Rejeitada — SSH remove a classe
  inteira do problema.
- **`rsync` na mão + `shimataro/ssh-key-action`** (a action só instala a chave e o
  `known_hosts`; o `rsync` é um passo explícito): controle total das flags, uma
  dependência a menos. Igualmente válida e mantida como **fallback imediato** se a
  `easingthemes/ssh-deploy` der problema. Não escolhida como primária só porque a
  action empacotada é troca mais próxima de 1:1 pela `FTP-Deploy-Action` (mesmos
  campos `SOURCE`/`TARGET`/`EXCLUDE`/`ARGS`) e já resolve `ssh-agent`/`known_hosts`.
- **`scp`/`sftp` (`appleboy/scp-action`)**: não exige `rsync` no host, mas sobe
  tudo a cada deploy e não tem mirror-delete seguro num docroot compartilhado.
  Reservada como fallback **só** se o probe mostrar ausência de `rsync`.
- **`appleboy/ssh-action` + `git pull` no servidor**: inverte o modelo (host
  puxa), exige git + credencial de repositório no host e `composer install` +
  caches rodando no shared host a cada deploy. Mais peças, mais lento, e o `.env`
  passaria a viver no servidor. Rejeitada.
- **cPanel "Git Version Control" + `.cpanel.yml`**: nativo do HostGator, mas
  também "host puxa", com deploy hook limitado e sem o pipeline de build do
  Actions (setup PHP, `--no-dev`, cache de config/rota/view). Rejeitada — perde o
  que o workflow atual já faz bem.
- **Migrar os 3 workflows (backend + site + frontend) agora**: mesmo mecanismo,
  mesmos secrets — tentador. Mas o backend é o único quebrado e urgente;
  site/frontend funcionam. Fatiar reduz o raio de explosão de um deploy ainda não
  validado. Site/frontend viram feature/bug própria.
- **Reusar os secrets `SFTP_*`**: os nomes `SFTP_*` carregam `server`/`user`/
  `password` de FTP; SSH usa `host`/`port`/`user`/`chave privada`/`target`.
  Sobrepor semântica confunde e atrapalha a remoção limpa depois. Secrets novos
  `SSH_*`.

**[Emenda 2026-09-03] — sobre automatizar migration:**

- **Guarda fail-fast** (o CI roda só `php artisan migrate:status` e **aborta** o
  deploy se houver migration pendente, mantendo o `migrate` manual e o gate
  humano): resolveria o incidente sem tirar a inspeção humana, mas mantém um passo
  manual obrigatório a cada migration e trava o deploy até alguém rodar à mão.
  Rejeitada — a automação total é mais simples e o risco (shared host, app
  pequeno) é aceitável.
- **Manter 100% manual** (status quo): rejeitada — é a causa do incidente de
  2026-09-03.
- **Check automático de migration destrutiva no pipeline** (grep por `->drop`,
  `->dropColumn`, `->renameColumn`, `->change`, `Schema::drop*`, DDL em
  `DB::statement` nas migrations pendentes, falhando o deploy se achar): rejeitada
  em favor de convenção humana (reviewer do PR de promoção). Peça a mais no
  pipeline, detecção heurística com falso positivo/negativo, e o volume/ritmo
  atual de migrations não justifica. Reavaliável (candidato a `docs/backlog/`) se
  algum dia passar batido.

## Referências

- `docs/feature/20260831-deploy-backend-ssh/` — `specify.md`, `plan.md`, `tasks.md`.
- `docs/bugfix/concluidos/20260829-deploy-backend-ftp-texto-puro.md` — §2
  "Riscos", "Resolução" (encaminhamento condicional para SSH).
- `00-constitution.md` §3 (Stack — linha "Deploy backend"), §5.1 (decisão de
  stack vira ADR), §5.2 (gates: editar Constitution; rotacionar/expor/remover
  segredo; deploy; merge em `main`), §6.1.
- `docs/sdd/decisions/ADR-005-download-arquivo-signed-url.md` e
  `docs/feature/20260828-comprovante-storage-download/plan.md` §1 — comprovantes
  no disco `local` (`storage/app/comprovantes/…`).
- `docs/sdd/decisions/ADR-006-whatsapp-meta-cloud-api.md` — restrição "sem SSH"
  agora superada; evolução fila+cron destravada.
- `.github/workflows/deploy-backend.yml` — passo "🚀 Deploy via FTP".
- **[Emenda 2026-09-03]** `docs/feature/20260903-deploy-backend-migracao-automatica/`
  — `specify.md`, `plan.md`, `tasks.md` (TASK-271 a TASK-275). Incidente de
  origem: 500 em `GET /api/groups/{id}/expenses/cycles` e `.../gross-debts` por
  `settled_at` ausente em produção.
