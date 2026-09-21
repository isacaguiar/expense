# Endurecer enumeração no pré-cadastro e travar o Mailable do código fora da fila

ID: 045
Origem: revisão de segurança de docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/ (achados 5 e 8)
Criado em: 2026-09-19
Prioridade: BAIXA
Status: Aberto

## Descrição

Dois resíduos da revisão de segurança do auto-cadastro, ambos não exploráveis na forma entregue:

1. **Oráculo de cadastro pendente**: `POST /pre-register/resend` responde diferente para um e-mail
   com pré-cadastro pendente (200/429) e sem (422), revelando que alguém está se cadastrando agora.
   É parente da enumeração de e-mail já aceita conscientemente em `POST /register` e
   `POST /forgot-password`, mas expõe uma informação a mais: intenção de cadastro. Deve ser
   endurecido **junto com** os outros dois, não isoladamente — respostas uniformes em toda a
   superfície de auth.
2. **`PreRegisterCodeMail` usa os traits `Queueable`/`SerializesModels`**
   (`backend/app/Mail/PreRegisterCodeMail.php`). Hoje o envio é síncrono (`Mail::to()->send()`),
   então o código em claro nunca é serializado. Se alguém trocar para `->queue()` ou
   `implements ShouldQueue`, o código de 6 dígitos vai em claro para o payload do job
   (`jobs`/`failed_jobs`) — violando `docs/sdd/00-constitution.md` §6.2.

## Por que importa

O item 1 vaza um bit de informação sobre terceiros. O item 2 é uma armadilha: a mudança que o
dispara (colocar e-mail na fila) é exatamente a otimização que alguém vai querer fazer quando o
envio síncrono começar a pesar.

Tipo sugerido: backend
