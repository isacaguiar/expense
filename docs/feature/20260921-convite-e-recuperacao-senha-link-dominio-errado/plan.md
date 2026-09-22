# Plan — Link de e-mail (convite de grupo e recuperação de senha) aponta pro domínio errado

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260921

---

## 1. Trocar `url()` por `config('services.frontend_url')` (specify §2.1, §2.2)

`UserInvitedMail.php:33` e `InvitationController.php:84` passam a montar o link com `config('services.frontend_url')` em vez de `url()`, seguindo o mesmo padrão já usado por `GoogleAuthController.php:49`. Não é preciso criar config nova — `config/services.php:40` (`'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')`) já existe e já está corretamente configurada em produção (`deploy-backend.yml`, secret `ENV_FRONTEND_URL`).

```php
// antes
$activationLink = url("/aceitar-convite?email={$this->user->email}&token={$this->token}");
// depois
$activationLink = config('services.frontend_url')."/aceitar-convite?email={$this->user->email}&token={$this->token}";
```

Mesma troca em `InvitationController::forgotPassword` para `$resetLink`. Nenhuma outra linha dos dois arquivos muda.

## 2. Rota `/recuperar-senha` no frontend, reaproveitando `AcceptInvitePage` (specify §2.2)

**Decisão:** nova rota apontando para o **mesmo componente** `AcceptInvitePage`, não duplicar página nem trocar o path que o backend gera. Alternativa descartada — fazer o backend gerar `/aceitar-convite` também para reset de senha — porque a URL ficaria semanticamente errada para quem está recuperando senha, não aceitando convite algum.

`AcceptInvitePage` já é agnóstico ao fluxo: só lê `email`/`token` da query string e posta em `POST /api/invitations/verify`, que já aceita tanto `invitation-token:` quanto `password-reset-token:` (`InvitationController.php:29-35`). O único ajuste é de **copy** — hoje o componente fala "convite" em dois lugares (`AcceptInvitePage.tsx:83`, mensagem de erro de parâmetros faltando; e implicitamente no título/botão), o que confundiria quem chegou ali para redefinir senha.

Solução, seguindo o precedente já usado em `LoginBrandingPanel` (`docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/plan.md §6`: "ganha props opcionais... com os valores atuais como default, para não duplicar o componente") — prop opcional `mode?: 'invite' | 'reset'`, default `'invite'` (preserva 100% do comportamento/testes atuais sem tocar neles):

| Texto | `mode="invite"` (atual, default) | `mode="reset"` (novo) |
|---|---|---|
| Título | "Criar senha de acesso" | "Redefinir senha" |
| Subtítulo | "Defina uma senha para ativar sua conta (…)." | "Defina uma nova senha de acesso (…)." |
| Alerta de link inválido | "Link de convite inválido — faltam informações. Solicite um novo convite." | "Link de recuperação inválido — faltam informações. Solicite uma nova recuperação de senha." |
| Botão de submit | "Ativar conta" | "Redefinir senha" |
| Snackbar de sucesso | "Senha definida com sucesso! Redirecionando para o login..." | "Senha redefinida com sucesso! Redirecionando para o login..." |

`frontend/src/App.tsx` ganha uma rota nova, ao lado de `/aceitar-convite` (linha 43):

```tsx
<Route path="/aceitar-convite" element={<AcceptInvitePage />} />
<Route path="/recuperar-senha" element={<AcceptInvitePage mode="reset" />} />
```

**Não renomeio** o arquivo/componente (`AcceptInvitePage.tsx`) apesar do nome agora cobrir dois fluxos — evita quebrar `AcceptInvitePage.test.tsx` e qualquer import existente por um custo que não paga a pena aqui (`06-context-backend.md`, "Não corrigir de passagem" — mesmo espírito vale do lado frontend: renomear é um refactor à parte, não desta feature). Fica registrado o nome desalinhado com o uso; se incomodar no futuro, é troca de nome isolada, sem lógica nova.

## 3. Testes (specify §2.3)

**Backend** — dois testes novos, um por Mailable/fluxo, verificando o **host** do link, não só a view:

- `GroupMemberInvitationMailTest.php`: no closure já existente de `Mail::assertSent(UserInvitedMail::class, ...)` (linha 51), adicionar a asserção de que `$mail->build()->viewData['activationLink']` começa com `config('services.frontend_url')` — hoje o teste só confere remetente/nome do grupo, não o link.
- `InvitationControllerForgotPasswordTest.php`: `forgotPassword` usa `Mail::send($view, $data, $closure)` bruto, não um Mailable — o precedente do projeto para inspecionar isso é `Mail::shouldReceive('send')` (Mockery), já usado nesta mesma classe de teste (`TASK-122`, `docs/feature/concluidas/202608/20260821-recuperacao-senha-login/implementation.md`). Novo teste captura o array de `$data` passado e afirma que `$data['resetLink']` começa com `config('services.frontend_url')`.

Os dois testes devem **falhar antes** da mudança de `url()` (comprovando que hoje o link sai com o host da API) e passar depois — não é teste que só confirma comportamento novo, é o que fixa a regressão.

**Frontend** — `AcceptInvitePage.test.tsx` ganha um `describe`/bloco novo para `mode="reset"`, espelhando o teste existente de submissão bem-sucedida (linha 36-53), mas renderizando `<AcceptInvitePage mode="reset" />` sob `/recuperar-senha` e afirmando os textos da coluna "reset" da tabela acima (título, botão "Redefinir senha", snackbar). Os testes existentes (`mode` implícito `'invite'`) continuam intactos e não precisam mudar.

`App.test.tsx` (se existir cobertura de rotas) ganha o caso de `/recuperar-senha` resolvendo para o componente — só se esse tipo de teste já existir no projeto; não criar suíte de roteamento nova só para isso.

## 4. Gates e o que não dá para fechar aqui

- Sem migration, sem mudança de contrato de API (`POST /api/invitations/verify` já aceita os dois tipos de token; nenhum payload/rota muda).
- `FRONTEND_URL` já está correta em produção — nenhuma mudança de secret/workflow necessária nesta feature (diferente do bug irmão, PR #190).
- Merge do PR final em `dev` é gate humano, como sempre (`00-constitution.md` §5.2). Como a feature toca `InvitationController`/`UserInvitedMail` (auth), o agent `security-reviewer` deve rodar antes do PR, conforme `CLAUDE.md` item 4.
