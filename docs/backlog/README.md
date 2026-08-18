# Backlog — Ideias e débitos técnicos não agendados

> Achados e ideias de implementação encontrados durante o trabalho em uma feature, que não bloqueiam nenhuma task dela, mas não devem ser esquecidos. Um item aqui só ganha um `TASK-0xx` quando alguém decidir de fato executá-lo — nesse momento, use `/promover-backlog <ID>` (ver `.claude/commands/promover-backlog.md`) para conduzir o processo completo (Specify → Tech Plan → Tasks → execução, com aprovação humana entre cada etapa) e marque o status abaixo como "Promovido".

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
| 001 | [config-url-api-frontend.md](config-url-api-frontend.md) | Configuração de URL da API via variável de ambiente | migracao-frontend-expo | 2026-08-17 | MEDIA | Aberto |
| 002 | [infra-testes-frontend.md](infra-testes-frontend.md) | Infraestrutura de testes no frontend | migracao-frontend-expo | 2026-08-17 | MEDIA | Aberto |
| 003 | [tipos-duplicados-frontend.md](tipos-duplicados-frontend.md) | Extrair tipos duplicados para módulo compartilhado | migracao-frontend-expo | 2026-08-17 | BAIXA | Aberto |
| 004 | [auth-guard-redirect-frontend.md](auth-guard-redirect-frontend.md) | Auth guard / redirect automático | migracao-frontend-expo | 2026-08-17 | BAIXA | Aberto |
