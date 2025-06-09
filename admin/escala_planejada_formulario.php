<?php
// admin/escala_planejada_formulario.php
// ATUALIZADO v10: Inclui Tipo de Escala (Linha/Função), WorkID dinâmico para Função e Linha,
// e Select de Veículo dinâmico via AJAX e obrigatório.

require_once 'auth_check.php';

// --- Permissões ---
$niveis_permitidos_gerenciar_escala = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerenciar_escala)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para gerenciar a Escala Planejada.";
    header('Location: index.php'); // Ou para um dashboard principal do admin
    exit;
}

require_once '../db_config.php';
$page_title_action = 'Adicionar Entrada na Escala Planejada';

// --- Inicialização de Variáveis do Formulário ---
$escala_id_edit = null;
$tipo_escala_form_php = 'linha'; // Default
$data_escala_form_php = date('Y-m-d');
$motorista_id_form_php = '';
$motorista_texto_repop_php = '';

// Para Linha
$linha_origem_id_form_php = '';
$veiculo_id_db_php = ''; // ID do veículo salvo no banco (para edição)
$veiculo_prefixo_db_php = ''; // Prefixo do veículo salvo (para exibição inicial na edição)

// Para Função Operacional
$funcao_operacional_id_form_php = '';
$turno_funcao_form_php = '';
$posicao_letra_form_php = '';

// Comuns (WorkID será preenchido dinamicamente ou pelo status)
$work_id_form_php = '';
$tabela_escalas_form_php = '';
$hora_inicio_form_php = '';
$local_inicio_id_form_php = '';
$hora_fim_form_php = '';
$local_fim_id_form_php = '';
$eh_extra_form_php = 0;

// Para Status Especiais
$is_folga_check_php = false;
$is_falta_check_php = false;
$is_fora_escala_check_php = false;
$is_ferias_check_php = false;
$is_atestado_check_php = false;

$modo_edicao_escala_php = false;

// Listas para Selects
$lista_linhas_select_php = [];
$lista_locais_select_php = [];
// $lista_veiculos_select_php não é mais carregada aqui, será via AJAX
$lista_funcoes_operacionais_php = [];

if ($pdo) {
    try {
        $stmt_linhas_all = $pdo->query("SELECT id, numero, nome FROM linhas WHERE status_linha = 'ativa' ORDER BY CAST(numero AS UNSIGNED), numero, nome ASC");
        $lista_linhas_select_php = $stmt_linhas_all->fetchAll(PDO::FETCH_ASSOC);

        $stmt_locais_all = $pdo->query("SELECT id, nome, tipo FROM locais ORDER BY nome ASC");
        $lista_locais_select_php = $stmt_locais_all->fetchAll(PDO::FETCH_ASSOC);
        
        // Veículos não são mais carregados aqui, mas funções sim.
        $stmt_funcoes = $pdo->query("SELECT id, nome_funcao, work_id_prefixo, locais_permitidos_tipo, locais_permitidos_ids, local_fixo_id, turnos_disponiveis, requer_posicao_especifica, max_posicoes_por_turno, ignorar_validacao_jornada FROM funcoes_operacionais WHERE status = 'ativa' ORDER BY nome_funcao ASC");
        $lista_funcoes_operacionais_php = $stmt_funcoes->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $_SESSION['admin_warning_message'] = "Atenção: Erro ao carregar algumas opções de seleção para o formulário.";
        error_log("Erro ao carregar dados para formulário de escala: " . $e->getMessage());
    }
}

// --- Lógica de Edição ---
if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $escala_id_edit = (int)$_GET['id'];
    $modo_edicao_escala_php = true;
    $page_title_action = 'Editar Entrada da Escala Planejada';
    if ($pdo) {
        try {
            $sql_get_escala = "SELECT esc.*, 
                                      mot.nome as nome_motorista_atual, mot.matricula as matricula_motorista_atual,
                                      veic.prefixo as prefixo_veiculo_atual 
                               FROM motorista_escalas esc 
                               LEFT JOIN motoristas mot ON esc.motorista_id = mot.id 
                               LEFT JOIN veiculos veic ON esc.veiculo_id = veic.id
                               WHERE esc.id = :id_escala";
            $stmt_get_escala = $pdo->prepare($sql_get_escala);
            $stmt_get_escala->bindParam(':id_escala', $escala_id_edit, PDO::PARAM_INT);
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

                if (!empty($funcao_operacional_id_form_php)) {
                    $tipo_escala_form_php = 'funcao';
                    $funcao_obj_edit = null;
                    foreach($lista_funcoes_operacionais_php as $f){
                        if(strval($f['id']) === strval($funcao_operacional_id_form_php)){
                            $funcao_obj_edit = $f;
                            break;
                        }
                    }
                    if ($funcao_obj_edit && $work_id_form_php) {
                        $prefixo_func_edit = $funcao_obj_edit['work_id_prefixo'];
                        $sem_prefixo_edit = preg_replace('/^'.preg_quote($prefixo_func_edit, '/').'-?/i', '', $work_id_form_php);
                        if (!$funcao_obj_edit['local_fixo_id']) { // Remove o local curto se não for fixo
                             $sem_prefixo_edit = preg_replace('/^[A-Z0-9]{1,3}-/i', '', $sem_prefixo_edit);
                        }
                        $partes_turno_pos_edit = explode('-', $sem_prefixo_edit);
                        $ultimo_segmento_edit = array_pop($partes_turno_pos_edit);
                        
                        if($funcao_obj_edit['requer_posicao_especifica'] && strlen($ultimo_segmento_edit) > 2 && ctype_alpha(substr($ultimo_segmento_edit,-1))){
                            $posicao_letra_form_php = strtoupper(substr($ultimo_segmento_edit,-1));
                            $turno_funcao_form_php = substr($ultimo_segmento_edit,0,-1);
                        } elseif (strlen($ultimo_segmento_edit) == 2 && ctype_digit($ultimo_segmento_edit)){
                           $turno_funcao_form_php = $ultimo_segmento_edit;
                           $posicao_letra_form_php = '';
                        } // Else: pode ser um formato diferente ou só o turno, precisa de mais lógica se houver outros padrões
                    }
                } else {
                    $tipo_escala_form_php = 'linha';
                    $linha_origem_id_form_php = $escala_db['linha_origem_id'];
                    $veiculo_id_db_php = $escala_db['veiculo_id'];
                    $veiculo_prefixo_db_php = $escala_db['prefixo_veiculo_atual'];
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
                    // $linha_origem_id_form_php já foi tratado acima para tipo função
                    // $veiculo_id_db_php também (não se aplica a função ou status especial)
                }
                $hora_inicio_form_php = $is_status_especial ? '' : ($escala_db['hora_inicio_prevista'] ? date('H:i', strtotime($escala_db['hora_inicio_prevista'])) : '');
                $local_inicio_id_form_php = $is_status_especial ? '' : $escala_db['local_inicio_turno_id'];
                $hora_fim_form_php = $is_status_especial ? '' : ($escala_db['hora_fim_prevista'] ? date('H:i', strtotime($escala_db['hora_fim_prevista'])) : '');
                $local_fim_id_form_php = $is_status_especial ? '' : $escala_db['local_fim_turno_id'];
                $eh_extra_form_php = $is_status_especial ? 0 : $escala_db['eh_extra'];
                $page_title_action .= ' (' . $motorista_texto_repop_php . ' em ' . date('d/m/Y', strtotime($data_escala_form_php)) . ')';
            } else { 
                $_SESSION['admin_error_message'] = "Entrada da Escala Planejada ID {$escala_id_edit} não encontrada.";
                header('Location: escala_planejada_listar.php?' . http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))));
                exit;
             }
        } catch (PDOException $e) { 
            $_SESSION['admin_error_message'] = "Erro ao carregar dados da escala para edição.";
            error_log("Erro PDO ao buscar escala para edição: " . $e->getMessage());
            header('Location: escala_planejada_listar.php?' . http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))));
            exit;
        }
    }
}

$page_title = $page_title_action;
require_once 'admin_header.php';

// --- Repopulação do Formulário ---
$form_data_repop_session = $_SESSION['form_data_escala_planejada'] ?? [];
if(!empty($form_data_repop_session)) {
    $tipo_escala_form_php = $form_data_repop_session['tipo_escala'] ?? $tipo_escala_form_php;
    $data_escala_form_php = $form_data_repop_session['data_escala'] ?? $data_escala_form_php;
    $motorista_id_form_php = $form_data_repop_session['motorista_id'] ?? $motorista_id_form_php;
    if ($motorista_id_form_php && empty($motorista_texto_repop_php) && $pdo) { /* ... busca nome motorista ... */ }
    
    $linha_origem_id_form_php = $form_data_repop_session['linha_origem_id'] ?? $linha_origem_id_form_php;
    $veiculo_id_db_php = $form_data_repop_session['veiculo_id'] ?? $veiculo_id_db_php; // Repopula ID do veículo
    if ($veiculo_id_db_php && empty($veiculo_prefixo_db_php) && $pdo) { /* ... busca prefixo veículo ... */ }

    $funcao_operacional_id_form_php = $form_data_repop_session['funcao_operacional_id'] ?? $funcao_operacional_id_form_php;
    $turno_funcao_form_php = $form_data_repop_session['turno_funcao'] ?? $turno_funcao_form_php;
    $posicao_letra_form_php = $form_data_repop_session['posicao_letra_funcao'] ?? $posicao_letra_form_php;

    $is_folga_check_php = isset($form_data_repop_session['is_folga_check']);
    $is_falta_check_php = isset($form_data_repop_session['is_falta_check']);
    // ... etc. para outros status
    
    $work_id_repop_val = $form_data_repop_session['work_id'] ?? ($form_data_repop_session['work_id_select_input_disabled'] ?? ($form_data_repop_session['work_id_text_input_disabled'] ?? $work_id_form_php) );
    if ($is_folga_check_php) $work_id_form_php = 'FOLGA';
    // ... etc. para outros status
    else $work_id_form_php = $work_id_repop_val;
    
    $is_status_especial_repop = $is_folga_check_php; // ... etc.
    $tabela_escalas_form_php = ($is_status_especial_repop || $tipo_escala_form_php === 'funcao') ? '' : ($form_data_repop_session['tabela_escalas'] ?? $tabela_escalas_form_php);
    if (($tipo_escala_form_php === 'funcao' || $is_status_especial_repop)) { 
        $linha_origem_id_form_php = ''; 
        $veiculo_id_db_php = ''; // Limpa veículo se for função/status
        $veiculo_prefixo_db_php = '';
    }
    // ... (restante da repopulação)
    unset($_SESSION['form_data_escala_planejada']);
}

// Passar dados PHP para JavaScript
$js_work_id_inicial_php = $work_id_form_php;
$js_funcoes_operacionais_data = []; foreach($lista_funcoes_operacionais_php as $func) { $js_funcoes_operacionais_data[$func['id']] = $func; }
$js_locais_data_todos = []; foreach ($lista_locais_select_php as $loc) { $js_locais_data_todos[] = ['id' => $loc['id'], 'text' => htmlspecialchars($loc['nome']), 'tipo' => strtolower($loc['tipo'] ?? '')]; }
$js_veiculo_id_atual_php = $veiculo_id_db_php;
$js_veiculo_prefixo_atual_php = $veiculo_prefixo_db_php;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title_action); ?></h1>
    <a href="escala_planejada_listar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Voltar para Lista
    </a>
</div>

<?php
if (isset($_SESSION['admin_form_error_escala_p'])) { echo '<div class="alert alert-danger alert-dismissible fade show">' . nl2br(htmlspecialchars($_SESSION['admin_form_error_escala_p'])) . '<button type="button" class="close" data-dismiss="alert">&times;</button></div>'; unset($_SESSION['admin_form_error_escala_p']); }
?>

<form action="escala_planejada_processa.php" method="POST" id="form-escala-planejada">
    <?php if ($modo_edicao_escala_php && $escala_id_edit): ?>
        <input type="hidden" name="escala_id" value="<?php echo $escala_id_edit; ?>">
    <?php endif; ?>
    <?php
     $params_to_preserve_submit_planejada = ['pagina_original' => 'pagina', 'filtro_data_original' => 'data_escala', 'filtro_tipo_busca_original' => 'tipo_busca_adicional', 'filtro_valor_busca_original' => 'valor_busca_adicional'];
    foreach ($params_to_preserve_submit_planejada as $hidden_name_planejada => $get_key_planejada):
        if (isset($_GET[$get_key_planejada])):
    ?>
        <input type="hidden" name="<?php echo htmlspecialchars($hidden_name_planejada); ?>" value="<?php echo htmlspecialchars($_GET[$get_key_planejada]); ?>">
    <?php endif; endforeach; ?>

    <fieldset class="mb-4 border p-3 rounded bg-light">
        <legend class="w-auto px-2 h6 text-secondary font-weight-normal">Copiar Dados de Escala Planejada Existente (Opcional)</legend>
        <div class="form-row">
            <div class="form-group col-md-5">
                <label for="copiar_motorista_id_select2" class="small">Motorista da Escala de Origem:</label>
                <select class="form-control form-control-sm" id="copiar_motorista_id_select2" data-placeholder="Buscar motorista para copiar...">
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
        <div class="form-group col-md-3">
            <label for="data_escala">Data da Escala <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="data_escala" name="data_escala" value="<?php echo htmlspecialchars($data_escala_form_php); ?>" required>
        </div>
        <div class="form-group col-md-5">
            <label for="motorista_id_select2_escala">Motorista <span class="text-danger">*</span></label>
            <select class="form-control" id="motorista_id_select2_escala" name="motorista_id" required data-placeholder="Selecione ou digite nome/matrícula...">
                <?php if ($motorista_id_form_php && !empty($motorista_texto_repop_php)): ?>
                    <option value="<?php echo htmlspecialchars($motorista_id_form_php); ?>" selected><?php echo $motorista_texto_repop_php; ?></option>
                <?php elseif ($motorista_id_form_php): // Caso só tenhamos o ID (ex: erro de validação sem que o nome tenha sido carregado) ?>
                     <option value="<?php echo htmlspecialchars($motorista_id_form_php); ?>" selected>ID: <?php echo htmlspecialchars($motorista_id_form_php); ?> (Carregando...)</option>
                <?php else: ?><option></option><?php endif; ?>
            </select>
        </div>
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

    <div id="campos_escala_linha_wrapper"> <div class="form-row">
            <div class="form-group col-md-8">
                <label for="linha_origem_id">Linha de Origem (Principal) <span class="text-danger">*</span></label>
                <select class="form-control select2-simple" id="linha_origem_id" name="linha_origem_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach ($lista_linhas_select_php as $l):?>
                        <option value="<?php echo $l['id'];?>" <?php if(strval($l['id'])==strval($linha_origem_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($l['numero'].($l['nome']?' - '.$l['nome']:''));?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label for="veiculo_id_ajax">Veículo <span class="text-danger" id="veiculo_obrigatorio_asterisco">*</span></label>
                <select class="form-control" id="veiculo_id_ajax" name="veiculo_id" data-placeholder="Selecione uma linha primeiro...">
                    <option value="">Selecione uma linha...</option>
                    <?php if ($modo_edicao_escala_php && !empty($veiculo_id_db_php) && !empty($veiculo_prefixo_db_php) && $tipo_escala_form_php === 'linha'): ?>
                        <option value="<?php echo htmlspecialchars($veiculo_id_db_php); ?>" selected>
                            <?php echo htmlspecialchars($veiculo_prefixo_db_php); ?> (Salvo)
                        </option>
                    <?php endif; ?>
                </select>
                <small id="veiculo_id_ajax_feedback" class="form-text"></small>
            </div>
        </div>
    </div>

    <div id="todos_campos_funcao_wrapper"> <div class="form-row">
            <div class="form-group col-md-12">
                <label for="funcao_operacional_id_select">Função Operacional <span class="text-danger">*</span></label>
                <select class="form-control select2-simple" id="funcao_operacional_id_select" name="funcao_operacional_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach($lista_funcoes_operacionais_php as $fo):?>
                        <option value="<?php echo $fo['id'];?>" 
                                data-prefixo="<?php echo htmlspecialchars($fo['work_id_prefixo']);?>"
                                data-locais-tipo="<?php echo htmlspecialchars($fo['locais_permitidos_tipo']??'');?>"
                                data-locais-ids="<?php echo htmlspecialchars($fo['locais_permitidos_ids']??'');?>"
                                data-local-fixo-id="<?php echo htmlspecialchars($fo['local_fixo_id']??'');?>"
                                data-turnos="<?php echo htmlspecialchars($fo['turnos_disponiveis']);?>"
                                data-requer-posicao="<?php echo $fo['requer_posicao_especifica']?'true':'false';?>"
                                data-max-posicoes="<?php echo htmlspecialchars($fo['max_posicoes_por_turno']??'0');?>"
                                data-ignora-jornada="<?php echo $fo['ignorar_validacao_jornada']?'true':'false';?>"
                                <?php if(strval($fo['id'])==strval($funcao_operacional_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($fo['nome_funcao']);?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="turno_funcao_select">Turno da Função <span class="text-danger">*</span></label>
                <select class="form-control" id="turno_funcao_select" name="turno_funcao">
                    <option value="">Selecione...</option>
                    </select>
            </div>
            <div class="form-group col-md-4" id="wrapper_posicao_letra_funcao" style="display:none;">
                <label for="posicao_letra_funcao_select">Posição/Letra <span class="text-danger">*</span></label>
                <select class="form-control" id="posicao_letra_funcao_select" name="posicao_letra_funcao">
                    <option value="">Selecione...</option>
                    </select>
            </div>
        </div>
    </div>

    <div id="campos_comuns_escala_wrapper"> <div class="form-row">
            <div class="form-group col-md-4" id="div_work_id_campo_unico">
                <label for="work_id_input">WorkID <span id="work_id_obrigatorio_asterisco" class="text-danger">*</span></label>
                <input type="text" class="form-control" id="work_id_input" name="work_id_text_input_disabled" 
                       value="<?php echo htmlspecialchars($work_id_form_php); ?>" maxlength="50">
                <select class="form-control" id="work_id_select" name="work_id_select_input_disabled">
                    <option value="">Selecione Linha e Data...</option>
                    <?php if ($modo_edicao_escala_php && $tipo_escala_form_php === 'linha' && !empty($work_id_form_php) && !$is_folga_check_php && !$is_falta_check_php && !$is_fora_escala_check_php && !$is_ferias_check_php && !$is_atestado_check_php): ?>
                        <option value="<?php echo htmlspecialchars($work_id_form_php); ?>" selected><?php echo htmlspecialchars($work_id_form_php); ?> (Salvo)</option>
                    <?php endif; ?>
                </select>
                <small class="form-text" id="work_id_sugestao_text"></small>
            </div>
            <div class="form-group col-md-4" id="wrapper_tabela_escalas">
                <label for="tabela_escalas">Nº Tabela da Escala</label>
                <input type="text" class="form-control" id="tabela_escalas" name="tabela_escalas" value="<?php echo htmlspecialchars($tabela_escalas_form_php); ?>" maxlength="10">
            </div>
            <div class="form-group col-md-4 d-flex align-items-center pt-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="eh_extra" name="eh_extra" <?php echo ($eh_extra_form_php==1)?'checked':'';?>>
                    <label class="form-check-label" for="eh_extra">Turno Extra?</label>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="hora_inicio_prevista">Hora Início <span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="hora_inicio_prevista" name="hora_inicio_prevista" value="<?php echo htmlspecialchars($hora_inicio_form_php);?>">
            </div>
            <div class="form-group col-md-3">
                <label for="local_inicio_turno_id">Local Início <span class="text-danger">*</span></label>
                <select class="form-control select2-simple" id="local_inicio_turno_id" name="local_inicio_turno_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach($lista_locais_select_php as $li):?>
                        <option value="<?php echo $li['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($li['tipo']??''));?>" <?php if(strval($li['id'])==strval($local_inicio_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($li['nome']);?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="hora_fim_prevista">Hora Fim <span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="hora_fim_prevista" name="hora_fim_prevista" value="<?php echo htmlspecialchars($hora_fim_form_php);?>">
            </div>
            <div class="form-group col-md-3">
                <label for="local_fim_turno_id">Local Fim <span class="text-danger">*</span></label>
                <select class="form-control select2-simple" id="local_fim_turno_id" name="local_fim_turno_id" data-placeholder="Selecione...">
                    <option value=""></option>
                    <?php foreach($lista_locais_select_php as $lf):?>
                        <option value="<?php echo $lf['id'];?>" data-tipo="<?php echo strtolower(htmlspecialchars($lf['tipo']??''));?>" <?php if(strval($lf['id'])==strval($local_fim_id_form_php))echo 'selected';?>>
                            <?php echo htmlspecialchars($lf['nome']);?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>
        </div>
    </div>
    <hr>
    <button type="submit" name="salvar_escala_planejada" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Entrada</button>
    <a href="escala_planejada_listar.php?<?php echo http_build_query(array_intersect_key($_GET, array_flip(['pagina', 'data_escala', 'tipo_busca_adicional', 'valor_busca_adicional']))); ?>" class="btn btn-secondary">Cancelar</a>
</form>

<?php
ob_start(); // Captura o JavaScript
?>
<script>
    // Passar dados PHP para JavaScript de forma segura
    const funcoesOperacionaisData = <?php echo json_encode($js_funcoes_operacionais_data); ?>;
    const todosOsLocaisData = <?php echo json_encode($js_locais_data_todos); ?>;
    var valorOriginalWorkIdJs = <?php echo json_encode($js_work_id_inicial_php); ?>;
    const veiculoIdAtualPhp = <?php echo json_encode($js_veiculo_id_atual_php); ?>;
    const veiculoPrefixoAtualPhp = <?php echo json_encode($js_veiculo_prefixo_atual_php); ?>;

$(document).ready(function() {
    // --- Seletores Globais do Formulário ---
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
    
    const $workIdInputText = $('#work_id_input');
    const $workIdSelectLinha = $('#work_id_select');
    const $workIdSugestaoText = $('#work_id_sugestao_text');
    const $linhaOrigemSelectWorkID = $('#linha_origem_id'); 
    const $dataEscalaInputWorkID = $('#data_escala');

    const $linhaOrigemSelectVeiculo = $('#linha_origem_id');
    const $veiculoSelectAjax = $('#veiculo_id_ajax');
    const $veiculoFeedbackAjax = $('#veiculo_id_ajax_feedback');
    const $veiculoObrigatorioAsterisco = $('#veiculo_obrigatorio_asterisco');

    // --- Inicialização de Plugins (Select2) ---
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

    $('#linha_origem_id, #funcao_operacional_id_select, #local_inicio_turno_id, #local_fim_turno_id').each(function() {
        $(this).select2({ theme: 'bootstrap4', placeholder: $(this).data('placeholder') || 'Selecione...', allowClear: true, width: '100%' });
    });
    // O select de Veículo (#veiculo_id_ajax) não precisa de Select2 complexo inicialmente.
    // Poderia ser transformado em Select2 com busca após ser populado, se desejado.
    // $veiculoSelectAjax.select2({ theme: 'bootstrap4', placeholder: 'Selecione...', allowClear: true, width: '100%' });


    // --- Funções Auxiliares ---
    function carregarVeiculosCompativelComLinha() {
        const linhaId = $linhaOrigemSelectVeiculo.val();
        const dataEscala = $dataEscalaInputWorkID.val(); // A data da escala também é relevante
        const tipoEscalaAtual = $tipoEscalaSelect.val();

        $veiculoSelectAjax.prop('disabled', true).html('<option value="">Carregando veículos...</option>');
        $veiculoFeedbackAjax.removeClass('text-success text-danger text-info').addClass('text-muted').text('Buscando veículos compatíveis...');

        if (tipoEscalaAtual === 'linha' && linhaId && dataEscala && !$statusCheckboxes.is(':checked')) {
            $.ajax({
                url: 'buscar_veiculos_por_linha_ajax.php',
                type: 'GET', data: { linha_id: linhaId }, dataType: 'json',
                success: function(response) {
                    $veiculoSelectAjax.prop('disabled', false).empty();
                    if (response.success && response.veiculos && response.veiculos.length > 0) {
                        $veiculoSelectAjax.append('<option value="">Selecione um veículo...</option>');
                        let veiculoAtualEncontrado = false;
                        $.each(response.veiculos, function(index, veiculo) {
                            const selected = (String(veiculo.id) === String(veiculoIdAtualPhp));
                            $veiculoSelectAjax.append($('<option>', { value: veiculo.id, text: veiculo.text, selected: selected }));
                            if (selected) veiculoAtualEncontrado = true;
                        });
                        $veiculoFeedbackAjax.removeClass('text-muted text-danger').addClass('text-success').text('Veículos carregados.');
                        if (<?php echo json_encode($modo_edicao_escala_php); ?> && veiculoIdAtualPhp && !veiculoAtualEncontrado && veiculoPrefixoAtualPhp) {
                            $veiculoSelectAjax.append($('<option>', { value: veiculoIdAtualPhp, text: veiculoPrefixoAtualPhp + ' (Salvo - Verificar compatibilidade)', selected: true, style: 'color:orange;' }));
                            $veiculoFeedbackAjax.append(' <span class="text-warning">Atenção: O veículo salvo pode não ser mais compatível.</span>');
                        }
                    } else {
                        $veiculoSelectAjax.append('<option value="">Nenhum veículo compatível</option>');
                        $veiculoFeedbackAjax.removeClass('text-muted text-success').addClass('text-danger').text(response.message || 'Nenhum veículo compatível.');
                         if (<?php echo json_encode($modo_edicao_escala_php); ?> && veiculoIdAtualPhp && veiculoPrefixoAtualPhp) {
                            $veiculoSelectAjax.append($('<option>', { value: veiculoIdAtualPhp, text: veiculoPrefixoAtualPhp + ' (Salvo - Verificar compatibilidade)', selected: true, style: 'color:orange;' }));
                        }
                    }
                },
                error: function() {
                    $veiculoSelectAjax.prop('disabled', false).html('<option value="">Erro ao carregar</option>');
                    $veiculoFeedbackAjax.removeClass('text-muted text-success').addClass('text-danger').text('Erro ao buscar veículos.');
                }
            });
        } else {
            $veiculoSelectAjax.prop('disabled', true);
            if (tipoEscalaAtual !== 'linha' || $statusCheckboxes.is(':checked')) {
                 $veiculoSelectAjax.html('<option value="">Não aplicável</option>');
                 $veiculoFeedbackAjax.text('');
            } else {
                 $veiculoSelectAjax.html('<option value="">Selecione uma linha...</option>');
                 $veiculoFeedbackAjax.text('Selecione uma linha para carregar os veículos.');
            }
        }
    }
    
    function carregarWorkIDsDisponiveis() { /* ... (Lógica da v9 para WorkID de Linha) ... */ 
        const linhaId = $linhaOrigemSelectWorkID.val(); 
        const dataEscala = $dataEscalaInputWorkID.val();
        const tipoEscalaAtual = $tipoEscalaSelect.val();

        if (tipoEscalaAtual === 'linha' && linhaId && dataEscala && !$statusCheckboxes.is(':checked')) {
            $workIdSelectLinha.prop('disabled', true).html('<option value="">Carregando WorkIDs...</option>');
            $workIdSugestaoText.removeClass('feedback-success feedback-error feedback-info').addClass('feedback-loading').html('<span><i class="fas fa-spinner fa-spin"></i> Buscando WorkIDs...</span>').show();
            
            $.ajax({
                url: 'buscar_workids_disponiveis_ajax.php',
                type: 'POST', data: { linha_id: linhaId, data_escala: dataEscala }, dataType: 'json',
                success: function(response) {
                    $workIdSelectLinha.prop('disabled', false).empty();
                    let workIdEncontradoNaLista = false;
                    if (response.success && response.workids && response.workids.length > 0) {
                        $workIdSelectLinha.append('<option value="">Selecione um WorkID...</option>');
                        $.each(response.workids, function(index, workid) {
                            const selected = (workid === valorOriginalWorkIdJs);
                            $workIdSelectLinha.append($('<option>', { value: workid, text: workid, selected: selected }));
                            if (selected) workIdEncontradoNaLista = true;
                        });
                        $workIdSugestaoText.removeClass('feedback-loading feedback-error feedback-info').addClass('feedback-success').html('<span><i class="fas fa-check-circle"></i> WorkIDs carregados.</span>').show();
                        if (valorOriginalWorkIdJs && !workIdEncontradoNaLista && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                             $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                             $workIdSugestaoText.append(' <span>O WorkID salvo ('+valorOriginalWorkIdJs+') foi mantido.</span>');
                        }
                    } else {
                        $workIdSelectLinha.append('<option value="">Nenhum WorkID encontrado</option>');
                        $workIdSugestaoText.removeClass('feedback-loading feedback-success feedback-info').addClass('feedback-error').html('<span><i class="fas fa-exclamation-triangle"></i> '+(response.message || 'Nenhum WorkID compatível.')+'</span>').show();
                         if (valorOriginalWorkIdJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                            $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                            $workIdSugestaoText.append(' <span>O WorkID salvo ('+valorOriginalWorkIdJs+') foi mantido.</span>');
                        }
                    }
                },
                error: function() { 
                    $workIdSelectLinha.prop('disabled', false).html('<option value="">Erro ao carregar</option>');
                    $workIdSugestaoText.removeClass('feedback-loading feedback-success feedback-info').addClass('feedback-error').html('<span><i class="fas fa-times-circle"></i> Erro ao carregar WorkIDs.</span>').show();
                     if (valorOriginalWorkIdJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                         $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                    }
                }
            });
        } else if (tipoEscalaAtual === 'linha' && !$statusCheckboxes.is(':checked')) {
            $workIdSelectLinha.html('<option value="">Selecione Linha e Data...</option>');
            $workIdSugestaoText.removeClass('feedback-success feedback-error feedback-loading').addClass('feedback-info').html('<span><i class="fas fa-info-circle"></i> Selecione Linha e Data.</span>').show();
            if (valorOriginalWorkIdJs && <?php echo json_encode($modo_edicao_escala_php); ?>) {
                if ($workIdSelectLinha.find("option[value='" + valorOriginalWorkIdJs + "']").length === 0) {
                    $workIdSelectLinha.append($('<option>', { value: valorOriginalWorkIdJs, text: valorOriginalWorkIdJs + ' (Salvo)', selected: true }));
                } else { $workIdSelectLinha.val(valorOriginalWorkIdJs); }
            }
        } else if (tipoEscalaAtual !== 'linha'){ // Se for função ou status
            $workIdSelectLinha.empty().append('<option value="">Não aplicável</option>');
        }
    }

    function atualizarVisibilidadeCampos() { /* ... (Lógica da v9, com adaptações para o novo campo de veículo) ... */ 
        const tipoSelecionado = $tipoEscalaSelect.val();
        let algumStatusMarcado = $statusCheckboxes.is(':checked');
        
        $workIdInputText.attr('name', 'work_id_text_input_disabled').hide();
        $workIdSelectLinha.attr('name', 'work_id_select_input_disabled').hide();
        $veiculoSelectAjax.prop('required', false); $veiculoObrigatorioAsterisco.hide();

        $('#linha_origem_id, #funcao_operacional_id_select, #turno_funcao_select, #posicao_letra_funcao_select, #local_inicio_turno_id, #local_fim_turno_id, #hora_inicio_prevista, #hora_fim_prevista').prop('required', false);
        $workIdInputText.prop('required', false).prop('readonly', false);
        $workIdSelectLinha.prop('required', false);

        if (algumStatusMarcado) {
            $tipoEscalaSelect.prop('disabled', true).val('linha').trigger('change.select2'); // Força para linha, mas campos estarão escondidos
            $camposLinhaWrapper.hide();
            $todosCamposFuncaoWrapper.hide();
            $camposComunsWrapper.find('input[type="time"], #tabela_escalas').val('');
            $camposComunsWrapper.find('select.select2-simple:not(#motorista_id_select2_escala)').val(null).trigger('change').prop('disabled', true);
            $camposComunsWrapper.find('#eh_extra').prop('checked', false).prop('disabled',true);
            $camposComunsWrapper.find('input:not(#work_id_input, #data_escala)').prop('disabled', true);
            $tabelaEscalasWrapper.hide();
            let valorWorkIdParaStatus = '';
            $statusCheckboxes.each(function() { if ($(this).is(':checked')) { valorWorkIdParaStatus = $(this).val(); return false; }});
            
            $workIdInputText.val(valorWorkIdParaStatus).show().prop('readonly', true).prop('required', true).attr('name', 'work_id');
            $workIdSelectLinha.hide().val(null).trigger('change');
            $workIdSugestaoText.removeClass('feedback-loading feedback-success feedback-error feedback-info').addClass('feedback-secondary-text').html('<span>WorkID definido pelo status.</span>').show();
            
            $veiculoSelectAjax.prop('disabled', true).val(null).trigger('change');
            $veiculoFeedbackAjax.text(''); $veiculoObrigatorioAsterisco.hide();

        } else {
            $tipoEscalaSelect.prop('disabled', false);
            $camposComunsWrapper.find('select.select2-simple:not(#motorista_id_select2_escala), input[type="time"], #tabela_escalas, #eh_extra').prop('disabled', false);
            $('#hora_inicio_prevista, #hora_fim_prevista, #local_inicio_turno_id, #local_fim_turno_id').prop('required', true);

            if (tipoSelecionado === 'linha') {
                $camposLinhaWrapper.show();
                $todosCamposFuncaoWrapper.hide();
                $funcaoSelect.val(null).trigger('change.select2');
                $('#linha_origem_id').prop('required', true);
                
                $workIdSelectLinha.show().prop('required', true).attr('name', 'work_id');
                // Não mexer no valor do input de texto aqui, apenas no nome e visibilidade
                
                $tabelaEscalasWrapper.show();
                $veiculoSelectAjax.prop('disabled', false).prop('required', true); $veiculoObrigatorioAsterisco.show();
                // carregarVeiculosCompativelComLinha(); // Será chamado pelos listeners de linha/data ou na inicialização

            } else if (tipoSelecionado === 'funcao') {
                $camposLinhaWrapper.hide();
                $todosCamposFuncaoWrapper.show();
                $('#linha_origem_id, #veiculo_id_ajax').val(null).trigger('change.select2'); // Limpa linha e VEÍCULO
                $funcaoSelect.prop('required', true);
                $turnoFuncaoSelect.prop('required', true);
                
                $workIdInputText.show().prop('required', true).prop('readonly', false).attr('name', 'work_id');
                
                $tabelaEscalasWrapper.hide(); $('#tabela_escalas').val('');
                $workIdInputText.prop('placeholder', 'WorkID será sugerido pela função');
                $veiculoSelectAjax.prop('disabled', true).val(null).trigger('change');
                $veiculoFeedbackAjax.text('Veículo não aplicável para função.'); $veiculoObrigatorioAsterisco.hide();
                atualizarCamposFuncao(); 
                montarWorkIDSugerido();
            }
            // Restaura o valor original do WorkID se ele não for um status especial
            const workIdAtualUpper = $workIdInputText.val().toUpperCase();
            const statusEspeciaisForm = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
            if (statusEspeciaisForm.includes(workIdAtualUpper)) { 
                if (valorOriginalWorkIdJs && !statusEspeciaisForm.includes(valorOriginalWorkIdJs.toUpperCase())) {
                    if(tipoSelecionado === 'linha') { 
                        // O select de WorkID será populado e o valorOriginalWorkIdJs será usado para tentar pré-selecionar
                    } else { 
                         $workIdInputText.val(valorOriginalWorkIdJs);
                    }
                } else { 
                     if(tipoSelecionado !== 'linha'){ $workIdInputText.val(''); }
                }
            }
        }
    }

    function montarWorkIDSugerido() { /* ... (Lógica da v9) ... */ 
        const tipoEscala = $tipoEscalaSelect.val();
        if (tipoEscala !== 'funcao' || $statusCheckboxes.is(':checked')) { 
            if (tipoEscala !== 'linha') { $workIdSugestaoText.text('').hide(); }
            return;
        }
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
            $workIdInputText.val(sugestao); 
            $workIdSugestaoText.removeClass('feedback-loading feedback-error feedback-info').addClass('feedback-secondary-text').html('<span>WorkID Sugerido: ' + sugestao + '</span>').show();
        } else { $workIdSugestaoText.text('').hide(); }
    }
    function atualizarCamposFuncao(dadosCopia = null) { /* ... (Lógica da v9) ... */
        const funcaoId = $funcaoSelect.val();
        let turnoParaSetar = dadosCopia ? dadosCopia.turno_funcao_detectado : <?php echo json_encode($turno_funcao_form_php); ?>;
        let posicaoParaSetar = dadosCopia ? dadosCopia.posicao_letra_detectada : <?php echo json_encode($posicao_letra_form_php); ?>;
        let localInicioParaSetar = dadosCopia ? dadosCopia.localInicio : <?php echo json_encode($local_inicio_id_form_php); ?>;
        let localFimParaSetar = dadosCopia ? dadosCopia.localFim : <?php echo json_encode($local_fim_id_form_php); ?>;

        $posicaoLetraWrapper.hide(); $posicaoLetraSelect.prop('required', false).val('');
        if (!funcaoId || !funcoesOperacionaisData[funcaoId]) {
            $turnoFuncaoSelect.html('<option value="">Selecione a função...</option>').prop('disabled', true).val('');
            filtrarLocais(null, 'qualquer', null, localInicioParaSetar, localFimParaSetar);
            $localInicioSelect.prop('disabled', false).prop('required',true);
            $localFimSelect.prop('disabled', false).prop('required',true);
            montarWorkIDSugerido(); return;
        }
        const funcaoData = funcoesOperacionaisData[funcaoId];
        const turnosArray = funcaoData.turnos_disponiveis ? String(funcaoData.turnos_disponiveis).split(',') : [];
        $turnoFuncaoSelect.html('<option value="">Selecione o turno...</option>');
        const turnoNomes = {'01': 'Manhã', '02': 'Tarde', '03': 'Noite'};
        turnosArray.forEach(function(turno) { $turnoFuncaoSelect.append(new Option(turnoNomes[turno.trim()] || 'Turno ' + turno.trim(), turno.trim())); });
        $turnoFuncaoSelect.prop('disabled', false).prop('required', true).val(turnoParaSetar).trigger('change');
        const requerPosicao = (String(funcaoData.requer_posicao_especifica).toLowerCase() === 'true' || funcaoData.requer_posicao_especifica === 1 || funcaoData.requer_posicao_especifica === true);
        if (requerPosicao && funcaoData.max_posicoes_por_turno > 0) {
            $posicaoLetraSelect.html('<option value="">Selecione...</option>');
            for (let i = 0; i < funcaoData.max_posicoes_por_turno; i++) { let letra = String.fromCharCode(65 + i); $posicaoLetraSelect.append(new Option(letra, letra)); }
            $posicaoLetraWrapper.show(); $posicaoLetraSelect.prop('required', true).val(posicaoParaSetar).trigger('change');
        }
        filtrarLocais(funcaoData.local_fixo_id, funcaoData.locais_permitidos_tipo, funcaoData.locais_permitidos_ids, localInicioParaSetar, localFimParaSetar);
        if (funcaoData.local_fixo_id) { $localInicioSelect.prop('required', false); $localFimSelect.prop('required', false);
        } else { $localInicioSelect.prop('disabled', false).prop('required', true); $localFimSelect.prop('disabled', false).prop('required', true); }
        montarWorkIDSugerido();
    }
    function filtrarLocais(localFixoId, tipoPermitido, idsPermitidosStr, valorPreselecaoInicio = null, valorPreselecaoFim = null) { /* ... (Lógica da v9) ... */ 
        const idsPermitidos = idsPermitidosStr ? String(idsPermitidosStr).split(',').map(id => String(id).trim()) : [];
        let valorSelecionarInicio = valorPreselecaoInicio !== null ? valorPreselecaoInicio : $localInicioSelect.val();
        let valorSelecionarFim = valorPreselecaoFim !== null ? valorPreselecaoFim : $localFimSelect.val();
        $localInicioSelect.html('<option value=""></option>'); $localFimSelect.html('<option value=""></option>');   
        todosOsLocaisData.forEach(function(local) {
            let incluirLocal = false;
            if (localFixoId && String(local.id) === String(localFixoId)) { incluirLocal = true; valorSelecionarInicio = local.id; valorSelecionarFim = local.id;
            } else if (!localFixoId && tipoPermitido && tipoPermitido.toLowerCase() !== 'qualquer' && tipoPermitido.toLowerCase() !== 'nenhum') {
                if (local.tipo === tipoPermitido.toLowerCase()) { if (idsPermitidos.length > 0) { if (idsPermitidos.includes(String(local.id))) incluirLocal = true; }  else { incluirLocal = true; } }
            } else if (!localFixoId && (!tipoPermitido || tipoPermitido.toLowerCase() === 'qualquer' || tipoPermitido.toLowerCase() === 'nenhum')) { incluirLocal = true; }
            if (incluirLocal) { $localInicioSelect.append(new Option(local.text, local.id)); $localFimSelect.append(new Option(local.text, local.id)); }
        });
        $localInicioSelect.val(valorSelecionarInicio).trigger('change.select2'); $localFimSelect.val(valorSelecionarFim).trigger('change.select2');
        if (localFixoId) { $localInicioSelect.prop('disabled', true); $localFimSelect.prop('disabled', true);
        } else { $localInicioSelect.prop('disabled', false); $localFimSelect.prop('disabled', false); }
    }


    // --- Event Listeners ---
    $tipoEscalaSelect.on('change', function() {
        let currentWorkId = "";
        if($statusCheckboxes.is(':checked')) { currentWorkId = $workIdInputText.val(); } 
        else { currentWorkId = ($tipoEscalaSelect.val() === 'funcao') ? $workIdInputText.val() : $workIdSelectLinha.val(); }
        const statusEspeciais = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        if(currentWorkId && !statusEspeciais.includes(currentWorkId.toUpperCase())){ valorOriginalWorkIdJs = currentWorkId; }
        
        atualizarVisibilidadeCampos();
        // Se mudou para linha E não é status especial, tenta carregar WorkIDs de linha e veículos
        if ($tipoEscalaSelect.val() === 'linha' && !$statusCheckboxes.is(':checked')) { 
            carregarWorkIDsDisponiveis(); 
            carregarVeiculosCompativelComLinha();
        }
    });

    $funcaoSelect.on('change', function(){ 
        $turnoFuncaoSelect.val(null).trigger('change'); $posicaoLetraSelect.val(null).trigger('change'); 
        atualizarCamposFuncao(); 
    });
    $turnoFuncaoSelect.on('change', montarWorkIDSugerido);
    $posicaoLetraSelect.on('change', montarWorkIDSugerido);
    $localInicioSelect.on('change', montarWorkIDSugerido);

    $statusCheckboxes.on('change', function() {
        const $checkboxAtual = $(this);
        const statusEspeciais = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
        let currentWorkIdVal = $tipoEscalaSelect.val() === 'linha' && !$checkboxAtual.is(':checked') ? $workIdSelectLinha.val() : $workIdInputText.val();
        if ($checkboxAtual.is(':checked')) {
            if (currentWorkIdVal && !statusEspeciais.includes(currentWorkIdVal.toUpperCase())) { valorOriginalWorkIdJs = currentWorkIdVal;}
            $statusCheckboxes.not($checkboxAtual).prop('checked', false);
        }
        atualizarVisibilidadeCampos();
        if ($tipoEscalaSelect.val() === 'linha' && !$statusCheckboxes.is(':checked')) {
            carregarWorkIDsDisponiveis();
            carregarVeiculosCompativelComLinha();
        }
    });
    
    $linhaOrigemSelectWorkID.on('change', function() { 
        carregarWorkIDsDisponiveis(); 
        carregarVeiculosCompativelComLinha();
    });
    $dataEscalaInputWorkID.on('change', function() { 
        carregarWorkIDsDisponiveis(); 
        carregarVeiculosCompativelComLinha();
    });
    
    // --- Lógica de Cópia (Adaptada para chamar carregaVeiculos também) ---
    $('#btnBuscarCopiarEscala').on('click', function() {
        var motoristaOrigemId = $('#copiar_motorista_id_select2').val();
        var dataOrigem = $('#copiar_data_escala_input').val();
        var $feedbackDivCopia = $('#copiar_escala_feedback');
        $feedbackDivCopia.html('<small class="text-info"><i class="fas fa-spinner fa-spin"></i> Buscando...</small>');

        if (!motoristaOrigemId || !dataOrigem) { $feedbackDivCopia.html('<small class="text-danger">Selecione Motorista e Data de Origem.</small>'); return; }

        $.ajax({
            url: 'buscar_escala_para_copia_ajax.php', type: 'GET', dataType: 'json',
            data: { motorista_id: motoristaOrigemId, data_escala: dataOrigem },
            success: function(response) {
                if (response.success && response.escala) {
                    var esc = response.escala;
                    $statusCheckboxes.prop('checked', false);
                    valorOriginalWorkIdJs = esc.work_id || ''; // Define o WorkID da escala copiada como o "original" para o form
                    
                    var workIdCopiadoUpper = (esc.work_id || '').toUpperCase();
                    const statusEspeciaisCopia = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
                    let copiouStatusEspecial = false;
                    if (statusEspeciaisCopia.includes(workIdCopiadoUpper)) {
                        $('#is_' + workIdCopiadoUpper.toLowerCase() + '_check').prop('checked', true);
                        copiouStatusEspecial = true;
                    }
                    
                    let tipoEscalaCopiada = 'linha';
                    if (esc.funcao_operacional_id) { tipoEscalaCopiada = 'funcao';
                    } else if (!esc.linha_origem_id && !copiouStatusEspecial && esc.work_id) {
                        let funcaoInferida = null;
                        for (const idFuncao in funcoesOperacionaisData) { if (esc.work_id && esc.work_id.startsWith(funcoesOperacionaisData[idFuncao].work_id_prefixo)) { funcaoInferida = funcoesOperacionaisData[idFuncao]; break; } }
                        if (funcaoInferida) { tipoEscalaCopiada = 'funcao'; esc.funcao_operacional_id = funcaoInferida.id; }
                    }
                    $tipoEscalaSelect.val(tipoEscalaCopiada).trigger('change'); // Isso deve chamar atualizarVisibilidadeCampos

                    setTimeout(function() {
                        var dadosParaFuncaoCopia = null;
                        if (tipoEscalaCopiada === 'funcao') {
                            dadosParaFuncaoCopia = { turno_funcao_detectado: esc.turno_funcao_detectado || '', posicao_letra_detectada: esc.posicao_letra_detectada || '', localInicio: esc.local_inicio_turno_id, localFim: esc.local_fim_turno_id };
                            $funcaoSelect.val(esc.funcao_operacional_id || null).trigger('change'); // Dispara atualizarCamposFuncao
                            atualizarCamposFuncao(dadosParaFuncaoCopia); // Para garantir que turno e posição sejam setados
                            $('#work_id_input').val(valorOriginalWorkIdJs); // WorkID de função vai no input de texto
                        } else { // Linha
                            $('#linha_origem_id').val(esc.linha_origem_id || null).trigger('change.select2'); // Dispara carga de WorkIDs e Veículos
                            // O veiculoIdAtualPhp será usado pelo carregarVeiculos... para tentar pré-selecionar
                            // Precisamos garantir que o veiculoIdAtualPhp seja o da escala copiada
                            window.veiculoIdAtualPhp = esc.veiculo_id || ''; // Atualiza a variável global JS
                            window.veiculoPrefixoAtualPhp = esc.prefixo_veiculo_atual || '';

                            if (!copiouStatusEspecial) $('#tabela_escalas').val(esc.tabela_escalas || '');
                        }
                        
                        $('#hora_inicio_prevista').val(esc.hora_inicio_prevista || '');
                        if (!(tipoEscalaCopiada === 'funcao' && funcoesOperacionaisData[esc.funcao_operacional_id] && funcoesOperacionaisData[esc.funcao_operacional_id].local_fixo_id)) {
                            $('#local_inicio_turno_id').val(esc.local_inicio_turno_id || null).trigger('change.select2');
                            $('#local_fim_turno_id').val(esc.local_fim_turno_id || null).trigger('change.select2');
                        }
                        $('#hora_fim_prevista').val(esc.hora_fim_prevista || '');
                        $('#eh_extra').prop('checked', esc.eh_extra == 1);
                        
                        if (copiouStatusEspecial) { $statusCheckboxes.filter(':checked').trigger('change'); }
                         else { atualizarVisibilidadeCampos(); } // Garante que tudo está correto
                        $feedbackDivCopia.html('<small class="text-success"><i class="fas fa-check"></i> Dados preenchidos. Ajuste Data/Motorista atuais e salve.</small>');
                    }, 450); // Aumentei o delay para dar tempo dos selects se ajustarem

                } else { $feedbackDivCopia.html('<small class="text-warning">' + (response.message || 'Nenhuma escala encontrada.') + '</small>'); }
            }, error: function() { $feedbackDivCopia.html('<small class="text-danger">Erro ao buscar dados.</small>');}
        });
    });

    // --- Validação de Submit ---
    $('#form-escala-planejada').on('submit', function(e) { 
        var isStatusChecked = $statusCheckboxes.is(':checked');
        if (!isStatusChecked) { 
            const tipoEscalaAtualSubmit = $tipoEscalaSelect.val();
            if (tipoEscalaAtualSubmit === 'linha' && (!$('#linha_origem_id').val() )) { alert('Linha de Origem é obrigatória.'); e.preventDefault(); return false; }
            if (tipoEscalaAtualSubmit === 'funcao' && (!$('#funcao_operacional_id_select').val() )) { alert('Função Operacional é obrigatória.'); e.preventDefault(); return false; }
            if (tipoEscalaAtualSubmit === 'funcao' && (!$('#turno_funcao_select').val() )) { alert('Turno da Função é obrigatório.'); e.preventDefault(); return false; }
            if (tipoEscalaAtualSubmit === 'funcao' && $posicaoLetraWrapper.is(':visible') && (!$('#posicao_letra_funcao_select').val())) { alert('Posição/Letra da Função é obrigatória.'); e.preventDefault(); return false; }
            
            // Validação do WorkID
            let workIdValueToSubmit = '';
            if (tipoEscalaAtualSubmit === 'linha') { workIdValueToSubmit = $workIdSelectLinha.val(); }
            else if (tipoEscalaAtualSubmit === 'funcao') { workIdValueToSubmit = $workIdInputText.val().trim(); }
            if (!workIdValueToSubmit) { 
                alert('WorkID é obrigatório se não for um status especial.'); 
                if (tipoEscalaAtualSubmit === 'linha') $workIdSelectLinha.focus(); else $workIdInputText.focus();
                e.preventDefault(); return false; 
            }

            // Validação do Veículo (somente para tipo 'linha')
            if (tipoEscalaAtualSubmit === 'linha' && (!$veiculoSelectAjax.val() || $veiculoSelectAjax.val() === "") ) {
                alert('O campo Veículo é obrigatório para escala de linha.');
                if ($veiculoSelectAjax.data('select2')) { $veiculoSelectAjax.select2('open'); } else { $veiculoSelectAjax.focus(); }
                e.preventDefault(); return false;
            }

            if ($('#hora_inicio_prevista').val() === '' || $('#hora_fim_prevista').val() === '') { alert('Hora Início e Fim são obrigatórias.'); e.preventDefault(); return false; }
            if ((!$('#local_inicio_turno_id').val() || !$('#local_fim_turno_id').val()) && !$('#local_inicio_turno_id').is(':disabled') ) { alert('Local Início e Fim são obrigatórios.'); e.preventDefault(); return false; }
        }
     });

    // --- Chamadas Iniciais ---
    atualizarVisibilidadeCampos(); 
    if (<?php echo json_encode($modo_edicao_escala_php); ?>) {
        if ($tipoEscalaSelect.val() === 'funcao' && $funcaoSelect.val()) {
             atualizarCamposFuncao();
        }
        // Se estiver editando uma escala de linha e os campos relevantes já tiverem valor, carrega os selects dinâmicos
        if ($tipoEscalaSelect.val() === 'linha' && $linhaOrigemSelectWorkID.val() && $dataEscalaInputWorkID.val() && !$statusCheckboxes.is(':checked')) {
             carregarWorkIDsDisponiveis();
             carregarVeiculosCompativelComLinha();
        }
    }
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once 'admin_footer.php';
?>