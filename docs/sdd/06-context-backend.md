# Contexto de execução — Backend

> Documento **portátil**, sem nada específico de ferramenta: é o conteúdo que qualquer assistente de IA (ou desenvolvedor) deve carregar antes de mexer na API do projeto. Hoje é referenciado pela skill `expense-backend` do Claude Code (`expense/.claude/skills/expense-backend/SKILL.md`), mas não depende dela — se o projeto trocar de ferramenta de IA, este arquivo continua valendo, só muda o adaptador que aponta pra ele.

Versão: 1.0 · Última atualização: 2026-08-17

---

API REST stateless em Laravel 10 / PHP 8.1+, MySQL 8, autenticação JWT (`tymon/jwt-auth`). Consumida por `expense/frontend` (React web) e, em migração, por `expense/app` (Expo).

## Antes de codar

Carregue o contexto abaixo se ele ainda não estiver na conversa (não releia o que já foi lido na mesma sessão):

- `00-constitution.md` §1 (Arquitetura), §2 (Qualidade) e §6 (Segurança) — regras de camada, Pint/PHPUnit, e a lista de violações de segurança já conhecidas (endpoint Pix público, `GroupController` sem checagem de membership, etc.) para não repetir o padrão em código novo.
- `02-plan.md` §3-5 — convenção de camadas alvo (Controller fino → FormRequest → Service/Action → Model), débitos técnicos existentes, integrações (Pix, e-mail).
- O controller/model mais próximo do que será alterado (ex.: `app/Http/Controllers/GroupController.php` como referência para outro CRUD).
- `routes/api.php` — para registrar rota nova sem repetir o erro já conhecido de `apiResource` apontando para método inexistente no controller.

## Convenções fixas

- Controllers finos: validação (via `FormRequest` quando a regra crescer) + orquestração. Regra de negócio densa (cálculo de divisão, apuração de saldo) vai para Service/Action, não para o controller.
- Tabelas novas usam prefixo `ex_` (convenção já em uso).
- Exclusão de registro de negócio é soft delete (`deleted = true`), nunca `DELETE` físico.
- `./vendor/bin/pint` limpo antes de commit; teste PHPUnit para toda regra de negócio nova ou alterada.
- Toda rota que expõe dado financeiro/pessoal fica dentro do grupo `jwt.auth` e o controller confere que o usuário autenticado tem relação com o recurso (é membro do grupo, é dono do dado).

## Gates human-in-the-loop (de `00-constitution.md` §5.2)

- Livre para codar, testar e rodar migration **local** em branch.
- **Exige aprovação humana**: migration em banco compartilhado/produção, migration destrutiva (drop/rename/alterar tipo), merge em `main`, deploy, e rotacionar/expor qualquer segredo. Nunca assuma essa aprovação como implícita.

## Não corrigir de passagem

Os achados de segurança e os endpoints quebrados listados na Constitution (`00-constitution.md` §5.3, `03-tasks.md` Épicos B e C) já são tasks próprias (`TASK-011` a `TASK-017`). Se a tarefa atual não é uma dessas tasks, não misture a correção — abra/sinalize a task correspondente em vez de consertar como refactor de passagem.
