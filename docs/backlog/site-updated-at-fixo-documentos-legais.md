# `updated_at` dos documentos legais é uma string fixa

ID: 051
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/specify.md §3
Criado em: 2026-09-20
Prioridade: BAIXA
Status: Promovido para TASK-321

## Descrição
`site/src/config.php` tem `'updated_at' => '24 de agosto de 2026'`, exibido como "Última atualização" tanto em `termos.php` quanto em `privacidade.php`. É um valor único para os dois documentos, atualizado à mão.

## Por que importa
Dois problemas que só aparecem com o tempo. Primeiro: editar um dos documentos sem lembrar de mexer no `config.php` faz o site declarar uma data de atualização falsa — justamente no tipo de documento em que a data importa juridicamente. Segundo: os dois documentos compartilham a mesma data mesmo quando só um muda. A correção natural é uma data por documento, e idealmente derivada da modificação real do arquivo.

Tipo sugerido: frontend
