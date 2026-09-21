# Backlog — Ideias e débitos técnicos não agendados

> Achados e ideias de implementação encontrados durante o trabalho em uma feature, que não bloqueiam nenhuma task dela, mas não devem ser esquecidos. Um item aqui só ganha um `TASK-0xx` quando alguém decidir de fato executá-lo — nesse momento, use `/promover-backlog <ID>` (ver `.claude/skills/promover-backlog/SKILL.md`) para conduzir o processo completo (Specify → Tech Plan → Tasks → execução, com aprovação humana entre cada etapa) e marque o status abaixo como "Promovido". Quando a execução terminar (todas as tasks com PR aberto), o item sai da tabela "Índice" abaixo e vai para `concluidos/`, com um resumo do porquê — ver "Itens concluídos" mais abaixo.

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
| 003 | [tipos-duplicados-frontend.md](tipos-duplicados-frontend.md) | Extrair tipos duplicados para módulo compartilhado | migracao-frontend-expo | 2026-08-17 | BAIXA | Promovido para TASK-281 |
| 014 | [login-social-google.md](login-social-google.md) | Implementar login social via Google (OAuth) | novo-layout-tela-login | 2026-08-19 | BAIXA | Aberto |
| 015 | [login-social-microsoft.md](login-social-microsoft.md) | Implementar login social via Microsoft (OAuth) | novo-layout-tela-login | 2026-08-19 | BAIXA | Aberto |
| 016 | [i18n-frontend.md](i18n-frontend.md) | Implementar internacionalização (i18n) real do frontend | novo-layout-tela-login | 2026-08-19 | BAIXA | Aberto |
| 024 | [expense-campo-categoria.md](expense-campo-categoria.md) | Adicionar campo "categoria" em despesas | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |
| 025 | [expense-status-aguardando.md](expense-status-aguardando.md) | Definir e implementar status "Aguardando" para despesas | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |
| 026 | [expense-busca-filtro-paginacao-backend.md](expense-busca-filtro-paginacao-backend.md) | Busca, filtro por tipo e paginação server-side no endpoint de despesas do grupo | atualizacao-layout-paginas | 2026-08-20 | BAIXA | Aberto |
| 033 | [eslint-setup-frontend.md](eslint-setup-frontend.md) | Configurar ESLint no frontend | ci-frontend | 2026-08-27 | BAIXA | Aberto |
| 034 | [workflow-ci-backend.md](workflow-ci-backend.md) | Workflow de CI (verificação) para o backend | ci-frontend | 2026-08-27 | MEDIA | Aberto |
| 035 | [google-oauth-state-pull-nao-atomico.md](google-oauth-state-pull-nao-atomico.md) | `Cache::pull` do state OAuth não é atômico (uso único não estrito) | google-oauth-state-opaco | 2026-09-01 | BAIXA | Aberto |
| 036 | [frontend-index-html-lang-title-estaticos.md](frontend-index-html-lang-title-estaticos.md) | `frontend/index.html` com `lang="en"` e `<title>` estático | frontend-meta-viewport-mobile | 2026-09-01 | BAIXA | Aberto |
| 037 | [frontend-dist-index-html-versionado.md](frontend-dist-index-html-versionado.md) | `frontend/dist/index.html` versionado apesar de `dist/` no `.gitignore` | frontend-meta-viewport-mobile | 2026-09-01 | BAIXA | Aberto |
| 038 | [expense-parcela-retroativa-paid-by-sem-consentimento.md](expense-parcela-retroativa-paid-by-sem-consentimento.md) | Parcela retroativa nasce `paid`/`paid_by` em nome do credor, sem consentimento nem aviso | despesa-parcelada-retroativa | 2026-09-04 | MEDIA | Aberto |
| 039 | [expense-view-tipo-e-pagadores.md](expense-view-tipo-e-pagadores.md) | Página da despesa (`ExpenseView`) rotula Parcelada como "Variável" e não lista pagadores | detalhe-despesa-tipo-parcela-valores | 2026-09-05 | MEDIA | Promovido para TASK-276 |
| 040 | [expense-view-edicao-campo-data-vazio.md](expense-view-edicao-campo-data-vazio.md) | Modo de edição da página da despesa abre com o campo Data vazio | expense-view-tipo-e-pagadores | 2026-09-12 | MEDIA | Aberto |
| 042 | [register-legado-sem-verificacao.md](register-legado-sem-verificacao.md) | `POST /register` legado cria conta sem verificar o e-mail | cadastro-de-usuarios | 2026-09-19 | MEDIA | Aberto |
| 043 | [login-nao-checa-email-verificado.md](login-nao-checa-email-verificado.md) | `AuthController::login` não checa `email_verified_at` | cadastro-de-usuarios | 2026-09-19 | MEDIA | Aberto |
| 044 | [expurgo-pre-cadastro-expirado.md](expurgo-pre-cadastro-expirado.md) | Sem rotina de expurgo de `ex_user_pre_create` | cadastro-de-usuarios | 2026-09-19 | BAIXA | Aberto |
| 045 | [pre-cadastro-oraculo-e-mailable-queueable.md](pre-cadastro-oraculo-e-mailable-queueable.md) | Endurecer enumeração no pré-cadastro e travar o Mailable do código fora da fila | cadastro-de-usuarios | 2026-09-19 | BAIXA | Aberto |
| 046 | [login-refresh-token-undefined.md](login-refresh-token-undefined.md) | `LoginPage` grava `refreshToken` com a string `"undefined"` | cadastro-de-usuarios | 2026-09-19 | BAIXA | Aberto |
| 047 | [whatsapp-secrets-vazios-sobrescrevem-defaults.md](whatsapp-secrets-vazios-sobrescrevem-defaults.md) | Secrets de WhatsApp ausentes viram string vazia e anulam os defaults de `config/services.php` | cadastro-de-usuarios (verificação do deploy) | 2026-09-19 | BAIXA | Aberto |
| 049 | [site-pagina-404.md](site-pagina-404.md) | Site não tem página 404 com o layout e a navegação | site-conteudo-e-precos | 2026-09-20 | BAIXA | Aberto |
| 050 | [tres-nomes-do-produto.md](tres-nomes-do-produto.md) | O produto se chama "Shared Expense", "Controle de Despesas Compartilhadas" e "SCD" | site-conteudo-e-precos | 2026-09-20 | BAIXA | Aberto |
| 051 | [site-updated-at-fixo-documentos-legais.md](site-updated-at-fixo-documentos-legais.md) | `updated_at` dos documentos legais é string fixa e compartilhada | site-conteudo-e-precos | 2026-09-20 | BAIXA | Aberto |
| 052 | [contexto-sdd-para-o-site-php.md](contexto-sdd-para-o-site-php.md) | Site institucional não tem documento de contexto no SDD | site-conteudo-e-precos | 2026-09-20 | BAIXA | Aberto |
| 053 | [limite-grupos-constante-duplicada.md](limite-grupos-constante-duplicada.md) | Limite de grupos duplicado em backend, frontend e site, agora anunciado como plano | site-conteudo-e-precos | 2026-09-20 | MEDIA | Aberto |
| 054 | [site-og-image-dedicada.md](site-og-image-dedicada.md) | Imagem de compartilhamento do site é uma screenshot reaproveitada | site-conteudo-e-precos | 2026-09-20 | BAIXA | Aberto |
| 055 | [site-header-mobile-duas-linhas.md](site-header-mobile-duas-linhas.md) | Header do site ocupa duas linhas no celular | site-conteudo-e-precos (verificação do deploy) | 2026-09-20 | BAIXA | Aberto |

## Itens concluídos

Itens cuja feature de promoção (`/promover-backlog`) já teve todas as tasks executadas (PR aberto) saem da tabela acima e vêm para cá. O arquivo original é movido para `concluidos/<arquivo>.md` e ganha uma seção `## Resolução` com data, feature, tasks e PR(s) — histórico de por que a ideia existiu, preservado mesmo depois de implementada.

| ID | Arquivo | Título | Resolvido em | Feature | Tasks |
|---|---|---|---|---|---|
| 048 | [concluidos/site-consentimento-cookies-analytics.md](concluidos/site-consentimento-cookies-analytics.md) | Google Analytics do site carrega sem consentimento (LGPD) | 2026-09-20 | 20260920-analytics-app-e-consentimento | TASK-309 a TASK-320 |
| 005 | [node-modules-versionado-frontend.md](concluidos/node-modules-versionado-frontend.md) | node_modules do frontend versionado no repositório | 2026-08-21 (fora do fluxo SDD — commit `477d27665`, anterior) | — | — |
| 007 | [storage-cache-logs-versionados-backend.md](concluidos/storage-cache-logs-versionados-backend.md) | Cache e logs do Laravel versionados no repositório | 2026-08-21 (fora do fluxo SDD — commit `ec81e9cfd`, anterior) | — | — |
| 030 | [storage-sessions-views-compilados-versionados-backend.md](concluidos/storage-sessions-views-compilados-versionados-backend.md) | Sessões e views compiladas do Laravel versionadas no repositório | 2026-08-21 | storage-sessions-views-versionados | TASK-131 |
| 012 | [expense-manager-installments-nao-projetadas-por-mes.md](concluidos/expense-manager-installments-nao-projetadas-por-mes.md) | Despesas Parceladas não aparecem nos meses seguintes ao de criação, e valor exibido é o total, não a parcela | 2026-08-21 | expense-manager-mes-e-data-corretos | TASK-132 |
| 013 | [expense-manager-data-exibida-com-um-dia-a-menos.md](concluidos/expense-manager-data-exibida-com-um-dia-a-menos.md) | ExpenseManager exibe a data das despesas com 1 dia a menos em fusos negativos | 2026-08-21 | expense-manager-mes-e-data-corretos | TASK-133 |
| 001 | [config-url-api-frontend.md](concluidos/config-url-api-frontend.md) | Configuração de URL da API via variável de ambiente | 2026-08-17 | config-url-api-frontend | TASK-027 |
| 004 | [auth-guard-redirect-frontend.md](concluidos/auth-guard-redirect-frontend.md) | Auth guard / redirect automático | 2026-08-17 | config-url-api-frontend | TASK-028, TASK-029 |
| 002 | [infra-testes-frontend.md](concluidos/infra-testes-frontend.md) | Infraestrutura de testes no frontend | 2026-08-18 | infra-testes-frontend | TASK-030, TASK-031, TASK-032 |
| 011 | [recuperacao-senha-quebra-login-backend.md](concluidos/recuperacao-senha-quebra-login-backend.md) | Fluxo de recuperação de senha sobrescreve a senha antes de garantir entrega do e-mail | 2026-08-21 | recuperacao-senha-login | TASK-122, TASK-123, TASK-124, TASK-125 |
| 023 | [expense-show-update-destroy-ausentes.md](concluidos/expense-show-update-destroy-ausentes.md) | Implementar ExpenseController::show/update/destroy (rotas já registradas sem método) | 2026-08-21 | expense-show-update-destroy | TASK-126, TASK-127, TASK-128 |
| 028 | [invitation-invite-message-key-colisao-mail.md](concluidos/invitation-invite-message-key-colisao-mail.md) | Convite por e-mail quebra quando `message` é preenchido (colisão de chave com `Mail::send` legado) | 2026-08-21 | invitation-message-colisao-mail | TASK-129 |
| 029 | [cors-origin-rede-local-hardcoded-backend.md](concluidos/cors-origin-rede-local-hardcoded-backend.md) | CORS do backend com IP de rede local hardcoded (deveria ser configurável via .env) | 2026-08-21 | cors-origin-rede-local-configuravel | TASK-130 |
| 006 | [expense-manager-groupid-param-mismatch.md](concluidos/expense-manager-groupid-param-mismatch.md) | ExpenseManager nunca carrega despesas (nome de param de rota errado) | 2026-08-18 | fluxo-despesas-grupo | TASK-033, TASK-034, TASK-035 |
| 009 | [despesas-menu-tela-branco-frontend.md](concluidos/despesas-menu-tela-branco-frontend.md) | Tela de despesas abre em branco ao clicar no menu | 2026-08-18 | fluxo-despesas-grupo | TASK-038 |
| 010 | [expense-store-sem-checagem-membership-payer.md](concluidos/expense-store-sem-checagem-membership-payer.md) | POST /api/expenses não checa se user_payer_id/payers são membros do grupo | 2026-08-18 | expense-payer-membership | TASK-039, TASK-040, TASK-041 |
| 017 | [summary-tela-pagamentos.md](concluidos/summary-tela-pagamentos.md) | Criar tela de Pagamentos do grupo | 2026-08-22 (fora do fluxo `/promover-backlog` — feature criada sob demanda) | criacao-tela-pagamentos + pagamentos-grid-pix | — |
| 027 | [dashboard-grouplist-duplicados.md](concluidos/dashboard-grouplist-duplicados.md) | Consolidar Dashboard.tsx e GroupList.tsx (páginas quase duplicadas) | 2026-08-20 (resolvido como parte de uma feature de layout, não por `/promover-backlog`) | atualizacao-layout-paginas | TASK-092 |
| 022 | [summary-sidebar-navegacao-mobile.md](concluidos/summary-sidebar-navegacao-mobile.md) | Navegação alternativa para a sidebar da tela de Resumo em mobile | 2026-08-27 | navegacao-mobile-group-shell | TASK-204, TASK-205, TASK-206, TASK-207 |
| 019 | [summary-tela-configuracoes.md](concluidos/summary-tela-configuracoes.md) | Criar tela de Configurações do grupo | 2026-08-26 (fechado sem promoção — necessidade concreta já resolvida em `GroupForm.tsx`) | — | — |
| 032 | [simpleshelllayout-sidebar-navegacao-mobile.md](concluidos/simpleshelllayout-sidebar-navegacao-mobile.md) | Navegação alternativa para a sidebar do SimpleShellLayout em mobile | 2026-08-27 | navegacao-mobile-simple-shell | TASK-208 |
| 031 | [dashboard-visao-financeira-credor-devedores.md](concluidos/dashboard-visao-financeira-credor-devedores.md) | Dashboard sem dado financeiro (resumo Credor→devedores + Pix por grupo) | 2026-08-27 | dashboard-resumo-credor-devedores | TASK-212, TASK-213, TASK-214 |
| 018 | [summary-tela-relatorios.md](concluidos/summary-tela-relatorios.md) | Criar tela de Relatórios do grupo | 2026-08-27 | relatorios-historico-ciclos | TASK-209, TASK-210, TASK-211 |
| 008 | [workflow-cicd-frontend.md](concluidos/workflow-cicd-frontend.md) | Workflow de CI/CD para o frontend | 2026-08-28 | ci-frontend | TASK-215, TASK-216, TASK-217 |
| 020 | [sistema-notificacoes-frontend.md](concluidos/sistema-notificacoes-frontend.md) | Implementar sistema de notificações | 2026-09-03 | notificacoes-in-app | TASK-260 … TASK-267 |
| 021 | [avatar-foto-usuario.md](concluidos/avatar-foto-usuario.md) | Suportar foto de perfil do usuário | 2026-09-03 | notificacoes-in-app (agrupado com 020) | TASK-268, TASK-269, TASK-270 |
| 041 | [site-footer-logo-novemax-link.md](concluidos/site-footer-logo-novemax-link.md) | Trocar link de e-mail do rodapé do site pelo logo da Novemax | 2026-09-19 | logo-novemax-rodape-site | TASK-292, TASK-293 |
