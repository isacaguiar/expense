# Implementation — Execução

> Como uma task de `03-tasks.md` vira código. Aplica-se a trabalho humano e a trabalho de IA da mesma forma.

Versão: 1.0 · Última atualização: 2026-08-17

## 1. Fluxo de execução de uma task

> Fluxo de branch/PR válido a partir de 2026-08-17, primeira feature a segui-lo por inteiro é a que vier depois de `docs/feature/20260817-config-url-api-frontend/` (essa segue o fluxo antigo — branch/PR por task direto pra `main` — pra não trocar o processo no meio da execução; ver Constitution §5.1). Se encontrar uma branch/PR nomeada no padrão antigo (`<tipo>/TASK-0xx-slug-curto`, direto pra `main`), é uma feature anterior a essa data — não precisa migrar retroativamente.

1. **Branch**: `git checkout -b <tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx` a partir de `dev` atualizada (`git pull origin dev` antes de criar a branch) — `<AAAAMMDD>-<slug-da-feature>` é exatamente o nome da pasta em `docs/feature/`, e `TASK-0xx` vai no final (ex.: feature `docs/feature/20260901-notificacoes-email/`, task `TASK-030` → branch `backend/20260901-notificacoes-email-TASK-030`). Uma branch por task, não por feature inteira — cada task mantém seu próprio PR e aprovação.
2. **Implementar** só o escopo da task. Se descobrir trabalho extra necessário, vira task nova em `03-tasks.md` (ou no `tasks.md` da feature) — não expande a task em andamento.
3. **Checklist antes de abrir PR** (comandos reais, rodar a partir da raiz do repo):
   - [ ] Critério de aceite da task cumprido e verificável (rodar o app, rodar o teste, chamar o endpoint).
   - [ ] Backend, se a task tocou `backend/`: `cd backend && ./vendor/bin/pint` limpo (`./vendor/bin/pint --test` para só checar sem alterar); `php artisan test` verde; teste PHPUnit novo/atualizado se a task mexeu em regra de negócio (Constitution §2.2).
   - [ ] Frontend, se a task tocou `frontend/`: `cd frontend && npx tsc --noEmit` sem erro (não há script `tsc` no `package.json`; `vite build` sozinho não type-checa), sem `any` não justificado.
   - [ ] Se a task envolve migration: é aditiva? Se destrutiva, o gate humano da task foi respeitado (não rodar em banco compartilhado sem aprovação)?
   - [ ] Nenhum segredo novo no diff (`git diff` revisado antes do commit).
   - [ ] O `implementation.md` da feature (`docs/feature/<...>/implementation.md`) cita o comando real executado e o resultado obtido para cada item acima — não basta escrever "testado"/"validado" em prosa (ex.: "`php artisan test` — 8/8 verde", não só "testes passando").
4. **Abrir PR contra `dev`** (não `main`) referenciando o ID da task (`TASK-0xx`) na descrição.
5. **Gate humano de revisão**: merge em `dev` só acontece após aprovação humana do PR (Constitution, tabela de Governança) — mesmo que todo o checklist acima esteja verde.
6. **Promoção `dev` → `main`**: depois que as tasks relevantes já estão em `dev` e validadas/testadas lá (manualmente ou via preview), abrir um PR de `dev` para `main`. Merge nesse PR é gate humano — e, na prática, também decide o deploy: push em `main` dispara `deploy-backend.yml` automaticamente (não existe hoje um passo de deploy manual separado do merge; `workflow_dispatch` é só para re-disparar sem novo push).

## 2. O que muda quando quem executa é uma IA

- Tudo acima vale igual. A diferença é só **onde param os gates**: a IA pode ir do branch até o PR aberto sozinha; humano decide merge, migration em ambiente compartilhado, deploy e rotação de segredo (ver tabela completa em `00-constitution.md` §5.2).
- Se uma task pedir uma dessas ações com gate humano, a execução da IA para no ponto do gate e relata o que falta aprovar — não assume aprovação implícita de pedidos anteriores.

## 3. Log de implementação

Preenchido conforme as tasks de `03-tasks.md` são executadas. Uma linha por task.

| Task ID | Status | Data | Responsável | Observações |
|---|---|---|---|---|
| — | — | — | — | (nenhuma task executada ainda — este SDD acabou de ser criado em 2026-08-17) |
