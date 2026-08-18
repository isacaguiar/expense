# Backlog — Ideias e débitos técnicos não agendados

> Achados e ideias de implementação encontrados durante o trabalho em uma feature, que não bloqueiam nenhuma task dela, mas não devem ser esquecidos. Um item aqui só ganha um `TASK-0xx` quando alguém decidir de fato executá-lo — nesse momento, promova o conteúdo para o `tasks.md` da feature/épico correspondente e marque o status abaixo como "Promovido".

## Quando adicionar um item aqui

- Um débito técnico encontrado durante o trabalho em uma feature (`docs/feature/<...>/specify.md`), que não bloqueia nenhuma task da feature atual — ver critério de bloqueante vs. não-bloqueante no `plan.md` da feature que o originou.
- Uma ideia de melhoria/produto que surgiu durante o trabalho, mas está fora do escopo do `specify.md` da feature.

Não é para achado que já bloqueia algo hoje (isso vira task direto) nem para decisão de arquitetura/stack (isso é ADR — ver `docs/sdd/decisions/`).

## Formato

Um arquivo por ideia, nome curto em kebab-case:

```
# <Título curto>

Origem: docs/feature/<AAAAMMDD>-<slug>/specify.md §<n> (ou onde foi identificado)
Criado em: <AAAA-MM-DD>
Status: Aberto | Promovido para TASK-0xx

## Descrição
<O que é, em 1-3 frases>

## Por que importa
<Consequência de não fazer, ou benefício de fazer>

Tipo sugerido: frontend | backend | infra | doc
```

## Índice

| Arquivo | Título | Origem | Status |
|---|---|---|---|
| [config-url-api-frontend.md](config-url-api-frontend.md) | Configuração de URL da API via variável de ambiente | migracao-frontend-expo | Aberto |
| [infra-testes-frontend.md](infra-testes-frontend.md) | Infraestrutura de testes no frontend | migracao-frontend-expo | Aberto |
| [tipos-duplicados-frontend.md](tipos-duplicados-frontend.md) | Extrair tipos duplicados para módulo compartilhado | migracao-frontend-expo | Aberto |
| [auth-guard-redirect-frontend.md](auth-guard-redirect-frontend.md) | Auth guard / redirect automático | migracao-frontend-expo | Aberto |
