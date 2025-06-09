<?php
// admin/escala_diaria_processa.php
// ATUALIZADO v11: Adicionada validação de conflito de veículo.

require_once 'auth_check.php';
require_once '../db_config.php';

// --- Permissões ---
$niveis_permitidos_gerenciar_diaria = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerenciar_diaria)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para gerenciar a Escala Diária.";
    // Adicionar lógica de redirect_params se necessário
    $redirect_query_params_err_d = [];
    if (isset($_POST['pagina_original'])) $redirect_query_params_err_d['pagina'] = $_POST['pagina_original'];
    if (isset($_POST['filtro_data_original'])) $redirect_query_params_err_d['data_escala'] = $_POST['filtro_data_original'];
    header('Location: escala_diaria_consultar.php' . (!empty($redirect_query_params_err_d) ? '?' . http_build_query($redirect_query_params_err_d) : ''));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['salvar_escala_diaria'])) {

    $escala_diaria_id = filter_input(INPUT_POST, 'escala_diaria_id', FILTER_VALIDATE_INT);
    $tipo_escala = trim($_POST['tipo_escala'] ?? 'linha');
    $data_escala_str = trim($_POST['data_escala'] ?? '');
    $motorista_id = filter_input(INPUT_POST, 'motorista_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    
    $is_folga = isset($_POST['is_folga_check']);
    $is_falta = isset($_POST['is_falta_check']);
    $is_fora_escala = isset($_POST['is_fora_escala_check']);
    $is_ferias = isset($_POST['is_ferias_check']);
    $is_atestado = isset($_POST['is_atestado_check']);
    
    $work_id_input = trim($_POST['work_id'] ?? ''); // Nome do campo 'work_id' do formulário
    
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
    $observacoes_ajuste_input = trim($_POST['observacoes_ajuste'] ?? '');
    $admin_id_logado_param_d = $_SESSION['admin_user_id'];


    // --- Parâmetros para redirecionamento ---
    $redirect_query_params_d = [];
    if (isset($_POST['pagina_original'])) $redirect_query_params_d['pagina'] = $_POST['pagina_original'];
    if (isset($_POST['filtro_data_original'])) $redirect_query_params_d['data_escala'] = $_POST['filtro_data_original'];
    if (isset($_POST['filtro_tipo_busca_original'])) $redirect_query_params_d['tipo_busca_adicional'] = $_POST['filtro_tipo_busca_original'];
    if (isset($_POST['filtro_valor_busca_original'])) $redirect_query_params_d['valor_busca_adicional'] = $_POST['filtro_valor_busca_original'];
    
    $redirect_form_location_d = ($escala_diaria_id ? 'escala_diaria_formulario.php?id=' . $escala_diaria_id : 'escala_diaria_formulario.php');
    if (!empty($redirect_query_params_d)) {
        $redirect_form_location_d .= (strpos($redirect_form_location_d, '?') === false ? '?' : '&') . http_build_query($redirect_query_params_d);
    }
    $redirect_list_location_d = 'escala_diaria_consultar.php' . (!empty($redirect_query_params_d) ? '?' . http_build_query($redirect_query_params_d) : '');

    $validation_errors_d = [];
    $data_obj_d = null;
    $funcao_data_db_d = null;
    $ignorar_validacao_jornada_final_d = false;

    if (empty($data_escala_str)) { $validation_errors_d[] = "Data da Escala Diária é obrigatória."; }
    else {
        try { $data_obj_d = new DateTime($data_escala_str); if ($data_obj_d->format('Y-m-d') !== $data_escala_str) { throw new Exception(); }}
        catch (Exception $e) { $validation_errors_d[] = "Data da Escala Diária inválida. Use AAAA-MM-DD."; $data_obj_d = null; }
    }
    if (empty($motorista_id)) { $validation_errors_d[] = "Motorista é obrigatório."; }

    $is_status_especial_d = false;
    $work_id_to_save_d = $work_id_input;

    if ($is_folga) { $work_id_to_save_d = 'FOLGA'; $is_status_especial_d = true; }
    elseif ($is_falta) { $work_id_to_save_d = 'FALTA'; $is_status_especial_d = true; }
    elseif ($is_fora_escala) { $work_id_to_save_d = 'FORADEESCALA'; $is_status_especial_d = true; }
    elseif ($is_ferias) { $work_id_to_save_d = 'FÉRIAS'; $is_status_especial_d = true; }
    elseif ($is_atestado) { $work_id_to_save_d = 'ATESTADO'; $is_status_especial_d = true; }

    if (!$is_status_especial_d) {
        if ($tipo_escala === 'funcao') {
            if (empty($funcao_operacional_id_val)) { $validation_errors_d[] = "Função Operacional é obrigatória (Diária)."; }
            else {
                if ($pdo) {
                    // ... (Busca dados da função e valida, como na planejada, usando $funcao_data_db_d e $ignorar_validacao_jornada_final_d)
                    // ... (Lógica para construir $work_id_to_save_d para função)
                } else { $validation_errors_d[] = "Erro de conexão para validar Função (Diária)."; }
            }
            $linha_origem_id_val = null; $veiculo_id_val = null; $tabela_escalas_input = null;
        } elseif ($tipo_escala === 'linha') {
            if (empty($work_id_input)) { $validation_errors_d[] = "WorkID é obrigatório para escala de linha (Diária)."; }
            if (empty($linha_origem_id_val)) { $validation_errors_d[] = "Linha de Origem é obrigatória (Diária)."; }
            if (empty($veiculo_id_val)) { $validation_errors_d[] = "Veículo é obrigatório para escala de linha (Diária)."; } // Veículo obrigatório
            $funcao_operacional_id_val = null; $ignorar_validacao_jornada_final_d = false;
        } else { $validation_errors_d[] = "Tipo de Escala inválido (Diária)."; }
    } else { /* ... (Zera campos não aplicáveis para status especial) ... */ }

    $hora_inicio_for_db_d = null; $hora_fim_for_db_d = null;
    $current_start_dt_d = null; $current_end_dt_d = null;

    if (!$is_status_especial_d && !$ignorar_validacao_jornada_final_d) {
        if (empty($hora_inicio_str) || empty($hora_fim_str)) { $validation_errors_d[] = "Hora de Início e Fim são obrigatórias (Diária, se aplicável)."; }
        else {
            try { /* ... (Validação de formato de hora e cálculo de $current_start_dt_d, $current_end_dt_d) ... */ }
            catch (Exception $e) { $validation_errors_d[] = "Erro ao processar horas (Diária): " . $e->getMessage(); }
        }
        if ($pdo && $motorista_id && $data_obj_d && $hora_inicio_for_db_d && $hora_fim_for_db_d && empty($validation_errors_d)) {
            // --- VALIDAÇÃO DE JORNADA PARA ESCALA DIÁRIA ---
            // (Lógica similar à da planejada, mas consultando 'motorista_escalas_diaria')
            // ...
        }
    }

    // ***** NOVA VALIDAÇÃO: CONFLITO DE VEÍCULO PARA ESCALA DIÁRIA *****
    if (!$is_status_especial_d && $tipo_escala === 'linha' && $veiculo_id_val && $data_obj_d && $hora_inicio_for_db_d && $hora_fim_for_db_d && empty($validation_errors_d)) {
        if ($pdo) {
            try {
                $inicio_atual_dt_obj_d = new DateTime($data_escala_str . ' ' . $hora_inicio_for_db_d);
                $fim_atual_dt_obj_d = new DateTime($data_escala_str . ' ' . $hora_fim_for_db_d);
                if ($fim_atual_dt_obj_d <= $inicio_atual_dt_obj_d) { $fim_atual_dt_obj_d->modify('+1 day'); }

                $sql_conflito_veic_d = "SELECT escd.id, escd.hora_inicio_prevista, escd.hora_fim_prevista, mot.nome as nome_motorista_conflito, veic.prefixo as prefixo_veiculo_conflito
                                          FROM motorista_escalas_diaria escd
                                          LEFT JOIN motoristas mot ON escd.motorista_id = mot.id
                                          LEFT JOIN veiculos veic ON escd.veiculo_id = veic.id
                                          WHERE escd.data = :data_conflito_d
                                            AND escd.veiculo_id = :veiculo_id_conflito_d";
                if ($escala_diaria_id) { // Se estiver editando, exclui a própria escala
                    $sql_conflito_veic_d .= " AND escd.id != :escala_id_atual_conflito_d";
                }

                $stmt_conflito_veic_d = $pdo->prepare($sql_conflito_veic_d);
                $stmt_conflito_veic_d->bindParam(':data_conflito_d', $data_escala_str, PDO::PARAM_STR);
                $stmt_conflito_veic_d->bindParam(':veiculo_id_conflito_d', $veiculo_id_val, PDO::PARAM_INT);
                if ($escala_diaria_id) {
                    $stmt_conflito_veic_d->bindParam(':escala_id_atual_conflito_d', $escala_diaria_id, PDO::PARAM_INT);
                }
                
                $stmt_conflito_veic_d->execute();
                $escalas_conflitantes_veic_d = $stmt_conflito_veic_d->fetchAll(PDO::FETCH_ASSOC);

                if ($escalas_conflitantes_veic_d) {
                    foreach ($escalas_conflitantes_veic_d as $conflito_vd) {
                        if ($conflito_vd['hora_inicio_prevista'] && $conflito_vd['hora_fim_prevista']) {
                            $inicio_existente_dt_obj_d = new DateTime($data_escala_str . ' ' . $conflito_vd['hora_inicio_prevista']);
                            $fim_existente_dt_obj_d = new DateTime($data_escala_str . ' ' . $conflito_vd['hora_fim_prevista']);
                            if ($fim_existente_dt_obj_d <= $inicio_existente_dt_obj_d) { $fim_existente_dt_obj_d->modify('+1 day'); }

                            if ($inicio_atual_dt_obj_d < $fim_existente_dt_obj_d && $fim_atual_dt_obj_d > $inicio_existente_dt_obj_d) {
                                $prefixo_atual_conflito_d = $conflito_vd['prefixo_veiculo_conflito'] ?? 'desconhecido';
                                $validation_errors_d[] = "Conflito de veículo (Escala Diária)! O veículo prefixo '{$prefixo_atual_conflito_d}' já está alocado para '" . 
                                                       htmlspecialchars($conflito_vd['nome_motorista_conflito'] ?: 'Outra escala') . 
                                                       "' entre " . $inicio_existente_dt_obj_d->format('H:i') . 
                                                       " e " . $fim_existente_dt_obj_d->format('H:i') . " nesta data.";
                                break; 
                            }
                        }
                    }
                }
            } catch (PDOException $e_veic_conflito_d) {
                $validation_errors_d[] = "Erro ao verificar conflito de veículo (Diária).";
                error_log("Erro PDO conflito veículo (Diária): " . $e_veic_conflito_d->getMessage());
            }
        }
    }
    // ***** FIM VALIDAÇÃO DE CONFLITO DE VEÍCULO PARA ESCALA DIÁRIA *****


    if (!empty($validation_errors_d)) {
        $_SESSION['admin_form_error_escala_d'] = implode("<br>", $validation_errors_d);
        $_SESSION['form_data_escala_diaria'] = $_POST; // Repopula com o POST original
        header('Location: ' . $redirect_form_location_d);
        exit;
    }

    // --- Preparar Dados Finais para Salvar (com sufixo _d) ---
    $tabela_to_save_d = ($is_status_especial_d || $tipo_escala === 'funcao') ? null : $tabela_escalas_input;
    // ... (outros campos com sufixo _d)
    $veiculo_to_save_d = ($is_status_especial_d || $tipo_escala !== 'linha') ? null : $veiculo_id_val;


    // --- Operação no Banco de Dados (tabela motorista_escalas_diaria) ---
    try {
        $pdo->beginTransaction();
        if ($escala_diaria_id) { // Edição
            $sql_op_d = "UPDATE motorista_escalas_diaria SET
                            data = :data, motorista_id = :motorista_id, work_id = :work_id,
                            tabela_escalas = :tabela_escalas, linha_origem_id = :linha_origem_id,
                            funcao_operacional_id = :funcao_operacional_id, 
                            hora_inicio_prevista = :hora_inicio, local_inicio_turno_id = :local_inicio,
                            hora_fim_prevista = :hora_fim, local_fim_turno_id = :local_fim,
                            eh_extra = :eh_extra, veiculo_id = :veiculo_id,
                            observacoes_ajuste = :observacoes_ajuste,
                            modificado_por_admin_id = :modificado_por_admin_id
                          WHERE id = :escala_diaria_id_bind";
            $stmt_op_d = $pdo->prepare($sql_op_d);
            $stmt_op_d->bindParam(':escala_diaria_id_bind', $escala_diaria_id, PDO::PARAM_INT);
        } else { // Cadastro
            $sql_op_d = "INSERT INTO motorista_escalas_diaria
                            (data, motorista_id, work_id, tabela_escalas, linha_origem_id, funcao_operacional_id,
                             hora_inicio_prevista, local_inicio_turno_id, hora_fim_prevista, local_fim_turno_id,
                             eh_extra, veiculo_id, observacoes_ajuste, modificado_por_admin_id)
                           VALUES
                            (:data, :motorista_id, :work_id, :tabela_escalas, :linha_origem_id, :funcao_operacional_id,
                             :hora_inicio, :local_inicio, :hora_fim, :local_fim,
                             :eh_extra, :veiculo_id, :observacoes_ajuste, :modificado_por_admin_id)";
            $stmt_op_d = $pdo->prepare($sql_op_d);
        }

        // Binds (com sufixo _d para os valores preparados)
        $stmt_op_d->bindParam(':data', $data_escala_str, PDO::PARAM_STR);
        $stmt_op_d->bindParam(':motorista_id', $motorista_id, PDO::PARAM_INT);
        $stmt_op_d->bindParam(':work_id', $work_id_to_save_d, PDO::PARAM_STR);
        // ... (bind de todos os outros parâmetros, usando as variáveis com _d)
        $stmt_op_d->bindParam(':veiculo_id', $veiculo_to_save_d, $veiculo_to_save_d === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_op_d->bindParam(':observacoes_ajuste', $observacoes_ajuste_input, PDO::PARAM_STR);
        $stmt_op_d->bindParam(':modificado_por_admin_id', $admin_id_logado_param_d, PDO::PARAM_INT);
        // ... (etc.)


        if ($stmt_op_d->execute()) {
            $pdo->commit();
            $_SESSION['admin_success_message'] = "Entrada da Escala Diária " . ($escala_diaria_id ? "atualizada" : "salva") . " com sucesso!";
        } else {
            $pdo->rollBack();
            $errorInfoOp_d = $stmt_op_d->errorInfo();
            // ... (tratamento de erro SQL)
            $_SESSION['admin_error_message'] = "Erro ao salvar Escala Diária. Detalhes: SQLSTATE[{$errorInfoOp_d[0]}] {$errorInfoOp_d[1]}";
        }

    } catch (PDOException $e_db_op_escala_d) { /* ... */ }
    catch (Exception $e_gen_op_escala_d) { /* ... */ }

    header('Location: ' . $redirect_list_location_d);
    exit;
} else {
    $_SESSION['admin_error_message'] = "Acesso inválido ao processamento de Escala Diária.";
    header('Location: escala_diaria_consultar.php');
    exit;
}
?>