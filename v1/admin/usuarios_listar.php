<?php
// admin/usuarios_listar.php

require_once 'auth_check.php'; // Define $admin_nivel_acesso_logado

// Permissão para ACESSAR a lista de usuários administradores
$niveis_permitidos_ver_lista_usuarios = ['Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_ver_lista_usuarios)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para acessar a área de gerenciamento de usuários do painel.";
    header('Location: index.php');
    exit;
}

require_once '../db_config.php';
$page_title = 'Gerenciar Usuários do Painel';
require_once 'admin_header.php';

// Para paginação (opcional, mas bom para muitos usuários)
$usuarios_por_pagina = 15;
$pagina_atual = isset($_GET['pagina']) && filter_var($_GET['pagina'], FILTER_VALIDATE_INT) && $_GET['pagina'] > 0 ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_atual - 1) * $usuarios_por_pagina;
$total_usuarios = 0;
$total_paginas = 0;
$usuarios_admin = [];
$erro_busca_usuarios = false;

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <?php
        // Somente 'Administrador' pode cadastrar novos usuários do painel
        if ($admin_nivel_acesso_logado === 'Administrador'):
        ?>
        <a href="usuario_formulario.php" class="btn btn-success">
            <i class="fas fa-user-plus"></i> Adicionar Novo Usuário
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
// Feedback de ações
if (isset($_SESSION['admin_success_message'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_success_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    unset($_SESSION['admin_success_message']);
}
if (isset($_SESSION['admin_error_message'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_error_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    unset($_SESSION['admin_error_message']);
}
?>

<div class="table-responsive">
    <table class="table table-striped table-sm table-hover">
        <thead class="thead-light">
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Usuário (Login)</th>
                <th>Email</th>
                <th>Nível de Acesso</th>
                <th>Data Cadastro</th>
                <?php if ($admin_nivel_acesso_logado === 'Administrador'): ?>
                    <th style="width: 120px;">Ações</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($pdo) {
                try {
                    // Contar total de usuários para paginação
                    $stmt_count = $pdo->query("SELECT COUNT(*) FROM administradores");
                    $total_usuarios = (int)$stmt_count->fetchColumn();
                    $total_paginas = ceil($total_usuarios / $usuarios_por_pagina);
                     if ($pagina_atual > $total_paginas && $total_paginas > 0) $pagina_atual = $total_paginas;
                     if ($pagina_atual < 1) $pagina_atual = 1;
                     $offset = ($pagina_atual - 1) * $usuarios_por_pagina;

                    // Buscar usuários da página atual
                    $stmt_select = $pdo->prepare("SELECT id, nome, username, email, nivel_acesso, data_cadastro 
                                                  FROM administradores 
                                                  ORDER BY nome ASC 
                                                  LIMIT :limit OFFSET :offset");
                    $stmt_select->bindValue(':limit', $usuarios_por_pagina, PDO::PARAM_INT);
                    $stmt_select->bindValue(':offset', $offset, PDO::PARAM_INT);
                    $stmt_select->execute();
                    $usuarios_admin = $stmt_select->fetchAll(PDO::FETCH_ASSOC);

                    if ($usuarios_admin) {
                        foreach ($usuarios_admin as $usuario) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($usuario['id']) . "</td>";
                            echo "<td>" . htmlspecialchars($usuario['nome']) . "</td>";
                            echo "<td>" . htmlspecialchars($usuario['username']) . "</td>";
                            echo "<td>" . htmlspecialchars($usuario['email'] ?: '-') . "</td>";
                            echo "<td>" . htmlspecialchars($usuario['nivel_acesso']) . "</td>";
                            echo "<td>" . date('d/m/Y H:i', strtotime($usuario['data_cadastro'])) . "</td>";

                            // Ações visíveis apenas para o nível 'Administrador'
                            if ($admin_nivel_acesso_logado === 'Administrador') {
                                echo "<td class='action-buttons'>";
                                // Não permitir que o admin logado apague a si mesmo ou edite seu próprio nível de acesso facilmente aqui
                                if ($_SESSION['admin_user_id'] == $usuario['id']) {
                                    echo "<a href='usuario_formulario.php?id=" . $usuario['id'] . "&pagina=" . $pagina_atual . "' class='btn btn-primary btn-sm' title='Editar Perfil (Exceto Nível)'><i class='fas fa-user-edit'></i></a> ";
                                    echo "<button class='btn btn-danger btn-sm' title='Não pode apagar a si mesmo' disabled><i class='fas fa-trash-alt'></i></button>";
                                } else {
                                    echo "<a href='usuario_formulario.php?id=" . $usuario['id'] . "&pagina=" . $pagina_atual . "' class='btn btn-primary btn-sm' title='Editar Usuário'><i class='fas fa-edit'></i></a> ";
                                    echo "<a href='usuario_apagar.php?id=" . $usuario['id'] . "&pagina=" . $pagina_atual . "' class='btn btn-danger btn-sm' title='Apagar Usuário' onclick='return confirm(\"Tem certeza que deseja apagar o usuário " . htmlspecialchars(addslashes($usuario['nome'])) . " (Login: " . htmlspecialchars(addslashes($usuario['username'])) . ")? Esta ação não pode ser desfeita.\");'><i class='fas fa-trash-alt'></i></a>";
                                }
                                echo "</td>";
                            }
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='" . ($admin_nivel_acesso_logado === 'Administrador' ? "7" : "6") . "' class='text-center'>Nenhum usuário administrativo encontrado.</td></tr>";
                    }
                } catch (PDOException $e) {
                    echo "<tr><td colspan='" . ($admin_nivel_acesso_logado === 'Administrador' ? "7" : "6") . "' class='text-danger text-center'>Erro ao buscar usuários: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                    $erro_busca_usuarios = true;
                }
            } else {
                 echo "<tr><td colspan='" . ($admin_nivel_acesso_logado === 'Administrador' ? "7" : "6") . "' class='text-danger text-center'>Falha na conexão com o banco de dados.</td></tr>";
                 $erro_busca_usuarios = true;
            }
            ?>
        </tbody>
    </table>
</div>

<?php if (!$erro_busca_usuarios && $total_paginas > 1): ?>
<nav aria-label="Navegação dos usuários">
    <ul class="pagination justify-content-center mt-4">
        <?php
        // Lógica da paginação (pode copiar e adaptar da mensagens_listar.php, ajustando o link base)
        $link_base = 'usuarios_listar.php?'; // Adicionar outros parâmetros de filtro se houver
        if ($pagina_atual > 1) {
            echo '<li class="page-item"><a class="page-link" href="' . $link_base . 'pagina=1">Primeira</a></li>';
            echo '<li class="page-item"><a class="page-link" href="' . $link_base . 'pagina=' . ($pagina_atual - 1) . '" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></a></li>';
        } else {
            echo '<li class="page-item disabled"><span class="page-link">Primeira</span></li>';
            echo '<li class="page-item disabled"><span class="page-link" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></span></li>';
        }
        // ... (restante da lógica de paginação, similar à de mensagens_listar.php) ...
        $num_links_paginacao = 3;
        $inicio_loop = max(1, $pagina_atual - $num_links_paginacao);
        $fim_loop = min($total_paginas, $pagina_atual + $num_links_paginacao);
        if ($inicio_loop > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        for ($i = $inicio_loop; $i <= $fim_loop; $i++) {
            echo '<li class="page-item ' . ($i == $pagina_atual ? 'active' : '') . '"><a class="page-link" href="' . $link_base . 'pagina=' . $i . '">' . $i . '</a></li>';
        }
        if ($fim_loop < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        if ($pagina_atual < $total_paginas) {
            echo '<li class="page-item"><a class="page-link" href="' . $link_base . 'pagina=' . ($pagina_atual + 1) . '" aria-label="Próxima"><span aria-hidden="true">&raquo;</span></a></li>';
            echo '<li class="page-item"><a class="page-link" href="' . $link_base . 'pagina=' . $total_paginas . '">Última</a></li>';
        } else {
            echo '<li class="page-item disabled"><span class="page-link" aria-label="Próxima"><span aria-hidden="true">&raquo;</span></span></li>';
            echo '<li class="page-item disabled"><span class="page-link">Última</span></li>';
        }
        ?>
    </ul>
</nav>
<?php endif; ?>

<?php
require_once 'admin_footer.php';
?>