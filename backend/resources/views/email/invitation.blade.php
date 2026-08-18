<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Convite para a Plataforma - Novemax</title>
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
    .button {
      display: inline-block;
      background-color: #0056d2;
      color: white;
      text-decoration: none;
      padding: 12px 24px;
      border-radius: 5px;
      margin-top: 20px;
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
      <img src="https://novemax.com.br/assets/images/logo.png" alt="Novemax">
    </div>

    <h2>Você foi convidado para acessar a plataforma Novemax!</h2>

    <p><strong>{{ $inviterName }}</strong> convidou você para participar da nossa plataforma.</p>

    @if($message)
      <p><em>Mensagem:</em> "{{ $message }}"</p>
    @endif

    <p>Para começar, clique no botão abaixo e ative sua conta:</p>

    <p style="text-align: center;">
      <a href="{{ $activationLink }}" class="button">Ativar minha conta</a>
    </p>

    <p>Se preferir, copie e cole este link no navegador:</p>
    <p><a href="{{ $activationLink }}">{{ $activationLink }}</a></p>

    <div class="footer">
      <p>Este convite é exclusivo e válido para um único uso.</p>
      <p>© {{ now()->year }} Novemax Soluções em Informática</p>
    </div>
  </div>
</body>
</html>
