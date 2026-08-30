# Contexto de execução — Backend

> Documento **portátil** (markdown puro, sem nada específico de ferramenta): o que qualquer assistente de IA (ou dev) deve carregar antes de mexer na API. Contrato de portabilidade e relação com as skills: `README.md`, "Skills e portabilidade".

Versão: 1.1 · Última atualização: 2026-08-30

---

API REST stateless em Laravel 10 / PHP 8.1+, MySQL 8, autenticação JWT (`tymon/jwt-auth`). Consumida por `expense/frontend` (React web) e, em migração, por `expense/app` (Expo).

## Antes de codar

Carregue o contexto abaixo se ele ainda não estiver na conversa (não releia o que já foi lido na mesma sessão):

- `00-constitution.md` §1 (Arquitetura), §2 (Qualidade) e §6 (Segurança, com o digest §6.0) — regras de camada, Pint/PHPUnit e as invariantes de segurança; achados já conhecidos que aguardam decisão humana em §5.3.
- `02-plan.md` §3-5 — convenção de camadas alvo (Controller fino → FormRequest → Service/Action → Model), débitos técnicos existentes, integrações (Pix, e-mail).
- O controller/model mais próximo do que será alterado (ex.: `app/Http/Controllers/GroupController.php` como referência para outro CRUD).
- `routes/api.php` — para registrar rota nova sem repetir o erro já conhecido de `apiResource` apontando para método inexistente no controller.

## Convenções fixas

- Controllers finos: validação (via `FormRequest` quando a regra crescer) + orquestração. Regra de negócio densa (cálculo de divisão, apuração de saldo) vai para Service/Action, não para o controller.
- Tabelas novas usam prefixo `ex_` (convenção já em uso).
- `./vendor/bin/pint` limpo antes de commit; teste PHPUnit para toda regra de negócio nova ou alterada.
- Invariantes de segurança/arquitetura que nada pode contradizer (soft delete; rota que expõe dado financeiro/pessoal dentro do grupo `jwt.auth` + checagem de membership/ownership; integridade de rota `apiResource`): `00-constitution.md` §1, §2, §6 — digest em §6.0.

## Gates human-in-the-loop

Fronteira de autonomia: `00-constitution.md` §5.2 (tabela normativa) e `agent-architecture.md` §5 (desenho). Nunca assuma aprovação como implícita.

## Não corrigir de passagem

Os achados de segurança e os endpoints quebrados já conhecidos (`00-constitution.md` §5.3) já são tasks próprias. Se a tarefa atual não é uma dessas, não misture a correção — abra/sinalize a task correspondente em vez de consertar como refactor de passagem.
