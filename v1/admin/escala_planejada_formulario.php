<?php
// admin/escala_planejada_formulario.php
// ATUALIZADO v9: WorkID dinâmico APENAS para Tipo de Escala "Linha". Mantém lógica original para Função e Status.

require_once 'auth_check.php';

$niveis_permitidos_gerenciar_escala = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerenciar_escala)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para gerenciar a Escala Planejada.";
    header('Location: index.php');
    exit;
}

require_once '../db_config.php';
$page_title_action = 'Adicionar Entrada na Escala Planejada';

// Inicialização de variáveis (como no seu arquivo original)
$escala_id_edit = null;
$tipo_escala_form_php = 'linha'; // Default
$data_escala_form_php = date('Y-m-d');
$motorista_id_form_php = '';
$motorista_texto_repop_php = ''; // Para Select2 com AJAX
$linha_origem_id_form_php = ''; // Usaremos este como ID para o select de linha
$veiculo_id_form_php = '';
$funcao_operacional_id_form_php = '';
$turno_funcao_form_php = '';
$posicao_letra_form_php = '';
$work_id_form_php = ''; // WorkID existente se estiver editando (importante para pré-selecionar/mostrar)
$tabela_escalas_form_php = '';
$hora_inicio_form_php = '';
$local_inicio_id_form_php = '';
$hora_fim_form_php = '';
$local_fim_id_form_php = '';
$eh_extra_form_php = 0;
$is_folga_check_php = false; $is_falta_check_php = false; $is_fora_escala_check_php = false; $is_ferias_check_php = false; $is_atestado_check_php = false;
$modo_edicao_escala_php = false;
$lista_linhas_select_php = []; $lista_locais_select_php = []; $lista_veiculos_select_php = []; $lista_funcoes_operacionais_php = [];

if ($pdo) {
    try {
        // Mantém suas queries originais para popular os selects
        $stmt_linhas_all = $pdo->query("SELECT id, numero, nome FROM linhas WHERE status_linha = 'ativa' ORDER BY CAST(numero AS UNSIGNED), numero, nome ASC");
        $lista_linhas_select_php = $stmt_linhas_all->fetchAll(PDO::FETCH_ASSOC);

        $stmt_locais_all = $pdo->query("SELECT id, nome, tipo FROM locais ORDER BY nome ASC");
        $lista_locais_select_php = $stmt_locais_all->fetchAll(PDO::FETCH_ASSOC);

        $stmt_veiculos_all = $pdo->query("SELECT id, prefixo FROM veiculos ORDER BY prefixo ASC");
        if($stmt_veiculos_all) $lista_veiculos_select_php = $stmt_veiculos_all->fetchAll(PDO::FETCH_ASSOC);

        $stmt_funcoes = $pdo->query("SELECT id, nome_funcao, work_id_prefixo, locais_permitidos_tipo, locais_permitidos_ids, local_fixo_id, turnos_disponiveis, requer_posicao_especifica, max_posicoes_por_turno, ignorar_validacao_jornada FROM funcoes_operacionais WHERE status = 'ativa' ORDER BY nome_funcao ASC");
        $lista_funcoes_operacionais_php = $stmt_funcoes->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $_SESSION['admin_warning_message'] = "Atenção: Erro ao carregar algumas opções de seleção para o formulário.";
        error_log("Erro ao carregar dados para formulário de escala: " . $e->getMessage());
    }
}

// Lógica de Edição (como no seu arquivo original, adaptada para novas variáveis se necessário)
if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $escala_id_edit = (int)$_GET['id'];
    $modo_edicao_escala_php = true;
    $page_title_action = 'Editar Entrada da Escala Planejada';
    if ($pdo) {
        try {
            $stmt_get_escala = $pdo->prepare("SELECT esc.*, mot.nome as nome_motorista_atual, mot.matricula as matricula_motorista_atual FROM motorista_escalas esc LEFT JOIN motoristas mot ON esc.motorista_id = mot.id WHERE esc.id = :id_escala");
            $stmt_get_escala->bindParam(':id_escala', $escala_id_edit, PDO::PARAM_INT);
            $stmt_get_escala->execute();
            $escala_db = $stmt_get_escala->fetch(PDO::FETCH_ASSOC);
            if ($escala_db) {
                $data_escala_form_php = $escala_db['data'];
                $motorista_id_form_php = $escala_db['motorista_id'];
                if ($motorista_id_form_php && isset($escala_db['nome_motorista_atual'])) { $motorista_texto_repop_php = htmlspecialchars($escala_db['nome_motorista_atual'] . ' (Mat: ' . $escala_db['matricula_motorista_atual'] . ')'); }
                
                $work_id_form_php = $escala_db['work_id']; // IMPORTANTE: Este é o WorkID salvo
                
                $funcao_operacional_id_form_php = $escala_db['funcao_operacional_id'];
                if (!empty($funcao_operacional_id_form_php)) {
                    $tipo_escala_form_php = 'funcao';
                    // ... (sua lógica para extrair turno e posição do work_id_form_php se for função)
                     $funcao_obj_edit = null;
                    foreach($lista_funcoes_operacionais_php as $f){
                        if(strval($f['id']) === strval($funcao_operacional_id_form_php)){
                            $funcao_obj_edit = $f;
                            break;
                        }
                    }
                    if ($funcao_obj_edit && $work_id_form_php) {
                        $prefixo = $funcao_obj_edit['work_id_prefixo'];
                        $sem_prefixo = preg_replace('/^'.preg_quote($prefixo, '/').'-?/i', '', $work_id_form_php);
                        if (!$funcao_obj_edit['local_fixo_id']) {
                            $sem_prefixo = preg_replace('/^[A-Z0-9]{1,3}-/i', '', $sem_prefixo);
                        }
                        $partes = explode('-', $sem_prefixo);
                        $ultimo_segmento = array_pop($partes);
                        $requer_pos = (isset($funcao_obj_edit['requer_posicao_especifica']) && ($funcao_obj_edit['requer_posicao_especifica'] === true || $funcao_obj_edit['requer_posicao_especifica'] === '1' || strtolower($funcao_obj_edit['requer_posicao_especifica']) === 'true'));
                        if($requer_pos && strlen($ultimo_segmento) > 2 && ctype_alpha(substr($ultimo_segmento,-1))){
                            $posicao_letra_form_php = strtoupper(substr($ultimo_segmento,-1));
                            $turno_funcao_form_php = substr($ultimo_segmento,0,-1);
                        } elseif (strlen($ultimo_segmento) == 2 && ctype_digit($ultimo_segmento)){
                           $turno_funcao_form_php = $ultimo_segmento;
                           $posicao_letra_form_php = '';
                        }
                    }
                } else {
                    $tipo_escala_form_php = 'linha'; // Ou o tipo que você usa para linha
                    $linha_origem_id_form_php = $escala_db['linha_origem_id'];
                    $veiculo_id_form_php = $escala_db['veiculo_id'];
                }

                $work_id_upper = strtoupper($work_id_form_php ?? '');
                $is_folga_check_php = ($work_id_upper === 'FOLGA');
                $is_falta_check_php = ($work_id_upper === 'FALTA');
                $is_fora_escala_check_php = ($work_id_upper === 'FORADEESCALA');
                $is_ferias_check_php = ($work_id_upper === 'FÉRIAS');
                $is_atestado_check_php = ($work_id_upper === 'ATESTADO');
                $is_status_especial = $is_folga_check_php || $is_falta_check_php || $is_fora_escala_check_php || $is_ferias_check_php || $is_atestado_check_php;
                
                $tabela_escalas_form_php = ($is_status_especial || $tipo_escala_form_php === 'funcao') ? '' : $escala_db['tabela_escalas'];
                if (($tipo_escala_form_php === 'funcao' || $is_status_especial)) {
                    $linha_origem_id_form_php = '';
                    $veiculo_id_form_php = '';
                }
                $hora_inicio_form_php = $is_status_especial ? '' : ($escala_db['hora_inicio_prevista'] ? date('H:i', strtotime($escala_db['hora_inicio_prevista'])) : '');
                $local_inicio_id_form_php = $is_status_especial ? '' : $escala_db['local_inicio_turno_id'];
                $hora_fim_form_php = $is_status_especial ? '' : ($escala_db['hora_fim_prevista'] ? date('H:i', strtotime($escala_db['hora_fim_prevista'])) : '');
                $local_fim_id_form_php = $is_status_especial ? '' : $escala_db['local_fim_turno_id'];
                $eh_extra_form_php = $is_status_especial ? 0 : $escala_db['eh_extra'];
                $page_title_action .= ' (' . $motorista_texto_repop_php . ' em ' . date('d/m/Y', strtotime($data_escala_form_php)) . ')';
            } else { /* ... seu tratamento de erro ... */ }
        } catch (PDOException $e) { /* ... seu tratamento de erro ... */ }
    } else { /* ... seu tratamento de erro ... */ }
}


$page_title = $page_title_action; // Define para o header
require_once 'admin_header.php';

// Lógica de Repopulação (como no seu arquivo original)
$form_data_repop_session = $_SESSION['form_data_escala_planejada'] ?? [];
if(!empty($form_data_repop_session)) {
    // ... (sua lógica de repopulação completa aqui, certifique-se de incluir $work_id_form_php) ...
    $tipo_escala_form_php = $form_data_repop_session['tipo_escala'] ?? $tipo_escala_form_php;
    $data_escala_form_php = $form_data_repop_session['data_escala'] ?? $data_escala_form_php;
    $motorista_id_form_php = $form_data_repop_session['motorista_id'] ?? $motorista_id_form_php;
    if ($motorista_id_form_php && empty($motorista_texto_repop_php) && $pdo) { try { $stmt_repop_mot = $pdo->prepare("SELECT nome, matricula FROM motoristas WHERE id = :mid"); $stmt_repop_mot->bindParam(':mid', $motorista_id_form_php, PDO::PARAM_INT); $stmt_repop_mot->execute(); $mot_repop = $stmt_repop_mot->fetch(PDO::FETCH_ASSOC); if ($mot_repop) { $motorista_texto_repop_php = htmlspecialchars($mot_repop['nome'] . ' (Mat: ' . $mot_repop['matricula'] . ')');} } catch (PDOException $e_repop) {} }
    $linha_origem_id_form_php = $form_data_repop_session['linha_origem_id'] ?? $linha_origem_id_form_php;
    $veiculo_id_form_php = $form_data_repop_session['veiculo_id'] ?? $veiculo_id_form_php;
    $funcao_operacional_id_form_php = $form_data_repop_session['funcao_operacional_id'] ?? $funcao_operacional_id_form_php;
    $turno_funcao_form_php = $form_data_repop_session['turno_funcao'] ?? $turno_funcao_form_php;
    $posicao_letra_form_php = $form_data_repop_session['posicao_letra_funcao'] ?? $posicao_letra_form_php;
    $is_folga_check_php = isset($form_data_repop_session['is_folga_check']); $is_falta_check_php = isset($form_data_repop_session['is_falta_check']); $is_fora_escala_check_php = isset($form_data_repop_session['is_fora_escala_check']); $is_ferias_check_php = isset($form_data_repop_session['is_ferias_check']); $is_atestado_check_php = isset($form_data_repop_session['is_atestado_check']);
    
    // MODIFICADO: WorkID repopulado do form_data se existir, senão mantém o que veio da edição
    $work_id_repop_val = $form_data_repop_session['work_id'] ?? $work_id_form_php;

    if ($is_folga_check_php) $work_id_form_php = 'FOLGA'; elseif ($is_falta_check_php) $work_id_form_php = 'FALTA'; elseif ($is_fora_escala_check_php) $work_id_form_php = 'FORADEESCALA'; elseif ($is_ferias_check_php) $work_id_form_php = 'FÉRIAS'; elseif ($is_atestado_check_php) $work_id_form_php = 'ATESTADO'; else $work_id_form_php = $work_id_repop_val;
    
    $is_status_especial_repop = $is_folga_check_php || $is_falta_check_php || $is_fora_escala_check_php || $is_ferias_check_php || $is_atestado_check_php;
    $tabela_escalas_form_php = ($is_status_especial_repop || $tipo_escala_form_php === 'funcao') ? '' : ($form_data_repop_session['tabela_escalas'] ?? $tabela_escalas_form_php);
    if (($tipo_escala_form_php === 'funcao' || $is_status_especial_repop)) { $linha_origem_id_form_php = ''; $veiculo_id_form_php = '';}
    $hora_inicio_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['hora_inicio_prevista'] ?? $hora_inicio_form_php);
    $local_inicio_id_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['local_inicio_turno_id'] ?? $local_inicio_id_form_php);
    $hora_fim_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['hora_fim_prevista'] ?? $hora_fim_form_php);
    $local_fim_id_form_php = $is_status_especial_repop ? '' : ($form_data_repop_session['local_fim_turno_id'] ?? $local_fim_id_form_php);
    $eh_extra_form_php = $is_status_especial_repop ? 0 : (isset($form_data_repop_session['eh_extra']) ? 1 : 0);
    unset($_SESSION['form_data_escala_planejada']);
}

// Passar dados PHP para JavaScript de forma segura
$js_work_id_inicial_php = $work_id_form_php; // Este será o WorkID original se estiver editando
$js_funcoes_operacionais_data = []; foreach($lista_funcoes_operacionais_php as $func) { $js_funcoes_operacionais_data[$func['id']] = $func; }
$js_locais_data_todos = []; foreach ($lista_locais_select_php as $loc) { $js_locais_data_todos[] = ['id' => $loc['id'], 'text' => htmlspecialchars($loc['nome']), 'tipo' => strtolower($loc['tipo'] ?? '')]; }
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title_action); ?></h1>
    <a href="escala_planejada_listar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Voltar para Lista
    </a>
</div>

<?php
if (isset($_SESSION['admin_form_error_escala_p'])) { echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . nl2br(htmlspecialchars($_SESSION['admin_form_error_escala_p'])) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_form_error_escala_p']); }
?>

<form action="escala_planejada_processa.php" method="POST" id="form-escala-planejada">
    <?php if ($modo_edicao_escala_php && $escala_id_edit): ?>
        <input type="hidden" name="escala_id" value="<?php echo $escala_id_edit; ?>">
    <?php endif; ?>
    <?php // Mantém seus campos hidden para filtros de retorno
     $params_to_preserve_submit = ['pagina_original' => 'pagina', 'filtro_data_original' => 'data_escala', 'filtro_tipo_busca_original' => 'tipo_busca_adicional', 'filtro_valor_busca_original' => 'valor_busca_adicional'];
    foreach ($params_to_preserve_submit as $hidden_name_submit_loop => $get_key_submit_loop):
        if (isset($_GET[$get_key_submit_loop])):
    ?>
        <input type="hidden" name="<?php echo htmlspecialchars($hidden_name_submit_loop); ?>" value="<?php echo htmlspecialchars($_GET[$get_key_submit_loop]); ?>">
    <?php endif; endforeach; ?>

    <fieldset class="mb-4 border p-3 rounded bg-light">
        <legend class="w-auto px-2 h6 text-secondary font-weight-normal">Copiar Dados de Escala Planejada Existente (Opcional)</legend>
        <div class="form-row">
            <div class="form-group col-md-5">
                <label for="copiar_motorista_id_select2" class="small">Matrícula da Escala de Origem:</label>
                <select class="form-control form-control-sm" id="copiar_motorista_id_select2" data-placeholder="Buscar matrícula para copiar...">
                    <option></option>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label for="copiar_data_escala_input" class="small">Data da Escala de Origem:</label>
                <input type="date" class="form-control form-control-sm" id="copiar_data_escala_input">
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-info btn-block" id="btnBuscarCopiarEscala">
                    <i class="fas fa-search-plus"></i> Buscar & Preencher
                </button>
            </div>
        </div>
        <div id="copiar_escala_feedback" class="small mt-1" style="min-height: 20px;"></div>
    </fieldset>

    <div class="form-row">
        <div class="form-group col-md-3"><label for="data_escala">Data da Escala <span class="text-danger">*</span></label><input type="date" class="form-control" id="data_escala" name="data_escala" value="<?php echo htmlspecialchars($data_escala_form_php); ?>" required></div>
        <div class="form-group col-md-5"><label for="motorista_id_select2_escala">Matrícula <span class="text-danger">*</span></label><select class="form-control" id="motorista_id_select2_escala" name="motorista_id" required data-placeholder="Selecione ou digite matrícula/nome..."><?php if ($motorista_id_form_php && !empty($motorista_texto_repop_php)): ?><option value="<?php echo htmlspecialchars($motorista_id_form_php); ?>" selected><?php echo $motorista_texto_repop_php; ?></option><?php elseif ($motorista_id_form_php): ?><option value="<?php echo htmlspecialchars($motorista_id_form_php); ?>" selected>ID: <?php echo htmlspecialchars($motorista_id_form_php); ?> (Carregando...)</option><?php else: ?><option></option><?php endif; ?></select></div>
        <div class="form-group col-md-4 d-flex align-items-center flex-wrap">
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check" type="checkbox" value="FOLGA" id="is_folga_check" name="is_folga_check" <?php echo $is_folga_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_folga_check"><strong>Folga?</strong></label></div>
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check" type="checkbox" value="FALTA" id="is_falta_check" name="is_falta_check" <?php echo $is_falta_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_falta_check"><strong>Falta?</strong></label></div>
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check" type="checkbox" value="FORADEESCALA" id="is_fora_escala_check" name="is_fora_escala_check" <?php echo $is_fora_escala_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_fora_escala_check"><strong>Fora de Escala?</strong></label></div>
            <div class="form-check mb-2 mr-3"><input class="form-check-input status-escala-check" type="checkbox" value="FÉRIAS" id="is_ferias_check" name="is_ferias_check" <?php echo $is_ferias_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_ferias_check"><strong>Férias?</strong></label></div>
            <div class="form-check mb-2"><input class="form-check-input status-escala-check" type="checkbox" value="ATESTADO" id="is_atestado_check" name="is_atestado_check" <?php echo $is_atestado_check_php ? 'checked' : ''; ?>><label class="form-check-label" for="is_atestado_check"><strong>Atestado?</strong></label></div>
        </div>
    </div>
    <hr>
    <div class="form-row">
        <div class="form-group col-md-4">
            <label for="tipo_escala_select">Tipo de Escala <span class="text-danger">*</span></label>
            <select class="form-control" id="tipo_escala_select" name="tipo_escala">
                <option value="linha" <?php echo ($tipo_escala_form_php === 'linha') ? 'selected' : ''; ?>>Linha de Ônibus</option>
                <option value="funcao" <?php echo ($tipo_escala_form_php === 'funcao') ? 'selected' : ''; ?>>Função Operacional</option>
            </select>
        </div>
    </div>

    <div id="campos_escala_linha_wrapper" style="<?php echo ($tipo_escala_form_php !== 'linha') ? 'display:none;' : ''; ?>">
        <div class="form-row">
            <div class="form-group col-md-8"><label for="linha_origem_id">Linha de Origem (Principal) <span class="text-danger">*</span></label><select class="form-control select2-simple" id="linha_origem_id" name="linha_origem_id" data-placeholder="Selecione..."><option value=""></option><?php foreach ($lista_linhas_select_php as $l):?><option value="<?php echo $l['id'];?>" <?php if(strval($l['id'])==strval($linha_origem_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($l['numero'].($l['nome']?' - '.$l['nome']:''));?></option><?php endforeach;?></select></div>
            <div class="form-group col-md-4"><label for="veiculo_id">Veículo (Opcional)</label><select class="form-control select2-simple" id="veiculo_id" name="veiculo_id" data-placeholder="Selecione..."><option value=""></option><?php foreach ($lista_veiculos_select_php as $v):?><option value="<?php echo $v['id'];?>" <?php if(strval($v['id'])==strval($veiculo_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($v['prefixo']);?></option><?php endforeach;?></select></div>
        </div>
    </div>

    <div id="todos_campos_funcao_wrapper" style="<?php echo ($tipo_escala_form_php !== 'funcao') ? 'display:none;' : ''; ?>">
        <div class="form-row">
            <div class="form-group col-md-12"><label for="funcao_operacional_id_select">Função Operacional <span class="text-danger">*</span></label><select class="form-control select2-simple" id="funcao_operacional_id_select" name="funcao_operacional_id" data-placeholder="Selecione..."><option value=""></option><?php foreach($lista_funcoes_operacionais_php as $fo):?><option value="<?php echo $fo['id'];?>" data-prefixo="<?php echo htmlspecialchars($fo['work_id_prefixo']);?>" data-locais-tipo="<?php echo htmlspecialchars($fo['locais_permitidos_tipo']??'');?>" data-locais-ids="<?php echo htmlspecialchars($fo['locais_permitidos_ids']??'');?>" data-local-fixo-id="<?php echo htmlspecialchars($fo['local_fixo_id']??'');?>" data-turnos="<?php echo htmlspecialchars($fo['turnos_disponiveis']);?>" data-requer-posicao="<?php echo $fo['requer_posicao_especifica']?'true':'false';?>" data-max-posicoes="<?php echo htmlspecialchars($fo['max_posicoes_por_turno']??'0');?>" data-ignora-jornada="<?php echo $fo['ignorar_validacao_jornada']?'true':'false';?>" <?php if(strval($fo['id'])==strval($funcao_operacional_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($fo['nome_funcao']);?></option><?php endforeach;?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4"><label for="turno_funcao_select">Turno da Função <span class="text-danger">*</span></label><select class="form-control" id="turno_funcao_select" name="turno_funcao"><option value="">Selecione...</option></select></div>
            <div class="form-group col-md-4" id="wrapper_posicao_letra_funcao" style="display:none;"><label for="posicao_letra_funcao_select">Posição/Letra <span class="text-danger">*</span></label><select class="form-control" id="posicao_letra_funcao_select" name="posicao_letra_funcao"><option value="">Selecione...</option></select></div>
        </div>
    </div>

    <div id="campos_comuns_escala_wrapper">
        <div class="form-row">
            <div class="form-group col-md-4" id="div_work_id_campo_unico">
                <label for="work_id_input">WorkID <span id="work_id_obrigatorio_asterisco" class="text-danger">*</span></label>
                
                <input type="text" class="form-control" id="work_id_input" name="work_id_text_input" 
                       value="<?php echo htmlspecialchars($work_id_form_php); ?>" maxlength="50"
                       style="<?php echo ($tipo_escala_form_php === 'funcao' || $is_folga_check_php || $is_falta_check_php || $is_fora_escala_check_php || $is_ferias_check_php || $is_atestado_check_php) ? '' : 'display:none;'; ?>">

                <select class="form-control" id="work_id_select" name="work_id_select_input" 
                        style="<?php echo ($tipo_escala_form_php === 'linha') ? '' : 'display:none;'; ?>">
                    <option value="">Selecione Linha e Data...</option>
                    <?php if ($modo_edicao_escala_php && $tipo_escala_form_php === 'linha' && !empty($work_id_form_php)): ?>
                        <option value="<?php echo htmlspecialchars($work_id_form_php); ?>" selected><?php echo htmlspecialchars($work_id_form_php); ?> (Salvo)</option>
                    <?php endif; ?>
                </select>
                <small class="" id="work_id_sugestao_text"></small>
            </div>

            <div class="form-group col-md-4" id="wrapper_tabela_escalas"><label for="tabela_escalas">Nº Tabela da Escala</label><input type="text" class="form-control" id="tabela_escalas" name="tabela_escalas" value="<?php echo htmlspecialchars($tabela_escalas_form_php); ?>" maxlength="10"></div>
            <div class="form-group col-md-4 d-flex align-items-center pt-3"><div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="eh_extra" name="eh_extra" <?php echo ($eh_extra_form_php==1)?'checked':'';?>><label class="form-check-label" for="eh_extra">Turno Extra?</label></div></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3"><label for="hora_inicio_prevista">Hora Início Prev. <span class="text-danger">*</span></label><input type="time" class="form-control" id="hora_inicio_prevista" name="hora_inicio_prevista" value="<?php echo htmlspecialchars($hora_inicio_form_php);?>"></div>
            <div class="form-group col-md-3"><label for="local_inicio_turno_id">Local Início <span class="text-danger">*</span></label><select class="form-control select2-simple" id="local_inicio_turno_id" name="local_inicio_turno_id" data-placeholder="Selecione..."><option value=""></option><?php foreach($lista_locais_select_php as $li):?><option value="<?php echo $li['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($li['tipo']??''));?>" <?php if(strval($li['id'])==strval($local_inicio_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($li['nome']);?></option><?php endforeach;?></select></div>
            <div class="form-group col-md-3"><label for="hora_fim_prevista">Hora Fim Prev. <span class="text-danger">*</span></label><input type="time" class="form-control" id="hora_fim_prevista" name="hora_fim_prevista" value="<?php echo htmlspecialchars($hora_fim_form_php);?>"></div>
            <div class="form-group col-md-3"><label for="local_fim_turno_id">Local Fim <span class="text-danger">*</span></label><select class="form-control select2-simple" id="local_fim_turno_id" name="local_fim_turno_id" data-placeholder="Selecione..."><option value=""></option><?php foreach($lista_locais_select_php as $lf):?><option value="<?php echo $lf['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($lf['tipo']??''));?>" <?php if(strval($lf['id'])==strval($local_fim_id_form_php))echo 'selected';?>><?php echo htmlspecialchars($lf['nome']);?></option><?php endforeach;?></select></div>
        </div>
    </div>
    <hr>
    <button type="submit" name="salvar_escala_planejada" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Entrada</button>
    <a href="escala_planejada_listar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-secondary">Cancelar</a>
</form>

<?php
ob_start(); // Captura o JavaScript para $page_specific_js
?>
<script>
    // Passar dados PHP para JavaScript de forma segura
    const funcoesOperacionaisData = <?php echo json_encode($js_funcoes_operacionais_data); ?>;
    const todosOsLocaisData = <?php echo json_encode($js_locais_data_todos); ?>;
    var valorOriginalWorkIdJs = <?php echo json_encode($js_work_id_inicial_php); ?>; // WorkID salvo no DB (para edição)

$(document).ready(function() {
    // Seus inicializadores de Select2 (mantidos)
    $('#motorista_id_select2_escala, #copiar_motorista_id_select2').select2({
        theme: 'bootstrap4', language: "pt-BR", width: '100%', allowClear: true,
        placeholder: 'Digite matrícula ou nome...',
        ajax: { 
            url: 'buscar_motoristas_ajax.php', dataType: 'json', delay: 250,
            data: function (params) { return { q: params.term, page: params.page || 1 }; },
            processResults: function (data, params) { params.page = params.page || 1; return { results: data.items, pagination: { more: (params.page * 10) < data.total_count } }; },
        },
        minimumInputLength: 2, escapeMarkup: function (m) { return m; },
        templateResult: function (d) { return d.text || "Buscando..."; },
        templateSelection: function (d) { return d.text || d.id; }
    });

    $('#linha_origem_id, #veiculo_id, #funcao_operacional_id_select, #local_inicio_turno_id, #local_fim_turno_id').each(function() {
        $(this).select2({ theme: 'bootstrap4', placeholder: $(this).data('placeholder') || 'Selecione...', allowClear: true, width: '100%' });
    });

    // Elementos do formulário
    const $tipoEscalaSelect = $('#tipo_escala_select');
    const $camposLinhaWrapper = $('#campos_escala_linha_wrapper');
    const $todosCamposFuncaoWrapper = $('#todos_campos_funcao_wrapper');
    const $funcaoSelect = $('#funcao_operacional_id_select');
    const $turnoFuncaoSelect = $('#turno_funcao_select');
    const $posicaoLetraWrapper = $('#wrapper_posicao_letra_funcao');
    const $posicaoLetraSelect = $('#posicao_letra_funcao_select');
    const $localInicioSelect = $('#local_inicio_turno_id');
    const $localFimSelect = $('#local_fim_turno_id');
    const $tabelaEscalasWrapper = $('#wrapper_tabela_escalas');
    const $camposComunsWrapper = $('#campos_comuns_escala_wrapper');
    const $statusCheckboxes = $('.status-escala-check');

    // NOVO: Elementos do WorkID
    const $workIdInputText = $('#work_id_input'); // Input de texto original para WorkID
    const $workIdSelectLinha = $('#work_id_select'); // Novo Select para WorkID de Linha
    const $workIdSugestaoText = $('#work_id_sugestao_text'); // Small text para feedback

    // Inputs para buscar WorkIDs de linha
    const $linhaOrigemSelectWorkID = $('#linha_origem_id'); 
    const $dataEscalaInputWorkID = $('#data_escala');


    function atualizarVisibilidadeCampos() {
        const tipoSelecionado = $tipoEscalaSelect.val();
        let algumStatusMarcado = $statusCheckboxes.is(':checked');
        
        // Reseta os nomes dos campos WorkID para evitar submissão de ambos
        $workIdInputText.attr('name', 'work_id_text_input_disabled');
        $workIdSelectLinha.attr('name', 'work_id_select_input_disabled');

        // Oculta ambos os campos WorkID por padrão, serão exibidos conforme a lógica
        $workIdInputText.hide();
        $workIdSelectLinha.hide();
        $workIdSugestaoText.text('').hide();

        // Resetar 'required' e 'disabled' para campos principais
        $('#linha_origem_id, #funcao_operacional_id_select, #turno_funcao_select, #posicao_letra_funcao_select, #local_inicio_turno_id, #local_fim_turno_id, #hora_inicio_prevista, #hora_fim_prevista').prop('required', false);
        $workIdInputText.prop('required', false).prop('readonly', false);
        $workIdSelectLinha.prop('required', false);


        if (algumStatusMarcado) {
            // Lógica para status especiais (Folga, Falta, etc.)
            $tipoEscalaSelect.prop('disabled', true);
            $camposLinhaWrapper.hide();
            $todosCamposFuncaoWrapper.hide();
            $camposComunsWrapper.find('input[type="time"], #tabela_escalas').val('');
            $camposComunsWrapper.find('select.select2-simple:not(#motorista_id_select2_escala)').val(null).trigger('change').prop('disabled', true);
            $camposComunsWrapper.find('#eh_extra').prop('checked', false).prop('disabled',true);
            $camposComunsWrapper.find('input:not(#work_id_input, #data_escala)').prop('disabled', true); // Mantém data_escala editável

            $tabelaEscalasWrapper.hide();
            let valorWorkIdParaStatus = '';
            $statusCheckboxes.each(function() { if ($(this).is(':checked')) { valorWorkIdParaStatus = $(this).val(); return false; }});
            
            $workIdInputText.val(valorWorkIdParaStatus).show().prop('readonly', true).prop('required', true).attr('name', 'work_id'); // Ativa o input de texto para status
            $workIdSelectLinha.hide().val(null).trigger('change'); // Garante que o select está escondido e limpo
            $workIdSugestaoText.text('WorkID definido pelo status selecionado.').show();

        } else {
            // Lógica para Tipo de Escala (Linha ou Função)
            $tipoEscalaSelect.prop('disabled', false);
            $camposComunsWrapper.find('select.select2-simple:not(#motorista_id_select2_escala), input[type="time"], #tabela_escalas, #eh_extra').prop('disabled', false);
             $('#hora_inicio_prevista, #hora_fim_prevista, #local_inicio_turno_id, #local_fim_turno_id').prop('required', true);


            if (tipoSelecionado === 'linha') {
                $camposLinhaWrapper.show();
                $todosCamposFuncaoWrapper.hide();
                $funcaoSelect.val(null).trigger('change.select2'); // Limpa função
                $('#linha_origem_id').prop('required', true);
                
                $workIdSelectLinha.show().prop('required', true).attr('name', 'work_id'); // Mostra e torna obrigatório o SELECT de WorkID
                $workIdInputText.hide().val(valorOriginalWorkIdJs || ''); // Esconde o input de texto e restaura valor original se houver
                
                $tabelaEscalasWrapper.show();
                $workIdSugestaoText.text('Selecione linha e data para carregar WorkIDs.').show();
                carregarWorkIDsDisponiveis(); // Chama para carregar os WorkIDs

            } else if (tipoSelecionado === 'funcao') {
                $camposLinhaWrapper.hide();
                $todosCamposFuncaoWrapper.show();
                $('#linha_origem_id, #veiculo_id').val(null).trigger('change.select2'); // Limpa linha/veículo
                $funcaoSelect.prop('required', true);
                $turnoFuncaoSelect.prop('required', true); // Turno é obrigatório para função
                
                $workIdInputText.show().prop('required', true).prop('readonly', false).attr('name', 'work_id'); // Mostra o INPUT TEXT para WorkID de função
                $workIdSelectLinha.hide().val(null).trigger('change'); // Esconde o select
                
                $tabelaEscalasWrapper.hide();
                $('#tabela_escalas').val('');
                $workIdInputText.prop('placeholder', 'WorkID será sugerido pela função');
                atualizarCamposFuncao(); // Lógica para campos de função
                montarWorkIDSugerido(); // Tenta montar o WorkID da função
            }
        }
    }

    function montarWorkIDSugerido() {
        const tipoEscala = $tipoEscalaSelect.val();
        if (tipoEscala !== 'funcao' || $statusCheckboxes.is(':checked')) {
            if (tipoEscala !== 'linha') { // Só limpa sugestão se não for linha (que tem seu próprio texto)
                 $workIdSugestaoText.text('').hide();
            }
            return;
        }
        // ... (sua lógica existente para montar WorkID de função)
        const funcaoId = $funcaoSelect.val();
        if (!funcaoId || !funcoesOperacionaisData[funcaoId]) { $workIdSugestaoText.text('').hide(); return; }
        const funcaoData = funcoesOperacionaisData[funcaoId];
        const prefixo = funcaoData.work_id_prefixo;
        const turno = $turnoFuncaoSelect.val();
        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        const posicao = $posicaoLetraSelect.val();
        let sugestao = '';
        if (prefixo && turno) {
            sugestao = prefixo;
            if (!funcaoData.local_fixo_id && $localInicioSelect.val()) {
                let nomeLocalCompleto = $localInicioSelect.find('option:selected').text();
                let nomeLocalCurto = '';
                if(nomeLocalCompleto && nomeLocalCompleto.trim().toLowerCase() !== 'selecione...' && nomeLocalCompleto.trim() !== ''){
                    let partesNomeLocal = nomeLocalCompleto.split(' ');
                    if (partesNomeLocal.length > 1 && partesNomeLocal[0].toUpperCase() === 'T.') {
                        nomeLocalCurto = "T" + (partesNomeLocal[1] ? partesNomeLocal[1].substring(0,1).toUpperCase() : '');
                    } else { nomeLocalCurto = nomeLocalCompleto.substring(0,3).toUpperCase().replace(/[^A-Z0-9]/g, '');}
                    if(nomeLocalCurto) sugestao += '-' + nomeLocalCurto;
                }
            }
            sugestao += '-' + turno;
            if (requerPosicao && posicao) { sugestao += posicao.toUpperCase(); }
            $workIdInputText.val(sugestao); // Coloca no input de TEXTO
            $workIdSugestaoText.addClass('form-text feedback-success').text('WorkID Sugerido: ' + sugestao).show();
        } else { $workIdSugestaoText.text('').hide(); }
    }

    function atualizarCamposFuncao(dadosCopia = null) {
        // ... (sua lógica existente para atualizar campos de função)
        const funcaoId = $funcaoSelect.val();
        let turnoParaSetar = (dadosCopia && dadosCopia.turno !== undefined) ? dadosCopia.turno : <?php echo json_encode($turno_funcao_form_php); ?>;
        let posicaoParaSetar = (dadosCopia && dadosCopia.posicao !== undefined) ? dadosCopia.posicao : <?php echo json_encode($posicao_letra_form_php); ?>;
        let localInicioParaSetar = (dadosCopia && dadosCopia.localInicio !== undefined) ? dadosCopia.localInicio : <?php echo json_encode($local_inicio_id_form_php); ?>;
        let localFimParaSetar = (dadosCopia && dadosCopia.localFim !== undefined) ? dadosCopia.localFim : <?php echo json_encode($local_fim_id_form_php); ?>;

        $posicaoLetraWrapper.hide();
        $posicaoLetraSelect.prop('required', false).val('');

        if (!funcaoId || !funcoesOperacionaisData[funcaoId]) {
            $turnoFuncaoSelect.html('<option value="">Selecione a função...</option>').prop('disabled', true).val('');
            filtrarLocais(null, 'qualquer', null, localInicioParaSetar, localFimParaSetar);
            $localInicioSelect.prop('disabled', false).prop('required',true);
            $localFimSelect.prop('disabled', false).prop('required',true);
            montarWorkIDSugerido();
            return;
        }

        const funcaoData = funcoesOperacionaisData[funcaoId];
        const turnosArray = funcaoData.turnos_disponiveis ? funcaoData.turnos_disponiveis.split(',') : [];
        $turnoFuncaoSelect.html('<option value="">Selecione o turno...</option>');
        const turnoNomes = {'01': 'Manhã', '02': 'Tarde', '03': 'Noite'}; // Ajuste conforme seus turnos
        turnosArray.forEach(function(turno) {
            $turnoFuncaoSelect.append(new Option(turnoNomes[turno.trim()] || 'Turno ' + turno.trim(), turno.trim()));
        });
        $turnoFuncaoSelect.prop('disabled', false).prop('required', true).val(turnoParaSetar).trigger('change');
        
        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        if (requerPosicao && funcaoData.max_posicoes_por_turno > 0) {
            $posicaoLetraSelect.html('<option value="">Selecione...</option>');
            for (let i = 0; i < funcaoData.max_posicoes_por_turno; i++) { let letra = String.fromCharCode(65 + i); $posicaoLetraSelect.append(new Option(letra, letra)); }
            $posicaoLetraWrapper.show();
            $posicaoLetraSelect.prop('required', true).val(posicaoParaSetar).trigger('change');
        }
        
        filtrarLocais(funcaoData.local_fixo_id, funcaoData.locais_permitidos_tipo, funcaoData.locais_permitidos_ids, localInicioParaSetar, localFimParaSetar);

        if (funcaoData.local_fixo_id) {
            $localInicioSelect.prop('required', false); 
            $localFimSelect.prop('required', false);
        } else {
            $localInicioSelect.prop('disabled', false).prop('required', true);
            $localFimSelect.prop('disabled', false).prop('required', true);
        }
        montarWorkIDSugerido();
    }

    function filtrarLocais(localFixoId, tipoPermitido, idsPermitidosStr, valorPreselecaoInicio = null, valorPreselecaoFim = null) {
        // ... (sua lógica existente para filtrar locais)
        const idsPermitidos = idsPermitidosStr ? String(idsPermitidosStr).split(',').map(id => String(id).trim()) : [];
        let valorSelecionarInicio = valorPreselecaoInicio !== null ? valorPreselecaoInicio : $localInicioSelect.val();
        let valorSelecionarFim = valorPreselecaoFim !== null ? valorPreselecaoFim : $localFimSelect.val();

        $localInicioSelect.html('<option value=""></option>'); 
        $localFimSelect.html('<option value=""></option>');   

        todosOsLocaisData.forEach(function(local) {
            let incluirLocal = false;
            if (localFixoId && String(local.id) === String(localFixoId)) {
                incluirLocal = true;
                valorSelecionarInicio = local.id; 
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
                $localInicioSelect.append(new Option(local.text, local.id));
                $localFimSelect.append(new Option(local.text, local.id));
            }
        });
        
        $localInicioSelect.val(valorSelecionarInicio).trigger('change.select2');
        $localFimSelect.val(valorSelecionarFim).trigger('change.select2');

        if (localFixoId) {
            $localInicioSelect.prop('disabled', true);
            $localFimSelect.prop('disabled', true);
        } else {
            $localInicioSelect.prop('disabled', false);
            $localFimSelect.prop('disabled', false);
        }
    }

    // --- INÍCIO: Lógica para WorkID dinâmico para Linha de Ônibus ---
    function carregarWorkIDsDisponiveis() {
        const linhaId = $linhaOrigemSelectWorkID.val(); // Usa o select de linha de origem
        const dataEscala = $dataEscalaInputWorkID.val();
        const tipoEscalaAtual = $tipoEscalaSelect.val();

        // Só carrega se o tipo de escala for 'linha' E linha e data estiverem selecionados
        if (tipoEscalaAtual === 'linha' && linhaId && dataEscala) {
            $workIdSelectLinha.prop('disabled', true).html('<option value="">Carregando WorkIDs...</option>');
            $workIdSugestaoText.addClass('form-text feedback-loading').text('Buscando WorkIDs para linha ' + $linhaOrigemSelectWorkID.find('option:selected').text() + '...');
            
            $.ajax({
                url: 'buscar_workids_disponiveis_ajax.php', // Seu script AJAX
                type: 'POST',
                data: { linha_id: linhaId, data_escala: dataEscala },
                dataType: 'json',
                success: function(response) {
                    $workIdSelectLinha.prop('disabled', false).empty();
                    let workIdEncontradoNaLista = false;

                    if (response.success && response.workids && response.workids.length > 0) {
                        $workIdSelectLinha.append('<option value="">Selecione um WorkID...</option>');
                        $.each(response.workids, function(index, workid) {
                            const selected = (workid === valorOriginalWorkIdJs); // Compara com o valor original do BD
                            $workIdSelectLinha.append($('<option>', { value: workid, text: workid, selected: selected }));
                            if (selected) workIdEncontradoNaLista = true;
                        });
                        $workIdSugestaoText.html('<span class="feedback-success">WorkIDs carregados.</span>');
                        // Se o WorkID original não estiver na lista, você pode querer adicioná-lo ou notificar
                        if (valorOriginalWorkIdJs && !workIdEncontradoNaLista && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                             $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                             $workIdSugestaoText.append(' O WorkID salvo ('+valorOriginalWorkIdJs+') foi mantido.');
                        }
                    } else {
                        $workIdSelectLinha.append('<option value="">Nenhum WorkID encontrado</option>');
                        $workIdSugestaoText.text(response.message).html('<span class="feedback-error">Nenhum WorkID compatível.</span>');
                         if (valorOriginalWorkIdJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                            $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                            $workIdSugestaoText.append(' O WorkID salvo ('+valorOriginalWorkIdJs+') foi mantido.');
                        }
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("Erro AJAX buscar WorkIDs:", textStatus, errorThrown, jqXHR.responseText);
                    $workIdSelectLinha.prop('disabled', false).html('<option value="">Erro ao carregar</option>');
                    $workIdSugestaoText.html('<span class="feedback-error">Erro ao carregar WorkIDs.</span>');
                    if (valorOriginalWorkIdJs && <?php echo json_encode($modo_edicao_escala_php); ?>) { // Mantém o original em caso de erro na edição
                         $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                    }
                }
            });
        } else if (tipoEscalaAtual === 'linha') {
            $workIdSelectLinha.html('<option value="">Selecione Linha e Data...</option>');
            $workIdSugestaoText.html('<span class="feedback-info">Selecione a linha e a data para carregar WorkIDs.</span>');
            if (valorOriginalWorkIdJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
            }
        }
    }

    // Listeners
    $tipoEscalaSelect.on('change', function() {
        let currentWorkId = $statusCheckboxes.is(':checked') ? $workIdInputText.val() : 
                           ($tipoEscalaSelect.val() === 'funcao' ? $workIdInputText.val() : $workIdSelectLinha.val());
        const statusEspeciais = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        if(!statusEspeciais.includes(currentWorkId.toUpperCase())){ valorOriginalWorkIdJs = currentWorkId; } // Guarda antes de mudar
        atualizarVisibilidadeCampos();
        carregarWorkIDsDisponiveis(); // Também chama aqui caso o tipo mude para linha
    });

    $funcaoSelect.on('change', function() { $turnoFuncaoSelect.val(null).trigger('change'); $posicaoLetraSelect.val(null).trigger('change'); atualizarCamposFuncao(); });
    $turnoFuncaoSelect.on('change', montarWorkIDSugerido);
    $posicaoLetraSelect.on('change', montarWorkIDSugerido);
    $localInicioSelect.on('change', montarWorkIDSugerido); // Se local afeta WorkID de função

    $statusCheckboxes.on('change', function() {
        const $checkboxAtual = $(this);
        let currentWorkIdVal = $workIdInputText.val();
        if ($checkboxAtual.is(':checked')) {
            const statusEspeciais = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
            if (!statusEspeciais.includes(currentWorkIdVal.toUpperCase())) { valorOriginalWorkIdJs = currentWorkIdVal;}
            $statusCheckboxes.not($checkboxAtual).prop('checked', false);
        }
        atualizarVisibilidadeCampos();
    });

    // NOVO: Listeners para linha e data para carregar WorkIDs de linha
    $linhaOrigemSelectWorkID.on('change', carregarWorkIDsDisponiveis);
    $dataEscalaInputWorkID.on('change', carregarWorkIDsDisponiveis);
    
    // Chamadas iniciais
    atualizarVisibilidadeCampos(); // Configura a visibilidade inicial dos campos
    if (<?php echo json_encode($modo_edicao_escala_php); ?>) {
        if ($tipoEscalaSelect.val() === 'funcao' && $funcaoSelect.val()) {
             atualizarCamposFuncao(); // Popula campos de função na edição
        }
        // Para WorkID de linha na edição, a pré-seleção é feita no PHP e refinada no success do AJAX
        // Se os campos linha e data já estiverem preenchidos (PHP), chama para carregar
        if ($('#linha_origem_id').val() && $('#data_escala').val() && $tipoEscalaSelect.val() === 'linha') {
             carregarWorkIDsDisponiveis();
        }
    }


    // Copiar Dados (seu código existente)
     $('#btnBuscarCopiarEscala').on('click', function() {
        var motoristaOrigemId = $('#copiar_motorista_id_select2').val();
        var dataOrigem = $('#copiar_data_escala_input').val();
        var $feedbackDiv = $('#copiar_escala_feedback');
        $feedbackDiv.html('<small class="text-info"><i class="fas fa-spinner fa-spin"></i> Buscando...</small>');

        if (!motoristaOrigemId || !dataOrigem) {
            $feedbackDiv.html('<small class="text-danger">Selecione Matrícula e Data de Origem para cópia.</small>');
            return;
        }

        $.ajax({
            url: 'buscar_escala_para_copia_ajax.php', type: 'GET', dataType: 'json',
            data: { motorista_id: motoristaOrigemId, data_escala: dataOrigem },
            success: function(response) {
                if (response.success && response.escala) {
                    var esc = response.escala;
                    $feedbackDiv.html('<small class="text-success"><i class="fas fa-check"></i> Dados da escala de ' + (esc.data_formatada_origem || dataOrigem) + ' preenchidos. <strong>Ajuste a "Data da Escala" e "Matrícula" atuais se necessário.</strong></small>');
                    $statusCheckboxes.prop('checked', false);
                    
                    // Define o valorOriginalWorkIdJs com o WorkID copiado para pré-seleção/exibição correta
                    valorOriginalWorkIdJs = esc.work_id || ''; 

                    var workIdCopiadoUpper = (esc.work_id || '').toUpperCase();
                    const statusEspeciaisCopia = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
                    let copiouStatusEspecial = false;
                    if (statusEspeciaisCopia.includes(workIdCopiadoUpper)) {
                        $('#is_' + workIdCopiadoUpper.toLowerCase() + '_check').prop('checked', true);
                        copiouStatusEspecial = true;
                    }
                    
                    let tipoEscalaCopiada = 'linha';
                    if (esc.funcao_operacional_id) tipoEscalaCopiada = 'funcao';
                     else if (!esc.linha_origem_id && !copiouStatusEspecial && esc.work_id) {
                        let funcaoInferida = null;
                        for (const idFuncao in funcoesOperacionaisData) {
                            if (esc.work_id && esc.work_id.startsWith(funcoesOperacionaisData[idFuncao].work_id_prefixo)) {
                                funcaoInferida = funcoesOperacionaisData[idFuncao]; break;
                            }
                        }
                        if (funcaoInferida) { tipoEscalaCopiada = 'funcao'; esc.funcao_operacional_id = funcaoInferida.id; }
                    }
                    $tipoEscalaSelect.val(tipoEscalaCopiada).trigger('change'); // Dispara change para atualizar UI

                    setTimeout(function() { // Pequeno delay para garantir que a UI do tipo de escala se ajustou
                        if (copiouStatusEspecial) {
                             $statusCheckboxes.filter(':checked').trigger('change'); // Atualiza a UI para o status
                        } else if (tipoEscalaCopiada === 'funcao') {
                            var dadosParaFuncaoCopia = { turno: esc.turno_funcao_detectado, posicao: esc.posicao_letra_detectada, localInicio: esc.local_inicio_turno_id, localFim: esc.local_fim_turno_id };
                            $funcaoSelect.val(esc.funcao_operacional_id || null).trigger('change');
                            atualizarCamposFuncao(dadosParaFuncaoCopia);
                            // WorkID para função é construído ou mantido como está, $workIdInputText é usado
                            $('#work_id_input').val(esc.work_id || '');
                        } else { // Linha
                            $('#linha_origem_id').val(esc.linha_origem_id || null).trigger('change.select2');
                            $('#veiculo_id').val(esc.veiculo_id || null).trigger('change.select2');
                            $('#tabela_escalas').val(esc.tabela_escalas || '');
                            // Para linha, o WorkID será carregado pelo AJAX, mas precisamos garantir que o valorOriginalWorkIdJs é o da escala copiada
                            // A função carregarWorkIDsDisponiveis será chamada pelo trigger('change') do $tipoEscalaSelect ou $linha_origem_id
                            // e tentará selecionar o valorOriginalWorkIdJs
                             $('#data_escala').trigger('change'); // Garante que a data está setada para o AJAX de workids
                        }
                        
                        $('#hora_inicio_prevista').val(esc.hora_inicio_prevista || '');
                        if (!(tipoEscalaCopiada === 'funcao' && funcoesOperacionaisData[esc.funcao_operacional_id] && funcoesOperacionaisData[esc.funcao_operacional_id].local_fixo_id)) {
                            $('#local_inicio_turno_id').val(esc.local_inicio_turno_id || null).trigger('change.select2');
                            $('#local_fim_turno_id').val(esc.local_fim_turno_id || null).trigger('change.select2');
                        }
                        $('#hora_fim_prevista').val(esc.hora_fim_prevista || '');
                        $('#eh_extra').prop('checked', esc.eh_extra == 1);
                        
                        // Garante que a visibilidade e os nomes dos campos WorkID estejam corretos
                         atualizarVisibilidadeCampos();
                         if (tipoEscalaCopiada === 'linha' && !copiouStatusEspecial) {
                            // Força o recarregamento dos WorkIDs se os campos relevantes já tiverem valor
                            if ($('#linha_origem_id').val() && $('#data_escala').val()) {
                                carregarWorkIDsDisponiveis();
                            }
                        }

                    }, 350); // Aumentei um pouco o delay
                } else { /* ... erro ... */ }
            }, error: function() { /* ... erro ... */ }
        });
    });

    // Submit (seu código existente)
    $('#form-escala-planejada').on('submit', function(e) { 
        var isStatusChecked = $statusCheckboxes.is(':checked');
        if (!isStatusChecked) { 
            if ($tipoEscalaSelect.val() === 'linha' && ($('#linha_origem_id').val() === '' || $('#linha_origem_id').val() === null )) {
                alert('Linha de Origem é obrigatória para escala de linha.'); e.preventDefault(); return false;
            }
            if ($tipoEscalaSelect.val() === 'funcao' && ($('#funcao_operacional_id_select').val() === '' || $('#funcao_operacional_id_select').val() === null)) {
                alert('Função Operacional é obrigatória para escala de função.'); e.preventDefault(); return false;
            }
            if ($tipoEscalaSelect.val() === 'funcao' && ($('#turno_funcao_select').val() === '' || $('#turno_funcao_select').val() === null)) {
                alert('Turno da Função é obrigatório.'); e.preventDefault(); return false;
            }
            if ($tipoEscalaSelect.val() === 'funcao' && $posicaoLetraWrapper.is(':visible') && ($('#posicao_letra_funcao_select').val() === '' || $('#posicao_letra_funcao_select').val() === null)) {
                alert('Posição/Letra da Função é obrigatória para esta função.'); e.preventDefault(); return false;
            }
            
            // MODIFICADO: Validação do WorkID dependendo do tipo de escala
            let workIdValue = '';
            if ($tipoEscalaSelect.val() === 'linha') {
                workIdValue = $('#work_id_select').val(); // Pega do select
            } else if ($tipoEscalaSelect.val() === 'funcao') {
                workIdValue = $('#work_id_input').val(); // Pega do input de texto
            }

            if (workIdValue === '' || workIdValue === null) {
                alert('WorkID é obrigatório se não for um status especial.'); 
                if ($tipoEscalaSelect.val() === 'linha') $('#work_id_select').focus();
                else $('#work_id_input').focus();
                e.preventDefault(); return false;
            }

            if ($('#hora_inicio_prevista').val() === '' || $('#hora_fim_prevista').val() === '') {
                alert('Hora Início e Hora Fim são obrigatórias se não for um status especial.'); e.preventDefault(); return false;
            }
            if (($('#local_inicio_turno_id').val() === '' || $('#local_inicio_turno_id').val() === null || $('#local_fim_turno_id').val() === '' || $('#local_fim_turno_id').val() === null)) {
                if (!$('#local_inicio_turno_id').is(':disabled')) { 
                     alert('Local de Início e Local de Fim são obrigatórios se não for um status especial ou função com local fixo.'); e.preventDefault(); return false;
                }
            }
        }
        // Adiciona o valor do WorkID correto ao formulário ANTES de submeter,
        // pois agora temos dois campos (input e select) e apenas um deve ter name="work_id" efetivo.
        // O JS na função atualizarVisibilidadeCampos já está trocando o 'name' para o campo ativo.
     });
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once 'admin_footer.php';
?>