<?php
// admin/buscar_veiculos_disponiveis_ajax.php

require_once 'auth_check.php';
require_once '../db_config.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Parâmetros inválidos.', 'veiculos' => []];

// Permissões
$niveis_permitidos_buscar_veiculos_escala = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_buscar_veiculos_escala)) {
    $response['message'] = 'Acesso negado.';
    echo json_encode($response);
    exit;
}

// Receber parâmetros (GET ou POST, dependendo de como o JS enviar)
$linha_id = isset($_REQUEST['linha_id']) ? filter_var($_REQUEST['linha_id'], FILTER_VALIDATE_INT) : null;
$data_escala_str = isset($_REQUEST['data_escala']) ? trim($_REQUEST['data_escala']) : null;
$hora_inicio_str = isset($_REQUEST['hora_inicio']) ? trim($_REQUEST['hora_inicio']) : null;
$hora_fim_str = isset($_REQUEST['hora_fim']) ? trim($_REQUEST['hora_fim']) : null;
$escala_id_atual_ajax = isset($_REQUEST['escala_id_atual']) ? filter_var($_REQUEST['escala_id_atual'], FILTER_VALIDATE_INT) : 0; // 0 se não estiver editando
$tabela_escala_alvo = isset($_REQUEST['tabela_escala']) ? trim($_REQUEST['tabela_escala']) : null; // 'planejada' ou 'diaria'

// Validações básicas dos parâmetros obrigatórios
if (empty($data_escala_str) || empty($hora_inicio_str) || empty($hora_fim_str) || !in_array($tabela_escala_alvo, ['planejada', 'diaria'])) {
    $response['message'] = 'Parâmetros essenciais ausentes ou inválidos (data, horários, tabela alvo).';
    echo json_encode($response);
    exit;
}

// Validar formato da data e horas
try {
    $data_obj_ajax = new DateTime($data_escala_str);
    if ($data_obj_ajax->format('Y-m-d') !== $data_escala_str) throw new Exception('Data inválida');
    
    $inicio_req_dt_obj = new DateTime($data_escala_str . ' ' . $hora_inicio_str);
    if ($inicio_req_dt_obj->format('Y-m-d H:i') !== $data_escala_str . ' ' . $hora_inicio_str && $inicio_req_dt_obj->format('Y-m-d H:i:s') !== $data_escala_str . ' ' . $hora_inicio_str.':00') throw new Exception('Hora início inválida');

    $fim_req_dt_obj = new DateTime($data_escala_str . ' ' . $hora_fim_str);
    if ($fim_req_dt_obj->format('Y-m-d H:i') !== $data_escala_str . ' ' . $hora_fim_str && $fim_req_dt_obj->format('Y-m-d H:i:s') !== $data_escala_str . ' ' . $hora_fim_str.':00') throw new Exception('Hora fim inválida');

    if ($fim_req_dt_obj <= $inicio_req_dt_obj) {
        $fim_req_dt_obj->modify('+1 day'); // Considera virada de dia
    }
} catch (Exception $e) {
    $response['message'] = 'Formato de data ou hora inválido: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}


if ($pdo) {
    try {
        $tipos_permitidos_para_linha = [];
        if ($linha_id) {
            $stmt_tipos = $pdo->prepare("SELECT tipo_veiculo FROM linha_tipos_veiculo_permitidos WHERE linha_id = :linha_id");
            $stmt_tipos->bindParam(':linha_id', $linha_id, PDO::PARAM_INT);
            $stmt_tipos->execute();
            $tipos_permitidos_para_linha = $stmt_tipos->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tipos_permitidos_para_linha)) {
                $response['message'] = 'Nenhum tipo de veículo configurado para esta linha.';
                $response['success'] = true; // Sucesso, mas sem veículos
                echo json_encode($response);
                exit;
            }
        }

        // Montar a query base para buscar veículos candidatos
        $sql_veiculos_candidatos = "SELECT id, prefixo, tipo FROM veiculos WHERE status = 'operação'";
        $params_veiculos_candidatos = [];

        if ($linha_id && !empty($tipos_permitidos_para_linha)) {
            $in_placeholders_tipos = implode(',', array_fill(0, count($tipos_permitidos_para_linha), '?'));
            $sql_veiculos_candidatos .= " AND tipo IN ({$in_placeholders_tipos})";
            $params_veiculos_candidatos = $tipos_permitidos_para_linha;
        } elseif ($linha_id && empty($tipos_permitidos_para_linha)) {
            // Se uma linha foi especificada mas não tem tipos permitidos, nenhum veículo pode ser usado.
            $response['success'] = true;
            $response['message'] = 'Linha não tem tipos de veículo permitidos configurados.';
            echo json_encode($response);
            exit;
        }
        // Se $linha_id for nulo (ex: para uma função que não restringe linha), busca todos os veículos em operação.

        $sql_veiculos_candidatos .= " ORDER BY prefixo ASC";
        
        $stmt_candidatos = $pdo->prepare($sql_veiculos_candidatos);
        $stmt_candidatos->execute($params_veiculos_candidatos);
        $veiculos_candidatos = $stmt_candidatos->fetchAll(PDO::FETCH_ASSOC);

        $veiculos_disponiveis = [];
        $tabela_escala_db_name = ($tabela_escala_alvo === 'planejada') ? 'motorista_escalas' : 'motorista_escalas_diaria';

        if ($veiculos_candidatos) {
            foreach ($veiculos_candidatos as $candidato) {
                $veiculo_esta_livre = true;
                // Verificar conflitos para este candidato
                $sql_conflito = "SELECT id FROM {$tabela_escala_db_name}
                                 WHERE veiculo_id = :veiculo_id_check
                                   AND data = :data_check
                                   AND id != :escala_id_atual_check 
                                   AND (
                                       (hora_inicio_prevista < :hora_fim_req AND hora_fim_prevista > :hora_inicio_req) OR
                                       (hora_inicio_prevista <= :hora_inicio_req AND hora_fim_prevista >= :hora_fim_req) OR
                                       (hora_inicio_prevista >= :hora_inicio_req AND hora_fim_prevista <= :hora_fim_req)
                                   )";
                // A condição de sobreposição acima é uma forma comum, mas pode precisar de ajuste fino 
                // para "virada de dia" se hora_fim_prevista for menor que hora_inicio_prevista.
                // A lógica com objetos DateTime é mais robusta para isso.

                $stmt_check_conflito = $pdo->prepare($sql_conflito);
                $stmt_check_conflito->bindParam(':veiculo_id_check', $candidato['id'], PDO::PARAM_INT);
                $stmt_check_conflito->bindParam(':data_check', $data_escala_str, PDO::PARAM_STR);
                $stmt_check_conflito->bindParam(':escala_id_atual_check', $escala_id_atual_ajax, PDO::PARAM_INT);
                $stmt_check_conflito->bindValue(':hora_inicio_req', $inicio_req_dt_obj->format('H:i:s'));
                $stmt_check_conflito->bindValue(':hora_fim_req', $fim_req_dt_obj->format('H:i:s'));
                // Para a query SQL, as horas precisam estar no formato H:i:s.
                // Se o fim é no dia seguinte, a data ainda é a mesma na tabela, mas o intervalo de tempo cruza a meia-noite.
                // A lógica de verificação de sobreposição de intervalos precisa ser cuidadosa.

                // Lógica de verificação de sobreposição mais robusta (iterando sobre conflitos potenciais):
                $sql_conflitos_potenciais = "SELECT hora_inicio_prevista, hora_fim_prevista FROM {$tabela_escala_db_name}
                                            WHERE veiculo_id = :veiculo_id_c AND data = :data_c AND id != :escala_id_c";
                $stmt_potenciais = $pdo->prepare($sql_conflitos_potenciais);
                $stmt_potenciais->bindParam(':veiculo_id_c', $candidato['id'], PDO::PARAM_INT);
                $stmt_potenciais->bindParam(':data_c', $data_escala_str, PDO::PARAM_STR);
                $stmt_potenciais->bindParam(':escala_id_c', $escala_id_atual_ajax, PDO::PARAM_INT);
                $stmt_potenciais->execute();
                $conflitos = $stmt_potenciais->fetchAll(PDO::FETCH_ASSOC);

                foreach($conflitos as $conflito_existente) {
                    if($conflito_existente['hora_inicio_prevista'] && $conflito_existente['hora_fim_prevista']) {
                        $inicio_existente_dt = new DateTime($data_escala_str . ' ' . $conflito_existente['hora_inicio_prevista']);
                        $fim_existente_dt = new DateTime($data_escala_str . ' ' . $conflito_existente['hora_fim_prevista']);
                        if($fim_existente_dt <= $inicio_existente_dt) $fim_existente_dt->modify('+1 day');

                        if ($inicio_req_dt_obj < $fim_existente_dt && $fim_req_dt_obj > $inicio_existente_dt) {
                            $veiculo_esta_livre = false;
                            break; // Encontrou conflito, não precisa checar mais para este veículo
                        }
                    }
                }
                
                if ($veiculo_esta_livre) {
                    $veiculos_disponiveis[] = [
                        'id' => $candidato['id'],
                        'text' => htmlspecialchars($candidato['prefixo'] . ($linha_id ? ' (' . $candidato['tipo'] . ')' : ''))
                        // Só mostra o tipo se a busca foi para uma linha específica, senão pode poluir muito
                    ];
                }
            }
        }

        if (!empty($veiculos_disponiveis)) {
            $response['success'] = true;
            $response['message'] = 'Veículos disponíveis carregados.';
            $response['veiculos'] = $veiculos_disponiveis;
        } else {
            $response['success'] = true; // Sucesso na operação, mas nenhum veículo disponível
            $response['message'] = 'Nenhum veículo compatível e livre encontrado para esta linha/horário.';
        }

    } catch (PDOException $e) {
        error_log("Erro AJAX ao buscar veículos disponíveis: " . $e->getMessage());
        $response['message'] = 'Erro no servidor ao buscar veículos. (Ref: PDO-AJAX-VDISP)';
    }
} else {
    $response['message'] = 'Erro de conexão com o banco de dados. (AJAX-VDISP)';
}

echo json_encode($response);
?>