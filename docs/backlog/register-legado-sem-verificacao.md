# `POST /register` legado cria conta sem verificar o e-mail

ID: 042
Origem: docs/feature/20260919-cadastro-de-usuarios/specify.md §3
Criado em: 2026-09-19
Prioridade: MEDIA
Status: Aberto

## Descrição

Desde a feature de auto-cadastro, `POST /api/register` (`backend/app/Http/Controllers/AuthController.php:13`,
registrado em `backend/routes/api.php:17`) ficou sem nenhum cliente: a tela `/cadastro` usa
`POST /pre-register` + `/pre-register/verify`. Ele continua público e continua criando o `User`
na hora, **sem confirmar o e-mail**, sem `password_confirmation` e sem telefone — exatamente o
que o fluxo novo existe para evitar.

Foi mantido intacto porque alterar contrato de rota existente sem depreciação assistida é
proibido por `docs/sdd/00-constitution.md` §4.1. A decisão de depreciar (remover, ou redirecionar
para o fluxo novo, com aviso) é que ficou pendente.

## Por que importa

Enquanto ele existir, qualquer pessoa cria contas com e-mails que não possui, direto pela API —
o que torna a confirmação por código contornável para quem chama a API em vez da tela. Também
é o único caminho que ainda gera `User` com `email_verified_at` nulo, o que atrapalha o item 043.

Tipo sugerido: backend
