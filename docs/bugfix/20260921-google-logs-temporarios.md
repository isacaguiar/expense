# Bugfix — Logs de diagnóstico temporários ativos em produção no callback do Google

Versão: 1.0 · Criado em: 20260921 · Branch: `fix/20260921-google-logs-temporarios`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível**
- [ ] **Migration ou contrato de API**
- [ ] **Causa raiz obscura / correção ampla**
- [ ] **Decisão de produto/arquitetura**

Nenhuma marcada → segue no BFF.

**A primeira caixa exige justificativa, e foi decidida com o usuário em 2026-09-21.** O arquivo
`GoogleAuthController.php` **é** um controller de autenticação, e a leitura literal da caixa
mandaria escalar para feature SDD. O que pesou contra escalar:

- O diff é composto **exclusivamente de remoções** (16 linhas, nenhuma adição): duas chamadas
  `Log::info` marcadas `// TEMP diag`.
- Nenhuma linha de fluxo de autenticação, autorização, validação de `state`, emissão de token ou
  persistência é tocada.
- O conteúdo removido **não registrava dado sensível**: `user_id`, `strlen` do state (comprimento,
  não o valor), booleanos `has_state`/`has_code`, `google_error` e a URL do frontend. Nenhum token,
  código de autorização ou e-mail.

A decisão foi seguir no fluxo leve **com esta análise registrada**, em vez de marcar a caixa. Se o
diff tivesse acrescentado qualquer linha ao fluxo de auth, a decisão teria sido a outra.

## 1. Problema

- **Sintoma:** duas chamadas `Log::info` marcadas `// TEMP diag (fix/20260901-google-callback-logs)`
  continuam ativas em produção desde 2026-09-01, gravando em log a cada geração de URL de
  consentimento e a cada callback do Google.

- **Reprodução:** `rg "TEMP diag" backend/` → duas ocorrências, em
  `backend/app/Http/Controllers/GoogleAuthController.php`, nos métodos `redirectUrl()` e
  `callback()`.

- **Esperado vs. atual:** esperado, instrumentação temporária sair junto com o encerramento da
  investigação que a motivou. Atual: sobrevive há três semanas ao bug que diagnosticava.

- **Causa raiz:** os logs entraram pela investigação do state bloqueado com 406 pelo ModSecurity na
  HostGator. A causa foi corrigida em
  `docs/feature/concluidas/202609/20260901-google-oauth-state-opaco/`, que trocou o
  `Crypt::encryptString` por `Str::random(40)` com contexto no cache. Os logs ficaram — não há
  registro de decisão de mantê-los, e a marcação `TEMP` indica o contrário.

## 2. Correção

- **O que muda e por quê:** remove os dois blocos `// TEMP diag` e suas chamadas `Log::info`. Some o
  ruído de log de todo fluxo de vínculo com o Google, sem perder nenhum sinal de erro.
- **Arquivos tocados:** `backend/app/Http/Controllers/GoogleAuthController.php` (16 linhas removidas,
  nenhuma acrescentada).
- **Teste de regressão:** sem teste novo — a mudança remove instrumentação, não comportamento. O que
  se garante é que nada além dos dois blocos saiu, verificado por contagem de chamadas restantes e
  pelo diff de 16 deleções e 0 adições.
- **Riscos / efeitos colaterais:** se o problema do ModSecurity voltar, a investigação perde esse
  ponto de partida — mas o `Log::warning` de `state ausente/desconhecido/expirado`, que é o sintoma
  pelo qual o problema se manifestava, **permanece**. Os cinco `Log::warning` de erro real e os dois
  `Log::info` não marcados como temporários (Google user obtido, vínculo concluído) ficam intactos.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-21 | `php -l backend/app/Http/Controllers/GoogleAuthController.php` | "No syntax errors detected" |
| 2026-09-21 | `rg "TEMP diag" backend/` | `0` ocorrências |
| 2026-09-21 | `grep -c "Log::warning"` no controller | `5` — todos os sinais de erro preservados |
| 2026-09-21 | `grep -c "Log::info"` no controller | `2` — os não marcados como temporários, preservados |
| 2026-09-21 | `grep -n "use Illuminate"` no controller | o import de `Log` segue na linha 9, ainda necessário |
| 2026-09-21 | `git diff --stat` | `16 deletions(-)`, **nenhuma inserção** |
| 2026-09-21 | `pint` / `php artisan test` | **não executados**: `backend/vendor/` não está instalado neste ambiente. Coberto pelo CI. |
