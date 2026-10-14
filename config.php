<?php
/**
 * config.php
 * Conexão com o banco (SQLite embutido — zero configuração) e criação
 * automática do schema + dados de exemplo na primeira execução.
 *
 * Para usar MySQL em produção, troque o bloco de conexão abaixo pelo
 * DSN mysql:host=...;dbname=... e use o arquivo schema.sql incluso.
 */

date_default_timezone_set('America/Sao_Paulo');
session_start();

// Credenciais do Mercado Pago ficam fora do código público.
$mpLocal = __DIR__ . '/config.local.php';
if (is_file($mpLocal)) { require_once $mpLocal; }
if (!defined('MP_ACCESS_TOKEN')) define('MP_ACCESS_TOKEN', '');
if (!defined('MP_NOTIFICATION_URL')) define('MP_NOTIFICATION_URL', '');

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

define('DB_PATH', $dataDir . '/assistencia.sqlite');
$novoBanco = !file_exists(DB_PATH);

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    die('Erro ao conectar ao banco de dados: ' . htmlspecialchars($e->getMessage()));
}

if ($novoBanco) {
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schema);

    // ---- seed: usuários ----
    $senhaAdmin = password_hash('admin123', PASSWORD_DEFAULT);
    $senhaTec   = password_hash('tecnico123', PASSWORD_DEFAULT);
    $senhaAtd   = password_hash('atendente123', PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo) VALUES (?,?,?,?)")
        ->execute(['Administrador', 'admin@assistencia.com', $senhaAdmin, 'admin']);
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo) VALUES (?,?,?,?)")
        ->execute(['Carlos Souza', 'tecnico@assistencia.com', $senhaTec, 'tecnico']);
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo) VALUES (?,?,?,?)")
        ->execute(['Marina Alves', 'atendente@assistencia.com', $senhaAtd, 'atendente']);

    // ---- seed: cliente + aparelho + OS de exemplo ----
    $pdo->prepare("INSERT INTO clientes (nome, telefone, email, cpf, endereco) VALUES (?,?,?,?,?)")
        ->execute(['João Pereira', '(11) 98888-1234', 'joao.pereira@email.com', '111.222.333-44', 'Rua das Flores, 120 — São Paulo/SP']);
    $clienteId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO aparelhos (cliente_id, tipo, marca, modelo, numero_serie, cor, senha_desbloqueio, acessorios) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$clienteId, 'Smartphone', 'Samsung', 'Galaxy S23', 'SN-88291A', 'Preto', '2468', 'Capa e película']);
    $aparelhoId = $pdo->lastInsertId();

    require_once __DIR__ . '/functions.php';
    $codigo = gerarCodigoOS($pdo);
    $pdo->prepare("INSERT INTO ordens_servico (codigo, aparelho_id, usuario_id, status, defeito_relatado, data_abertura, data_previsao) VALUES (?,?,?,?,?,datetime('now'),date('now','+3 day'))")
        ->execute([$codigo, $aparelhoId, 3, 'Recebido', 'Tela trincada e não liga com carregador original.']);
    $osId = $pdo->lastInsertId();

    registrarHistorico($pdo, $osId, 'Recebido', 3, 'Aparelho recebido no balcão.');
}


// Migração leve para pagamentos Mercado Pago.
$pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ordem_id INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
    valor REAL NOT NULL,
    forma_pagamento TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'Pendente',
    data_pagamento TEXT NOT NULL DEFAULT (datetime('now'))
)");
$colsPag = $pdo->query("PRAGMA table_info(pagamentos)")->fetchAll(PDO::FETCH_ASSOC);
$nomesPag = array_column($colsPag, 'name');
foreach ([
    'mercado_pago_id' => "ALTER TABLE pagamentos ADD COLUMN mercado_pago_id TEXT",
    'pix_qr_code' => "ALTER TABLE pagamentos ADD COLUMN pix_qr_code TEXT",
    'pix_qr_base64' => "ALTER TABLE pagamentos ADD COLUMN pix_qr_base64 TEXT",
    'pix_ticket_url' => "ALTER TABLE pagamentos ADD COLUMN pix_ticket_url TEXT",
] as $col => $sql) {
    if (!in_array($col, $nomesPag, true)) $pdo->exec($sql);
}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pagamentos_mp_id ON pagamentos(mercado_pago_id)");

// Migração leve para instalações já existentes: catálogo da loja e produtos vinculados às OS.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS produtos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        descricao TEXT,
        preco REAL NOT NULL,
        estoque INTEGER NOT NULL DEFAULT 0,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL DEFAULT (datetime('now'))
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS os_produtos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ordem_id INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
        produto_id INTEGER NOT NULL REFERENCES produtos(id) ON DELETE RESTRICT,
        quantidade INTEGER NOT NULL DEFAULT 1 CHECK (quantidade > 0),
        valor_unitario REAL NOT NULL,
        criado_em TEXT NOT NULL DEFAULT (datetime('now'))
    )
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_produtos_ativo ON produtos(ativo)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_os_produtos_ordem ON os_produtos(ordem_id)");

// Migração: imagens dos produtos da loja.
$colunasProdutos = $pdo->query("PRAGMA table_info(produtos)")->fetchAll(PDO::FETCH_ASSOC);
$temImagemProduto = false;
foreach ($colunasProdutos as $coluna) { if (($coluna['name'] ?? '') === 'imagem') { $temImagemProduto = true; break; } }
if (!$temImagemProduto) {
    $pdo->exec("ALTER TABLE produtos ADD COLUMN imagem TEXT");
}
if (!is_dir($dataDir . '/produtos')) {
    mkdir($dataDir . '/produtos', 0777, true);
}

// Migração de estoque para instalações existentes.
$colunasProdutos = $pdo->query("PRAGMA table_info(produtos)")->fetchAll(PDO::FETCH_ASSOC);
$temEstoque = false;
foreach ($colunasProdutos as $coluna) { if (($coluna['name'] ?? '') === 'estoque') { $temEstoque = true; break; } }
if (!$temEstoque) {
    $pdo->exec("ALTER TABLE produtos ADD COLUMN estoque INTEGER NOT NULL DEFAULT 0");
}


// Catálogo inicial da loja, somente quando ainda não houver produtos.
if ((int)$pdo->query("SELECT COUNT(*) FROM produtos")->fetchColumn() === 0) {
    $produtosIniciais = [
        ['Película de vidro 3D', 'Proteção para a tela do celular.', 39.90, 20],
        ['Capa anti-impacto', 'Capa reforçada para proteção contra quedas.', 59.90, 15],
        ['Cabo USB-C', 'Cabo USB-C para carregamento e dados.', 49.90, 25],
        ['Carregador rápido 20W', 'Carregador compatível com aparelhos USB-C.', 89.90, 10],
        ['Suporte veicular', 'Suporte universal para celular no carro.', 69.90, 12],
    ];
    $ins = $pdo->prepare("INSERT INTO produtos (nome, descricao, preco, estoque) VALUES (?,?,?,?)");
    foreach ($produtosIniciais as $produto) $ins->execute($produto);
}

// Migração leve para instalações já existentes: garante a tabela de imagens.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS os_imagens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ordem_id INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
        usuario_id INTEGER REFERENCES usuarios(id),
        status_os TEXT NOT NULL,
        nome_original TEXT NOT NULL,
        arquivo TEXT NOT NULL UNIQUE,
        mime TEXT NOT NULL,
        tamanho INTEGER NOT NULL,
        criado_em TEXT NOT NULL DEFAULT (datetime('now'))
    )
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_os_imagens_ordem ON os_imagens(ordem_id)");
