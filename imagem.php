<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM os_imagens WHERE id = ?");
$stmt->execute([$id]);
$imagem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$imagem) {
    http_response_code(404);
    exit('Imagem não encontrada.');
}

$arquivo = __DIR__ . '/data/imagens/' . basename($imagem['arquivo']);
if (!is_file($arquivo)) {
    http_response_code(404);
    exit('Arquivo da imagem não encontrado.');
}

header('Content-Type: ' . $imagem['mime']);
header('Content-Length: ' . filesize($arquivo));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($imagem['nome_original'])) . '"');
header('X-Content-Type-Options: nosniff');
readfile($arquivo);
exit;
