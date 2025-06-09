<?php
// admin/escala_diaria_formulario.php
// ATUALIZADO v12: Inclui Tipo de Escala (Linha/Função), WorkID dinâmico para Função e Linha,
// e Select de Veículo dinâmico via AJAX e obrigatório.

require_once 'auth_check.php';

// --- Permissões ---
$niveis_permitidos_gerenciar_diaria = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerenciar_diaria)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para gerenciar a Escala Diária.";
    header('Location: escala_diaria_consultar.php');
    exit;
}

require_once '../db_config.php';
$page_title_action = 'Ajustar/Adicionar na Escala Diária';

// --- Inicialização de Variáveis do Formulário ---
$escala_diaria_id_edit = null;
$tipo_escala_form_php = 'linha';
$data_escala_form_php = isset($_GET['data_escala']) ? htmlspecialchars($_GET['data_escala']) : date('Y-m-d');
$motorista_id_form_php = '';
$motorista_texto_repop_php = '';

// Para Linha
$linha_origem_id_form_php = '';
$veiculo_id_db_diaria_php = ''; // ID do veículo salvo no banco para esta escala diária
$veiculo_prefixo_db_diaria_php = ''; // Prefixo do veículo salvo

// Para Função Operacional
$funcao_operacional_id_form_php = '';
$turno_funcao_form_php = '';
$posicao_letra_form_php = '';

$work_id_form_php = '';
$tabela_escalas_form_php = '';
$hora_inicio_form_php = '';
$local_inicio_id_form_php = '';
$hora_fim_form_php = '';
$local_fim_id_form_php = '';
$eh_extra_form_php = 0;
$observacoes_ajuste_form_php = '';

$is_folga_check_php = false; $is_falta_check_php = false; $is_fora_escala_check_php = false; $is_ferias_check_php = false; $is_atestado_check_php = false;
$modo_edicao_escala_php = false;

// Listas para Selects
$lista_linhas_select_php = [];
$lista_locais_select_php = [];
// Veículos serão carregados via AJAX
$lista_funcoes_operacionais_php = [];

if ($pdo) {
    try {
        $stmt_linhas_all = $pdo->query("SELECT id, numero, nome FROM linhas WHERE status_linha = 'ativa' ORDER BY CAST(numero AS UNSIGNED), numero, nome ASC");
        $lista_linhas_select_php = $stmt_linhas_all->fetchAll(PDO::FETCH_ASSOC);

        $stmt_locais_all = $pdo->query("SELECT id, nome, tipo FROM locais ORDER BY nome ASC");
        $lista_locais_select_php = $stmt_locais_all->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt_funcoes = $pdo->query("SELECT id, nome_funcao, work_id_prefixo, locais_permitidos_tipo, locais_permitidos_ids, local_fixo_id, turnos_disponiveis, requer_posicao_especifica, max_posicoes_por_turno, ignorar_validacao_jornada FROM funcoes_operacionais WHERE status = 'ativa' ORDER BY nome_funcao ASC");
        $lista_funcoes_operacionais_php = $stmt_funcoes->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Erro ao buscar dados (linhas/locais/funções) para formulário de escala diária: " . $e->getMessage());
        $_SESSION['admin_warning_message'] = "Atenção: Erro ao carregar algumas opções de seleção.";
    }
}

// --- Lógica de Edição ---
if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $escala_diaria_id_edit = (int)$_GET['id'];
    $modo_edicao_escala_php = true;
    $page_title_action = 'Editar Entrada da Escala Diária';

    if ($pdo) {
        try {
            $sql_get_escala_diaria = "SELECT escd.*, 
                                             mot.nome as nome_motorista_atual, mot.matricula as matricula_motorista_atual,
                                             veic.prefixo as prefixo_veiculo_atual 
                                      FROM motorista_escalas_diaria escd
                                      LEFT JOIN motoristas mot ON escd.motorista_id = mot.id 
                                      LEFT JOIN veiculos veic ON escd.veiculo_id = veic.id
                                      WHERE escd.id = :id_escala_diaria";
            $stmt_get_escala = $pdo->prepare($sql_get_escala_diaria);
            $stmt_get_escala->bindParam(':id_escala_diaria', $escala_diaria_id_edit, PDO::PARAM_INT);
            $stmt_get_escala->execute();
            $escala_db = $stmt_get_escala->fetch(PDO::FETCH_ASSOC);

            if ($escala_db) {
                $data_escala_form_php = $escala_db['data'];
                $motorista_id_form_php = $escala_db['motorista_id'];
                if ($motorista_id_form_php && isset($escala_db['nome_motorista_atual'])) { 
                    $motorista_texto_repop_php = htmlspecialchars($escala_db['nome_motorista_atual'] . ' (Mat: ' . $escala_db['matricula_motorista_atual'] . ')'); 
                }
                
                $work_id_form_php = $escala_db['work_id'];
                $funcao_operacional_id_form_php = $escala_db['funcao_operacional_id'];
                $observacoes_ajuste_form_php = $escala_db['observacoes_ajuste'];

                if (!empty($funcao_operacional_id_form_php)) {
                    $tipo_escala_form_php = 'funcao';
                    $funcao_obj_edit_d = null;
                    foreach($lista_funcoes_operacionais_php as $f_d){ if(strval($f_d['id'])===strval($funcao_operacional_id_form_php)){$funcao_obj_edit_d=$f_d;break;}}
                    if ($funcao_obj_edit_d && $work_id_form_php) {
                        $prefixo_func_edit_d = $funcao_obj_edit_d['work_id_prefixo'];
                        $sem_prefixo_edit_d = preg_replace('/^'.preg_quote($prefixo_func_edit_d, '/').'-?/i', '', $work_id_form_php);
                        if (!$funcao_obj_edit_d['local_fixo_id']) { $sem_prefixo_edit_d = preg_replace('/^[A-Z0-9]{1,3}-/i', '', $sem_prefixo_edit_d); }
                        $partes_turno_pos_edit_d = explode('-', $sem_prefixo_edit_d);
                        $ultimo_segmento_edit_d = array_pop($partes_turno_pos_edit_d);
                        if($funcao_obj_edit_d['requer_posicao_especifica'] && strlen($ultimo_segmento_edit_d) > 2 && ctype_alpha(substr($ultimo_segmento_edit_d,-1))){
                            $posicao_letra_form_php = strtoupper(substr($ultimo_segmento_edit_d,-1));
                            $turno_funcao_form_php = substr($ultimo_segmento_edit_d,0,-1);
                        } elseif (strlen($ultimo_segmento_edit_d) == 2 && ctype_digit($ultimo_segmento_edit_d)){
                           $turno_funcao_form_php = $ultimo_segmento_edit_d; $posicao_letra_form_php = '';
                        }
                    }
                } else {
                    $tipo_escala_form_php = 'linha';
                    $linha_origem_id_form_php = $escala_db['linha_origem_id'];
                    $veiculo_id_db_diaria_php = $escala_db['veiculo_id']; // Armazena ID
                    $veiculo_prefixo_db_diaria_php = $escala_db['prefixo_veiculo_atual']; // Armazena prefixo
                }
                
                $work_id_upper = strtoupper($work_id_form_php ?? '');
                $is_folga_check_php = ($work_id_upper === 'FOLGA');
                $is_falta_check_php = ($work_id_upper === 'FALTA');
                $is_fora_escala_check_php = ($work_id_upper === 'FORADEESCALA');
                $is_ferias_check_php = ($work_id_upper === 'FÉRIAS');
                $is_atestado_check_php = ($work_id_upper === 'ATESTADO');
                $is_status_especial = $is_folga_check_php || $is_falta_check_php || $is_fora_escala_check_php || $is_ferias_check_php || $is_atestado_check_php;
                
                $tabela_escalas_form_php = ($is_status_especial || $tipo_escala_form_php === 'funcao') ? '' : $escala_db['tabela_escalas'];
                if (($tipo_escala_form_php === 'funcao' || $is_status_especial)) { /* linha e veículo são tratados acima */ }
                $hora_inicio_form_php = $is_status_especial ? '' : ($escala_db['hora_inicio_prevista'] ? date('H:i', strtotime($escala_db['hora_inicio_prevista'])) : '');
                $local_inicio_id_form_php = $is_status_especial ? '' : $escala_db['local_inicio_turno_id'];
                $hora_fim_form_php = $is_status_especial ? '' : ($escala_db['hora_fim_prevista'] ? date('H:i', strtotime($escala_db['hora_fim_prevista'])) : '');
                $local_fim_id_form_php = $is_status_especial ? '' : $escala_db['local_fim_turno_id'];
                $eh_extra_form_php = $is_status_especial ? 0 : $escala_db['eh_extra'];
                $page_title_action .= ' (' . $motorista_texto_repop_php . ' em ' . date('d/m/Y', strtotime($data_escala_form_php)) . ')';

            } else { 
                $_SESSION['admin_error_message'] = "Entrada da Escala Diária ID {$escala_diaria_id_edit} não encontrada.";
                // ... (redirect para consulta diária)
                header('Location: escala_diaria_consultar.php?' . http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))));
                exit;
            }
        } catch (PDOException $e) { /* ... tratamento de erro ... */ }
    }
}

$page_title = $page_title_action;
require_once 'admin_header.php';

// --- Repopulação do Formulário ---
$form_data_repop_session_d = $_SESSION['form_data_escala_diaria'] ?? [];
if(!empty($form_data_repop_session_d)) {
    // ... (sua lógica de repopulação, similar à da escala planejada, mas usando as variáveis com sufixo _diaria_php)
    $tipo_escala_form_php = $form_data_repop_session_d['tipo_escala'] ?? $tipo_escala_form_php;
    // ... (etc.)
    $veiculo_id_db_diaria_php = $form_data_repop_session_d['veiculo_id'] ?? $veiculo_id_db_diaria_php;
    if ($veiculo_id_db_diaria_php && empty($veiculo_prefixo_db_diaria_php) && $pdo) { /* ... busca prefixo ... */ }
    $observacoes_ajuste_form_php = $form_data_repop_session_d['observacoes_ajuste'] ?? $observacoes_ajuste_form_php;
    unset($_SESSION['form_data_escala_diaria']);
}

// Passar dados PHP para JavaScript
$js_work_id_inicial_diaria_php = $work_id_form_php;
$js_funcoes_operacionais_data_diaria = []; foreach($lista_funcoes_operacionais_php as $func_d) { $js_funcoes_operacionais_data_diaria[$func_d['id']] = $func_d; }
$js_locais_data_todos_diaria = []; foreach ($lista_locais_select_php as $loc_d) { $js_locais_data_todos_diaria[] = ['id' => $loc_d['id'], 'text' => htmlspecialchars($loc_d['nome']), 'tipo' => strtolower($loc_d['tipo'] ?? '')]; }
$js_veiculo_id_atual_diaria_php = $veiculo_id_db_diaria_php;
$js_veiculo_prefixo_atual_diaria_php = $veiculo_prefixo_db_diaria_php;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title_action); ?></h1>
    <a href="escala_diaria_consultar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Voltar para Consulta Diária
    </a>
</div>

<?php
if (isset($_SESSION['admin_form_error_escala_d'])) { echo '<div class="alert alert-danger alert-dismissible fade show">' . nl2br(htmlspecialchars($_SESSION['admin_form_error_escala_d'])) . '<button type="button" class="close" data-dismiss="alert">&times;</button></div>'; unset($_SESSION['admin_form_error_escala_d']); }
if (isset($_SESSION['admin_warning_message'])) { /* ... exibir warning ... */ }
?>

<form action="escala_diaria_processa.php" method="POST" id="form-escala-diaria">
    <?php if ($modo_edicao_escala_php && $escala_diaria_id_edit): ?>
        <input type="hidden" name="escala_diaria_id" value="<?php echo $escala_diaria_id_edit; ?>">
    <?php endif; ?>
    <?php
    $params_to_preserve_submit_diaria = ['pagina_original' => 'pagina', 'filtro_data_original' => 'data_escala', 'filtro_tipo_busca_original' => 'tipo_busca_adicional', 'filtro_valor_busca_original' => 'valor_busca_adicional'];
    foreach ($params_to_preserve_submit_diaria as $hidden_name_diaria => $get_key_diaria):
        if (isset($_GET[$get_key_diaria])): ?>
        <input type="hidden" name="<?php echo htmlspecialchars($hidden_name_diaria); ?>" value="<?php echo htmlspecialchars($_GET[$get_key_diaria]); ?>">
    <?php endif; endforeach; ?>

    <fieldset class="mb-4 border p-3 rounded bg-light">
        <legend class="w-auto px-2 h6 text-secondary font-weight-normal">Copiar da Escala Planejada para esta Data/Motorista (Opcional)</legend>
        <div class="form-row align-items-end">
            <div class="form-group col-md-8">
                <label for="motorista_display_copia_planejada_diaria" class="small">Motorista (usará o motorista já selecionado abaixo):</label>
                <input type="text" class="form-control form-control-sm" id="motorista_display_copia_planejada_diaria" readonly 
                       value="<?php echo $motorista_id_form_php ? $motorista_texto_repop_php : 'Selecione o motorista principal abaixo primeiro'; ?>">
            </div>
            <div class="form-group col-md-4">
                 <label for="data_copia_planejada_display_diaria" class="small">Data (usará a data principal do formulário):</label>
                 <input type="text" class="form-control form-control-sm" id="data_copia_planejada_display_diaria" value="<?php echo date('d/m/Y', strtotime($data_escala_form_php)); ?>" readonly>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-info mt-2" id="btnCopiarDaPlanejadaDiaria">
            <i class="fas fa-copy"></i> Preencher com Dados da Escala Planejada
        </button>
        <div id="copiar_planejada_feedback_diaria" class="small mt-1" style="min-height: 20px;"></div>
    </fieldset>


    <div class="form-row">
        <div class="form-group col-md-3">
            <label for="data_escala_diaria_form">Data da Escala <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="data_escala_diaria_form" name="data_escala" value="<?php echo htmlspecialchars($data_escala_form_php); ?>" required>
        </div>
        <div class="form-group col-md-5">
            <label for="motorista_id_select2_escala_diaria_form">Motorista <span class="text-danger">*</span></label>
            <select class="form-control" id="motorista_id_select2_escala_diaria_form" name="motorista_id" required data-placeholder="Selecione ou digite nome/matrícula...">
                <?php if ($motorista_id_form_php && !empty($motorista_texto_repop_php)): ?>
                    <option value="<?php echo htmlspecialchars($motorista_id_form_php); ?>" selected><?php echo $motorista_texto_repop_php; ?></option>
                <?php elseif ($motorista_id_form_php): ?>
                     <option value="<?php echo htmlspecialchars($motorista_id_form_php); ?>" selected>ID: <?php echo htmlspecialchars($motorista_id_form_php); ?> (Carregando...)</option>
                <?php else: ?><option></option><?php endif; ?>
            </select>
        </div>
        <div class="form-group col-md-4 d-flex align-items-center flex-wrap">
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check-diaria-form" type="checkbox" value="FOLGA" id="is_folga_check_diaria_form" name="is_folga_check" <?php echo $is_folga_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_folga_check_diaria_form"><strong>Folga?</strong></label></div>
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check-diaria-form" type="checkbox" value="FALTA" id="is_falta_check_diaria_form" name="is_falta_check" <?php echo $is_falta_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_falta_check_diaria_form"><strong>Falta?</strong></label></div>
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check-diaria-form" type="checkbox" value="FORADEESCALA" id="is_fora_escala_check_diaria_form" name="is_fora_escala_check" <?php echo $is_fora_escala_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_fora_escala_check_diaria_form"><strong>Fora de Escala?</strong></label></div>
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check-diaria-form" type="checkbox" value="FÉRIAS" id="is_ferias_check_diaria_form" name="is_ferias_check" <?php echo $is_ferias_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_ferias_check_diaria_form"><strong>Férias?</strong></label></div>
            <div class="form-check mb-2"><input class="form-check-input status-escala-check-diaria-form" type="checkbox" value="ATESTADO" id="is_atestado_check_diaria_form" name="is_atestado_check" <?php echo $is_atestado_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_atestado_check_diaria_form"><strong>Atestado?</strong></label></div>
        </div>
    </div>
    <hr>
    
    <div class="form-row">
        <div class="form-group col-md-4">
            <label for="tipo_escala_select_diaria_form">Tipo de Escala <span class="text-danger">*</span></label>
            <select class="form-control" id="tipo_escala_select_diaria_form" name="tipo_escala">
                <option value="linha" <?php echo ($tipo_escala_form_php === 'linha') ? 'selected' : ''; ?>>Linha de Ônibus</option>
                <option value="funcao" <?php echo ($tipo_escala_form_php === 'funcao') ? 'selected' : ''; ?>>Função Operacional</option>
            </select>
        </div>
    </div>

    <div id="campos_escala_linha_wrapper_diaria_form" style="<?php echo ($tipo_escala_form_php !== 'linha') ? 'display:none;' : ''; ?>">
        <div class="form-row">
            <div class="form-group col-md-8">
                <label for="linha_origem_id_diaria_form">Linha de Origem (Principal) <span class="text-danger">*</span></label>
                <select class="form-control select2-simple-diaria" id="linha_origem_id_diaria_form" name="linha_origem_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach ($lista_linhas_select_php as $l_d):?>
                        <option value="<?php echo $l_d['id'];?>" <?php if(strval($l_d['id'])==strval($linha_origem_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($l_d['numero'].($l_d['nome']?' - '.$l_d['nome']:''));?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label for="veiculo_id_ajax_diaria_form">Veículo <span class="text-danger" id="veiculo_obrigatorio_asterisco_diaria_form">*</span></label>
                <select class="form-control" id="veiculo_id_ajax_diaria_form" name="veiculo_id" data-placeholder="Selecione linha primeiro...">
                    <option value="">Selecione uma linha...</option>
                    <?php if ($modo_edicao_escala_php && !empty($veiculo_id_db_diaria_php) && !empty($veiculo_prefixo_db_diaria_php) && $tipo_escala_form_php === 'linha'): ?>
                        <option value="<?php echo htmlspecialchars($veiculo_id_db_diaria_php); ?>" selected>
                            <?php echo htmlspecialchars($veiculo_prefixo_db_diaria_php); ?> (Salvo)
                        </option>
                    <?php endif; ?>
                </select>
                <small id="veiculo_id_ajax_feedback_diaria_form" class="form-text"></small>
            </div>
        </div>
    </div>

    <div id="todos_campos_funcao_wrapper_diaria_form" style="<?php echo ($tipo_escala_form_php !== 'funcao') ? 'display:none;' : ''; ?>">
        <div class="form-row">
            <div class="form-group col-md-12">
                <label for="funcao_operacional_id_select_diaria_form">Função Operacional <span class="text-danger">*</span></label>
                <select class="form-control select2-simple-diaria" id="funcao_operacional_id_select_diaria_form" name="funcao_operacional_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach($lista_funcoes_operacionais_php as $fo_d):?>
                        <option value="<?php echo $fo_d['id'];?>" 
                                data-prefixo="<?php echo htmlspecialchars($fo_d['work_id_prefixo']);?>"
                                data-locais-tipo="<?php echo htmlspecialchars($fo_d['locais_permitidos_tipo']??'');?>"
                                data-locais-ids="<?php echo htmlspecialchars($fo_d['locais_permitidos_ids']??'');?>"
                                data-local-fixo-id="<?php echo htmlspecialchars($fo_d['local_fixo_id']??'');?>"
                                data-turnos="<?php echo htmlspecialchars($fo_d['turnos_disponiveis']);?>"
                                data-requer-posicao="<?php echo $fo_d['requer_posicao_especifica']?'true':'false';?>"
                                data-max-posicoes="<?php echo htmlspecialchars($fo_d['max_posicoes_por_turno']??'0');?>"
                                data-ignora-jornada="<?php echo $fo_d['ignorar_validacao_jornada']?'true':'false';?>"
                                <?php if(strval($fo_d['id'])==strval($funcao_operacional_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($fo_d['nome_funcao']);?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="turno_funcao_select_diaria_form">Turno da Função <span class="text-danger">*</span></label>
                <select class="form-control" id="turno_funcao_select_diaria_form" name="turno_funcao">
                    <option value="">Selecione...</option>
                    </select>
            </div>
            <div class="form-group col-md-4" id="wrapper_posicao_letra_funcao_diaria_form" style="display:none;">
                <label for="posicao_letra_funcao_select_diaria_form">Posição/Letra <span class="text-danger">*</span></label>
                <select class="form-control" id="posicao_letra_funcao_select_diaria_form" name="posicao_letra_funcao">
                    <option value="">Selecione...</option>
                    </select>
            </div>
        </div>
    </div>

    <div id="campos_comuns_escala_wrapper_diaria_form">
        <div class="form-row">
            <div class="form-group col-md-4" id="div_work_id_campo_unico_diaria_form">
                <label for="work_id_input_diaria_form">WorkID <span id="work_id_obrigatorio_asterisco_diaria_form" class="text-danger">*</span></label>
                <input type="text" class="form-control" id="work_id_input_diaria_form" name="work_id_text_input_diaria_disabled" 
                       value="<?php echo htmlspecialchars($work_id_form_php); ?>" maxlength="50">
                <select class="form-control" id="work_id_select_diaria_form" name="work_id_select_input_diaria_disabled">
                    <option value="">Selecione Linha e Data...</option>
                    <?php if ($modo_edicao_escala_php && $tipo_escala_form_php === 'linha' && !empty($work_id_form_php) && !$is_folga_check_php && !$is_falta_check_php && !$is_fora_escala_check_php && !$is_ferias_check_php && !$is_atestado_check_php): ?>
                        <option value="<?php echo htmlspecialchars($work_id_form_php); ?>" selected><?php echo htmlspecialchars($work_id_form_php); ?> (Salvo)</option>
                    <?php endif; ?>
                </select>
                <small class="form-text" id="work_id_sugestao_text_diaria_form"></small>
            </div>
            <div class="form-group col-md-4" id="wrapper_tabela_escalas_diaria_form">
                <label for="tabela_escalas_diaria_form">Nº Tabela da Escala</label>
                <input type="text" class="form-control" id="tabela_escalas_diaria_form" name="tabela_escalas" value="<?php echo htmlspecialchars($tabela_escalas_form_php); ?>" maxlength="10">
            </div>
            <div class="form-group col-md-4 d-flex align-items-center pt-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="eh_extra_diaria_form" name="eh_extra" <?php echo ($eh_extra_form_php==1)?'checked':'';?>>
                    <label class="form-check-label" for="eh_extra_diaria_form">Turno Extra?</label>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="hora_inicio_prevista_diaria_form">Hora Início <span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="hora_inicio_prevista_diaria_form" name="hora_inicio_prevista" value="<?php echo htmlspecialchars($hora_inicio_form_php);?>">
            </div>
            <div class="form-group col-md-3">
                <label for="local_inicio_turno_id_diaria_form">Local Início <span class="text-danger">*</span></label>
                <select class="form-control select2-simple-diaria" id="local_inicio_turno_id_diaria_form" name="local_inicio_turno_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach($lista_locais_select_php as $li_d):?>
                        <option value="<?php echo $li_d['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($li_d['tipo']??''));?>" <?php if(strval($li_d['id'])==strval($local_inicio_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($li_d['nome']);?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="hora_fim_prevista_diaria_form">Hora Fim <span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="hora_fim_prevista_diaria_form" name="hora_fim_prevista" value="<?php echo htmlspecialchars($hora_fim_form_php);?>">
            </div>
            <div class="form-group col-md-3">
                <label for="local_fim_turno_id_diaria_form">Local Fim <span class="text-danger">*</span></label>
                <select class="form-control select2-simple-diaria" id="local_fim_turno_id_diaria_form" name="local_fim_turno_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach($lista_locais_select_php as $lf_d):?>
                        <option value="<?php echo $lf_d['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($lf_d['tipo']??''));?>" <?php if(strval($lf_d['id'])==strval($local_fim_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($lf_d['nome']);?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
    </div>

    <div class="form-group mt-3">
        <label for="observacoes_ajuste_diaria_form">Observações do Ajuste (Escala Diária):</label>
        <textarea class="form-control" id="observacoes_ajuste_diaria_form" name="observacoes_ajuste" rows="3"><?php echo htmlspecialchars($observacoes_ajuste_form_php); ?></textarea>
        <small class="form-text text-muted">Qualquer informação relevante sobre a alteração feita na escala diária (ex: troca de turno, ajuste de horário emergencial).</small>
    </div>

    <hr>
    <button type="submit" name="salvar_escala_diaria" class="btn btn-warning"><i class="fas fa-save"></i> Salvar na Escala Diária</button>
    <a href="escala_diaria_consultar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-secondary">Cancelar</a>
</form>

<?php
ob_start(); // Captura o JavaScript
?>
<script>
    // Passar dados PHP para JavaScript
    const funcoesOperacionaisDataDiaria = <?php echo json_encode($js_funcoes_operacionais_data_diaria); ?>;
    const todosOsLocaisDataDiaria = <?php echo json_encode($js_locais_data_todos_diaria); ?>;
    var valorOriginalWorkIdDiariaFormJs = <?php echo json_encode($js_work_id_inicial_diaria_php); ?>;
    const veiculoIdAtualDiariaPhp = <?php echo json_encode($js_veiculo_id_atual_diaria_php); ?>;
    const veiculoPrefixoAtualDiariaPhp = <?php echo json_encode($js_veiculo_prefixo_atual_diaria_php); ?>;

$(document).ready(function() {
    // --- Seletores Globais do Formulário com sufixo "_diaria_form" ---
    const $tipoEscalaSelectForm = $('#tipo_escala_select_diaria_form');
    const $camposLinhaWrapperForm = $('#campos_escala_linha_wrapper_diaria_form');
    const $todosCamposFuncaoWrapperForm = $('#todos_campos_funcao_wrapper_diaria_form');
    const $funcaoSelectForm = $('#funcao_operacional_id_select_diaria_form');
    const $turnoFuncaoSelectForm = $('#turno_funcao_select_diaria_form');
    const $posicaoLetraWrapperForm = $('#wrapper_posicao_letra_funcao_diaria_form');
    const $posicaoLetraSelectForm = $('#posicao_letra_funcao_select_diaria_form');
    const $localInicioSelectForm = $('#local_inicio_turno_id_diaria_form');
    const $localFimSelectForm = $('#local_fim_turno_id_diaria_form');
    const $tabelaEscalasWrapperForm = $('#wrapper_tabela_escalas_diaria_form');
    const $camposComunsWrapperForm = $('#campos_comuns_escala_wrapper_diaria_form');
    const $statusCheckboxesForm = $('.status-escala-check-diaria-form');
    
    const $workIdInputDiariaForm = $('#work_id_input_diaria_form');
    const $workIdSelectDiariaForm = $('#work_id_select_diaria_form');
    const $workIdSugestaoTextDiariaForm = $('#work_id_sugestao_text_diaria_form');
    const $linhaOrigemSelectDiariaWorkID = $('#linha_origem_id_diaria_form');
    const $dataEscalaInputDiariaWorkID = $('#data_escala_diaria_form');

    const $linhaOrigemSelectVeiculoDiaria = $('#linha_origem_id_diaria_form');
    const $veiculoSelectAjaxDiaria = $('#veiculo_id_ajax_diaria_form');
    const $veiculoFeedbackAjaxDiaria = $('#veiculo_id_ajax_feedback_diaria_form');
    const $veiculoObrigatorioAsteriscoDiaria = $('#veiculo_obrigatorio_asterisco_diaria_form');

    // --- Inicialização de Plugins (Select2) ---
    $('#motorista_id_select2_escala_diaria_form').select2({ /* ... config AJAX ... */ 
        theme: 'bootstrap4', language: "pt-BR", width: '100%', allowClear: true,
        placeholder: 'Digite nome ou matrícula...',
        ajax: { 
            url: 'buscar_motoristas_ajax.php', dataType: 'json', delay: 250,
            data: function (params) { return { q: params.term, page: params.page || 1 }; },
            processResults: function (data, params) { params.page = params.page || 1; return { results: data.items, pagination: { more: (params.page * 10) < data.total_count } }; },
        },
        minimumInputLength: 2, escapeMarkup: function (m) { return m; },
        templateResult: function (d) { return d.text || "Buscando..."; },
        templateSelection: function (d) { return d.text || d.id; }
    }).on('select2:select', function (e) {
        var data = e.params.data;
        $('#motorista_display_copia_planejada_diaria').val(data.text || 'Motorista ID: ' + data.id);
    });
    
    $('.select2-simple-diaria').each(function() { // Usando a classe correta
        $(this).select2({ theme: 'bootstrap4', placeholder: $(this).data('placeholder') || 'Selecione...', allowClear: true, width: '100%' });
    });

    // --- Funções Auxiliares (com sufixo "_diaria" quando necessário) ---
    function carregarVeiculosCompativelComLinhaDiaria() {
        const linhaId = $linhaOrigemSelectVeiculoDiaria.val();
        const dataEscala = $dataEscalaInputDiariaWorkID.val();
        const tipoEscalaAtual = $tipoEscalaSelectForm.val();

        $veiculoSelectAjaxDiaria.prop('disabled', true).html('<option value="">Carregando veículos...</option>');
        $veiculoFeedbackAjaxDiaria.removeClass('text-success text-danger text-info').addClass('text-muted').text('Buscando veículos compatíveis...');

        if (tipoEscalaAtual === 'linha' && linhaId && dataEscala && !$statusCheckboxesForm.is(':checked')) {
            $.ajax({
                url: 'buscar_veiculos_por_linha_ajax.php', type: 'GET', 
                data: { linha_id: linhaId }, dataType: 'json',
                success: function(response) {
                    $veiculoSelectAjaxDiaria.prop('disabled', false).empty();
                    if (response.success && response.veiculos && response.veiculos.length > 0) {
                        $veiculoSelectAjaxDiaria.append('<option value="">Selecione um veículo...</option>');
                        let veiculoAtualEncontrado = false;
                        $.each(response.veiculos, function(index, veiculo) {
                            const selected = (String(veiculo.id) === String(veiculoIdAtualDiariaPhp));
                            $veiculoSelectAjaxDiaria.append($('<option>', { value: veiculo.id, text: veiculo.text, selected: selected }));
                            if (selected) veiculoAtualEncontrado = true;
                        });
                        $veiculoFeedbackAjaxDiaria.removeClass('text-muted text-danger').addClass('text-success').text('Veículos carregados.');
                        if (<?php echo json_encode($modo_edicao_escala_php); ?> && veiculoIdAtualDiariaPhp && !veiculoAtualEncontrado && veiculoPrefixoAtualDiariaPhp) {
                            $veiculoSelectAjaxDiaria.append($('<option>', { value: veiculoIdAtualDiariaPhp, text: veiculoPrefixoAtualDiariaPhp + ' (Salvo - Verificar compatibilidade)', selected: true, style: 'color:orange;' }));
                            $veiculoFeedbackAjaxDiaria.append(' <span class="text-warning">Atenção: O veículo salvo pode não ser mais compatível.</span>');
                        }
                    } else {
                        $veiculoSelectAjaxDiaria.append('<option value="">Nenhum veículo compatível</option>');
                        $veiculoFeedbackAjaxDiaria.removeClass('text-muted text-success').addClass('text-danger').text(response.message || 'Nenhum veículo compatível.');
                        if (<?php echo json_encode($modo_edicao_escala_php); ?> && veiculoIdAtualDiariaPhp && veiculoPrefixoAtualDiariaPhp) {
                            $veiculoSelectAjaxDiaria.append($('<option>', { value: veiculoIdAtualDiariaPhp, text: veiculoPrefixoAtualDiariaPhp + ' (Salvo - Verificar compatibilidade)', selected: true, style: 'color:orange;' }));
                        }
                    }
                },
                error: function() {
                    $veiculoSelectAjaxDiaria.prop('disabled', false).html('<option value="">Erro ao carregar</option>');
                    $veiculoFeedbackAjaxDiaria.removeClass('text-muted text-success').addClass('text-danger').text('Erro ao buscar veículos.');
                }
            });
        } else {
            $veiculoSelectAjaxDiaria.prop('disabled', true);
            if (tipoEscalaAtual !== 'linha' || $statusCheckboxesForm.is(':checked')) {
                 $veiculoSelectAjaxDiaria.html('<option value="">Não aplicável</option>');
                 $veiculoFeedbackAjaxDiaria.text('');
            } else {
                 $veiculoSelectAjaxDiaria.html('<option value="">Selecione uma linha...</option>');
                 $veiculoFeedbackAjaxDiaria.text('Selecione uma linha para carregar os veículos.');
            }
        }
    }

    function carregarWorkIDsDisponiveisDiaria() { /* ... Lógica similar ao da Plan., mas com IDs "_diaria_form" ... */ 
        const linhaId = $linhaOrigemSelectDiariaWorkID.val(); 
        const dataEscala = $dataEscalaInputDiariaWorkID.val();
        const tipoEscalaAtual = $tipoEscalaSelectForm.val();

        if (tipoEscalaAtual === 'linha' && linhaId && dataEscala && !$statusCheckboxesForm.is(':checked')) {
            $workIdSelectDiariaForm.prop('disabled', true).html('<option value="">Carregando WorkIDs...</option>');
            $workIdSugestaoTextDiariaForm.removeClass('feedback-success feedback-error feedback-info').addClass('feedback-loading').html('<span><i class="fas fa-spinner fa-spin"></i> Buscando WorkIDs...</span>').show();
            
            $.ajax({
                url: 'buscar_workids_disponiveis_ajax.php',
                type: 'POST', data: { linha_id: linhaId, data_escala: dataEscala }, dataType: 'json',
                success: function(response) {
                    $workIdSelectDiariaForm.prop('disabled', false).empty();
                    let workIdEncontradoNaLista = false;
                    if (response.success && response.workids && response.workids.length > 0) {
                        $workIdSelectDiariaForm.append('<option value="">Selecione um WorkID...</option>');
                        $.each(response.workids, function(index, workid) {
                            const selected = (workid === valorOriginalWorkIdDiariaFormJs);
                            $workIdSelectDiariaForm.append($('<option>', { value: workid, text: workid, selected: selected }));
                            if (selected) workIdEncontradoNaLista = true;
                        });
                        $workIdSugestaoTextDiariaForm.removeClass('feedback-loading feedback-error feedback-info').addClass('feedback-success').html('<span><i class="fas fa-check-circle"></i> WorkIDs carregados.</span>').show();
                        if (valorOriginalWorkIdDiariaFormJs && !workIdEncontradoNaLista && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                             $workIdSelectDiariaForm.append($('<option>', { value: valorOriginalWorkIdDiariaFormJs, text: valorOriginalWorkIdDiariaFormJs + ' (Salvo)', selected: true }));
                             $workIdSugestaoTextDiariaForm.append(' <span>O WorkID salvo ('+valorOriginalWorkIdDiariaFormJs+') foi mantido.</span>');
                        }
                    } else {
                        $workIdSelectDiariaForm.append('<option value="">Nenhum WorkID encontrado</option>');
                        $workIdSugestaoTextDiariaForm.removeClass('feedback-loading feedback-success feedback-info').addClass('feedback-error').html('<span><i class="fas fa-exclamation-triangle"></i> '+(response.message || 'Nenhum WorkID compatível.')+'</span>').show();
                         if (valorOriginalWorkIdDiariaFormJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                            $workIdSelectDiariaForm.append($('<option>', { value: valorOriginalWorkIdDiariaFormJs, text: valorOriginalWorkIdDiariaFormJs + ' (Salvo)', selected: true }));
                            $workIdSugestaoTextDiariaForm.append(' <span>O WorkID salvo ('+valorOriginalWorkIdDiariaFormJs+') foi mantido.</span>');
                        }
                    }
                },
                error: function() { /* ... */ }
            });
        } else if (tipoEscalaAtual === 'linha' && !$statusCheckboxesForm.is(':checked')) {
            $workIdSelectDiariaForm.html('<option value="">Selecione Linha e Data...</option>');
            $workIdSugestaoTextDiariaForm.removeClass('feedback-success feedback-error feedback-loading').addClass('feedback-info').html('<span><i class="fas fa-info-circle"></i> Selecione Linha e Data.</span>').show();
             if (valorOriginalWorkIdDiariaFormJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                 if ($workIdSelectDiariaForm.find("option[value='" + valorOriginalWorkIdDiariaFormJs + "']").length === 0) {
                    $workIdSelectDiariaForm.append($('<option>', { value: valorOriginalWorkIdDiariaFormJs, text: valorOriginalWorkIdDiariaFormJs + ' (Salvo)', selected: true }));
                } else { $workIdSelectDiariaForm.val(valorOriginalWorkIdDiariaFormJs); }
            }
        } else if (tipoEscalaAtual !== 'linha'){ $workIdSelectDiariaForm.empty().append('<option value="">Não aplicável</option>'); }
    }
    function atualizarVisibilidadeCamposForm() { /* ... Lógica similar ao da Plan., mas com IDs "_diaria_form" ... */
        const tipoSelecionado = $tipoEscalaSelectForm.val();
        let algumStatusMarcado = $statusCheckboxesForm.is(':checked');
        
        $workIdInputDiariaForm.attr('name', 'work_id_text_input_diaria_disabled').hide();
        $workIdSelectDiariaForm.attr('name', 'work_id_select_input_diaria_disabled').hide();
        $veiculoSelectAjaxDiaria.prop('required', false); $veiculoObrigatorioAsteriscoDiaria.hide();

        $('#linha_origem_id_diaria_form, #funcao_operacional_id_select_diaria_form, #turno_funcao_select_diaria_form, #posicao_letra_funcao_select_diaria_form, #local_inicio_turno_id_diaria_form, #local_fim_turno_id_diaria_form, #hora_inicio_prevista_diaria_form, #hora_fim_prevista_diaria_form').prop('required', false);
        $workIdInputDiariaForm.prop('required', false).prop('readonly', false);
        $workIdSelectDiariaForm.prop('required', false);

        if (algumStatusMarcado) {
            $tipoEscalaSelectForm.prop('disabled', true).val('linha').trigger('change');
            $camposLinhaWrapperForm.hide(); $todosCamposFuncaoWrapperForm.hide(); $camposComunsWrapperForm.hide();
            let valorWorkIdParaStatus = '';
            $statusCheckboxesForm.each(function() { if ($(this).is(':checked')) { valorWorkIdParaStatus = $(this).val(); return false; }});
            $workIdInputDiariaForm.val(valorWorkIdParaStatus).show().prop('readonly', true).prop('required', true).attr('name', 'work_id');
            $workIdSugestaoTextDiariaForm.removeClass('feedback-loading feedback-success feedback-error feedback-info').addClass('feedback-secondary-text').html('<span>WorkID definido pelo status.</span>').show();
            $veiculoSelectAjaxDiaria.prop('disabled', true).val(null).trigger('change');
            $veiculoFeedbackAjaxDiaria.text(''); $veiculoObrigatorioAsteriscoDiaria.hide();
        } else {
            $tipoEscalaSelectForm.prop('disabled', false); $camposComunsWrapperForm.show();
            $camposComunsWrapperForm.find('select.select2-simple-diaria:not(#motorista_id_select2_escala_diaria_form), input[type="time"], #tabela_escalas_diaria_form, #eh_extra_diaria_form').prop('disabled', false);
            $('#hora_inicio_prevista_diaria_form, #hora_fim_prevista_diaria_form, #local_inicio_turno_id_diaria_form, #local_fim_turno_id_diaria_form').prop('required', true);
            if (tipoSelecionado === 'linha') {
                $camposLinhaWrapperForm.show(); $todosCamposFuncaoWrapperForm.hide();
                $funcaoSelectForm.val(null).trigger('change'); $('#linha_origem_id_diaria_form').prop('required', true);
                $workIdSelectDiariaForm.show().prop('required', true).attr('name', 'work_id');
                $tabelaEscalasWrapperForm.show();
                $veiculoSelectAjaxDiaria.prop('disabled', false).prop('required', true); $veiculoObrigatorioAsteriscoDiaria.show();
                // carregarVeiculosCompativelComLinhaDiaria(); // Chamado pelos listeners
            } else if (tipoSelecionado === 'funcao') {
                $camposLinhaWrapperForm.hide(); $todosCamposFuncaoWrapperForm.show();
                $('#linha_origem_id_diaria_form, #veiculo_id_ajax_diaria_form').val(null).trigger('change');
                $funcaoSelectForm.prop('required', true); $turnoFuncaoSelectForm.prop('required', true);
                $workIdInputDiariaForm.show().prop('required', true).prop('readonly', false).attr('name', 'work_id');
                $tabelaEscalasWrapperForm.hide(); $('#tabela_escalas_diaria_form').val('');
                $workIdInputDiariaForm.prop('placeholder', 'WorkID será sugerido');
                $veiculoSelectAjaxDiaria.prop('disabled', true).val(null).trigger('change');
                $veiculoFeedbackAjaxDiaria.text('Veículo não aplicável para função.'); $veiculoObrigatorioAsteriscoDiaria.hide();
                atualizarCamposFuncaoFormDiaria(); montarWorkIDSugeridoFormDiaria();
            }
            const workIdAtualUpper = $workIdInputDiariaForm.val().toUpperCase();
            const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
            if (statusEspeciaisForm.includes(workIdAtualUpper)) { 
                if (valorOriginalWorkIdDiariaFormJs && !statusEspeciaisForm.includes(valorOriginalWorkIdDiariaFormJs.toUpperCase())) {
                    if(tipoSelecionado === 'linha') { /* O select de WorkID será tratado por carregarWorkIDsDisponiveisDiaria */ } 
                    else { $workIdInputDiariaForm.val(valorOriginalWorkIdDiariaFormJs); }
                } else { if(tipoSelecionado !== 'linha'){ $workIdInputDiariaForm.val(''); } }
            }
        }
    }
    function montarWorkIDSugeridoFormDiaria() { /* ... Lógica similar, mas com IDs "_diaria_form" ... */
        const tipoEscala = $tipoEscalaSelectForm.val();
        if (tipoEscala !== 'funcao' || $statusCheckboxesForm.is(':checked')) { 
            if (tipoEscala !== 'linha') { $workIdSugestaoTextDiariaForm.text('').hide(); } return; }
        const funcaoId = $funcaoSelectForm.val();
        if (!funcaoId || !funcoesOperacionaisDataDiaria[funcaoId]) { $workIdSugestaoTextDiariaForm.text('').hide(); return; }
        const funcaoData = funcoesOperacionaisDataDiaria[funcaoId]; const prefixo = funcaoData.work_id_prefixo; const turno = $turnoFuncaoSelectForm.val();
        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        const posicao = $posicaoLetraSelectForm.val(); let sugestao = '';
        if (prefixo && turno) {
            sugestao = prefixo;
            if (!funcaoData.local_fixo_id && $localInicioSelectForm.val()) {
                let nomeLocalCompleto = $localInicioSelectForm.find('option:selected').text(); let nomeLocalCurto = '';
                if(nomeLocalCompleto && nomeLocalCompleto.trim().toLowerCase() !== 'selecione...' && nomeLocalCompleto.trim() !== ''){
                    let partesNomeLocal = nomeLocalCompleto.split(' ');
                    if (partesNomeLocal.length > 1 && partesNomeLocal[0].toUpperCase() === 'T.') { nomeLocalCurto = "T" + (partesNomeLocal[1] ? partesNomeLocal[1].substring(0,1).toUpperCase() : '');
                    } else { nomeLocalCurto = nomeLocalCompleto.substring(0,3).toUpperCase().replace(/[^A-Z0-9]/g, '');}
                    if(nomeLocalCurto) sugestao += '-' + nomeLocalCurto;
                }
            }
            sugestao += '-' + turno; if (requerPosicao && posicao) { sugestao += posicao.toUpperCase(); }
            $workIdInputDiariaForm.val(sugestao); 
            $workIdSugestaoTextDiariaForm.removeClass('feedback-loading feedback-error feedback-info').addClass('feedback-secondary-text').html('<span>WorkID Sugerido: ' + sugestao + '</span>').show();
        } else { $workIdSugestaoTextDiariaForm.text('').hide(); }
    }
    function atualizarCamposFuncaoFormDiaria(dadosCopia = null) { /* ... Lógica similar, mas com IDs "_diaria_form" ... */
        const funcaoId = $funcaoSelectForm.val();
        let turnoParaSetar = dadosCopia ? dadosCopia.turno_funcao_detectado : <?php echo json_encode($turno_funcao_form_php); ?>;
        let posicaoParaSetar = dadosCopia ? dadosCopia.posicao_letra_detectada : <?php echo json_encode($posicao_letra_form_php); ?>;
        let localInicioParaSetar = dadosCopia ? dadosCopia.localInicio : <?php echo json_encode($local_inicio_id_form_php); ?>;
        let localFimParaSetar = dadosCopia ? dadosCopia.localFim : <?php echo json_encode($local_fim_id_form_php); ?>;
        $posicaoLetraWrapperForm.hide(); $posicaoLetraSelectForm.prop('required', false).val('');
        if (!funcaoId || !funcoesOperacionaisDataDiaria[funcaoId]) {
            $turnoFuncaoSelectForm.html('<option value="">Selecione a função...</option>').prop('disabled', true).val('');
            filtrarLocaisDiaria(null, 'qualquer', null, localInicioParaSetar, localFimParaSetar);
            $localInicioSelectForm.prop('disabled', false).prop('required',true); $localFimSelectForm.prop('disabled', false).prop('required',true);
            montarWorkIDSugeridoFormDiaria(); return;
        }
        const funcaoData = funcoesOperacionaisDataDiaria[funcaoId];
        const turnosArray = funcaoData.turnos_disponiveis ? String(funcaoData.turnos_disponiveis).split(',') : [];
        $turnoFuncaoSelectForm.html('<option value="">Selecione o turno...</option>');
        const turnoNomes = {'01': 'Manhã', '02': 'Tarde', '03': 'Noite'};
        turnosArray.forEach(function(turno) { $turnoFuncaoSelectForm.append(new Option(turnoNomes[turno.trim()] || 'Turno ' + turno.trim(), turno.trim())); });
        $turnoFuncaoSelectForm.prop('disabled', false).prop('required', true).val(turnoParaSetar).trigger('change');
        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        if (requerPosicao && funcaoData.max_posicoes_por_turno > 0) {
            $posicaoLetraSelectForm.html('<option value="">Selecione...</option>');
            for (let i = 0; i < funcaoData.max_posicoes_por_turno; i++) { let letra = String.fromCharCode(65 + i); $posicaoLetraSelectForm.append(new Option(letra, letra)); }
            $posicaoLetraWrapperForm.show(); $posicaoLetraSelectForm.prop('required', true).val(posicaoParaSetar).trigger('change');
        }
        filtrarLocaisDiaria(funcaoData.local_fixo_id, funcaoData.locais_permitidos_tipo, funcaoData.locais_permitidos_ids, localInicioParaSetar, localFimParaSetar);
        if (funcaoData.local_fixo_id) { $localInicioSelectForm.prop('required', false); $localFimSelectForm.prop('required', false);
        } else { $localInicioSelectForm.prop('disabled', false).prop('required', true); $localFimSelectForm.prop('disabled', false).prop('required', true); }
        montarWorkIDSugeridoFormDiaria();
    }
    function filtrarLocaisDiaria(localFixoId, tipoPermitido, idsPermitidosStr, valorPreselecaoInicio = null, valorPreselecaoFim = null) { /* ... Lógica similar, mas com IDs "_diaria_form" ... */
        const idsPermitidos = idsPermitidosStr ? String(idsPermitidosStr).split(',').map(id => String(id).trim()) : [];
        let valorSelecionarInicio = valorPreselecaoInicio !== null ? valorPreselecaoInicio : $localInicioSelectForm.val();
        let valorSelecionarFim = valorPreselecaoFim !== null ? valorPreselecaoFim : $localFimSelectForm.val();
        $localInicioSelectForm.html('<option value=""></option>'); $localFimSelectForm.html('<option value=""></option>');   
        todosOsLocaisDataDiaria.forEach(function(local) {
            let incluirLocal = false;
            if (localFixoId && String(local.id) === String(localFixoId)) { incluirLocal = true; valorSelecionarInicio = local.id; valorSelecionarFim = local.id;
            } else if (!localFixoId && tipoPermitido && tipoPermitido.toLowerCase() !== 'qualquer' && tipoPermitido.toLowerCase() !== 'nenhum') {
                if (local.tipo === tipoPermitido.toLowerCase()) { if (idsPermitidos.length > 0) { if (idsPermitidos.includes(String(local.id))) incluirLocal = true; }  else { incluirLocal = true; } }
            } else if (!localFixoId && (!tipoPermitido || tipoPermitido.toLowerCase() === 'qualquer' || tipoPermitido.toLowerCase() === 'nenhum')) { incluirLocal = true; }
            if (incluirLocal) { $localInicioSelectForm.append(new Option(local.text, local.id)); $localFimSelectForm.append(new Option(local.text, local.id)); }
        });
        $localInicioSelectForm.val(valorSelecionarInicio).trigger('change.select2'); $localFimSelectForm.val(valorSelecionarFim).trigger('change.select2');
        if (localFixoId) { $localInicioSelectForm.prop('disabled', true); $localFimSelectForm.prop('disabled', true);
        } else { $localInicioSelectForm.prop('disabled', false); $localFimSelectForm.prop('disabled', false); }
    }


    // --- Event Listeners (com sufixo "_diaria_form") ---
    $tipoEscalaSelectForm.on('change', function() {
        let currentWorkId = "";
        if($statusCheckboxesForm.is(':checked')) { currentWorkId = $workIdInputDiariaForm.val(); } 
        else { currentWorkId = ($tipoEscalaSelectForm.val() === 'funcao') ? $workIdInputDiariaForm.val() : $workIdSelectDiariaForm.val(); }
        const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        if(currentWorkId && !statusEspeciaisForm.includes(currentWorkId.toUpperCase())){ valorOriginalWorkIdDiariaFormJs = currentWorkId; }
        
        atualizarVisibilidadeCamposForm();
        if ($tipoEscalaSelectForm.val() === 'linha' && !$statusCheckboxesForm.is(':checked')) { 
            carregarWorkIDsDisponiveisDiaria(); 
            carregarVeiculosCompativelComLinhaDiaria();
        }
    });

    $funcaoSelectForm.on('change', function(){ $turnoFuncaoSelectForm.val(null).trigger('change'); $posicaoLetraSelectForm.val(null).trigger('change'); atualizarCamposFuncaoFormDiaria(); });
    $turnoFuncaoSelectForm.on('change', montarWorkIDSugeridoFormDiaria);
    $posicaoLetraSelectForm.on('change', montarWorkIDSugeridoFormDiaria);
    $localInicioSelectForm.on('change', montarWorkIDSugeridoFormDiaria);

    $statusCheckboxesForm.on('change', function() {
        const $checkboxAtual = $(this);
        const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        let currentWorkIdVal = $tipoEscalaSelectForm.val() === 'linha' && !$checkboxAtual.is(':checked') ? $workIdSelectDiariaForm.val() : $workIdInputDiariaForm.val();
        if ($checkboxAtual.is(':checked')) {
            if (currentWorkIdVal && !statusEspeciaisForm.includes(currentWorkIdVal.toUpperCase())) { valorOriginalWorkIdDiariaFormJs = currentWorkIdVal; }
            $statusCheckboxesForm.not($checkboxAtual).prop('checked', false);
        }
        atualizarVisibilidadeCamposForm();
        if ($tipoEscalaSelectForm.val() === 'linha' && !$statusCheckboxesForm.is(':checked')) {
            carregarWorkIDsDisponiveisDiaria();
            carregarVeiculosCompativelComLinhaDiaria();
        }
    });
    
    $linhaOrigemSelectDiariaWorkID.on('change', function() { carregarWorkIDsDisponiveisDiaria(); carregarVeiculosCompativelComLinhaDiaria(); });
    $dataEscalaInputDiariaWorkID.on('change', function() {
        var novaData = $(this).val();
        if (novaData) { var dateObj = new Date(novaData + 'T00:00:00'); $('#data_copia_planejada_display_diaria').val(dateObj.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }));
        } else { $('#data_copia_planejada_display_diaria').val(''); }
        if ($tipoEscalaSelectForm.val() === 'linha' && !$statusCheckboxesForm.is(':checked')) { carregarWorkIDsDisponiveisDiaria(); carregarVeiculosCompativelComLinhaDiaria(); }
    });

    // --- Lógica de Cópia da Planejada (Adaptada) ---
    $('#btnCopiarDaPlanejadaDiaria').on('click', function() {
        var motoristaIdParaCopia = $('#motorista_id_select2_escala_diaria_form').val(); 
        var dataParaCopia = $('#data_escala_diaria_form').val(); 
        var $feedbackDivCopiaDiaria = $('#copiar_planejada_feedback_diaria');
        // ... (lógica AJAX e de preenchimento similar à da Escala Planejada, adaptando os IDs e variáveis JS)
        if (!motoristaIdParaCopia || !dataParaCopia) { $feedbackDivCopiaDiaria.html('<small class="text-danger">Motorista e Data devem estar preenchidos.</small>'); return; }
        $feedbackDivCopiaDiaria.html('<small class="text-info"><i class="fas fa-spinner fa-spin"></i> Buscando da Planejada...</small>');
        $.ajax({
            url: 'buscar_escala_para_copia_ajax.php', type: 'GET', dataType: 'json',
            data: { motorista_id: motoristaIdParaCopia, data_escala: dataParaCopia }, // Busca na Planejada
            success: function(response) {
                if (response.success && response.escala) {
                    var esc = response.escala;
                    $statusCheckboxesForm.prop('checked', false); 
                    valorOriginalWorkIdDiariaFormJs = esc.work_id || '';
                    window.veiculoIdAtualDiariaPhp = esc.veiculo_id || ''; 
                    window.veiculoPrefixoAtualDiariaPhp = esc.prefixo_veiculo_atual || '';

                    var workIdCopiadoUpper = (esc.work_id || '').toUpperCase();
                    const statusEspeciaisCopia = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
                    let copiouStatusEspecial = false;
                    if (statusEspeciaisCopia.includes(workIdCopiadoUpper)) {
                        $('#is_' + workIdCopiadoUpper.toLowerCase() + '_check_diaria_form').prop('checked', true);
                        copiouStatusEspecial = true;
                    }
                    let tipoEscalaCopiada = 'linha';
                    if (esc.funcao_operacional_id) tipoEscalaCopiada = 'funcao';
                    else if (!esc.linha_origem_id && !copiouStatusEspecial && esc.work_id) { /* ... inferir função ... */ }
                    $tipoEscalaSelectForm.val(tipoEscalaCopiada).trigger('change');
                    setTimeout(function() {
                        var dadosParaFuncaoCopiaDiaria = null;
                        if (tipoEscalaCopiada === 'funcao') {
                            dadosParaFuncaoCopiaDiaria = { turno_funcao_detectado: esc.turno_funcao_detectado || '', posicao_letra_detectada: esc.posicao_letra_detectada || '', localInicio: esc.local_inicio_turno_id, localFim: esc.local_fim_turno_id };
                            $funcaoSelectForm.val(esc.funcao_operacional_id || null).trigger('change');
                            atualizarCamposFuncaoFormDiaria(dadosParaFuncaoCopiaDiaria);
                            $('#work_id_input_diaria_form').val(valorOriginalWorkIdDiariaFormJs);
                        } else { 
                            $('#linha_origem_id_diaria_form').val(esc.linha_origem_id || null).trigger('change');
                            if (!copiouStatusEspecial) $('#tabela_escalas_diaria_form').val(esc.tabela_escalas || '');
                        }
                        $('#hora_inicio_prevista_diaria_form').val(esc.hora_inicio_prevista || '');
                        if (!(tipoEscalaCopiada === 'funcao' && funcoesOperacionaisDataDiaria[esc.funcao_operacional_id] && funcoesOperacionaisDataDiaria[esc.funcao_operacional_id].local_fixo_id)) {
                            $('#local_inicio_turno_id_diaria_form').val(esc.local_inicio_turno_id || null).trigger('change');
                            $('#local_fim_turno_id_diaria_form').val(esc.local_fim_turno_id || null).trigger('change');
                        }
                        $('#hora_fim_prevista_diaria_form').val(esc.hora_fim_prevista || '');
                        $('#eh_extra_diaria_form').prop('checked', esc.eh_extra == 1);
                        $('#observacoes_ajuste_diaria_form').val('Copiado da Escala Planejada.'); 
                        if (copiouStatusEspecial) { $statusCheckboxesForm.filter(':checked').trigger('change'); }
                         else { atualizarVisibilidadeCamposForm(); }
                        $feedbackDivCopiaDiaria.html('<small class="text-success"><i class="fas fa-check"></i> Dados preenchidos. Ajuste e salve.</small>');
                    }, 450);
                } else { $feedbackDivCopiaDiaria.html('<small class="text-warning">' + (response.message || 'Nenhuma escala planejada encontrada.') + '</small>'); }
            }, error: function() { $feedbackDivCopiaDiaria.html('<small class="text-danger">Erro ao buscar dados da planejada.</small>');}
        });
    });

    // --- Validação de Submit (Adaptada) ---
    $('#form-escala-diaria').on('submit', function(e) {
        var isStatusChecked = $statusCheckboxesForm.is(':checked');
        if (!isStatusChecked) {
            const tipoEscalaAtualSubmit = $tipoEscalaSelectForm.val();
            if (tipoEscalaAtualSubmit === 'linha' && (!$('#linha_origem_id_diaria_form').val() )) { alert('Linha é obrigatória.'); e.preventDefault(); return false; }
            // ... (outras validações de função, workid, horas, locais, e VEÍCULO para linha)
            if (tipoEscalaAtualSubmit === 'linha' && (!$veiculoSelectAjaxDiaria.val() || $veiculoSelectAjaxDiaria.val() === "") ) {
                alert('O campo Veículo é obrigatório para escala de linha na Diária.');
                if ($veiculoSelectAjaxDiaria.data('select2')) { $veiculoSelectAjaxDiaria.select2('open'); } else { $veiculoSelectAjaxDiaria.focus(); }
                e.preventDefault(); return false;
            }
        }
        // A lógica de name no atualizarVisibilidadeCamposForm garante o envio do WorkID correto
    });

    // --- Chamadas Iniciais (Adaptadas) ---
    atualizarVisibilidadeCamposForm(); 
    if (<?php echo json_encode($modo_edicao_escala_php); ?>) {
        if ($tipoEscalaSelectForm.val() === 'funcao' && $funcaoSelectForm.val()) {
             atualizarCamposFuncaoFormDiaria();
        }
        if ($tipoEscalaSelectForm.val() === 'linha' && $linhaOrigemSelectDiariaWorkID.val() && $dataEscalaInputDiariaWorkID.val() && !$statusCheckboxesForm.is(':checked')) {
             carregarWorkIDsDisponiveisDiaria();
             carregarVeiculosCompativelComLinhaDiaria();
        }
    }
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once 'admin_footer.php';
?>