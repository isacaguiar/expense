# Tasks — Ajuste do Deploy Backend para Google OAuth

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260824

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-203 | Incluir variáveis do Google/frontend e corrigir domínio no deploy-backend.yml | infra | plan.md §1 | nenhum (edição de workflow em branch; deploy real continua gate humano à parte) | Pendente |

## Critérios de aceite

- **TASK-203**: `.github/workflows/deploy-backend.yml` contém as 5 novas linhas `echo "...=${{ secrets.ENV_... }}" >> .env` para `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `FRONTEND_URL`, `FRONTEND_NETWORK_URL`; `APP_URL` e `MAIL_FROM_ADDRESS` usam `expense-api.novemax.com.br`; o YAML é válido (sem erro de sintaxe) — verificável lendo o arquivo e, se possível, com `yamllint`/parser YAML.
