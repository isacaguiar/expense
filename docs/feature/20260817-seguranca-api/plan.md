# Plan — Segurança da API

> Traduz `specify.md` em decisão técnica, achado por achado. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 2026-08-17

---

## 1. Pix sem autenticação (specify §2.1)

- Mover `GET /pix/generate` para dentro do grupo `Route::middleware('jwt.auth')` em `backend/routes/api.php`.
- Em `PixController::gerarPix`, restringir quem pode ser alvo da geração: o próprio usuário autenticado, ou um usuário que compartilhe pelo menos um grupo com ele (`User::groups()` ∩ grupos do alvo) — é a mesma noção de "relação com o recurso" já usada em `GroupController@index` (`whereHas('members', ...)`). Sem grupo em comum, não existe despesa compartilhada que justifique a cobrança.
- Resposta de negação: `403`, sem vazar se o e-mail existe ou não além do necessário.

## 2. IDOR em grupos (specify §2.2)

- `GroupController@show/update/destroy`: antes de qualquer leitura/escrita, checar `$group->members()->where('user_id', auth()->id())->exists()` (mesmo padrão de `index`). Sem isso, **`404`** (decisão tomada na TASK-012: evita confirmar a existência do grupo para quem não é membro — mesmo raciocínio de não vazar informação usado em outros endpoints que checam relação com o recurso).

## 3. Segredos versionados (specify §2.3)

- Confirmar no repositório atual se os arquivos/valores citados na Constitution ainda existem (checagem já feita ao abrir esta feature: não encontrados). Se reaparecerem, tratar como P0.
- Registrar explicitamente que a rotação das credenciais antigas (mesmo já removidas do repo) precisa de confirmação humana — não é algo que o código resolve sozinho.

## 4. Path do deploy (specify §2.4)

- Corrigir `working-directory: backend-php` → `backend` em `.github/workflows/deploy-backend.yml`.
- Não validar sozinho que o próximo deploy funcionou — isso depende de rodar o workflow em produção, que é gate humano (deploy).

## 5. Log de senha (specify §2.5)

- Remover a linha `Log::debug('Credenciais extraídas', $credentials)` de `AuthController::login`. Os logs de falha (`Log::warning('Falha no login: credenciais inválidas', $credentials)`) também logam a senha — mesmo problema, mesma correção: não logar o array `$credentials` bruto; logar no máximo o e-mail.

## 6. Ordem de execução

Sem dependência técnica entre os 5 itens — podem ser feitos em qualquer ordem/paralelo. Ordem sugerida em `tasks.md` segue a gravidade (exposição de dado financeiro de terceiros primeiro).
