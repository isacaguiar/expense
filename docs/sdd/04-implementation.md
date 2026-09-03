# Implementation — Execução

> Como uma task de `03-tasks.md` vira código. Aplica-se a trabalho humano e a trabalho de IA da mesma forma.

Versão: 1.2 · Última atualização: 2026-08-30

## 1. Fluxo de execução de uma task

> Fluxo de branch/PR vigente (`docs/sdd/decisions/ADR-003-fluxo-branch-por-feature.md`, 2026-08-18) descrito abaixo. Histórico dos 3 modelos e a regra de não misturá-los numa mesma feature em andamento: `00-constitution.md` §5.1.1. Branch/PR num padrão antigo não precisa migrar retroativamente.

1. **Branch**:
   - **Primeira task da feature**: `git checkout -b <tipo>/<AAAAMMDD>-<slug-da-feature>` a partir de `dev` atualizada (`git pull origin dev` antes de criar a branch) — `<AAAAMMDD>-<slug-da-feature>` é exatamente o nome da pasta em `docs/feature/`. Essa é a **branch da feature**; a primeira task é implementada direto nela, sem sub-branch própria.
   - **Tasks seguintes da mesma feature**: `git checkout -b <tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx` a partir da **branch da feature** atualizada (não de `dev`) — ex.: feature `docs/feature/20260901-notificacoes-email/`, task `TASK-030` → branch `backend/20260901-notificacoes-email-TASK-030`, criada a partir de `backend/20260901-notificacoes-email`.
2. **Implementar** só o escopo da task. Se descobrir trabalho extra necessário, vira task nova em `03-tasks.md` (ou no `tasks.md` da feature) — não expande a task em andamento.
3. **Checklist antes de integrar na branch da feature** (comandos reais, rodar a partir da raiz do repo):
   - [ ] Critério de aceite da task cumprido e verificável (rodar o app, rodar o teste, chamar o endpoint).
   - [ ] Backend, se a task tocou `backend/`: `cd backend && ./vendor/bin/pint` limpo (`./vendor/bin/pint --test` para só checar sem alterar); `php artisan test` verde; teste PHPUnit novo/atualizado se a task mexeu em regra de negócio (Constitution §2.2).
   - [ ] Frontend, se a task tocou `frontend/`: `cd frontend && npx tsc --noEmit` sem erro (não há script `tsc` no `package.json`; `vite build` sozinho não type-checa), sem `any` não justificado.
   - [ ] Se a task envolve migration: é aditiva? Se destrutiva, o gate humano da task foi respeitado (não rodar em banco compartilhado sem aprovação)? **O deploy aplica migrations automaticamente ao mergear em `main`** (`deploy-backend.yml` → `SCRIPT_AFTER` → `php artisan migrate --force`; `ADR-008`, emenda 2026-09-03) — então uma migration destrutiva (`drop`/`rename`/alterar tipo) só entra em `main` com aval humano explícito registrado no PR de promoção `dev` → `main`.
   - [ ] Nenhum segredo novo no diff (`git diff` revisado antes do commit).
   - [ ] O `implementation.md` da feature (`docs/feature/<...>/implementation.md`) cita o comando real executado e o resultado obtido para cada item acima — não basta escrever "testado"/"validado" em prosa (ex.: "`php artisan test` — 8/8 verde", não só "testes passando").
4. **Merge na branch da feature**: exceto na primeira task (que já nasce direto na branch da feature), faz merge local da branch de task na branch da feature (`git checkout <branch-da-feature> && git merge --no-ff <branch-da-task>`) — **sem PR, sem gate humano**, porque a branch da feature ainda não é `dev`/`main` (ver `ADR-003`). Depois do merge: `git push` da branch da feature e descartar a branch de task (local e remota, se já tiver sido empurrada).
5. **Quando a feature estiver pronta** (todas as tasks planejadas integradas, ou o subconjunto que o humano decidir promover): rodar o checklist do item 3 mais uma vez na branch da feature já integrada — pega problema de integração entre tasks que o checklist individual não pegaria.
6. **Abrir um único PR** da branch da feature contra `dev` (não `main`), referenciando a feature e as tasks incluídas na descrição.
7. **Gate humano de revisão**: merge em `dev` só acontece após aprovação humana do PR (Constitution, tabela de Governança) — mesmo que todo o checklist acima esteja verde. Esse gate é por feature, não por task.
8. **Promoção `dev` → `main`**: depois que a(s) feature(s) relevante(s) já estão em `dev` e validadas/testadas lá (manualmente ou via preview), abrir um PR de `dev` para `main`. Merge nesse PR é gate humano — e, na prática, também decide o deploy: push em `main` dispara `deploy-backend.yml` automaticamente (não existe hoje um passo de deploy manual separado do merge; `workflow_dispatch` é só para re-disparar sem novo push).

## 2. O que muda quando quem executa é uma IA

- Tudo acima vale igual. A diferença é só **onde param os gates**: a IA pode ir do branch até o PR aberto sozinha; o que exige aprovação humana está em `00-constitution.md` §5.2.
- Se uma task pedir uma ação com gate humano, a execução da IA para no ponto do gate e relata o que falta aprovar — não assume aprovação implícita de pedidos anteriores. Pontos de parada do loop consolidados em `agent-architecture.md` §3.

## 3. Log de implementação

Preenchido conforme as tasks de `03-tasks.md` são executadas. Uma linha por task.

| Task ID | Status | Data | Responsável | Observações |
|---|---|---|---|---|
| — | — | — | — | (nenhuma task executada ainda — este SDD acabou de ser criado em 2026-08-17) |
