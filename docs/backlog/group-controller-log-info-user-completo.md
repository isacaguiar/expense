# `GroupController::store` loga o model `User` inteiro em texto

ID: 060
Origem: docs/feature/20260922-limite-grupos-fonte-unica/ (achado do `security-reviewer` na TASK-353, fora do diff revisado)
Criado em: 2026-09-22
Prioridade: BAIXA
Status: Aberto

## Descrição

`GroupController::store()` (`backend/app/Http/Controllers/GroupController.php:43`) faz `Log::info('User authenticated:', ['user' => auth()->user()])`, gravando o model `User` inteiro (serializado por `toArray()`/`jsonSerialize()` ao ir pro log) em vez de campos específicos como `id`/`email`. Mesmo padrão de risco (dado pessoal em texto no log) já identificado e corrigido em `AuthController::login` — ver `00-constitution.md` §5.3, item "`AuthController::login` loga `$credentials` inteiro via `Log::debug`" (esse já tinha senha em claro, o que é mais grave; este aqui não expõe senha porque `password` está em `$hidden`, mas ainda grava nome/e-mail/etc. sem necessidade).

## Por que importa

Log não é o lugar certo para guardar dado pessoal completo de um usuário — cresce a superfície de quem tem acesso a esse dado (qualquer um com acesso aos logs) sem necessidade, já que `$userId` (linha 16, poucas linhas antes) já identifica o usuário para fins de auditoria/debug.

Tipo sugerido: backend
