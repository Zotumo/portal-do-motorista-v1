<?php
// admin/relatorios/escalas_filtros.php (v2.5 - Polimento Final Select2 Ocorrências)

require_once dirname(__DIR__) . '/auth_check.php'; 

$niveis_permitidos_rel_escalas = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_rel_escalas)) {
    $_SESSION['admin_feedback'] = ['type' => 'error', 'message' => 'Acesso negado aos relatórios de escalas.'];
    header('Location: ../relatorios_index.php'); 
    exit;
}

require_once dirname(dirname(__DIR__)) . '/db_config.php'; 
$page_title = 'Relatórios de Escalas';
// admin_header.php DEVE incluir os links CSS para Select2 e o tema Select2 Bootstrap4
// Exemplo no header:
// <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
// <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@latest/dist/select2-bootstrap4.min.css" rel="stylesheet" /> 
require_once dirname(__DIR__) . '/admin_header.php'; 

// Repopulação de filtros
$tipo_relatorio_escala_selecionado = $_REQUEST['tipo_relatorio_escala'] ?? ''; 
$filtro_data_inicio_geral = $_REQUEST['filtro_data_inicio'] ?? date('Y-m-01');
$filtro_data_fim_geral = $_REQUEST['filtro_data_fim'] ?? date('Y-m-t');
$filtro_motorista_id_geral = $_REQUEST['filtro_motorista_id'] ?? '';
$motorista_texto_repop_geral = '';
$filtro_tipo_escala_fonte_geral = $_REQUEST['filtro_tipo_escala_fonte'] ?? 'ambas';

$filtro_tipo_ocorrencia_selecionados_raw = $_REQUEST['filtro_tipo_ocorrencia'] ?? [];
$filtro_tipo_ocorrencia_selecionados = [];
if (is_string($filtro_tipo_ocorrencia_selecionados_raw) && !empty($filtro_tipo_ocorrencia_selecionados_raw)) {
    $filtro_tipo_ocorrencia_selecionados = explode(',', $filtro_tipo_ocorrencia_selecionados_raw);
} elseif (is_array($filtro_tipo_ocorrencia_selecionados_raw)) {
    $filtro_tipo_ocorrencia_selecionados = $filtro_tipo_ocorrencia_selecionados_raw;
}
$filtro_tipo_ocorrencia_selecionados = array_map('trim', array_map('strtoupper', $filtro_tipo_ocorrencia_selecionados));
$filtro_tipo_ocorrencia_selecionados = array_filter($filtro_tipo_ocorrencia_selecionados);

$tipos_ocorrencia_disponiveis_map = [
    'FOLGA'        => 'Folga', 
    'FALTA'        => 'Falta', 
    'ATESTADO'     => 'Atestado', 
    'FÉRIAS'       => 'Férias',       
    'FORADEESCALA' => 'Fora de Escala' 
];

if (!empty($filtro_motorista_id_geral) && $pdo) { 
    try {
        $stmt_mot_rep = $pdo->prepare("SELECT nome, matricula FROM motoristas WHERE id = :id_mot");
        $stmt_mot_rep->bindParam(':id_mot', $filtro_motorista_id_geral, PDO::PARAM_INT);
        $stmt_mot_rep->execute();
        $mot_data_rep = $stmt_mot_rep->fetch(PDO::FETCH_ASSOC);
        if ($mot_data_rep) {
            $motorista_texto_repop_geral = htmlspecialchars($mot_data_rep['nome'] . " (Matrícula: " . $mot_data_rep['matricula'] . ")");
        }
    } catch (PDOException $e) {
        error_log("Erro ao buscar motorista para repopulação (filtros de escala): " . $e->getMessage());
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <a href="../relatorios_index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Voltar para Central de Relatórios
    </a>
</div>

<?php
if (isset($_SESSION['admin_feedback_rel_escala'])) { 
    $feedback_escala = $_SESSION['admin_feedback_rel_escala'];
    $alert_class_escala = $feedback_escala['type'] === 'success' ? 'alert-success' : ($feedback_escala['type'] === 'error' ? 'alert-danger' : 'alert-warning');
    echo '<div class="alert ' . $alert_class_escala . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($feedback_escala['message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    unset($_SESSION['admin_feedback_rel_escala']);
}
?>

<div class="card mb-4 shadow-sm">
    <div class="card-header"><h5 class="mb-0">Configurar Relatório de Escalas</h5></div>
    <div class="card-body">
        <form id="formConfigRelatorioEscalas">
            <div class="form-row">
                <div class="form-group col-md-7">
                    <label for="tipo_relatorio_escala_select"><strong>1. Escolha o Relatório de Escala:</strong></label>
                    <select class="form-control" id="tipo_relatorio_escala_select" name="tipo_relatorio_escala" required>
                        <option value="">Selecione um relatório...</option>
                        <option value="total_horas_trabalhadas" <?php echo ($tipo_relatorio_escala_selecionado === 'total_horas_trabalhadas' ? 'selected' : ''); ?>>Total de Horas Trabalhadas por Funcionário</option>
                        <option value="ocorrencias_escala" <?php echo ($tipo_relatorio_escala_selecionado === 'ocorrencias_escala' ? 'selected' : ''); ?>>Relatório de Ocorrências na Escala</option>
                    </select>
                </div>
            </div>
            <hr>
            
            <div id="secao_filtros_dinamicos" style="display:none;">
                <h6 class="mb-3"><strong>2. Filtros Específicos:</strong></h6>
                
                <div class="filtros-comuns-escala">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="filtro_data_inicio_geral">Data Início <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="filtro_data_inicio" id="filtro_data_inicio_geral" value="<?php echo htmlspecialchars($filtro_data_inicio_geral); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="filtro_data_fim_geral">Data Fim <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="filtro_data_fim" id="filtro_data_fim_geral" value="<?php echo htmlspecialchars($filtro_data_fim_geral); ?>" required>
                        </div>
                         <div class="form-group col-md-3">
                            <label for="filtro_tipo_escala_fonte_geral">Fonte da Escala:</label>
                            <select name="filtro_tipo_escala_fonte" id="filtro_tipo_escala_fonte_geral" class="form-control form-control-sm">
                                <option value="ambas" <?php echo ($filtro_tipo_escala_fonte_geral === 'ambas' ? 'selected' : ''); ?>>Ambas (Planejada e Diária)</option>
                                <option value="planejada" <?php echo ($filtro_tipo_escala_fonte_geral === 'planejada' ? 'selected' : ''); ?>>Apenas Planejada</option>
                                <option value="diaria" <?php echo ($filtro_tipo_escala_fonte_geral === 'diaria' ? 'selected' : ''); ?>>Apenas Diária</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="filtro_motorista_id_geral_select">Funcionário (Opcional):</label>
                            <select class="form-control form-control-sm" id="filtro_motorista_id_geral_select" name="filtro_motorista_id" data-placeholder="Todos os funcionários...">
                                <?php if ($filtro_motorista_id_geral && !empty($motorista_texto_repop_geral)): ?>
                                    <option value="<?php echo htmlspecialchars($filtro_motorista_id_geral); ?>" selected><?php echo $motorista_texto_repop_geral; ?></option>
                                <?php else: ?>
                                    <option value=""></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="filtros-especificos-relatorio-escala" id="filtros_especificos_ocorrencias_escala" style="display:none;"> 
                    <div class="form-row">
                        <div class="form-group col-md-6"> 
                            <label for="filtro_tipo_ocorrencia_select">Tipo de Ocorrência:</label>
                            <select class="form-control" id="filtro_tipo_ocorrencia_select" name="filtro_tipo_ocorrencia[]" multiple="multiple">
                                <option></option> 
                                <?php foreach ($tipos_ocorrencia_disponiveis_map as $valor_db_ocor => $texto_display_ocor): ?>
                                    <option value="<?php echo htmlspecialchars($valor_db_ocor); ?>" 
                                        <?php echo (in_array(strtoupper($valor_db_ocor), $filtro_tipo_ocorrencia_selecionados)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($texto_display_ocor); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                             <small class="form-text text-muted">Deixe em branco para buscar ocorrências padrão, ou selecione uma ou mais.</small>
                        </div>
                    </div>
                </div>
            </div> 

            <div class="mt-3">
                <button type="button" class="btn btn-info" id="btnVisualizarRelatorioEscala"><i class="fas fa-eye"></i> Visualizar na Tela</button>
            </div>
        </form>
    </div>
</div>

<div id="area_resultado_relatorio_html_wrapper_escala" class="mt-4" style="display:none;">
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0" id="titulo_relatorio_visualizado_escala">Relatório</h5>
            <div>
                <form method="POST" action="escalas_gerar.php" target="_blank" id="formExportRelatorioEscala" style="display: inline;">
                    <input type="hidden" name="tipo_relatorio_escala" id="hidden_tipo_rel_export_escala">
                    <input type="hidden" name="filtro_data_inicio" id="hidden_data_inicio_export_escala">
                    <input type="hidden" name="filtro_data_fim" id="hidden_data_fim_export_escala">
                    <input type="hidden" name="filtro_motorista_id" id="hidden_motorista_export_escala">
                    <input type="hidden" name="filtro_tipo_escala_fonte" id="hidden_tipo_fonte_export_escala">
                    <input type="hidden" name="filtro_tipo_ocorrencia" id="hidden_tipo_ocorrencia_export_escala"> 
                    
                    <button type="submit" class="btn btn-sm btn-primary btn_export_escala" name="modo_exibicao" value="download_csv" style="display:none;"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                    <button type="submit" class="btn btn-sm btn-danger btn_export_escala" name="modo_exibicao" value="download_pdf_simples" style="display:none;"><i class="fas fa-file-pdf"></i> Exportar PDF</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <div id="conteudo_relatorio_html_dinamico_escala">
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
    const $tipoRelatorioEscalaSelect = $('#tipo_relatorio_escala_select');
    const $btnVisualizarEscala = $('#btnVisualizarRelatorioEscala');
    const $areaResultadoWrapperEscala = $('#area_resultado_relatorio_html_wrapper_escala');
    const $conteudoHtmlDinamicoEscala = $('#conteudo_relatorio_html_dinamico_escala');
    const $tituloRelatorioVisualizadoEscala = $('#titulo_relatorio_visualizado_escala');
    const $btnsExportEscala = $('.btn_export_escala'); 
    const $secaoFiltrosDinamicos = $('#secao_filtros_dinamicos');
    
    const $filtrosComunsEscala = $('.filtros-comuns-escala');
    const $filtrosEspecificosOcorrencias = $('#filtros_especificos_ocorrencias_escala');
    const $selectTipoOcorrencia = $('#filtro_tipo_ocorrencia_select');

    $('#filtro_motorista_id_geral_select').select2({
        theme: 'bootstrap4', 
        language: "pt-BR", 
        width: '100%', 
        allowClear: true,
        placeholder: 'Todos os funcionários',
        ajax: { 
            url: '../buscar_motoristas_ajax.php', 
            dataType: 'json', 
            delay: 250,
            data: function(params) { return { q: params.term, page: params.page || 1 }; },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return { results: data.items, pagination: { more: (params.page * 10) < data.total_count } };
            }
        },
        minimumInputLength: 2
    });

    // Inicialização do Select2 para o filtro de TIPO DE OCORRÊNCIA
    $selectTipoOcorrencia.select2({
        theme: 'bootstrap4',        
        language: "pt-BR",          
        width: '100%',              
        placeholder: 'Selecione um ou mais tipos...', 
        allowClear: true,           
        closeOnSelect: false        
    });

    function mostrarOcultarFiltrosEscalas() {
        var tipoRel = $tipoRelatorioEscalaSelect.val();
        $filtrosComunsEscala.hide(); 
        $filtrosEspecificosOcorrencias.hide();
        $secaoFiltrosDinamicos.hide(); 

        if (tipoRel) { 
            $secaoFiltrosDinamicos.show();
            $filtrosComunsEscala.show(); 

            if (tipoRel === 'ocorrencias_escala') {
                $filtrosEspecificosOcorrencias.show(); 
            }
        }
        $areaResultadoWrapperEscala.hide();
        $conteudoHtmlDinamicoEscala.html('<p class="text-center text-muted">Aguardando geração...</p>');
        $btnsExportEscala.hide(); 
    }

    $tipoRelatorioEscalaSelect.on('change', mostrarOcultarFiltrosEscalas).trigger('change');

    $btnVisualizarEscala.on('click', function() {
        const tipoRel = $tipoRelatorioEscalaSelect.val();
        if (!tipoRel) {
            alert('Por favor, selecione um tipo de relatório de escala.'); return;
        }

        let dataToSend = { 
            tipo_relatorio_escala: tipoRel,
            modo_exibicao: 'html',
            filtro_data_inicio: $('#filtro_data_inicio_geral').val(),
            filtro_data_fim: $('#filtro_data_fim_geral').val(),
            filtro_tipo_escala_fonte: $('#filtro_tipo_escala_fonte_geral').val(),
            filtro_motorista_id: $('#filtro_motorista_id_geral_select').val()
        };
        
        if (!dataToSend.filtro_data_inicio || !dataToSend.filtro_data_fim) {
            alert('Data de Início e Data Fim são obrigatórias.'); return;
        }

        if (tipoRel === 'ocorrencias_escala') {
            dataToSend.filtro_tipo_ocorrencia = $selectTipoOcorrencia.val() || []; 
        }

        $conteudoHtmlDinamicoEscala.html('<p class="text-center text-info"><i class="fas fa-spinner fa-spin"></i> Gerando...</p>');
        $areaResultadoWrapperEscala.show();
        $tituloRelatorioVisualizadoEscala.text("Visualizando: " + $tipoRelatorioEscalaSelect.find('option:selected').text());
        $btnsExportEscala.hide(); 

        $.ajax({
            url: 'escalas_gerar.php', 
            type: 'POST',
            data: dataToSend,
            success: function(response) {
                $conteudoHtmlDinamicoEscala.html(response);
                if (response.toLowerCase().indexOf("nenhum dado") === -1 && 
                    response.toLowerCase().indexOf("erro ao gerar") === -1 && 
                    response.toLowerCase().indexOf("acesso negado") === -1 &&
                    response.trim() !== "" && 
                    !response.includes("alert-danger") && 
                    !response.includes("alert-warning") ) {
                    $btnsExportEscala.show(); 
                    
                    $('#hidden_tipo_rel_export_escala').val(dataToSend['tipo_relatorio_escala']);
                    $('#hidden_data_inicio_export_escala').val(dataToSend['filtro_data_inicio'] || '');
                    $('#hidden_data_fim_export_escala').val(dataToSend['filtro_data_fim'] || '');
                    $('#hidden_motorista_export_escala').val(dataToSend['filtro_motorista_id'] || '');
                    $('#hidden_tipo_fonte_export_escala').val(dataToSend['filtro_tipo_escala_fonte'] || 'ambas');
                    
                    var tiposOcorrenciaArray = dataToSend['filtro_tipo_ocorrencia'];
                    if (Array.isArray(tiposOcorrenciaArray)) {
                        $('#hidden_tipo_ocorrencia_export_escala').val(tiposOcorrenciaArray.join(','));
                    } else {
                         $('#hidden_tipo_ocorrencia_export_escala').val(tiposOcorrenciaArray || '');
                    }
                } else {
                    $btnsExportEscala.hide();
                }
            },
            error: function(jqXHR) {
                $conteudoHtmlDinamicoEscala.html('<div class="alert alert-danger">Erro ao gerar visualização. Verifique o console.</div>');
                console.error("Erro AJAX visualizar escalas:", jqXHR.responseText);
            }
        });
    });
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once dirname(__DIR__) . '/admin_footer.php'; 
?>