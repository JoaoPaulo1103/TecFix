<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();

$osId = (int)($_GET['id'] ?? 0);
$usuario = usuarioLogado();
$erro = '';
$sucesso = '';

function carregarOS(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT os.*, a.tipo, a.marca, a.modelo, a.numero_serie, a.cor, a.senha_desbloqueio, a.acessorios,
               c.id AS cliente_id, c.nome AS cliente_nome,
               c.telefone AS cliente_telefone, c.email AS cliente_email, c.cpf AS cliente_cpf, c.endereco AS cliente_endereco
        FROM ordens_servico os
        JOIN aparelhos a ON a.id = os.aparelho_id
        JOIN clientes c ON c.id = a.cliente_id
        WHERE os.id = ?
    ");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

$os = carregarOS($pdo, $osId);
if (!$os) { http_response_code(404); die('Ordem de serviço não encontrada.'); }

if (isset($_GET['criada'])) $sucesso = "Ordem {$os['codigo']} aberta com sucesso.";

// ---------------- ações POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'upload_imagem') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Apenas técnicos e administradores podem enviar imagens.');
            if (!podeEnviarImagemStatus($os['status'])) {
                throw new RuntimeException('Imagens só podem ser adicionadas quando a OS estiver em "Recebido", "Em análise" ou "Pronto".');
            }

            if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecione uma imagem válida para enviar.');
            }

            $arquivoUpload = $_FILES['imagem'];
            if ((int)$arquivoUpload['size'] > 8 * 1024 * 1024) {
                throw new RuntimeException('A imagem deve ter no máximo 8 MB.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($arquivoUpload['tmp_name']);
            if (!imagemMimePermitido($mime)) {
                throw new RuntimeException('Formato não permitido. Use JPG, PNG, WEBP ou GIF.');
            }

            $dimensoes = @getimagesize($arquivoUpload['tmp_name']);
            if ($dimensoes === false) {
                throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
            }

            $ext = extensaoPorMime($mime);
            $nomeSeguro = bin2hex(random_bytes(20)) . '.' . $ext;
            $pastaImagens = __DIR__ . '/data/imagens';
            if (!is_dir($pastaImagens) && !mkdir($pastaImagens, 0755, true)) {
                throw new RuntimeException('Não foi possível preparar a pasta de imagens.');
            }

            $destino = $pastaImagens . '/' . $nomeSeguro;
            if (!move_uploaded_file($arquivoUpload['tmp_name'], $destino)) {
                throw new RuntimeException('Não foi possível salvar a imagem.');
            }

            try {
                $pdo->prepare("INSERT INTO os_imagens (ordem_id, usuario_id, status_os, nome_original, arquivo, mime, tamanho) VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        $osId,
                        $usuario['id'],
                        $os['status'],
                        basename($arquivoUpload['name']),
                        $nomeSeguro,
                        $mime,
                        (int)$arquivoUpload['size']
                    ]);
            } catch (Exception $e) {
                @unlink($destino);
                throw $e;
            }

            $sucesso = 'Imagem adicionada à ordem de serviço.';
        } elseif ($acao === 'excluir_imagem') {
            if (!ehAdmin()) throw new RuntimeException('Apenas o administrador pode excluir imagens.');
            $imagemId = (int)($_POST['imagem_id'] ?? 0);
            $stmtImg = $pdo->prepare("SELECT * FROM os_imagens WHERE id = ? AND ordem_id = ?");
            $stmtImg->execute([$imagemId, $osId]);
            $imagem = $stmtImg->fetch(PDO::FETCH_ASSOC);
            if (!$imagem) throw new RuntimeException('Imagem não encontrada.');

            $pdo->prepare("DELETE FROM os_imagens WHERE id = ?")->execute([$imagemId]);
            @unlink(__DIR__ . '/data/imagens/' . basename($imagem['arquivo']));
            $sucesso = 'Imagem excluída.';
        } elseif ($acao === 'salvar_laudo') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Apenas técnicos podem editar o laudo.');
            $pdo->prepare("UPDATE ordens_servico SET laudo_tecnico = ? WHERE id = ?")
                ->execute([trim($_POST['laudo_tecnico'] ?? ''), $osId]);
            $sucesso = 'Laudo técnico salvo.';

        } elseif ($acao === 'add_servico') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Apenas técnicos podem lançar itens no orçamento.');
            $desc = trim($_POST['descricao'] ?? '');
            $tipo = $_POST['tipo'] ?? 'peca';
            $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
            if ($desc === '' || $valor <= 0) throw new RuntimeException('Informe a descrição e um valor válido.');

            $pdo->prepare("INSERT INTO servicos (ordem_id, descricao, tipo, valor) VALUES (?,?,?,?)")
                ->execute([$osId, $desc, $tipo, $valor]);

            // recalcula valor_orcamento como soma dos itens
            $novoTotal = totalServicos($pdo, $osId);
            $pdo->prepare("UPDATE ordens_servico SET valor_orcamento = ? WHERE id = ?")->execute([$novoTotal, $osId]);
            $sucesso = 'Item adicionado ao orçamento.';

        } elseif ($acao === 'aprovar_orcamento') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Sem permissão para aprovar orçamento.');
            $pdo->prepare("UPDATE ordens_servico SET orcamento_aprovado = 1 WHERE id = ?")->execute([$osId]);
            $sucesso = 'Orçamento marcado como aprovado pelo cliente.';

        } elseif ($acao === 'avancar_status') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Sem permissão para alterar o status.');
            [$ok, $motivo] = podeAvancar($os);
            if (!$ok) throw new RuntimeException($motivo);
            $proximo = FLUXO_STATUS[$os['status']];
            $pdo->prepare("UPDATE ordens_servico SET status = ? WHERE id = ?")->execute([$proximo, $osId]);
            registrarHistorico($pdo, $osId, $proximo, $usuario['id'], trim($_POST['observacao'] ?? ''));
            $sucesso = "Status atualizado para \"$proximo\".";

        } elseif ($acao === 'entregar') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Sem permissão para concluir a entrega.');
            [$ok, $motivo] = podeEntregar($pdo, $os);
            if (!$ok) throw new RuntimeException($motivo);
            $pdo->prepare("UPDATE ordens_servico SET status = 'Entregue', data_entrega = datetime('now'), data_conclusao = COALESCE(data_conclusao, datetime('now')) WHERE id = ?")
                ->execute([$osId]);
            registrarHistorico($pdo, $osId, 'Entregue', $usuario['id'], 'Aparelho entregue ao cliente.');
            $sucesso = 'Aparelho marcado como entregue.';

        } elseif ($acao === 'cancelar') {
            if (!podeGerenciarTecnico()) throw new RuntimeException('Sem permissão para cancelar a OS.');
            if (in_array($os['status'], ['Entregue','Cancelado'], true)) throw new RuntimeException('Esta ordem não pode mais ser cancelada.');
            $motivo = trim($_POST['observacao'] ?? 'Cancelada pela equipe.');
            $pdo->prepare("UPDATE ordens_servico SET status = 'Cancelado' WHERE id = ?")->execute([$osId]);
            registrarHistorico($pdo, $osId, 'Cancelado', $usuario['id'], $motivo);
            $sucesso = 'Ordem de serviço cancelada.';

        } elseif ($acao === 'add_pagamento') {
            $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
            $forma = $_POST['forma_pagamento'] ?? '';
            if ($valor <= 0) throw new RuntimeException('Informe um valor de pagamento válido.');
            $pdo->prepare("INSERT INTO pagamentos (ordem_id, valor, forma_pagamento, status) VALUES (?,?,?, 'Pago')")
                ->execute([$osId, $valor, $forma]);
            $sucesso = 'Pagamento registrado.';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }

    $os = carregarOS($pdo, $osId); // recarrega com dados atualizados
}

$itensServico = $pdo->prepare("SELECT * FROM servicos WHERE ordem_id = ? ORDER BY criado_em");
$itensServico->execute([$osId]);
$itensServico = $itensServico->fetchAll(PDO::FETCH_ASSOC);

$pagamentos = $pdo->prepare("SELECT * FROM pagamentos WHERE ordem_id = ? ORDER BY data_pagamento");
$pagamentos->execute([$osId]);
$pagamentos = $pagamentos->fetchAll(PDO::FETCH_ASSOC);

$historico = $pdo->prepare("
    SELECT h.*, u.nome AS usuario_nome
    FROM ordens_status_historico h
    LEFT JOIN usuarios u ON u.id = h.usuario_id
    WHERE h.ordem_id = ? ORDER BY h.criado_em DESC
");
$historico->execute([$osId]);
$historico = $historico->fetchAll(PDO::FETCH_ASSOC);

$imagensStmt = $pdo->prepare("
    SELECT i.*, u.nome AS usuario_nome
    FROM os_imagens i
    LEFT JOIN usuarios u ON u.id = i.usuario_id
    WHERE i.ordem_id = ?
    ORDER BY i.criado_em DESC
");
$imagensStmt->execute([$osId]);
$imagens = $imagensStmt->fetchAll(PDO::FETCH_ASSOC);

$totalServ = totalServicos($pdo, $osId);
$totalProdutos = totalProdutosOS($pdo, $osId);
$totalGeral = valorTotalOS($pdo, $osId);
$totalPg = totalPago($pdo, $osId);
$saldo = $totalGeral - $totalPg;
$produtosOSStmt = $pdo->prepare("SELECT op.*, p.nome, p.descricao FROM os_produtos op JOIN produtos p ON p.id = op.produto_id WHERE op.ordem_id = ? ORDER BY op.criado_em");
$produtosOSStmt->execute([$osId]);
$produtosOS = $produtosOSStmt->fetchAll(PDO::FETCH_ASSOC);

[$podeAvancarOk, $motivoBloqueio] = podeAvancar($os);
$sequenciaStatus = ['Recebido','Em análise','Aguardando aprovação','Em conserto','Pronto','Entregue'];
$posAtual = array_search($os['status'], $sequenciaStatus, true);

$pageTitle = $os['codigo'];
$pageSub = $os['cliente_nome'] . ' — ' . $os['marca'] . ' ' . $os['modelo'];
$activeNav = 'os';
require __DIR__ . '/includes/header.php';
?>

<?php if ($erro): ?><div class="alert alert-error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alert alert-ok"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <div>
      <span class="os-tag os-tag-solid"><?= htmlspecialchars($os['codigo']) ?></span>
      <span class="badge <?= classeStatus($os['status']) ?>" style="margin-left:10px;"><?= htmlspecialchars($os['status']) ?></span>
    </div>
    <?php if (!in_array($os['status'], ['Entregue','Cancelado'], true) && podeGerenciarTecnico()): ?>
      <form method="post" onsubmit="return confirm('Cancelar esta ordem de serviço?');">
        <input type="hidden" name="acao" value="cancelar">
        <input type="hidden" name="observacao" value="Cancelada pela equipe.">
        <button type="submit" class="btn btn-danger btn-sm">Cancelar OS</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($os['status'] === 'Cancelado'): ?>
    <div class="alert alert-error" style="margin-bottom:0;">Esta ordem de serviço foi cancelada.</div>
  <?php else: ?>
    <div class="stepper">
      <?php foreach ($sequenciaStatus as $i => $s): ?>
        <div class="step <?= $i < $posAtual ? 'done' : ($i === $posAtual ? 'current' : '') ?>">
          <div class="dot"><div class="line"></div></div>
          <div class="lbl"><?= htmlspecialchars($s) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (podeGerenciarTecnico() && $os['status'] !== 'Pronto'): ?>
      <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="acao" value="avancar_status">
        <input type="text" name="observacao" placeholder="Observação (opcional)" style="flex:1; min-width:200px; padding:9px 12px; border:1px solid var(--line); border-radius:6px;">
        <button type="submit" class="btn btn-accent btn-solid-flat" <?= $podeAvancarOk ? '' : 'disabled' ?>>
          Avançar para "<?= htmlspecialchars(FLUXO_STATUS[$os['status']] ?? '') ?>"
        </button>
      </form>
      <?php if (!$podeAvancarOk): ?><div class="alert alert-info" style="margin-top:12px; margin-bottom:0;"><?= htmlspecialchars($motivoBloqueio) ?></div><?php endif; ?>
    <?php elseif ($os['status'] === 'Pronto' && podeGerenciarTecnico()): ?>
      <?php [$podeEnt, $motivoEnt] = podeEntregar($pdo, $os); ?>
      <form method="post" onsubmit="return confirm('Confirmar entrega do aparelho ao cliente?');">
        <input type="hidden" name="acao" value="entregar">
        <button type="submit" class="btn btn-accent" <?= $podeEnt ? '' : 'disabled' ?>>Confirmar entrega ao cliente</button>
      </form>
      <?php if (!$podeEnt): ?><div class="alert alert-info" style="margin-top:12px; margin-bottom:0;"><?= htmlspecialchars($motivoEnt) ?></div><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="form-grid">
  <div class="panel">
    <div class="panel-head"><h2>Cliente</h2></div>
    <p style="margin:0 0 6px; font-weight:600;"><?= htmlspecialchars($os['cliente_nome']) ?></p>
    <?php if (podeVerDadosPessoais()): ?>
      <p style="margin:0 0 4px; color:var(--ink-soft); font-size:13px;">Telefone: <?= htmlspecialchars($os['cliente_telefone']) ?></p>
      <p style="margin:0 0 4px; color:var(--ink-soft); font-size:13px;">E-mail: <?= htmlspecialchars($os['cliente_email'] ?: '—') ?></p>
      <p style="margin:0 0 4px; color:var(--ink-soft); font-size:13px;">CPF: <span class="mono"><?= htmlspecialchars($os['cliente_cpf'] ?: '—') ?></span></p>
      <p style="margin:0; color:var(--ink-soft); font-size:13px;">Endereço: <?= htmlspecialchars($os['cliente_endereco'] ?: '—') ?></p>
    <?php else: ?>
      <div class="privacy-note">Dados pessoais protegidos: telefone, e-mail, CPF e endereço não são exibidos para técnicos.</div>
    <?php endif; ?>
  </div>
  <div class="panel">
    <div class="panel-head"><h2>Aparelho</h2></div>
    <p style="margin:0 0 6px; font-weight:600;"><?= htmlspecialchars($os['tipo'] . ' — ' . $os['marca'] . ' ' . $os['modelo']) ?></p>
    <p style="margin:0 0 4px; color:var(--ink-soft); font-size:13px;">Cor: <?= htmlspecialchars($os['cor'] ?: '—') ?> · Série: <span class="mono"><?= htmlspecialchars($os['numero_serie'] ?: '—') ?></span></p>
    <p style="margin:0; color:var(--ink-soft); font-size:13px;">Acessórios: <?= htmlspecialchars($os['acessorios'] ?: '—') ?></p>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Defeito relatado</h2></div>
  <p style="margin:0; color:var(--ink-soft);"><?= nl2br(htmlspecialchars($os['defeito_relatado'])) ?></p>
</div>

<div class="panel">
  <div class="panel-head">
    <div>
      <h2>Imagens da OS</h2>
      <div class="hint" style="margin-top:4px;">Permitidas nos status: Recebido, Em análise e Pronto.</div>
    </div>
  </div>

  <?php if (podeGerenciarTecnico() && podeEnviarImagemStatus($os['status']) && !in_array($os['status'], ['Entregue','Cancelado'], true)): ?>
    <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:18px;">
      <input type="hidden" name="acao" value="upload_imagem">
      <div class="field" style="flex:1; min-width:240px;">
        <label for="imagem">Adicionar imagem</label>
        <input type="file" id="imagem" name="imagem" accept="image/jpeg,image/png,image/webp,image/gif" required>
        <div class="hint">JPG, PNG, WEBP ou GIF — máximo 8 MB.</div>
      </div>
      <button type="submit" class="btn btn-accent btn-solid-flat">Enviar imagem</button>
    </form>
  <?php elseif (!podeEnviarImagemStatus($os['status'])): ?>
    <div class="alert alert-info">O envio de imagens está bloqueado neste status. Só é permitido em Recebido, Em análise e Pronto.</div>
  <?php endif; ?>

  <?php if (empty($imagens)): ?>
    <div class="empty" style="padding:24px;">Nenhuma imagem anexada.</div>
  <?php else: ?>
    <div class="image-grid">
      <?php foreach ($imagens as $img): ?>
        <div class="image-card">
          <a href="imagem.php?id=<?= (int)$img['id'] ?>" target="_blank" rel="noopener">
            <img src="imagem.php?id=<?= (int)$img['id'] ?>" alt="<?= htmlspecialchars($img['nome_original']) ?>">
          </a>
          <div class="image-meta">
            <strong><?= htmlspecialchars($img['nome_original']) ?></strong>
            <span><?= htmlspecialchars($img['status_os']) ?> · <?= formatarData($img['criado_em'], 'd/m/Y H:i') ?></span>
            <span>Enviada por <?= htmlspecialchars($img['usuario_nome'] ?: 'usuário') ?></span>
            <?php if (ehAdmin()): ?>
              <form method="post" onsubmit="return confirm('Excluir esta imagem?');">
                <input type="hidden" name="acao" value="excluir_imagem">
                <input type="hidden" name="imagem_id" value="<?= (int)$img['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h2>Laudo técnico</h2></div>
  <?php if (podeGerenciarTecnico()): ?>
    <form method="post">
      <input type="hidden" name="acao" value="salvar_laudo">
      <div class="field">
        <textarea name="laudo_tecnico" placeholder="Diagnóstico técnico do problema encontrado..."><?= htmlspecialchars($os['laudo_tecnico'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-line btn-sm" style="margin-top:10px;">Salvar laudo</button>
    </form>
  <?php else: ?>
    <p style="margin:0; color:var(--ink-soft);"><?= $os['laudo_tecnico'] ? nl2br(htmlspecialchars($os['laudo_tecnico'])) : '— ainda não preenchido —' ?></p>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Orçamento</h2>
    <?php if ($os['valor_orcamento'] > 0 && !$os['orcamento_aprovado'] && podeGerenciarTecnico() && !in_array($os['status'],['Entregue','Cancelado'],true)): ?>
      <form method="post"><input type="hidden" name="acao" value="aprovar_orcamento">
        <button type="submit" class="btn btn-accent btn-sm">Marcar como aprovado pelo cliente</button>
      </form>
    <?php elseif ($os['orcamento_aprovado']): ?>
      <span class="badge status-verde">Aprovado pelo cliente</span>
    <?php endif; ?>
  </div>

  <?php if (empty($itensServico)): ?>
    <div class="empty" style="padding:24px;">Nenhum item lançado ainda.</div>
  <?php else: ?>
    <table class="grid-table">
      <thead><tr><th>Descrição</th><th>Tipo</th><th>Valor</th></tr></thead>
      <tbody>
        <?php foreach ($itensServico as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td><?= $item['tipo'] === 'peca' ? 'Peça' : 'Mão de obra' ?></td>
            <td><?= formatarMoeda($item['valor']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="text-align:right; padding-top:14px; font-weight:700; font-family:var(--font-display); font-size:16px;">
      Serviços: <?= formatarMoeda($totalServ) ?> · Produtos: <?= formatarMoeda($totalProdutos) ?> · Total da OS: <?= formatarMoeda($totalGeral) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($produtosOS)): ?>
    <div style="margin-top:18px; padding-top:18px; border-top:1px solid var(--line);">
      <div style="font-weight:700; margin-bottom:10px;">Produtos comprados na loja</div>
      <table class="grid-table">
        <thead><tr><th>Produto</th><th>Qtd.</th><th>Valor unitário</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($produtosOS as $produto): ?>
            <tr>
              <td><?= htmlspecialchars($produto['nome']) ?></td>
              <td><?= (int)$produto['quantidade'] ?></td>
              <td><?= formatarMoeda($produto['valor_unitario']) ?></td>
              <td><?= formatarMoeda($produto['quantidade'] * $produto['valor_unitario']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (podeGerenciarTecnico() && !in_array($os['status'],['Entregue','Cancelado'],true)): ?>
    <form method="post" style="display:flex; gap:10px; align-items:end; margin-top:18px; padding-top:18px; border-top:1px solid var(--line); flex-wrap:wrap;">
      <input type="hidden" name="acao" value="add_servico">
      <div class="field" style="flex:2; min-width:180px;"><label>Descrição</label><input type="text" name="descricao" placeholder="Tela AMOLED original, troca de bateria..." required></div>
      <div class="field" style="width:150px;"><label>Tipo</label>
        <select name="tipo"><option value="peca">Peça</option><option value="mao_de_obra">Mão de obra</option></select>
      </div>
      <div class="field" style="width:140px;"><label>Valor (R$)</label><input type="text" name="valor" placeholder="0,00" required></div>
      <button type="submit" class="btn btn-line">Adicionar item</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h2>Pagamentos</h2></div>

  <?php if (empty($pagamentos)): ?>
    <div class="empty" style="padding:24px;">Nenhum pagamento registrado.</div>
  <?php else: ?>
    <table class="grid-table">
      <thead><tr><th>Data</th><th>Forma</th><th>Valor</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($pagamentos as $p): ?>
          <tr>
            <td><?= formatarData($p['data_pagamento'], 'd/m/Y H:i') ?></td>
            <td><?= htmlspecialchars($p['forma_pagamento']) ?></td>
            <td><?= formatarMoeda($p['valor']) ?></td>
            <td><span class="badge status-verde"><?= htmlspecialchars($p['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="display:flex; justify-content:space-between; padding-top:16px; margin-top: <?= empty($pagamentos)?'0':'16px' ?>; <?= empty($pagamentos)?'':'border-top:1px solid var(--line);' ?> font-size:13.5px;">
    <span>Total pago: <strong><?= formatarMoeda($totalPg) ?></strong></span>
    <span>Saldo: <strong><?= formatarMoeda(max($saldo,0)) ?></strong></span>
  </div>

  <?php if (!in_array($os['status'],['Cancelado'],true)): ?>
    <form method="post" style="display:flex; gap:10px; align-items:end; margin-top:18px; padding-top:18px; border-top:1px solid var(--line); flex-wrap:wrap;">
      <input type="hidden" name="acao" value="add_pagamento">
      <div class="field" style="width:150px;"><label>Valor (R$)</label><input type="text" name="valor" placeholder="0,00" required></div>
      <div class="field" style="width:200px;"><label>Forma de pagamento</label>
        <select name="forma_pagamento">
          <option>Pix</option><option>Dinheiro</option><option>Cartão de Crédito</option><option>Cartão de Débito</option>
        </select>
      </div>
      <button type="submit" class="btn btn-line">Registrar pagamento</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h2>Histórico</h2></div>
  <div class="timeline">
    <?php foreach ($historico as $h): ?>
      <div class="tl-item">
        <div class="tl-dot"></div>
        <div class="tl-body">
          <b><?= htmlspecialchars($h['status']) ?></b>
          <div class="when"><?= formatarData($h['criado_em'], 'd/m/Y H:i') ?> <?= $h['usuario_nome'] ? '· ' . htmlspecialchars($h['usuario_nome']) : '' ?></div>
          <?php if ($h['observacao']): ?><div class="obs"><?= htmlspecialchars($h['observacao']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
