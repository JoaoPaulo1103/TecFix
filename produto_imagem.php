<?php
require_once __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ?");
$stmt->execute([$id]);
$produto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$produto || empty($produto['imagem'])) {
    http_response_code(404);
    exit('Imagem não encontrada.');
}

$arquivo = __DIR__ . '/data/produtos/' . basename($produto['imagem']);
if (!is_file($arquivo)) {
    http_response_code(404);
    exit('Arquivo da imagem não encontrado.');
}

$mime = function_exists('mime_content_type') ? mime_content_type($arquivo) : 'application/octet-stream';
$permitidos = ['image/jpeg','image/png','image/webp','image/gif'];
if (!in_array($mime, $permitidos, true)) {
    http_response_code(415);
    exit('Formato de imagem não permitido.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($arquivo));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($arquivo);
exit;
