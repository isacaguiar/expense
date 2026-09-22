# `backend/.env.example` está no `.gitignore` e nunca foi versionado

ID: 056
Origem: docs/bugfix/20260921-cadastro-codigo-email-nao-chega.md
Criado em: 2026-09-21
Prioridade: BAIXA
Status: Aberto

## Descrição

`.gitignore:13` (raiz do repo) ignora o padrão `.env.example`, o que também atinge
`backend/.env.example`. `backend/.gitignore` (linhas 8-10) só ignora `.env`,
`.env.backup` e `.env.production` — não `.env.example` — sugerindo que a intenção
era manter o arquivo de exemplo versionado, e o padrão amplo no `.gitignore` raiz
pegou ele de arrasto sem querer. `git ls-files backend/.env.example` não retorna
nada: o arquivo nunca foi commitado, apesar de existir em disco e ser referenciado
como se fosse o template padrão do projeto (`docker-compose.yml`, onboarding).

Descoberto ao tentar documentar a variável `MAIL_EHLO_DOMAIN` (correção do bug
`20260921-cadastro-codigo-email-nao-chega`) nesse arquivo — a edição existe só
localmente e nunca chegaria a um PR.

## Por que importa

`.env.example` é o template que documenta, para quem configura um ambiente novo,
quais variáveis existem e seus defaults sãos (sem segredo real). Sem ele versionado,
essa documentação vive só na cópia local de quem já tem o projeto rodando — variável
nova (como `MAIL_EHLO_DOMAIN`) não tem onde ser anotada para o próximo desenvolvedor,
e o repositório não serve como fonte de verdade do shape do `.env`.

Correção provável: `.gitignore:13` trocar `.env.example` por um padrão mais
específico (ex.: `.env` sozinho, sem afetar `*.example`), e então `git add -f
backend/.env.example` uma vez para começar a versionar.

Tipo sugerido: infra
