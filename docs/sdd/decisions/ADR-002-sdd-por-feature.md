# ADR-002: SDD por feature (`docs/feature/<AAAAMMDD>-<slug>/`) em vez de arquivos únicos

Status: Aceita
Data: 2026-08-17

## Contexto

O SDD original tinha `02-plan.md`, `03-tasks.md` e `04-implementation.md` como arquivos únicos, crescendo indefinidamente para todo trabalho novo do sistema (todo épico, toda feature, toda task). Ao surgir a primeira feature com múltiplos achados relacionados e log de execução detalhado (segurança da API, 5 achados confirmados), ficou claro que continuar acrescentando seções a três arquivos já grandes tornaria cada um deles cada vez mais caro de carregar por inteiro antes de qualquer tarefa nova — e misturaria o "estado atual do sistema" (que muda devagar) com o "estado de uma feature em andamento" (que muda a cada task).

## Decisão

A partir de 2026-08-17, `02-plan.md`, `03-tasks.md` e `04-implementation.md` param de crescer como arquivos únicos para todo o sistema. Todo trabalho novo — feature de produto ou épico técnico — ganha uma pasta própria em `docs/feature/<AAAAMMDD>-<slug>/` (data de criação da pasta + nome curto), com os mesmos quatro documentos (`specify.md`, `plan.md`, `tasks.md`, `implementation.md`), escopados só àquela feature.

`00-constitution.md` continua no topo da hierarquia para tudo, e `01-specify.md` continua sendo o baseline de "como o sistema é hoje" (o que o sistema faz, não uma feature específica) — nenhum dos dois se move para `docs/feature/`.

Épicos que já existiam em `03-tasks.md` antes desta data e ainda não foram tocados continuam lá até alguém começar a trabalhar neles; nesse momento migram para uma pasta em `docs/feature/`, deixando só um ponteiro no arquivo original (primeiro caso: Épico B → `docs/feature/20260817-seguranca-api/`).

## Consequências

- Toda feature nova fica auto-contida e mais barata de carregar (só os 4 arquivos daquela pasta, não o histórico inteiro do sistema).
- O `04-implementation.md` de nível de sistema perde sua razão de ser como log corrente — vira só a definição do fluxo de execução (branch, checklist, gates), com a tabela de log vazia por definição (o log real vive em cada `docs/feature/<...>/implementation.md`).
- Cria uma segunda convenção de "onde procurar": épicos antigos não migrados continuam em `03-tasks.md`, épicos/features novos ficam em `docs/feature/`. O `docs/sdd/README.md` precisa deixar essa regra explícita para não confundir quem chega depois (já faz isso, seção "Trabalho por feature").
- Scaffolding manual dessa estrutura (criar a pasta, copiar o formato dos 4 documentos) ficava só descrito em prosa no `CLAUDE.md` até a criação dos templates (`docs/sdd/templates/`) e do slash command `/nova-feature` — ver histórico da sessão que gerou esta ADR.

## Alternativas consideradas

- **Continuar com os 3 arquivos únicos, só quebrando em seções por épico**: descartada — não resolve o problema de custo de carregamento (ainda é um arquivo por tipo de documento, crescendo para sempre) nem separa claramente "feature em andamento" de "estado consolidado do sistema".
- **Um repositório/ferramenta de tracking externo (ex.: board de tasks) em vez de markdown no próprio repo**: descartada por ora — manter tudo versionado junto com o código, sem dependência de ferramenta externa, era um valor já implícito no uso de SDD em markdown deste projeto; não foi um trade-off reavaliado nesta decisão.

## Referências

- `docs/sdd/README.md` — seção "Trabalho por feature (`docs/feature/`)".
- `03-tasks.md` — seção "Convenção a partir de 2026-08-17".
- `docs/feature/20260817-seguranca-api/` — primeiro caso real desta convenção.
