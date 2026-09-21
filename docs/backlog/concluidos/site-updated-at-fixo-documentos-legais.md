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

## Resolução
Concluído em: 2026-09-21
Feature: docs/feature/concluidas/202609/20260920-data-por-documento-legal/ (migra para lá quando o PR mergear em `dev` — ADR-009)
Tasks: TASK-321 a TASK-327
PRs: https://github.com/isacaguiar/expense/pull/177

O item pedia "uma data por documento, idealmente derivada da modificação real do
arquivo". Foi o que saiu, com um ajuste que a execução revelou: derivar do arquivo
da *página* dataria os Termos pelo commit de uma metatag de SEO, então o texto
jurídico passou a viver em arquivo próprio, e é o histórico dele que manda.
