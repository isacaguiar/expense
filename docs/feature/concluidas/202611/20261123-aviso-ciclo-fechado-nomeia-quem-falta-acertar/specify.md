# Specify — Aviso de ciclo fechado nomeia quem falta acertar

> Feature: o aviso de ciclo fechado passa a dizer, em uma linha por acerto pendente, quem ainda não pagou e a quem. Origem: item 161 do backlog (`docs/backlog/aviso-de-ciclo-fechado-nomeia-quem-falta-acertar-com-texto-mais-claro.md`), pedido dele em 2026-11-20.

Versão: 1.1 · Criado em: 20261123 · v1.1: D5-A e D6-A decididas por ele (lista completa, sem valor)

---

## 1. Problema

Depois que o ciclo fecha, o `CycleClosingAlert` mostra só nomes soltos: "O ciclo fechou e ainda falta acertar: Ana, Davi." Não diz a quem cada pessoa deve, então quem quer cobrar precisa abrir a tela de Pagamentos. O pedido é uma mensagem informativa direta, que diga quem ainda não pagou o acerto e a quem.

## 2. Requisitos

Decisões dele (2026-11-23): D1-B, D2-B, D3-A, D4-A, escopo só o item 161.

### 2.1 Estado atual (conferido em `frontend/src/components/CycleClosingAlert.tsx`)

- Pré-fechamento (`info`): "Este ciclo fecha em DD/MM. Registre e acerte as despesas até lá." Não muda (D4-A).
- Pós-fechamento (`warning`, linhas 69-77): só com o ciclo `closed`/`closed_manually`, não selado (`!cycle.settled`) e com ao menos um acerto sem `confirmedAt`. O texto lista os devedores (`pendingDebtorNames`, linhas 29-37), cada um uma vez; o nome vem de `balances`, com "Desconhecido" de reserva.
- Cada `summary.settlements[]` já traz `from_user_id`, `to_user_id` e `confirmedAt` (`hooks/useGroupCycle.ts:113-120`), então a API não muda.

### 2.2 Nova mensagem pós-fechamento (D1-B, D2-B)

- Uma linha por acerto sem confirmação, no formato "**<devedor> ainda não pagou o acerto a <credor>.**" (par devedor → credor), em vez da lista de nomes.
- Acerto pendente é o mesmo critério de hoje: `confirmedAt` ausente. A condição de aparecer (ciclo `closed`/`closed_manually`, não selado, ao menos um pendente) não muda.
- O nome do credor vem de `balances` pelo `to_user_id`, com "Desconhecido" de reserva, como o do devedor.
- Quem tem mais de um credor aparece em uma linha por credor.
- Ordem das linhas: a dos acertos em `settlements`.
- Continua num único `Alert` `warning`, com um título curto antes das linhas ("O ciclo fechou e ainda falta acertar:").

### 2.3 Onde e quando (D3-A, D4-A)

- Só no `CycleClosingAlert`, que já aparece na Home do grupo, em Despesas e no ExpenseView. Pagamentos e Relatórios não mudam.
- Só depois do fechamento e antes de selar, como hoje.

### 2.4 Decisões fechadas por ele (2026-11-23)

| # | Decisão | Escolha | Efeito |
|---|---|---|---|
| D5 | Mostrar o valor | **A) não** | A linha diz só "Ana ainda não pagou o acerto a João."; o valor fica em Pagamentos. |
| D6 | Muitos pendentes | **A) lista completa** | Uma linha por acerto pendente, sem corte nem "e mais N". Ele considerou a ideia de um aviso genérico ("há contas em aberto") e manteve a lista nomeada. |

Premissa: o item sugeria a frase "<nome> não possui conta em aberto" para quem está em dia. Pela D2-B ela não entra: o aviso lista só quem falta.

## 3. Fora de escopo desta feature

- Mudar o aviso de pré-fechamento, o critério de acerto pendente ou quando o ciclo sela.
- Mostrar o aviso em Pagamentos ou Relatórios (D3-A).
- Dizer quem está em dia (D2-B).
- Qualquer mudança de backend ou de API.
- Outros itens do backlog (escopo só o 161).
