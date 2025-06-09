<?php
// admin/relatorios/funcionarios_gerar.php
// v13 - Utiliza lista predefinida de cargos para garantir que todos apareçam na contagem.

require_once dirname(__DIR__) . '/auth_check.php'; 
require_once dirname(dirname(__DIR__)) . '/db_config.php'; 

// Níveis de acesso
$niveis_permitidos_gerar_rel_func = ['CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerar_rel_func)) {
    if (isset($_POST['modo_exibicao']) && $_POST['modo_exibicao'] === 'html') {
        echo '<div class="alert alert-danger m-3">Acesso negado para gerar este tipo de relatório.</div>';
    } else { 
        die("Acesso negado.");
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['tipo_relatorio_especifico'])) {
    $tipo_relatorio_especifico = $_POST['tipo_relatorio_especifico'];
    $modo_exibicao = $_POST['modo_exibicao'] ?? 'download_csv'; 

    $filtro_cargo_rel = $_POST['filtro_cargo'] ?? '';
    $filtro_status_rel = $_POST['filtro_status'] ?? '';
    $filtro_data_contratacao_de_rel = $_POST['filtro_data_contratacao_de'] ?? '';
    $filtro_data_contratacao_ate_rel = $_POST['filtro_data_contratacao_ate'] ?? '';

    if (!$pdo) { 
        $error_msg = "Erro de conexão com o banco de dados.";
        if ($modo_exibicao === 'html') { echo '<div class="alert alert-danger m-3">' . htmlspecialchars($error_msg) . '</div>'; }
        else { die($error_msg); }
        exit;
    }

    $html_output = "";
    $nome_arquivo_base = "rel_funcionarios_"; 

    // Lista de todos os cargos possíveis que você quer que apareçam no relatório de contagem.
    // Esta lista é a "fonte da verdade" para os cargos a serem exibidos quando "Todos os Cargos" é selecionado.
    // MANTENHA ESTA LISTA ATUALIZADA COM TODOS OS CARGOS QUE SEU SISTEMA UTILIZA.
    $todos_os_cargos_sistema = ['Motorista', 'Agente de Terminal', 'Catraca', 'CIOP Monitoramento', 'CIOP Planejamento', 'Instrutor', 'Porteiro', 'Soltura'];
    // Você pode, alternativamente, buscar os cargos do banco e mesclar com esta lista para garantir
    // que cargos novos no banco também apareçam, mas usar uma lista fixa aqui garante a ordem e a presença
    // mesmo de cargos que temporariamente não tenham nenhum funcionário.
    // Exemplo para mesclar (opcional):
    // $stmt_cargos_db_temp = $pdo->query("SELECT DISTINCT cargo FROM motoristas WHERE cargo IS NOT NULL AND cargo != ''");
    // $cargos_no_db_temp = $stmt_cargos_db_temp->fetchAll(PDO::FETCH_COLUMN);
    // $todos_os_cargos_sistema = array_values(array_unique(array_merge($todos_os_cargos_sistema, $cargos_no_db_temp)));
    // sort($todos_os_cargos_sistema);


    // =========================================================================
    // Sub-Relatório: CONTAGEM DE FUNCIONÁRIOS POR STATUS E CARGO
    // =========================================================================
    if ($tipo_relatorio_especifico === 'contagem_funcionarios_status_cargo') {
        $nome_arquivo_base = "rel_contagem_funcionarios_";
        try {
            $resultados_para_exibicao = []; 
            $status_possiveis = ['ativo', 'inativo'];

            // 1. Determinar a lista de cargos para o relatório com base no filtro.
            $cargos_efetivos_para_relatorio = [];
            if (empty($filtro_cargo_rel)) { // "Todos os Cargos" foi selecionado no formulário
                $cargos_efetivos_para_relatorio = $todos_os_cargos_sistema; // Usa a lista completa predefinida
            } else { // Um cargo específico foi selecionado
                $cargos_efetivos_para_relatorio = [$filtro_cargo_rel];
            }
            
            // 2. Inicializar resultados com 0 para todas as combinações de cargo/status a serem exibidas.
            foreach ($cargos_efetivos_para_relatorio as $cargo_iter) {
                foreach ($status_possiveis as $status_iter) {
                    if (empty($filtro_status_rel) || $filtro_status_rel === $status_iter) {
                        $resultados_para_exibicao[$cargo_iter . '_' . $status_iter] = [
                            'cargo' => $cargo_iter,
                            'status' => $status_iter,
                            'total' => 0
                        ];
                    }
                }
            }

            // 3. Query para buscar as contagens REAIS do banco.
            $sql_contagem_real = "SELECT cargo, status, COUNT(*) as total_real FROM motoristas ";
            $where_contagem_real_parts = [];
            $params_contagem_real = [];

            // Aplica filtro de CARGO à query de contagem SOMENTE se um cargo específico foi fornecido.
            if (!empty($filtro_cargo_rel)) {
                $where_contagem_real_parts[] = "cargo = :p_cargo_q";
                $params_contagem_real[':p_cargo_q'] = $filtro_cargo_rel;
            }
            // Se $filtro_cargo_rel estiver vazio (Todos os Cargos), nenhum filtro de cargo é adicionado à query.
            
            if (!empty($filtro_status_rel)) {
                $where_contagem_real_parts[] = "status = :p_status_q";
                $params_contagem_real[':p_status_q'] = $filtro_status_rel;
            }
            $where_contagem_real_parts[] = "cargo IS NOT NULL AND cargo != ''";


            if (!empty($where_contagem_real_parts)) {
                $sql_contagem_real .= " WHERE " . implode(" AND ", $where_contagem_real_parts);
            }
            $sql_contagem_real .= " GROUP BY cargo, status ORDER BY cargo, status";

            $stmt_reais_exec = $pdo->prepare($sql_contagem_real);
            $stmt_reais_exec->execute($params_contagem_real);
            $dados_reais_do_banco = $stmt_reais_exec->fetchAll(PDO::FETCH_ASSOC);

            // 4. Atualizar a estrutura $resultados_para_exibicao com as contagens reais.
            foreach ($dados_reais_do_banco as $dado_real_db_item) {
                $chave = $dado_real_db_item['cargo'] . '_' . $dado_real_db_item['status'];
                // Só atualiza se a combinação cargo/status está no nosso array de exibição.
                if (isset($resultados_para_exibicao[$chave])) {
                    $resultados_para_exibicao[$chave]['total'] = $dado_real_db_item['total_real'];
                }
            }
            
            // Ordenar o array final para exibição consistente.
             uasort($resultados_para_exibicao, function($a, $b) {
                // Primeiro, tenta ordenar pela ordem da lista $todos_os_cargos_sistema
                // Se não estiver usando $todos_os_cargos_sistema para ordenação, a ordenação alfabética é um bom fallback.
                global $todos_os_cargos_sistema; // Torna a array externa acessível
                $pos_a = array_search($a['cargo'], $todos_os_cargos_sistema);
                $pos_b = array_search($b['cargo'], $todos_os_cargos_sistema);

                if ($pos_a !== false && $pos_b !== false && $pos_a !== $pos_b) {
                    return $pos_a - $pos_b;
                } elseif ($pos_a !== false && $pos_b === false) {
                    return -1; // $a vem antes se $b não está na lista
                } elseif ($pos_a === false && $pos_b !== false) {
                    return 1;  // $b vem antes se $a não está na lista
                }
                // Se ambos não estão na lista ou são o mesmo cargo, ordena alfabeticamente
                $cmp_cargo = strcmp($a['cargo'], $b['cargo']);
                if ($cmp_cargo == 0) { return strcmp($a['status'], $b['status']); }
                return $cmp_cargo;
            });

            // Geração da Saída
            if ($modo_exibicao === 'html') {
                if (!empty($resultados_para_exibicao)) {
                    $html_output .= "<div class='table-responsive mt-3'><table class='table table-sm table-striped table-hover table-bordered'>";
                    $html_output .= "<thead class='thead-light'><tr><th>Cargo</th><th>Status</th><th class='text-right'>Total</th></tr></thead><tbody>";
                    foreach ($resultados_para_exibicao as $linha_final_data) {
                        $html_output .= "<tr><td>" . htmlspecialchars($linha_final_data['cargo']) . "</td><td>" . htmlspecialchars(ucfirst($linha_final_data['status'])) . "</td><td class='text-right'>" . htmlspecialchars($linha_final_data['total']) . "</td></tr>";
                    }
                    $html_output .= "</tbody></table></div>";
                } else {
                    $html_output = "<p class='text-info mt-2'>Nenhum dado de contagem para exibir com os filtros atuais (verifique se há funcionários cadastrados com os cargos/status desejados ou se os cargos existem na base de dados).</p>";
                }
                echo $html_output;

            } elseif ($modo_exibicao === 'download_csv') {
                $filename = $nome_arquivo_base . date('Ymd_His') . ".csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                $output_csv = fopen('php://output', 'w');
                fputcsv($output_csv, ['Cargo', 'Status', 'Total']);
                if (!empty($resultados_para_exibicao)) {
                    foreach ($resultados_para_exibicao as $linha_final_data) {
                        fputcsv($output_csv, [$linha_final_data['cargo'], ucfirst($linha_final_data['status']), $linha_final_data['total']]);
                    }
                } else {
                    fputcsv($output_csv, ['Nenhum dado encontrado.', '', '']);
                }
                fclose($output_csv);
            } elseif ($modo_exibicao === 'download_pdf_simples') { 
                echo "Gerador de PDF para Contagem de Funcionários ainda não implementado."; // Placeholder
            }
            exit;

        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de contagem de funcionários: " . $e->getMessage());
            $error_message_user = "Erro ao gerar relatório de contagem.";
            if ($modo_exibicao === 'html') { echo '<div class="alert alert-danger m-3">' . htmlspecialchars($error_message_user) . ' Consulte o log.</div>'; }
            else { die($error_message_user); }
            exit;
        }
    } 
    // =========================================================================
    // Sub-Relatório: LISTA DETALHADA DE FUNCIONÁRIOS 
    // =========================================================================
    elseif ($tipo_relatorio_especifico === 'lista_funcionarios_detalhada') {
        $nome_arquivo_base = "rel_lista_funcionarios_detalhada_";
        try {
            $sql_base_lista = "SELECT nome, matricula, cargo, status, data_contratacao, tipo_veiculo, email, telefone FROM motoristas ";
            $where_parts_lista = []; 
            $params_lista = [];
            
            if (!empty($filtro_cargo_rel)) {
                 $where_parts_lista[] = "cargo = :param_cargo_lista"; 
                 $params_lista[':param_cargo_lista'] = $filtro_cargo_rel; 
            }
            if (!empty($filtro_status_rel)) { $where_parts_lista[] = "status = :param_status_lista"; $params_lista[':param_status_lista'] = $filtro_status_rel; }
            if (!empty($filtro_data_contratacao_de_rel)) { $where_parts_lista[] = "data_contratacao >= :param_data_de_lista"; $params_lista[':param_data_de_lista'] = $filtro_data_contratacao_de_rel; }
            if (!empty($filtro_data_contratacao_ate_rel)) { $where_parts_lista[] = "data_contratacao <= :param_data_ate_lista"; $params_lista[':param_data_ate_lista'] = $filtro_data_contratacao_ate_rel; }
            
            $sql_query_lista = $sql_base_lista;
            if (!empty($where_parts_lista)) { $sql_query_lista .= " WHERE " . implode(" AND ", $where_parts_lista); }
            $sql_query_lista .= " ORDER BY nome ASC";
            
            $stmt_lista = $pdo->prepare($sql_query_lista); 
            $stmt_lista->execute($params_lista);
            $resultados_lista = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);

            if ($modo_exibicao === 'html') {
                if ($resultados_lista) {
                    $html_output .= "<div class='table-responsive mt-3'><table class='table table-sm table-striped table-hover table-bordered'>";
                    $html_output .= "<thead class='thead-light'><tr><th>Nome</th><th>Matrícula</th><th>Cargo</th><th>Status</th><th>Dt. Contratação</th><th>Tipo Veículo</th><th>Email</th><th>Telefone</th></tr></thead><tbody>";
                    foreach ($resultados_lista as $linha_lista) { 
                        $html_output .= "<tr><td>" . htmlspecialchars($linha_lista['nome']) . "</td><td>" . htmlspecialchars($linha_lista['matricula']) . "</td><td>" . htmlspecialchars($linha_lista['cargo']) . "</td><td>" . htmlspecialchars(ucfirst($linha_lista['status'])) . "</td><td>" . ($linha_lista['data_contratacao'] ? date('d/m/Y', strtotime($linha_lista['data_contratacao'])) : '-') . "</td><td>" . htmlspecialchars($linha_lista['tipo_veiculo'] ?: '-') . "</td><td>" . htmlspecialchars($linha_lista['email'] ?: '-') . "</td><td>" . htmlspecialchars($linha_lista['telefone'] ?: '-') . "</td></tr>";
                     }
                    $html_output .= "</tbody></table></div>";
                } else { 
                    $html_output = "<p class='text-info mt-2'>Nenhum funcionário encontrado para os filtros selecionados.</p>"; 
                }
                echo $html_output;
            } elseif ($modo_exibicao === 'download_csv') {
                $filename = $nome_arquivo_base . date('Ymd_His') . ".csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                $output_csv_lista = fopen('php://output', 'w');
                fputcsv($output_csv_lista, ['Nome', 'Matrícula', 'Cargo', 'Status', 'Data Contratação', 'Tipo Veículo', 'Email', 'Telefone']);
                if ($resultados_lista) { 
                    foreach ($resultados_lista as $linha_lista) { 
                        fputcsv($output_csv_lista, [
                            $linha_lista['nome'], $linha_lista['matricula'], $linha_lista['cargo'], 
                            ucfirst($linha_lista['status']), 
                            ($linha_lista['data_contratacao'] ? date('d/m/Y', strtotime($linha_lista['data_contratacao'])) : ''), 
                            $linha_lista['tipo_veiculo'], $linha_lista['email'], $linha_lista['telefone']
                        ]); 
                    }
                } else { 
                    fputcsv($output_csv_lista, ['Nenhum funcionário encontrado.', '', '', '', '', '', '', '']); 
                }
                fclose($output_csv_lista);
            } elseif ($modo_exibicao === 'download_pdf_simples') {
                 echo "PDF para Lista Detalhada de Funcionários não implementado ainda.";
            }
            exit;
        } catch (PDOException $e) {
            error_log("Erro ao gerar Lista Detalhada Funcionários: " . $e->getMessage());
            $error_message_user_lista = "Erro ao gerar relatório de lista detalhada.";
            if ($modo_exibicao === 'html') { echo '<div class="alert alert-danger m-3">' . htmlspecialchars($error_message_user_lista) . ' Consulte o log.</div>'; }
            else { die($error_message_user_lista); }
            exit;
        }
    }
    else {
        $unknown_report_msg = "Tipo de relatório de funcionário desconhecido ou não implementado.";
        if ($modo_exibicao === 'html') { echo '<div class="alert alert-warning m-3">'.htmlspecialchars($unknown_report_msg).'</div>'; }
        else { die($unknown_report_msg); }
        exit;
    }
} else {
    $_SESSION['admin_feedback'] = ['type' => 'error', 'message' => 'Requisição inválida para gerar relatório de funcionários.'];
    header('Location: funcionarios_filtros.php'); 
    exit;
}
?>