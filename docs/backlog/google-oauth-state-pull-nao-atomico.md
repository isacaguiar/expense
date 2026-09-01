# `Cache::pull` do state OAuth não é atômico (uso único não estrito)

ID: 035
Origem: docs/feature/20260901-google-oauth-state-opaco/ — observação INFO-1 da revisão do `security-reviewer` na TASK-235
Criado em: 2026-09-01
Prioridade: BAIXA
Status: Aberto

## Descrição

`GoogleAuthController::pullState()` recupera o contexto do vínculo com `Cache::pull('google_oauth_state:<token>')` — um get + forget sem lock. No driver `file` (o de produção) dois callbacks concorrentes com o mesmo `state` válido podem ler o contexto antes de qualquer um apagar a chave, furando o "uso único" estrito. Fechar com `Cache::lock()` em volta do pull, ou `Cache::add` como guarda de consumo.

## Por que importa

Hoje o impacto é nulo: explorar exige o token de 40 chars válido + `code`(s) válido(s) do Google disparados em janela de milissegundos, e o resultado é uma re-execução idempotente do mesmo vínculo (`google_id`/`avatar_url` gravados com valores iguais) — sem ganho de privilégio. Passa a importar quando `intent=login` for implementado no mesmo método (backlog 014 — `login-social-google.md`), onde um `state` reprocessado poderia emitir sessão/token mais de uma vez. Revisitar **junto** dessa feature, não isolado.

Tipo sugerido: backend
