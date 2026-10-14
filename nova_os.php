<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();
if (!podeAbrirOS()) { http_response_code(403); die('Acesso negado.'); }

$erro = '';
$clientes = $pdo->query("SELECT id, nome, telefone, cpf FROM clientes ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modoCliente = $_POST['modo_cliente'] ?? 'existente';
    $defeito = trim($_POST['defeito_relatado'] ?? '');
    $previsao = $_POST['data_previsao'] ?? null;

    if ($defeito === '') {
        $erro = 'Descreva o defeito relatado pelo cliente.';
    }

    try {
        if (!$erro) {
            $pdo->beginTransaction();

            // ---- cliente ----
            if ($modoCliente === 'novo') {
                $nome = trim($_POST['cliente_nome'] ?? '');
                $telefone = trim($_POST['cliente_telefone'] ?? '');
                $email = trim($_POST['cliente_email'] ?? '') ?: null;
                $cpf = trim($_POST['cliente_cpf'] ?? '') ?: null;
                $endereco = trim($_POST['cliente_endereco'] ?? '') ?: null;

                if ($nome === '' || $telefone === '') {
                    throw new RuntimeException('Nome e telefone do cliente são obrigatórios.');
                }
                $pdo->prepare("INSERT INTO clientes (nome, telefone, email, cpf, endereco) VALUES (?,?,?,?,?)")
                    ->execute([$nome, $telefone, $email, $cpf, $endereco]);
                $clienteId = (int)$pdo->lastInsertId();
            } else {
                $clienteId = (int)($_POST['cliente_id'] ?? 0);
                if ($clienteId <= 0) throw new RuntimeException('Selecione um cliente.');
            }

            // ---- aparelho ----
            $tipo = trim($_POST['tipo'] ?? '');
            $marca = trim($_POST['marca'] ?? '');
            $modelo = trim($_POST['modelo'] ?? '');
            $numeroSerie = trim($_POST['numero_serie'] ?? '') ?: null;
            $cor = trim($_POST['cor'] ?? '') ?: null;
            $senhaDesbloqueio = trim($_POST['senha_desbloqueio'] ?? '') ?: null;
            $acessorios = trim($_POST['acessorios'] ?? '') ?: null;

            if ($tipo === '' || $marca === '' || $modelo === '') {
                throw new RuntimeException('Informe tipo, marca e modelo do aparelho.');
            }

            $pdo->prepare("INSERT INTO aparelhos (cliente_id, tipo, marca, modelo, numero_serie, cor, senha_desbloqueio, acessorios) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$clienteId, $tipo, $marca, $modelo, $numeroSerie, $cor, $senhaDesbloqueio, $acessorios]);
            $aparelhoId = (int)$pdo->lastInsertId();

            // ---- ordem de serviço ----
            $codigo = gerarCodigoOS($pdo);
            $usuarioId = usuarioLogado()['id'];
            $pdo->prepare("INSERT INTO ordens_servico (codigo, aparelho_id, usuario_id, status, defeito_relatado, data_previsao) VALUES (?,?,?,?,?,?)")
                ->execute([$codigo, $aparelhoId, $usuarioId, 'Recebido', $defeito, $previsao ?: null]);
            $osId = (int)$pdo->lastInsertId();

            registrarHistorico($pdo, $osId, 'Recebido', $usuarioId, 'Aparelho recebido no balcão.');

            $pdo->commit();
            header('Location: os_detalhe.php?id=' . $osId . '&criada=1');
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erro = $e->getMessage();
    }
}

$pageTitle = 'Nova ordem de serviço';
$pageSub = 'Cadastre o cliente, o aparelho e abra a OS';
$activeNav = 'nova_os';
require __DIR__ . '/includes/header.php';
?>

<?php if ($erro): ?><div class="alert alert-error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<form method="post">
  <div class="panel">
    <div class="panel-head"><h2>1. Cliente</h2></div>

    <div style="display:flex; gap:18px; margin-bottom:16px;">
      <label style="display:flex; align-items:center; gap:6px; font-size:13.5px;">
        <input type="radio" name="modo_cliente" value="existente" checked onclick="toggleCliente('existente')"> Cliente já cadastrado
      </label>
      <label style="display:flex; align-items:center; gap:6px; font-size:13.5px;">
        <input type="radio" name="modo_cliente" value="novo" onclick="toggleCliente('novo')"> Novo cliente
      </label>
    </div>

    <div id="bloco-existente" class="field">
      <label for="cliente_id">Selecionar cliente</label>
      <select name="cliente_id" id="cliente_id">
        <option value="">— selecione —</option>
        <?php foreach ($clientes as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?> — <?= htmlspecialchars($c['telefone']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint"><?= count($clientes) ?> cliente(s) cadastrado(s). Sem o cliente na lista? Marque "Novo cliente" acima.</div>
    </div>

    <div id="bloco-novo" class="form-grid" style="display:none;">
      <div class="field"><label>Nome completo</label><input type="text" name="cliente_nome"></div>
      <div class="field"><label>Telefone</label><input type="text" name="cliente_telefone" placeholder="(11) 90000-0000"></div>
      <div class="field"><label>E-mail</label><input type="email" name="cliente_email"></div>
      <div class="field"><label>CPF</label><input type="text" name="cliente_cpf" placeholder="000.000.000-00"></div>
      <div class="field full"><label>Endereço</label><input type="text" name="cliente_endereco"></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>2. Aparelho</h2></div>
    <div class="form-grid">
      <div class="field">
        <label>Tipo</label>
        <select name="tipo" required>
          <option value="Smartphone">Smartphone</option>
          <option value="Tablet">Tablet</option>
          <option value="Notebook">Notebook</option>
          <option value="Smartwatch">Smartwatch</option>
          <option value="Outro">Outro</option>
        </select>
      </div>
      <div class="field"><label>Marca</label><input type="text" name="marca" required placeholder="Samsung, Apple, Motorola..."></div>
      <div class="field"><label>Modelo</label><input type="text" name="modelo" required placeholder="Galaxy S23, iPhone 13..."></div>
      <div class="field"><label>Cor</label><input type="text" name="cor"></div>
      <div class="field"><label>Número de série / IMEI</label><input type="text" name="numero_serie"></div>
      <div class="field"><label>Senha de desbloqueio</label><input type="text" name="senha_desbloqueio" placeholder="Opcional, se necessário para o teste"></div>
      <div class="field full"><label>Acessórios entregues junto</label><input type="text" name="acessorios" placeholder="Capa, carregador, fone..."></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>3. Ordem de serviço</h2></div>
    <div class="form-grid">
      <div class="field full">
        <label>Defeito relatado pelo cliente</label>
        <textarea name="defeito_relatado" required placeholder="Descreva exatamente o que o cliente relatou..."></textarea>
      </div>
      <div class="field">
        <label>Previsão de conclusão</label>
        <input type="date" name="data_previsao">
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-accent desktop-solid-action">Abrir ordem de serviço</button>
</form>

<script>
function toggleCliente(modo){
  document.getElementById('bloco-existente').style.display = modo === 'existente' ? 'block' : 'none';
  document.getElementById('bloco-novo').style.display = modo === 'novo' ? 'grid' : 'none';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
