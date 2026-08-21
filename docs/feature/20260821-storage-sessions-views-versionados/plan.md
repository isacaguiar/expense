# Plan — Storage sessions/views versionados

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. Untrack dos 11 arquivos (specify §2.1)

- `git rm --cached backend/storage/framework/sessions/<6 arquivos> backend/storage/framework/views/<5 arquivos>` — lista exata obtida de `git ls-files backend/storage/framework/sessions backend/storage/framework/views` no momento do commit (nomes de sessão são gerados, podem já ter mudado desde o specify).
- Sem `--force`: se algum desses arquivos já estiver coberto por uma regra de `.gitignore` mais nova (não é o caso aqui, mas é a checagem de segurança do próprio comando), `git rm --cached` sem `-f` recusaria e sinalizaria — comportamento desejado.
- Nenhuma mudança em `.gitignore` (specify §3).
- Nenhum teste automatizado aplicável (mudança é só de tracking do git, não de comportamento de código).

## 2. Ordem de execução

Item único, sem dependência — uma task, ver `tasks.md`.
