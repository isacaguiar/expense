# Painel pessoal e `GET /me/overview` não enxergam quem paga por quem

ID: 139
Origem: docs/feature/concluidas/202611/20261109-responsavel-do-devedor/specify.md RK7 e D6 (o alcance da primeira entrega ficou só na tela Pagamentos do grupo); `frontend/src/components/GroupPositionCard.tsx` (`:106-112`, assume o usuário logado como devedor de tudo) e `OverviewController::show` (`GET /me/overview`, `backend/routes/api.php:40`)
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Aberto

## Descrição
Com a feature "quem paga por quem", um membro pode enviar o comprovante dos acertos de quem o escolheu, mas só na tela **Pagamentos** do grupo. O **Painel pessoal** (`GroupPositionCard`) e `GET /me/overview` continuam tratando o usuário logado como devedor de tudo: o responsável não vê ali o que deve pagar pelo dependente, nem o selo, nem os botões. Hoje ele precisa abrir o grupo e a tela Pagamentos.

## Por que importa
O responsável pode esquecer de um acerto do dependente por não aparecer no Painel, que é a tela inicial. Não bloqueia nada: o caminho por Pagamentos funciona.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quando fazer | A) deixar como está até haver demanda<br>B) acrescentar ao Painel uma seção "Você paga por {nome}" | **A** agora, B se o uso mostrar que o responsável esquece | B muda o contrato de `GET /me/overview` (campo novo, aditivo) e o `GroupPositionCard`, com teste nos dois lados; é uma feature pequena, não um ajuste. |

Tipo sugerido: backend (campo aditivo em `/me/overview`) + frontend (Painel).
