# Bugfix — Deploy do backend transmite credencial FTP em texto puro

Versão: 1.0 · Criado em: 20260829 · Branch: `fix/20260829-deploy-backend-ftp-texto-puro`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**

- [ ] **Auth / autorização / dado sensível** — rotas/controllers/middleware de auth, Pix, grupos, despesas, usuários.
- [ ] **Migration ou contrato de API** — altera schema do banco (mesmo aditivo) ou muda resposta/rota/status/payload que `frontend`/`app` consomem.
- [ ] **Causa raiz obscura / correção ampla** — causa não clara após timebox, ou > ~3 arquivos / vários módulos.
- [ ] **Decisão de produto/arquitetura** — depende de comportamento novo, ou contradiz a Constitution.

Nenhuma marcada → segue no BFF.

**Justificativa da Triagem:**

- *Auth / dado sensível* — o bug envolve o secret `SFTP_PASS`, mas esta caixa é sobre **rotas/controllers/middleware da aplicação** (Pix, grupos, despesas, usuários); o trigger do agent `security-reviewer` é `routes/api.php` / `app/Http/Controllers/**` / `app/Http/Middleware/**`. Aqui o arquivo tocado é um workflow de CI (`.github/workflows/deploy-backend.yml`) — não altera nenhuma lógica de autenticação/autorização da API. *(Rotacionar `SFTP_PASS` continua sendo gate humano — ver §2, fora deste PR.)*
- *Migration / contrato de API* — não. Nenhum schema, nenhuma resposta/rota/status/payload consumida por `frontend` ou `app`.
- *Causa raiz obscura / correção ampla* — causa clara: `deploy-backend.yml:92` fixa `protocol: ftp` (FTP simples, porta 21, `USER`/`PASS` em claro). Correção = trocar 1 valor para `ftps` em 1 arquivo.
- *Decisão de produto/arquitetura* — a troca `ftp` → `ftps` reusa a mesma action, a mesma porta 21 (agora com `AUTH TLS`) e os mesmos secrets; não muda o mecanismo de deploy. A alternativa mais profunda — abandonar FTP e migrar para rsync/SSH (porta 22, chave) — **essa sim** seria decisão de arquitetura e viraria `/nova-feature`; fica fora do escopo deste bug.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** no deploy do backend (`deploy-backend.yml`, job "Build Laravel app (backend)", passo "🚀 Deploy via FTP"), a action `SamKirkland/FTP-Deploy-Action@v4.3.5` conecta com `protocol: ftp` na porta 21. Usuário e senha (`secrets.SFTP_USER` / `secrets.SFTP_PASS`) e todos os arquivos do build trafegam **sem criptografia** entre o runner do GitHub Actions e o host de hospedagem (`162.241.x.x`, cPanel compartilhado). Qualquer ponto no caminho de rede vê a credencial em claro.
- **Reprodução:** disparar o workflow (push em `main` ou `workflow_dispatch`); no log verboso (`log-level: verbose`) da etapa de FTP, o handshake é FTP simples — comandos `USER` / `PASS` sem `AUTH TLS` antes.
- **Esperado vs. atual:**
  - Esperado: canal de controle **e** de dados cifrados; credencial nunca trafega em texto puro na rede (regra de segredos do `CLAUDE.md` raiz — "Nunca commitar/expor segredo em texto puro").
  - Atual: FTP plano; credencial e conteúdo em claro a cada deploy.
- **Causa raiz:** `.github/workflows/deploy-backend.yml:92` — `protocol: ftp`. O commit `beae31d70` ("explicita protocolo FTP e log verboso no deploy do backend") fixou explicitamente o modo inseguro. Os secrets têm nome `SFTP_*`, sugerindo que a intenção original era um canal seguro, mas o protocolo configurado é FTP simples.

## 2. Correção

- **O que muda e por quê:** `deploy-backend.yml:92` — `protocol: ftp` → `protocol: ftps` (FTPS explícito: negocia `AUTH TLS` na própria porta 21 antes de enviar `USER`/`PASS`). Passa a cifrar o canal de controle (credenciais) e o de dados. Mesma action, mesmos secrets, mesmo `server-dir: /api/` e `port: 21`.
- **Arquivos tocados:** `.github/workflows/deploy-backend.yml`.
- **Teste de regressão:** sem teste automatizado — é workflow de CI/infra, não há suíte que exercite o deploy. Verificação = rodar o deploy (`workflow_dispatch`) e conferir no log verboso que o handshake usa `AUTH TLS` / conexão `Secured` e que a transferência conclui com sucesso. Registrar comando + resultado no §3.
- **Riscos / efeitos colaterais:**
  - Se o certificado TLS do serviço FTP do host for self-signed ou com hostname divergente, a action pode falhar na validação TLS. Nesse caso, avaliar `protocol: ftps-legacy` (FTPS implícito, porta 990) ou escalar para a migração rsync/SSH (fora do escopo — vira `/nova-feature`).
  - **Não resolve** por si só o `ECONNREFUSED` em modo passivo já relatado (`connect ECONNREFUSED 162.241.203.30:38686`) — aquilo é firewall / anti-brute-force do host e é item separado.
  - Depois de confirmar o FTPS em produção, **rotacionar `SFTP_PASS`** (esteve exposta em texto puro em todos os deploys anteriores) e atualizar o secret no repo — **gate humano**, fora deste PR.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| <AAAA-MM-DD> | <ex.: `workflow_dispatch` de `deploy-backend.yml` + inspeção do log verboso da etapa FTP> | <ex.: handshake `AUTH TLS` / `Connection secured`, upload concluído> |
