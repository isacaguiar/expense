# Adicionar campo "categoria" em despesas

ID: 024
Origem: docs/feature/concluidas/202608/20260820-atualizacao-layout-paginas/specify.md (achado ao avaliar o mockup de Despesas)
Criado em: 2026-08-20
Prioridade: BAIXA
Status: Aberto

## Descrição

O mockup de Despesas (`assets/images/screen/desktop.png`, telas "Visualizar"/"Criar"/"Editar despesa") mostra um campo "Categoria" (ex.: "Moradia") em toda despesa. `ex_expenses` (`backend/database/migrations/2025_06_12_013849_create_ex_expenses_table.php`) não tem nenhuma coluna equivalente hoje — precisaria de migration aditiva + campo no formulário de criar/editar + exibição na listagem/detalhe.

## Por que importa

É só cosmético até alguém precisar filtrar/relatar despesas por categoria (ex.: futura tela de Relatórios, backlog `018`). Sem uso real hoje, não vale abrir migration só por causa do mockup.

Tipo sugerido: backend
