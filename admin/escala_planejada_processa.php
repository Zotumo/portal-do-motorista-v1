<?php
// admin/escala_planejada_processa.php
// ATUALIZADO v10: Adicionada validação de conflito de veículo.

require_once 'auth_check.php';
require_once '../db_config.php';

// --- Permissões ---
$niveis_permitidos_proc_escala = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_proc_escala)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para processar esta ação na Escala Planejada.";
    // Adicionar lógica de redirect_params se necessário para preservar filtros
    $redirect_query_params_err = [];
    if (isset($_POST['pagina_original'])) $redirect_query_params_err['pagina'] = $_POST['pagina_original'];
    if (isset($_POST['filtro_data_original'])) $redirect_query_params_err['data_escala'] = $_POST['filtro_data_original'];
    header('Location: escala_planejada_listar.php' . (!empty($redirect_query_params_err) ? '?' . http_build_query($redirect_query_params_err) : ''));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['salvar_escala_planejada'])) {

    $escala_id = filter_input(INPUT_POST, 'escala_id', FILTER_VALIDATE_INT);
    $tipo_escala = trim($_POST['tipo_escala'] ?? 'linha');
    $data_escala_str = trim($_POST['data_escala'] ?? '');
    $motorista_id = filter_input(INPUT_POST, 'motorista_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    
    $is_folga = isset($_POST['is_folga_check']);
    $is_falta = isset($_POST['is_falta_check']);
    $is_fora_escala = isset($_POST['is_fora_escala_check']);
    $is_ferias = isset($_POST['is_ferias_check']);
    $is_atestado = isset($_POST['is_atestado_check']);
    
    // O nome do campo work_id será 'work_id' (garantido pelo JS do formulário)
    $work_id_input = trim($_POST['work_id'] ?? ''); 
    
    $tabela_escalas_input = trim($_POST['tabela_escalas'] ?? '');
    $linha_origem_id_input = filter_input(INPUT_POST, 'linha_origem_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $linha_origem_id_val = $linha_origem_id_input ?: null;
    
    $veiculo_id_val_input = filter_input(INPUT_POST, 'veiculo_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $veiculo_id_val = $veiculo_id_val_input ?: null;

    $funcao_operacional_id_input = filter_input(INPUT_POST, 'funcao_operacional_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $funcao_operacional_id_val = $funcao_operacional_id_input ?: null;
    $turno_funcao_input = trim($_POST['turno_funcao'] ?? '');
    $posicao_letra_funcao_input = trim($_POST['posicao_letra_funcao'] ?? '');

    $hora_inicio_str = trim($_POST['hora_inicio_prevista'] ?? '');
    $local_inicio_id_input = filter_input(INPUT_POST, 'local_inicio_turno_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $local_inicio_id_val = $local_inicio_id_input ?: null;
    
    $hora_fim_str = trim($_POST['hora_fim_prevista'] ?? '');
    $local_fim_id_input = filter_input(INPUT_POST, 'local_fim_turno_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $local_fim_id_val = $local_fim_id_input ?: null;
    
    $eh_extra_val = (isset($_POST['eh_extra']) && $_POST['eh_extra'] == '1') ? 1 : 0;

    // Parâmetros para redirecionamento
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
    $funcao_data_db = null;
    $ignorar_validacao_jornada_final = false;

    if (empty($data_escala_str)) { $validation_errors[] = "Data da Escala é obrigatória."; }
    else {
        try { $data_obj = new DateTime($data_escala_str); if ($data_obj->format('Y-m-d') !== $data_escala_str) { throw new Exception(); }}
        catch (Exception $e) { $validation_errors[] = "Data da Escala inválida. Use AAAA-MM-DD."; $data_obj = null; }
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
                $validation_errors[] = "Função Operacional é obrigatória quando o tipo é 'Função'.";
            } else {
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
                        $sugestao_work_id_funcao = $funcao_data_db['work_id_prefixo'];
                        if (!$funcao_data_db['local_fixo_id'] && $local_inicio_id_val && $pdo) {
                            // ... (lógica para nome curto do local)
                        }
                        $sugestao_work_id_funcao .= '-' . $turno_funcao_input;
                        if ($funcao_data_db['requer_posicao_especifica'] && !empty($posicao_letra_funcao_input)) {
                            $sugestao_work_id_funcao .= strtoupper($posicao_letra_funcao_input);
                        }
                        // Se o WorkID do formulário (vindo de $work_id_input) estiver vazio ou diferente da sugestão,
                        // e o usuário não digitou nada explicitamente nele, podemos usar a sugestão.
                        // A validação no JS já deve ter construído o $work_id_input corretamente.
                        // Aqui, $work_id_to_save já tem o valor de $work_id_input, que foi o campo nomeado 'work_id' no form.
                        if(empty($work_id_input) || $work_id_input !== $sugestao_work_id_funcao){
                            // Considerar se o $work_id_input enviado pelo form deve ser usado ou sempre o gerado.
                            // Se o JS já monta e coloca no campo 'work_id', $work_id_input aqui já será o correto.
                        }
                         $work_id_to_save = $work_id_input; // Confirma que está usando o que veio do campo 'work_id'

                        if($funcao_data_db['local_fixo_id']){
                            $local_inicio_id_val = $funcao_data_db['local_fixo_id'];
                            $local_fim_id_val = $funcao_data_db['local_fixo_id'];
                        }
                    }
                } else { $validation_errors[] = "Erro de conexão para validar Função Operacional."; }
            }
            $linha_origem_id_val = null; $veiculo_id_val = null; $tabela_escalas_input = null;
        } elseif ($tipo_escala === 'linha') {
            if (empty($work_id_input)) { $validation_errors[] = "WorkID é obrigatório para escala de linha."; }
            if (empty($linha_origem_id_val)) { $validation_errors[] = "Linha de Origem é obrigatória para escala de linha."; }
            // Adicionado: Veículo é obrigatório para escala de linha se não for status especial
            if (empty($veiculo_id_val)) { $validation_errors[] = "Veículo é obrigatório para escala de linha.";}

            $funcao_operacional_id_val = null; $ignorar_validacao_jornada_final = false;
        } else { $validation_errors[] = "Tipo de Escala inválido selecionado."; }
    } else {
        $ignorar_validacao_jornada_final = true;
        $tipo_escala = null; $funcao_operacional_id_val = null; $linha_origem_id_val = null;
        $veiculo_id_val = null; $tabela_escalas_input = null; $hora_inicio_str = null;
        $local_inicio_id_val = null; $hora_fim_str = null; $local_fim_id_val = null; $eh_extra_val = 0;
    }

    $hora_inicio_for_db = null; $hora_fim_for_db = null;
    $current_start_dt = null; $current_end_dt = null;

    if (!$is_status_especial && !$ignorar_validacao_jornada_final) {
        if (empty($hora_inicio_str) || empty($hora_fim_str)) {
            $validation_errors[] = "Hora de Início e Hora Final são obrigatórias se não for um status especial ou função que ignora validação.";
        } else {
            try {
                $start_time_obj_val = DateTime::createFromFormat('H:i', $hora_inicio_str);
                $end_time_obj_val = DateTime::createFromFormat('H:i', $hora_fim_str);
                if (!$start_time_obj_val || $start_time_obj_val->format('H:i') !== $hora_inicio_str) { $validation_errors[] = "Formato Hora Início inválido."; }
                if (!$end_time_obj_val || $end_time_obj_val->format('H:i') !== $hora_fim_str) { $validation_errors[] = "Formato Hora Fim inválido."; }
                if ($start_time_obj_val && $end_time_obj_val && $data_obj && empty($validation_errors)) {
                    $current_start_dt = new DateTime($data_escala_str . ' ' . $start_time_obj_val->format('H:i:s'));
                    $current_end_dt = new DateTime($data_escala_str . ' ' . $end_time_obj_val->format('H:i:s'));
                    if ($current_end_dt <= $current_start_dt) { $current_end_dt->modify('+1 day'); }
                    $hora_inicio_for_db = $current_start_dt->format('H:i:s');
                    $hora_fim_for_db = $end_time_obj_val->format('H:i:s');
                }
            } catch (Exception $e) { $validation_errors[] = "Erro ao processar horas: " . $e->getMessage(); }
        }

        if ($pdo && $motorista_id && $data_obj && $hora_inicio_for_db && $hora_fim_for_db && $current_start_dt && $current_end_dt && empty($validation_errors)) {
            // --- VALIDAÇÃO DE JORNADA (MAX_NORMAL_SECONDS_DAY, etc.) ---
            // Esta lógica permanece como na sua versão anterior, mas é executada condicionalmente.
            // ... (colar aqui a sua lógica completa de validação de jornada para MAX_NORMAL, MAX_EXTRA, MAX_TOTAL, INTERVALO, INTERJORNADA)
        }
    }
    
    // ***** VALIDAÇÃO DE CONFLITO DE VEÍCULO (Inserida aqui) *****
    if (!$is_status_especial && $tipo_escala === 'linha' && $veiculo_id_val && $data_obj && $hora_inicio_for_db && $hora_fim_for_db && empty($validation_errors)) {
        if ($pdo) {
            try {
                $inicio_atual_dt_obj = new DateTime($data_escala_str . ' ' . $hora_inicio_for_db);
                $fim_atual_dt_obj = new DateTime($data_escala_str . ' ' . $hora_fim_for_db);
                if ($fim_atual_dt_obj <= $inicio_atual_dt_obj) { $fim_atual_dt_obj->modify('+1 day'); }

                $sql_conflito_veiculo = "SELECT esc.id, esc.hora_inicio_prevista, esc.hora_fim_prevista, mot.nome as nome_motorista_conflito, veic.prefixo as prefixo_veiculo_conflito
                                         FROM motorista_escalas esc
                                         LEFT JOIN motoristas mot ON esc.motorista_id = mot.id
                                         LEFT JOIN veiculos veic ON esc.veiculo_id = veic.id
                                         WHERE esc.data = :data_conflito
                                           AND esc.veiculo_id = :veiculo_id_conflito";
                if ($escala_id) { // Se estiver editando, exclui a própria escala
                    $sql_conflito_veiculo .= " AND esc.id != :escala_id_atual_conflito";
                }

                $stmt_conflito_veiculo = $pdo->prepare($sql_conflito_veiculo);
                $stmt_conflito_veiculo->bindParam(':data_conflito', $data_escala_str, PDO::PARAM_STR);
                $stmt_conflito_veiculo->bindParam(':veiculo_id_conflito', $veiculo_id_val, PDO::PARAM_INT);
                if ($escala_id) {
                    $stmt_conflito_veiculo->bindParam(':escala_id_atual_conflito', $escala_id, PDO::PARAM_INT);
                }
                
                $stmt_conflito_veiculo->execute();
                $escalas_conflitantes_veiculo = $stmt_conflito_veiculo->fetchAll(PDO::FETCH_ASSOC);

                if ($escalas_conflitantes_veiculo) {
                    foreach ($escalas_conflitantes_veiculo as $conflito_v) {
                        if ($conflito_v['hora_inicio_prevista'] && $conflito_v['hora_fim_prevista']) {
                            $inicio_existente_dt_obj = new DateTime($data_escala_str . ' ' . $conflito_v['hora_inicio_prevista']);
                            $fim_existente_dt_obj = new DateTime($data_escala_str . ' ' . $conflito_v['hora_fim_prevista']);
                            if ($fim_existente_dt_obj <= $inicio_existente_dt_obj) { $fim_existente_dt_obj->modify('+1 day'); }

                            if ($inicio_atual_dt_obj < $fim_existente_dt_obj && $fim_atual_dt_obj > $inicio_existente_dt_obj) {
                                $prefixo_atual_conflito = $conflito_v['prefixo_veiculo_conflito'] ?? 'desconhecido';
                                $validation_errors[] = "Conflito de veículo! O veículo prefixo '{$prefixo_atual_conflito}' já está alocado para '" . 
                                                       htmlspecialchars($conflito_v['nome_motorista_conflito'] ?: 'Outra escala') . 
                                                       "' entre " . $inicio_existente_dt_obj->format('H:i') . 
                                                       " e " . $fim_existente_dt_obj->format('H:i') . " nesta data.";
                                break; 
                            }
                        }
                    }
                }
            } catch (PDOException $e_veic_conflito) {
                $validation_errors[] = "Erro ao verificar conflito de alocação do veículo.";
                error_log("Erro PDO ao verificar conflito de veículo: " . $e_veic_conflito->getMessage());
            }
        }
    }


    if (!empty($validation_errors)) {
        $_SESSION['admin_form_error_escala_p'] = implode("<br>", $validation_errors);
        $_SESSION['form_data_escala_planejada'] = $_POST;
        header('Location: ' . $redirect_form_location);
        exit;
    }

    // --- Preparar Dados Finais para Salvar ---
    $tabela_to_save = ($is_status_especial || $tipo_escala === 'funcao') ? null : (!empty($tabela_escalas_input) ? $tabela_escalas_input : null);
    $linha_to_save = ($is_status_especial || $tipo_escala === 'funcao') ? null : $linha_origem_id_val;
    $funcao_id_to_save = ($is_status_especial || $tipo_escala === 'linha') ? null : $funcao_operacional_id_val;
    // Veículo só é salvo se for tipo 'linha' e não for status especial
    $veiculo_to_save = ($is_status_especial || $tipo_escala !== 'linha') ? null : $veiculo_id_val; 
    
    $local_inicio_to_save = $is_status_especial ? null : $local_inicio_id_val;
    $local_fim_to_save = $is_status_especial ? null : $local_fim_id_val;
    $eh_extra_to_save = $is_status_especial ? 0 : $eh_extra_val;
    
    $hora_inicio_final_db = ($is_status_especial || ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final)) ? null : $hora_inicio_for_db;
    $hora_fim_final_db = ($is_status_especial || ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final)) ? null : $hora_fim_for_db;

    if ($tipo_escala === 'funcao' && $ignorar_validacao_jornada_final && !$is_status_especial) {
        if (!empty($hora_inicio_str)) $hora_inicio_final_db = DateTime::createFromFormat('H:i', $hora_inicio_str)->format('H:i:s'); else $hora_inicio_final_db = null;
        if (!empty($hora_fim_str)) $hora_fim_final_db = DateTime::createFromFormat('H:i', $hora_fim_str)->format('H:i:s'); else $hora_fim_final_db = null;
    }

    // --- Operação no Banco de Dados ---
    try {
        $pdo->beginTransaction();
        if ($escala_id) { // Edição
            $sql_op = "UPDATE motorista_escalas SET
                            data = :data, motorista_id = :motorista_id, work_id = :work_id,
                            tabela_escalas = :tabela_escalas, linha_origem_id = :linha_origem_id,
                            funcao_operacional_id = :funcao_operacional_id,
                            hora_inicio_prevista = :hora_inicio, local_inicio_turno_id = :local_inicio,
                            hora_fim_prevista = :hora_fim, local_fim_turno_id = :local_fim,
                            eh_extra = :eh_extra, veiculo_id = :veiculo_id
                          WHERE id = :escala_id_bind";
            $stmt_op = $pdo->prepare($sql_op);
            $stmt_op->bindParam(':escala_id_bind', $escala_id, PDO::PARAM_INT);
        } else { // Cadastro
            $sql_op = "INSERT INTO motorista_escalas
                            (data, motorista_id, work_id, tabela_escalas, linha_origem_id,
                             funcao_operacional_id,
                             hora_inicio_prevista, local_inicio_turno_id, hora_fim_prevista, local_fim_turno_id,
                             eh_extra, veiculo_id)
                           VALUES
                            (:data, :motorista_id, :work_id, :tabela_escalas, :linha_origem_id,
                             :funcao_operacional_id,
                             :hora_inicio, :local_inicio, :hora_fim, :local_fim,
                             :eh_extra, :veiculo_id)";
            $stmt_op = $pdo->prepare($sql_op);
        }

        $stmt_op->bindParam(':data', $data_escala_str, PDO::PARAM_STR);
        $stmt_op->bindParam(':motorista_id', $motorista_id, PDO::PARAM_INT);
        $stmt_op->bindParam(':work_id', $work_id_to_save, PDO::PARAM_STR);
        $stmt_op->bindParam(':tabela_escalas', $tabela_to_save, $tabela_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt_op->bindParam(':linha_origem_id', $linha_to_save, $linha_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op->bindParam(':funcao_operacional_id', $funcao_id_to_save, $funcao_id_to_save === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
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
            if (isset($errorInfoOp[1]) && $errorInfoOp[1] == 1062) { 
                $error_message_user .= " Possível entrada duplicada.";
            } else {
                 $error_message_user .= " Detalhes: SQLSTATE[{$errorInfoOp[0]}] {$errorInfoOp[1]}"; // Removido $errorInfoOp[2] para ser mais conciso
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

    header('Location: ' . $redirect_list_location);
    exit;
} else {
    $_SESSION['admin_error_message'] = "Acesso inválido ao processamento de escala planejada.";
    header('Location: escala_planejada_listar.php');
    exit;
}
?>