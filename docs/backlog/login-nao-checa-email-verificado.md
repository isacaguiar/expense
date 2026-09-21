# `AuthController::login` não checa `email_verified_at`

ID: 043
Origem: docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/specify.md §3
Criado em: 2026-09-19
Prioridade: MEDIA
Status: Aberto

## Descrição

`backend/app/Http/Controllers/AuthController.php:31` autentica qualquer usuário com credencial
correta, sem olhar `email_verified_at`. O auto-cadastro (`docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/`)
passou a criar contas já verificadas, mas as contas antigas — criadas por `POST /register`, por
convite ou por Google OAuth — têm o campo nulo em parte dos casos.

Gatear o login nesse campo **hoje quebraria usuários existentes**, então ficou fora do escopo
daquela feature. Fazer isso direito exige antes: levantar quantas contas têm o campo nulo, decidir
se backfill ou reverificação por e-mail, e só então ligar a checagem.

## Por que importa

Enquanto não houver essa checagem, "e-mail verificado" não é uma garantia que o resto do sistema
possa assumir — o que limita o valor da confirmação por código que acabou de ser implementada.

Tipo sugerido: backend
