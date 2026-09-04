# Roadmap de agents nativos do projeto

> Agents nativos do Claude Code (`.claude/agents/*.md`, com `tools` restritos) próprios deste projeto. Não é um catálogo para criar de uma vez — cada agent só é construído quando o gatilho abaixo vira concreto no repo. Registrar aqui um candidato sem construir evita tanto reinventar a ideia do zero da próxima vez quanto criar agent especulativo demais cedo (mesma lição do princípio "não criar estrutura vazia pra reservar espaço").

Este "construir só quando o gatilho é concreto" é a alternativa descartada do padrão **orquestrador + subagentes** em `agent-architecture.md` §4.3 (o descartado seria criar todos os subagentes de antemão).

## Construídos

| Agent | Arquivo | Domínio | Gatilho que justificou construir agora |
|---|---|---|---|
| `security-reviewer` | [`.claude/agents/security-reviewer.md`](../../.claude/agents/security-reviewer.md) | Backend — auth/autorização | Padrões de vulnerabilidade já confirmados e corrigidos no repo — ver `00-constitution.md` §5.3 e `docs/feature/concluidas/202608/20260817-seguranca-api/`. |
| `pr-readiness-checker` | [`.claude/agents/pr-readiness-checker.md`](../../.claude/agents/pr-readiness-checker.md) | Backend + frontend — checklist pré-PR | O checklist pré-PR de `04-implementation.md` já existe e é real, mas hoje depende de o executor lembrar de rodar cada comando manualmente — mesmo problema que a Constitution já reconhece para o Pint ("não há hook/CI que o obrigue"). |

## Candidatos futuros (não construir ainda)

| Agent candidato | Domínio | Gatilho para construir |
|---|---|---|
| `migration-safety-checker` | Backend/DB | Quando a próxima task adicionar uma migration real — hoje nenhuma task em andamento cria migration nova. Checaria se é aditiva (`00-constitution.md` §4.2) e sinalizaria gate humano se destrutiva. |
| `balance-calc-auditor` | Backend | Quando TASK-020 (extrair Service de cálculo de saldo, hoje duplicado ~80% entre `reportByGroupAndYear` e `reportByGroupAndYearMonthlySettlement`) for iniciada — audita antes/depois da extração, comparando comportamento. |
| `frontend-parity-checker` | Frontend | Quando `expense/app` (Expo) tiver telas reais o suficiente para divergir de `expense/frontend` — hoje o Épico A ainda não começou (`03-tasks.md`, TASK-001 em diante). Confirma que os dois clientes consomem o mesmo contrato de API (`00-constitution.md` §4.3). |

Quando um candidato futuro virar concreto: mover a linha da tabela "Candidatos futuros" para "Construídos", criar o arquivo em `.claude/agents/`, linkar aqui.
