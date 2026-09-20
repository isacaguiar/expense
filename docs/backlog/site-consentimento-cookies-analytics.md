# Google Analytics do site carrega sem consentimento

ID: 048
Origem: docs/feature/20260920-site-conteudo-e-precos/specify.md §3
Criado em: 2026-09-20
Prioridade: MEDIA
Status: Aberto

## Descrição
O `site/src/templates/header.php:18-26` injeta o `gtag.js` (`G-RNQM4DT19G`) incondicionalmente, antes até do `<meta charset>`, sem banner de consentimento e sem nenhuma forma de recusa. O site publica uma Política de Privacidade em `site/public/privacidade.php`, mas não há mecanismo que respeite a escolha do visitante.

## Por que importa
Analytics com cookies é tratamento de dado pessoal sob a LGPD, e o consentimento precisa ser livre, informado e revogável. Hoje há um descasamento entre o que a política declara e o que o site faz — o tipo de inconsistência que fica cara quando alguém repara, e que só piora conforme o site ganha tráfego. Resolver envolve decidir entre banner de consentimento, Consent Mode do Google, ou trocar por uma solução de analytics sem cookies.

Tipo sugerido: frontend
