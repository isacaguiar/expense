# `AuthController` grava o e-mail em claro nos logs de login e logout

ID: 167
Origem: feature `20261125-log-sem-mensagem-da-excecao-restantes` (informativo do `security-reviewer`, fora do escopo; decisão de registrar tomada por ele em 2026-11-26); `backend/app/Http/Controllers/AuthController.php`
Criado em: 2026-11-26
Prioridade: BAIXA
Status: Aberto

## Descrição
`AuthController` grava o e-mail do usuário em texto nos logs do fluxo de autenticação:

| Linhas | Log | Campo de e-mail |
|---|---|---|
| 20-22 | `Log::info('Tentativa de login recebida')` | `$request->email`, **o que foi digitado**, antes de qualquer validação |
| 31 | `Log::warning('Falha no login: credenciais inválidas')` | `$credentials['email']` |
| 37 | `Log::warning('Falha no login: e-mail não verificado')` | `$credentials['email']` |
| 45-48 | `Log::info('Login bem-sucedido para o usuário')` | `user_id` e `email` |
| 72-75 | `Log::info('Logout do usuário')` | `user_id` e `email` |

(O `Log::warning` da linha 27 está dentro de um bloco comentado e não executa.)

Nos logs de sucesso e de logout o `user_id` já identifica a pessoa; o e-mail só repete o dado pessoal. Nos de tentativa e de falha o e-mail é o texto digitado: pode ser de uma conta que não existe ou, por engano, conter outra coisa (por exemplo, a senha colada no campo errado).

Não é falha de comportamento nem é explorável por um cliente: é dado pessoal em log (LGPD, repositório público e logs de produção lidos fora do banco).

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Nos logs de sucesso e logout | A) manter só `user_id` e tirar o e-mail<br>B) manter os dois | **A** | O `user_id` já identifica; o e-mail só duplica dado pessoal. |
| D2 | Na tentativa e na falha de login | A) tirar o e-mail e registrar só o fato (e o IP, se já for registrado)<br>B) registrar um hash do e-mail<br>C) manter o e-mail | **A** | Sem o e-mail não se perde o sinal de "houve falha"; B ainda permite correlacionar sem expor, mas custa uma decisão de sal/algoritmo. C mantém o problema. |
| D3 | Depuração de suporte | A) aceitar que o suporte procure por `user_id`<br>B) manter o e-mail só no log de falha | **A** | O `user_id` de sucesso cobre o caso de "o usuário diz que logou"; a falha sem e-mail ainda mostra o volume. |
| D4 | Escopo | A) só o `AuthController`<br>B) varrer todo `backend/app` atrás de `'email' =>` em `Log::*` | **B** | O `InvitationController` (recuperação de senha) já foi corrigido na feature 165; uma varredura confirma que não restam outros. |
| D5 | Bloco comentado das linhas 26-29 | A) apagar junto<br>B) deixar | **A** | Código morto, com o mesmo log; custa uma linha no diff. |

## Por que importa
É defesa em profundidade e conformidade: o log não precisa do e-mail para cumprir seu papel, e o projeto vem tirando dado de negócio e dado pessoal dos logs (155 ponto 2, 163, 164, 165).

## Fora deste item
- `GroupController::store` loga o model `User` inteiro: já registrado no item 060.
- Os `catch (\Throwable)` que relançam e caem no reporter padrão: item 166.

Tipo sugerido: backend (sem mudança de contrato nem migration)
