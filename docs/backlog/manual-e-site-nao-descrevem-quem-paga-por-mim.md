# Manual e site não descrevem "Quem paga por mim"; o popup do comprovante diz "seu pagamento"

ID: 140
Origem: revisão final da branch da feature `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/`; `MANUAL.md` (seção Pagamentos e o passo 6 do roteiro), `MANUAL.pdf`, `site/public/manual.php` (seção 7, "Pagar e comprovar"), `site/public/recursos.php` (`:47`) e `frontend/src/pages/Payments.tsx` (descrição do popup "Enviar comprovante do Pix")
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature acrescentou o botão **Quem paga por mim** (Participantes), o selo **Você paga por {nome}** e o texto **Enviado por {nome}** (Pagamentos), mas o material voltado ao usuário não fala disso:
- `MANUAL.md` descreve só o devedor: "Em **Valores a pagar**, quem deve usa **Pagar com Pix** … e **Enviar comprovante**" e o passo 6 do roteiro ("o devedor paga por **Pix** e envia o comprovante"). O `MANUAL.pdf` é gerado dele.
- `site/public/manual.php` (seção 7, "Quem deve usa o QR Code…") e `site/public/recursos.php` ("Quem paga anexa o comprovante") não dizem nada errado, mas também não citam a capacidade nova.
- O popup do comprovante, quando quem envia é o responsável, ainda diz "Anexe o comprovante do **seu** pagamento via Pix para confirmar." (a specify §3.6 manda manter os rótulos de hoje, então não foi alterado na feature).

Nada está errado a ponto de induzir erro (o responsável faz o que o devedor faria), mas a capacidade nova não é descoberta por quem só lê o manual ou o site.

## Por que importa
Quem é escolhido recebe um aviso no sino, mas o manual e o site não explicam o que fazer com ele nem como escolher. Não bloqueia.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quando documentar | A) numa task da própria feature, antes do PR<br>B) item próprio, depois da validação local | **B** | A atualização do manual pede capturas de tela novas (`docs/manual/images`) e regerar o PDF, e isso é feito com a tela de Participantes e de Pagamentos rodando com dados reais, o que acontece na validação local; o texto não está errado, só incompleto. |
| D2 | Texto do popup | A) deixar "seu pagamento"<br>B) texto neutro ("o comprovante do pagamento via Pix") | **B** | Serve ao devedor e ao responsável, sem outro texto por caso; é só a descrição do popup (nenhum teste cita esse texto hoje). |

Tipo sugerido: doc (manual, PDF e site) + um ajuste de texto no frontend.
