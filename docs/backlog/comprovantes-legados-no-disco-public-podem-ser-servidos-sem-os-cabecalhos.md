# Os comprovantes legados do disco `public` podem ser servidos direto pelo servidor web, sem URL assinada e sem os cabeçalhos do item 154

ID: 157
Origem: docs/feature/20261116-endurecer-upload-de-comprovante/ (TASK-645, revisão do `security-reviewer`, achado médio fora do delta; **não confirmado em produção**)
Criado em: 2026-11-16
Prioridade: MEDIA
Status: Aberto

## Descrição
`ProofStorage::resolveDisk` manda para o disco `public` todo caminho que não segue o layout novo `comprovantes/<grupo>/...`: ou seja, os comprovantes **legados** `comprovantes/xxx` e `comprovantes-settlements/xxx`, gravados antes da convenção por grupo. `config/filesystems.php` aponta o disco `public` para `storage/app/public` e o `links` cria `public/storage` → `storage/app/public`.

- Onde o `php artisan storage:link` existe (confirmado no ambiente local), o Apache serve esses arquivos direto em `/storage/comprovantes/<arquivo>`: o `.htaccess` do Laravel só reescreve para o `index.php` quando o arquivo **não** existe.
- Esse caminho é anônimo: não passa pelo `ProofDownloadController`, não exige a URL assinada de 30 minutos (ADR-005) e **não recebe** o `nosniff` nem o CSP `sandbox` do item 154. Um SVG legado com `<script>` abriria com script na origem da API para quem soubesse o nome.
- O nome do arquivo é um uuid ou hash: inadivinhável, mas não protegido. A exploração exige conhecer o nome (um link assinado vencido, um log, um histórico do navegador).
- O deploy exclui `/storage/app` e `/storage/logs` (`.github/workflows/deploy-backend.yml:97`), mas o symlink `public/storage` em produção depende de ter sido criado à mão no cPanel. **Não sei se existe.**

## Conferência (gate humano, de fora)
1. Em produção, pedir `https://<api>/storage/comprovantes/<nome-de-um-comprovante-legado>` e ver se responde 200 (o nome vem do banco: `payment_proof_path` sem o layout `comprovantes/<id>/`, ou `proof_path` de `ex_settlement_confirmations`). 200 = exposto; 404 ou 403 = o link não existe e o item cai para BAIXA.
2. Listar no `storage/app/public` os arquivos `.svg`, `.html` e `.htm`: se não houver nenhum, o conteúdo ativo não existe hoje, e o risco é só o de uma ação futura.

## Sugestão
Se a conferência 1 der 200: migrar os legados para o layout novo no disco privado (script único, com cópia e atualização de `payment_proof_path` e `proof_path` numa transação por linha, sem apagar o original até conferir) ou, mais simples e reversível, bloquear a execução de conteúdo ativo no `storage/app/public` por `.htaccess` (`Header set Content-Security-Policy "default-src 'none'; sandbox"` e `X-Content-Type-Options: nosniff`, se o `mod_headers` estiver disponível no host compartilhado). Migrar arquivo de produção é gate humano.

## Por que importa
O item 154 fecha o caminho do `ProofDownloadController`, que serve a maioria dos comprovantes. Os legados são o único conjunto que ainda pode passar por fora dele, e a verificação em produção é barata.

Tipo sugerido: infra e backend (depois da conferência)
