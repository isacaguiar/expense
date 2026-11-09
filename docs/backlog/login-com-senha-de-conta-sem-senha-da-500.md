# Login por senha de uma conta sem senha (criada pelo Google) lança `RuntimeException` e responde 500, e o status denuncia a conta

ID: 134
Origem: revisão de segurança da feature `docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/` (TASK-576, achado derivado do A1); `backend/app/Http/Controllers/AuthController.php` (`login`, `Auth::guard('api')->attempt`), `GoogleAuthController.php:218` (`'password' => null`), `backend/config/hashing.php` (`verify => true`)
Criado em: 2026-11-03
Prioridade: BAIXA
Status: Aberto

## Descrição
- As contas criadas pelo login do Google nascem com `password` nulo (`GoogleAuthController.php:218`; a coluna é nullable desde a migration `2026_08_22_194808`). Com `hashing.verify = true` (o padrão), `Hash::check($senha, null)` **lança** `RuntimeException: This password does not use the Bcrypt algorithm.` (`BcryptHasher.php:71`). Então `POST /api/login` com o e-mail de uma conta assim e **qualquer** senha responde **500**, em vez do 401 de credenciais inválidas.
- Confirmado de duas formas, ambas em transação desfeita no banco de teste: uma sonda com `Auth::guard('api')->attempt` (lança a exceção acima) e, durante a TASK-576, `POST /api/login` por HTTP contra uma conta de senha nula (500 no lugar de 401).
- Efeito: (1) quem entrou pelo Google e tenta uma senha vê um erro do servidor; (2) o status 500 (contra 401 para e-mail inexistente ou senha errada) revela que o e-mail é de uma conta sem senha, um oráculo de existência. Ele é menor que o do pré-cadastro (item 045), que já diz "este e-mail já está cadastrado".
- Como foi achado: o `security-reviewer` sugeriu, para o achado A1 da feature do convite, criar a conta provisória do convidado com senha `null` (para igualar o tempo de resposta). A verificação mostrou que isso criaria exatamente este 500 para as contas convidadas. A feature usa um hash bcrypt de custo 4 (`GroupInvitationService::placeholderPassword`) e **não** é afetada.

## Por que importa
Um erro do servidor numa rota pública de login, previsível e disparável por qualquer pessoa, além de um canal lateral sobre quais e-mails vieram do Google. O app Flutter (item 074) vai consumir esse contrato.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como tratar conta sem senha no login | A) `AuthController::login` responde 401 quando a conta não tem senha (checar `password === null` antes do `attempt`, ou capturar a `RuntimeException`)<br>B) dar às contas do Google um hash provisório no cadastro<br>C) desligar `hashing.verify` | **A** | A é uma checagem pequena, não mexe em dados nem em configuração global e resolve também as contas já criadas. B não corrige as contas existentes. C afrouxa a verificação de algoritmo para o app inteiro. |

Relacionado: 045 (enumeração no pré-cadastro), 091 (oráculo do `GET /pix/generate`), 098 (oráculos de existência de grupo).

Tipo sugerido: bugfix pequeno (fluxo BFF) com teste de 401 para conta sem senha.
