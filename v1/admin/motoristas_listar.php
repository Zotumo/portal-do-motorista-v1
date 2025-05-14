<?php
// admin/motoristas_listar.php
// ATUALIZADO: Agora é "Gerenciar Funcionários" e inclui filtro/exibição de Cargo.

require_once 'auth_check.php';

// Níveis que podem VISUALIZAR a lista de funcionários
$niveis_permitidos_ver_lista_funcionarios = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_ver_lista_funcionarios)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para acessar a lista de funcionários.";
    header('Location: index.php');
    exit;
}

require_once '../db_config.php';

// Filtros
$filtro_nome_matricula = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro_status = isset($_GET['status_filtro']) ? trim($_GET['status_filtro']) : '';
$filtro_cargo = isset($_GET['filtro_cargo']) ? trim($_GET['filtro_cargo']) : ''; // Novo filtro

// Define o título da página dinamicamente
$page_title = 'Gerenciar Funcionários';
if ($filtro_cargo === 'Motorista') {
    $page_title = 'Gerenciar Motoristas';
} elseif ($filtro_cargo === 'NAO_MOTORISTA') {
    $page_title = 'Gerenciar Outras Funções (Não Motoristas)';
} elseif (!empty($filtro_cargo)) {
    $page_title = 'Gerenciar Funcionários - Cargo: ' . htmlspecialchars($filtro_cargo);
}

require_once 'admin_header.php';

// Configurações da Paginação
$funcionarios_por_pagina = 15; // Nome da variável mais genérico
$pagina_atual = isset($_GET['pagina']) && filter_var($_GET['pagina'], FILTER_VALIDATE_INT) && $_GET['pagina'] > 0 ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_atual - 1) * $funcionarios_por_pagina;

$funcionarios_cadastrados = []; // Nome da variável mais genérico
$total_funcionarios = 0; // Nome da variável mais genérico
$total_paginas = 0;
$erro_busca_funcionarios_lista = false;

// Permissões para ações específicas (mantidas como antes, referem-se ao contexto de funcionário agora)
$pode_cadastrar_funcionario = in_array($admin_nivel_acesso_logado, ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador']);
$pode_editar_funcionario_basico = in_array($admin_nivel_acesso_logado, ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador']);
$pode_gerenciar_status_senha_funcionario = in_array($admin_nivel_acesso_logado, ['Supervisores', 'Gerência', 'Administrador']);

// Lista de cargos para o filtro (pode ser populada do banco se preferir no futuro)
$cargos_para_filtro = ['Motorista', 'Agente de Terminal', 'Catraca', 'CIOP Monitoramento', 'CIOP Planejamento', 'Instrutor', 'Porteiro', 'Soltura']; // Adicione/Remova conforme necessário

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <?php if ($pode_cadastrar_funcionario): ?>
        <a href="motorista_formulario.php" class="btn btn-success">
            <i class="fas fa-user-plus"></i> Adicionar Novo Funcionário
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

<form method="GET" action="motoristas_listar.php" class="mb-3 card card-body bg-light p-3">
    <div class="form-row align-items-end">
        <div class="col-md-4 form-group mb-md-0">
            <label for="busca_input" class="sr-only">Buscar por nome ou matrícula</label>
            <input type="text" name="busca" id="busca_input" class="form-control form-control-sm" placeholder="Nome ou Matrícula..." value="<?php echo htmlspecialchars($filtro_nome_matricula); ?>">
        </div>
        <div class="col-md-3 form-group mb-md-0">
            <label for="status_filtro_select" class="sr-only">Filtrar por status</label>
            <select name="status_filtro" id="status_filtro_select" class="form-control form-control-sm">
                <option value="">Todos os Status</option>
                <option value="ativo" <?php echo ($filtro_status === 'ativo') ? 'selected' : ''; ?>>Ativo</option>
                <option value="inativo" <?php echo ($filtro_status === 'inativo') ? 'selected' : ''; ?>>Inativo</option>
            </select>
        </div>
        <div class="col-md-3 form-group mb-md-0">
            <label for="filtro_cargo_select" class="sr-only">Filtrar por cargo</label>
            <select name="filtro_cargo" id="filtro_cargo_select" class="form-control form-control-sm">
                <option value="">Todos os Cargos</option>
                <?php foreach ($cargos_para_filtro as $cargo_opt): ?>
                    <option value="<?php echo htmlspecialchars($cargo_opt); ?>" <?php echo ($filtro_cargo === $cargo_opt) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cargo_opt); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 form-group mb-md-0">
            <button type="submit" class="btn btn-sm btn-primary btn-block"><i class="fas fa-filter"></i></button>
        </div>
        <?php if (!empty($filtro_nome_matricula) || !empty($filtro_status) || !empty($filtro_cargo)): ?>
        <div class="col-md-1 form-group mb-md-0">
            <a href="motoristas_listar.php" class="btn btn-sm btn-outline-secondary btn-block" title="Limpar Filtros"><i class="fas fa-times"></i></a>
        </div>
        <?php endif; ?>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped table-sm table-hover">
        <thead class="thead-light">
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Matrícula</th>
                <th>Cargo</th>
                <th>Status</th>
                <th>Data Cadastro</th>
                <?php // As ações já consideram o nível de acesso do usuário logado ?>
                <?php if ($pode_editar_funcionario_basico || $pode_gerenciar_status_senha_funcionario): ?>
                    <th style="width: 180px;">Ações</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($pdo) {
                try {
                    // Nome da tabela ainda é 'motoristas', mas conceitualmente agora é 'funcionarios'
                    $sql_base = "FROM motoristas ";
                    $sql_where_parts = [];
                    $params_sql = [];

                    if (!empty($filtro_nome_matricula)) {
                        $sql_where_parts[] = "(nome LIKE :busca_nome OR matricula LIKE :busca_mat)";
                        $params_sql[':busca_nome'] = '%' . $filtro_nome_matricula . '%';
                        $params_sql[':busca_mat'] = '%' . $filtro_nome_matricula . '%';
                    }
                    if (!empty($filtro_status)) {
                        $sql_where_parts[] = "status = :status_val";
                        $params_sql[':status_val'] = $filtro_status;
                    }
                    if (!empty($filtro_cargo)) {
                        if ($filtro_cargo === 'NAO_MOTORISTA') {
                            $sql_where_parts[] = "cargo != :cargo_val_principal"; // Exclui 'Motorista'
                            $params_sql[':cargo_val_principal'] = 'Motorista';
                        } else {
                            $sql_where_parts[] = "cargo = :cargo_val";
                            $params_sql[':cargo_val'] = $filtro_cargo;
                        }
                    }

                    $sql_where = "";
                    if (!empty($sql_where_parts)) {
                        $sql_where = " WHERE " . implode(" AND ", $sql_where_parts);
                    }

                    $stmt_count = $pdo->prepare("SELECT COUNT(*) " . $sql_base . $sql_where);
                    $stmt_count->execute($params_sql);
                    $total_funcionarios = (int)$stmt_count->fetchColumn();
                    $total_paginas = ceil($total_funcionarios / $funcionarios_por_pagina);
                    if ($pagina_atual > $total_paginas && $total_paginas > 0) $pagina_atual = $total_paginas;
                    if ($pagina_atual < 1) $pagina_atual = 1;
                    $offset = ($pagina_atual - 1) * $funcionarios_por_pagina;

                    // Adicionar a coluna 'cargo' ao SELECT
                    $stmt_select = $pdo->prepare("SELECT id, nome, matricula, cargo, status, data_cadastro " . $sql_base . $sql_where . " ORDER BY nome ASC LIMIT :limit OFFSET :offset");
                    foreach ($params_sql as $key => $value) {
                        $stmt_select->bindValue($key, $value);
                    }
                    $stmt_select->bindValue(':limit', $funcionarios_por_pagina, PDO::PARAM_INT);
                    $stmt_select->bindValue(':offset', $offset, PDO::PARAM_INT);
                    $stmt_select->execute();
                    $funcionarios_cadastrados = $stmt_select->fetchAll(PDO::FETCH_ASSOC);

                    if ($funcionarios_cadastrados) {
                        foreach ($funcionarios_cadastrados as $funcionario) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($funcionario['id']) . "</td>";
                            echo "<td>" . htmlspecialchars($funcionario['nome']) . "</td>";
                            echo "<td>" . htmlspecialchars($funcionario['matricula']) . "</td>";
                            echo "<td>" . htmlspecialchars($funcionario['cargo']) . "</td>"; // Exibe o cargo
                            echo "<td><span class='badge badge-" . ($funcionario['status'] == 'ativo' ? 'success' : 'danger') . " p-2'>" . htmlspecialchars(ucfirst($funcionario['status'])) . "</span></td>";
                            echo "<td>" . date('d/m/Y H:i', strtotime($funcionario['data_cadastro'])) . "</td>";

                            if ($pode_editar_funcionario_basico || $pode_gerenciar_status_senha_funcionario) {
                                echo "<td class='action-buttons'>";
                                if ($pode_editar_funcionario_basico) {
                                    // Link para motorista_formulario.php para edição
                                    echo "<a href='motorista_formulario.php?id=" . $funcionario['id'] . "&pagina=" . $pagina_atual . "&" . http_build_query(['busca' => $filtro_nome_matricula, 'status_filtro' => $filtro_status, 'filtro_cargo' => $filtro_cargo]) . "' class='btn btn-primary btn-sm' title='Editar Dados Básicos'><i class='fas fa-edit'></i></a> ";
                                }
                                if ($pode_gerenciar_status_senha_funcionario) {
                                    // Link para motorista_acao.php para ativar/desativar
                                    $nova_acao_status = ($funcionario['status'] == 'ativo' ? 'desativar' : 'ativar');
                                    $btn_classe_status_cor = ($funcionario['status'] == 'ativo' ? 'btn-danger' : 'btn-success');
                                    $icone_status_acao = ($funcionario['status'] == 'ativo' ? 'fa-toggle-off' : 'fa-toggle-on');
                                    $query_string_filtros_acao = http_build_query(['busca' => $filtro_nome_matricula, 'status_filtro' => $filtro_status, 'filtro_cargo' => $filtro_cargo]);
                                    echo "<a href='motorista_acao.php?acao={$nova_acao_status}&id=" . $funcionario['id'] . "&pagina=" . $pagina_atual . "&" . $query_string_filtros_acao . "&token=" . uniqid('csrf_func_status_',true) . "' class='btn {$btn_classe_status_cor} btn-sm' title='" . ucfirst($nova_acao_status) . " Funcionário' onclick='return confirm(\"Tem certeza que deseja " . $nova_acao_status . " o funcionário " . htmlspecialchars(addslashes($funcionario['nome'])) . "?\");'><i class='fas {$icone_status_acao}'></i></a> ";

                                    // Link para motorista_formulario.php para resetar senha
                                    echo "<a href='motorista_formulario.php?id=" . $funcionario['id'] . "&acao=reset_senha&pagina=" . $pagina_atual . "&" . $query_string_filtros_acao . "' class='btn btn-warning btn-sm' title='Redefinir Senha'><i class='fas fa-key'></i></a>";
                                }
                                echo "</td>";
                            }
                            echo "</tr>";
                        }
                    } else {
                        $colspan = 6 + (($pode_editar_funcionario_basico || $pode_gerenciar_status_senha_funcionario) ? 1 : 0); // Colspan ajustado
                        echo "<tr><td colspan='{$colspan}' class='text-center'>Nenhum funcionário encontrado" . (!empty($filtro_nome_matricula) || !empty($filtro_status) || !empty($filtro_cargo) ? " com os filtros aplicados" : "") . ".</td></tr>";
                    }
                } catch (PDOException $e) {
                    $colspan = 6 + (($pode_editar_funcionario_basico || $pode_gerenciar_status_senha_funcionario) ? 1 : 0);
                    echo "<tr><td colspan='{$colspan}' class='text-danger text-center'>Erro ao buscar funcionários: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                    $erro_busca_funcionarios_lista = true;
                }
            } else {
                 $colspan = 6 + (($pode_editar_funcionario_basico || $pode_gerenciar_status_senha_funcionario) ? 1 : 0);
                 echo "<tr><td colspan='{$colspan}' class='text-danger text-center'>Falha na conexão com o banco de dados.</td></tr>";
                 $erro_busca_funcionarios_lista = true;
            }
            ?>
        </tbody>
    </table>
</div>

<?php if (!$erro_busca_funcionarios_lista && $total_paginas > 1): ?>
<nav aria-label="Navegação dos funcionários">
    <ul class="pagination justify-content-center mt-4">
        <?php
        // Mantém os filtros na URL da paginação
        $query_params_paginacao = [];
        if (!empty($filtro_nome_matricula)) $query_params_paginacao['busca'] = $filtro_nome_matricula;
        if (!empty($filtro_status)) $query_params_paginacao['status_filtro'] = $filtro_status;
        if (!empty($filtro_cargo)) $query_params_paginacao['filtro_cargo'] = $filtro_cargo; // Adiciona filtro de cargo
        
        $link_base_paginacao = 'motoristas_listar.php?' . http_build_query($query_params_paginacao) . (empty($query_params_paginacao) ? '' : '&');

        if ($pagina_atual > 1) {
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_paginacao . 'pagina=1">Primeira</a></li>';
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_paginacao . 'pagina=' . ($pagina_atual - 1) . '" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></a></li>';
        } else {
            echo '<li class="page-item disabled"><span class="page-link">Primeira</span></li>';
            echo '<li class="page-item disabled"><span class="page-link" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></span></li>';
        }
        
        $num_links_paginacao = 3; 
        $inicio_loop = max(1, $pagina_atual - $num_links_paginacao);
        $fim_loop = min($total_paginas, $pagina_atual + $num_links_paginacao);

        if ($inicio_loop > 1) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        for ($i = $inicio_loop; $i <= $fim_loop; $i++) {
            echo '<li class="page-item ' . ($i == $pagina_atual ? 'active' : '') . '"><a class="page-link" href="' . $link_base_paginacao . 'pagina=' . $i . '">' . $i . '</a></li>';
        }

        if ($fim_loop < $total_paginas) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        if ($pagina_atual < $total_paginas) {
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_paginacao . 'pagina=' . ($pagina_atual + 1) . '" aria-label="Próxima"><span aria-hidden="true">&raquo;</span></a></li>';
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_paginacao . 'pagina=' . $total_paginas . '">Última</a></li>';
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