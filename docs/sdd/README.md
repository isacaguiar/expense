# SDD — Controle de Despesas Compartilhadas

Spec-Driven Development do projeto, com **human-in-the-loop**: a IA (ou qualquer executor) pode redigir specs, planejar e codar em branch livremente; ações irreversíveis ou que afetam produção/segredos sempre passam por aprovação humana explícita.

## Ordem de leitura / fluxo

```
00-constitution.md  → regras que nada pode contradizer (Arquitetura, Qualidade, Stack,
                       Compatibilidade, Governança, Segurança)
        ↓
01-specify.md        → o que o sistema faz hoje, de negócio (fonte: código real)
        ↓
02-plan.md            → como isso se traduz em engenharia (front/back/banco/infra)
        ↓
03-tasks.md            → plano quebrado em unidades atômicas executáveis
        ↓
04-implementation.md  → como uma task vira código, e o log do que já foi feito
        ↓
05-context-frontend.md → contexto de execução portátil para tarefas de frontend
06-context-backend.md  → contexto de execução portátil para tarefas de backend
```

Leitura transversal (não é uma fase): `agent-architecture.md` — o SDD + `.claude/` vistos como um agente (6 peças, loop, condição de parada, fronteira de autonomia).

Fluxos paralelos: `docs/feature/<AAAAMMDD>-<slug>/` (desenvolvimento novo — ver abaixo; feature concluída vai para `docs/feature/concluidas/<AAAAMM>/`), `docs/backlog/` (ideia/débito não agendado), `docs/bugfix/` (correção de defeito — BFF; concluído vai para `docs/bugfix/concluidos/<AAAAMM>/`, ver abaixo).

Toda mudança relevante no sistema deve conseguir apontar: qual task → qual parte do plan → qual necessidade de negócio → dentro de quais regras (`00-constitution.md`).

## Trabalho por feature (`docs/feature/`)

Todo trabalho novo — feature de produto ou épico técnico — ganha uma pasta própria em `docs/feature/<AAAAMMDD>-<slug>/` (data de criação da pasta + nome curto), com os quatro documentos (`specify.md`, `plan.md`, `tasks.md`, `implementation.md`) escopados à feature. `00-constitution.md` (topo da hierarquia) e `01-specify.md` (baseline de "como o sistema é hoje") não se movem para lá. Por que `02`/`03`/`04` deixaram de crescer como arquivos únicos a partir de 2026-08-17: `docs/sdd/decisions/ADR-002-sdd-por-feature.md`.

Épicos que já existiam em `03-tasks.md` antes dessa data e ainda não foram tocados continuam lá até alguém começar a trabalhar neles; nesse momento migram para uma pasta em `docs/feature/` (primeiro caso: Épico B → `docs/feature/concluidas/202608/20260817-seguranca-api/`).

Para criar a pasta de uma feature nova sem redigir os 4 documentos do zero, use `docs/sdd/templates/` (esqueleto em branco de cada documento) ou o slash command `/nova-feature <slug-curto>` (cria a pasta e copia os templates automaticamente). Decisões de stack/arquitetura ficam registradas separadamente em `docs/sdd/decisions/` (formato ADR) — ver `00-constitution.md` §5.1.

Quando o PR único da feature é mergeado em `dev`, a pasta é movida para `docs/feature/concluidas/<AAAAMM>/<AAAAMMDD>-<slug>/` (`<AAAAMM>` = os 6 primeiros dígitos do nome da pasta, mês de criação). `docs/feature/` na raiz fica só com o que está em andamento; não há índice — o estado "concluída" é a própria localização. Porquê e migração retroativa: `docs/sdd/decisions/ADR-009-arquivar-concluidos-por-anomes.md`.

## Backlog de ideias não bloqueantes (`docs/backlog/`)

Toda feature encontra, no caminho, achados que não bloqueiam nenhuma task dela (ex.: um débito técnico tangencial, uma melhoria de DX, uma ideia de produto fora do `specify.md` atual). Esse tipo de achado **não fica preso à pasta da feature que o encontrou** — vai para `docs/backlog/` (diretório único, compartilhado por todo o projeto), um arquivo por ideia, com um `README.md` de índice. Formato e critério completos em `docs/backlog/README.md`; regra curta: se bloqueia uma task da feature atual, vira `TASK-0xx` ali mesmo; se não bloqueia, vira um arquivo em `docs/backlog/`, e só ganha `TASK-0xx` quando alguém decidir de fato executá-lo (nesse momento, promova o conteúdo para o `tasks.md` da feature/épico que for tocá-lo).

Essa separação existe porque um achado não-bloqueante costuma sobreviver à feature que o descobriu — ele é conhecimento do projeto, não artefato descartável de uma pasta de trabalho.

## Correção de bug (`docs/bugfix/`)

Correção de defeito em comportamento que já existe não precisa dos 4 documentos de `docs/feature/`. Segue o **BFF (Bug-Fix Flow)**: um arquivo `docs/bugfix/<AAAAMMDD>-<slug>.md` (Triagem + Problema + Correção + log), branch única `fix/<AAAAMMDD>-<slug>`, um PR contra `dev` (merge = gate humano). Uma Triagem no topo do arquivo decide se o trabalho fica no BFF ou vira feature SDD normal. Slash command `/novo-bug <slug>`. Triagem, formato e regra de escalação: `docs/bugfix/README.md`; decisão: `docs/sdd/decisions/ADR-004-fluxo-bugfix.md`. Ao concluir, o arquivo vai para `docs/bugfix/concluidos/<AAAAMM>/` (`ADR-009`).

Quando alguém decide de fato executar um item do backlog, o slash command `/promover-backlog <ID>` conduz o processo completo — scaffold da feature, depois Specify → Tech Plan → Tasks → execução de cada task, pedindo aprovação humana explícita entre cada etapa (mesmo espírito do `/nova-feature`, só que partindo de um item já existente e indo até a execução). Ver `.claude/skills/promover-backlog/SKILL.md`.

## Skills e portabilidade

`05-context-frontend.md` e `06-context-backend.md` são markdown puro, sem nada específico de ferramenta — contêm o que carregar antes de codar e as convenções fixas de cada frente (os gates continuam só em `00-constitution.md` §5.2). Hoje eles são referenciados por duas skills do Claude Code (`expense/.claude/skills/expense-frontend` e `expense-backend`), que são só **adaptadores finos**: frontmatter com a `description` que dispara a auto-invocação + uma linha apontando pra cá. Se o projeto trocar de ferramenta de IA no futuro, só o adaptador precisa ser reescrito (no formato da ferramenta nova); o conteúdo real permanece nestes dois arquivos.

Além das skills, o projeto tem agents nativos do Claude Code em `.claude/agents/` (`security-reviewer`, `pr-readiness-checker`) — cada um construído só quando um gatilho concreto justificou. Ver `docs/sdd/agents-roadmap.md` para o que já existe e o que é candidato futuro.

## Gates human-in-the-loop

Tabela normativa: `00-constitution.md` §5.2. Desenho da fronteira de autonomia: `agent-architecture.md` §5.

## Estado atual (2026-08-17)

Primeiro conjunto de tasks definido em `03-tasks.md`: **Épico A** é a migração do frontend para React Native (Expo + React Native Paper, novo projeto em `expense/app`, `expense/frontend` continua ativo em paralelo). Também há 3 achados de segurança/infra já registrados como tasks prioritárias (Épico B) encontrados durante a criação deste SDD — ver `00-constitution.md` §5.3 antes de mexer em Pix, grupos ou nos segredos do repositório.
