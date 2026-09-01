# Bugfix — Correções de defeito em andamento (BFF, Bug-Fix Flow)

> Fluxo leve para **corrigir defeito em comportamento já existente**, paralelo ao SDD-por-feature (`docs/feature/`). Um arquivo por bug, um PR por bug. Definido em `docs/sdd/decisions/ADR-004-fluxo-bugfix.md`; exceção registrada na Constitution §5.1 (itens 1 e 1.2).

## Quando usar o BFF (e quando não usar)

Use o BFF quando o pedido é **corrigir algo que já deveria funcionar e não funciona** — e a Triagem abaixo não marca nenhuma caixa.

Marque a Triagem antes de começar. **Qualquer caixa marcada tira o trabalho do BFF** — ele vira uma feature SDD normal (`/nova-feature`, ou `/promover-backlog` se já existir item de backlog):

1. **Toca auth / autorização / dado sensível** — rotas, controllers ou middleware de autenticação/autorização; Pix; grupos; despesas; usuários. (São as "regras que nunca devem ser ignoradas" do `CLAUDE.md` raiz; mexer aí também aciona o agent `security-reviewer`.)
2. **Exige migration ou muda contrato de API** — alterar schema do banco (mesmo coluna nullable aditiva), ou mudar formato de resposta / rota / status code / payload que o `frontend` ou o `app` consomem.
3. **Causa raiz obscura ou correção ampla** — depois de um timebox de investigação a causa ainda não está clara, ou a correção passa de ~3 arquivos / toca vários módulos.
4. **Exige decisão de produto ou arquitetura** — a correção "certa" depende de escolher um comportamento novo, ou contradiz a Constitution.

Não use o BFF para desenvolvimento novo (feature, endpoint novo, tela nova) — isso é `docs/feature/` desde o começo. Não use para débito técnico não-bloqueante que ninguém vai executar agora — isso é `docs/backlog/`.

**Escalar no meio do caminho é esperado.** Se um gatilho da Triagem só aparecer durante a correção, pare: crie a pasta `docs/feature/<AAAAMMDD>-<slug>/` (`/nova-feature`), mova o conteúdo relevante, e deixe no arquivo de bugfix só um ponteiro para a feature. O caminho só sobe, nunca desce.

## Formato

Um arquivo por bug, nome `<AAAAMMDD>-<slug>.md` (data de criação + slug curto em kebab-case, sem acentos). Use `docs/bugfix/templates/bugfix.template.md` ou o slash command `/novo-bug <slug>`.

Estrutura (detalhe no template):

- **Cabeçalho** — versão, data de criação, branch `fix/<AAAAMMDD>-<slug>`.
- **Triagem** — as 4 caixas acima, marcadas ou não. Ficam no arquivo mesmo desmarcadas: é o registro de por que o bug foi elegível ao fluxo leve.
- **§1 Problema** — sintoma, passos de reprodução, comportamento esperado vs. atual, causa raiz (com `arquivo:linha` do código real).
- **§2 Correção** — o que muda e por quê, arquivos tocados, teste de regressão adicionado (ou motivo de não haver), riscos/efeitos colaterais.
- **§3 Implementação** — log: comando executado + resultado real (`./vendor/bin/pint --test`, `php artisan test`, `npx tsc --noEmit`, `vite build`), no mesmo espírito do checklist de `docs/sdd/04-implementation.md` §1.

## Fluxo de branch/PR

Mais simples que o `ADR-003` (que vale só para features com múltiplas tasks):

1. `git checkout dev && git pull origin dev`
2. `git checkout -b fix/<AAAAMMDD>-<slug>` — branch única, sem sub-branches.
3. Corrigir só o escopo do bug. Achado extra não-bloqueante vira arquivo em `docs/backlog/`; achado que é outro bug vira outro `/novo-bug`.
4. Checklist pré-PR (ou agent `pr-readiness-checker`): os comandos de `docs/sdd/04-implementation.md` §1 (item 3) para a frente tocada, mais — bug não reproduz mais (verificado); §3 do arquivo preenchida com comando + resultado; Triagem sem nenhuma caixa marcada.
5. Um único PR `fix/...` → `dev`, referenciando `docs/bugfix/<arquivo>.md`. Abrir o PR é autônomo.
6. **Merge em `dev` é gate humano** (revisão do PR — Constitution §5.2). Promoção `dev` → `main` inalterada (`docs/sdd/04-implementation.md` §1.8).

## Fechamento

Quando o PR do bugfix estiver aberto (não é preciso esperar o merge):

1. No arquivo `docs/bugfix/<arquivo>.md`, acrescente ao final:
   ```
   ## Resolução
   Concluído em: <AAAA-MM-DD>
   Branch: fix/<AAAAMMDD>-<slug>
   PR: <link>
   ```
2. Mova o arquivo para `docs/bugfix/concluidos/<arquivo>.md` (mesmo nome, só muda de pasta).
3. Neste `README.md`: tire a linha da tabela "Em andamento" e ponha na tabela "Concluídos".

## Em andamento

| Arquivo | Título | Criado em | Branch | Status |
|---|---|---|---|---|
| [20260830-login-remover-botao-microsoft.md](20260830-login-remover-botao-microsoft.md) | Botão "Microsoft" morto na tela de login | 2026-08-30 | `fix/20260830-login-remover-botao-microsoft` | Aberto |
| [20260901-expense-store-update-422.md](20260901-expense-store-update-422.md) | Testes de despesa (store/update/destroy) falham com 422 após virada de mês | 2026-09-01 | `fix/20260901-expense-store-update-422` | Aberto |

## Concluídos

| Arquivo | Título | Concluído em | PR |
|---|---|---|---|
| [concluidos/20260828-login-sem-mensagem-erro.md](concluidos/20260828-login-sem-mensagem-erro.md) | Tela de login não exibe mensagem de erro com credenciais inválidas | 2026-08-28 | #81 |
| [concluidos/20260829-deploy-site-secrets-quebrado.md](concluidos/20260829-deploy-site-secrets-quebrado.md) | deploy-site.yml falha por nomes de secret inexistentes | 2026-08-29 | #89 |
| [concluidos/20260829-cors-nao-le-frontend-url.md](concluidos/20260829-cors-nao-le-frontend-url.md) | CORS não permite o frontend de produção (config não lê FRONTEND_URL) | 2026-08-29 | #94 |
| [concluidos/20260829-deploy-backend-ftp-texto-puro.md](concluidos/20260829-deploy-backend-ftp-texto-puro.md) | Deploy do backend transmite credencial FTP em texto puro | 2026-08-29 | — (direto em `dev`) |
