# Criar tela de Configurações do grupo

ID: 019
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.3/R2 (item "Configurações" da sidebar como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto (parcialmente obsoleto — ver nota 2026-08-26)

> Nota 2026-08-26 (revisão de backlog): a única necessidade concreta que este item registrava — "o dia de fechamento do grupo não tem lugar na UI para ser editado depois da criação" — já foi resolvida: `GroupForm.tsx` (rota `/groups/:id/edit`, acessível pelo ícone de editar na tabela de grupos) tem o campo "Dia de fechamento (opcional)" desde a feature `20260821-melhoria-menu-tela-grupos-perfil`. O que sobra deste item é genérico ("outras configurações que o mockup sugere"), sem escopo concreto. Recomendação: fechar, ou reabrir só quando houver uma configuração específica nova a adicionar.

## Descrição

O novo layout da tela de Resumo (`novo-layout-tela-entrada`) inclui uma sidebar com o item "Configurações", sem tela dedicada hoje — existe apenas `GroupForm.tsx` (editar nome/descrição do grupo), que não cobre outras configurações que a tela do mockup sugere (ex.: dia de fechamento do ciclo, ver `docs/feature/20260818-resumo-grupo-dashboard/specify.md` R1).

## Por que importa

Sem isso, o item "Configurações" da sidebar fica como promessa visual não cumprida, e a configuração de dia de fechamento do grupo (já existente no backend) fica sem um lugar natural na UI para ser editada depois da criação do grupo.

Tipo sugerido: frontend
