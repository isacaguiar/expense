<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Código de confirmação - Novemax</title>
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
    .code {
      display: inline-block;
      background-color: #E8F5E9;
      color: #128468;
      font-size: 32px;
      font-weight: bold;
      letter-spacing: 8px;
      padding: 16px 28px;
      border-radius: 8px;
      margin-top: 10px;
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

    <h2>Confirme seu e-mail para criar a conta</h2>

    <p>Olá, <strong>{{ $name }}</strong>!</p>

    <p>Use o código abaixo na tela de cadastro para confirmar este endereço de e-mail:</p>

    <p style="text-align: center;">
      <span class="code">{{ $code }}</span>
    </p>

    <p>O código vale por <strong>{{ $expiresInMinutes }} minutos</strong>. Depois disso, peça um novo na própria tela.</p>

    <p>Se não foi você que pediu este cadastro, ignore este e-mail — nenhuma conta é criada sem o código.</p>

    <div class="footer">
      <p>Nunca compartilhe este código com outras pessoas.</p>
      <p>© {{ now()->year }} Novemax Soluções em Informática</p>
    </div>
  </div>
</body>
</html>
