<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();

$statusFiltro = $_GET['status'] ?? '';
$busca = trim($_GET['q'] ?? '');

$sql = "
    SELECT os.*, c.nome AS cliente_nome, a.tipo, a.marca, a.modelo
    FROM ordens_servico os
    JOIN aparelhos a ON a.id = os.aparelho_id
    JOIN clientes c ON c.id = a.cliente_id
    WHERE 1=1
";
$params = [];

if ($statusFiltro !== '') {
    $sql .= " AND os.status = ?";
    $params[] = $statusFiltro;
}
if ($busca !== '') {
    $sql .= " AND (os.codigo LIKE ? OR c.nome LIKE ? OR a.modelo LIKE ?)";
    $like = "%$busca%";
    array_push($params, $like, $like, $like);
}
$sql .= " ORDER BY os.criado_em DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ordens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$todosStatus = ['Recebido','Em análise','Aguardando aprovação','Em conserto','Pronto','Entregue','Cancelado'];

$pageTitle = 'Ordens de serviço';
$pageSub = count($ordens) . ' ordem(ns) encontrada(s)';
$activeNav = 'os';
require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:20px;">
    <div class="field" style="flex:1; min-width:220px;">
      <label for="q">Buscar</label>
      <input type="text" id="q" name="q" placeholder="Código, cliente ou modelo..." value="<?= htmlspecialchars($busca) ?>">
    </div>
    <div class="field" style="width:220px;">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Todos os status</option>
        <?php foreach ($todosStatus as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>" <?= $statusFiltro === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-solid desktop-solid-action">Filtrar</button>
    <?php if ($statusFiltro || $busca): ?><a href="os.php" class="btn btn-line">Limpar</a><?php endif; ?>
  </form>

  <?php if (empty($ordens)): ?>
    <div class="empty">Nenhuma ordem de serviço encontrada com esses filtros.</div>
  <?php else: ?>
  <table class="grid-table">
    <thead>
      <tr><th>Código</th><th>Cliente</th><th>Aparelho</th><th>Status</th><th>Orçamento</th><th>Aberta em</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($ordens as $os): ?>
        <tr>
          <td><span class="mono"><?= htmlspecialchars($os['codigo']) ?></span></td>
          <td><?= htmlspecialchars($os['cliente_nome']) ?></td>
          <td><?= htmlspecialchars($os['tipo'] . ' — ' . $os['marca'] . ' ' . $os['modelo']) ?></td>
          <td><span class="badge <?= classeStatus($os['status']) ?>"><?= htmlspecialchars($os['status']) ?></span></td>
          <td><?= formatarMoeda($os['valor_orcamento']) ?></td>
          <td><?= formatarData($os['data_abertura']) ?></td>
          <td><a href="os_detalhe.php?id=<?= $os['id'] ?>" class="btn btn-line btn-sm desktop-solid-action">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
