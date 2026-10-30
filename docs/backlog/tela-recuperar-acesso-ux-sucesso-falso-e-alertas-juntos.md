# Tela `/recuperar-acesso`: e-mail malformado mostra sucesso, e "Reenviar link" mostra os alertas verde e vermelho juntos

ID: 123
Origem: docs/feature/20260930-recuperacao-acesso/specify.md e revisão de segurança de 2026-10-30 (F9); `frontend/src/pages/ForgotPasswordPage.tsx:33`, `frontend/src/pages/forgot-password/ForgotPasswordFormCard.tsx:110`
Criado em: 2026-10-30
Prioridade: BAIXA
Status: Aberto

## Descrição
- **Falso sucesso.** O formulário usa `noValidate`, sem validação no cliente, e o 422 é tratado como "se esse e-mail estiver cadastrado…" (`ForgotPasswordPage.tsx:33`). Quem digita um endereço com formato inválido (por exemplo, sem `@`) recebe a mensagem de sucesso e espera um e-mail que nunca sai.
- **Alertas juntos.** Clicar em "Reenviar link" logo depois do sucesso (`ForgotPasswordFormCard.tsx:110`) cai sempre no 429 do backend, e como `success` não é zerado a tela mostra o alerta verde e o vermelho ao mesmo tempo.

Proposta: validar o formato no cliente antes de chamar a API (só um e-mail bem formado chega ao servidor, e aí o 422 passa a significar "conta inexistente") e, ao reenviar, zerar `success` ou desabilitar o botão durante os 15 minutos. Combina com o item 119.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Não muda a API nem a mensagem de sucesso genérica. |
| R2 | Os testes novos cobrem o formato inválido e o reenvio seguido de 429; `ForgotPasswordPage.test.tsx` segue valendo. |

## Decisões em aberto
Nenhuma: a correção não depende de decisão de produto.

## Por que importa
Quem erra o formato do e-mail fica sem saber que errou, e é justamente a pessoa que está tentando recuperar o acesso.

Tipo sugerido: frontend
