<?php
// admin/escala_planejada_processa.php
// ATUALIZADO para incluir Linha e Função Operacional, e validação de jornada condicional.

require_once 'auth_check.php';
require_once '../db_config.php';

// Permissões (mantenha como no seu original)
$niveis_permitidos_proc_escala = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_proc_escala)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para processar esta ação na Escala Planejada.";
    // Adicionar lógica de redirect_params se necessário
    header('Location: escala_planejada_listar.php');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['salvar_escala_planejada'])) {

    $escala_id = filter_input(INPUT_POST, 'escala_id', FILTER_VALIDATE_INT);

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
    
    $work_id_input = trim($_POST['work_id'] ?? ''); // WorkID vindo do formulário
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

    // Parâmetros para redirecionamento (manter lógica original)
    $redirect_query_params = [];
    if (isset($_POST['pagina_original'])) $redirect_query_params['pagina'] = $_POST['pagina_original'];
    if (isset($_POST['filtro_data_original'])) $redirect_query_params['data_escala'] = $_POST['filtro_data_original'];
    if (isset($_POST['filtro_tipo_busca_original'])) $redirect_query_params['tipo_busca_adicional'] = $_POST['filtro_tipo_busca_original'];
    if (isset($_POST['filtro_valor_busca_original'])) $redirect_query_params['valor_busca_adicional'] = $_POST['filtro_valor_busca_original'];
    $redirect_form_location = ($escala_id ? 'escala_planejada_formulario.php?id=' . $escala_id : 'escala_planejada_formulario.php');
    if (!empty($redirect_query_params)) {
        $redirect_form_location .= (strpos($redirect_form_location, '?') === false ? '?' : '&') . http_build_query($redirect_query_params);
    }
    $redirect_list_location = 'escala_planejada_listar.php' . (!empty($redirect_query_params) ? '?' . http_build_query($redirect_query_params) : '');

    $validation_errors = [];
    $data_obj = null;
    $funcao_data_db = null; // Para armazenar dados da função operacional
    $ignorar_validacao_jornada_final = false; // Padrão é validar

    if (empty($data_escala_str)) { $validation_errors[] = "Data da Escala é obrigatória."; }
    else {
        try { $data_obj = new DateTime($data_escala_str); if ($data_obj->format('Y-m-d') !== $data_escala_str) { throw new Exception(); }}
        catch (Exception $e) { $validation_errors[] = "Data da Escala inválida. Use AAAA-MM-DD."; $data_obj = null; }
    }
    if (empty($motorista_id)) { $validation_errors[] = "Motorista é obrigatório."; }

    // --- Lógica para Status Especiais (Folga, Falta, etc.) ---
    $is_status_especial = false;
    $work_id_to_save = $work_id_input; // WorkID final a ser salvo

    if ($is_folga) { $work_id_to_save = 'FOLGA'; $is_status_especial = true; }
    elseif ($is_falta) { $work_id_to_save = 'FALTA'; $is_status_especial = true; }
    elseif ($is_fora_escala) { $work_id_to_save = 'FORADEESCALA'; $is_status_especial = true; }
    elseif ($is_ferias) { $work_id_to_save = 'FÉRIAS'; $is_status_especial = true; }
    elseif ($is_atestado) { $work_id_to_save = 'ATESTADO'; $is_status_especial = true; }

    // --- Lógica específica para TIPO DE ESCALA (Linha ou Função) ---
    if (!$is_status_especial) {
        if ($tipo_escala === 'funcao') {
            if (empty($funcao_operacional_id_val)) {
                $validation_errors[] = "Função Operacional é obrigatória quando o tipo é 'Função'.";
            } else {
                // Buscar dados da função no banco
                if ($pdo) {
                    $stmt_funcao = $pdo->prepare("SELECT * FROM funcoes_operacionais WHERE id = :id_funcao");
                    $stmt_funcao->bindParam(':id_funcao', $funcao_operacional_id_val, PDO::PARAM_INT);
                    $stmt_funcao->execute();
                    $funcao_data_db = $stmt_funcao->fetch(PDO::FETCH_ASSOC);
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

                        // Construir WorkID para função (lógica similar ao JavaScript do formulário)
                        $sugestao_work_id_funcao = $funcao_data_db['work_id_prefixo'];
                        if (!$funcao_data_db['local_fixo_id'] && $local_inicio_id_val && $pdo) {
                            $stmt_local_nome_curto = $pdo->prepare("SELECT nome FROM locais WHERE id = :id_local_param");
                            $stmt_local_nome_curto->bindParam(':id_local_param', $local_inicio_id_val, PDO::PARAM_INT);
                            $stmt_local_nome_curto->execute();
                            $nome_local_completo = $stmt_local_nome_curto->fetchColumn();
                            if($nome_local_completo){
                                $partes_nome_local_func = explode(' ', $nome_local_completo);
                                $nome_local_curto_func = '';
                                if (count($partes_nome_local_func) > 1 && strtoupper($partes_nome_local_func[0]) === 'T.') {
                                    $nome_local_curto_func = "T" . (isset($partes_nome_local_func[1]) ? strtoupper(substr($partes_nome_local_func[1], 0, 1)) : '');
                                } else { $nome_local_curto_func = strtoupper(substr(str_replace(' ', '', $nome_local_completo), 0, 3));}
                                if($nome_local_curto_func) $sugestao_work_id_funcao .= '-' . $nome_local_curto_func;
                            }
                        }
                        $sugestao_work_id_funcao .= '-' . $turno_funcao_input;
                        if ($funcao_data_db['requer_posicao_especifica'] && !empty($posicao_letra_funcao_input)) {
                            $sugestao_work_id_funcao .= strtoupper($posicao_letra_funcao_input);
                        }
                        // Se o WorkID do formulário estiver vazio ou diferente da sugestão, usa a sugestão.
                        // Ou, você pode optar por validar se o $work_id_input corresponde ao padrão esperado.
                        // Por simplicidade, vamos priorizar a construção aqui se for função.
                        $work_id_to_save = $sugestao_work_id_funcao;
                        
                        // Se a função tem local fixo, usa ele
                        if($funcao_data_db['local_fixo_id']){
                            $local_inicio_id_val = $funcao_data_db['local_fixo_id'];
                            $local_fim_id_val = $funcao_data_db['local_fixo_id'];
                        }
                    }
                } else {
                    $validation_errors[] = "Erro de conexão para validar Função Operacional.";
                }
            }
            // Para função, zerar campos de linha
            $linha_origem_id_val = null;
            $veiculo_id_val = null;
            $tabela_escalas_input = null;

        } elseif ($tipo_escala === 'linha') {
            if (empty($work_id_input)) { // work_id_to_save já tem o $work_id_input
                $validation_errors[] = "WorkID é obrigatório para escala de linha.";
            }
            if (empty($linha_origem_id_val)) {
                $validation_errors[] = "Linha de Origem é obrigatória para escala de linha.";
            }
            // Para linha, função é nula
            $funcao_operacional_id_val = null;
            $ignorar_validacao_jornada_final = false; // Linhas sempre validam jornada
        } else {
            $validation_errors[] = "Tipo de Escala inválido selecionado.";
        }
    } else { // Se for status especial (Folga, etc.)
        $ignorar_validacao_jornada_final = true; // Status especiais ignoram validação de jornada
        // Zera campos não aplicáveis a status especiais
        $tipo_escala = null; // Ou um valor padrão, mas não será usado para campos de função/linha
        $funcao_operacional_id_val = null;
        $linha_origem_id_val = null;
        $veiculo_id_val = null;
        $tabela_escalas_input = null;
        $hora_inicio_str = null;
        $local_inicio_id_val = null;
        $hora_fim_str = null;
        $local_fim_id_val = null;
        $eh_extra_val = 0;
    }
    // --- Fim Lógica Tipo de Escala ---


    // --- Validação de Horas e Jornada (Condicional) ---
    $hora_inicio_for_db = null; $hora_fim_for_db = null;
    $current_start_dt = null; $current_end_dt = null;

    if (!$is_status_especial && !$ignorar_validacao_jornada_final) { // Só valida se não for status especial E não for para ignorar
        if (empty($hora_inicio_str) || empty($hora_fim_str)) {
            $validation_errors[] = "Hora de Início e Hora Final são obrigatórias se não for um status especial ou função que ignora validação.";
        } else {
            // ... (Lógica de validação de formato de hora e cálculo de $current_start_dt, $current_end_dt - MANTIDA IGUAL À VERSÃO ANTERIOR)
            try {
                $start_time_obj_val = DateTime::createFromFormat('H:i', $hora_inicio_str);
                $end_time_obj_val = DateTime::createFromFormat('H:i', $hora_fim_str);
                if (!$start_time_obj_val || $start_time_obj_val->format('H:i') !== $hora_inicio_str) { $validation_errors[] = "Formato Hora Início inválido."; }
                if (!$end_time_obj_val || $end_time_obj_val->format('H:i') !== $hora_fim_str) { $validation_errors[] = "Formato Hora Fim inválido."; }
                if ($start_time_obj_val && $end_time_obj_val && $data_obj && empty($validation_errors)) {
                    $current_start_dt = new DateTime($data_escala_str . ' ' . $start_time_obj_val->format('H:i:s'));
                    $current_end_dt = new DateTime($data_escala_str . ' ' . $end_time_obj_val->format('H:i:s'));
                    if ($current_end_dt <= $current_start_dt) { $current_end_dt->modify('+1 day'); }
                    $hora_inicio_for_db = $current_start_dt->format('H:i:s'); // Mantém H:i:s para DB
                    $hora_fim_for_db = $end_time_obj_val->format('H:i:s');   // Mantém H:i:s para DB
                }
            } catch (Exception $e) { $validation_errors[] = "Erro ao processar horas: " . $e->getMessage(); }
        }

        // Se horas válidas, prossegue para validação de jornada
        if ($pdo && $motorista_id && $data_obj && $hora_inicio_for_db && $hora_fim_for_db && $current_start_dt && $current_end_dt && empty($validation_errors)) {
            // ... (TODA A LÓGICA DE VALIDAÇÃO DE JORNADA - MAX_NORMAL_SECONDS_DAY, etc. - MANTIDA IGUAL À VERSÃO ANTERIOR)
            // Esta parte é complexa e assume-se que está correta da versão anterior.
            // Apenas garantimos que ela só seja executada se $ignorar_validacao_jornada_final for false.
            $status_especiais_sql_array = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];
            $placeholders_para_status = implode(',', array_fill(0, count($status_especiais_sql_array), '?'));
            
            $query_params_other = [$motorista_id, $data_escala_str];
            $sql_other_shifts = "SELECT id, hora_inicio_prevista, hora_fim_prevista, eh_extra, funcao_operacional_id
                                 FROM motorista_escalas
                                 WHERE motorista_id = ? AND data = ?";
            if ($escala_id) { // Se estiver editando, exclui a própria escala da verificação
                $sql_other_shifts .= " AND id != ?";
                $query_params_other[] = $escala_id;
            }
             // Exclui escalas que são status especiais E aquelas de funções que ignoram jornada
            $sql_other_shifts .= " AND (UPPER(work_id) NOT IN (" . $placeholders_para_status . ") ";
            $sql_other_shifts .= " AND (funcao_operacional_id IS NULL OR (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = motorista_escalas.funcao_operacional_id) = 0) )";

            $final_query_params_other = array_merge($query_params_other, $status_especiais_sql_array);

            $stmt_other_shifts = $pdo->prepare($sql_other_shifts);
            $stmt_other_shifts->execute($final_query_params_other);
            $other_scales_on_day = $stmt_other_shifts->fetchAll(PDO::FETCH_ASSOC);

            if ($other_scales_on_day === false) { $validation_errors[] = "Falha ao verificar outras escalas para validação de jornada."; }
            else {
                // Constantes de validação (mantenha as suas ou defina aqui)
                if (!defined('MAX_NORMAL_SECONDS_DAY')) define('MAX_NORMAL_SECONDS_DAY', 6 * 3600 + 40 * 60); // 6h40
                if (!defined('MAX_EXTRA_SECONDS_DAY')) define('MAX_EXTRA_SECONDS_DAY', 2 * 3600);       // 2h
                if (!defined('MAX_TOTAL_SECONDS_DAY')) define('MAX_TOTAL_SECONDS_DAY', (6 * 3600 + 40 * 60) + (2*3600) ); // 8h40
                if (!defined('MAX_INTERVAL_SECONDS')) define('MAX_INTERVAL_SECONDS', 4 * 3600);      // 4h
                if (!defined('MIN_INTERJORNADA_SECONDS')) define('MIN_INTERJORNADA_SECONDS', 11 * 3600); // 11h

                $total_normal_seconds_day = 0; $total_extra_seconds_day = 0; $all_shifts_for_day = [];
                
                $current_shift_is_extra = $eh_extra_val;
                $duration_current_shift_seconds = $current_end_dt->getTimestamp() - $current_start_dt->getTimestamp();

                if ($current_shift_is_extra) { $total_extra_seconds_day += $duration_current_shift_seconds; }
                else { $total_normal_seconds_day += $duration_current_shift_seconds; }
                $all_shifts_for_day[] = ['start' => $current_start_dt, 'end' => $current_end_dt];

                foreach ($other_scales_on_day as $other_shift_item) {
                    // A query já filtrou as funções que ignoram jornada, então aqui todas devem ser validadas
                    if (isset($other_shift_item['hora_inicio_prevista']) && isset($other_shift_item['hora_fim_prevista'])) {
                        try {
                            $other_start_dt_loop = new DateTime($data_escala_str . ' ' . $other_shift_item['hora_inicio_prevista']);
                            $other_end_dt_loop = new DateTime($data_escala_str . ' ' . $other_shift_item['hora_fim_prevista']);
                            if ($other_end_dt_loop <= $other_start_dt_loop) { $other_end_dt_loop->modify('+1 day'); }
                            if ($other_end_dt_loop > $other_start_dt_loop) {
                                $other_duration_seconds = $other_end_dt_loop->getTimestamp() - $other_start_dt_loop->getTimestamp();
                                $other_shift_is_extra = isset($other_shift_item['eh_extra']) && $other_shift_item['eh_extra'] == 1;
                                if ($other_shift_is_extra) { $total_extra_seconds_day += $other_duration_seconds; }
                                else { $total_normal_seconds_day += $other_duration_seconds; }
                                $all_shifts_for_day[] = ['start' => $other_start_dt_loop, 'end' => $other_end_dt_loop];
                            }
                        } catch (Exception $e) { $validation_errors[] = "Erro ao processar horários de pegas existentes (validação)."; break; }
                    }
                }
                // ... (restante da validação de jornada: MAX_NORMAL, MAX_EXTRA, MAX_TOTAL, INTERVALO, INTERJORNADA - MANTIDA)
                if (empty($validation_errors)) {
                    $total_normal_hours_calc = round($total_normal_seconds_day / 3600, 2);
                    $total_extra_hours_calc = round($total_extra_seconds_day / 3600, 2);
                    $overall_total_hours_calc = round(($total_normal_seconds_day + $total_extra_seconds_day) / 3600, 2);
                    if ($total_normal_seconds_day > MAX_NORMAL_SECONDS_DAY) { $validation_errors[] = "Jornada Normal ({$total_normal_hours_calc}h) excede o limite de " . (MAX_NORMAL_SECONDS_DAY/3600) . "h."; }
                    if ($total_extra_seconds_day > MAX_EXTRA_SECONDS_DAY) { $validation_errors[] = "Jornada Extra ({$total_extra_hours_calc}h) excede o limite de " . (MAX_EXTRA_SECONDS_DAY/3600) . "h."; }
                    if (($total_normal_seconds_day + $total_extra_seconds_day) > MAX_TOTAL_SECONDS_DAY) { $validation_errors[] = "Carga horária total ({$overall_total_hours_calc}h) excede o limite de " . (MAX_TOTAL_SECONDS_DAY/3600) . "h."; }
                }
                if (count($all_shifts_for_day) > 1 && empty($validation_errors)) {
                    usort($all_shifts_for_day, function($a, $b) { return $a['start']->getTimestamp() - $b['start']->getTimestamp(); });
                    for ($i = 0; $i < count($all_shifts_for_day) - 1; $i++) {
                        $end_ts = $all_shifts_for_day[$i]['end']->getTimestamp();
                        $start_ts_next = $all_shifts_for_day[$i+1]['start']->getTimestamp();
                        $interval_sec = $start_ts_next - $end_ts;
                        if ($interval_sec > MAX_INTERVAL_SECONDS) { $validation_errors[] = "Intervalo entre pegas excede " . (MAX_INTERVAL_SECONDS/3600) . "h."; break; }
                        if ($interval_sec < 0) { $validation_errors[] = "Sobreposição de horários detectada entre pegas."; break; }
                    }
                }
                if (empty($validation_errors)) { // Validação de Interjornada
                    try {
                        $date_obj_for_prev_inter = new DateTime($data_escala_str);
                        $prev_day_sql_format_inter = $date_obj_for_prev_inter->modify('-1 day')->format('Y-m-d');
                        // Subquery para filtrar funções que ignoram jornada
                        $sql_prev_inter = "SELECT MAX(hora_fim_prevista) FROM motorista_escalas 
                                           WHERE motorista_id = ? AND data = ? 
                                           AND UPPER(work_id) NOT IN ($placeholders_para_status)
                                           AND (funcao_operacional_id IS NULL OR (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = motorista_escalas.funcao_operacional_id) = 0)";
                        $stmt_prev_day_inter = $pdo->prepare($sql_prev_inter);
                        $stmt_prev_day_inter->execute(array_merge([$motorista_id, $prev_day_sql_format_inter], $status_especiais_sql_array));
                        $last_end_prev_day_time_inter = $stmt_prev_day_inter->fetchColumn();
                        if ($last_end_prev_day_time_inter) {
                            $last_end_prev_day_ts_inter = strtotime($prev_day_sql_format_inter . ' ' . $last_end_prev_day_time_inter);
                            if ($last_end_prev_day_ts_inter !== false && ($current_start_dt->getTimestamp() - $last_end_prev_day_ts_inter) < MIN_INTERJORNADA_SECONDS) {
                                $validation_errors[] = "Interjornada com dia anterior menor que " . (MIN_INTERJORNADA_SECONDS/3600) . "h.";
                            }
                        }
                        
                        $date_obj_for_next_inter = new DateTime($data_escala_str);
                        $next_day_sql_format_inter = $date_obj_for_next_inter->modify('+1 day')->format('Y-m-d');
                        // Subquery para filtrar funções que ignoram jornada
                        $sql_next_inter = "SELECT MIN(hora_inicio_prevista) FROM motorista_escalas 
                                           WHERE motorista_id = ? AND data = ? 
                                           AND UPPER(work_id) NOT IN ($placeholders_para_status)
                                           AND (funcao_operacional_id IS NULL OR (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = motorista_escalas.funcao_operacional_id) = 0)";
                        $stmt_next_day_inter = $pdo->prepare($sql_next_inter);
                        $stmt_next_day_inter->execute(array_merge([$motorista_id, $next_day_sql_format_inter], $status_especiais_sql_array));
                        $first_start_next_day_time_inter = $stmt_next_day_inter->fetchColumn();
                        if ($first_start_next_day_time_inter) {
                            $first_start_next_day_ts_inter = strtotime($next_day_sql_format_inter . ' ' . $first_start_next_day_time_inter);
                            if ($first_start_next_day_ts_inter !== false && ($first_start_next_day_ts_inter - $current_end_dt->getTimestamp()) < MIN_INTERJORNADA_SECONDS) {
                                $validation_errors[] = "Interjornada com dia seguinte menor que " . (MIN_INTERJORNADA_SECONDS/3600) . "h.";
                            }
                        }
                    } catch (Exception $e) { $validation_errors[] = "Erro ao validar interjornada."; }
                }


            } // fim else $other_scales_on_day === false
        } // fim if $pdo && ... && empty($validation_errors)
    } // Fim if (!$is_status_especial && !$ignorar_validacao_jornada_final)
    // --- Fim Validação de Horas e Jornada ---


    if (!empty($validation_errors)) {
        $_SESSION['admin_form_error_escala_p'] = implode("<br>", $validation_errors);
        $form_data_repopulate = $_POST; // Repopula com o POST original
        // Adiciona os checkboxes de status se marcados
        if($is_folga) $form_data_repopulate['is_folga_check'] = 'FOLGA'; // Valor pode ser qualquer coisa, a existência da chave é o que importa
        if($is_falta) $form_data_repopulate['is_falta_check'] = 'FALTA';
        if($is_fora_escala) $form_data_repopulate['is_fora_escala_check'] = 'FORADEESCALA';
        if($is_ferias) $form_data_repopulate['is_ferias_check'] = 'FÉRIAS';
        if($is_atestado) $form_data_repopulate['is_atestado_check'] = 'ATESTADO';
        $_SESSION['form_data_escala_planejada'] = $form_data_repopulate;
        header('Location: ' . $redirect_form_location);
        exit;
    }

    // --- Preparar Dados Finais para Salvar ---
    $tabela_to_save = ($is_status_especial || $tipo_escala === 'funcao') ? null : (!empty($tabela_escalas_input) ? $tabela_escalas_input : null);
    $linha_to_save = ($is_status_especial || $tipo_escala === 'funcao') ? null : $linha_origem_id_val;
    $funcao_id_to_save = ($is_status_especial || $tipo_escala === 'linha') ? null : $funcao_operacional_id_val;
    $veiculo_to_save = ($is_status_especial || $tipo_escala === 'funcao') ? null : $veiculo_id_val;
    
    $local_inicio_to_save = $is_status_especial ? null : $local_inicio_id_val;
    $local_fim_to_save = $is_status_especial ? null : $local_fim_id_val;
    $eh_extra_to_save = $is_status_especial ? 0 : $eh_extra_val;
    
    $hora_inicio_final_db = ($is_status_especial || ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final)) ? null : $hora_inicio_for_db;
    $hora_fim_final_db = ($is_status_especial || ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final)) ? null : $hora_fim_for_db;

    // Se for função e ignora jornada, mas as horas foram preenchidas no form, usa-as.
    if ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final && !$is_status_especial) {
        if (!empty($hora_inicio_str)) $hora_inicio_final_db = DateTime::createFromFormat('H:i', $hora_inicio_str)->format('H:i:s'); else $hora_inicio_final_db = null;
        if (!empty($hora_fim_str)) $hora_fim_final_db = DateTime::createFromFormat('H:i', $hora_fim_str)->format('H:i:s'); else $hora_fim_final_db = null;
    }
    // --- Fim Preparar Dados ---


    // --- Operação no Banco de Dados ---
    try {
        $pdo->beginTransaction();
        if ($escala_id) { // Edição
            $sql_op = "UPDATE motorista_escalas SET
                            data = :data, motorista_id = :motorista_id, work_id = :work_id,
                            tabela_escalas = :tabela_escalas, linha_origem_id = :linha_origem_id,
                            funcao_operacional_id = :funcao_operacional_id, /* NOVO CAMPO */
                            hora_inicio_prevista = :hora_inicio, local_inicio_turno_id = :local_inicio,
                            hora_fim_prevista = :hora_fim, local_fim_turno_id = :local_fim,
                            eh_extra = :eh_extra, veiculo_id = :veiculo_id
                          WHERE id = :escala_id_bind";
            $stmt_op = $pdo->prepare($sql_op);
            $stmt_op->bindParam(':escala_id_bind', $escala_id, PDO::PARAM_INT);
        } else { // Cadastro
            $sql_op = "INSERT INTO motorista_escalas
                            (data, motorista_id, work_id, tabela_escalas, linha_origem_id,
                             funcao_operacional_id, /* NOVO CAMPO */
                             hora_inicio_prevista, local_inicio_turno_id, hora_fim_prevista, local_fim_turno_id,
                             eh_extra, veiculo_id)
                           VALUES
                            (:data, :motorista_id, :work_id, :tabela_escalas, :linha_origem_id,
                             :funcao_operacional_id, /* NOVO CAMPO */
                             :hora_inicio, :local_inicio, :hora_fim, :local_fim,
                             :eh_extra, :veiculo_id)";
            $stmt_op = $pdo->prepare($sql_op);
        }

        $stmt_op->bindParam(':data', $data_escala_str, PDO::PARAM_STR);
        $stmt_op->bindParam(':motorista_id', $motorista_id, PDO::PARAM_INT);
        $stmt_op->bindParam(':work_id', $work_id_to_save, PDO::PARAM_STR);
        $stmt_op->bindParam(':tabela_escalas', $tabela_to_save, $tabela_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op->bindParam(':linha_origem_id', $linha_to_save, $linha_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op->bindParam(':funcao_operacional_id', $funcao_id_to_save, $funcao_id_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT); // BIND NOVO CAMPO
        $stmt_op->bindParam(':hora_inicio', $hora_inicio_final_db, $hora_inicio_final_db === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op->bindParam(':local_inicio', $local_inicio_to_save, $local_inicio_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op->bindParam(':hora_fim', $hora_fim_final_db, $hora_fim_final_db === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op->bindParam(':local_fim', $local_fim_to_save, $local_fim_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op->bindParam(':eh_extra', $eh_extra_to_save, PDO::PARAM_INT);
        $stmt_op->bindParam(':veiculo_id', $veiculo_to_save, $veiculo_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        if ($stmt_op->execute()) {
            $pdo->commit();
            $_SESSION['admin_success_message'] = "Entrada da escala planejada " . ($escala_id ? "atualizada" : "cadastrada") . " com sucesso!";
        } else {
            $pdo->rollBack();
            $errorInfoOp = $stmt_op->errorInfo();
            $error_message_user = "Erro ao salvar entrada da escala.";
            if (isset($errorInfoOp[1]) && $errorInfoOp[1] == 1062) { // Código de erro para entrada duplicada
                $error_message_user .= " Possível entrada duplicada (mesmo motorista, data e horários/workID já existe).";
            } else {
                 $error_message_user .= " Detalhes técnicos: SQLSTATE[{$errorInfoOp[0]}] [{$errorInfoOp[1]}] {$errorInfoOp[2]}";
            }
            $_SESSION['admin_error_message'] = $error_message_user;
            error_log("Erro SQL ao salvar escala planejada: SQLSTATE[{$errorInfoOp[0]}] [{$errorInfoOp[1]}] {$errorInfoOp[2]}");
        }

    } catch (PDOException $e_db_op_escala) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Erro PDO CRÍTICO ao processar escala planejada: " . $e_db_op_escala->getMessage());
        $_SESSION['admin_error_message'] = "Erro crítico de banco de dados (PDO) ao salvar escala. Consulte o log do servidor.";
    } catch (Exception $e_gen_op_escala) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Erro Geral CRÍTICO ao processar escala planejada: " . $e_gen_op_escala->getMessage());
        $_SESSION['admin_error_message'] = "Erro crítico geral ao processar escala: " . $e_gen_op_escala->getMessage();
    }
    // --- Fim Operação no Banco ---

    header('Location: ' . $redirect_list_location);
    exit;
} else {
    $_SESSION['admin_error_message'] = "Acesso inválido ao processamento de escala planejada.";
    header('Location: escala_planejada_listar.php'); // Ou uma página de erro geral
    exit;
}
?>