# Specify — Ver o comprovante em um modal

> Feature: ao clicar num link de comprovante, mostrar a imagem num modal sobre a tela atual, em vez de abrir outra aba. Origem: pedido dele em 2026-11-16, registrado como item 158 do backlog (`docs/backlog/ver-comprovante-em-modal-em-vez-de-nova-pagina.md`); escopo "só o 158", confirmado por ele em 2026-11-20.

Versão: 1.0 · Criado em: 20261120

---

## 1. Problema

Hoje cada conferência de comprovante tira a pessoa da tela onde estava: o chip **"Comprovante enviado"** (e os outros links de comprovante) abre a imagem **em outra aba**. Em celular a aba nova fica empilhada ao app e é fácil se perder. O credor, que logo em seguida decide entre **Aceitar** e **Recusar**, perde o contexto da lista de acertos. Com um modal, ao fechar a pessoa continua exatamente onde estava (mesma lista, mesma rolagem).

É mudança só de frontend: a URL do comprovante já vem assinada da API (ADR-005, 30 minutos) e o upload já só aceita imagem (feature `20261116-endurecer-upload-de-comprovante`, `v1.8.0`). Nenhum endpoint muda.

## 2. Requisitos

### 2.1 Componente único `ProofViewerDialog`

Um diálogo MUI reaproveitável, no padrão dos já existentes (`RefuseReasonDialog`, `SettlementComplementDialog`), que recebe a URL e um título e mostra o comprovante:

- `<img>` com `object-fit: contain`, limitada à altura da tela, sem rolagem horizontal; `alt` descritivo.
- Título e botão **Fechar**; `Esc` e o clique fora também fecham; o foco volta ao elemento que abriu o modal.
- `fullScreen` abaixo de `sm`.
- **Estado de erro** quando a imagem não carrega (URL assinada vencida depois de 30 minutos com a tela aberta, ou arquivo removido): "Não foi possível carregar o comprovante. Atualize a página.", em vez de imagem quebrada.
- Estado de carregamento enquanto a imagem não chega.
- Link secundário **"Abrir em nova aba"** dentro do modal (D2), para quem quer ampliar ou salvar a foto.

### 2.2 Pontos de uso (conferidos no código em 2026-11-20)

Hoje todos usam link `target="_blank"` para a URL assinada:

| # | Onde | Arquivo:linha | Quem vê |
|---|---|---|---|
| 1 | Chip "Comprovante enviado" (`confirmedProofUrl`) | `components/PayableSettlementList.tsx:269` | quem paga |
| 2 | Chip "Comprovante enviado" (`confirmed_proof_url`) | `components/ReceivableList.tsx:57` | credor |
| 3 | "Ver comprovante recusado" | `components/PayableSettlementList.tsx:371` | quem paga |
| 4 | "Ver comprovante" do histórico do acerto | `components/SettlementProofHistory.tsx:88` | credor e devedor |
| 5 | Botão de imagem "Ver comprovante" na coluna de ações | `pages/ExpenseManager.tsx:404` | quem gerencia |
| 6 | "Ver comprovante" no detalhe da despesa | `pages/ExpenseManager.tsx:801` | quem gerencia |
| 7 | "Ver comprovante" na visualização da despesa | `pages/ExpenseView.tsx:561` | membro |
| 8 | "Ver comprovante" na lista de pagamentos | `pages/Payments.tsx:358` | membro |
| 9 | "Ver foto atual" dentro de `PaymentProofDialog` | `components/PaymentProofDialog.tsx:181` | quem edita o comprovante |

O backlog citava 8 pontos; a conferência achou o **9º** (`PaymentProofDialog`), que abre um diálogo dentro de outro caso vire modal (D5). O ramo sem URL do chip (arquivo removido) não é link e não muda.

### 2.3 Comportamento que já vale (não é decisão)

- O comprovante é sempre imagem (JPG, JPEG, PNG, GIF, WEBP). Comprovantes antigos podem ser SVG: dentro de `<img>` o SVG não executa script, e o download já sai com `nosniff` e CSP `sandbox`.
- A leitura continua pela mesma URL assinada que a tela já recebe; o modal não pede URL nova.
- Acessibilidade: diálogo com título, foco devolvido, `alt`; teclado completo.
- Medir o layout em 375, 768, 1024, 1280 e 1440 px, como nas outras telas.

## 2.4 Decisões em aberto (a fechar por ele neste specify)

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Escopo | A) só os 2 chips "Comprovante enviado" (1 e 2)<br>B) os 8 pontos de link (1 a 8)<br>C) os 9, inclusive o do diálogo | **B**, em tasks por tela | O pedido cita um link, mas o componente é um só; deixar metade em aba e metade em modal seria inconsistente. O 9 (D5) fica de fora por ser modal sobre modal. |
| D2 | "Abrir em nova aba" dentro do modal | A) sim, link secundário<br>B) não | **A** | Quem quer ampliar ou salvar continua podendo. |
| D3 | Zoom | A) só `object-fit: contain`<br>B) clique para ampliar | **A** | Atende o pedido sem biblioteca; B vira item depois. |
| D4 | URL expirada | A) estado de erro com instrução<br>B) buscar URL nova ao abrir | **A** | Renovar exige endpoint novo ou recarregar o resumo; o erro basta para o caso raro. |
| D5 | "Ver foto atual" dentro de `PaymentProofDialog` | A) mantém o link em nova aba<br>B) abre o `ProofViewerDialog` por cima do diálogo de edição | **A** | Modal sobre modal complica foco e `Esc`; a pessoa ali já está editando e a aba nova não a tira de lugar nenhum. Se preferir consistência total, B (e sobe para a opção C da D1). |

## 3. Fora de escopo desta feature

- Qualquer mudança de backend, de URL assinada, de rota ou de upload.
- Zoom, rotação, galeria com setas entre comprovantes e download pelo botão (D3).
- Renovar a URL vencida sem recarregar a página (D4).
- Itens de backlog **157** (`public/storage` em produção) e **159** (`AvatarStorage`): não tocam esta tela.
- Foto de perfil (avatar): não é comprovante.
