# Bugfix — Caminho dos agents no `AGENTS.md` com caixa errada (`.Codex/agents/`)

Versão: 1.0 · Criado em: 20260920 · Branch: `fix/20260920-agents-md-caminho-codex`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível**
- [ ] **Migration ou contrato de API**
- [ ] **Causa raiz obscura / correção ampla**
- [ ] **Decisão de produto/arquitetura**

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** o `AGENTS.md` (instruções lidas por agentes no padrão AGENTS/Codex) manda usar os agents `security-reviewer` e `pr-readiness-checker` apontando para `.Codex/agents/`. Esse diretório não existe — o real é `.codex/agents/`. Em Linux/macOS, onde o sistema de arquivos diferencia maiúsculas de minúsculas, o caminho não resolve e o agente não encontra as definições.
- **Reprodução:**
  1. Em checkout num sistema case-sensitive (Linux/macOS), da raiz do repo: `ls .Codex/agents/` → `No such file or directory`.
  2. `ls .codex/agents/` → lista `pr-readiness-checker.toml` e `security-reviewer.toml`.
  3. No Windows (case-insensitive) o defeito fica latente: o caminho resolve mesmo com a caixa errada, então passa despercebido.
- **Esperado vs. atual:** esperado, o `AGENTS.md` citar `.codex/agents/`, igual ao diretório realmente versionado — como o `CLAUDE.md` faz na linha equivalente, citando `.claude/agents/`. Atual: cita `.Codex/agents/`.
- **Causa raiz:** `AGENTS.md:22`. A linha nasceu no commit `4d0a4c2e8d` ("ajustes", 2026-09-06) como adaptação da linha 22 do `CLAUDE.md`: na troca de `.claude/` por `.codex/` a inicial saiu maiúscula. Os dois arquivos de agent existem e estão corretos em `.codex/agents/`; o defeito é só na referência. `git grep -n -E '\.(Codex|Claude|Agents)/'` em `dev` retorna essa única ocorrência de caixa errada em todo o repositório.

## 2. Correção

- **O que muda e por quê:** corrige a caixa do caminho em `AGENTS.md:22` — `.Codex/agents/` → `.codex/agents/` —, alinhando a referência ao diretório realmente versionado. Sem isso, o caminho não resolve em sistema de arquivos case-sensitive e o agente que lê o `AGENTS.md` não encontra as definições de `security-reviewer` e `pr-readiness-checker`.
- **Arquivos tocados:** `AGENTS.md` (1 linha); `docs/bugfix/README.md` (linha de registro na tabela "Em andamento"); `docs/bugfix/20260920-agents-md-caminho-codex.md` (este arquivo).
- **Teste de regressão:** sem teste automatizado — o defeito é de documentação e não há suíte que cubra referência de caminho no `AGENTS.md`. Verificação equivalente registrada no log §3: `git grep` por caixa errada volta vazio fora de `docs/bugfix/` (onde as ocorrências descrevem o próprio bug) e `test -d .codex/agents/` confirma que o caminho citado resolve.
- **Riscos / efeitos colaterais:** nenhum identificado. É texto em arquivo de instrução: não toca código, build, schema nem contrato de API. Nenhum segredo novo no diff (revisado com `git diff` antes do commit).

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-20 | `ls .codex/agents/` | lista `pr-readiness-checker.toml` e `security-reviewer.toml` — diretório real confirmado |
| 2026-09-20 | `grep -n 'codex/agents' AGENTS.md` | linha 22 agora cita `.codex/agents/` |
| 2026-09-20 | `git grep -n -E '\.(Codex\|Claude\|Agents)/' -- . ':!docs/bugfix' ':!*.pdf' ':!*.png'` | nenhuma ocorrência de caixa errada fora de `docs/bugfix/` |
| 2026-09-20 | `test -d .codex/agents/` | OK — o caminho agora citado no `AGENTS.md` resolve |
| 2026-09-20 | `git diff` revisado antes do commit | nenhum segredo novo; 1 linha alterada em `AGENTS.md` + 1 linha nova na tabela do `README.md` |
| 2026-09-20 | pint / `php artisan test` / `npx tsc --noEmit` | não se aplica: nenhum arquivo em `backend/` ou `frontend/` tocado — o checklist de `04-implementation.md` §1 item 3 é condicional à frente tocada |
