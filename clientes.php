<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();
if (!podeVerDadosPessoais()) { http_response_code(403); die('Acesso negado.'); }

$busca = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM clientes";
$params = [];
if ($busca !== '') {
    $sql .= " WHERE nome LIKE ? OR telefone LIKE ? OR cpf LIKE ?";
    $like = "%$busca%";
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY nome";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// contagem de aparelhos e OS por cliente
$contAparelhos = $pdo->query("SELECT cliente_id, COUNT(*) c FROM aparelhos GROUP BY cliente_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Clientes';
$pageSub = count($clientes) . ' cliente(s) cadastrado(s)';
$activeNav = 'clientes';
require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <form method="get" style="display:flex; gap:10px; margin-bottom:20px;">
    <div class="field" style="flex:1;">
      <input type="text" name="q" placeholder="Buscar por nome, telefone ou CPF..." value="<?= htmlspecialchars($busca) ?>">
    </div>
    <button type="submit" class="btn btn-solid desktop-solid-action">Buscar</button>
    <?php if ($busca): ?><a href="clientes.php" class="btn btn-line">Limpar</a><?php endif; ?>
  </form>

  <?php if (empty($clientes)): ?>
    <div class="empty">Nenhum cliente encontrado.</div>
  <?php else: ?>
  <table class="grid-table">
    <thead><tr><th>Nome</th><th>Telefone</th><th>E-mail</th><th>CPF</th><th>Aparelhos</th></tr></thead>
    <tbody>
      <?php foreach ($clientes as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['nome']) ?></td>
          <td><?= htmlspecialchars($c['telefone']) ?></td>
          <td><?= htmlspecialchars($c['email'] ?: '—') ?></td>
          <td><span class="mono"><?= htmlspecialchars($c['cpf'] ?: '—') ?></span></td>
          <td><?= (int)($contAparelhos[$c['id']] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
