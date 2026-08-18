# Backlog — Ideias e débitos técnicos não agendados

> Achados e ideias de implementação encontrados durante o trabalho em uma feature, que não bloqueiam nenhuma task dela, mas não devem ser esquecidos. Um item aqui só ganha um `TASK-0xx` quando alguém decidir de fato executá-lo — nesse momento, use `/promover-backlog <ID>` (ver `.claude/commands/promover-backlog.md`) para conduzir o processo completo (Specify → Tech Plan → Tasks → execução, com aprovação humana entre cada etapa) e marque o status abaixo como "Promovido". Quando a execução terminar (todas as tasks com PR aberto), o item sai da tabela "Índice" abaixo e vai para `concluidos/`, com um resumo do porquê — ver "Itens concluídos" mais abaixo.

## Quando adicionar um item aqui

- Um débito técnico encontrado durante o trabalho em uma feature (`docs/feature/<...>/specify.md`), que não bloqueia nenhuma task da feature atual — ver critério de bloqueante vs. não-bloqueante no `plan.md` da feature que o originou.
- Uma ideia de melhoria/produto que surgiu durante o trabalho, mas está fora do escopo do `specify.md` da feature.

Não é para achado que já bloqueia algo hoje (isso vira task direto) nem para decisão de arquitetura/stack (isso é ADR — ver `docs/sdd/decisions/`).

## Formato

Um arquivo por ideia, nome curto em kebab-case:

```
# <Título curto>

ID: <numérico sequencial, 3 dígitos — próximo livre está no Índice abaixo>
Origem: docs/feature/<AAAAMMDD>-<slug>/specify.md §<n> (ou onde foi identificado)
Criado em: <AAAA-MM-DD>
Prioridade: ALTA | MEDIA | BAIXA
Status: Aberto | Promovido para TASK-0xx

## Descrição
<O que é, em 1-3 frases>

## Por que importa
<Consequência de não fazer, ou benefício de fazer>

Tipo sugerido: frontend | backend | infra | doc
```

Critério de prioridade: **ALTA** = risco/custo cresce com o tempo (segurança, dado incorreto, bloqueia outro trabalho em breve); **MEDIA** = vale fazer antes de um marco conhecido (ex.: antes de um corte de produção), mas não urge; **BAIXA** = manutenção/DX, sem prazo natural.

O `ID` é só numérico e sequencial (não reaproveita número de item removido/promovido), diferente do `TASK-0xx` — existe só para referenciar um item do backlog de forma curta antes dele virar task.

## Índice

| ID | Arquivo | Título | Origem | Criado em | Prioridade | Status |
|---|---|---|---|---|---|---|
| 003 | [tipos-duplicados-frontend.md](tipos-duplicados-frontend.md) | Extrair tipos duplicados para módulo compartilhado | migracao-frontend-expo | 2026-08-17 | BAIXA | Aberto |
| 005 | [node-modules-versionado-frontend.md](node-modules-versionado-frontend.md) | node_modules do frontend versionado no repositório | config-url-api-frontend | 2026-08-17 | BAIXA | Aberto |
| 006 | [expense-manager-groupid-param-mismatch.md](expense-manager-groupid-param-mismatch.md) | ExpenseManager nunca carrega despesas (nome de param de rota errado) | config-url-api-frontend | 2026-08-17 | ALTA | Aberto |
| 007 | [storage-cache-logs-versionados-backend.md](storage-cache-logs-versionados-backend.md) | Cache e logs do Laravel versionados no repositório | config-url-api-frontend | 2026-08-17 | BAIXA | Aberto |

## Itens concluídos

Itens cuja feature de promoção (`/promover-backlog`) já teve todas as tasks executadas (PR aberto) saem da tabela acima e vêm para cá. O arquivo original é movido para `concluidos/<arquivo>.md` e ganha uma seção `## Resolução` com data, feature, tasks e PR(s) — histórico de por que a ideia existiu, preservado mesmo depois de implementada.

| ID | Arquivo | Título | Resolvido em | Feature | Tasks |
|---|---|---|---|---|---|
| 001 | [config-url-api-frontend.md](concluidos/config-url-api-frontend.md) | Configuração de URL da API via variável de ambiente | 2026-08-17 | config-url-api-frontend | TASK-027 |
| 004 | [auth-guard-redirect-frontend.md](concluidos/auth-guard-redirect-frontend.md) | Auth guard / redirect automático | 2026-08-17 | config-url-api-frontend | TASK-028, TASK-029 |
| 002 | [infra-testes-frontend.md](concluidos/infra-testes-frontend.md) | Infraestrutura de testes no frontend | 2026-08-18 | infra-testes-frontend | TASK-030, TASK-031, TASK-032 |
