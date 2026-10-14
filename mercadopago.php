<?php
/**
 * Integração Mercado Pago - Pix
 * Nunca coloque o Access Token diretamente no HTML/JS.
 */

function mercadoPagoAccessToken(): string
{
    $token = defined('MP_ACCESS_TOKEN') ? trim((string)MP_ACCESS_TOKEN) : '';
    return $token;
}

function criarPagamentoPixMercadoPago(float $valor, string $descricao, string $email, string $referencia, string $notificationUrl = ''): array
{
    $token = mercadoPagoAccessToken();
    if ($token === '' || $token === 'COLOQUE_SEU_ACCESS_TOKEN_AQUI') {
        throw new RuntimeException('Mercado Pago ainda não configurado. Adicione o Access Token no arquivo config.local.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP está desativada no servidor.');
    }

    $payload = [
        'transaction_amount' => round($valor, 2),
        'description' => $descricao,
        'payment_method_id' => 'pix',
        'payer' => ['email' => $email ?: 'cliente@tefix.local'],
        'external_reference' => $referencia,
    ];
    if ($notificationUrl !== '') $payload['notification_url'] = $notificationUrl;

    $ch = curl_init('https://api.mercadopago.com/v1/payments');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-Idempotency-Key: ' . bin2hex(random_bytes(16)),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error) throw new RuntimeException('Não foi possível comunicar com o Mercado Pago.');
    $data = json_decode($body, true);
    if ($http < 200 || $http >= 300 || !is_array($data)) {
        $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? 'Erro desconhecido') : 'Resposta inválida.';
        throw new RuntimeException('Mercado Pago: ' . $message);
    }

    $tx = $data['point_of_interaction']['transaction_data'] ?? [];
    if (empty($tx['qr_code']) || empty($tx['qr_code_base64'])) {
        throw new RuntimeException('O Mercado Pago não retornou os dados do QR Code Pix.');
    }

    return [
        'id' => (string)($data['id'] ?? ''),
        'status' => (string)($data['status'] ?? 'pending'),
        'qr_code' => (string)$tx['qr_code'],
        'qr_code_base64' => (string)$tx['qr_code_base64'],
        'ticket_url' => (string)($tx['ticket_url'] ?? ''),
    ];
}

function consultarPagamentoMercadoPago(string $paymentId): array
{
    $token = mercadoPagoAccessToken();
    if ($token === '' || $token === 'COLOQUE_SEU_ACCESS_TOKEN_AQUI') throw new RuntimeException('Access Token do Mercado Pago não configurado.');
    $ch = curl_init('https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$body, true);
    if ($http < 200 || $http >= 300 || !is_array($data)) throw new RuntimeException('Não foi possível consultar o pagamento.');
    return $data;
}
