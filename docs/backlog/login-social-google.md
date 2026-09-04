# Implementar login social via Google (OAuth)

ID: 014
Origem: docs/feature/concluidas/202608/20260819-novo-layout-tela-login/specify.md §2.3/§3 (botão "Google" como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de login (`novo-layout-tela-login`) inclui um botão "Google" que hoje é só um link visual (`href="#"`), sem integração real. Implementar login social via Google exigiria: (1) resolver `TASK-021` (`docs/sdd/03-tasks.md`) — decisão de produto sobre implementar login social de fato ou remover de vez as referências —, (2) rotacionar as credenciais Google OAuth órfãs já registradas em `00-constitution.md` §5.3 (nunca reaproveitar as que vazaram em `client_secret_*.json`/`README.md` raiz), (3) integrar OAuth no backend Laravel (ex.: Socialite) e (4) trocar o botão placeholder do frontend por um fluxo funcional.

## Por que importa

Sem isso, o botão "Google" na tela de login fica como promessa visual não cumprida — o usuário clica e nada acontece. A implementação depende de uma decisão de produto ainda pendente (`TASK-021`); não deve ser iniciada antes dela, nem antes de rotacionar as credenciais expostas.

Tipo sugerido: backend
