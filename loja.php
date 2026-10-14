<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
exigirAdmin();

$erro = '';
$sucesso = '';
$dirProdutos = __DIR__ . '/data/produtos';
if (!is_dir($dirProdutos)) mkdir($dirProdutos, 0777, true);

function salvarImagemProduto(?array $arquivo, string $dir): ?string {
    if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível enviar a imagem.');
    if (($arquivo['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('A imagem deve ter no máximo 5 MB.');

    $tmp = $arquivo['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Arquivo de imagem inválido.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $extensoes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($extensoes[$mime])) throw new RuntimeException('Formato inválido. Use JPG, PNG, WEBP ou GIF.');

    $nome = bin2hex(random_bytes(16)) . '.' . $extensoes[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $nome)) throw new RuntimeException('Não foi possível salvar a imagem.');
    return $nome;
}

function apagarImagemProduto(?string $nome, string $dir): void {
    if ($nome) {
        $arquivo = $dir . '/' . basename($nome);
        if (is_file($arquivo)) @unlink($arquivo);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'criar') {
            $nome = trim($_POST['nome'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            $preco = (float)str_replace(',', '.', $_POST['preco'] ?? 0);
            $estoque = max(0, (int)($_POST['estoque'] ?? 0));
            if ($nome === '') throw new RuntimeException('Informe o nome do produto.');
            if ($preco < 0) throw new RuntimeException('O preço não pode ser negativo.');
            $imagem = salvarImagemProduto($_FILES['imagem'] ?? null, $dirProdutos);
            $pdo->prepare("INSERT INTO produtos (nome, descricao, preco, estoque, ativo, imagem) VALUES (?,?,?,?,1,?)")
                ->execute([$nome, $descricao, $preco, $estoque, $imagem]);
            $sucesso = 'Produto adicionado à loja com sucesso.';
        } elseif ($acao === 'editar') {
            $id = (int)($_POST['id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            $preco = (float)str_replace(',', '.', $_POST['preco'] ?? 0);
            $estoque = max(0, (int)($_POST['estoque'] ?? 0));
            $ativo = isset($_POST['ativo']) ? 1 : 0;
            if ($id < 1 || $nome === '') throw new RuntimeException('Dados do produto inválidos.');
            if ($preco < 0) throw new RuntimeException('O preço não pode ser negativo.');
            $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id=?"); $stmt->execute([$id]); $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual) throw new RuntimeException('Produto não encontrado.');

            $novaImagem = salvarImagemProduto($_FILES['imagem'] ?? null, $dirProdutos);
            $imagem = $atual['imagem'] ?? null;
            if ($novaImagem) { $imagem = $novaImagem; }
            if (isset($_POST['remover_imagem']) && $_POST['remover_imagem'] === '1') { $imagem = null; }

            $pdo->prepare("UPDATE produtos SET nome=?, descricao=?, preco=?, estoque=?, ativo=?, imagem=? WHERE id=?")
                ->execute([$nome, $descricao, $preco, $estoque, $ativo, $imagem, $id]);
            if ($imagem !== ($atual['imagem'] ?? null)) apagarImagemProduto($atual['imagem'] ?? null, $dirProdutos);
            if (isset($_POST['remover_imagem']) && $_POST['remover_imagem'] === '1' && $novaImagem) apagarImagemProduto($novaImagem, $dirProdutos);
            $sucesso = 'Produto atualizado.';
        } elseif ($acao === 'ajustar_estoque') {
            $id = (int)($_POST['id'] ?? 0);
            $movimento = (int)($_POST['movimento'] ?? 0);
            $stmt = $pdo->prepare("SELECT estoque, nome FROM produtos WHERE id=?");
            $stmt->execute([$id]); $produto = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$produto) throw new RuntimeException('Produto não encontrado.');
            $novo = (int)$produto['estoque'] + $movimento;
            if ($novo < 0) throw new RuntimeException('O estoque não pode ficar negativo.');
            $pdo->prepare("UPDATE produtos SET estoque=? WHERE id=?")->execute([$novo, $id]);
            $sucesso = 'Estoque de ' . $produto['nome'] . ' atualizado para ' . $novo . ' unidade(s).';
        }
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}

$produtos = $pdo->query("SELECT * FROM produtos ORDER BY ativo DESC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$ativos = array_filter($produtos, fn($p) => (int)$p['ativo'] === 1);
$totalItens = array_sum(array_map(fn($p) => (int)$p['estoque'], $produtos));
$baixoEstoque = count(array_filter($produtos, fn($p) => (int)$p['ativo'] === 1 && (int)$p['estoque'] <= 5));

$pageTitle = 'Loja';
$pageSub = 'Produtos, vitrine e controle de estoque';
$activeNav = 'loja';
require __DIR__ . '/includes/header.php';
?>

<?php if ($sucesso): ?><div class="alert alert-ok" style="margin-bottom:18px"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-error" style="margin-bottom:18px"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="cards-row">
  <div class="stat-card"><div class="num"><?= count($ativos) ?></div><div class="label">Produtos ativos</div></div>
  <div class="stat-card"><div class="num"><?= $totalItens ?></div><div class="label">Itens em estoque</div></div>
  <div class="stat-card"><div class="num"><?= $baixoEstoque ?></div><div class="label">Estoque baixo</div></div>
</div>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head"><div><h2>Adicionar produto</h2><div class="sub">Cadastre o produto, preço, estoque e uma foto para a vitrine.</div></div></div>
  <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:1.1fr 1.7fr .75fr .7fr 1.1fr auto;gap:12px;align-items:end">
    <input type="hidden" name="acao" value="criar">
    <div class="field"><label>Nome</label><input name="nome" required placeholder="Ex.: Capa premium"></div>
    <div class="field"><label>Descrição</label><input name="descricao" placeholder="Descrição curta do produto"></div>
    <div class="field"><label>Preço</label><input name="preco" type="number" min="0" step="0.01" required placeholder="0,00"></div>
    <div class="field"><label>Estoque</label><input name="estoque" type="number" min="0" value="0" required></div>
    <div class="field upload-field"><label>Foto do produto</label><label class="upload-drop"><input id="produtoImagemCriar" name="imagem" type="file" accept="image/jpeg,image/png,image/webp,image/gif"><span class="upload-icon">↑</span><span><b>Enviar foto</b><small>JPG, PNG, WEBP ou GIF · até 5 MB</small></span><img id="previewProdutoCriar" class="upload-preview" alt="Prévia"></label></div>
    <button class="btn btn-solid desktop-solid-action" type="submit">Adicionar</button>
  </form>
  <div class="sub" style="margin-top:10px">Formatos: JPG, PNG, WEBP ou GIF · máximo 5 MB.</div>
</div>

<div class="panel">
  <div class="panel-head"><div><h2>Produtos e estoque</h2><div class="sub">A foto cadastrada aparecerá como vitrine para o cliente.</div></div></div>
  <?php if (!$produtos): ?><div class="empty">Nenhum produto cadastrado.</div><?php else: ?>
  <div class="admin-products-grid">
  <?php foreach ($produtos as $p): $baixo=(int)$p['estoque']<=5 && (int)$p['ativo']; ?>
    <article class="admin-product-card">
      <div class="admin-product-image">
        <?php if (!empty($p['imagem'])): ?><img src="produto_imagem.php?id=<?= (int)$p['id'] ?>" alt="<?= htmlspecialchars($p['nome']) ?>"><?php else: ?><span>Sem foto</span><?php endif; ?>
      </div>
      <div class="admin-product-info">
        <div class="admin-product-title"><strong><?= htmlspecialchars($p['nome']) ?></strong><span class="stock-pill <?= $baixo ? 'low' : '' ?>"><?= (int)$p['estoque'] ?> em estoque</span></div>
        <div class="sub"><?= htmlspecialchars($p['descricao'] ?: 'Sem descrição') ?></div>
        <div class="admin-product-bottom"><strong><?= formatarMoeda((float)$p['preco']) ?></strong><span class="sub"><?= (int)$p['ativo'] ? 'Ativo na loja' : 'Inativo' ?></span></div>
        <div class="admin-actions">
          <form method="post"><input type="hidden" name="acao" value="ajustar_estoque"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="movimento" value="1"><button class="btn btn-line btn-sm" title="Adicionar 1 unidade">+1 estoque</button></form>
          <form method="post"><input type="hidden" name="acao" value="ajustar_estoque"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="movimento" value="-1"><button class="btn btn-line btn-sm" title="Retirar 1 unidade">−1 estoque</button></form>
          <button class="btn btn-line btn-sm" type="button" onclick="document.getElementById('edit-<?= (int)$p['id'] ?>').showModal()">Editar</button>
        </div>
      </div>
    </article>
    <dialog id="edit-<?= (int)$p['id'] ?>" class="product-dialog">
      <form method="post" enctype="multipart/form-data"><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <h3 style="margin-top:0">Editar produto</h3>
        <div class="field"><label>Nome</label><input name="nome" required value="<?= htmlspecialchars($p['nome']) ?>"></div>
        <div class="field"><label>Descrição</label><textarea name="descricao" rows="3"><?= htmlspecialchars($p['descricao'] ?? '') ?></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="field"><label>Preço</label><input name="preco" type="number" min="0" step="0.01" value="<?= htmlspecialchars($p['preco']) ?>"></div><div class="field"><label>Estoque</label><input name="estoque" type="number" min="0" value="<?= (int)$p['estoque'] ?>"></div></div>
        <div class="field upload-field"><label>Nova foto</label><label class="upload-drop compact"><input class="produtoImagemEditar" name="imagem" type="file" accept="image/jpeg,image/png,image/webp,image/gif"><span class="upload-icon">↑</span><span><b>Trocar foto</b><small>Selecione uma nova imagem</small></span></label></div>
        <?php if (!empty($p['imagem'])): ?><label class="check-line"><input type="checkbox" name="remover_imagem" value="1"> Remover foto atual</label><?php endif; ?>
        <label class="check-line"><input type="checkbox" name="ativo" <?= (int)$p['ativo'] ? 'checked' : '' ?>> Produto disponível para clientes</label>
        <div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" class="btn btn-line" onclick="this.closest('dialog').close()">Cancelar</button><button class="btn btn-solid">Salvar</button></div>
      </form>
    </dialog>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function previewFile(input, img){ const file=input.files&&input.files[0]; if(!file)return; const r=new FileReader(); r.onload=e=>{img.src=e.target.result;img.style.display='block'}; r.readAsDataURL(file); }
const cri=document.getElementById('produtoImagemCriar'), prev=document.getElementById('previewProdutoCriar'); if(cri&&prev) cri.addEventListener('change',()=>previewFile(cri,prev));
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
