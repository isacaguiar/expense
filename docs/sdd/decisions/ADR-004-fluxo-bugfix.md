# ADR-004: Fluxo de correção de bug (BFF — Bug-Fix Flow), paralelo ao SDD-por-feature

Status: Aceita
Data: 2026-08-28

## Contexto

O SDD-por-feature (`ADR-002`) e o fluxo de branch em duas camadas (`ADR-003`) foram desenhados para **desenvolvimento novo** — features de produto e épicos técnicos, com múltiplos achados e tasks relacionadas. Aplicá-los a uma correção de defeito pequena (uma tela que não mostra mensagem de erro, um nome de parâmetro de rota errado, um cálculo com off-by-one) obriga a criar uma pasta `docs/feature/<AAAAMMDD>-<slug>/` com 4 documentos (`specify.md`, `plan.md`, `tasks.md`, `implementation.md`) e, pelo `ADR-003`, potencialmente sub-branches por task — cerimônia desproporcional ao tamanho da mudança.

Na prática, vários bugs já foram corrigidos "por dentro" de features de layout ou promovendo item de backlog, sem um fluxo próprio (ver `docs/backlog/concluidos/` — os itens 006, 009, 012 e 013 são bugs que passaram pelo fluxo de feature por falta de alternativa).

A Constitution §5.1, item 1, hoje diz "Todo trabalho de negócio segue as 5 fases do SDD nesta ordem" — sem uma exceção explícita, toda correção teria que passar pelas 5 fases.

## Decisão

Criar o **BFF (Bug-Fix Flow)**, um caminho paralelo ao SDD-por-feature, exclusivo para **correção de defeito em comportamento já existente** (não desenvolvimento novo).

**1. Triagem (porta de entrada).** Todo bug começa com 4 caixas. Se **qualquer uma** for marcada, o BFF não se aplica — o trabalho vira feature SDD normal (`/nova-feature`, ou `/promover-backlog` se já existir item de backlog):

1. Toca autenticação / autorização / dado sensível (rotas, controllers ou middleware de auth; Pix; grupos; despesas; usuários) — as "regras que nunca devem ser ignoradas" do `CLAUDE.md` raiz.
2. Exige migration (mesmo aditiva) **ou** muda contrato de API (formato de resposta, rota, status code ou payload que o `frontend`/`app` consome).
3. Causa raiz não esclarecida após um timebox de investigação, **ou** correção que passa de ~3 arquivos / toca vários módulos.
4. A correção "certa" depende de decidir um comportamento novo, ou contradiz a Constitution.

**2. Artefato.** Um arquivo único `docs/bugfix/<AAAAMMDD>-<slug>.md` (diretório flat, espelhando `docs/backlog/`), com um `README.md` de índice e uma subpasta `concluidos/`. Estrutura do arquivo: bloco de Triagem + §1 Problema + §2 Correção + §3 Implementação (log). Sem `plan.md`/`tasks.md` separados.

**3. Branch/PR.** Branch única `fix/<AAAAMMDD>-<slug>` criada de `dev` atualizada, sem sub-branches (o bugfix é uma unidade só — o fluxo de duas camadas do `ADR-003` não se aplica). Um único PR `fix/...` → `dev`. Abrir o PR é autônomo; **merge em `dev` continua gate humano**. Promoção `dev` → `main` inalterada.

**4. Teste.** Segue a Constitution §2.2 como está (teste exigido para regra de negócio; bug de UI/config pode não ter). A §2 do artefato obriga registrar explicitamente qual teste de regressão foi adicionado, ou o motivo de não haver — a decisão fica no arquivo, não implícita.

**5. Escalar no meio do caminho.** Se durante a correção surgir qualquer gatilho da Triagem, para-se o BFF, move-se o conteúdo para uma pasta `docs/feature/` (`/nova-feature`) e deixa-se no arquivo de bugfix só um ponteiro para a feature. O caminho só sobe, nunca desce.

**6. Scaffold.** Slash command `/novo-bug <slug>` + skill `.claude/skills/novo-bug/` — adaptador fino espelhando `/nova-feature`: cria o arquivo a partir de `docs/bugfix/templates/bugfix.template.md`, roda a Triagem, não escreve código.

`docs/feature/`, o `ADR-002` e o `ADR-003` seguem intactos — o BFF é adição, não substituição.

## Consequências

- Correção de defeito pequena deixa de exigir 4 documentos e vira 1 arquivo + 1 PR.
- Cria uma terceira convenção de "onde procurar": `docs/feature/` (desenvolvimento novo), `docs/backlog/` (ideia/débito não agendado), `docs/bugfix/` (correção em andamento ou feita). O `docs/sdd/README.md` passa a listar as três.
- A Triagem fica versionada no próprio arquivo — o registro auditável de *por que* aquele bug foi elegível ao fluxo leve.
- Risco: classificar como bug algo que era feature disfarçada. Mitigado pela Triagem (4 gatilhos amplos, incluindo "decisão de produto") e pela regra de escalar no meio do caminho.
- A Constitution §5.1 (item 1 + novo item 1.2) e a tabela de Governança §5.2 precisam refletir a exceção — feito nesta mesma mudança (bump 1.1 → 1.2).
- `CLAUDE.md` raiz precisa direcionar pedido de correção de bug para `/novo-bug` + Triagem antes de assumir o fluxo de feature — feito nesta mudança.

## Alternativas consideradas

- **Pasta enxuta `docs/bugfix/<AAAAMMDD>-<slug>/` com 2 arquivos** (`bug.md` + `implementation.md`): descartada — para o tamanho de bug que o BFF cobre, dois arquivos ainda é mais estrutura do que o necessário; um arquivo com 3 seções basta e é mais barato de abrir.
- **Sem doc dedicado, só `docs/backlog/` + corpo do PR**: descartada — perde rastreabilidade (Triagem e causa raiz não ficam versionadas de forma consistente) e não deixa um lugar óbvio para o log de execução.
- **Commit direto em `dev` para fix trivial, sem PR**: descartada — quebra o gate de revisão de PR em `dev` (Constitution §5.2) sem ganho real; abrir um PR pequeno custa pouco.
- **Manter tudo no SDD-por-feature**: descartada — é exatamente a cerimônia desproporcional que motivou esta decisão.

## Referências

- `docs/bugfix/README.md` — formato, Triagem e regra de escalação.
- `docs/sdd/00-constitution.md` §5.1 (itens 1 e 1.2) e tabela de Governança §5.2 (linhas do PR de bugfix).
- `docs/sdd/decisions/ADR-002-sdd-por-feature.md`, `ADR-003-fluxo-branch-por-feature.md` — fluxos que o BFF complementa sem alterar.
- `.claude/skills/novo-bug/SKILL.md` — scaffold do artefato.
- Primeiro caso previsto: `docs/bugfix/20260828-login-sem-mensagem-erro.md` (estreia do fluxo).
