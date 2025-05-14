<?php
// admin/escala_diaria_processa.php
// ATUALIZADO para incluir Tipo de Escala (Linha/Função), WorkID dinâmico para função,
// e validação de jornada condicional.

require_once 'auth_check.php';
require_once '../db_config.php';

// Permissões (mantenha como no seu original)
$niveis_permitidos_gerenciar_diaria = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerenciar_diaria)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para gerenciar a Escala Diária.";
    header('Location: escala_diaria_consultar.php');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['salvar_escala_diaria'])) {

    $escala_diaria_id = filter_input(INPUT_POST, 'escala_diaria_id', FILTER_VALIDATE_INT);

    // --- Novos campos para Tipo de Escala e Função Operacional ---
    $tipo_escala = trim($_POST['tipo_escala'] ?? 'linha'); // 'linha' ou 'funcao'
    $funcao_operacional_id_input = filter_input(INPUT_POST, 'funcao_operacional_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $funcao_operacional_id_val = $funcao_operacional_id_input ?: null;
    $turno_funcao_input = trim($_POST['turno_funcao'] ?? '');
    $posicao_letra_funcao_input = trim($_POST['posicao_letra_funcao'] ?? '');
    // --- Fim Novos campos ---

    $data_escala_str = trim($_POST['data_escala'] ?? '');
    $motorista_id = filter_input(INPUT_POST, 'motorista_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    
    $is_folga = isset($_POST['is_folga_check']);
    $is_falta = isset($_POST['is_falta_check']);
    $is_fora_escala = isset($_POST['is_fora_escala_check']);
    $is_ferias = isset($_POST['is_ferias_check']);
    $is_atestado = isset($_POST['is_atestado_check']);
    
    $work_id_input = trim($_POST['work_id'] ?? '');
    $tabela_escalas_input = trim($_POST['tabela_escalas'] ?? '');
    $linha_origem_id_input = filter_input(INPUT_POST, 'linha_origem_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $linha_origem_id_val = $linha_origem_id_input ?: null;
    
    $hora_inicio_str = trim($_POST['hora_inicio_prevista'] ?? '');
    $local_inicio_id_input = filter_input(INPUT_POST, 'local_inicio_turno_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $local_inicio_id_val = $local_inicio_id_input ?: null;
    
    $hora_fim_str = trim($_POST['hora_fim_prevista'] ?? '');
    $local_fim_id_input = filter_input(INPUT_POST, 'local_fim_turno_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $local_fim_id_val = $local_fim_id_input ?: null;
    
    $eh_extra_val = (isset($_POST['eh_extra']) && $_POST['eh_extra'] == '1') ? 1 : 0;
    $veiculo_id_val_input = filter_input(INPUT_POST, 'veiculo_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $veiculo_id_val = $veiculo_id_val_input ?: null;
    $observacoes_ajuste_input = trim($_POST['observacoes_ajuste'] ?? '');

    // Parâmetros para redirecionamento
    $redirect_query_params = [];
    if (isset($_POST['pagina_original'])) $redirect_query_params['pagina'] = $_POST['pagina_original'];
    if (isset($_POST['filtro_data_original'])) $redirect_query_params['data_escala'] = $_POST['filtro_data_original'];
    if (isset($_POST['filtro_tipo_busca_original'])) $redirect_query_params['tipo_busca_adicional'] = $_POST['filtro_tipo_busca_original'];
    if (isset($_POST['filtro_valor_busca_original'])) $redirect_query_params['valor_busca_adicional'] = $_POST['filtro_valor_busca_original'];
    $redirect_form_location = ($escala_diaria_id ? 'escala_diaria_formulario.php?id=' . $escala_diaria_id : 'escala_diaria_formulario.php');
    if (!empty($redirect_query_params)) {
        $redirect_form_location .= (strpos($redirect_form_location, '?') === false ? '?' : '&') . http_build_query($redirect_query_params);
    }
    $redirect_list_location = 'escala_diaria_consultar.php' . (!empty($redirect_query_params) ? '?' . http_build_query($redirect_query_params) : '');

    $validation_errors = [];
    $data_obj = null;
    $funcao_data_db = null;
    $ignorar_validacao_jornada_final = false;

    if (empty($data_escala_str)) { $validation_errors[] = "Data da Escala Diária é obrigatória."; }
    else {
        try { $data_obj = new DateTime($data_escala_str); if ($data_obj->format('Y-m-d') !== $data_escala_str) { throw new Exception(); }}
        catch (Exception $e) { $validation_errors[] = "Data da Escala Diária inválida. Use AAAA-MM-DD."; $data_obj = null; }
    }
    if (empty($motorista_id)) { $validation_errors[] = "Motorista é obrigatório."; }

    $is_status_especial = false;
    $work_id_to_save = $work_id_input;

    if ($is_folga) { $work_id_to_save = 'FOLGA'; $is_status_especial = true; }
    elseif ($is_falta) { $work_id_to_save = 'FALTA'; $is_status_especial = true; }
    elseif ($is_fora_escala) { $work_id_to_save = 'FORADEESCALA'; $is_status_especial = true; }
    elseif ($is_ferias) { $work_id_to_save = 'FÉRIAS'; $is_status_especial = true; }
    elseif ($is_atestado) { $work_id_to_save = 'ATESTADO'; $is_status_especial = true; }

    if (!$is_status_especial) {
        if ($tipo_escala === 'funcao') {
            if (empty($funcao_operacional_id_val)) {
                $validation_errors[] = "Função Operacional é obrigatória.";
            } else {
                if ($pdo) {
                    $stmt_funcao_d = $pdo->prepare("SELECT * FROM funcoes_operacionais WHERE id = :id_funcao_d");
                    $stmt_funcao_d->bindParam(':id_funcao_d', $funcao_operacional_id_val, PDO::PARAM_INT);
                    $stmt_funcao_d->execute();
                    $funcao_data_db = $stmt_funcao_d->fetch(PDO::FETCH_ASSOC);
                    if (!$funcao_data_db) {
                        $validation_errors[] = "Função Operacional selecionada é inválida.";
                    } else {
                        $ignorar_validacao_jornada_final = (bool)$funcao_data_db['ignorar_validacao_jornada'];
                        if (empty($turno_funcao_input)) {
                            $validation_errors[] = "Turno da Função é obrigatório.";
                        }
                        if ($funcao_data_db['requer_posicao_especifica'] && empty($posicao_letra_funcao_input)) {
                            $validation_errors[] = "Posição/Letra da Função é obrigatória para '" . htmlspecialchars($funcao_data_db['nome_funcao']) . "'.";
                        }
                        
                        // Construir WorkID para função (se não for enviado explicitamente ou para validar)
                        $sugestao_work_id_func_d = $funcao_data_db['work_id_prefixo'];
                         if (!$funcao_data_db['local_fixo_id'] && $local_inicio_id_val && $pdo) {
                            $stmt_local_nome_curto_d = $pdo->prepare("SELECT nome FROM locais WHERE id = :id_local_param_d");
                            $stmt_local_nome_curto_d->bindParam(':id_local_param_d', $local_inicio_id_val, PDO::PARAM_INT);
                            $stmt_local_nome_curto_d->execute();
                            $nome_local_completo_d = $stmt_local_nome_curto_d->fetchColumn();
                            if($nome_local_completo_d){
                                $partes_nome_local_d = explode(' ', $nome_local_completo_d);
                                $nome_local_curto_d = '';
                                if (count($partes_nome_local_d) > 1 && strtoupper($partes_nome_local_d[0]) === 'T.') {
                                    $nome_local_curto_d = "T" . (isset($partes_nome_local_d[1]) ? strtoupper(substr($partes_nome_local_d[1], 0, 1)) : '');
                                } else { $nome_local_curto_d = strtoupper(substr(str_replace(' ', '', $nome_local_completo_d), 0, 3));}
                                if($nome_local_curto_d) $sugestao_work_id_func_d .= '-' . $nome_local_curto_d;
                            }
                        }
                        $sugestao_work_id_func_d .= '-' . $turno_funcao_input;
                        if ($funcao_data_db['requer_posicao_especifica'] && !empty($posicao_letra_funcao_input)) {
                            $sugestao_work_id_func_d .= strtoupper($posicao_letra_funcao_input);
                        }
                        // Usar o WorkID construído se o do formulário estiver vazio ou diferente (ou validar)
                        if(empty($work_id_input) || $work_id_input !== $sugestao_work_id_func_d){
                             // Pode adicionar um log aqui se o work_id do form for diferente do gerado
                             // error_log("WorkID de função: form='{$work_id_input}', gerado='{$sugestao_work_id_func_d}'");
                        }
                        $work_id_to_save = $sugestao_work_id_func_d; // Prioriza o construído

                        if($funcao_data_db['local_fixo_id']){
                            $local_inicio_id_val = $funcao_data_db['local_fixo_id'];
                            $local_fim_id_val = $funcao_data_db['local_fixo_id'];
                        }
                    }
                } else { $validation_errors[] = "Erro de conexão para validar Função Operacional (Diária)."; }
            }
            $linha_origem_id_val = null; $veiculo_id_val = null; $tabela_escalas_input = null;
        } elseif ($tipo_escala === 'linha') {
            if (empty($work_id_input)) { $validation_errors[] = "WorkID é obrigatório para escala de linha (Diária)."; }
            if (empty($linha_origem_id_val)) { $validation_errors[] = "Linha de Origem é obrigatória (Diária)."; }
            $funcao_operacional_id_val = null; $ignorar_validacao_jornada_final = false;
        } else { $validation_errors[] = "Tipo de Escala inválido (Diária)."; }
    } else { // Status especial
        $ignorar_validacao_jornada_final = true;
        $tipo_escala = null; $funcao_operacional_id_val = null; $linha_origem_id_val = null;
        $veiculo_id_val = null; $tabela_escalas_input = null; $hora_inicio_str = null;
        $local_inicio_id_val = null; $hora_fim_str = null; $local_fim_id_val = null; $eh_extra_val = 0;
    }

    $hora_inicio_for_db = null; $hora_fim_for_db = null;
    $current_start_dt = null; $current_end_dt = null;

    if (!$is_status_especial && !$ignorar_validacao_jornada_final) {
        if (empty($hora_inicio_str) || empty($hora_fim_str)) {
            $validation_errors[] = "Hora de Início e Fim são obrigatórias (Diária, se não for status/ignorar).";
        } else {
            try { /* ... Validação de formato de hora e cálculo de $current_start_dt, $current_end_dt ... */
                 $start_time_obj_val_d = DateTime::createFromFormat('H:i', $hora_inicio_str);
                $end_time_obj_val_d = DateTime::createFromFormat('H:i', $hora_fim_str);
                if (!$start_time_obj_val_d || $start_time_obj_val_d->format('H:i') !== $hora_inicio_str) { $validation_errors[] = "Formato Hora Início inválido (Diária)."; }
                if (!$end_time_obj_val_d || $end_time_obj_val_d->format('H:i') !== $hora_fim_str) { $validation_errors[] = "Formato Hora Fim inválido (Diária)."; }
                if ($start_time_obj_val_d && $end_time_obj_val_d && $data_obj && empty($validation_errors)) {
                    $current_start_dt = new DateTime($data_escala_str . ' ' . $start_time_obj_val_d->format('H:i:s'));
                    $current_end_dt = new DateTime($data_escala_str . ' ' . $end_time_obj_val_d->format('H:i:s'));
                    if ($current_end_dt <= $current_start_dt) { $current_end_dt->modify('+1 day'); }
                    $hora_inicio_for_db = $current_start_dt->format('H:i:s');
                    $hora_fim_for_db = $end_time_obj_val_d->format('H:i:s');
                }
            } catch (Exception $e) { $validation_errors[] = "Erro ao processar horas (Diária): " . $e->getMessage(); }
        }

        if ($pdo && $motorista_id && $data_obj && $hora_inicio_for_db && $hora_fim_for_db && $current_start_dt && $current_end_dt && empty($validation_errors)) {
            // --- Lógica de Validação de Jornada para Escala Diária ---
            // (Similar à da Planejada, mas consulta 'motorista_escalas_diaria' e considera 'funcao_operacional_id' para ignorar)
            $status_especiais_sql_array_d = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
            $placeholders_para_status_d = implode(',', array_fill(0, count($status_especiais_sql_array_d), '?'));

            $query_params_other_d = [$motorista_id, $data_escala_str];
            $sql_other_shifts_d = "SELECT id, hora_inicio_prevista, hora_fim_prevista, eh_extra, funcao_operacional_id 
                                 FROM motorista_escalas_diaria
                                 WHERE motorista_id = ? AND data = ?";
            if ($escala_diaria_id) {
                $sql_other_shifts_d .= " AND id != ?";
                $query_params_other_d[] = $escala_diaria_id;
            }
            $sql_other_shifts_d .= " AND (UPPER(work_id) NOT IN (" . $placeholders_para_status_d . ") ";
            $sql_other_shifts_d .= " AND (funcao_operacional_id IS NULL OR (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = motorista_escalas_diaria.funcao_operacional_id) = 0) )";
            
            $final_query_params_other_d = array_merge($query_params_other_d, $status_especiais_sql_array_d);
            $stmt_other_shifts_d = $pdo->prepare($sql_other_shifts_d);
            $stmt_other_shifts_d->execute($final_query_params_other_d);
            $other_scales_on_day_d = $stmt_other_shifts_d->fetchAll(PDO::FETCH_ASSOC);

            if ($other_scales_on_day_d === false) { $validation_errors[] = "Falha ao verificar outras escalas diárias."; }
            else {
                // Constantes (mantenha ou defina)
                if (!defined('MAX_NORMAL_SECONDS_DAY')) define('MAX_NORMAL_SECONDS_DAY', 6 * 3600 + 40 * 60);
                if (!defined('MAX_EXTRA_SECONDS_DAY')) define('MAX_EXTRA_SECONDS_DAY', 2 * 3600);
                if (!defined('MAX_TOTAL_SECONDS_DAY')) define('MAX_TOTAL_SECONDS_DAY', (6 * 3600 + 40 * 60) + (2*3600) );
                if (!defined('MAX_INTERVAL_SECONDS')) define('MAX_INTERVAL_SECONDS', 4 * 3600);
                if (!defined('MIN_INTERJORNADA_SECONDS')) define('MIN_INTERJORNADA_SECONDS', 11 * 3600);

                $total_normal_seconds_day = 0; $total_extra_seconds_day = 0; $all_shifts_for_day = [];
                $current_shift_is_extra = $eh_extra_val;
                $duration_current_shift_seconds = $current_end_dt->getTimestamp() - $current_start_dt->getTimestamp();
                if ($current_shift_is_extra) { $total_extra_seconds_day += $duration_current_shift_seconds; }
                else { $total_normal_seconds_day += $duration_current_shift_seconds; }
                $all_shifts_for_day[] = ['start' => $current_start_dt, 'end' => $current_end_dt];

                foreach ($other_scales_on_day_d as $other_shift_item_d) {
                    // ... (lógica de cálculo de duração para outras escalas, igual à planejada)
                     if (isset($other_shift_item_d['hora_inicio_prevista']) && isset($other_shift_item_d['hora_fim_prevista'])) {
                        try {
                            $other_start_dt_loop_d = new DateTime($data_escala_str . ' ' . $other_shift_item_d['hora_inicio_prevista']);
                            $other_end_dt_loop_d = new DateTime($data_escala_str . ' ' . $other_shift_item_d['hora_fim_prevista']);
                            if ($other_end_dt_loop_d <= $other_start_dt_loop_d) { $other_end_dt_loop_d->modify('+1 day'); }
                            if ($other_end_dt_loop_d > $other_start_dt_loop_d) {
                                $other_duration_seconds = $other_end_dt_loop_d->getTimestamp() - $other_start_dt_loop_d->getTimestamp();
                                $other_shift_is_extra = isset($other_shift_item_d['eh_extra']) && $other_shift_item_d['eh_extra'] == 1;
                                if ($other_shift_is_extra) { $total_extra_seconds_day += $other_duration_seconds; }
                                else { $total_normal_seconds_day += $other_duration_seconds; }
                                $all_shifts_for_day[] = ['start' => $other_start_dt_loop_d, 'end' => $other_end_dt_loop_d];
                            }
                        } catch (Exception $e) { $validation_errors[] = "Erro ao processar horários de pegas diárias existentes (validação)."; break; }
                    }
                }
                // ... (validação de MAX_NORMAL, MAX_EXTRA, MAX_TOTAL, INTERVALO, INTERJORNADA - igual à planejada)
                 if (empty($validation_errors)) {
                    $total_normal_hours_calc = round($total_normal_seconds_day / 3600, 2);
                    $total_extra_hours_calc = round($total_extra_seconds_day / 3600, 2);
                    $overall_total_hours_calc = round(($total_normal_seconds_day + $total_extra_seconds_day) / 3600, 2);
                    if ($total_normal_seconds_day > MAX_NORMAL_SECONDS_DAY) { $validation_errors[] = "Jornada Normal ({$total_normal_hours_calc}h) excede o limite de " . (MAX_NORMAL_SECONDS_DAY/3600) . "h (Diária)."; }
                    if ($total_extra_seconds_day > MAX_EXTRA_SECONDS_DAY) { $validation_errors[] = "Jornada Extra ({$total_extra_hours_calc}h) excede o limite de " . (MAX_EXTRA_SECONDS_DAY/3600) . "h (Diária)."; }
                    if (($total_normal_seconds_day + $total_extra_seconds_day) > MAX_TOTAL_SECONDS_DAY) { $validation_errors[] = "Carga horária total ({$overall_total_hours_calc}h) excede o limite de " . (MAX_TOTAL_SECONDS_DAY/3600) . "h (Diária)."; }
                }
                if (count($all_shifts_for_day) > 1 && empty($validation_errors)) {
                    usort($all_shifts_for_day, function($a, $b) { return $a['start']->getTimestamp() - $b['start']->getTimestamp(); });
                    for ($i = 0; $i < count($all_shifts_for_day) - 1; $i++) {
                        $end_ts = $all_shifts_for_day[$i]['end']->getTimestamp();
                        $start_ts_next = $all_shifts_for_day[$i+1]['start']->getTimestamp();
                        $interval_sec = $start_ts_next - $end_ts;
                        if ($interval_sec > MAX_INTERVAL_SECONDS) { $validation_errors[] = "Intervalo entre pegas excede " . (MAX_INTERVAL_SECONDS/3600) . "h (Diária)."; break; }
                        if ($interval_sec < 0) { $validation_errors[] = "Sobreposição de horários detectada entre pegas (Diária)."; break; }
                    }
                }
                if (empty($validation_errors)) { // Validação de Interjornada
                    try { // Dia Anterior
                        $date_obj_for_prev_inter_d = new DateTime($data_escala_str);
                        $prev_day_sql_format_inter_d = $date_obj_for_prev_inter_d->modify('-1 day')->format('Y-m-d');
                        $sql_prev_inter_d = "SELECT MAX(hora_fim_prevista) FROM motorista_escalas_diaria 
                                           WHERE motorista_id = ? AND data = ? 
                                           AND UPPER(work_id) NOT IN ($placeholders_para_status_d)
                                           AND (funcao_operacional_id IS NULL OR (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = motorista_escalas_diaria.funcao_operacional_id) = 0)";
                        $stmt_prev_day_inter_d = $pdo->prepare($sql_prev_inter_d);
                        $stmt_prev_day_inter_d->execute(array_merge([$motorista_id, $prev_day_sql_format_inter_d], $status_especiais_sql_array_d));
                        $last_end_prev_day_time_inter_d = $stmt_prev_day_inter_d->fetchColumn();
                        if ($last_end_prev_day_time_inter_d) {
                            $last_end_prev_day_ts_inter_d = strtotime($prev_day_sql_format_inter_d . ' ' . $last_end_prev_day_time_inter_d);
                            if ($last_end_prev_day_ts_inter_d !== false && ($current_start_dt->getTimestamp() - $last_end_prev_day_ts_inter_d) < MIN_INTERJORNADA_SECONDS) {
                                $validation_errors[] = "Interjornada com dia anterior menor que " . (MIN_INTERJORNADA_SECONDS/3600) . "h (Diária).";
                            }
                        }
                        // Dia Seguinte
                        $date_obj_for_next_inter_d = new DateTime($data_escala_str);
                        $next_day_sql_format_inter_d = $date_obj_for_next_inter_d->modify('+1 day')->format('Y-m-d');
                        $sql_next_inter_d = "SELECT MIN(hora_inicio_prevista) FROM motorista_escalas_diaria 
                                           WHERE motorista_id = ? AND data = ? 
                                           AND UPPER(work_id) NOT IN ($placeholders_para_status_d)
                                           AND (funcao_operacional_id IS NULL OR (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = motorista_escalas_diaria.funcao_operacional_id) = 0)";
                        $stmt_next_day_inter_d = $pdo->prepare($sql_next_inter_d);
                        $stmt_next_day_inter_d->execute(array_merge([$motorista_id, $next_day_sql_format_inter_d], $status_especiais_sql_array_d));
                        $first_start_next_day_time_inter_d = $stmt_next_day_inter_d->fetchColumn();
                        if ($first_start_next_day_time_inter_d) {
                            $first_start_next_day_ts_inter_d = strtotime($next_day_sql_format_inter_d . ' ' . $first_start_next_day_time_inter_d);
                            if ($first_start_next_day_ts_inter_d !== false && ($first_start_next_day_ts_inter_d - $current_end_dt->getTimestamp()) < MIN_INTERJORNADA_SECONDS) {
                                $validation_errors[] = "Interjornada com dia seguinte menor que " . (MIN_INTERJORNADA_SECONDS/3600) . "h (Diária).";
                            }
                        }
                    } catch (Exception $e) { $validation_errors[] = "Erro ao validar interjornada (Diária)."; }
                }
            }
        }
    }

    if (!empty($validation_errors)) {
        $_SESSION['admin_form_error_escala_d'] = implode("<br>", $validation_errors);
        $form_data_repopulate_d = $_POST;
        if($is_folga) $form_data_repopulate_d['is_folga_check'] = 'FOLGA';
        if($is_falta) $form_data_repopulate_d['is_falta_check'] = 'FALTA';
        // ... (adicionar para outros status)
        $_SESSION['form_data_escala_diaria'] = $form_data_repopulate_d;
        header('Location: ' . $redirect_form_location);
        exit;
    }

    $tabela_to_save_d = ($is_status_especial || $tipo_escala === 'funcao') ? null : (!empty($tabela_escalas_input) ? $tabela_escalas_input : null);
    $linha_to_save_d = ($is_status_especial || $tipo_escala === 'funcao') ? null : $linha_origem_id_val;
    $funcao_id_to_save_d = ($is_status_especial || $tipo_escala === 'linha') ? null : $funcao_operacional_id_val;
    $veiculo_to_save_d = ($is_status_especial || $tipo_escala === 'funcao') ? null : $veiculo_id_val;
    $local_inicio_to_save_d = $is_status_especial ? null : $local_inicio_id_val;
    $local_fim_to_save_d = $is_status_especial ? null : $local_fim_id_val;
    $eh_extra_to_save_d = $is_status_especial ? 0 : $eh_extra_val;
    $hora_inicio_final_db_d = ($is_status_especial || ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final)) ? null : $hora_inicio_for_db;
    $hora_fim_final_db_d = ($is_status_especial || ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final)) ? null : $hora_fim_for_db;
    if ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final && !$is_status_especial) {
        if (!empty($hora_inicio_str)) $hora_inicio_final_db_d = DateTime::createFromFormat('H:i', $hora_inicio_str)->format('H:i:s'); else $hora_inicio_final_db_d = null;
        if (!empty($hora_fim_str)) $hora_fim_final_db_d = DateTime::createFromFormat('H:i', $hora_fim_str)->format('H:i:s'); else $hora_fim_final_db_d = null;
    }
    $admin_id_logado_param_d = $_SESSION['admin_user_id'];

    try {
        $pdo->beginTransaction();
        if ($escala_diaria_id) {
            $sql_op_d = "UPDATE motorista_escalas_diaria SET
                            data = :data, motorista_id = :motorista_id, work_id = :work_id,
                            tabela_escalas = :tabela_escalas, linha_origem_id = :linha_origem_id,
                            funcao_operacional_id = :funcao_operacional_id, /* NOVO */
                            hora_inicio_prevista = :hora_inicio, local_inicio_turno_id = :local_inicio,
                            hora_fim_prevista = :hora_fim, local_fim_turno_id = :local_fim,
                            eh_extra = :eh_extra, veiculo_id = :veiculo_id,
                            observacoes_ajuste = :observacoes_ajuste,
                            modificado_por_admin_id = :modificado_por_admin_id
                          WHERE id = :escala_diaria_id_bind";
            $stmt_op_d = $pdo->prepare($sql_op_d);
            $stmt_op_d->bindParam(':escala_diaria_id_bind', $escala_diaria_id, PDO::PARAM_INT);
        } else {
            $sql_op_d = "INSERT INTO motorista_escalas_diaria
                            (data, motorista_id, work_id, tabela_escalas, linha_origem_id, funcao_operacional_id, /* NOVO */
                             hora_inicio_prevista, local_inicio_turno_id, hora_fim_prevista, local_fim_turno_id,
                             eh_extra, veiculo_id, observacoes_ajuste, modificado_por_admin_id)
                           VALUES
                            (:data, :motorista_id, :work_id, :tabela_escalas, :linha_origem_id, :funcao_operacional_id, /* NOVO */
                             :hora_inicio, :local_inicio, :hora_fim, :local_fim,
                             :eh_extra, :veiculo_id, :observacoes_ajuste, :modificado_por_admin_id)";
            $stmt_op_d = $pdo->prepare($sql_op_d);
        }

        $stmt_op_d->bindParam(':data', $data_escala_str, PDO::PARAM_STR);
        $stmt_op_d->bindParam(':motorista_id', $motorista_id, PDO::PARAM_INT);
        $stmt_op_d->bindParam(':work_id', $work_id_to_save, PDO::PARAM_STR);
        $stmt_op_d->bindParam(':tabela_escalas', $tabela_to_save_d, $tabela_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op_d->bindParam(':linha_origem_id', $linha_to_save_d, $linha_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op_d->bindParam(':funcao_operacional_id', $funcao_id_to_save_d, $funcao_id_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_INT); // BIND NOVO
        $stmt_op_d->bindParam(':hora_inicio', $hora_inicio_final_db_d, $hora_inicio_final_db_d === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op_d->bindParam(':local_inicio', $local_inicio_to_save_d, $local_inicio_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op_d->bindParam(':hora_fim', $hora_fim_final_db_d, $hora_fim_final_db_d === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op_d->bindParam(':local_fim', $local_fim_to_save_d, $local_fim_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op_d->bindParam(':eh_extra', $eh_extra_to_save_d, PDO::PARAM_INT);
        $stmt_op_d->bindParam(':veiculo_id', $veiculo_to_save_d, $veiculo_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op_d->bindParam(':observacoes_ajuste', $observacoes_ajuste_input, PDO::PARAM_STR);
        $stmt_op_d->bindParam(':modificado_por_admin_id', $admin_id_logado_param_d, PDO::PARAM_INT);

        if ($stmt_op_d->execute()) {
            $pdo->commit();
            $_SESSION['admin_success_message'] = "Entrada da Escala Diária " . ($escala_diaria_id ? "atualizada" : "salva") . " com sucesso!";
        } else {
            $pdo->rollBack();
            // ... (tratamento de erro SQL como na planejada) ...
             $errorInfoOp_d = $stmt_op_d->errorInfo();
            $error_message_user_d = "Erro ao salvar entrada da escala diária.";
            if (isset($errorInfoOp_d[1]) && $errorInfoOp_d[1] == 1062) { 
                $error_message_user_d .= " Possível entrada duplicada.";
            } else {
                 $error_message_user_d .= " Detalhes: SQLSTATE[{$errorInfoOp_d[0]}] {$errorInfoOp_d[1]}";
            }
            $_SESSION['admin_error_message'] = $error_message_user_d;
            error_log("Erro SQL Escala Diária: SQLSTATE[{$errorInfoOp_d[0]}] [{$errorInfoOp_d[1]}] {$errorInfoOp_d[2]}");
        }
    } catch (PDOException $e) { /* ... */ if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); error_log("Erro PDO Escala Diária: " . $e->getMessage()); $_SESSION['admin_error_message'] = "Erro de banco (PDO) ao processar Escala Diária.";}
    catch (Exception $e) { /* ... */ if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); error_log("Erro Geral Escala Diária: " . $e->getMessage()); $_SESSION['admin_error_message'] = "Erro geral ao processar Escala Diária: " . $e->getMessage();}

    header('Location: ' . $redirect_list_location);
    exit;
} else {
    $_SESSION['admin_error_message'] = "Acesso inválido (Diária).";
    header('Location: escala_diaria_consultar.php');
    exit;
}
?>