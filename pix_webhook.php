<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago.php';

http_response_code(200);
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$paymentId = $_GET['data.id'] ?? ($input['data']['id'] ?? '');
$type = $_GET['type'] ?? ($input['type'] ?? '');
if ($type !== 'payment' || !$paymentId) exit;

try {
    $payment = consultarPagamentoMercadoPago((string)$paymentId);
    $status = (string)($payment['status'] ?? 'pending');
    $novoStatus = match ($status) {
        'approved' => 'Pago',
        'refunded', 'charged_back', 'cancelled' => 'Estornado',
        default => 'Pendente',
    };
    $stmt = $pdo->prepare("UPDATE pagamentos SET status=?, data_pagamento=CASE WHEN ?='Pago' THEN datetime('now') ELSE data_pagamento END WHERE mercado_pago_id=?");
    $stmt->execute([$novoStatus, $novoStatus, (string)$paymentId]);
} catch (Throwable $e) {
    // Webhook deve responder 200 rapidamente; a consulta pode ser repetida pelo Mercado Pago.
}
