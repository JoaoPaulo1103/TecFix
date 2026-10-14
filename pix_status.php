<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago.php';
header('Content-Type: application/json; charset=utf-8');
$id = trim($_GET['id'] ?? '');
if ($id === '') { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Pagamento não informado']); exit; }
try {
    $st=$pdo->prepare('SELECT * FROM pagamentos WHERE mercado_pago_id=? LIMIT 1');
    $st->execute([$id]); $pag=$st->fetch(PDO::FETCH_ASSOC);
    if (!$pag) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Pagamento não encontrado']); exit; }
    $remote=consultarPagamentoMercadoPago($id);
    $status=(string)($remote['status'] ?? 'pending');
    $novo = $status==='approved'?'Pago':(in_array($status,['refunded','charged_back','cancelled'],true)?'Estornado':'Pendente');
    $pdo->prepare('UPDATE pagamentos SET status=?, data_pagamento=CASE WHEN ?="Pago" THEN datetime("now") ELSE data_pagamento END WHERE mercado_pago_id=?')->execute([$novo,$novo,$id]);
    echo json_encode(['ok'=>true,'status'=>$novo,'mercado_pago_status'=>$status]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); }
