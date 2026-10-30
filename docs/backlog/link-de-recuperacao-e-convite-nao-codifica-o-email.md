# O link de recuperação de senha e o de convite não codificam o e-mail: endereço com `+` não funciona

ID: 121
Origem: docs/feature/concluidas/202609/20260930-recuperacao-acesso/specify.md e revisão de segurança de 2026-10-30 (F7); `backend/app/Http/Controllers/InvitationController.php` (linha 84), `backend/app/Mail/UserInvitedMail.php:29`, `frontend/src/pages/AcceptInvitePage.tsx`
Criado em: 2026-10-30
Prioridade: MEDIA
Status: Aberto

## Descrição
A linha 84 monta o link por concatenação: `config('services.frontend_app_url')."/recuperar-senha?email={$user->email}&token={$token}"` (confirmado no código). O mesmo padrão está em `UserInvitedMail.php:29`. Num query string, o `+` do e-mail vira espaço: `new URLSearchParams('email=a+b@x.com&token=1').get('email')` devolve `"a b@x.com"`. A `AcceptInvitePage.tsx` lê com `searchParams.get`, então o `/verify` recebe um e-mail inválido e responde 422.

Quem usa o formato `nome+tag@gmail.com` **não consegue redefinir a senha pelo link** (nem aceitar o convite). A tela nova torna o caso alcançável por qualquer visitante. Não foi medido quantos usuários têm `+` no e-mail.

Proposta: montar o link com `http_build_query(['email' => …, 'token' => …])` nos dois pontos.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | O token continua indo na query string do link, como a Constitution aceita para o reset de 60 minutos (§6 item 3). |
| R2 | O e-mail do link volta a ser o mesmo que o usuário cadastrou, byte a byte. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde corrigir | A) montar o link com `http_build_query` no backend<br>B) tratar o `+` na tela | **A** | A causa é a montagem do link; B deixaria links já enviados e os do convite com o mesmo defeito escondido. |
| D2 | Caminho | A) `/novo-bug` (BFF) com a Triagem<br>B) feature SDD | **A** | É defeito em comportamento existente; a Triagem marca "Auth", o que escala para o fluxo de feature se o item for tratado como correção de código de autenticação. |

## Por que importa
É perda real de função (a pessoa não consegue redefinir a senha nem aceitar o convite), silenciosa para quem tem o caso e sem mensagem que explique.

Tipo sugerido: backend (bugfix; a Triagem marca "Auth")
