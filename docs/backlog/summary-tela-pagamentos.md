# Criar tela de Pagamentos do grupo

ID: 017
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.3/R2 (item "Pagamentos" da sidebar como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de Resumo (`novo-layout-tela-entrada`) inclui uma sidebar com o item "Pagamentos", que hoje não tem nenhuma tela/rota correspondente no `frontend/` nem endpoint dedicado no backend — fica como link visual desabilitado. Implementar de fato exigiria definir o que "Pagamentos" significa no domínio (marcar quota como paga? histórico de cobranças Pix?) antes de criar rota, tela e endpoint.

## Por que importa

Sem isso, o item "Pagamentos" da sidebar fica como promessa visual não cumprida. Depende de decisão de produto sobre o escopo exato (relaciona-se com o achado 2.6 do `specify.md` de `docs/feature/20260818-resumo-grupo-dashboard/`, sobre não existir endpoint para marcar `Quota.paid`).

Tipo sugerido: frontend
