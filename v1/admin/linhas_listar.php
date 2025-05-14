<?php
// admin/linhas_listar.php
// ATUALIZADO: Para status Ativa/Inativa em vez de apagar.

require_once 'auth_check.php';

// --- Definição de Permissões ---
$niveis_permitidos_ver_linhas = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_permitidos_adicionar_linhas = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_permitidos_editar_linhas = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
// $niveis_permitidos_apagar_linhas = ['Gerência', 'Administrador']; // Não mais apagar, mas sim ativar/desativar
$niveis_permitidos_mudar_status_linhas = ['Supervisores', 'Gerência', 'Administrador']; // Nova permissão

if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_ver_linhas)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para acessar o gerenciamento de linhas.";
    header('Location: index.php');
    exit;
}

require_once '../db_config.php';
$page_title = 'Gerenciar Linhas';
require_once 'admin_header.php';

$itens_por_pagina_linhas = 20;
$pagina_atual_linhas = isset($_GET['pagina']) && filter_var($_GET['pagina'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ? (int)$_GET['pagina'] : 1;
$offset_linhas = ($pagina_atual_linhas - 1) * $itens_por_pagina_linhas;

$filtro_numero_linha = isset($_GET['busca_numero']) ? trim($_GET['busca_numero']) : '';
$filtro_nome_linha = isset($_GET['busca_nome']) ? trim($_GET['busca_nome']) : '';
$filtro_status_linha = isset($_GET['status_filtro']) ? trim($_GET['status_filtro']) : ''; // NOVO FILTRO

$linhas_cadastradas = [];
$total_itens_linhas = 0;
$total_paginas_linhas = 0;
$erro_busca_linhas = false;
$base_img_path_browser = '../img/pontos/'; 
$base_img_path_server = dirname(__DIR__) . '/img/pontos/';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <?php if (in_array($admin_nivel_acesso_logado, $niveis_permitidos_adicionar_linhas)): ?>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="linha_formulario.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Adicionar Nova Linha
        </a>
    </div>
    <?php endif; ?>
</div>

<?php
if (isset($_SESSION['admin_success_message'])) { echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_success_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_success_message']); }
if (isset($_SESSION['admin_error_message'])) { echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_error_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_error_message']); }
?>

<form method="GET" action="linhas_listar.php" class="mb-4 card card-body bg-light p-3 shadow-sm">
    <div class="form-row align-items-end">
        <div class="col-md-3 form-group mb-md-0">
            <label for="busca_numero_linha" class="sr-only">Buscar por número</label>
            <input type="text" name="busca_numero" id="busca_numero_linha" class="form-control form-control-sm" placeholder="Número da Linha..." value="<?php echo htmlspecialchars($filtro_numero_linha); ?>">
        </div>
        <div class="col-md-3 form-group mb-md-0">
            <label for="busca_nome_linha_filtro" class="sr-only">Buscar por nome</label>
            <input type="text" name="busca_nome" id="busca_nome_linha_filtro" class="form-control form-control-sm" placeholder="Nome da Linha..." value="<?php echo htmlspecialchars($filtro_nome_linha); ?>">
        </div>
        <div class="col-md-2 form-group mb-md-0"> <label for="status_filtro_linha" class="sr-only">Filtrar por status</label>
            <select name="status_filtro" id="status_filtro_linha" class="form-control form-control-sm">
                <option value="">Todos os Status</option>
                <option value="ativa" <?php echo ($filtro_status_linha === 'ativa') ? 'selected' : ''; ?>>Ativa</option>
                <option value="inativa" <?php echo ($filtro_status_linha === 'inativa') ? 'selected' : ''; ?>>Inativa</option>
            </select>
        </div>
        <div class="col-md-2 form-group mb-md-0">
            <button type="submit" class="btn btn-sm btn-primary btn-block"><i class="fas fa-filter"></i> Filtrar</button>
        </div>
        <?php if (!empty($filtro_numero_linha) || !empty($filtro_nome_linha) || !empty($filtro_status_linha)): ?>
        <div class="col-md-2 form-group mb-md-0">
            <a href="linhas_listar.php" class="btn btn-sm btn-outline-secondary btn-block"><i class="fas fa-times"></i> Limpar</a>
        </div>
        <?php endif; ?>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped table-sm table-hover">
        <thead class="thead-light">
            <tr>
                <th>Número</th>
                <th>Nome da Linha</th>
                <th>Img. Ponto Ida</th>
                <th>Img. Ponto Volta</th>
                <th>Status</th> <th style="width: 220px;">Ações</th> </tr>
        </thead>
        <tbody>
            <?php
            if ($pdo) {
                try {
                    $sql_where_parts_linhas = [];
                    $params_sql_linhas = [];

                    if (!empty($filtro_numero_linha)) {
                        $sql_where_parts_linhas[] = "l.numero LIKE :numero_f";
                        $params_sql_linhas[':numero_f'] = '%' . $filtro_numero_linha . '%';
                    }
                    if (!empty($filtro_nome_linha)) {
                        $sql_where_parts_linhas[] = "l.nome LIKE :nome_f";
                        $params_sql_linhas[':nome_f'] = '%' . $filtro_nome_linha . '%';
                    }
                    if (!empty($filtro_status_linha)) { // NOVO FILTRO
                        $sql_where_parts_linhas[] = "l.status_linha = :status_f";
                        $params_sql_linhas[':status_f'] = $filtro_status_linha;
                    }
                    $sql_where_clause_linhas = "";
                    if (!empty($sql_where_parts_linhas)) {
                        $sql_where_clause_linhas = " WHERE " . implode(" AND ", $sql_where_parts_linhas);
                    }

                    $stmt_count_linhas = $pdo->prepare("SELECT COUNT(l.id) FROM linhas l" . $sql_where_clause_linhas);
                    $stmt_count_linhas->execute($params_sql_linhas);
                    $total_itens_linhas = (int)$stmt_count_linhas->fetchColumn();
                    $total_paginas_linhas = ceil($total_itens_linhas / $itens_por_pagina_linhas);
                    if ($pagina_atual_linhas > $total_paginas_linhas && $total_paginas_linhas > 0) $pagina_atual_linhas = $total_paginas_linhas;
                    if ($pagina_atual_linhas < 1) $pagina_atual_linhas = 1;
                    $offset_linhas = ($pagina_atual_linhas - 1) * $itens_por_pagina_linhas;

                    // ATUALIZADO: Selecionar status_linha
                    $sql_select_linhas = "SELECT l.id, l.numero, l.nome, 
                                                 l.imagem_ponto_ida_path, l.imagem_ponto_volta_path,
                                                 l.status_linha 
                                          FROM linhas l"
                                       . $sql_where_clause_linhas 
                                       . " ORDER BY CAST(l.numero AS UNSIGNED), l.numero, l.nome ASC 
                                         LIMIT :limit OFFSET :offset";
                    
                    $stmt_select_linhas = $pdo->prepare($sql_select_linhas);
                    foreach ($params_sql_linhas as $key_l => $value_l) {
                        $stmt_select_linhas->bindValue($key_l, $value_l);
                    }
                    $stmt_select_linhas->bindValue(':limit', $itens_por_pagina_linhas, PDO::PARAM_INT);
                    $stmt_select_linhas->bindValue(':offset', $offset_linhas, PDO::PARAM_INT);
                    $stmt_select_linhas->execute();
                    $linhas_cadastradas = $stmt_select_linhas->fetchAll(PDO::FETCH_ASSOC);

                    if ($linhas_cadastradas) {
                        foreach ($linhas_cadastradas as $linha) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($linha['numero']) . "</td>";
                            echo "<td>" . htmlspecialchars($linha['nome'] ?: '-') . "</td>";
                            
                            echo "<td>"; // Imagem Ida
                            if (!empty($linha['imagem_ponto_ida_path']) && file_exists($base_img_path_server . $linha['imagem_ponto_ida_path'])) {
                                $caminho_completo_ida = $base_img_path_browser . htmlspecialchars($linha['imagem_ponto_ida_path']);
                                $alt_text_ida = "Ponto Ida Linha " . htmlspecialchars($linha['numero']);
                                echo "<a href='#' data-toggle='modal' data-target='#imageZoomModal' data-imgsrc='" . $caminho_completo_ida . "' class='zoomable-image-admin' title='Clique para ampliar'>";
                                echo "<img src='" . $caminho_completo_ida . "' alt='" . $alt_text_ida . "' style='max-width: 70px; max-height: 50px; border-radius:3px; cursor:pointer;'>";
                                echo "</a>";
                            } else { echo '-'; }
                            echo "</td>";

                            echo "<td>"; // Imagem Volta
                            if (!empty($linha['imagem_ponto_volta_path']) && file_exists($base_img_path_server . $linha['imagem_ponto_volta_path'])) {
                                $caminho_completo_volta = $base_img_path_browser . htmlspecialchars($linha['imagem_ponto_volta_path']);
                                $alt_text_volta = "Ponto Volta Linha " . htmlspecialchars($linha['numero']);
                                echo "<a href='#' data-toggle='modal' data-target='#imageZoomModal' data-imgsrc='" . $caminho_completo_volta . "' class='zoomable-image-admin' title='Clique para ampliar'>";
                                echo "<img src='" . $caminho_completo_volta . "' alt='" . $alt_text_volta . "' style='max-width: 70px; max-height: 50px; border-radius:3px; cursor:pointer;'>";
                                echo "</a>";
                            } else { echo '-'; }
                            echo "</td>";

                            // Exibir Status
                            echo "<td><span class='badge badge-" . ($linha['status_linha'] == 'ativa' ? 'success' : 'danger') . " p-2'>" . htmlspecialchars(ucfirst($linha['status_linha'])) . "</span></td>";

                            echo "<td class='action-buttons'>";
                            echo "<a href='rotas_linha_gerenciar.php?linha_id=" . $linha['id'] . "&nome_linha=" . urlencode($linha['numero'] . ($linha['nome'] ? ' - ' . $linha['nome'] : '')) . "&pagina=" . $pagina_atual_linhas . "&" . http_build_query(['busca_numero' => $filtro_numero_linha, 'busca_nome' => $filtro_nome_linha, 'status_filtro' => $filtro_status_linha]) . "' class='btn btn-info btn-sm' title='Gerenciar Rotas/Mapas'><i class='fas fa-route'></i></a> ";
                            
                            if (in_array($admin_nivel_acesso_logado, $niveis_permitidos_editar_linhas)) {
                                echo "<a href='linha_formulario.php?id=" . $linha['id'] . "&pagina=" . $pagina_atual_linhas . "&" . http_build_query(['busca_numero' => $filtro_numero_linha, 'busca_nome' => $filtro_nome_linha, 'status_filtro' => $filtro_status_linha]) . "' class='btn btn-primary btn-sm' title='Editar Linha'><i class='fas fa-edit'></i></a> ";
                            }
                            // Botão Ativar/Desativar
                            if (in_array($admin_nivel_acesso_logado, $niveis_permitidos_mudar_status_linhas)) {
                                $nova_acao_status_linha = ($linha['status_linha'] == 'ativa' ? 'desativar' : 'ativar');
                                $btn_classe_status_cor_linha = ($linha['status_linha'] == 'ativa' ? 'btn-warning' : 'btn-success'); // Amarelo para desativar, verde para ativar
                                $icone_status_acao_linha = ($linha['status_linha'] == 'ativa' ? 'fa-toggle-off' : 'fa-toggle-on');
                                $query_string_filtros_acao_linha = http_build_query(['busca_numero' => $filtro_numero_linha, 'busca_nome' => $filtro_nome_linha, 'status_filtro' => $filtro_status_linha]);
                                
                                echo "<a href='linha_acao.php?acao={$nova_acao_status_linha}&id=" . $linha['id'] . "&pagina=" . $pagina_atual_linhas . "&" . $query_string_filtros_acao_linha . "&token=" . uniqid('csrf_linha_status_',true) . "' class='btn {$btn_classe_status_cor_linha} btn-sm' title='" . ucfirst($nova_acao_status_linha) . " Linha' onclick='return confirm(\"Tem certeza que deseja " . $nova_acao_status_linha . " a linha " . htmlspecialchars(addslashes($linha['numero'])) . "? Linhas inativas não aparecerão para seleção em novas escalas.\");'><i class='fas {$icone_status_acao_linha}'></i></a>";
                            }
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='text-center'>Nenhuma linha encontrada" . (!empty($filtro_numero_linha) || !empty($filtro_nome_linha) || !empty($filtro_status_linha) ? " com os filtros aplicados" : "") . ".</td></tr>"; // Colspan ajustado
                    }
                } catch (PDOException $e) {
                    echo "<tr><td colspan='6' class='text-danger text-center'>Erro ao buscar linhas: " . htmlspecialchars($e->getMessage()) . "</td></tr>"; // Colspan ajustado
                    $erro_busca_linhas = true;
                }
            } else {
                 echo "<tr><td colspan='6' class='text-danger text-center'>Falha na conexão com o banco de dados.</td></tr>"; // Colspan ajustado
                 $erro_busca_linhas = true;
            }
            ?>
        </tbody>
    </table>
</div>

<?php if (!$erro_busca_linhas && $total_paginas_linhas > 1): ?>
<nav aria-label="Navegação das linhas">
    <ul class="pagination justify-content-center mt-4">
        <?php
        $query_params_pag_linhas = [];
        if (!empty($filtro_numero_linha)) $query_params_pag_linhas['busca_numero'] = $filtro_numero_linha;
        if (!empty($filtro_nome_linha)) $query_params_pag_linhas['busca_nome'] = $filtro_nome_linha;
        if (!empty($filtro_status_linha)) $query_params_pag_linhas['status_filtro'] = $filtro_status_linha; // Adicionar filtro de status à paginação
        $link_base_pag_linhas = 'linhas_listar.php?' . http_build_query($query_params_pag_linhas) . (empty($query_params_pag_linhas) ? '' : '&');

        // ... (Lógica de links de paginação como antes, agora incluindo status_filtro) ...
        // (O código da paginação anterior já deve funcionar bem se adaptado para $link_base_pag_linhas)
        if ($pagina_atual_linhas > 1):
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_pag_linhas . 'pagina=1">Primeira</a></li>';
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_pag_linhas . 'pagina=' . ($pagina_atual_linhas - 1) . '" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></a></li>';
        else:
            echo '<li class="page-item disabled"><span class="page-link">Primeira</span></li>';
            echo '<li class="page-item disabled"><span class="page-link" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></span></li>';
        endif;

        $num_links_nav = 2;
        $inicio_loop = max(1, $pagina_atual_linhas - $num_links_nav);
        $fim_loop = min($total_paginas_linhas, $pagina_atual_linhas + $num_links_nav);

        if ($inicio_loop > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        for ($i = $inicio_loop; $i <= $fim_loop; $i++):
            echo '<li class="page-item ' . ($i == $pagina_atual_linhas ? 'active' : '') . '"><a class="page-link" href="' . $link_base_pag_linhas . 'pagina=' . $i . '">' . $i . '</a></li>';
        endfor;
        if ($fim_loop < $total_paginas_linhas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';

        if ($pagina_atual_linhas < $total_paginas_linhas):
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_pag_linhas . 'pagina=' . ($pagina_atual_linhas + 1) . '" aria-label="Próxima"><span aria-hidden="true">&raquo;</span></a></li>';
            echo '<li class="page-item"><a class="page-link" href="' . $link_base_pag_linhas . 'pagina=' . $total_paginas_linhas . '">Última</a></li>';
        else:
            echo '<li class="page-item disabled"><span class="page-link" aria-label="Próxima"><span aria-hidden="true">&raquo;</span></span></li>';
            echo '<li class="page-item disabled"><span class="page-link">Última</span></li>';
        endif;
        ?>
    </ul>
</nav>
<?php endif; ?>

<?php
// JavaScript para o Modal de Zoom (como no exemplo anterior)
ob_start(); 
?>
<script>
$(document).ready(function() {
    $(document).on('click', 'a.zoomable-image-admin', function(event) {
        event.preventDefault(); 
        var imgSrc = $(this).data('imgsrc');
        var imgAlt = $(this).find('img').attr('alt') || 'Imagem Ampliada'; 
        if (imgSrc) {
            $('#imageZoomModalLabel').text(imgAlt); 
            $('#zoomedImage').attr('src', imgSrc); 
        }
    });
    $('#imageZoomModal').on('hidden.bs.modal', function () {
        $('#zoomedImage').attr('src', '');
        $('#imageZoomModalLabel').text('Imagem Ampliada');
    });
});
</script>
<?php
$page_specific_js = ob_get_clean(); 
require_once 'admin_footer.php'; 
?>