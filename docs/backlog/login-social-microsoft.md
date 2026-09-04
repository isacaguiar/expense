# Implementar login social via Microsoft (OAuth)

ID: 015
Origem: docs/feature/concluidas/202608/20260819-novo-layout-tela-login/specify.md §2.3/§3 (botão "Microsoft" como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de login (`novo-layout-tela-login`) inclui um botão "Microsoft" que hoje é só um link visual (`href="#"`), sem integração real. Diferente do Google, não existe nenhuma credencial Microsoft/Azure AD (nem órfã) no projeto hoje — implementar exigiria: (1) decisão de produto sobre oferecer login social via Microsoft (mesma natureza da decisão de `TASK-021`, mas para um provedor novo), (2) registrar uma aplicação no Azure AD / Microsoft Entra ID e gerar client-id/secret próprios, tratando-os como segredo desde o início (nunca versionar em texto puro — ver `00-constitution.md` §5.3), (3) integrar OAuth no backend Laravel (ex.: Socialite com provider Microsoft) e (4) trocar o botão placeholder do frontend por um fluxo funcional.

## Por que importa

Sem isso, o botão "Microsoft" na tela de login fica como promessa visual não cumprida — o usuário clica e nada acontece. Por não haver nenhuma credencial ou decisão prévia (ao contrário do Google), este item depende de uma decisão de produto nova antes de qualquer trabalho técnico.

Tipo sugerido: backend
