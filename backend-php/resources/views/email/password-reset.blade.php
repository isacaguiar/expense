<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Recuperação de senha - Novemax</title>
  <style>
    body { background-color: #f2f4f8; font-family: 'Segoe UI', Helvetica, Arial, sans-serif; padding: 20px; }
    .container { background: #ffffff; border-radius: 8px; padding: 30px; max-width: 600px; margin: auto; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
    .logo { text-align: center; margin-bottom: 20px; }
    .logo img { height: 50px; }
    .button { display: inline-block; background-color: #0056d2; color: white; text-decoration: none; padding: 12px 24px; border-radius: 5px; margin-top: 20px; }
    .footer { margin-top: 40px; font-size: 12px; color: #999999; text-align: center; }
  </style>
</head>
<body>
  <div class="container">
    <div class="logo">
      <img src="https://novemax.com.br/assets/images/logo.png" alt="Novemax">
    </div>

    <h2>Recuperação de senha</h2>

    <p>Olá {{ $user->name }},</p>

    <p>Você solicitou a recuperação de sua senha. Clique no botão abaixo para definir uma nova senha:</p>

    <p style="text-align: center;">
      <a href="{{ $resetLink }}" class="button">Redefinir senha</a>
    </p>

    <p>Ou copie e cole o link no navegador:</p>
    <p><a href="{{ $resetLink }}">{{ $resetLink }}</a></p>

    <div class="footer">
      <p>Se você não solicitou esta recuperação, apenas ignore este e-mail.</p>
      <p>© {{ now()->year }} Novemax Soluções em Informática</p>
    </div>
  </div>
</body>
</html>
