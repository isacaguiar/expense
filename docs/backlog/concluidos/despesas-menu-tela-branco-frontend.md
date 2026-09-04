# Tela de despesas abre em branco ao clicar no menu

ID: 009
Origem: solicitação direta do usuário (conversa), 2026-08-18
Criado em: 2026-08-18
Prioridade: ALTA
Status: Promovido para TASK-033

## Descrição

Ao clicar no link "despesas" no menu do frontend, a tela abre em branco. Comportamento esperado: mostrar os grupos disponíveis para o usuário selecionar; se o usuário tiver apenas um grupo, redirecionar automaticamente para a tela de despesas desse grupo.

## Por que importa

Impede o acesso à tela de despesas pelo fluxo principal de navegação (menu) — funcionalidade central do produto fica inacessível dessa forma.

Tipo sugerido: frontend

## Resolução

Concluído em: 2026-08-18
Feature: docs/feature/concluidas/202608/20260818-fluxo-despesas-grupo/
Tasks: TASK-038 (rota `/expenses` com `ExpensesEntry.tsx` — seleção de grupo quando há mais de um, redirect automático quando há exatamente um, mensagem informativa quando não há nenhum; sem tela em branco em nenhum dos três casos)
PRs: #20 (mergeado em `dev`)
