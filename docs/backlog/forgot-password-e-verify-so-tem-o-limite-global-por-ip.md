# `/forgot-password` e `/invitations/verify` só têm o limite global por IP, e o limite por e-mail tem corrida

ID: 118
Origem: docs/feature/20260930-recuperacao-acesso/specify.md §3 e revisão de segurança de 2026-10-30 (F1); `backend/routes/api.php:21-22`, `backend/app/Providers/RouteServiceProvider.php:27-29`, `backend/app/Http/Controllers/InvitationController.php` (`forgotPassword`, linhas 71-76, 87 e 106)
Criado em: 2026-10-30
Prioridade: MEDIA
Status: Aberto

## Descrição
As duas rotas públicas só têm o limitador global `api` (60 requisições por minuto por IP). A rota irmã `/pre-register` tem `throttle:10,1` e `/pre-register/resend`, `throttle:5,1`, então a de recuperação é 6 vezes mais frouxa e **ainda dispara e-mail**: cada pedido com e-mail existente executa um `Mail::send` síncrono (`forgotPassword`, linha 87). Trocando o e-mail a cada requisição, um IP consegue até 60 envios por minuto: o app vira relé de e-mail com o remetente do domínio, consome a cota SMTP do provedor e prende um worker PHP em hospedagem compartilhada enquanto o SMTP responde.

O limite por e-mail (cache `password-reset-rate:` por 15 minutos) tem **corrida**: o `Cache::has` é lido na linha 72 e o `Cache::put` só acontece depois do envio, na 106. Requisições paralelas para o mesmo e-mail passam todas; cada uma envia um e-mail e **sobrescreve o token**, então quem clica no e-mail mais antigo vê "Token inválido ou expirado".

Proposta: `->middleware('throttle:5,1')` nas duas rotas (o padrão do repositório) e um `Cache::add` atômico antes do envio, com `Cache::forget` se o envio falhar.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Usa o mesmo mecanismo e o mesmo padrão de `/pre-register`; nenhuma biblioteca nova. |
| R2 | A resposta 429 já existe na rota e continua sendo a mesma; não muda contrato. |
| R3 | Segurança: vira task própria, com gate humano (`00-constitution.md` §6 item 6), e não correção de passagem em outra feature. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Valor do limite por IP | A) 5 por minuto (como o `resend`)<br>B) 10 por minuto (como o `pre-register`) | **A** | O fluxo legítimo pede um e-mail por vez; 5 por minuto não atrapalha ninguém e reduz o relé a um quinto. |
| D2 | IP real em produção | A) conferir antes qual `REMOTE_ADDR` chega à API (atrás de CDN ou proxy)<br>B) assumir que é o IP do cliente | **A** | `TrustProxies` está com `$proxies = null`; se houver CDN na frente, o limite por IP vira global e bloqueia todos juntos. Não foi verificado. |

## Por que importa
Abuso do envio de e-mail e da cota SMTP é custo real e barato de provocar; a corrida ainda quebra o uso legítimo (o link mais novo some). Foi achado pré-existente: o endpoint já está público desde o primeiro deploy, e a tela nova só o torna visível.

Tipo sugerido: backend (segurança)
