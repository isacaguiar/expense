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
| 006 | [expense-manager-groupid-param-mismatch.md](expense-manager-groupid-param-mismatch.md) | ExpenseManager nunca carrega despesas (nome de param de rota errado) | config-url-api-frontend | 2026-08-17 | ALTA | Promovido para TASK-033 |
| 007 | [storage-cache-logs-versionados-backend.md](storage-cache-logs-versionados-backend.md) | Cache e logs do Laravel versionados no repositório | config-url-api-frontend | 2026-08-17 | BAIXA | Aberto |
| 008 | [workflow-cicd-frontend.md](workflow-cicd-frontend.md) | Workflow de CI/CD para o frontend | (solicitação direta) | 2026-08-18 | MEDIA | Aberto |
| 009 | [despesas-menu-tela-branco-frontend.md](despesas-menu-tela-branco-frontend.md) | Tela de despesas abre em branco ao clicar no menu | (solicitação direta) | 2026-08-18 | ALTA | Promovido para TASK-033 |
| 010 | [expense-store-sem-checagem-membership-payer.md](expense-store-sem-checagem-membership-payer.md) | POST /api/expenses não checa se user_payer_id/payers são membros do grupo | fluxo-despesas-grupo (achado na TASK-036, revisão security-reviewer) | 2026-08-18 | ALTA | Promovido para TASK-039 |
| 012 | [expense-manager-installments-nao-projetadas-por-mes.md](expense-manager-installments-nao-projetadas-por-mes.md) | Despesas Parceladas não aparecem nos meses seguintes ao de criação, e valor exibido é o total, não a parcela | resumo-grupo-dashboard | 2026-08-18 | MEDIA | Aberto |
| 013 | [expense-manager-data-exibida-com-um-dia-a-menos.md](expense-manager-data-exibida-com-um-dia-a-menos.md) | ExpenseManager exibe a data das despesas com 1 dia a menos em fusos negativos | resumo-grupo-dashboard (achado na TASK-063) | 2026-08-19 | MEDIA | Aberto |
| 014 | [login-social-google.md](login-social-google.md) | Implementar login social via Google (OAuth) | novo-layout-tela-login | 2026-08-19 | BAIXA | Aberto |
| 015 | [login-social-microsoft.md](login-social-microsoft.md) | Implementar login social via Microsoft (OAuth) | novo-layout-tela-login | 2026-08-19 | BAIXA | Aberto |
| 016 | [i18n-frontend.md](i18n-frontend.md) | Implementar internacionalização (i18n) real do frontend | novo-layout-tela-login | 2026-08-19 | BAIXA | Aberto |
| 017 | [summary-tela-pagamentos.md](summary-tela-pagamentos.md) | Criar tela de Pagamentos do grupo | novo-layout-tela-entrada | 2026-08-19 | BAIXA | Aberto |
| 018 | [summary-tela-relatorios.md](summary-tela-relatorios.md) | Criar tela de Relatórios do grupo | novo-layout-tela-entrada | 2026-08-19 | BAIXA | Aberto |
| 019 | [summary-tela-configuracoes.md](summary-tela-configuracoes.md) | Criar tela de Configurações do grupo | novo-layout-tela-entrada | 2026-08-19 | BAIXA | Aberto |
| 020 | [sistema-notificacoes-frontend.md](sistema-notificacoes-frontend.md) | Implementar sistema de notificações | novo-layout-tela-entrada | 2026-08-19 | BAIXA | Aberto |
| 021 | [avatar-foto-usuario.md](avatar-foto-usuario.md) | Suportar foto de perfil do usuário | novo-layout-tela-entrada | 2026-08-19 | BAIXA | Aberto |
| 022 | [summary-sidebar-navegacao-mobile.md](summary-sidebar-navegacao-mobile.md) | Navegação alternativa para a sidebar da tela de Resumo em mobile | novo-layout-tela-entrada | 2026-08-19 | MEDIA | Aberto |
| 024 | [expense-campo-categoria.md](expense-campo-categoria.md) | Adicionar campo "categoria" em despesas | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |
| 025 | [expense-status-aguardando.md](expense-status-aguardando.md) | Definir e implementar status "Aguardando" para despesas | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |
| 026 | [expense-busca-filtro-paginacao-backend.md](expense-busca-filtro-paginacao-backend.md) | Busca, filtro por tipo e paginação server-side no endpoint de despesas do grupo | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |
| 027 | [dashboard-grouplist-duplicados.md](dashboard-grouplist-duplicados.md) | Consolidar Dashboard.tsx e GroupList.tsx (páginas quase duplicadas) | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |

## Itens concluídos

Itens cuja feature de promoção (`/promover-backlog`) já teve todas as tasks executadas (PR aberto) saem da tabela acima e vêm para cá. O arquivo original é movido para `concluidos/<arquivo>.md` e ganha uma seção `## Resolução` com data, feature, tasks e PR(s) — histórico de por que a ideia existiu, preservado mesmo depois de implementada.

| ID | Arquivo | Título | Resolvido em | Feature | Tasks |
|---|---|---|---|---|---|
| 001 | [config-url-api-frontend.md](concluidos/config-url-api-frontend.md) | Configuração de URL da API via variável de ambiente | 2026-08-17 | config-url-api-frontend | TASK-027 |
| 004 | [auth-guard-redirect-frontend.md](concluidos/auth-guard-redirect-frontend.md) | Auth guard / redirect automático | 2026-08-17 | config-url-api-frontend | TASK-028, TASK-029 |
| 002 | [infra-testes-frontend.md](concluidos/infra-testes-frontend.md) | Infraestrutura de testes no frontend | 2026-08-18 | infra-testes-frontend | TASK-030, TASK-031, TASK-032 |
| 011 | [recuperacao-senha-quebra-login-backend.md](concluidos/recuperacao-senha-quebra-login-backend.md) | Fluxo de recuperação de senha sobrescreve a senha antes de garantir entrega do e-mail | 2026-08-21 | recuperacao-senha-login | TASK-122, TASK-123, TASK-124, TASK-125 |
| 023 | [expense-show-update-destroy-ausentes.md](concluidos/expense-show-update-destroy-ausentes.md) | Implementar ExpenseController::show/update/destroy (rotas já registradas sem método) | 2026-08-21 | expense-show-update-destroy | TASK-126, TASK-127, TASK-128 |
| 028 | [invitation-invite-message-key-colisao-mail.md](concluidos/invitation-invite-message-key-colisao-mail.md) | Convite por e-mail quebra quando `message` é preenchido (colisão de chave com `Mail::send` legado) | 2026-08-21 | invitation-message-colisao-mail | TASK-129 |
