<?php
// admin/relatorios/funcionarios_filtros.php
require_once dirname(__DIR__) . '/auth_check.php'; 

$niveis_permitidos_rel_func = ['CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_rel_func)) {
    $_SESSION['admin_feedback'] = ['type' => 'error', 'message' => 'Acesso negado aos relatórios de funcionários.'];
    header('Location: ../relatorios_index.php'); 
    exit;
}

require_once dirname(dirname(__DIR__)) . '/db_config.php'; 
$page_title = 'Relatórios de Funcionários';
require_once dirname(__DIR__) . '/admin_header.php'; 

$cargos_para_filtro_relatorios = ['Motorista', 'Agente de Terminal', 'Catraca', 'CIOP Monitoramento', 'CIOP Planejamento', 'Instrutor', 'Porteiro', 'Soltura']; 

$tipo_relatorio_selecionado = $_REQUEST['tipo_relatorio_especifico'] ?? '';
$filtro_cargo_selecionado = $_REQUEST['filtro_cargo'] ?? '';
$filtro_status_selecionado = $_REQUEST['filtro_status'] ?? '';
$filtro_data_de_selecionada = $_REQUEST['filtro_data_contratacao_de'] ?? '';
$filtro_data_ate_selecionada = $_REQUEST['filtro_data_contratacao_ate'] ?? '';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <a href="../relatorios_index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Voltar para Central de Relatórios
    </a>
</div>

<?php
if (isset($_SESSION['admin_feedback_rel_func'])) { 
    $feedback = $_SESSION['admin_feedback_rel_func'];
    $alert_class = $feedback['type'] === 'success' ? 'alert-success' : ($feedback['type'] === 'error' ? 'alert-danger' : 'alert-warning');
    echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($feedback['message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    unset($_SESSION['admin_feedback_rel_func']);
}
?>

<div class="card mb-4 shadow-sm">
    <div class="card-header">
        <h5 class="mb-0">Configurar Relatório de Funcionários</h5>
    </div>
    <div class="card-body">
        <form id="formConfigRelatorioFuncionarios">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="tipo_relatorio_especifico_select"><strong>1. Escolha o Detalhamento:</strong></label>
                    <select class="form-control" id="tipo_relatorio_especifico_select" name="tipo_relatorio_especifico" required>
                        <option value="">Selecione...</option>
                        <option value="contagem_funcionarios_status_cargo" <?php echo ($tipo_relatorio_selecionado === 'contagem_funcionarios_status_cargo' ? 'selected' : ''); ?>>Contagem (por Status/Cargo)</option>
                        <option value="lista_funcionarios_detalhada" <?php echo ($tipo_relatorio_selecionado === 'lista_funcionarios_detalhada' ? 'selected' : ''); ?>>Lista Detalhada</option>
                    </select>
                </div>
            </div>
            <hr>
            <h6 class="mb-3" id="titulo_secao_filtros" style="display:none;"><strong>2. Filtros:</strong></h6>
            
            <div class="filtros-especificos-relatorio" id="filtros_func_comum" style="display:none;"> 
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="filtro_cargo_funcionario_rel">Cargo:</label>
                        <select name="filtro_cargo" id="filtro_cargo_funcionario_rel" class="form-control form-control-sm">
                            <option value="" <?php echo (empty($filtro_cargo_selecionado) ? 'selected' : ''); ?>>Todos os Cargos</option>
                            <?php foreach ($cargos_para_filtro_relatorios as $cargo_opt_rel): ?>
                                <option value="<?php echo htmlspecialchars($cargo_opt_rel); ?>" <?php echo ($filtro_cargo_selecionado === $cargo_opt_rel ? 'selected' : ''); ?>><?php echo htmlspecialchars($cargo_opt_rel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="filtro_status_funcionario_rel">Status:</label>
                        <select name="filtro_status" id="filtro_status_funcionario_rel" class="form-control form-control-sm">
                            <option value="" <?php echo (empty($filtro_status_selecionado) ? 'selected' : ''); ?>>Todos os Status</option>
                            <option value="ativo" <?php echo ($filtro_status_selecionado === 'ativo' ? 'selected' : ''); ?>>Ativo</option>
                            <option value="inativo" <?php echo ($filtro_status_selecionado === 'inativo' ? 'selected' : ''); ?>>Inativo</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="filtros-especificos-relatorio" id="filtros_func_lista_detalhada" style="display:none;">
                 <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="filtro_data_contratacao_de_rel">Data de Contratação (De):</label>
                        <input type="date" name="filtro_data_contratacao_de" id="filtro_data_contratacao_de_rel" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filtro_data_de_selecionada); ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="filtro_data_contratacao_ate_rel">Data de Contratação (Até):</label>
                        <input type="date" name="filtro_data_contratacao_ate" id="filtro_data_contratacao_ate_rel" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filtro_data_ate_selecionada); ?>">
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button type="button" class="btn btn-info" id="btnVisualizarRelatorioFunc"><i class="fas fa-eye"></i> Visualizar na Tela</button>
            </div>
        </form>
    </div>
</div>

<div id="area_resultado_relatorio_html_wrapper_func" class="mt-4" style="display:none;">
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0" id="titulo_relatorio_visualizado_func">Relatório</h5>
            <div>
                <form method="POST" action="funcionarios_gerar.php" target="_blank" id="formExportRelatorioFunc" style="display: inline;">
                    <input type="hidden" name="tipo_relatorio_especifico" id="hidden_tipo_rel_export_func">
                    <input type="hidden" name="filtro_cargo" id="hidden_cargo_export_func">
                    <input type="hidden" name="filtro_status" id="hidden_status_export_func">
                    <input type="hidden" name="filtro_data_contratacao_de" id="hidden_data_de_export_func">
                    <input type="hidden" name="filtro_data_contratacao_ate" id="hidden_data_ate_export_func">
                    
                    <button type="submit" class="btn btn-sm btn-primary btn_export_func" name="modo_exibicao" value="download_csv" style="display:none;"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                    <button type="submit" class="btn btn-sm btn-danger btn_export_func" name="modo_exibicao" value="download_pdf_simples" style="display:none;"><i class="fas fa-file-pdf"></i> Exportar PDF</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <div id="conteudo_relatorio_html_dinamico_func">
                <p class="text-center text-muted">Aguardando geração do relatório...</p>
            </div>
        </div>
    </div>
</div>

<?php
ob_start(); 
?>
<script>
$(document).ready(function() {
    const $tipoRelatorioEspecificoSelect = $('#tipo_relatorio_especifico_select');
    const $btnVisualizarFunc = $('#btnVisualizarRelatorioFunc'); // Corrigido ID (sem _page)
    const $areaResultadoWrapperFunc = $('#area_resultado_relatorio_html_wrapper_func'); // Corrigido ID
    const $conteudoHtmlDinamicoFunc = $('#conteudo_relatorio_html_dinamico_func'); // Corrigido ID
    const $tituloRelatorioVisualizadoFunc = $('#titulo_relatorio_visualizado_func'); // Corrigido ID
    const $btnsExportFunc = $('.btn_export_func'); // Corrigido Classe

    const $tituloSecaoFiltros = $('#titulo_secao_filtros'); // Adicionado para o título
    const $filtrosComunsFunc = $('#filtros_func_comum'); 
    const $filtrosListaDetalhadaFunc = $('#filtros_func_lista_detalhada');

    function mostrarOcultarFiltrosEspecificos() {
        var tipoRel = $tipoRelatorioEspecificoSelect.val();
        
        $filtrosComunsFunc.hide(); 
        $filtrosListaDetalhadaFunc.hide(); 
        $tituloSecaoFiltros.hide(); // Esconde o título por padrão

        if (tipoRel) { // Se algum tipo de relatório foi selecionado
            $tituloSecaoFiltros.show(); // Mostra o título "2. Filtros:"
            if (tipoRel === 'contagem_funcionarios_status_cargo') {
                $filtrosComunsFunc.show();
            } else if (tipoRel === 'lista_funcionarios_detalhada') {
                $filtrosComunsFunc.show();
                $filtrosListaDetalhadaFunc.show();
            }
        }

        $areaResultadoWrapperFunc.hide();
        $conteudoHtmlDinamicoFunc.html('<p class="text-center text-muted">Aguardando geração do relatório...</p>');
        $btnsExportFunc.hide(); 
    }

    $tipoRelatorioEspecificoSelect.on('change', mostrarOcultarFiltrosEspecificos).trigger('change');

    $btnVisualizarFunc.on('click', function() {
        const tipoRel = $tipoRelatorioEspecificoSelect.val();
        if (!tipoRel) {
            alert('Por favor, selecione um tipo de relatório de funcionário.');
            return;
        }

        const formData = $('#formConfigRelatorioFuncionarios').serializeArray();
        let dataToSend = {};
        formData.forEach(function(item) { dataToSend[item.name] = item.value; });
        dataToSend['modo_exibicao'] = 'html'; 

        $conteudoHtmlDinamicoFunc.html('<p class="text-center text-info"><i class="fas fa-spinner fa-spin"></i> Gerando...</p>');
        $areaResultadoWrapperFunc.show();
        $tituloRelatorioVisualizadoFunc.text("Visualizando: " + $tipoRelatorioEspecificoSelect.find('option:selected').text());
        $btnsExportFunc.hide(); 

        $.ajax({
            url: 'funcionarios_gerar.php', 
            type: 'POST',
            data: dataToSend,
            success: function(response) {
                $conteudoHtmlDinamicoFunc.html(response);
                if (response.toLowerCase().indexOf("nenhum dado") === -1 && response.toLowerCase().indexOf("erro ao gerar") === -1 && response.toLowerCase().indexOf("acesso negado") === -1) {
                    $btnsExportFunc.show(); 
                    $('#hidden_tipo_rel_export_func').val(dataToSend['tipo_relatorio_especifico']);
                    $('#hidden_cargo_export_func').val(dataToSend['filtro_cargo'] || '');
                    $('#hidden_status_export_func').val(dataToSend['filtro_status'] || '');
                    $('#hidden_data_de_export_func').val(dataToSend['filtro_data_contratacao_de'] || '');
                    $('#hidden_data_ate_export_func').val(dataToSend['filtro_data_contratacao_ate'] || '');
                } else {
                    $btnsExportFunc.hide(); 
                }
            },
            error: function(jqXHR) {
                $conteudoHtmlDinamicoFunc.html('<div class="alert alert-danger">Erro ao gerar visualização. Verifique o console para detalhes.</div>');
                console.error("Erro AJAX visualizar funcionários:", jqXHR.responseText);
            }
        });
    });
    
    // Os botões de exportação submetem o form #formExportRelatorioFunc
    // Nenhuma alteração necessária aqui para a visibilidade inicial dos filtros.
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once dirname(__DIR__) . '/admin_footer.php'; 
?>