<?php
// admin/escala_diaria_formulario.php
// ATUALIZADO para incluir Tipo de Escala (Linha/Função) e campos relacionados.

require_once 'auth_check.php';

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
$tipo_escala_form_php = 'linha'; // Padrão
$data_escala_form_php = isset($_GET['data_escala']) ? htmlspecialchars($_GET['data_escala']) : date('Y-m-d');
$motorista_id_form_php = '';
$motorista_texto_repop_php = ''; // Para repopular Select2

$funcao_operacional_id_form_php = '';
$turno_funcao_form_php = '';
$posicao_letra_form_php = '';

$work_id_form_php = '';
$tabela_escalas_form_php = '';
$linha_origem_id_form_php = '';
$veiculo_id_form_php = '';

$hora_inicio_form_php = '';
$local_inicio_id_form_php = '';
$hora_fim_form_php = '';
$local_fim_id_form_php = '';
$eh_extra_form_php = 0;
$observacoes_ajuste_form_php = '';

$is_folga_check_php = false;
$is_falta_check_php = false;
$is_fora_escala_check_php = false;
$is_ferias_check_php = false;
$is_atestado_check_php = false;

$modo_edicao_escala_php = false;

// --- Carregar Listas para Selects ---
$lista_linhas_select_php = [];
$lista_locais_select_php = [];
$lista_veiculos_select_php = [];
$lista_funcoes_operacionais_php = [];

if ($pdo) {
    try {
        $stmt_linhas_all = $pdo->query("SELECT id, numero, nome FROM linhas ORDER BY CAST(numero AS UNSIGNED), numero, nome ASC");
        $lista_linhas_select_php = $stmt_linhas_all->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt_locais_all = $pdo->query("SELECT id, nome, tipo FROM locais ORDER BY nome ASC"); // Adicionado tipo para JS
        $lista_locais_select_php = $stmt_locais_all->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt_veiculos_all = $pdo->query("SELECT id, prefixo FROM veiculos ORDER BY prefixo ASC");
        if($stmt_veiculos_all) $lista_veiculos_select_php = $stmt_veiculos_all->fetchAll(PDO::FETCH_ASSOC);

        $stmt_funcoes = $pdo->query("SELECT id, nome_funcao, work_id_prefixo, locais_permitidos_tipo, locais_permitidos_ids, local_fixo_id, turnos_disponiveis, requer_posicao_especifica, max_posicoes_por_turno, ignorar_validacao_jornada FROM funcoes_operacionais WHERE status = 'ativa' ORDER BY nome_funcao ASC");
        $lista_funcoes_operacionais_php = $stmt_funcoes->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Erro ao buscar dados (linhas/locais/veiculos/funções) para formulário de escala diária: " . $e->getMessage());
        $_SESSION['admin_warning_message'] = "Atenção: Erro ao carregar algumas opções de seleção.";
    }
}

// --- Lógica para Modo de Edição ---
if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $escala_diaria_id_edit = (int)$_GET['id'];
    $modo_edicao_escala_php = true;
    $page_title_action = 'Editar Entrada da Escala Diária';

    if ($pdo) {
        try {
            $stmt_get_escala = $pdo->prepare(
                "SELECT escd.*, mot.nome as nome_motorista_atual, mot.matricula as matricula_motorista_atual
                 FROM motorista_escalas_diaria escd
                 LEFT JOIN motoristas mot ON escd.motorista_id = mot.id
                 WHERE escd.id = :id_escala_diaria"
            );
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

                // Determinar tipo_escala com base em funcao_operacional_id
                if (!empty($funcao_operacional_id_form_php)) {
                    $tipo_escala_form_php = 'funcao';
                    // Tentar extrair turno e posição do work_id se for função
                    $funcao_obj_edit = null;
                    foreach($lista_funcoes_operacionais_php as $f){ if(strval($f['id'])===strval($funcao_operacional_id_form_php)){$funcao_obj_edit=$f;break;}}
                    if ($funcao_obj_edit && $work_id_form_php) {
                        $prefixo = $funcao_obj_edit['work_id_prefixo'];
                        $sem_prefixo = preg_replace('/^'.preg_quote($prefixo, '/').'-?/i', '', $work_id_form_php); // Remove prefixo
                        
                        // Tenta remover parte do local se não for fixo
                        // Esta lógica pode precisar de ajuste fino dependendo da complexidade dos nomes dos locais
                        if (!$funcao_obj_edit['local_fixo_id']) {
                            // Remove a parte do local que pode estar como '-XXX-' ou '-X-'
                            $sem_prefixo = preg_replace('/^[A-Z0-9]{1,3}-/i', '', $sem_prefixo);
                        }
                        
                        $partes_turno_pos = explode('-', $sem_prefixo); // O que sobrar, o último é turno ou turno+letra
                        $ultimo_segmento = array_pop($partes_turno_pos);

                        if($funcao_obj_edit['requer_posicao_especifica'] && strlen($ultimo_segmento) > 2 && ctype_alpha(substr($ultimo_segmento,-1))){
                            $posicao_letra_form_php = strtoupper(substr($ultimo_segmento,-1));
                            $turno_funcao_form_php = substr($ultimo_segmento,0,-1);
                        } elseif (strlen($ultimo_segmento) == 2 && ctype_digit($ultimo_segmento)){
                           $turno_funcao_form_php = $ultimo_segmento;
                           $posicao_letra_form_php = ''; // Garante que está vazio se não aplicável
                        }
                    }
                } else {
                    $tipo_escala_form_php = 'linha';
                }
                
                $observacoes_ajuste_form_php = $escala_db['observacoes_ajuste'];

                $work_id_upper = strtoupper($work_id_form_php ?? '');
                $is_folga_check_php       = ($work_id_upper === 'FOLGA');
                $is_falta_check_php       = ($work_id_upper === 'FALTA');
                $is_fora_escala_check_php = ($work_id_upper === 'FORADEESCALA');
                $is_ferias_check_php      = ($work_id_upper === 'FÉRIAS');
                $is_atestado_check_php    = ($work_id_upper === 'ATESTADO');
                $is_status_especial       = $is_folga_check_php || $is_falta_check_php || $is_fora_escala_check_php || $is_ferias_check_php || $is_atestado_check_php;

                $tabela_escalas_form_php = ($is_status_especial || $tipo_escala_form_php === 'funcao') ? '' : $escala_db['tabela_escalas'];
                $linha_origem_id_form_php = ($is_status_especial || $tipo_escala_form_php === 'funcao') ? '' : $escala_db['linha_origem_id'];
                $veiculo_id_form_php = ($is_status_especial || $tipo_escala_form_php === 'funcao') ? '' : $escala_db['veiculo_id'];
                
                $hora_inicio_form_php = $is_status_especial ? '' : ($escala_db['hora_inicio_prevista'] ? date('H:i', strtotime($escala_db['hora_inicio_prevista'])) : '');
                $local_inicio_id_form_php = $is_status_especial ? '' : $escala_db['local_inicio_turno_id'];
                $hora_fim_form_php = $is_status_especial ? '' : ($escala_db['hora_fim_prevista'] ? date('H:i', strtotime($escala_db['hora_fim_prevista'])) : '');
                $local_fim_id_form_php = $is_status_especial ? '' : $escala_db['local_fim_turno_id'];
                $eh_extra_form_php = $is_status_especial ? 0 : $escala_db['eh_extra'];
                
                $page_title_action .= ' (' . $motorista_texto_repop_php . ' em ' . date('d/m/Y', strtotime($data_escala_form_php)) . ')';
            } else { /* ... erro motorista não encontrado ... */ }
        } catch (PDOException $e) { /* ... erro PDO ... */ }
    }
}

$page_title = $page_title_action; // Define o título da página para o admin_header.php
require_once 'admin_header.php';

// --- Repopulação do Formulário em Caso de Erro de Validação ---
$form_data_repop_session = $_SESSION['form_data_escala_diaria'] ?? [];
if(!empty($form_data_repop_session)) {
    $tipo_escala_form_php = $form_data_repop_session['tipo_escala'] ?? $tipo_escala_form_php;
    $data_escala_form_php = $form_data_repop_session['data_escala'] ?? $data_escala_form_php;
    $motorista_id_form_php = $form_data_repop_session['motorista_id'] ?? $motorista_id_form_php;
    if ($motorista_id_form_php && empty($motorista_texto_repop_php) && $pdo) {
        try { /* ... lógica para buscar nome/matrícula do motorista para repopular Select2 ... */ } catch (PDOException $e_repop_d) {}
    }
    $funcao_operacional_id_form_php = $form_data_repop_session['funcao_operacional_id'] ?? $funcao_operacional_id_form_php;
    $turno_funcao_form_php = $form_data_repop_session['turno_funcao'] ?? $turno_funcao_form_php;
    $posicao_letra_form_php = $form_data_repop_session['posicao_letra_funcao'] ?? $posicao_letra_form_php;

    $is_folga_check_php = isset($form_data_repop_session['is_folga_check']);
    $is_falta_check_php = isset($form_data_repop_session['is_falta_check']);
    $is_fora_escala_check_php = isset($form_data_repop_session['is_fora_escala_check']);
    $is_ferias_check_php = isset($form_data_repop_session['is_ferias_check']);
    $is_atestado_check_php = isset($form_data_repop_session['is_atestado_check']);
    $work_id_repop_val = $form_data_repop_session['work_id'] ?? $work_id_form_php;

    if ($is_folga_check_php) $work_id_form_php = 'FOLGA';
    elseif ($is_falta_check_php) $work_id_form_php = 'FALTA';
    elseif ($is_fora_escala_check_php) $work_id_form_php = 'FORADEESCALA';
    elseif ($is_ferias_check_php) $work_id_form_php = 'FÉRIAS';
    elseif ($is_atestado_check_php) $work_id_form_php = 'ATESTADO';
    else $work_id_form_php = $work_id_repop_val;
    $is_status_especial_repop = $is_folga_check_php || $is_falta_check_php || $is_fora_escala_check_php || $is_ferias_check_php || $is_atestado_check_php;

    $tabela_escalas_form_php = ($is_status_especial_repop || $tipo_escala_form_php === 'funcao') ? '' : ($form_data_repop_session['tabela_escalas'] ?? $tabela_escalas_form_php);
    $linha_origem_id_form_php = ($is_status_especial_repop || $tipo_escala_form_php === 'funcao') ? '' : ($form_data_repop_session['linha_origem_id'] ?? $linha_origem_id_form_php);
    $veiculo_id_form_php = ($is_status_especial_repop || $tipo_escala_form_php === 'funcao') ? '' : ($form_data_repop_session['veiculo_id'] ?? $veiculo_id_form_php);
    
    $hora_inicio_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['hora_inicio_prevista'] ?? $hora_inicio_form_php);
    $local_inicio_id_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['local_inicio_turno_id'] ?? $local_inicio_id_form_php);
    $hora_fim_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['hora_fim_prevista'] ?? $hora_fim_form_php);
    $local_fim_id_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['local_fim_turno_id'] ?? $local_fim_id_form_php);
    $eh_extra_form_php = $is_status_especial_repop ? 0 : (isset($form_data_repop_session['eh_extra']) ? 1 : 0);
    $observacoes_ajuste_form_php = $form_data_repop_session['observacoes_ajuste'] ?? $observacoes_ajuste_form_php;
    unset($_SESSION['form_data_escala_diaria']);
}
$js_work_id_inicial_diaria_php = $work_id_form_php;
$js_funcoes_operacionais_data_diaria = []; foreach($lista_funcoes_operacionais_php as $func_d) { $js_funcoes_operacionais_data_diaria[$func_d['id']] = $func_d; }
$js_locais_data_todos_diaria = []; foreach ($lista_locais_select_php as $loc_d) { $js_locais_data_todos_diaria[] = ['id' => $loc_d['id'], 'text' => htmlspecialchars($loc_d['nome']), 'tipo' => strtolower($loc_d['tipo'] ?? '')]; }
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title_action); ?></h1>
    <a href="escala_diaria_consultar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Voltar para Consulta Diária
    </a>
</div>

<?php
if (isset($_SESSION['admin_form_error_escala_d'])) { echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . nl2br(htmlspecialchars($_SESSION['admin_form_error_escala_d'])) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_form_error_escala_d']); }
if (isset($_SESSION['admin_warning_message'])) { echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_warning_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_warning_message']); }
?>

<form action="escala_diaria_processa.php" method="POST" id="form-escala-diaria">
    <?php if ($modo_edicao_escala_php && $escala_diaria_id_edit): ?>
        <input type="hidden" name="escala_diaria_id" value="<?php echo $escala_diaria_id_edit; ?>">
    <?php endif; ?>
    <?php
    // Preservar parâmetros GET para o processamento
    $params_to_preserve_submit_d = ['pagina_original' => 'pagina', 'filtro_data_original' => 'data_escala', 'filtro_tipo_busca_original' => 'tipo_busca_adicional', 'filtro_valor_busca_original' => 'valor_busca_adicional'];
    foreach ($params_to_preserve_submit_d as $hidden_name_d => $get_key_d):
        if (isset($_GET[$get_key_d])): ?>
        <input type="hidden" name="<?php echo htmlspecialchars($hidden_name_d); ?>" value="<?php echo htmlspecialchars($_GET[$get_key_d]); ?>">
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
            <div class="form-group col-md-8"><label for="linha_origem_id_diaria_form">Linha de Origem <span class="text-danger">*</span></label><select class="form-control select2-simple-diaria" id="linha_origem_id_diaria_form" name="linha_origem_id" data-placeholder="Selecione..."><option value=""></option><?php foreach ($lista_linhas_select_php as $l_d):?><option value="<?php echo $l_d['id'];?>" <?php if(strval($l_d['id'])==strval($linha_origem_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($l_d['numero'].($l_d['nome']?' - '.$l_d['nome']:''));?></option><?php endforeach;?></select></div>
            <div class="form-group col-md-4"><label for="veiculo_id_diaria_form">Veículo (Opcional)</label><select class="form-control select2-simple-diaria" id="veiculo_id_diaria_form" name="veiculo_id" data-placeholder="Selecione..."><option value=""></option><?php foreach ($lista_veiculos_select_php as $v_d):?><option value="<?php echo $v_d['id'];?>" <?php if(strval($v_d['id'])==strval($veiculo_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($v_d['prefixo']);?></option><?php endforeach;?></select></div>
        </div>
    </div>

    <div id="todos_campos_funcao_wrapper_diaria_form" style="<?php echo ($tipo_escala_form_php !== 'funcao') ? 'display:none;' : ''; ?>">
        <div class="form-row">
            <div class="form-group col-md-12"><label for="funcao_operacional_id_select_diaria_form">Função Operacional <span class="text-danger">*</span></label><select class="form-control select2-simple-diaria" id="funcao_operacional_id_select_diaria_form" name="funcao_operacional_id" data-placeholder="Selecione..."><option value=""></option><?php foreach($lista_funcoes_operacionais_php as $fo_d):?><option value="<?php echo $fo_d['id'];?>" data-prefixo="<?php echo htmlspecialchars($fo_d['work_id_prefixo']);?>" data-locais-tipo="<?php echo htmlspecialchars($fo_d['locais_permitidos_tipo']??'');?>" data-locais-ids="<?php echo htmlspecialchars($fo_d['locais_permitidos_ids']??'');?>" data-local-fixo-id="<?php echo htmlspecialchars($fo_d['local_fixo_id']??'');?>" data-turnos="<?php echo htmlspecialchars($fo_d['turnos_disponiveis']);?>" data-requer-posicao="<?php echo $fo_d['requer_posicao_especifica']?'true':'false';?>" data-max-posicoes="<?php echo htmlspecialchars($fo_d['max_posicoes_por_turno']??'0');?>" data-ignora-jornada="<?php echo $fo_d['ignorar_validacao_jornada']?'true':'false';?>" <?php if(strval($fo_d['id'])==strval($funcao_operacional_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($fo_d['nome_funcao']);?></option><?php endforeach;?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4"><label for="turno_funcao_select_diaria_form">Turno da Função <span class="text-danger">*</span></label><select class="form-control" id="turno_funcao_select_diaria_form" name="turno_funcao"><option value="">Selecione...</option></select></div>
            <div class="form-group col-md-4" id="wrapper_posicao_letra_funcao_diaria_form" style="display:none;"><label for="posicao_letra_funcao_select_diaria_form">Posição/Letra <span class="text-danger">*</span></label><select class="form-control" id="posicao_letra_funcao_select_diaria_form" name="posicao_letra_funcao"><option value="">Selecione...</option></select></div>
        </div>
    </div>

    <div id="campos_comuns_escala_wrapper_diaria_form">
        <div class="form-row">
            <div class="form-group col-md-4"><label for="work_id_diaria_form">WorkID <span id="work_id_obrigatorio_asterisco_diaria_form" class="text-danger">*</span></label><input type="text" class="form-control" id="work_id_diaria_form" name="work_id" value="<?php echo htmlspecialchars($work_id_form_php); ?>" maxlength="50"><small class="form-text text-muted" id="work_id_sugestao_text_diaria_form"></small></div>
            <div class="form-group col-md-4" id="wrapper_tabela_escalas_diaria_form"><label for="tabela_escalas_diaria_form">Nº Tabela da Escala</label><input type="text" class="form-control" id="tabela_escalas_diaria_form" name="tabela_escalas" value="<?php echo htmlspecialchars($tabela_escalas_form_php); ?>" maxlength="10"></div>
            <div class="form-group col-md-4 d-flex align-items-center pt-3"><div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="eh_extra_diaria_form" name="eh_extra" <?php echo ($eh_extra_form_php==1)?'checked':'';?>><label class="form-check-label" for="eh_extra_diaria_form">Turno Extra?</label></div></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3"><label for="hora_inicio_prevista_diaria_form">Hora Início <span class="text-danger">*</span></label><input type="time" class="form-control" id="hora_inicio_prevista_diaria_form" name="hora_inicio_prevista" value="<?php echo htmlspecialchars($hora_inicio_form_php);?>"></div>
            <div class="form-group col-md-3"><label for="local_inicio_turno_id_diaria_form">Local Início <span class="text-danger">*</span></label><select class="form-control select2-simple-diaria" id="local_inicio_turno_id_diaria_form" name="local_inicio_turno_id" data-placeholder="Selecione..."><option value=""></option><?php foreach($lista_locais_select_php as $li_d):?><option value="<?php echo $li_d['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($li_d['tipo']??''));?>" <?php if(strval($li_d['id'])==strval($local_inicio_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($li_d['nome']);?></option><?php endforeach;?></select></div>
            <div class="form-group col-md-3"><label for="hora_fim_prevista_diaria_form">Hora Fim <span class="text-danger">*</span></label><input type="time" class="form-control" id="hora_fim_prevista_diaria_form" name="hora_fim_prevista" value="<?php echo htmlspecialchars($hora_fim_form_php);?>"></div>
            <div class="form-group col-md-3"><label for="local_fim_turno_id_diaria_form">Local Fim <span class="text-danger">*</span></label><select class="form-control select2-simple-diaria" id="local_fim_turno_id_diaria_form" name="local_fim_turno_id" data-placeholder="Selecione..."><option value=""></option><?php foreach($lista_locais_select_php as $lf_d):?><option value="<?php echo $lf_d['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($lf_d['tipo']??''));?>" <?php if(strval($lf_d['id'])==strval($local_fim_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($lf_d['nome']);?></option><?php endforeach;?></select></div>
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
ob_start();
?>
<script>
    // Passar dados PHP para JavaScript de forma segura
    const funcoesOperacionaisDataDiaria = <?php echo json_encode($js_funcoes_operacionais_data_diaria); ?>;
    const todosOsLocaisDataDiaria = <?php echo json_encode($js_locais_data_todos_diaria); ?>;
    var valorOriginalWorkIdDiariaFormJs = <?php echo json_encode($js_work_id_inicial_diaria_php); ?>; // Usado para restaurar WorkID ao desmarcar status

$(document).ready(function() {
    // --- INICIALIZAÇÃO DOS SELECT2 ---
    $('#motorista_id_select2_escala_diaria_form').select2({ // Select2 para o motorista principal
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
        var data = e.params.data; // Atualiza display do motorista na cópia da planejada
        $('#motorista_display_copia_planejada_diaria').val(data.text || 'Motorista ID: ' + data.id);
    });

    // Inicializa Select2 para os campos simples (linha, veiculo, locais, função)
    $('.select2-simple-diaria').each(function() {
        $(this).select2({ theme: 'bootstrap4', placeholder: $(this).data('placeholder') || 'Selecione...', allowClear: true, width: '100%' });
    });

    // Atualiza display da data na seção de cópia
    $('#data_escala_diaria_form').on('change', function() {
        var novaData = $(this).val();
        if (novaData) {
            // Adiciona T00:00:00 para evitar problemas de fuso horário na formatação local
            var dateObj = new Date(novaData + 'T00:00:00'); 
            $('#data_copia_planejada_display_diaria').val(dateObj.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }));
        } else {
            $('#data_copia_planejada_display_diaria').val('');
        }
    }).trigger('change'); // Dispara no carregamento

     // Atualiza o display do motorista na seção de cópia se já houver um valor no carregamento
    if ($('#motorista_id_select2_escala_diaria_form').find(':selected').length > 0 && 
        $('#motorista_id_select2_escala_diaria_form').find(':selected').text() !== '' &&
        $('#motorista_id_select2_escala_diaria_form').find(':selected').text() !== 'Digite nome ou matrícula...') {
        $('#motorista_display_copia_planejada_diaria').val($('#motorista_id_select2_escala_diaria_form').find(':selected').text());
    }


    // --- LÓGICA PARA CAMPOS CONDICIONAIS (TIPO DE ESCALA, FUNÇÃO, STATUS) ---
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
    const $workIdInputForm = $('#work_id_diaria_form');
    const $workIdSugestaoTextForm = $('#work_id_sugestao_text_diaria_form');
    const $camposComunsWrapperForm = $('#campos_comuns_escala_wrapper_diaria_form');
    const $statusCheckboxesForm = $('.status-escala-check-diaria-form');

    function atualizarVisibilidadeCamposForm() {
        const tipoSelecionado = $tipoEscalaSelectForm.val();
        let algumStatusMarcado = $statusCheckboxesForm.is(':checked');
        
        // Resetar 'required' e 'disabled' antes de aplicar novas regras
        // Habilitar todos os campos de trabalho por padrão antes de desabilitá-los se necessário
        $camposLinhaWrapperForm.find('select, input').prop('required', false).prop('disabled', false);
        $todosCamposFuncaoWrapperForm.find('select, input').prop('required', false).prop('disabled', false);
        $camposComunsWrapperForm.find('input, select').prop('disabled', false); // Habilita campos comuns
        
        // Resetar 'required' individualmente
        $('#linha_origem_id_diaria_form, #funcao_operacional_id_select_diaria_form, #turno_funcao_select_diaria_form, #posicao_letra_funcao_select_diaria_form, #local_inicio_turno_id_diaria_form, #local_fim_turno_id_diaria_form, #hora_inicio_prevista_diaria_form, #hora_fim_prevista_diaria_form, #work_id_diaria_form').prop('required', false);
        $tipoEscalaSelectForm.prop('disabled', false); // Habilita tipo de escala por padrão

        if (algumStatusMarcado) {
            $tipoEscalaSelectForm.prop('disabled', true); // DESABILITA Tipo de Escala

            $camposLinhaWrapperForm.hide();
            $todosCamposFuncaoWrapperForm.hide();
            $camposComunsWrapperForm.find('input[type="time"], #tabela_escalas_diaria_form').val('');
            // Ao desabilitar, garante que Select2 sejam resetados e desabilitados
            $camposComunsWrapperForm.find('select.select2-simple-diaria:not(#motorista_id_select2_escala_diaria_form)')
                                   .val(null).trigger('change').prop('disabled', true);
            $('#tipo_escala_select_diaria_form').prop('disabled',true); // Garante que tipo de escala seja desabilitado

            $camposComunsWrapperForm.find('#eh_extra_diaria_form').prop('checked', false).prop('disabled',true);
            // Desabilita campos comuns, exceto WorkID e Data (e Motorista que já é tratado pelo Select2)
            $camposComunsWrapperForm.find('input:not(#work_id_diaria_form, #data_escala_diaria_form)')
                                   .prop('disabled', true);
            // WorkID input é especial:
            $workIdInputForm.prop('disabled',false); // Mantém habilitado para receber o valor do status

            $tabelaEscalasWrapperForm.hide(); // Esconde wrapper da tabela
            let valorWorkIdParaStatus = '';
            $statusCheckboxesForm.each(function() { if ($(this).is(':checked')) { valorWorkIdParaStatus = $(this).val(); return false; }});
            $workIdInputForm.val(valorWorkIdParaStatus).prop('readonly', true).prop('required', true); // WorkID se torna readonly e obrigatório com o valor do status
            $workIdSugestaoTextForm.text('').hide();

        } else { // Nenhum status especial marcado, lógica normal para Linha/Função
            $tipoEscalaSelectForm.prop('disabled', false); // HABILITA Tipo de Escala
            // Campos comuns habilitados (já feito no reset geral no início da função)
            $workIdInputForm.prop('readonly', false).prop('required', true);
            $('#hora_inicio_prevista_diaria_form, #hora_fim_prevista_diaria_form, #local_inicio_turno_id_diaria_form, #local_fim_turno_id_diaria_form').prop('required', true); // Horas e locais são obrigatórios se não for status
            
            if (tipoSelecionado === 'linha') {
                $camposLinhaWrapperForm.show();
                $todosCamposFuncaoWrapperForm.hide();
                $funcaoSelectForm.val(null).trigger('change'); // Limpa função se estava selecionada
                $('#linha_origem_id_diaria_form').prop('required', true);
                $tabelaEscalasWrapperForm.show();
                $workIdSugestaoTextForm.text('').hide();
                $workIdInputForm.prop('placeholder', 'WorkID da Linha');
                filtrarLocaisDiaria(null, 'qualquer', null); 
            } else if (tipoSelecionado === 'funcao') {
                $camposLinhaWrapperForm.hide();
                $todosCamposFuncaoWrapperForm.show();
                $('#linha_origem_id_diaria_form, #veiculo_id_diaria_form').val(null).trigger('change');
                $funcaoSelectForm.prop('required', true);
                $turnoFuncaoSelectForm.prop('required', true); 
                $tabelaEscalasWrapperForm.hide();
                $('#tabela_escalas_diaria_form').val('');
                $workIdInputForm.prop('placeholder', 'WorkID será sugerido');
                atualizarCamposFuncaoForm(); 
            }
            // Restaurar WorkID se um status foi desmarcado
            const workIdAtualUpper = $workIdInputForm.val().toUpperCase();
            const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
            if (statusEspeciaisForm.includes(workIdAtualUpper)) { 
                if (valorOriginalWorkIdDiariaFormJs && !statusEspeciaisForm.includes(valorOriginalWorkIdDiariaFormJs.toUpperCase())) {
                    $workIdInputForm.val(valorOriginalWorkIdDiariaFormJs);
                } else { $workIdInputForm.val(''); }
            }
        }
    }

    function montarWorkIDSugeridoForm() {
        const tipoEscala = $tipoEscalaSelectForm.val();
        if (tipoEscala !== 'funcao' || $statusCheckboxesForm.is(':checked')) { $workIdSugestaoTextForm.text('').hide(); return;}
        
        const funcaoId = $funcaoSelectForm.val();
        if (!funcaoId || !funcoesOperacionaisDataDiaria[funcaoId]) { $workIdSugestaoTextForm.text('').hide(); return; }

        const funcaoData = funcoesOperacionaisDataDiaria[funcaoId];
        const prefixo = funcaoData.work_id_prefixo;
        const turno = $turnoFuncaoSelectForm.val();
        // Garante que requer_posicao_especifica seja tratado como booleano
        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        const posicao = $posicaoLetraSelectForm.val();
        let sugestao = '';

        if (prefixo && turno) {
            sugestao = prefixo;
            // Lógica para nome curto do local (se aplicável à função)
            if (!funcaoData.local_fixo_id && $localInicioSelectForm.val()) {
                let nomeLocalCompleto = $localInicioSelectForm.find('option:selected').text();
                let nomeLocalCurto = '';
                // Evita processar o placeholder
                if(nomeLocalCompleto && nomeLocalCompleto.trim().toLowerCase() !== 'selecione...' && nomeLocalCompleto.trim() !== ''){
                    let partesNomeLocal = nomeLocalCompleto.split(' ');
                    if (partesNomeLocal.length > 1 && partesNomeLocal[0].toUpperCase() === 'T.') { // Ex: "T. Acapulco" -> TA
                        nomeLocalCurto = "T" + (partesNomeLocal[1] ? partesNomeLocal[1].substring(0,1).toUpperCase() : '');
                    } else { // Pega os 3 primeiros caracteres e remove não alfanuméricos
                        nomeLocalCurto = nomeLocalCompleto.substring(0,3).toUpperCase().replace(/[^A-Z0-9]/g, '');
                    }
                    if(nomeLocalCurto) sugestao += '-' + nomeLocalCurto;
                }
            }
            sugestao += '-' + turno;
            if (requerPosicao && posicao) { sugestao += posicao.toUpperCase(); } // Garante que a letra da posição seja maiúscula
            
            $workIdInputForm.val(sugestao);
            $workIdSugestaoTextForm.text('WorkID Sugerido: ' + sugestao).show().removeClass('text-danger').addClass('text-muted');
        } else { 
            $workIdSugestaoTextForm.text('').hide(); 
        }
    }

    function atualizarCamposFuncaoForm(dadosCopia = null) {
        const funcaoId = $funcaoSelectForm.val();
        // Prioriza dados da cópia, senão usa os valores PHP (para edição/repopulação normal)
        let turnoParaSetar = dadosCopia ? dadosCopia.turno_funcao_detectado : <?php echo json_encode($turno_funcao_form_php); ?>;
        let posicaoParaSetar = dadosCopia ? dadosCopia.posicao_letra_detectada : <?php echo json_encode($posicao_letra_form_php); ?>;
        let localInicioParaSetar = dadosCopia ? dadosCopia.local_inicio_turno_id : <?php echo json_encode($local_inicio_id_form_php); ?>;
        let localFimParaSetar = dadosCopia ? dadosCopia.local_fim_turno_id : <?php echo json_encode($local_fim_id_form_php); ?>;


        $posicaoLetraWrapperForm.hide();
        $posicaoLetraSelectForm.prop('required', false).val('');

        if (!funcaoId || !funcoesOperacionaisDataDiaria[funcaoId]) {
            $turnoFuncaoSelectForm.html('<option value="">Selecione a função...</option>').prop('disabled', true).val('');
            filtrarLocaisDiaria(null, 'qualquer', null, localInicioParaSetar, localFimParaSetar);
            $localInicioSelectForm.prop('disabled', false).prop('required',true);
            $localFimSelectForm.prop('disabled', false).prop('required',true);
            montarWorkIDSugeridoForm();
            return;
        }

        const funcaoData = funcoesOperacionaisDataDiaria[funcaoId];
        const turnosArray = funcaoData.turnos_disponiveis ? String(funcaoData.turnos_disponiveis).split(',') : [];
        $turnoFuncaoSelectForm.html('<option value="">Selecione o turno...</option>');
        const turnoNomes = {'01': 'Manhã', '02': 'Tarde', '03': 'Noite'}; // Mapeamento de turnos
        turnosArray.forEach(function(turno) {
            $turnoFuncaoSelectForm.append(new Option(turnoNomes[turno.trim()] || 'Turno ' + turno.trim(), turno.trim()));
        });
        $turnoFuncaoSelectForm.prop('disabled', false).prop('required', true).val(turnoParaSetar).trigger('change');

        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        if (requerPosicao && funcaoData.max_posicoes_por_turno > 0) {
            $posicaoLetraSelectForm.html('<option value="">Selecione...</option>');
            for (let i = 0; i < funcaoData.max_posicoes_por_turno; i++) { let letra = String.fromCharCode(65 + i); $posicaoLetraSelectForm.append(new Option(letra, letra)); }
            $posicaoLetraWrapperForm.show();
            $posicaoLetraSelectForm.prop('required', true).val(posicaoParaSetar).trigger('change');
        }
        
        filtrarLocaisDiaria(funcaoData.local_fixo_id, funcaoData.locais_permitidos_tipo, funcaoData.locais_permitidos_ids, localInicioParaSetar, localFimParaSetar);

        if (funcaoData.local_fixo_id) {
            $localInicioSelectForm.prop('required', false); 
            $localFimSelectForm.prop('required', false);
        } else {
            $localInicioSelectForm.prop('disabled', false).prop('required', true);
            $localFimSelectForm.prop('disabled', false).prop('required', true);
        }
        montarWorkIDSugeridoForm();
    }

    function filtrarLocaisDiaria(localFixoId, tipoPermitido, idsPermitidosStr, valorPreselecaoInicio = null, valorPreselecaoFim = null) {
        const idsPermitidos = idsPermitidosStr ? String(idsPermitidosStr).split(',').map(id => String(id).trim()) : [];
        
        // Usa os valores de pré-seleção se fornecidos, senão os valores atuais dos selects
        let valorSelecionarInicio = valorPreselecaoInicio !== null ? valorPreselecaoInicio : $localInicioSelectForm.val();
        let valorSelecionarFim = valorPreselecaoFim !== null ? valorPreselecaoFim : $localFimSelectForm.val();

        $localInicioSelectForm.html('<option value=""></option>'); 
        $localFimSelectForm.html('<option value=""></option>');   

        todosOsLocaisDataDiaria.forEach(function(local) {
            let incluirLocal = false;
            if (localFixoId && String(local.id) === String(localFixoId)) {
                incluirLocal = true;
                valorSelecionarInicio = local.id; // Força seleção se for fixo
                valorSelecionarFim = local.id;
            } else if (!localFixoId && tipoPermitido && tipoPermitido.toLowerCase() !== 'qualquer' && tipoPermitido.toLowerCase() !== 'nenhum') {
                if (local.tipo === tipoPermitido.toLowerCase()) {
                    if (idsPermitidos.length > 0) { if (idsPermitidos.includes(String(local.id))) incluirLocal = true; } 
                    else { incluirLocal = true; }
                }
            } else if (!localFixoId && (!tipoPermitido || tipoPermitido.toLowerCase() === 'qualquer' || tipoPermitido.toLowerCase() === 'nenhum')) { 
                incluirLocal = true;
            }

            if (incluirLocal) {
                $localInicioSelectForm.append(new Option(local.text, local.id));
                $localFimSelectForm.append(new Option(local.text, local.id));
            }
        });
        
        // Restaura a seleção
        $localInicioSelectForm.val(valorSelecionarInicio).trigger('change.select2');
        $localFimSelectForm.val(valorSelecionarFim).trigger('change.select2');

        if (localFixoId) {
            $localInicioSelectForm.prop('disabled', true);
            $localFimSelectForm.prop('disabled', true);
        } else {
            $localInicioSelectForm.prop('disabled', false);
            $localFimSelectForm.prop('disabled', false);
        }
    }

    // Event Listeners
    $tipoEscalaSelectForm.on('change', function() {
        let currentWorkId = $workIdInputForm.val();
        const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        if(!statusEspeciaisForm.includes(currentWorkId.toUpperCase())){ valorOriginalWorkIdDiariaFormJs = currentWorkId; }
        atualizarVisibilidadeCamposForm();
    });
    $funcaoSelectForm.on('change', function(){ 
        $turnoFuncaoSelectForm.val(null).trigger('change'); 
        $posicaoLetraSelectForm.val(null).trigger('change'); 
        atualizarCamposFuncaoForm(); 
    });
    $turnoFuncaoSelectForm.on('change', montarWorkIDSugeridoForm);
    $posicaoLetraSelectForm.on('change', montarWorkIDSugeridoForm);
    $localInicioSelectForm.on('change', montarWorkIDSugeridoForm);

    $statusCheckboxesForm.on('change', function() {
        const $checkboxAtual = $(this);
        const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        let currentWorkIdVal = $workIdInputForm.val();

        if ($checkboxAtual.is(':checked')) {
            if (!statusEspeciaisForm.includes(currentWorkIdVal.toUpperCase())) {
                valorOriginalWorkIdDiariaFormJs = currentWorkIdVal;
            }
            $statusCheckboxesForm.not($checkboxAtual).prop('checked', false);
        }
        atualizarVisibilidadeCamposForm(); 
    });
    
    // Chamadas iniciais para configurar o formulário
    atualizarVisibilidadeCamposForm(); 
    // Se estiver editando uma função, força a atualização dos campos da função
    if (<?php echo json_encode($modo_edicao_escala_php); ?> && $tipoEscalaSelectForm.val() === 'funcao' && $funcaoSelectForm.val()) {
         atualizarCamposFuncaoForm();
    }


    // --- LÓGICA PARA COPIAR DA ESCALA PLANEJADA ---
    $('#btnCopiarDaPlanejadaDiaria').on('click', function() {
        var motoristaIdParaCopia = $('#motorista_id_select2_escala_diaria_form').val(); 
        var dataParaCopia = $('#data_escala_diaria_form').val(); 
        var $feedbackDivCopiaDiaria = $('#copiar_planejada_feedback_diaria');
        $feedbackDivCopiaDiaria.html('<small class="text-info"><i class="fas fa-spinner fa-spin"></i> Buscando na Planejada...</small>');

        if (!motoristaIdParaCopia || !dataParaCopia) {
            $feedbackDivCopiaDiaria.html('<small class="text-danger">O Motorista e a Data da Escala Diária devem estar preenchidos para buscar na Planejada.</small>');
            return;
        }

        $.ajax({
            url: 'buscar_escala_para_copia_ajax.php', 
            type: 'GET', dataType: 'json',
            data: { motorista_id: motoristaIdParaCopia, data_escala: dataParaCopia },
            success: function(response) {
                if (response.success && response.escala) {
                    var esc = response.escala;
                    $statusCheckboxesForm.prop('checked', false); 
                    valorOriginalWorkIdDiariaFormJs = ''; 

                    var workIdCopiadoUpper = (esc.work_id || '').toUpperCase();
                    const statusEspeciaisCopia = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
                    let copiouStatusEspecial = false;

                    if (statusEspeciaisCopia.includes(workIdCopiadoUpper)) {
                        $('#is_' + workIdCopiadoUpper.toLowerCase() + '_check_diaria_form').prop('checked', true);
                        copiouStatusEspecial = true;
                    } else {
                        valorOriginalWorkIdDiariaFormJs = esc.work_id || '';
                        $workIdInputForm.val(valorOriginalWorkIdDiariaFormJs);
                    }
                    
                    let tipoEscalaCopiada = 'linha';
                    if (esc.funcao_operacional_id) tipoEscalaCopiada = 'funcao';
                     else if (!esc.linha_origem_id && !copiouStatusEspecial && esc.work_id) {
                        let funcaoInferida = null;
                        for (const idFuncao in funcoesOperacionaisDataDiaria) {
                            if (esc.work_id.startsWith(funcoesOperacionaisDataDiaria[idFuncao].work_id_prefixo)) {
                                funcaoInferida = funcoesOperacionaisDataDiaria[idFuncao]; break;
                            }
                        }
                        if (funcaoInferida) { tipoEscalaCopiada = 'funcao'; esc.funcao_operacional_id = funcaoInferida.id; }
                    }

                    $tipoEscalaSelectForm.val(tipoEscalaCopiada).trigger('change');

                    setTimeout(function() {
                        var dadosParaFuncao = null;
                        if (tipoEscalaCopiada === 'funcao') {
                            dadosParaFuncao = {
                                turno: esc.turno_funcao_detectado,
                                posicao: esc.posicao_letra_detectada,
                                localInicio: esc.local_inicio_turno_id, // Passa para ser setado em atualizarCamposFuncaoForm
                                localFim: esc.local_fim_turno_id
                            };
                             $funcaoSelectForm.val(esc.funcao_operacional_id || null).trigger('change'); // Dispara para popular e filtrar locais
                             // A função atualizarCamposFuncaoForm é chamada pelo 'change' acima
                             // e usará os dados da cópia se fornecidos.
                             atualizarCamposFuncaoForm(dadosParaFuncao);

                        } else { // Linha
                            $('#linha_origem_id_diaria_form').val(esc.linha_origem_id || null).trigger('change');
                            $('#veiculo_id_diaria_form').val(esc.veiculo_id || null).trigger('change');
                            if (!copiouStatusEspecial) $('#tabela_escalas_diaria_form').val(esc.tabela_escalas || '');
                        }
                        
                        // Preenche campos comuns após o tipo de escala e campos específicos serem definidos
                        $('#hora_inicio_prevista_diaria_form').val(esc.hora_inicio_prevista || '');
                        // Para locais, se não for função com local fixo, a seleção é feita aqui após filtrar
                        if (!(tipoEscalaCopiada === 'funcao' && funcoesOperacionaisDataDiaria[esc.funcao_operacional_id] && funcoesOperacionaisDataDiaria[esc.funcao_operacional_id].local_fixo_id)) {
                            $('#local_inicio_turno_id_diaria_form').val(esc.local_inicio_turno_id || null).trigger('change');
                            $('#local_fim_turno_id_diaria_form').val(esc.local_fim_turno_id || null).trigger('change');
                        }
                        $('#eh_extra_diaria_form').prop('checked', esc.eh_extra == 1);
                        $('#observacoes_ajuste_diaria_form').val(''); 

                        // Garante que a UI reflita o estado correto após todas as manipulações
                        if (copiouStatusEspecial) { $statusCheckboxesForm.filter(':checked').trigger('change'); }
                        else { atualizarVisibilidadeCamposForm(); }

                        $feedbackDivCopiaDiaria.html('<small class="text-success"><i class="fas fa-check"></i> Dados da Escala Planejada preenchidos. Verifique e ajuste. Observações foram limpas.</small>');
                    }, 250); 
                } else {
                     $feedbackDivCopiaDiaria.html('<small class="text-warning">' + (response.message || 'Nenhuma escala planejada encontrada para este motorista/data para copiar.') + '</small>');
                }
            },
            error: function() { $feedbackDivCopiaDiaria.html('<small class="text-danger">Erro ao buscar dados da Escala Planejada.</small>'); }
        });
    });

    $('#form-escala-diaria').on('submit', function(e) {
        var isStatusChecked = $statusCheckboxesForm.is(':checked');
        if (!isStatusChecked) { // Só valida campos de trabalho se não for status especial
            if ($tipoEscalaSelectForm.val() === 'linha' && ($('#linha_origem_id_diaria_form').val() === '' || $('#linha_origem_id_diaria_form').val() === null )) {
                alert('Linha de Origem é obrigatória para escala de linha.'); e.preventDefault(); return false;
            }
            if ($tipoEscalaSelectForm.val() === 'funcao' && ($('#funcao_operacional_id_select_diaria_form').val() === '' || $('#funcao_operacional_id_select_diaria_form').val() === null)) {
                alert('Função Operacional é obrigatória para escala de função.'); e.preventDefault(); return false;
            }
            if ($tipoEscalaSelectForm.val() === 'funcao' && ($('#turno_funcao_select_diaria_form').val() === '' || $('#turno_funcao_select_diaria_form').val() === null)) {
                alert('Turno da Função é obrigatório.'); e.preventDefault(); return false;
            }
             // Validação de Posição/Letra se o campo estiver visível e for obrigatório
            if ($tipoEscalaSelectForm.val() === 'funcao' && $posicaoLetraWrapperForm.is(':visible') && ($('#posicao_letra_funcao_select_diaria_form').val() === '' || $('#posicao_letra_funcao_select_diaria_form').val() === null)) {
                alert('Posição/Letra da Função é obrigatória para esta função.'); e.preventDefault(); return false;
            }
            if ($workIdInputForm.val().trim() === '') {
                alert('WorkID é obrigatório se não for um status especial.'); $('#work_id_diaria_form').focus(); e.preventDefault(); return false;
            }
            if ($('#hora_inicio_prevista_diaria_form').val() === '' || $('#hora_fim_prevista_diaria_form').val() === '') {
                alert('Hora Início e Hora Fim são obrigatórias se não for um status especial.'); e.preventDefault(); return false;
            }
             if ($('#local_inicio_turno_id_diaria_form').val() === '' || $('#local_inicio_turno_id_diaria_form').val() === null || $('#local_fim_turno_id_diaria_form').val() === '' || $('#local_fim_turno_id_diaria_form').val() === null) {
                // Somente se os campos não estiverem desabilitados (ou seja, não é local fixo de função)
                if (!$('#local_inicio_turno_id_diaria_form').is(':disabled')) {
                     alert('Local de Início e Local de Fim são obrigatórios se não for um status especial ou função com local fixo.'); e.preventDefault(); return false;
                }
            }
        }
    });
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once 'admin_footer.php';
?>