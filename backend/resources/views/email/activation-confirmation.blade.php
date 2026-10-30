<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Conta ativada com sucesso - Shared Expense</title>
  <style>
    body {
      background-color: #f2f4f8;
      font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
      margin: 0;
      padding: 20px;
    }
    .container {
      background: #ffffff;
      max-width: 600px;
      margin: 0 auto;
      border-radius: 8px;
      padding: 30px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    .logo {
      text-align: center;
      margin-bottom: 20px;
    }
    .logo img {
      height: 50px;
    }
    .footer {
      margin-top: 40px;
      font-size: 12px;
      color: #999999;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="logo">
      <img src="{{ $message->embed(resource_path('images/mail/logo-expense.png')) }}" alt="Shared Expense">
    </div>

    <h2>Conta ativada com sucesso!</h2>

    <p>Olá {{ $user->name }},</p>

    <p>Sua conta foi ativada com sucesso e agora você pode acessar a plataforma Shared Expense com seu e-mail e senha recém-definidos.</p>

    <p>Seja bem-vindo(a)!</p>

    <div class="footer">
      <p>© {{ now()->year }} Shared Expense</p>
    </div>
  </div>
</body>
</html>
