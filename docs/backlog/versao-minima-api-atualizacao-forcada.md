# Versão mínima da API / atualização forçada

ID: 073
Origem: análise do app na Google Play (conversa de 2026-10-06)
Criado em: 2026-10-06
Prioridade: MEDIA
Status: Aberto

## Descrição
Não existe mecanismo para avisar a um cliente que a versão dele é antiga demais para falar com a API. Propor um endpoint aditivo (ex.: `GET /api/app-config`, público) com `min_version` por plataforma e uma mensagem, para o app (e, se fizer sentido, o web) consultar ao abrir e bloquear versões abaixo do mínimo, levando o usuário à loja.

Decidir no specify: formato da versão, onde o valor é configurado (config/env do backend, sem migration), comportamento sem rede e a política de quando subir o mínimo.

Ordem: sem dependências, pode andar em paralelo à trilha **070 → 071 → 074**. Precisa estar pronto **antes do primeiro release** do app (074).

## Por que importa
Um app nativo não é atualizado pelo deploy: versões antigas ficam em campo, e o app Flutter não tem atualização OTA, então toda correção passa pela loja. Sem um mínimo, uma mudança de contrato pode quebrar usuários antigos sem aviso. Com ele, a API continua aditiva (Constitution §4.1) e passa a existir uma saída quando uma mudança incompatível for inevitável.

Tipo sugerido: backend
