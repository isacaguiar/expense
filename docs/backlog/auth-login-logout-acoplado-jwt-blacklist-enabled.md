# Gate de e-mail não verificado no login depende silenciosamente de `JWT_BLACKLIST_ENABLED`

ID: 058
Origem: docs/feature/20260922-email-verificado-obrigatorio/ (achado do `security-reviewer` na TASK-340)
Criado em: 2026-09-22
Prioridade: BAIXA
Status: Aberto

## Descrição

`AuthController::login()` (`backend/app/Http/Controllers/AuthController.php`), ao recusar login de conta com `email_verified_at` nulo, chama `Auth::guard('api')->logout()` para invalidar o token JWT recém-emitido antes de responder `403`. Essa chamada só funciona sem lançar exceção porque `config/jwt.php:220` (`JWT_BLACKLIST_ENABLED`) está `true` — se esse flag for desligado no futuro (ex.: otimização de performance, prática comum para APIs stateless), `logout()` lança `JWTException` não capturada e a request aborta em `500` em vez do `403` esperado. Não há teste nem asserção em código que capture essa dependência.

Não é um vazamento de segurança em si (o token nunca chega a ser devolvido no corpo da resposta antes da exceção), mas é acoplamento silencioso a uma config de infraestrutura sem defesa em profundidade.

## Por que importa

Se `JWT_BLACKLIST_ENABLED` for desligado por outro motivo no futuro, o gate de e-mail não verificado quebra de forma abrupta (erro 500) sem ninguém perceber até acontecer em produção. Um teste que force `JWT_BLACKLIST_ENABLED=false` e confirme degradação controlada (ou um `try/catch` ao redor do `logout()`) fecharia essa lacuna.

Tipo sugerido: backend
