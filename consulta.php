<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mercadopago.php';

$codigo = trim($_GET['codigo'] ?? $_POST['codigo'] ?? '');
$os = null;
$naoEncontrada = false;
$erroLoja = '';
$sucessoLoja = '';
$abrirCarrinhoInicial = false;

function carregarOS(PDO $pdo, string $codigo): ?array {
    if ($codigo === '') return null;
    $stmt = $pdo->prepare("SELECT os.*, a.tipo, a.marca, a.modelo, c.nome AS cliente_nome FROM ordens_servico os JOIN aparelhos a ON a.id = os.aparelho_id JOIN clientes c ON c.id = a.cliente_id WHERE os.codigo = ?");
    $stmt->execute([$codigo]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function carregarDadosPortal(PDO $pdo, array $os): array {
    $historicoStmt = $pdo->prepare("SELECT * FROM ordens_status_historico WHERE ordem_id = ? ORDER BY criado_em DESC");
    $historicoStmt->execute([$os['id']]);
    $historico = $historicoStmt->fetchAll(PDO::FETCH_ASSOC);

    $produtos = $pdo->query("SELECT * FROM produtos WHERE ativo = 1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $produtosOSStmt = $pdo->prepare("SELECT op.*, p.nome, p.descricao, p.imagem FROM os_produtos op JOIN produtos p ON p.id = op.produto_id WHERE op.ordem_id = ? ORDER BY op.criado_em DESC");
    $produtosOSStmt->execute([$os['id']]);
    $produtosOS = $produtosOSStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalProdutos = totalProdutosOS($pdo, (int)$os['id']);
    $totalOS = valorTotalOS($pdo, (int)$os['id']);
    $totalPago = totalPago($pdo, (int)$os['id']);
    $saldo = max($totalOS - $totalPago, 0);
    return compact('historico','produtos','produtosOS','totalProdutos','totalOS','totalPago','saldo');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if (in_array($acao, ['carrinho_adicionar','carrinho_remover','carrinho_atualizar','carrinho_limpar','carrinho_orcamento','carrinho_pagar'], true)) {
        $codigoPost = trim($_POST['codigo'] ?? '');
        $osPost = carregarOS($pdo, $codigoPost);
        if (!$osPost) {
            $erroLoja = 'Ordem de serviço não encontrada.';
        } elseif (in_array($osPost['status'], ['Entregue','Cancelado'], true)) {
            $erroLoja = 'A loja não pode alterar uma ordem finalizada.';
        } else {
            $_SESSION['carrinho_loja'][$codigoPost] = $_SESSION['carrinho_loja'][$codigoPost] ?? [];
            $cart =& $_SESSION['carrinho_loja'][$codigoPost];
            try {
                if ($acao === 'carrinho_adicionar') {
                    $produtoId = (int)($_POST['produto_id'] ?? 0);
                    $qtd = max(1, min(20, (int)($_POST['quantidade'] ?? 1)));
                    $st = $pdo->prepare("SELECT * FROM produtos WHERE id=? AND ativo=1"); $st->execute([$produtoId]); $produto=$st->fetch(PDO::FETCH_ASSOC);
                    if (!$produto) throw new RuntimeException('Produto não encontrado ou indisponível.');
                    $atual = (int)($cart[$produtoId] ?? 0);
                    if ($atual + $qtd > (int)$produto['estoque']) throw new RuntimeException('Quantidade maior que o estoque disponível.');
                    $cart[$produtoId] = $atual + $qtd;
                    $sucessoLoja = 'Produto adicionado ao carrinho.';
                    $abrirCarrinhoInicial = true;
                } elseif ($acao === 'carrinho_remover') {
                    $produtoId=(int)($_POST['produto_id']??0); unset($cart[$produtoId]); $sucessoLoja='Item removido do carrinho.';
                } elseif ($acao === 'carrinho_atualizar') {
                    foreach (($_POST['quantidades'] ?? []) as $pid=>$q) {
                        $pid=(int)$pid; $q=max(0,min(20,(int)$q));
                        $st=$pdo->prepare("SELECT estoque FROM produtos WHERE id=? AND ativo=1"); $st->execute([$pid]); $stock=(int)$st->fetchColumn();
                        if ($q<=0) unset($cart[$pid]); else $cart[$pid]=min($q,$stock);
                    }
                    $sucessoLoja='Carrinho atualizado.';
                } elseif ($acao === 'carrinho_limpar') {
                    $cart=[]; $sucessoLoja='Carrinho limpo.';
                } elseif (in_array($acao, ['carrinho_orcamento','carrinho_pagar'], true)) {
                    if (!$cart) throw new RuntimeException('Seu carrinho está vazio.');
                    $forma = trim($_POST['forma_pagamento'] ?? 'Pix');
                    if ($acao === 'carrinho_pagar' && !in_array($forma, ['Pix','Cartão de Crédito','Cartão de Débito'], true)) {
                        throw new RuntimeException('Selecione uma forma de pagamento.');
                    }
                    $pdo->beginTransaction();
                    $ins=$pdo->prepare("INSERT INTO os_produtos (ordem_id, produto_id, quantidade, valor_unitario) VALUES (?,?,?,?)");
                    $totalCompra = 0;
                    foreach ($cart as $pid=>$qtd) {
                        $st=$pdo->prepare("SELECT * FROM produtos WHERE id=? AND ativo=1"); $st->execute([(int)$pid]); $produto=$st->fetch(PDO::FETCH_ASSOC);
                        if (!$produto || (int)$produto['estoque'] < (int)$qtd) throw new RuntimeException('Um dos produtos ficou sem estoque. Revise o carrinho.');
                        $ins->execute([$osPost['id'], $produto['id'], $qtd, $produto['preco']]);
                        $upd=$pdo->prepare("UPDATE produtos SET estoque=estoque-? WHERE id=? AND estoque>=?"); $upd->execute([$qtd,$produto['id'],$qtd]);
                        if ($upd->rowCount()!==1) throw new RuntimeException('Não foi possível atualizar o estoque.');
                        $totalCompra += $qtd * (float)$produto['preco'];
                    }
                    if ($acao === 'carrinho_pagar') {
                        $pdo->prepare("INSERT INTO pagamentos (ordem_id, valor, forma_pagamento, status) VALUES (?,?,?, 'Pago')")
                            ->execute([$osPost['id'], $totalCompra, $forma]);
                        $sucessoLoja='Pagamento fictício aprovado! Os produtos foram adicionados à OS e o valor foi marcado como pago.';
                    } else {
                        $sucessoLoja='Produtos adicionados ao orçamento. O pagamento ficará pendente para a retirada do celular.';
                    }
                    $pdo->commit(); $cart=[];
                }
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $erroLoja=$e->getMessage(); }
            unset($cart);
        }
        $codigo=$codigoPost;
        if ($abrirCarrinhoInicial) {
            $produtoRetorno = (int)($_POST['produto_id'] ?? 0);
            header('Location: consulta.php?codigo=' . urlencode($codigo) . '&aba=loja&produto=' . $produtoRetorno . '&carrinho=aberto');
            exit;
        }
    }
}

function montarCarrinho(PDO $pdo, string $codigo): array {
    $ids = $_SESSION['carrinho_loja'][$codigo] ?? [];
    $itens=[]; $total=0;
    foreach ($ids as $id=>$qtd) {
        $st=$pdo->prepare("SELECT * FROM produtos WHERE id=? AND ativo=1"); $st->execute([(int)$id]); $p=$st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['estoque']<=0) continue;
        $q=min((int)$qtd,(int)$p['estoque']); if ($q<=0) continue;
        $p['quantidade_carrinho']=$q; $p['subtotal']=$q*(float)$p['preco']; $itens[]=$p; $total += $p['subtotal'];
    }
    return [$itens,$total];
}

if ($codigo !== '' && !$os) {
    $os = carregarOS($pdo, $codigo);
    $naoEncontrada = !$os;
}

if ($os) $portal = carregarDadosPortal($pdo, $os);
$sequenciaStatus = ['Recebido','Em análise','Aguardando aprovação','Em conserto','Pronto','Entregue'];
$posAtual = $os ? array_search($os['status'], $sequenciaStatus, true) : -1;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Consultar ordem de serviço — TecFix</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.portal-layout{display:flex;min-height:100vh;background:#f7f7f8}
.portal-sidebar{position:fixed;left:0;top:0;bottom:0;width:250px;background:#fff;border-right:1px solid #e5e7eb;padding:22px 14px;z-index:20;box-sizing:border-box}
.portal-brand{padding:4px 10px 20px;border-bottom:1px solid #ececec;margin-bottom:14px}.portal-brand h3{margin:0;font-size:17px;color:#202123}.portal-brand p{margin:5px 0 0;color:#6b7280;font-size:12px;line-height:1.4}
.portal-nav{display:flex;flex-direction:column;gap:4px}.portal-nav button{width:100%;border:0;background:transparent;color:#4b5563;border-radius:8px;padding:11px 12px;text-align:left;font-size:14px;font-weight:600;cursor:pointer}.portal-nav button:hover{background:#f3f4f6}.portal-nav button.active{background:#eaf5ff;color:#1f3a8a}
.portal-side-summary{margin:20px 8px 0;padding:14px;border:1px solid #ececec;border-radius:10px;background:#fafafa}.portal-side-summary .code{font-weight:700;font-size:13px}.portal-side-summary .device{font-size:12px;color:#6b7280;margin-top:5px;line-height:1.4}
.portal-main{margin-left:250px;min-height:100vh;flex:1;padding:42px 36px;box-sizing:border-box}.portal-content-card{max-width:1040px;margin:0 auto}.portal-panel{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.03)}
.portal-panel h2{margin:0 0 6px;color:#202123}.portal-panel>.sub{margin-bottom:22px}.portal-product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px}.portal-product-card{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column}.portal-product-image{height:180px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden}.portal-product-image img{width:100%;height:100%;object-fit:cover}.portal-product-image .no-image{color:#9ca3af;font-size:13px}.portal-product-body{padding:14px;display:flex;flex-direction:column;flex:1}.portal-product-body h4{margin:0 0 5px;font-size:15px;color:#202123}.portal-product-body p{margin:0;color:#6b7280;font-size:12px;line-height:1.45}.portal-product-price{font-size:18px;font-weight:750;margin:12px 0 8px;color:#202123}.portal-stock{font-size:12px;color:#6b7280}.portal-buy{display:flex;gap:8px;align-items:end;margin-top:auto;padding-top:12px}.portal-buy .field{margin:0}.portal-buy input{width:64px}.portal-buy button{flex:1}
.portal-card{border:1px solid #e5e5e5;border-radius:10px;padding:14px;margin-bottom:10px;background:#fff}.portal-total{background:#202123;color:#fff;border-radius:12px;padding:16px;margin:18px 0}.portal-total small{opacity:.72}.portal-total strong{display:block;font-size:24px;margin:4px 0}.payment-pending{background:#fff7e6;color:#8a5a00;border:1px solid #f1d79a;border-radius:9px;padding:12px 14px;font-size:13px;margin:12px 0}.portal-added-product{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #eee}.portal-added-product:last-child{border-bottom:0}.portal-added-thumb{width:44px;height:44px;border-radius:7px;background:#f3f4f6;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af;flex:0 0 44px}.portal-added-thumb img{width:100%;height:100%;object-fit:cover}
.portal-mobile-title{display:none}.portal-section{display:none}.portal-section.active{display:block}.portal-status-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.portal-alert-space{min-height:1px}
@media(max-width:760px){.portal-layout{display:block}.portal-sidebar{position:relative;width:100%;height:auto;border-right:0;border-bottom:1px solid #e5e7eb;padding:14px}.portal-brand{padding-bottom:12px;margin-bottom:8px}.portal-nav{flex-direction:row}.portal-nav button{text-align:center}.portal-side-summary{display:none}.portal-main{margin-left:0;padding:18px}.portal-panel{padding:18px}.portal-product-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.portal-product-image{height:145px}}
@media(max-width:470px){.portal-product-grid{grid-template-columns:1fr}.portal-product-image{height:190px}}

.cart-checkout-options{display:flex;flex-direction:column;gap:10px;margin-top:8px}.checkout-option{border:1px solid #e7ebe9;border-radius:14px;padding:13px;background:#fafcfb}.checkout-option>div{display:flex;flex-direction:column;gap:3px;margin-bottom:10px}.checkout-option strong{font-size:13px;color:#202522}.checkout-option span{font-size:11px;color:#7b8784}.cart-budget{width:100%;border:1px solid #16866e;color:#16866e;background:#fff;border-radius:10px;padding:11px;font-weight:800;cursor:pointer}.cart-budget:hover{background:#eef8f5}.checkout-now{background:#f7fbf9}.now-pay-form{display:flex;gap:8px}.now-pay-form select{flex:0 0 145px;border:1px solid #dfe6e3;border-radius:10px;background:#fff;padding:10px;font-size:12px}.now-pay-form .cart-pay{flex:1;margin:0}.detail-buy{background:#fbfcfc;border:1px solid #e7ebe9;border-radius:16px;padding:14px}.detail-buy::before,.detail-buy::after{content:none!important;display:none!important}.detail-buy{position:static;padding:16px}.detail-buy-label{display:block;position:static;clear:both;margin:0 0 2px!important;line-height:1.35}.detail-cart-btn{white-space:nowrap}
.payment-overlay{position:fixed;inset:0;background:rgba(15,23,42,.42);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:.2s;z-index:80}.payment-overlay.open{opacity:1;pointer-events:auto}.payment-modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;z-index:90;transition:.2s}.payment-modal.open{opacity:1;pointer-events:auto}.payment-modal-card{width:min(760px,100%);max-height:min(90vh,720px);overflow:auto;background:#fff;border:1px solid #e6ebe8;border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.18);padding:24px}.payment-modal-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.payment-modal-head h3{margin:5px 0 6px;font-size:23px;color:#202522}.payment-modal-head p{margin:0;color:#74807c;font-size:13px}.payment-methods{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:22px 0 18px}.payment-method{border:1px solid #e3e9e6;background:#fafcfb;border-radius:14px;padding:13px;text-align:left;cursor:pointer;display:grid;grid-template-columns:34px 1fr;column-gap:10px;align-items:center}.payment-method span{grid-row:span 2;font-size:24px}.payment-method strong{font-size:14px}.payment-method small{color:#7c8784;font-size:11px}.payment-method.active{border-color:#168a80;background:#f0fbf8;box-shadow:0 0 0 2px rgba(22,138,128,.08)}.payment-pane{display:none}.payment-pane.active{display:block}.pix-layout{display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:center}.pix-qr-wrap{border:1px solid #e5ebe8;border-radius:16px;padding:14px;text-align:center;background:#fff}.pix-qr-wrap img{width:190px;height:190px;display:block;margin:auto}.pix-qr-wrap span{display:block;margin-top:8px;font-size:10px;color:#8a9592}.pay-total{display:flex;justify-content:space-between;align-items:center;padding:13px 15px;background:#f6faf8;border-radius:12px;margin-bottom:16px}.pay-total span{font-size:12px;color:#71807b}.pay-total strong{font-size:20px;color:#167f73}.pix-info label{display:block;font-size:12px;font-weight:700;margin-bottom:6px}.copy-row{display:flex;gap:8px}.copy-row input{min-width:0;flex:1;border:1px solid #dfe7e4;border-radius:10px;padding:11px;font-size:11px;color:#52605b;background:#f8faf9}.copy-row button{border:0;border-radius:10px;background:#167f73;color:#fff;font-weight:800;padding:0 16px;cursor:pointer}.copy-feedback{height:18px;font-size:11px;color:#168a80;margin:7px 0}.confirm-payment{width:100%;border:0;border-radius:11px;background:#168a80;color:#fff;padding:13px;font-weight:800;cursor:pointer}.confirm-payment:hover{filter:brightness(.96)}.card-sim-banner{padding:11px 13px;border-radius:11px;background:#f7f9f8;color:#65716d;font-size:11px;margin-bottom:16px}.card-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.card-form-grid label{font-size:12px;font-weight:700}.card-form-grid input{display:block;width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #dfe7e4;border-radius:10px;padding:12px;font-size:13px;outline:none}.card-form-grid input:focus{border-color:#168a80;box-shadow:0 0 0 3px rgba(22,138,128,.08)}.card-type-row{display:flex;gap:8px;margin:14px 0}.card-type{border:1px solid #dfe7e4;background:#fff;border-radius:9px;padding:9px 14px;font-weight:700;color:#56615e;cursor:pointer}.card-type.active{border-color:#168a80;color:#167f73;background:#f0fbf8}@media(max-width:700px){.pix-layout{grid-template-columns:1fr}.pix-qr-wrap{max-width:220px;margin:auto}.payment-modal-card{padding:18px}.card-form-grid{grid-template-columns:1fr}.payment-methods{grid-template-columns:1fr}}

/* ===== Portal do cliente: paleta alegre e microinterações ===== */
.portal-layout{
  background:
    radial-gradient(circle at 10% 8%,rgba(121,201,168,.16),transparent 25%),
    radial-gradient(circle at 92% 15%,rgba(91,169,230,.10),transparent 23%),
    linear-gradient(135deg,#f5f8ff,#fff8fc 52%,#f3fbf8);
}
.portal-sidebar{background:linear-gradient(180deg,#fff 0%,#faf9ff 55%,#f5fbff 100%);border-right-color:#e5e1f5;box-shadow:8px 0 30px rgba(78,72,130,.06)}
.portal-brand h3{color:#273451}.portal-brand p{color:#77819a}
.portal-nav button{transition:transform .2s ease,background .2s ease,color .2s ease,box-shadow .2s ease;position:relative;overflow:hidden}
.portal-nav button:hover{background:#eaf5ff;color:#347db7;transform:translateX(3px)}
.portal-nav button.active{background:linear-gradient(135deg,#e7f7ef,#e7f3ff);color:#347db7;box-shadow:0 7px 18px rgba(61,150,116,.12)}
.portal-nav button:active{transform:scale(.96)}
.portal-side-summary{background:linear-gradient(135deg,#f7f3ff,#eef8ff);border-color:#d7e8e4}
.portal-main{animation:portalIn .5s ease both}
.portal-panel{border-color:#dcebe7;box-shadow:0 12px 34px rgba(74,78,126,.075);transition:box-shadow .25s ease,border-color .25s ease}
.portal-panel:hover{box-shadow:0 16px 40px rgba(74,78,126,.10);border-color:#d9d3f0}
.portal-card{border-color:#e5e2f3;background:linear-gradient(135deg,#fff,#fbfaff);transition:transform .2s ease,box-shadow .2s ease}
.portal-card:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(88,77,160,.08)}
.portal-total{background:linear-gradient(135deg,#263a67 0%,#3b3564 100%);box-shadow:0 12px 28px rgba(52,66,105,.16)}
.portal-total strong{color:#fff}
.portal-product-card{position:relative;border-color:#dcebe7;box-shadow:0 7px 22px rgba(74,78,126,.06);transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease;overflow:hidden}
.portal-product-card::after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(120deg,transparent 30%,rgba(255,255,255,.6) 50%,transparent 70%);transform:translateX(-120%);transition:transform .65s ease}
.portal-product-card:hover{transform:translateY(-6px) rotate(-.25deg);box-shadow:0 18px 36px rgba(61,150,116,.15);border-color:#b9dfcf}
.portal-product-card:hover::after{transform:translateX(120%)}
.portal-product-card:active{transform:translateY(-1px) scale(.985)}
.portal-product-image{background:linear-gradient(135deg,#eaf8f1,#eef8ff,#f4f9ff)}
.portal-product-image img{transition:transform .45s ease,filter .45s ease}.portal-product-card:hover .portal-product-image img{transform:scale(1.07);filter:saturate(1.12)}
.portal-product-price{color:#5d55c5}.product-see{color:#178b72;transition:transform .2s ease}.portal-product-card:hover .product-see{transform:translateX(4px)}
.portal-nav button:active,.cart-button:active,.qty-control button:active,.cart-close:active,.cart-update:active,.cart-budget:active,.cart-pay:active,.confirm-payment:active,.payment-method:active,.card-type:active{transform:scale(.95)}
.cart-button{background:linear-gradient(135deg,#fff,#effaf5);border-color:#d7e8e4;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}.cart-button:hover{transform:translateY(-3px);box-shadow:0 11px 26px rgba(61,150,116,.15);border-color:#acd9c7}.cart-icon{background:#e7f7ef;color:#3d9674}.cart-count{background:linear-gradient(135deg,#5ba9e6,#79c9a8,#347db7)}
.detail-buy{background:linear-gradient(135deg,#fff,#f3fbf7);border-color:#d7e9e2;box-shadow:0 10px 26px rgba(74,78,126,.07)}
.detail-cart-btn{background:linear-gradient(135deg,#5ba9e6,#79c9a8,#347db7);border-color:transparent;box-shadow:0 8px 20px rgba(121,201,168,.18);transition:transform .2s ease,box-shadow .2s ease,filter .2s ease}.detail-cart-btn:hover{filter:saturate(1.08);box-shadow:0 13px 28px rgba(117,103,217,.25)}
.step.current .dot{box-shadow:0 0 0 5px rgba(121,201,168,.14),0 0 18px rgba(91,169,230,.22);animation:portalPulse 1.8s ease-in-out infinite}.step.done .dot{background:linear-gradient(135deg,#79c9a8,#54b9c6);border-color:#79c9a8}.tl-dot{background:linear-gradient(135deg,#5ba9e6,#79c9a8);box-shadow:0 0 0 4px rgba(91,169,230,.12)}
.portal-ripple{position:absolute;border-radius:50%;pointer-events:none;background:rgba(255,255,255,.55);transform:scale(0);animation:portalRipple .55s ease-out forwards}
@keyframes portalRipple{to{transform:scale(3.2);opacity:0}}
@keyframes portalIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes portalPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}
@media(prefers-reduced-motion:reduce){.portal-ripple{display:none!important}.portal-layout *{animation:none!important;transition:none!important}}
/* FINAL PALETTE OVERRIDE: verde pastel + azul, sem roxo */
.portal-layout{background:radial-gradient(circle at 10% 8%,rgba(121,201,168,.16),transparent 25%),radial-gradient(circle at 92% 15%,rgba(91,169,230,.14),transparent 23%),linear-gradient(135deg,#f4fbf8,#f5faff 52%,#fbfaf5)}
.portal-sidebar{background:linear-gradient(180deg,#fff 0%,#f7fcfa 55%,#f3f9ff 100%);border-right-color:#dcebe7}
.portal-nav button:hover{background:#eaf5ff;color:#347db7}.portal-nav button.active{background:linear-gradient(135deg,#e7f7ef,#eaf5ff);color:#347db7;box-shadow:0 7px 18px rgba(61,150,116,.12)}
.portal-product-image{background:linear-gradient(135deg,#eaf8f1,#edf7ff,#f8fbf5)}
.portal-product-price{color:#3d9674}.product-see{color:#3d9674}
.cart-button{background:linear-gradient(135deg,#fff,#effaf5)!important;border-color:#d7e8e4!important}.cart-icon{background:#e7f7ef!important;color:#3d9674!important}.cart-count{background:linear-gradient(135deg,#79c9a8,#5ba9e6)!important}
.detail-cart-btn{background:linear-gradient(135deg,#79c9a8,#5ba9e6)!important}.detail-stock{background:linear-gradient(135deg,#e5f7ee,#eaf5ff);color:#3d9674}
.step.current .dot{box-shadow:0 0 0 5px rgba(121,201,168,.16),0 0 18px rgba(91,169,230,.25)}.step.done .dot{background:linear-gradient(135deg,#79c9a8,#5ba9e6);border-color:#79c9a8}.tl-dot{background:linear-gradient(135deg,#79c9a8,#5ba9e6);box-shadow:0 0 0 4px rgba(121,201,168,.13)}
</style>
</head>
<body>
<?php if ($os): ?>
<div class="portal-layout">
  <aside class="portal-sidebar">
    <div class="portal-brand"><h3>Área do cliente</h3><p>Acompanhe seu aparelho e acesse a loja.</p></div>
    <nav class="portal-nav" aria-label="Navegação do cliente">
      <button id="portalNavOs" class="active" type="button" onclick="abrirPortal('os')">Ordem de serviço</button>
      <button id="portalNavLoja" type="button" onclick="abrirPortal('loja')">Loja</button>
    </nav>
    <div class="portal-side-summary"><div class="code"><?= htmlspecialchars($os['codigo']) ?></div><div class="device"><?= htmlspecialchars($os['tipo'].' · '.$os['marca'].' '.$os['modelo']) ?></div><div style="margin-top:9px"><span class="badge <?= classeStatus($os['status']) ?>"><?= htmlspecialchars($os['status']) ?></span></div></div>
  </aside>

  <main class="portal-main">
    <div class="portal-content-card">
      <section id="portalOs" class="portal-section active">
        <div class="portal-panel">
          <div class="portal-status-head"><div><h2>Ordem de serviço</h2><div class="sub">Acompanhe o andamento do conserto do seu aparelho.</div></div><span class="os-tag"><?= htmlspecialchars($os['codigo']) ?></span></div>
          <div class="portal-card"><span class="badge <?= classeStatus($os['status']) ?>"><?= htmlspecialchars($os['status']) ?></span><p style="margin:14px 0 3px;font-weight:700"><?= htmlspecialchars($os['tipo'].' — '.$os['marca'].' '.$os['modelo']) ?></p><p style="margin:0;color:var(--muted);font-size:12px">Aberta em <?= formatarData($os['data_abertura']) ?><?php if ($os['data_previsao']): ?> · previsão <?= formatarData($os['data_previsao']) ?><?php endif; ?></p></div>
          <?php if ($os['status'] !== 'Cancelado'): ?><div class="stepper" style="margin:24px 0 30px"><?php foreach ($sequenciaStatus as $i => $status): ?><div class="step <?= $i < $posAtual ? 'done' : ($i === $posAtual ? 'current' : '') ?>"><div class="dot"><div class="line"></div></div><div class="lbl"><?= htmlspecialchars($status) ?></div></div><?php endforeach; ?></div><?php endif; ?>
          <div class="portal-total"><small>Total atual da OS</small><strong><?= formatarMoeda($portal['totalOS']) ?></strong><small>Pago: <?= formatarMoeda($portal['totalPago']) ?> · Saldo: <?= formatarMoeda($portal['saldo']) ?></small></div>
          <?php if ($portal['saldo'] > 0): ?><div class="payment-pending"><strong>Pagamento pendente:</strong> <?= formatarMoeda($portal['saldo']) ?>. Esse valor deverá ser pago antes da retirada do aparelho.</div><?php else: ?><div class="alert alert-ok">Pagamento em dia. Não há valor pendente no momento.</div><?php endif; ?>
          <h3 style="margin:24px 0 12px">Histórico do conserto</h3>
          <div class="timeline"><?php foreach ($portal['historico'] as $h): ?><div class="tl-item"><div class="tl-dot"></div><div class="tl-body"><b><?= htmlspecialchars($h['status']) ?></b><div class="when"><?= formatarData($h['criado_em'], 'd/m/Y H:i') ?></div><?php if (!empty($h['observacao'])): ?><div class="obs"><?= htmlspecialchars($h['observacao']) ?></div><?php endif; ?></div></div><?php endforeach; ?></div>
        </div>
      </section>

      <section id="portalLoja" class="portal-section">
        <?php [$carrinho,$totalCarrinho] = montarCarrinho($pdo, $os['codigo']); $produtoDetalhe=(int)($_GET['produto']??0); $detalhe=null; if($produtoDetalhe){$st=$pdo->prepare("SELECT * FROM produtos WHERE id=? AND ativo=1");$st->execute([$produtoDetalhe]);$detalhe=$st->fetch(PDO::FETCH_ASSOC)?:null;} ?>
        <div class="portal-panel">
          <div class="store-header"><div><span class="store-kicker">LOJA TECFIX</span><h2>Produtos para o seu aparelho</h2><div class="sub">Escolha um produto, veja os detalhes e adicione ao carrinho.</div></div><button class="cart-button" type="button" onclick="abrirCarrinho()" aria-label="Abrir carrinho"><span class="cart-icon">🛒</span><span class="cart-label">Carrinho</span><span class="cart-count"><?= array_sum(array_map(fn($i)=>(int)$i['quantidade_carrinho'],$carrinho)) ?></span></button></div>
          <?php if ($sucessoLoja): ?><div class="alert alert-ok"><?= htmlspecialchars($sucessoLoja) ?></div><?php endif; ?>
          <?php if ($erroLoja): ?><div class="alert alert-error"><?= htmlspecialchars($erroLoja) ?></div><?php endif; ?>
          <div class="portal-total"><small>Valor atual da OS com produtos</small><strong><?= formatarMoeda($portal['totalOS']) ?></strong><small>Produtos já adicionados: <?= formatarMoeda($portal['totalProdutos']) ?></small></div>
          <?php if ($portal['saldo'] > 0 && $portal['totalProdutos'] > 0): ?><div class="payment-pending"><strong>Pagamento pendente.</strong> Há saldo de produtos/conserto para quitar na retirada.</div><?php endif; ?>

          <?php if ($detalhe): ?>
            <a class="back-store" href="consulta.php?codigo=<?= urlencode($os['codigo']) ?>&aba=loja">← Voltar para a loja</a>
            <div class="product-detail">
              <div class="product-detail-image"><?php if(!empty($detalhe['imagem'])):?><img src="produto_imagem.php?id=<?=(int)$detalhe['id']?>" alt="<?=htmlspecialchars($detalhe['nome'])?>"><?php else:?><span>Sem foto</span><?php endif;?></div>
              <div class="product-detail-info"><span class="product-label">PRODUTO</span><h2><?=htmlspecialchars($detalhe['nome'])?></h2><div class="detail-price"><?=formatarMoeda((float)$detalhe['preco'])?></div><p><?=nl2br(htmlspecialchars($detalhe['descricao']?:'Produto disponível na loja.'))?></p><div class="detail-stock <?= (int)$detalhe['estoque'] > 0 ? '' : 'out' ?>"><?= (int)$detalhe['estoque']>0 ? '✓ '.(int)$detalhe['estoque'].' disponíveis' : 'Sem estoque' ?></div>
                <?php $lojaBloqueada = in_array($os['status'], ['Entregue','Cancelado'], true); ?>
                <div class="detail-buy-wrap">
                  <?php if ($lojaBloqueada): ?>
                    <div class="detail-locked">Esta ordem de serviço já foi finalizada. A compra de novos produtos está indisponível.</div>
                  <?php elseif ((int)$detalhe['estoque'] <= 0): ?>
                    <div class="detail-locked">Produto temporariamente sem estoque.</div>
                  <?php else: ?>
                    <form method="post" action="consulta.php" class="detail-buy" id="formAdicionarProduto">
                      <input type="hidden" name="acao" value="carrinho_adicionar">
                      <input type="hidden" name="codigo" value="<?=htmlspecialchars($os['codigo'], ENT_QUOTES)?>">
                      <input type="hidden" name="produto_id" value="<?=(int)$detalhe['id']?>">
                      <div class="detail-buy-label">Quantidade</div>
                      <div class="qty-control">
                        <button type="button" onclick="alterarQtd(-1)" aria-label="Diminuir quantidade">−</button>
                        <input id="produtoQtd" type="number" name="quantidade" value="1" min="1" max="<?=min(20,(int)$detalhe['estoque'])?>" inputmode="numeric" aria-label="Quantidade">
                        <button type="button" onclick="alterarQtd(1)" aria-label="Aumentar quantidade">+</button>
                      </div>
                      <button class="btn btn-accent detail-cart-btn" type="submit">
                        <span aria-hidden="true">🛒</span> Adicionar ao carrinho
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php else: ?>
            <h3 style="margin:24px 0 14px">Vitrine</h3>
            <div class="portal-product-grid">
            <?php foreach ($portal['produtos'] as $produto): ?><a class="portal-product-card product-link" href="consulta.php?codigo=<?=urlencode($os['codigo'])?>&aba=loja&produto=<?=(int)$produto['id']?>"><div class="portal-product-image"><?php if(!empty($produto['imagem'])):?><img src="produto_imagem.php?id=<?=(int)$produto['id']?>" alt="<?=htmlspecialchars($produto['nome'])?>" loading="lazy"><?php else:?><span class="no-image">Sem foto</span><?php endif;?></div><div class="portal-product-body"><div class="product-meta"><span>Produto</span><span><?= (int)$produto['estoque']>0 ? 'Disponível' : 'Esgotado' ?></span></div><h4><?=htmlspecialchars($produto['nome'])?></h4><p><?=htmlspecialchars($produto['descricao']?:'Produto disponível na loja.')?></p><div class="portal-product-footer"><div><div class="portal-product-price"><?=formatarMoeda((float)$produto['preco'])?></div><div class="portal-stock"><?= (int)$produto['estoque']>0 ? (int)$produto['estoque'].' em estoque' : 'Sem estoque' ?></div></div><span class="product-see">Ver produto →</span></div></div></a><?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div id="cartOverlay" class="cart-overlay" onclick="fecharCarrinho()"></div>
        <aside id="cartPanel" class="cart-panel" aria-hidden="true" aria-label="Carrinho de compras">
          <div class="cart-head"><div><span class="store-kicker">SEU PEDIDO</span><strong>Carrinho</strong><span><?=array_sum(array_map(fn($i)=>(int)$i['quantidade_carrinho'],$carrinho))?> item(ns)</span></div><button type="button" class="cart-close" onclick="fecharCarrinho()">×</button></div>
          <div class="cart-body">
          <?php if(!$carrinho): ?><div class="cart-empty"><div class="cart-empty-icon">🛒</div><strong>Seu carrinho está vazio</strong><span>Escolha um produto para começar.</span><a href="consulta.php?codigo=<?=urlencode($os['codigo'])?>&aba=loja" onclick="fecharCarrinho()">Ver produtos</a></div>
          <?php else: ?>
            <form method="post" class="cart-form"><input type="hidden" name="acao" value="carrinho_atualizar"><input type="hidden" name="codigo" value="<?=htmlspecialchars($os['codigo'])?>">
            <?php foreach($carrinho as $item): ?><div class="cart-item"><div class="cart-thumb"><?php if(!empty($item['imagem'])):?><img src="produto_imagem.php?id=<?=(int)$item['id']?>" alt="<?=htmlspecialchars($item['nome'])?>"><?php else:?><span>Sem foto</span><?php endif;?></div><div class="cart-item-main"><strong><?=htmlspecialchars($item['nome'])?></strong><span class="cart-unit"><?=formatarMoeda((float)$item['preco'])?> cada</span><div class="cart-item-row"><div class="mini-qty"><button type="button" onclick="ajustarCarrinho(this,-1)">−</button><input type="number" name="quantidades[<?=(int)$item['id']?>]" value="<?=(int)$item['quantidade_carrinho']?>" min="0" max="<?=min(20,(int)$item['estoque'])?>"><button type="button" onclick="ajustarCarrinho(this,1)">+</button></div><strong><?=formatarMoeda($item['subtotal'])?></strong></div><button class="remove-link" type="submit" formaction="consulta.php?codigo=<?=urlencode($os['codigo'])?>&aba=loja" name="acao" value="carrinho_remover" formmethod="post" onclick="this.form.querySelector('[name=produto_id]').value='<?= (int)$item['id'] ?>'">Remover</button><input type="hidden" name="produto_id" value="<?=(int)$item['id']?>"></div></div><?php endforeach; ?>
            <button class="cart-update" type="submit">Atualizar quantidades</button></form>
            <div class="cart-summary"><div><span>Subtotal</span><strong><?=formatarMoeda($totalCarrinho)?></strong></div><small>Escolha como deseja pagar os produtos. O pagamento é apenas uma simulação.</small></div>
            <div class="cart-checkout-options">
              <div class="checkout-option"><div><strong>Adicionar ao orçamento</strong><span>Pague na retirada do celular</span></div><form method="post"><input type="hidden" name="acao" value="carrinho_orcamento"><input type="hidden" name="codigo" value="<?=htmlspecialchars($os['codigo'])?>"><button class="cart-budget" type="submit">Adicionar à OS</button></form></div>
              <div class="checkout-option checkout-now"><div><strong>Efetuar pagamento agora</strong><span>Pagamento fictício e imediato</span></div><button class="cart-pay" type="button" onclick="abrirPagamento()"><span>Escolher forma de pagamento</span><strong><?=formatarMoeda($totalCarrinho)?></strong></button></div>
            </div>
            <form method="post"><input type="hidden" name="acao" value="carrinho_limpar"><input type="hidden" name="codigo" value="<?=htmlspecialchars($os['codigo'])?>"><button class="cart-clear" type="submit">Limpar carrinho</button></form>
          <?php endif; ?>
          </div>
        </aside>
<div id="paymentOverlay" class="payment-overlay" onclick="fecharPagamento()"></div>
<section id="paymentModal" class="payment-modal" aria-hidden="true">
<div class="payment-modal-card">
<div class="payment-modal-head"><div><span class="store-kicker">PAGAMENTO FICTÍCIO</span><h3>Como você deseja pagar?</h3><p>Nenhum pagamento real será realizado.</p></div><button type="button" class="cart-close" onclick="fecharPagamento()">×</button></div>
<div class="payment-methods"><button type="button" class="payment-method active" data-method="pix" onclick="selecionarPagamento('pix')"><span>▦</span><strong>Pix</strong><small>QR Code + copia e cola</small></button><button type="button" class="payment-method" data-method="cartao" onclick="selecionarPagamento('cartao')"><span>▣</span><strong>Cartão</strong><small>Crédito ou débito</small></button></div>
<div id="paymentPix" class="payment-pane active">
  <div id="pixLoading" class="card-sim-banner">Clique em “Gerar Pix” para criar uma cobrança real no Mercado Pago.</div>
  <div id="pixError" class="alert alert-error" style="display:none"></div>
  <div id="pixResult" class="pix-layout" style="display:none">
    <div class="pix-qr-wrap"><img id="pixQrImage" src="" alt="QR Code Pix"><span>Abra o app do banco e escaneie o QR Code.</span></div>
    <div class="pix-info">
      <div class="pay-total"><span>Total</span><strong id="pixTotal"><?=formatarMoeda($totalCarrinho)?></strong></div>
      <label for="pixCode">Pix copia e cola</label>
      <div class="copy-row"><input id="pixCode" type="text" readonly value=""><button type="button" onclick="copiarPix()">Copiar</button></div>
      <p id="copyFeedback" class="copy-feedback"></p>
      <div id="pixStatus" class="card-sim-banner">Aguardando pagamento...</div>
      <a id="pixTicketUrl" href="#" target="_blank" rel="noopener" class="confirm-payment" style="display:block;text-align:center;text-decoration:none">Abrir Pix</a>
    </div>
  </div>
  <button id="gerarPixBtn" type="button" class="confirm-payment" onclick="gerarPix()">Gerar Pix</button>
</div>
<div id="paymentCard" class="payment-pane"><div class="card-sim-banner">💳 Demonstração: os dados do cartão não são enviados nem armazenados.</div><div class="card-form-grid"><label>Número do cartão<input id="cardNumber" inputmode="numeric" maxlength="19" placeholder="0000 0000 0000 0000" autocomplete="off"></label><label>Nome no cartão<input id="cardName" placeholder="Nome completo" autocomplete="off"></label><label>Validade<input id="cardExpiry" inputmode="numeric" maxlength="5" placeholder="MM/AA" autocomplete="off"></label><label>CVV<input id="cardCvv" type="password" inputmode="numeric" maxlength="4" placeholder="123" autocomplete="off"></label></div><div class="card-type-row"><button type="button" class="card-type active" data-card="Cartão de Crédito" onclick="selecionarTipoCartao(this)">Crédito</button><button type="button" class="card-type" data-card="Cartão de Débito" onclick="selecionarTipoCartao(this)">Débito</button></div><div class="pay-total"><span>Total</span><strong><?=formatarMoeda($totalCarrinho)?></strong></div><button type="button" class="confirm-payment" onclick="confirmarCartao()">Confirmar pagamento fictício</button></div>
<form id="paymentConfirmForm" method="post" style="display:none"><input type="hidden" name="acao" value="carrinho_pagar"><input type="hidden" name="codigo" value="<?=htmlspecialchars($os['codigo'], ENT_QUOTES)?>"><input id="paymentMethodValue" type="hidden" name="forma_pagamento" value="Pix"></form>
</div></section>
      </section>
    </div>
  </main>
</div>
<script>
function abrirCarrinho(){document.getElementById('cartPanel')?.classList.add('open');document.getElementById('cartOverlay')?.classList.add('open');document.getElementById('cartPanel')?.setAttribute('aria-hidden','false');document.body.classList.add('cart-open')}
function fecharCarrinho(){document.getElementById('cartPanel')?.classList.remove('open');document.getElementById('cartOverlay')?.classList.remove('open');document.getElementById('cartPanel')?.setAttribute('aria-hidden','true');document.body.classList.remove('cart-open')}
function alterarQtd(delta){const input=document.getElementById('produtoQtd');if(!input)return;const min=Number(input.min)||1,max=Number(input.max)||20;input.value=Math.max(min,Math.min(max,(Number(input.value)||1)+delta))}
function ajustarCarrinho(btn,delta){const input=btn.parentElement.querySelector('input');if(!input)return;const min=Number(input.min)||0,max=Number(input.max)||20;input.value=Math.max(min,Math.min(max,(Number(input.value)||0)+delta))}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){fecharCarrinho();fecharPagamento()}});
function abrirPagamento(){document.getElementById('paymentModal')?.classList.add('open');document.getElementById('paymentOverlay')?.classList.add('open');document.getElementById('paymentModal')?.setAttribute('aria-hidden','false');selecionarPagamento('pix'); prepararPix()}
let pixPollTimer=null;
function prepararPix(){const r=document.getElementById('pixResult'),e=document.getElementById('pixError'),b=document.getElementById('gerarPixBtn');if(r)r.style.display='none';if(e){e.style.display='none';e.textContent=''}if(b){b.style.display='block';b.disabled=false;b.textContent='Gerar Pix'}const l=document.getElementById('pixLoading');if(l)l.textContent='O QR Code será gerado pelo Mercado Pago depois que você clicar em “Gerar Pix”.';}
async function gerarPix(){const b=document.getElementById('gerarPixBtn'),e=document.getElementById('pixError'),l=document.getElementById('pixLoading');b.disabled=true;b.textContent='Gerando...';e.style.display='none';try{const fd=new FormData();fd.append('codigo',<?=json_encode($os['codigo'])?>);const res=await fetch('pix_criar.php',{method:'POST',body:fd});const data=await res.json();if(!data.ok)throw new Error(data.message||'Não foi possível gerar o Pix.');document.getElementById('pixQrImage').src='data:image/png;base64,'+data.qr_code_base64;document.getElementById('pixCode').value=data.qr_code;document.getElementById('pixTotal').textContent=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(data.valor);const link=document.getElementById('pixTicketUrl');if(data.ticket_url){link.href=data.ticket_url;link.style.display='block'}else link.style.display='none';document.getElementById('pixResult').style.display='grid';b.style.display='none';l.textContent='Pix criado. Aguardando confirmação do Mercado Pago...';iniciarConsultaPix(data.mercado_pago_id)}catch(err){e.textContent=err.message;e.style.display='block';b.disabled=false;b.textContent='Tentar novamente'}}
function iniciarConsultaPix(id){if(pixPollTimer)clearInterval(pixPollTimer);const status=document.getElementById('pixStatus');const verificar=async()=>{try{const r=await fetch('pix_status.php?id='+encodeURIComponent(id),{cache:'no-store'});const d=await r.json();if(d.ok){status.textContent=d.status==='Pago'?'Pagamento aprovado!':'Aguardando pagamento...';if(d.status==='Pago'){status.style.color='#168a80';clearInterval(pixPollTimer)}}}catch(e){}};verificar();pixPollTimer=setInterval(verificar,5000)}
function fecharPagamento(){document.getElementById('paymentModal')?.classList.remove('open');document.getElementById('paymentOverlay')?.classList.remove('open');document.getElementById('paymentModal')?.setAttribute('aria-hidden','true')}
function selecionarPagamento(m){document.querySelectorAll('.payment-method').forEach(b=>b.classList.toggle('active',b.dataset.method===m));document.getElementById('paymentPix')?.classList.toggle('active',m==='pix');document.getElementById('paymentCard')?.classList.toggle('active',m==='cartao')}
let tipoCartao='Cartão de Crédito';
function selecionarTipoCartao(b){document.querySelectorAll('.card-type').forEach(x=>x.classList.remove('active'));b.classList.add('active');tipoCartao=b.dataset.card}
function copiarPix(){const i=document.getElementById('pixCode'),f=document.getElementById('copyFeedback');if(!i)return;const ok=()=>{if(f){f.textContent='Código copiado!';setTimeout(()=>f.textContent='',2000)}};if(navigator.clipboard&&window.isSecureContext)navigator.clipboard.writeText(i.value).then(ok).catch(()=>{i.select();document.execCommand('copy');ok()});else{i.select();document.execCommand('copy');ok()}}
function confirmarPagamento(forma){document.getElementById('paymentMethodValue').value=forma;document.getElementById('paymentConfirmForm').submit()}
function confirmarCartao(){const n=(document.getElementById('cardNumber')?.value||'').replace(/\D/g,'');const nome=(document.getElementById('cardName')?.value||'').trim();const v=(document.getElementById('cardExpiry')?.value||'').trim();const cvv=(document.getElementById('cardCvv')?.value||'').replace(/\D/g,'');if(n.length<13||n.length>19||!nome||!/^(0[1-9]|1[0-2])\/\d{2}$/.test(v)||cvv.length<3){alert('Preencha os dados do cartão para continuar.');return}confirmarPagamento(tipoCartao)}
function abrirPortal(aba){
  const os=document.getElementById('portalOs'), loja=document.getElementById('portalLoja');
  const navOs=document.getElementById('portalNavOs'), navLoja=document.getElementById('portalNavLoja');
  const mostrarLoja=aba==='loja';
  os.classList.toggle('active', !mostrarLoja); loja.classList.toggle('active', mostrarLoja);
  navOs.classList.toggle('active', !mostrarLoja); navLoja.classList.toggle('active', mostrarLoja);
}
const params = new URLSearchParams(location.search);
const abaInicial=params.get('aba'); if(abaInicial==='loja') abrirPortal('loja');
if(params.get('carrinho')==='aberto') abrirCarrinho();
</script>
<?php endif; ?>

<?php if (!$os): ?>
<div class="center-screen">
  <div class="auth-box" style="width:460px;">
    <div class="brand"><span class="dot"></span>TecFix<small>ASSISTÊNCIA TÉCNICA</small></div>
    <h2>Consultar ordem de serviço</h2>
    <div class="sub">Digite o código informado no recibo para acompanhar o status do reparo e acessar a loja.</div>
    <form method="get" style="display:flex;gap:8px;margin-bottom:6px"><input type="text" name="codigo" placeholder="Ex: OS-2026-0001" value="<?= htmlspecialchars($codigo) ?>" style="flex:1;padding:10px 12px;border:1px solid var(--line);border-radius:6px"><button type="submit" class="btn btn-solid">Consultar</button></form>
    <?php if ($naoEncontrada): ?><div class="alert alert-error" style="margin-top:16px">Nenhuma ordem de serviço encontrada com esse código.</div><?php endif; ?>
    <div class="public-link"><a href="login.php">Sou da equipe — entrar no sistema</a></div>
  </div>
</div>
<?php endif; ?>
</body>
</html>
