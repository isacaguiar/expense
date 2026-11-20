# Abrir o comprovante em um modal ao clicar em "Comprovante enviado", em vez de uma nova página

ID: 158
Origem: pedido dele em 2026-11-16 (conversa da feature `20261116-endurecer-upload-de-foto-de-perfil`); `frontend/src/components/PayableSettlementList.tsx:269-276` e `frontend/src/components/ReceivableList.tsx:57-67`
Criado em: 2026-11-16
Prioridade: MEDIA
Status: Promovido para TASK-653 (feature 20261120-ver-comprovante-em-modal)

## Descrição
Hoje o chip **"Comprovante enviado"** é um link (`component="a"`, `target="_blank"`) para a URL assinada do arquivo: abre o comprovante **em outra aba**, longe da tela de acertos. O pedido: ao clicar, abrir um **modal** que mostra o comprovante, sem sair da página.

Onde o chip aparece, conferido no código em 2026-11-16:

- `components/PayableSettlementList.tsx:269` (devedor/quem paga vê "Comprovante enviado", com `settlement.confirmedProofUrl`); o ramo sem URL (arquivo removido) não é link e não muda.
- `components/ReceivableList.tsx:57` (credor, com `receivable.confirmed_proof_url`).

Outros pontos usam o mesmo padrão de link em nova aba e **não** estão no pedido; ficam como extensão a decidir (D1): "Ver comprovante recusado" (`PayableSettlementList.tsx:371`), "Ver comprovante" do histórico (`SettlementProofHistory.tsx:88`), a coluna e o detalhe de `ExpenseManager.tsx` (404 e 801), `ExpenseView.tsx:561` e `Payments.tsx:358`.

Pontos a cuidar na implementação (não são decisões, já valem):

- O comprovante é sempre **imagem** (JPG, JPEG, PNG, GIF ou WEBP; o upload recusa SVG desde a `v1.8.0`); o modal mostra uma `<img>`. Comprovantes antigos podem ser SVG: dentro de `<img>` o SVG não executa script, e o download já sai com `nosniff` e CSP `sandbox`.
- A URL é **assinada e vale 30 minutos** (ADR-005): se a tela ficar aberta além disso, a imagem não carrega. O modal precisa de um estado de erro ("Não foi possível carregar o comprovante. Atualize a página.") em vez de uma imagem quebrada.
- Acessibilidade: diálogo MUI com título, botão "Fechar", `Esc` fecha, foco volta ao chip, `alt` descritivo na imagem.
- Em tela pequena o modal deve ocupar a tela (`fullScreen` abaixo de `sm`) e a imagem caber sem rolagem horizontal (medir em 375, 768, 1024, 1280 e 1440 px, como nas outras telas).
- Reaproveitar o padrão de `Dialog` já usado no projeto (por exemplo `RefuseReasonDialog` e `SettlementComplementDialog`) e criar um componente único (`ProofViewerDialog`) usado pelos dois chips, para não duplicar.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Escopo | A) só os dois chips "Comprovante enviado"<br>B) todos os links de comprovante (recusado, histórico, despesas, detalhe, pagamentos) | **B**, em tasks separadas por tela | O pedido cita um link, mas a mesma troca vale para todos e o componente é um só; deixar metade abrindo em aba e metade em modal seria inconsistente. Se preferir enxuto, A primeiro e o resto vira item novo. |
| D2 | Botão "Abrir em nova aba" dentro do modal | A) sim, como link secundário<br>B) não | **A** | Quem quer ampliar ou salvar a foto continua podendo; o padrão é o modal. |
| D3 | Zoom da imagem | A) só `object-fit: contain` na altura da tela<br>B) clique para ampliar | **A** | Atende o pedido sem biblioteca; B pode virar item depois. |
| D4 | URL expirada | A) estado de erro no modal, sem renovar<br>B) buscar uma URL nova ao abrir | **A** | Renovar exige endpoint novo ou recarregar o resumo; o erro com instrução é suficiente para o caso raro. |

## Por que importa
Hoje cada conferência de comprovante tira a pessoa da tela de acertos, e em celular a nova aba fica empilhada ao app. Com o modal, ao fechar a pessoa continua exatamente onde estava (mesma lista, mesma rolagem), inclusive o credor, que decide logo em seguida entre Aceitar e Recusar.

Tipo sugerido: frontend (sem mudança de backend)
