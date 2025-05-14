<?php
// admin/relatorios_index.php
require_once 'auth_check.php';

// Defina aqui os níveis de acesso que podem ver esta página de relatórios
$niveis_permitidos = ['CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para acessar a seção de relatórios.";
    header('Location: index.php');
    exit;
}

$page_title = 'Relatórios';
require_once 'admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
</div>

<p>Bem-vindo à seção de Relatórios. Funcionalidade em desenvolvimento.</p>
<?php
require_once 'admin_footer.php';
?>