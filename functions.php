<?php
/**
 * functions.php — regras de negócio centralizadas.
 * Manter a lógica de status/permissões aqui evita que cada página
 * reimplemente (e possivelmente burle) as regras do fluxo.
 */

// Ordem oficial do fluxo. É a única fonte de verdade sobre "próximo status".
const FLUXO_STATUS = [
    'Recebido'              => 'Em análise',
    'Em análise'            => 'Aguardando aprovação',
    'Aguardando aprovação'  => 'Em conserto',
    'Em conserto'           => 'Pronto',
    'Pronto'                => 'Entregue',
];

const STATUS_CORES = [
    'Recebido'             => 'cinza',
    'Em análise'           => 'azul',
    'Aguardando aprovação' => 'ambar',
    'Em conserto'          => 'roxo',
    'Pronto'               => 'verde',
    'Entregue'             => 'verde-escuro',
    'Cancelado'            => 'vermelho',
];

function gerarCodigoOS(PDO $pdo): string
{
    $ano = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordens_servico WHERE codigo LIKE ?");
    $stmt->execute(["OS-$ano-%"]);
    $seq = (int)$stmt->fetchColumn() + 1;
    return sprintf('OS-%s-%04d', $ano, $seq);
}

function registrarHistorico(PDO $pdo, int $ordemId, string $status, ?int $usuarioId, string $observacao = ''): void
{
    $pdo->prepare("INSERT INTO ordens_status_historico (ordem_id, status, usuario_id, observacao) VALUES (?,?,?,?)")
        ->execute([$ordemId, $status, $usuarioId, $observacao]);
}

/**
 * Valida se a OS pode avançar para o próximo status do fluxo.
 * Retorna [bool $permitido, string $motivo].
 * Esta é a regra de negócio central do sistema.
 */
function podeAvancar(array $os): array
{
    $atual = $os['status'];

    if ($atual === 'Entregue' || $atual === 'Cancelado') {
        return [false, 'Esta ordem de serviço já foi finalizada.'];
    }

    if (!isset(FLUXO_STATUS[$atual])) {
        return [false, 'Status atual inválido.'];
    }

    $proximo = FLUXO_STATUS[$atual];

    // Regra: não pode entrar em conserto sem orçamento aprovado pelo cliente.
    if ($proximo === 'Em conserto' && (int)$os['orcamento_aprovado'] !== 1) {
        return [false, 'É necessário registrar o orçamento e a aprovação do cliente antes de iniciar o conserto.'];
    }

    // Regra: não pode marcar como pronto sem laudo técnico preenchido.
    if ($proximo === 'Pronto' && trim((string)$os['laudo_tecnico']) === '') {
        return [false, 'Preencha o laudo técnico antes de marcar a ordem como pronta.'];
    }

    return [true, ''];
}

/**
 * Regra: só libera "Entregue" se o total pago cobrir o valor orçado.
 */
function podeEntregar(PDO $pdo, array $os): array
{
    if ($os['status'] !== 'Pronto') {
        return [false, 'A ordem precisa estar com status "Pronto" antes de ser entregue.'];
    }
    $totalPago = totalPago($pdo, (int)$os['id']);
    $valorTotal = valorTotalOS($pdo, (int)$os['id']);

    if ($valorTotal > 0 && $totalPago < $valorTotal) {
        $falta = number_format($valorTotal - $totalPago, 2, ',', '.');
        return [false, "Pagamento incompleto. Faltam R$ $falta para liberar a entrega."];
    }
    return [true, ''];
}

function totalPago(PDO $pdo, int $ordemId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM pagamentos WHERE ordem_id = ? AND status = 'Pago'");
    $stmt->execute([$ordemId]);
    return (float)$stmt->fetchColumn();
}

function totalServicos(PDO $pdo, int $ordemId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM servicos WHERE ordem_id = ?");
    $stmt->execute([$ordemId]);
    return (float)$stmt->fetchColumn();
}

function totalProdutosOS(PDO $pdo, int $ordemId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantidade * valor_unitario),0) FROM os_produtos WHERE ordem_id = ?");
    $stmt->execute([$ordemId]);
    return (float)$stmt->fetchColumn();
}

function valorTotalOS(PDO $pdo, int $ordemId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(valor_orcamento,0) FROM ordens_servico WHERE id = ?");
    $stmt->execute([$ordemId]);
    return (float)$stmt->fetchColumn() + totalProdutosOS($pdo, $ordemId);
}

// ---------------- Autenticação / permissões ----------------

function usuarioLogado(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function exigirLogin(): void
{
    if (!usuarioLogado()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Regra de permissão: apenas técnico e admin podem alterar status,
 * laudo técnico e orçamento. Atendente cadastra clientes/aparelhos,
 * abre OS e registra pagamentos, mas não decide questões técnicas.
 */
function podeGerenciarTecnico(): bool
{
    $u = usuarioLogado();
    return $u && in_array($u['cargo'], ['admin', 'tecnico'], true);
}


function ehAdmin(): bool
{
    $u = usuarioLogado();
    return $u && $u['cargo'] === 'admin';
}

function ehTecnico(): bool
{
    $u = usuarioLogado();
    return $u && $u['cargo'] === 'tecnico';
}

function podeVerDadosPessoais(): bool
{
    $u = usuarioLogado();
    return $u && in_array($u['cargo'], ['admin', 'atendente'], true);
}

/**
 * Permissão específica para abertura de novas ordens de serviço.
 * Técnicos também podem abrir uma OS, sem ganhar acesso administrativo.
 */
function podeAbrirOS(): bool
{
    $u = usuarioLogado();
    return $u && in_array($u['cargo'], ['admin', 'atendente', 'tecnico'], true);
}

function exigirAdmin(): void
{
    if (!ehAdmin()) {
        http_response_code(403);
        die('Acesso negado. Apenas administradores podem acessar esta área.');
    }
}

function podeEnviarImagemStatus(string $status): bool
{
    return in_array($status, ['Recebido', 'Em análise', 'Pronto'], true);
}

function imagemMimePermitido(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

function extensaoPorMime(string $mime): ?string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    return $map[$mime] ?? null;
}

function formatarMoeda(?float $valor): string
{
    if ($valor === null) return '—';
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function formatarData(?string $data, string $formato = 'd/m/Y'): string
{
    if (!$data) return '—';
    $ts = strtotime($data);
    return $ts ? date($formato, $ts) : '—';
}

function classeStatus(string $status): string
{
    $mapa = STATUS_CORES;
    return 'status-' . ($mapa[$status] ?? 'cinza');
}
