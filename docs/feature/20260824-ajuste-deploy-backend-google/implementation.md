# Implementation — Ajuste do Deploy Backend para Google OAuth

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260824

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-203 | Concluída | 20260824 | Claude (sessão com o usuário) | Edição via `Edit` tool em `.github/workflows/deploy-backend.yml`: `APP_URL` e `MAIL_FROM_ADDRESS` trocados de `scd.novemax.com.br` para `expense-api.novemax.com.br`; 5 linhas `echo "...=${{ secrets.ENV_... }}" >> .env` adicionadas para `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `FRONTEND_URL`, `FRONTEND_NETWORK_URL`. Validação: leitura completa do arquivo pós-edição confirmando indentação (10 espaços, mesmo nível das linhas `echo` vizinhas dentro do bloco `run: \|`) e estrutura YAML consistentes; `pyyaml`/`js-yaml` não estavam disponíveis no ambiente para lint automático — não foi executado. | Validação real do YAML fica pendente na primeira execução do workflow (push em `main` ou `workflow_dispatch`) — não disparado nesta sessão, pois isso conta como deploy real (gate humano à parte, fora desta task). |
