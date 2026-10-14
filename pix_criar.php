<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mercadopago.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $codigo = trim($_POST['codigo'] ?? '');
    if ($codigo === '') throw new RuntimeException('Ordem de serviço não informada.');
    $st = $pdo->prepare("SELECT os.*, c.email AS cliente_email, c.nome AS cliente_nome FROM ordens_servico os JOIN aparelhos a ON a.id=os.aparelho_id JOIN clientes c ON c.id=a.cliente_id WHERE os.codigo=? LIMIT 1");
    $st->execute([$codigo]);
    $os = $st->fetch(PDO::FETCH_ASSOC);
    if (!$os) throw new RuntimeException('Ordem de serviço não encontrada.');
    if (in_array($os['status'], ['Entregue','Cancelado'], true)) throw new RuntimeException('Esta ordem de serviço não pode receber pagamentos.');

    $cart = $_SESSION['carrinho_loja'][$codigo] ?? [];
    if (!$cart) throw new RuntimeException('Seu carrinho está vazio.');

    $itens=[]; $total=0;
    foreach ($cart as $pid=>$qtd) {
        $st=$pdo->prepare('SELECT * FROM produtos WHERE id=? AND ativo=1');
        $st->execute([(int)$pid]); $produto=$st->fetch(PDO::FETCH_ASSOC);
        if (!$produto || (int)$produto['estoque'] < (int)$qtd) throw new RuntimeException('Um dos produtos ficou sem estoque. Revise o carrinho.');
        $subtotal=(int)$qtd*(float)$produto['preco']; $total += $subtotal;
        $itens[]=['produto'=>$produto,'quantidade'=>(int)$qtd];
    }
    $total=round($total,2);
    if ($total <= 0) throw new RuntimeException('O valor do pagamento precisa ser maior que zero.');

    $referencia='TECFIX-' . $os['codigo'] . '-' . bin2hex(random_bytes(6));
    $notificationUrl = defined('MP_NOTIFICATION_URL') ? trim((string)MP_NOTIFICATION_URL) : '';
    $pag = criarPagamentoPixMercadoPago(
        $total,
        'TecFix - produtos da OS ' . $os['codigo'],
        (string)($os['cliente_email'] ?: 'cliente@tefix.local'),
        $referencia,
        $notificationUrl
    );

    $pdo->beginTransaction();
    $ins=$pdo->prepare('INSERT INTO os_produtos (ordem_id, produto_id, quantidade, valor_unitario) VALUES (?,?,?,?)');
    foreach ($itens as $item) {
        $produto=$item['produto']; $qtd=$item['quantidade'];
        $ins->execute([$os['id'],$produto['id'],$qtd,$produto['preco']]);
        $upd=$pdo->prepare('UPDATE produtos SET estoque=estoque-? WHERE id=? AND estoque>=?');
        $upd->execute([$qtd,$produto['id'],$qtd]);
        if ($upd->rowCount()!==1) throw new RuntimeException('Não foi possível atualizar o estoque.');
    }
    $insPag=$pdo->prepare("INSERT INTO pagamentos (ordem_id, valor, forma_pagamento, status, mercado_pago_id, pix_qr_code, pix_qr_base64, pix_ticket_url) VALUES (?,?, 'Pix','Pendente',?,?,?,?)");
    $insPag->execute([$os['id'],$total,$pag['id'],$pag['qr_code'],$pag['qr_code_base64'],$pag['ticket_url']]);
    $pagamentoId=$pdo->lastInsertId();
    $pdo->commit();
    $_SESSION['carrinho_loja'][$codigo]=[];

    echo json_encode(['ok'=>true,'pagamento_id'=>$pagamentoId,'mercado_pago_id'=>$pag['id'],'valor'=>$total,'qr_code'=>$pag['qr_code'],'qr_code_base64'=>$pag['qr_code_base64'],'ticket_url'=>$pag['ticket_url']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
