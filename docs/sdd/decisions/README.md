# Decisões arquiteturais (ADR)

> Registro de decisões de stack, arquitetura ou produto que a tabela de Governança do `00-constitution.md` §5 já trata como algo que precisa de decisão explícita — não correção silenciosa. A Constitution continua sendo a fonte da verdade das regras vigentes; esta pasta guarda o **porquê** e as alternativas descartadas de cada decisão, coisa que a Constitution, por ser normativa, não é o lugar de carregar.

## Quando abrir uma ADR nova

- Mudança de stack ou de peça de arquitetura já travada em `00-constitution.md` §3 (trocar framework, biblioteca de auth, banco, etc.).
- Qualquer item que hoje vive como "decisão em aberto" em `02-plan.md` §7 ou em um `docs/feature/<...>/plan.md`, no momento em que for de fato decidido.
- Uma mudança de convenção do próprio SDD (ex.: a decisão de passar a usar `docs/feature/<AAAAMMDD>-<slug>/` em vez de crescer os arquivos únicos — ver `ADR-002-sdd-por-feature.md`).

Não abrir ADR para decisão de implementação dentro do escopo normal de uma task (isso é `plan.md` de feature, não ADR) — ADR é para decisão que muda uma regra ou trava algo na Constitution.

## Formato

```
# ADR-0xx: <Título curto no infinitivo ou substantivo>

Status: Aceita | Superada por ADR-0yy | Proposta
Data: <AAAA-MM-DD>

## Contexto
<Qual situação forçou essa decisão a ser tomada>

## Decisão
<O que foi decidido, de forma direta>

## Consequências
<O que essa decisão implica — inclusive trade-offs aceitos>

## Alternativas consideradas
<O que mais foi cogitado e por que não foi escolhido>

## Referências
<Links internos: seção da Constitution, plan.md, task, PR>
```

## Índice

| ID | Título | Status |
|---|---|---|
| [ADR-001](ADR-001-migracao-frontend-expo.md) | Migração do frontend para Expo + React Native Paper | Aceita |
| [ADR-002](ADR-002-sdd-por-feature.md) | SDD por feature (`docs/feature/<AAAAMMDD>-<slug>/`) em vez de arquivos únicos | Aceita |
| [ADR-003](ADR-003-fluxo-branch-por-feature.md) | Fluxo de branch por feature (branch principal + tasks mergeadas nela) | Aceita |
| [ADR-004](ADR-004-fluxo-bugfix.md) | Fluxo de correção de bug (BFF) paralelo ao SDD-por-feature | Aceita |
| [ADR-005](ADR-005-download-arquivo-signed-url.md) | Download de arquivo servido por URL assinada, fora do `jwt.auth` | Aceita |
| [ADR-006](ADR-006-whatsapp-meta-cloud-api.md) | WhatsApp via Meta Cloud API como canal de mensageria | Aceita |
| [ADR-007](ADR-007-fonte-unica-e-arquitetura-do-agente.md) | Fonte única por fato + documento de arquitetura do agente | Aceita |
| [ADR-008](ADR-008-deploy-backend-ssh-rsync.md) | Deploy do backend via SSH/rsync em vez de FTP | Aceita |
