# Botão "Informar pagamento" do `GroupGrossDebtsPanel` é só visual: definir o destino

ID: 088
Origem: docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/plan.md §9 e tasks.md (TASK-425); `frontend/src/components/GroupGrossDebtsPanel.tsx` (`markInformed`, botão com `aria-label` "Informar pagamento de … para …")
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
Na linha expansível de cada grupo em "Meus grupos" (`/meus-grupos`), cada par credor → devedor tem um botão **"Informar pagamento"**. O clique só executa `markInformed`, que adiciona a chave `"{credorId}-{devedorId}"` a um `Set` guardado em `useState` (`informedKeys`) e troca o botão por um chip verde **"Informado"**. Não chama a API, não notifica o credor e não grava nada. O estado some ao recarregar a página e é zerado ao trocar de ciclo.

O botão aparece em **todas** as linhas de devedor do grupo, inclusive nas que não são do usuário logado, e o chip "Informado" sugere que alguém foi avisado, o que não aconteceu.

Antes do Painel isso era um rascunho de interface. Agora há o caminho de verdade: no `/painel` o devedor envia o comprovante do acerto líquido (`POST /groups/{id}/settlements/confirm`) e o credor marca a despesa como paga; as duas ações também existem em Pagamentos. O botão do `GroupGrossDebtsPanel` ficou como um terceiro caminho que não faz nada. Conferido como **inalterado** na validação local da feature do Painel (`implementation.md`), de propósito: mexer nele estava fora do escopo.

Observação sobre o valor: a árvore mostra o valor **bruto** por par (só o que está em aberto, sem o netting), e o Painel mostra o acerto **líquido** (decisão D1 do Painel). Quem for do botão para uma tela de comprovante vê um número diferente do que clicou.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Nenhuma tela do produto mostra um estado de "pagamento informado" que não foi gravado nem comunicado ao outro lado. |
| R2 | O envio de comprovante e a marcação como paga continuam só nos fluxos que chamam a API (`confirmSettlement`, `pay`, `unpay`). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Destino do botão | A) remover o botão (e o chip "Informado")<br>B) trocar por um link "Enviar comprovante" que leva ao `/painel` (ou a Pagamentos do grupo)<br>C) manter como está | **A** | O estado não persiste e engana (parece um aviso enviado). O caminho real já existe e está a um clique do menu. B exige decidir para qual tela ir e como tratar bruto × líquido, sem ganho claro. C mantém um botão que não faz o que diz. |
| D2 | Se for A: o ícone de Pix da mesma linha | A) manter<br>B) remover junto | **A** | O Pix abre o `PixPaymentDialog` e gera o QR de verdade; só o botão "Informar" é cosmético. |

## Por que importa
É um botão que parece funcionar e não faz nada, ao lado de um fluxo real que o Painel passou a oferecer. O risco é o usuário achar que avisou o credor. Custo baixo de corrigir, mas muda uma tela existente, então passa pelo specify.

Relacionado: 089 (outro resto de tela/rota que o Painel deixou para trás).

Tipo sugerido: frontend
