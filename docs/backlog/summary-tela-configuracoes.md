# Criar tela de Configurações do grupo

ID: 019
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.3/R2 (item "Configurações" da sidebar como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de Resumo (`novo-layout-tela-entrada`) inclui uma sidebar com o item "Configurações", sem tela dedicada hoje — existe apenas `GroupForm.tsx` (editar nome/descrição do grupo), que não cobre outras configurações que a tela do mockup sugere (ex.: dia de fechamento do ciclo, ver `docs/feature/20260818-resumo-grupo-dashboard/specify.md` R1).

## Por que importa

Sem isso, o item "Configurações" da sidebar fica como promessa visual não cumprida, e a configuração de dia de fechamento do grupo (já existente no backend) fica sem um lugar natural na UI para ser editada depois da criação do grupo.

Tipo sugerido: frontend
