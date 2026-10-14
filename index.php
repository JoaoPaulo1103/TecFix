<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();

// contagem por status
$contagem = array_fill_keys(['Recebido','Em análise','Aguardando aprovação','Em conserto','Pronto'], 0);
$stmt = $pdo->query("SELECT status, COUNT(*) c FROM ordens_servico WHERE status != 'Entregue' AND status != 'Cancelado' GROUP BY status");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contagem[$row['status']] = (int)$row['c'];
}

$recentes = $pdo->query("
    SELECT os.*, c.nome AS cliente_nome, a.tipo, a.marca, a.modelo
    FROM ordens_servico os
    JOIN aparelhos a ON a.id = os.aparelho_id
    JOIN clientes c ON c.id = a.cliente_id
    ORDER BY os.criado_em DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Painel';
$pageSub = 'Visão geral das ordens de serviço em andamento';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="cards-row">
  <?php foreach ($contagem as $status => $n): ?>
    <div class="stat-card">
      <div class="num"><?= $n ?></div>
      <div class="label"><span class="badge <?= classeStatus($status) ?>"><?= htmlspecialchars($status) ?></span></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Últimas ordens de serviço</h2>
    <a href="os.php" class="btn btn-line btn-sm">Ver todas</a>
  </div>

  <?php if (empty($recentes)): ?>
    <div class="empty">Nenhuma ordem de serviço cadastrada ainda.</div>
  <?php else: ?>
  <table class="grid-table">
    <thead>
      <tr><th>Código</th><th>Cliente</th><th>Aparelho</th><th>Status</th><th>Aberta em</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($recentes as $os): ?>
        <tr>
          <td><span class="mono"><?= htmlspecialchars($os['codigo']) ?></span></td>
          <td><?= htmlspecialchars($os['cliente_nome']) ?></td>
          <td><?= htmlspecialchars($os['marca'] . ' ' . $os['modelo']) ?></td>
          <td><span class="badge <?= classeStatus($os['status']) ?>"><?= htmlspecialchars($os['status']) ?></span></td>
          <td><?= formatarData($os['data_abertura']) ?></td>
          <td><a href="os_detalhe.php?id=<?= $os['id'] ?>" class="btn btn-line btn-sm">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
