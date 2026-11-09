# Medir a entrada no grupo por convite (o aceite) no analytics

ID: 135
Origem: feature `docs/feature/20261103-convite-pendente-no-grupo/` (TASK-581, ao corrigir `docs/analytics/README.md` §2 e §4, que diziam que quem é convidado já é membro desde o convite)
Criado em: 2026-11-05
Prioridade: BAIXA
Status: Aberto

## Descrição
- O `docs/analytics/README.md` (§2) explicava que quem é convidado não gera `group_joined` porque já é membro no momento do convite, "então não existe um momento de entrar no navegador". Desde a feature do convite pendente a pessoa só entra ao aceitar: botão **Aceitar** em Meus Grupos (`frontend/src/components/ReceivedInvitations.tsx`, `POST /api/me/invitations/{id}/accept`). É um momento observável no navegador, e hoje **não é medido**: a lista fechada de eventos (`frontend/src/analytics/trackEvent.ts`) é `sign_up`, `group_created` e `expense_created`.
- **Efeito na ativação** (README §4: despesa em até 7 dias depois do `sign_up`). Quem é convidado precisa aceitar antes de lançar despesa, e a janela de 7 dias conta da ativação da conta (`sign_up` com `method: invite`), não do aceite. Quem ativa a conta e demora a aceitar gasta parte da janela, e sem um evento no aceite não dá para separar "ativou a conta e nunca aceitou" de "aceitou e não lançou despesa".
- O texto do README foi corrigido na TASK-581 (a premissa antiga saiu e o limite ficou escrito). **Não foi tocada** a pasta `docs/feature/20261004-metricas-aquisicao-ativacao/` (ainda não arquivada): o `specify.md` §2.5 dela guarda a premissa antiga ("não existe entrar no grupo no cliente") como registro da decisão de 2026-10-04.

## Por que importa
O funil de quem chega por convite ganha um passo novo (ativar a conta, aceitar, lançar despesa), e o analytics só vê o primeiro e o último. Para o piloto de divulgação (itens 063 e 066), convidado é um canal.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Medir o aceite? | A) evento `group_joined` sem parâmetros, disparado em `ReceivedInvitations` depois do 200 do aceite (lista fechada em `trackEvent.ts`, tabela do README §2, política de privacidade se a ação não estiver descrita)<br>B) não criar evento; ler pelo banco (`ex_group_invitations`: `status` e `responded_at`), com um `SELECT` somente leitura ao lado da linha "Contas reais no banco" do relatório | **B** | O banco responde exato e sem depender de consentimento (o GA4 subconta quem recusa o banner), e não cria evento nem texto de privacidade novos. A só vale se o funil do convidado no GA4 passar a importar. |

Relacionado: 066 (métricas de aquisição e ativação), 065, 133 (convite pendente).

Tipo sugerido: doc (se B) ou frontend pequeno com teste (se A).
