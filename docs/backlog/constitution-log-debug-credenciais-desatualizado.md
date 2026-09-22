# `00-constitution.md` §5 item 3 cita `Log::debug` de credenciais que não existe mais no código

ID: 059
Origem: docs/feature/20260922-email-verificado-obrigatorio/ (achado do `security-reviewer` na TASK-340)
Criado em: 2026-09-22
Prioridade: BAIXA
Status: Aberto

## Descrição

`docs/sdd/00-constitution.md:92` lista como achado pendente: "`AuthController::login` loga `$credentials` inteiro via `Log::debug`, incluindo a senha em claro no log". O `AuthController.php` atual (revisado na TASK-340 desta feature) não tem mais nenhum `Log::debug` nem log de `$credentials` inteiro — só `Log::warning`/`Log::info` com `['email' => ...]`, já coberto por `AuthControllerLoginLogTest`. O item parece ter sido corrigido em algum commit anterior sem que a Constitution fosse atualizada.

## Por que importa

A Constitution é documento normativo vivo (não um snapshot arquivado) — um item de "achado que exige decisão humana" que já foi resolvido, mas continua listado como pendente, pode levar alguém a re-investigar ou re-priorizar um problema que não existe mais.

Tipo sugerido: doc
