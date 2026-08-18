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

Toda mudança relevante no sistema deve conseguir apontar: qual task → qual parte do plan → qual necessidade de negócio → dentro de quais regras (`00-constitution.md`).

## Trabalho por feature (`docs/feature/`)

A partir de 2026-08-17, `02-plan.md`, `03-tasks.md` e `04-implementation.md` deixam de crescer como arquivos únicos para todo o sistema. Todo trabalho novo — feature de produto ou épico técnico — ganha uma pasta própria em `docs/feature/<AAAAMMDD>-<slug>/` (data de criação da pasta + nome curto), com os mesmos quatro documentos (`specify.md`, `plan.md`, `tasks.md`, `implementation.md`), só que escopados à feature. `00-constitution.md` continua no topo da hierarquia para tudo, e `01-specify.md` continua sendo o baseline de "como o sistema é hoje" — nenhum dos dois se move para `docs/feature/`.

Épicos que já existiam em `03-tasks.md` antes dessa data e ainda não foram tocados continuam lá até alguém começar a trabalhar neles; nesse momento migram para uma pasta em `docs/feature/` (primeiro caso: Épico B → `docs/feature/20260817-seguranca-api/`).

Para criar a pasta de uma feature nova sem redigir os 4 documentos do zero, use `docs/sdd/templates/` (esqueleto em branco de cada documento) ou o slash command `/nova-feature <slug-curto>` (cria a pasta e copia os templates automaticamente). Decisões de stack/arquitetura ficam registradas separadamente em `docs/sdd/decisions/` (formato ADR) — ver `00-constitution.md` §5.1.

## Skills e portabilidade

`05-context-frontend.md` e `06-context-backend.md` são markdown puro, sem nada específico de ferramenta — contêm o que carregar antes de codar, convenções fixas e os gates human-in-the-loop de cada frente. Hoje eles são referenciados por duas skills do Claude Code (`expense/.claude/skills/expense-frontend` e `expense-backend`), que são só **adaptadores finos**: frontmatter com a `description` que dispara a auto-invocação + uma linha apontando pra cá. Se o projeto trocar de ferramenta de IA no futuro, só o adaptador precisa ser reescrito (no formato da ferramenta nova); o conteúdo real permanece nestes dois arquivos.

Além das skills, o projeto tem agents nativos do Claude Code em `.claude/agents/` (`security-reviewer`, `pr-readiness-checker`) — cada um construído só quando um gatilho concreto justificou. Ver `docs/sdd/agents-roadmap.md` para o que já existe e o que é candidato futuro.

## Gates human-in-the-loop (resumo — versão completa em `00-constitution.md` §5)

| Autônomo | Exige aprovação humana |
|---|---|
| Redigir Specify/Plan/Tasks | Editar a Constitution |
| Codar, testar, migrar localmente em branch | Migration em ambiente compartilhado/produção |
| Abrir PR | Merge em `main` |
| — | Deploy (backend ou app) |
| — | Rotacionar/expor segredo |
| — | Apagar dado definitivamente |

## Estado atual (2026-08-17)

Primeiro conjunto de tasks definido em `03-tasks.md`: **Épico A** é a migração do frontend para React Native (Expo + React Native Paper, novo projeto em `expense/app`, `expense/frontend` continua ativo em paralelo). Também há 3 achados de segurança/infra já registrados como tasks prioritárias (Épico B) encontrados durante a criação deste SDD — ver `00-constitution.md` §5.3 antes de mexer em Pix, grupos ou nos segredos do repositório.
