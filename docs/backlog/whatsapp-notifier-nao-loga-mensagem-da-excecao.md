# `WhatsAppNotifier` registra `getMessage()` da exceção de envio

ID: 164
Origem: `security-reviewer` no fechamento da feature `20261124-notifier-nao-loga-sql-com-bindings` (informativo 1); `backend/app/Support/WhatsApp/WhatsAppNotifier.php:143-147`
Criado em: 2026-11-24
Prioridade: BAIXA
Status: Promovido para TASK-685 (feature 20261125-log-sem-mensagem-da-excecao-restantes)

## Descrição
No envio por WhatsApp, o bloco que captura `\Throwable` faz `Log::warning('WhatsApp: falha ao enviar notificação', ['user_id' => ..., 'template' => ..., 'error' => $e->getMessage()])`. A falha vem de `MetaCloudClient::sendTemplate` (chamada HTTP): a mensagem pode trazer o corpo da resposta da Meta ou a URL, e o telefone do destinatário é dado pessoal. É o mesmo padrão corrigido no `Notifier::guard` (TASK-676), com vazamento menos provável que o de SQL com bindings.

Sugestão: registrar `$e::class` no lugar de `$e->getMessage()`, mantendo `user_id` e `template`. Antes de mexer, conferir se algum teste afirma o conteúdo desse contexto.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar | A) só a classe (`$e::class`)<br>B) a classe e o status HTTP, se houver | **A** | Mesmo critério do `Notifier::guard`; o status HTTP pode ser visto no painel da Meta. |
| D2 | Agrupar com o item 163 | A) uma feature só para os dois<br>B) separados | **A** | Mesma mudança, mesmo critério, mesmo teste de log. |

## Por que importa
Higiene de log: dado pessoal e resposta de terceiro em texto livre, sem uso para o diagnóstico. Nenhuma exploração hoje.

Tipo sugerido: backend (sem mudança de contrato nem migration)
