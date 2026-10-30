# Aviso de possível duplicidade no cadastro de despesa

ID: 112
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/specify.md §3.4 (D14) e §3.5 (F7, F15); `frontend/src/pages/ExpenseForm.tsx`, `backend/app/Http/Controllers/ExpenseController.php` (`store`)
Criado em: 2026-10-27
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature `20261027-pagamento-no-cadastro-despesa` protege o cadastro contra reenvio com uma chave de idempotência (`client_request_id`), mas a chave é por formulário. **Duas abas** com o mesmo formulário e **recarregar a página** depois de uma resposta perdida geram chaves diferentes e, portanto, despesas duplicadas. Esta ideia cobre essas lacunas:

- **Aviso por chave natural:** quando o mesmo criador já cadastrou, há poucos minutos, uma despesa com o mesmo grupo, descrição, valor, data e credor, o formulário avisa "parece que você acabou de cadastrar esta despesa" e pede confirmação para cadastrar mesmo assim.
- **Chave persistida em `sessionStorage`**, para sobreviver a um recarregamento do mesmo formulário.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | O aviso nunca bloqueia: gastos legítimos repetidos (dois cafés no mesmo dia) continuam possíveis com confirmação. |
| R2 | A idempotência por chave continua sendo a proteção principal; isto é camada extra. |
| R3 | Contrato aditivo (Constitution §4 item 1). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde checar | A) endpoint de checagem consultado antes de salvar<br>B) `409` do `POST /expenses` com um campo de confirmação para forçar | **A** | B mistura a recusa com a regra de idempotência, que já usa `409`. |
| D2 | Janela de tempo | A) 5 minutos<br>B) 30 minutos | **A** | Cobre reenvio e segunda aba, sem acusar o gasto repetido do dia seguinte. |

## Por que importa
Fecha a duplicidade que a chave não alcança. Só vale a pena se os limites declarados na feature de origem incomodarem na prática; por isso a prioridade é baixa.

Tipo sugerido: backend + frontend
