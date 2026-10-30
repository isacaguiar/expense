# Uniformizar as respostas de `/forgot-password` e `/invitations/verify` (enumeração de e-mail, oráculo do 429 e do 500)

ID: 119
Origem: docs/feature/20260930-recuperacao-acesso/specify.md §3 e revisão de segurança de 2026-10-30 (F2); `backend/app/Http/Controllers/InvitationController.php` (linhas 18, 65, 73-75, 100-102), `frontend/src/pages/ForgotPasswordPage.tsx:33-39`, item 045 do backlog
Criado em: 2026-10-30
Prioridade: BAIXA
Status: Aberto

## Descrição
`forgotPassword` valida `exists:ex_users,email` (linha 65): e-mail inexistente dá **422** com a mensagem padrão do framework, em inglês (o locale é `en` e não há pasta `lang/`); e-mail existente dá **200** em português, ou **429** (linhas 73-75), ou **500** (linhas 100-102). O `verify` repete o mesmo `exists` (linha 18). Quem chama a API direto distingue as duas situações por status e corpo. Dois testes fixam esse comportamento: `test_forgot_password_with_unknown_email_returns_422_not_500` e `test_verify_with_unknown_email_returns_422_not_500` (`InvitationControllerValidationTest`).

A tela nova (`ForgotPasswordPage.tsx:33-39`) esconde o 422 mostrando sucesso genérico, mas **mostra a mensagem do backend no 429 e no 500**, e esses dois só existem para conta existente: enviar o mesmo e-mail duas vezes seguidas e ver "você já solicitou recentemente" confirma que a conta existe. O comentário da tela e o `specify.md` diziam que isso não piorava a enumeração; é verdade para a API e incompleto para a tela. **Risco aceito na feature de origem** (opção A da revisão), com o conserto neste item.

Mesmo uniformizando status e corpo, o **tempo de resposta** pode distinguir: o envio é síncrono, então conta existente espera o SMTP (inferência do código; **não medido**). O `/pre-register` também enumera (`PreRegisterRequest.php:26`, `unique`); o item 045 trata isso como "enumeração aceita conscientemente" e pede "respostas uniformes em toda a superfície de auth": este item o estende.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A resposta de sucesso é a mesma para conta existente e inexistente, na API e na tela. |
| R2 | O envio do e-mail sai do caminho da resposta (fila ou `afterResponse`), para o tempo não distinguir. |
| R3 | Troca os dois testes citados e o tratamento de 422/429/500 na tela; contrato de rota muda (Constitution §4 item 1), então precisa de decisão explícita. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como uniformizar | A) sempre 200 genérico, limite por e-mail silencioso, envio fora do caminho da resposta<br>B) manter o 422 e só tratar o 429 como sucesso na tela<br>C) aceitar a enumeração (como o 045 hoje) | **A** | B esconde o cooldown de quem usa de boa-fé e não fecha a API; C deixa o endpoint como oráculo com até 60 consultas por minuto por IP (item 118). |

## Por que importa
Saber que uma pessoa tem conta no app é pouco, mas barato de obter em massa; o `/pre-register` (10 por minuto) já entrega o mesmo bit, então o ganho incremental é pequeno. Por isso a prioridade é baixa e o item acompanha o 118.

Tipo sugerido: backend + frontend (segurança)
