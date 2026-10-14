<?php
// Espera que $pageTitle, $pageSub (opcional) e $activeNav estejam definidos antes do include.
$u = usuarioLogado();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Assistência Técnica') ?> — TecFix</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= strtolower(($u['cargo'] ?? '') === 'cliente' ? 'cliente-theme' : 'app-theme') ?>">
<div class="app">
  <div class="mobile-menu-overlay" aria-hidden="true"></div>
  <aside class="sidebar">
    <a href="index.php" class="brand"><span class="dot"></span>TecFix<small>ASSISTÊNCIA TÉCNICA</small></a>
    <nav class="side-nav">
      <a href="index.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="2" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.3"/></svg>
        Painel
      </a>
      <a href="os.php" class="<?= $activeNav === 'os' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2.5" y="1.5" width="11" height="13" rx="1.2" stroke="currentColor" stroke-width="1.3"/><line x1="5" y1="5" x2="11" y2="5" stroke="currentColor" stroke-width="1.3"/><line x1="5" y1="8" x2="11" y2="8" stroke="currentColor" stroke-width="1.3"/><line x1="5" y1="11" x2="9" y2="11" stroke="currentColor" stroke-width="1.3"/></svg>
        Ordens de serviço
      </a>
      <?php if (podeAbrirOS()): ?>
      <a href="nova_os.php" class="<?= $activeNav === 'nova_os' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.3"/><line x1="8" y1="5" x2="8" y2="11" stroke="currentColor" stroke-width="1.3"/><line x1="5" y1="8" x2="11" y2="8" stroke="currentColor" stroke-width="1.3"/></svg>
        Nova ordem
      </a>
      <?php endif; ?>
      <?php if (podeVerDadosPessoais()): ?>
      <a href="clientes.php" class="<?= $activeNav === 'clientes' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5.5" r="2.8" stroke="currentColor" stroke-width="1.3"/><path d="M2.5 14c0-3 2.5-4.8 5.5-4.8S13.5 11 13.5 14" stroke="currentColor" stroke-width="1.3"/></svg>
        Clientes
      </a>
      <?php endif; ?>
      <?php if (ehAdmin()): ?>
      <a href="loja.php" class="<?= $activeNav === 'loja' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2.5 6.5h11v7h-11zM3.5 6.5l1-3h7l1 3M6 9v4M10 9v4" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
        Loja / Estoque
      </a>
      <a href="financas.php" class="<?= $activeNav === 'financas' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M5 11V8M8 11V5M11 11V7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        Finanças
      </a>
      <a href="tecnicos.php" class="<?= $activeNav === 'tecnicos' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5" r="2.5" stroke="currentColor" stroke-width="1.3"/><path d="M3 14c.4-2.7 2.2-4.3 5-4.3s4.6 1.6 5 4.3" stroke="currentColor" stroke-width="1.3"/><path d="M12 3.5l.8.8M13.3 2.9l-.8.8" stroke="currentColor" stroke-width="1.3"/></svg>
        Técnicos
      </a>
      <?php endif; ?>
      <a href="logout.php" class="mobile-only-logout">
        <span aria-hidden="true">↪</span>
        Sair
      </a>
    </nav>
    <div class="side-foot">
      <div class="side-user">
        <div class="nome"><?= htmlspecialchars($u['nome'] ?? '') ?></div>
        <div class="cargo"><?= htmlspecialchars($u['cargo'] ?? '') ?></div>
      </div>
      <a href="logout.php">Sair</a>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <button type="button" class="mobile-menu-button" aria-label="Abrir menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <div>
        <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
        <?php if (!empty($pageSub)): ?><div class="sub"><?= htmlspecialchars($pageSub) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="content">
