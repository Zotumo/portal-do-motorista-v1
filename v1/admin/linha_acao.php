<?php
// admin/linha_acao.php
// Lida com a ativação e desativação de linhas.

require_once 'auth_check.php'; // Autenticação e permissões
require_once '../db_config.php'; // Conexão com o banco

// --- Definição de Permissões (DEVE SER CONSISTENTE COM linhas_listar.php) ---
$niveis_permitidos_mudar_status_linhas = ['Supervisores', 'Gerência', 'Administrador'];

if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_mudar_status_linhas)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para alterar o status de linhas.";
    header('Location: linhas_listar.php');
    exit;
}

// --- Validação dos Parâmetros ---
$linha_id_acao = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
$acao = isset($_GET['acao']) ? trim($_GET['acao']) : null;
// $token_recebido = isset($_GET['token']) ? trim($_GET['token']) : null; // Para CSRF, se implementado

if (!$linha_id_acao || !in_array($acao, ['ativar', 'desativar']) /* || empty($token_recebido) */ ) {
    $_SESSION['admin_error_message'] = "Ação inválida ou parâmetros ausentes para alterar status da linha.";
    header('Location: linhas_listar.php');
    exit;
}

// --- Validação CSRF Token (Exemplo simples, idealmente usar tokens de sessão) ---
/*
if (!isset($_SESSION['csrf_token_' . $acao . '_' . $linha_id_acao]) || 
    $_SESSION['csrf_token_' . $acao . '_' . $linha_id_acao] !== $token_recebido) {
    $_SESSION['admin_error_message'] = "Falha na verificação de segurança (token inválido). Ação cancelada.";
    header('Location: linhas_listar.php');
    exit;
}
unset($_SESSION['csrf_token_' . $acao . '_' . $linha_id_acao]); // Usar o token apenas uma vez
*/


// --- Preparar para Redirecionamento ---
$redirect_query_string_linha_acao = '';
$redirect_params_linha_acao = [];
if (isset($_GET['pagina'])) $redirect_params_linha_acao['pagina'] = $_GET['pagina'];
if (isset($_GET['busca_numero'])) $redirect_params_linha_acao['busca_numero'] = $_GET['busca_numero'];
if (isset($_GET['busca_nome'])) $redirect_params_linha_acao['busca_nome'] = $_GET['busca_nome'];
if (isset($_GET['status_filtro'])) $redirect_params_linha_acao['status_filtro'] = $_GET['status_filtro'];

if (!empty($redirect_params_linha_acao)) {
    $redirect_query_string_linha_acao = '?' . http_build_query($redirect_params_linha_acao);
}
$location_redirect_linha_acao = 'linhas_listar.php' . $redirect_query_string_linha_acao;


// --- Executar Ação ---
if ($pdo) {
    $novo_status_linha = ($acao === 'ativar' ? 'ativa' : 'inativa');
    $nome_linha_feedback_acao = "ID " . $linha_id_acao; // Fallback

    try {
        // Buscar nome da linha para feedback
        $stmt_nome_linha_acao = $pdo->prepare("SELECT numero, nome FROM linhas WHERE id = :id_linha_nome_acao");
        $stmt_nome_linha_acao->bindParam(':id_linha_nome_acao', $linha_id_acao, PDO::PARAM_INT);
        $stmt_nome_linha_acao->execute();
        $info_linha_acao = $stmt_nome_linha_acao->fetch(PDO::FETCH_ASSOC);
        if ($info_linha_acao) {
            $nome_linha_feedback_acao = htmlspecialchars($info_linha_acao['numero'] . ($info_linha_acao['nome'] ? ' - ' . $info_linha_acao['nome'] : ''));
        }

        $pdo->beginTransaction();
        $sql_update_status_linha = "UPDATE linhas SET status_linha = :novo_status WHERE id = :id_linha_upd";
        $stmt_update_linha = $pdo->prepare($sql_update_status_linha);
        $stmt_update_linha->bindParam(':novo_status', $novo_status_linha, PDO::PARAM_STR);
        $stmt_update_linha->bindParam(':id_linha_upd', $linha_id_acao, PDO::PARAM_INT);

        if ($stmt_update_linha->execute()) {
            if ($stmt_update_linha->rowCount() > 0) {
                $pdo->commit();
                $_SESSION['admin_success_message'] = "Status da linha '{$nome_linha_feedback_acao}' alterado para '" . ucfirst($novo_status_linha) . "' com sucesso.";
            } else {
                $pdo->rollBack();
                $_SESSION['admin_warning_message'] = "Nenhuma alteração de status realizada para a linha '{$nome_linha_feedback_acao}' (talvez já estivesse no status desejado ou não foi encontrada).";
            }
        } else {
            $pdo->rollBack();
            $_SESSION['admin_error_message'] = "Erro ao tentar alterar o status da linha '{$nome_linha_feedback_acao}'.";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro PDO ao alterar status da linha ID {$linha_id_acao}: " . $e->getMessage());
        $_SESSION['admin_error_message'] = "Erro de banco de dados ao tentar alterar o status da linha '{$nome_linha_feedback_acao}'. Consulte o log.";
    }
} else {
    $_SESSION['admin_error_message'] = "Falha na conexão com o banco de dados. Status da linha não alterado.";
}

header("Location: " . $location_redirect_linha_acao);
exit;
?>