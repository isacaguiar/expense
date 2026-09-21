#!/usr/bin/env bash
#
# Gera site/src/legal-dates.php a partir do histórico do git.
#
# A data de "Última atualização" de cada documento legal é a data de autoria do
# último commit que alterou o arquivo de conteúdo correspondente em
# site/src/legal/. O servidor não tem git, então o cálculo acontece no runner do
# deploy e chega ao site como dado pronto, lido por legal_updated_at()
# (site/src/helpers.php).
#
# Roda também na máquina de quem desenvolve, para conferir o que o deploy vai
# publicar antes de publicar:
#
#     bash site/tools/gerar-datas-legais.sh
#
set -euo pipefail

# Precisa ser o mesmo valor de FUSO_DO_PROJETO em site/src/helpers.php: se os
# dois divergirem, um documento alterado à noite é datado como do dia seguinte.
FUSO='America/Sao_Paulo'

DOCUMENTOS=(privacidade termos)

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DESTINO="$RAIZ/site/src/legal-dates.php"

# Clone raso devolve data ERRADA, não vazia: sem histórico, o git enxerga todo
# arquivo como criado no commit do topo, e cada documento sairia datado do dia
# do deploy. Por isso a checagem é explícita, e não "a saída veio vazia".
if [ "$(git -C "$RAIZ" rev-parse --is-shallow-repository)" = 'true' ]; then
  echo "ERRO: repositório raso — as datas sairiam todas com a data do deploy." >&2
  echo "      O checkout do workflow precisa de 'fetch-depth: 0'." >&2
  exit 1
fi

declare -A DATAS

for documento in "${DOCUMENTOS[@]}"; do
  arquivo="site/src/legal/${documento}.php"

  if [ ! -f "$RAIZ/$arquivo" ]; then
    echo "ERRO: $arquivo não existe." >&2
    exit 1
  fi

  # %ad (autoria) e não %cd (commit): rebase e cherry-pick reescrevem a data de
  # commit sem que uma linha do texto tenha mudado.
  data="$(TZ="$FUSO" git -C "$RAIZ" log -1 --date=format-local:'%Y-%m-%d' --format=%ad -- "$arquivo")"

  if [ -z "$data" ]; then
    echo "ERRO: git log não devolveu data para $arquivo." >&2
    exit 1
  fi

  DATAS["$documento"]="$data"
done

# Só escreve depois que todas as datas foram obtidas — um arquivo parcial seria
# pior do que nenhum, porque legal_updated_at() confia no que estiver lá.
{
  echo '<?php'
  echo ''
  echo '// Gerado por site/tools/gerar-datas-legais.sh durante o deploy.'
  echo '// Não editar à mão: qualquer valor aqui é sobrescrito no próximo deploy.'
  echo 'return ['
  for documento in "${DOCUMENTOS[@]}"; do
    printf "    '%s' => '%s',\n" "$documento" "${DATAS[$documento]}"
  done
  echo '];'
} > "$DESTINO"

echo "Gerado $DESTINO"
for documento in "${DOCUMENTOS[@]}"; do
  echo "  $documento -> ${DATAS[$documento]}"
done
