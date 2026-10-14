<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();
exigirAdmin();

$periodo = $_GET['periodo'] ?? 'dia';
$periodosPermitidos = ['dia', 'semana', 'mes', 'ano'];
if (!in_array($periodo, $periodosPermitidos, true)) {
    $periodo = 'dia';
}

$tzLocal = new DateTimeZone('America/Sao_Paulo');
$tzUtc = new DateTimeZone('UTC');
$agora = new DateTimeImmutable('now', $tzLocal);

switch ($periodo) {
    case 'semana':
        $inicioLocal = $agora->modify('monday this week')->setTime(0, 0, 0);
        $fimLocal = $inicioLocal->modify('+1 week');
        $tituloPeriodo = 'Esta semana';
        break;
    case 'mes':
        $inicioLocal = $agora->modify('first day of this month')->setTime(0, 0, 0);
        $fimLocal = $inicioLocal->modify('first day of next month');
        $tituloPeriodo = 'Este mês';
        break;
    case 'ano':
        $inicioLocal = $agora->setDate((int)$agora->format('Y'), 1, 1)->setTime(0, 0, 0);
        $fimLocal = $inicioLocal->modify('+1 year');
        $tituloPeriodo = 'Este ano';
        break;
    default:
        $inicioLocal = $agora->setTime(0, 0, 0);
        $fimLocal = $inicioLocal->modify('+1 day');
        $tituloPeriodo = 'Hoje';
        break;
}

// O SQLite grava datetime('now') em UTC. As consultas abaixo convertem
// os limites escolhidos no horário de São Paulo para UTC antes da busca.
$inicioUtc = $inicioLocal->setTimezone($tzUtc)->format('Y-m-d H:i:s');
$fimUtc = $fimLocal->setTimezone($tzUtc)->format('Y-m-d H:i:s');

// ---------------- Receitas recebidas ----------------
$stmt = $pdo->prepare("SELECT valor, data_pagamento FROM pagamentos WHERE status = 'Pago' AND data_pagamento >= ? AND data_pagamento < ? ORDER BY data_pagamento");
$stmt->execute([$inicioUtc, $fimUtc]);
$pagamentosPeriodo = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ganhoTotal = 0.0;
foreach ($pagamentosPeriodo as $pagamento) {
    $ganhoTotal += (float)$pagamento['valor'];
}

// ---------------- Clientes atendidos ----------------
// Consideramos atendido o cliente que teve uma OS entregue no período.
$stmt = $pdo->prepare("\n    SELECT COUNT(DISTINCT c.id)\n    FROM ordens_servico os\n    JOIN aparelhos a ON a.id = os.aparelho_id\n    JOIN clientes c ON c.id = a.cliente_id\n    WHERE os.status = 'Entregue'\n      AND os.data_entrega >= ?\n      AND os.data_entrega < ?\n");
$stmt->execute([$inicioUtc, $fimUtc]);
$clientesAtendidos = (int)$stmt->fetchColumn();

// ---------------- Dados do gráfico ----------------
$labels = [];
$valores = [];

if ($periodo === 'dia') {
    for ($h = 0; $h < 24; $h++) {
        $labels[] = sprintf('%02d:00', $h);
        $valores[] = 0.0;
    }
    foreach ($pagamentosPeriodo as $pagamento) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $pagamento['data_pagamento'], $tzUtc);
        if (!$dt) continue;
        $local = $dt->setTimezone($tzLocal);
        $h = (int)$local->format('G');
        $valores[$h] += (float)$pagamento['valor'];
    }
    $textoGrafico = 'Recebimentos por hora';
} elseif ($periodo === 'semana') {
    $nomes = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    for ($i = 0; $i < 7; $i++) {
        $labels[] = $nomes[$i];
        $valores[] = 0.0;
    }
    foreach ($pagamentosPeriodo as $pagamento) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $pagamento['data_pagamento'], $tzUtc);
        if (!$dt) continue;
        $local = $dt->setTimezone($tzLocal);
        $i = (int)$local->format('N') - 1;
        $valores[$i] += (float)$pagamento['valor'];
    }
    $textoGrafico = 'Recebimentos por dia';
} elseif ($periodo === 'mes') {
    $dias = (int)$inicioLocal->format('t');
    for ($i = 1; $i <= $dias; $i++) {
        $labels[] = sprintf('%02d', $i);
        $valores[] = 0.0;
    }
    foreach ($pagamentosPeriodo as $pagamento) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $pagamento['data_pagamento'], $tzUtc);
        if (!$dt) continue;
        $local = $dt->setTimezone($tzLocal);
        $i = (int)$local->format('j') - 1;
        if (isset($valores[$i])) $valores[$i] += (float)$pagamento['valor'];
    }
    $textoGrafico = 'Recebimentos por dia do mês';
} else {
    $nomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    for ($i = 0; $i < 12; $i++) {
        $labels[] = $nomes[$i];
        $valores[] = 0.0;
    }
    foreach ($pagamentosPeriodo as $pagamento) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $pagamento['data_pagamento'], $tzUtc);
        if (!$dt) continue;
        $local = $dt->setTimezone($tzLocal);
        $i = (int)$local->format('n') - 1;
        $valores[$i] += (float)$pagamento['valor'];
    }
    $textoGrafico = 'Recebimentos por mês';
}

$maxValorGrafico = max($valores ?: [0]);

// Geometria do gráfico SVG responsivo.
$chartW = 1000;
$chartH = 360;
$padL = 64;
$padR = 24;
$padT = 28;
$padB = 54;
$plotW = $chartW - $padL - $padR;
$plotH = $chartH - $padT - $padB;
$escalaMax = $maxValorGrafico > 0 ? $maxValorGrafico * 1.12 : 1;
$quantidadePontos = count($valores);
$points = [];
foreach ($valores as $i => $valor) {
    $x = $quantidadePontos > 1 ? $padL + ($i / ($quantidadePontos - 1)) * $plotW : $padL + $plotW / 2;
    $y = $padT + $plotH - (($valor / $escalaMax) * $plotH);
    $points[] = ['x' => $x, 'y' => $y, 'valor' => (float)$valor, 'label' => $labels[$i]];
}
$linhaPontos = implode(' ', array_map(fn($p) => number_format($p['x'], 2, '.', '') . ',' . number_format($p['y'], 2, '.', ''), $points));
$areaPontos = $padL . ',' . ($padT + $plotH) . ' ' . $linhaPontos . ' ' . ($padL + $plotW) . ',' . ($padT + $plotH);
$passoRotulo = count($labels) <= 12 ? 1 : (count($labels) <= 24 ? 2 : 4);

$pageTitle = 'Finanças';
$pageSub = 'Acompanhe os recebimentos e clientes atendidos';
$activeNav = 'financas';
require __DIR__ . '/includes/header.php';
?>

<div class="panel finance-period-panel">
  <div>
    <div class="panel-kicker">PERÍODO</div>
    <h2><?= htmlspecialchars($tituloPeriodo) ?></h2>
    <div class="finance-period-date">
      <?= $inicioLocal->format('d/m/Y H:i') ?> até <?= $fimLocal->modify('-1 second')->format('d/m/Y H:i') ?>
    </div>
  </div>

  <form method="get" class="finance-period-form">
    <label for="periodo">Mostrar</label>
    <select id="periodo" name="periodo" onchange="this.form.submit()">
      <option value="dia" <?= $periodo === 'dia' ? 'selected' : '' ?>>Dia</option>
      <option value="semana" <?= $periodo === 'semana' ? 'selected' : '' ?>>Semana</option>
      <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Mês</option>
      <option value="ano" <?= $periodo === 'ano' ? 'selected' : '' ?>>Ano</option>
    </select>
    <button type="submit" class="btn btn-solid desktop-solid-action">Aplicar</button>
  </form>
</div>

<div class="cards-row finance-cards">
  <div class="stat-card finance-main-card">
    <div class="finance-card-title">Ganho no período</div>
    <div class="num"><?= formatarMoeda($ganhoTotal) ?></div>
    <div class="label">Pagamentos com status <strong>Pago</strong></div>
  </div>
  <div class="stat-card">
    <div class="finance-card-title">Clientes atendidos</div>
    <div class="num"><?= $clientesAtendidos ?></div>
    <div class="label">Clientes únicos com OS entregue</div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <div>
      <h2>Gráfico de ganhos</h2>
      <div class="panel-subtext"><?= htmlspecialchars($textoGrafico) ?></div>
    </div>
    <span class="badge status-verde"><?= htmlspecialchars($tituloPeriodo) ?></span>
  </div>

  <div class="finance-chart-modern" aria-label="Gráfico moderno de ganhos">
    <div class="finance-chart-summary">
      <div>
        <span>Total</span>
        <strong><?= formatarMoeda($ganhoTotal) ?></strong>
      </div>
      <div>
        <span>Média</span>
        <strong><?= formatarMoeda(count($valores) ? $ganhoTotal / count($valores) : 0) ?></strong>
      </div>
    </div>

    <div class="finance-svg-wrap">
      <svg class="finance-svg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" role="img" aria-label="<?= htmlspecialchars($textoGrafico) ?>">
        <defs>
          <linearGradient id="financeAreaGradient" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#2aa99d" stop-opacity="0.28"/>
            <stop offset="100%" stop-color="#2aa99d" stop-opacity="0.02"/>
          </linearGradient>
          <filter id="financeShadow" x="-20%" y="-20%" width="140%" height="140%">
            <feDropShadow dx="0" dy="4" stdDeviation="5" flood-opacity="0.12"/>
          </filter>
        </defs>

        <?php for ($g = 0; $g <= 4; $g++):
          $gy = $padT + ($plotH * $g / 4);
          $gv = $escalaMax * (1 - $g / 4);
        ?>
          <line x1="<?= $padL ?>" y1="<?= number_format($gy,2,'.','') ?>" x2="<?= $padL + $plotW ?>" y2="<?= number_format($gy,2,'.','') ?>" class="finance-grid-line"/>
          <text x="<?= $padL - 12 ?>" y="<?= number_format($gy + 4,2,'.','') ?>" text-anchor="end" class="finance-axis-value"><?= htmlspecialchars(formatarMoeda($gv)) ?></text>
        <?php endfor; ?>

        <?php if ($quantidadePontos > 0): ?>
          <polygon points="<?= htmlspecialchars($areaPontos) ?>" class="finance-area"/>
          <polyline points="<?= htmlspecialchars($linhaPontos) ?>" class="finance-line" filter="url(#financeShadow)"/>

          <?php foreach ($points as $i => $point):
            $mostrarRotulo = ($i % $passoRotulo === 0) || $i === count($points) - 1;
          ?>
            <g class="finance-point-group">
              <circle cx="<?= number_format($point['x'],2,'.','') ?>" cy="<?= number_format($point['y'],2,'.','') ?>" r="5.5" class="finance-point"/>
              <circle cx="<?= number_format($point['x'],2,'.','') ?>" cy="<?= number_format($point['y'],2,'.','') ?>" r="12" class="finance-point-hit">
                <title><?= htmlspecialchars($point['label'] . ': ' . formatarMoeda($point['valor'])) ?></title>
              </circle>
              <?php if ($mostrarRotulo): ?>
                <text x="<?= number_format($point['x'],2,'.','') ?>" y="<?= $chartH - 18 ?>" text-anchor="middle" class="finance-axis-label"><?= htmlspecialchars($point['label']) ?></text>
              <?php endif; ?>
            </g>
          <?php endforeach; ?>
        <?php endif; ?>
      </svg>
    </div>
  </div>

  <?php if ($ganhoTotal <= 0): ?>
    <div class="empty" style="padding:24px 10px 8px;">Nenhum pagamento recebido neste período para exibir no gráfico.</div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <div>
      <h2>Clientes atendidos</h2>
      <div class="panel-subtext">Clientes únicos com ordem de serviço entregue em <?= htmlspecialchars(strtolower($tituloPeriodo)) ?>.</div>
    </div>
    <div class="finance-big-number"><?= $clientesAtendidos ?></div>
  </div>

  <div class="finance-info-box">
    <strong>Como o sistema calcula:</strong>
    <span>O ganho é a soma dos pagamentos registrados como <b>Pago</b>. Um cliente é contado como atendido quando possui pelo menos uma OS marcada como <b>Entregue</b> no período selecionado.</span>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
