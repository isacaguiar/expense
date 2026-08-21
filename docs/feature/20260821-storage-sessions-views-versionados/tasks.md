# Tasks — Storage sessions/views versionados

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-130).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-131 | `git rm --cached` nos arquivos de sessão e view compilada versionados por engano | infra | plan.md §1 | antes do merge | Implementada na branch da feature |

## Critérios de aceite

- **TASK-131**: `git ls-files backend/storage/framework/sessions backend/storage/framework/views` retorna só os `.gitignore` de cada pasta (nenhum arquivo de sessão/view compilada). Os arquivos continuam existindo em disco (`ls backend/storage/framework/sessions`/`views` sem `--cached` mostra os mesmos arquivos de antes). `git status` limpo em relação a essas pastas.
