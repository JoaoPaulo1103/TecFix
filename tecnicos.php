<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();
exigirAdmin();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'adicionar') {
            $nome = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $senha = $_POST['senha'] ?? '';

            if ($nome === '' || $email === '' || $senha === '') {
                throw new RuntimeException('Preencha nome, e-mail e senha.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }
            if (strlen($senha) < 6) {
                throw new RuntimeException('A senha deve ter pelo menos 6 caracteres.');
            }

            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, ativo) VALUES (?,?,?,?,1)");
            $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), 'tecnico']);
            $sucesso = 'Técnico cadastrado com sucesso.';
        } elseif ($acao === 'alternar') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, nome, ativo FROM usuarios WHERE id = ? AND cargo = 'tecnico'");
            $stmt->execute([$id]);
            $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tecnico) {
                throw new RuntimeException('Técnico não encontrado.');
            }

            $novoStatus = (int)$tecnico['ativo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE usuarios SET ativo = ? WHERE id = ? AND cargo = 'tecnico'")
                ->execute([$novoStatus, $id]);

            $sucesso = $novoStatus ? 'Técnico reativado.' : 'Acesso do técnico retirado.';
        }
    } catch (PDOException $e) {
        $erro = $e->getCode() === '23000'
            ? 'Já existe um usuário com este e-mail.'
            : 'Não foi possível concluir a operação.';
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

$tecnicos = $pdo->query("SELECT id, nome, email, ativo, criado_em FROM usuarios WHERE cargo = 'tecnico' ORDER BY ativo DESC, nome")
    ->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Administrar técnicos';
$pageSub = 'Adicione técnicos e controle quem tem acesso ao sistema';
$activeNav = 'tecnicos';
require __DIR__ . '/includes/header.php';
?>

<?php if ($erro): ?><div class="alert alert-error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alert alert-ok"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<div class="form-grid">
  <div class="panel">
    <div class="panel-head"><h2>Adicionar técnico</h2></div>
    <form method="post">
      <input type="hidden" name="acao" value="adicionar">
      <div class="field" style="margin-bottom:14px;">
        <label for="nome">Nome</label>
        <input id="nome" name="nome" type="text" required>
      </div>
      <div class="field" style="margin-bottom:14px;">
        <label for="email">E-mail de acesso</label>
        <input id="email" name="email" type="email" required>
      </div>
      <div class="field" style="margin-bottom:14px;">
        <label for="senha">Senha inicial</label>
        <input id="senha" name="senha" type="password" minlength="6" required>
        <div class="hint">Mínimo de 6 caracteres.</div>
      </div>
      <button type="submit" class="btn btn-accent btn-cadastrar-tecnico" style="background:#3d9674 !important;background-image:none !important;border:1px solid #3d9674 !important;border-color:#3d9674 !important;color:#fff !important;box-shadow:none !important;">Cadastrar técnico</button>
    </form>
  </div>

  <div class="panel">
    <div class="panel-head">
      <h2>Técnicos cadastrados</h2>
      <span class="badge status-azul"><?= count($tecnicos) ?> cadastro(s)</span>
    </div>

    <?php if (empty($tecnicos)): ?>
      <div class="empty" style="padding:24px;">Nenhum técnico cadastrado.</div>
    <?php else: ?>
      <table class="grid-table">
        <thead><tr><th>Nome</th><th>E-mail</th><th>Status</th><th>Ação</th></tr></thead>
        <tbody>
          <?php foreach ($tecnicos as $tec): ?>
            <tr>
              <td style="font-weight:600;"><?= htmlspecialchars($tec['nome']) ?></td>
              <td><?= htmlspecialchars($tec['email']) ?></td>
              <td>
                <span class="badge <?= $tec['ativo'] ? 'status-verde' : 'status-vermelho' ?>">
                  <?= $tec['ativo'] ? 'Ativo' : 'Acesso retirado' ?>
                </span>
              </td>
              <td>
                <form method="post">
                  <input type="hidden" name="acao" value="alternar">
                  <input type="hidden" name="id" value="<?= (int)$tec['id'] ?>">
                  <button type="submit" class="btn <?= $tec['ativo'] ? 'btn-danger' : 'btn-line' ?> btn-sm">
                    <?= $tec['ativo'] ? 'Retirar acesso' : 'Reativar' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
