<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (usuarioLogado()) { header('Location: index.php'); exit; }

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
        $_SESSION['usuario'] = [
            'id' => $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'cargo' => $usuario['cargo'],
        ];
        header('Location: index.php');
        exit;
    }
    $erro = 'E-mail ou senha inválidos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar — TecFix</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="center-screen">
  <div class="auth-box">
    <div class="brand"><span class="dot"></span>TecFix<small>ASSISTÊNCIA TÉCNICA</small></div>
    <h2>Acesso da equipe</h2>
    <div class="sub">Entre com sua conta para gerenciar as ordens de serviço.</div>

    <?php if ($erro): ?><div class="alert alert-error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

    <form method="post">
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autofocus placeholder="voce@assistencia.com">
      </div>
      <div class="field">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-solid" style="width:100%; margin-top:6px;">Entrar</button>
    </form>

    <div class="demo-creds">
      admin@assistencia.com / admin123 (admin)<br>
      tecnico@assistencia.com / tecnico123 (técnico)<br>
      atendente@assistencia.com / atendente123 (atendente)
    </div>

    <div class="public-link">É cliente? <a href="consulta.php">Consulte sua ordem de serviço aqui</a></div>
  </div>
</div>
</body>
</html>
