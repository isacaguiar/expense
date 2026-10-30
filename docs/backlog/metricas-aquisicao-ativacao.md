# Medir aquisição, cadastro e ativação por canal

ID: 066
Origem: conversa com o usuário em 2026-10-04 sobre divulgação gratuita do Expense
Criado em: 2026-10-04
Prioridade: MEDIA
Status: Promovido para TASK-383

## Descrição
Evoluir a instrumentação existente para acompanhar o caminho entre visita, cadastro concluído e primeiro uso de um grupo. Definir atribuição por canal e eventos de conversão para avaliar páginas, comunidades, conteúdo e indicações, reaproveitando o analytics e o consentimento já implementados.

## Por que importa
Visitas isoladas não mostram quais canais trazem pessoas que usam o produto. A medição deve estar pronta antes do piloto de divulgação para orientar a priorização das próximas melhorias.

## Escopo sugerido
- Inventariar os eventos existentes antes de adicionar novos e padronizar parâmetros UTM de campanha.
- Definir ativação, considerando criação ou entrada em grupo e primeiro uso relevante de despesas, sem penalizar quem entra por convite.
- Medir cadastro concluído e ações relevantes apenas após confirmação de sucesso, evitando duplicação em recargas.
- Definir relatório por canal com visitas, cadastros, ativações e limites conhecidos da atribuição entre site e app.
- Respeitar o consentimento existente, excluir tráfego de desenvolvimento e não enviar e-mail, nome, dados financeiros ou tokens aos serviços de analytics.

## Critérios para promoção
- Definições dos eventos, ativação e atribuição aprovadas.
- Eventos conferidos nos fluxos reais, incluindo falhas e recusa ou revogação do consentimento.
- Relatório permite comparar os canais do piloto e explicita que usuários sem consentimento não estão integralmente representados.

Dependência de contexto: docs/feature/concluidas/202609/20260920-analytics-app-e-consentimento/ (analytics e consentimento existentes).
Relacionados: 061, 062, 063 e 065.

Tipo sugerido: frontend
