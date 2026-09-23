# Implementar login social via Google (OAuth)

ID: 014
Origem: docs/feature/concluidas/202608/20260819-novo-layout-tela-login/specify.md §2.3/§3 (botão "Google" como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Promovido para TASK-357

## Descrição

O novo layout da tela de login (`novo-layout-tela-login`) inclui um botão "Google" que hoje é só um link visual (`href="#"`), sem integração real. Implementar login social via Google exigiria: (1) resolver `TASK-021` (`docs/sdd/03-tasks.md`) — decisão de produto sobre implementar login social de fato ou remover de vez as referências —, (2) rotacionar as credenciais Google OAuth órfãs já registradas em `00-constitution.md` §5.3 (nunca reaproveitar as que vazaram em `client_secret_*.json`/`README.md` raiz), (3) integrar OAuth no backend Laravel (ex.: Socialite) e (4) trocar o botão placeholder do frontend por um fluxo funcional.

## Por que importa

Sem isso, o botão "Google" na tela de login fica como promessa visual não cumprida — o usuário clica e nada acontece. A implementação depende de uma decisão de produto ainda pendente (`TASK-021`); não deve ser iniciada antes dela, nem antes de rotacionar as credenciais expostas.

Tipo sugerido: backend

## Resolução
Concluído em: 2026-09-23
Feature: docs/feature/20260821-login-social-google/ (migra para docs/feature/concluidas/202608/ quando o PR mergear em `dev` — ADR-009; pasta ficou dois meses só com `specify.md`, retomada em 2026-09-23)
Tasks: TASK-357 a TASK-370
PRs: https://github.com/isacaguiar/expense/pull/199

A decisão de produto (`TASK-021`) já tinha sido tomada em 2026-08-21: implementar,
só para Google. O que ficou parado foi a execução. Entre agosto e a retomada, outra
feature (`atualizacao-minha-conta`) já tinha construído boa parte da infraestrutura
(Socialite, colunas, callback) para o caso de vínculo — esta feature estendeu esse
mesmo callback para o caso de login, em vez de recriar. A revisão de segurança
(TASK-368) achou um problema real no meio do caminho: o auto-vínculo por e-mail
confiava que "o Google só devolve e-mail verificado" sem checar isso de fato,
permitindo takeover de conta local — corrigido nas TASK-369/370 antes do PR.
Rotação das credenciais órfãs (item 2 da descrição original) segue como debt
separado, fora do escopo desta feature (`specify.md` §3) — as credenciais em uso
já são novas, criadas depois do vazamento.
