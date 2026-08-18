# Implementation — Execução

> Como uma task de `03-tasks.md` vira código. Aplica-se a trabalho humano e a trabalho de IA da mesma forma.

Versão: 1.0 · Última atualização: 2026-08-17

## 1. Fluxo de execução de uma task

1. **Branch**: `git checkout -b <tipo>/TASK-0xx-slug-curto` a partir de `main` atualizada (ex.: `frontend/TASK-004-tela-login`).
2. **Implementar** só o escopo da task. Se descobrir trabalho extra necessário, vira task nova em `03-tasks.md` — não expande a task em andamento.
3. **Checklist antes de abrir PR** (comandos reais, rodar a partir da raiz do repo):
   - [ ] Critério de aceite da task cumprido e verificável (rodar o app, rodar o teste, chamar o endpoint).
   - [ ] Backend, se a task tocou `backend/`: `cd backend && ./vendor/bin/pint` limpo (`./vendor/bin/pint --test` para só checar sem alterar); `php artisan test` verde; teste PHPUnit novo/atualizado se a task mexeu em regra de negócio (Constitution §2.2).
   - [ ] Frontend, se a task tocou `frontend/`: `cd frontend && npx tsc --noEmit` sem erro (não há script `tsc` no `package.json`; `vite build` sozinho não type-checa), sem `any` não justificado.
   - [ ] Se a task envolve migration: é aditiva? Se destrutiva, o gate humano da task foi respeitado (não rodar em banco compartilhado sem aprovação)?
   - [ ] Nenhum segredo novo no diff (`git diff` revisado antes do commit).
   - [ ] O `implementation.md` da feature (`docs/feature/<...>/implementation.md`) cita o comando real executado e o resultado obtido para cada item acima — não basta escrever "testado"/"validado" em prosa (ex.: "`php artisan test` — 8/8 verde", não só "testes passando").
4. **Abrir PR** referenciando o ID da task (`TASK-0xx`) na descrição.
5. **Gate humano de revisão**: merge em `main` só acontece após aprovação humana do PR (Constitution, tabela de Governança) — mesmo que todo o checklist acima esteja verde.
6. **Deploy**: sempre gate humano separado do merge — merge em `main` não decide sozinho ir para produção (workflow de deploy é `workflow_dispatch` ou push em `main`, conforme `.github/workflows/deploy-backend.yml`; enquanto o path bug de TASK-014 não for corrigido, deploy manual precisa ser conferido).

## 2. O que muda quando quem executa é uma IA

- Tudo acima vale igual. A diferença é só **onde param os gates**: a IA pode ir do branch até o PR aberto sozinha; humano decide merge, migration em ambiente compartilhado, deploy e rotação de segredo (ver tabela completa em `00-constitution.md` §5.2).
- Se uma task pedir uma dessas ações com gate humano, a execução da IA para no ponto do gate e relata o que falta aprovar — não assume aprovação implícita de pedidos anteriores.

## 3. Log de implementação

Preenchido conforme as tasks de `03-tasks.md` são executadas. Uma linha por task.

| Task ID | Status | Data | Responsável | Observações |
|---|---|---|---|---|
| — | — | — | — | (nenhuma task executada ainda — este SDD acabou de ser criado em 2026-08-17) |
