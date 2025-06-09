<?php
// admin/relatorios/escalas_gerar.php (v2.3 - Completo, com correção HY093 e capitalização)

require_once dirname(__DIR__) . '/auth_check.php'; 
require_once dirname(dirname(__DIR__)) . '/db_config.php'; 

$niveis_permitidos_gerar_rel_escalas = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerar_rel_escalas)) {
    if (isset($_POST['modo_exibicao']) && $_POST['modo_exibicao'] === 'html') {
        echo '<div class="alert alert-danger m-3">Acesso negado para gerar este tipo de relatório.</div>';
    } else { die("Acesso negado."); }
    exit;
}

function calcularDuracaoSegundos($data_base_str, $hora_inicio_str, $hora_fim_str) {
    if (empty($hora_inicio_str) || empty($hora_fim_str)) return 0;
    try {
        $inicio = new DateTime($data_base_str . ' ' . $hora_inicio_str);
        $fim = new DateTime($data_base_str . ' ' . $hora_fim_str);
        if ($fim <= $inicio) { $fim->modify('+1 day'); }
        $intervalo = $inicio->diff($fim);
        return ($intervalo->days * 24 * 3600) + ($intervalo->h * 3600) + ($intervalo->i * 60) + $intervalo->s;
    } catch (Exception $e) { 
        error_log("Erro calcularDuracaoSegundos: Data " . $data_base_str . ", Início " . $hora_inicio_str . ", Fim " . $hora_fim_str . " - " . $e->getMessage());
        return 0; 
    }
}
function formatarSegundosParaHHMM($total_segundos) {
    if (!is_numeric($total_segundos) || $total_segundos < 0) $total_segundos = 0; 
    $horas = floor($total_segundos / 3600);
    $minutos = floor(($total_segundos % 3600) / 60);
    return sprintf('%02d:%02d', $horas, $minutos);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['tipo_relatorio_escala'])) {
    $tipo_relatorio_escala = $_POST['tipo_relatorio_escala'];
    $modo_exibicao = $_POST['modo_exibicao'] ?? 'download_csv'; 

    $filtro_data_inicio = $_POST['filtro_data_inicio'] ?? '';
    $filtro_data_fim = $_POST['filtro_data_fim'] ?? '';
    $filtro_motorista_id = !empty($_POST['filtro_motorista_id']) ? (int)$_POST['filtro_motorista_id'] : null;
    $filtro_tipo_escala_fonte = $_POST['filtro_tipo_escala_fonte'] ?? 'ambas';
    
    $filtro_tipos_ocorrencia_raw = $_POST['filtro_tipo_ocorrencia'] ?? [];
    $filtro_tipos_ocorrencia_processados = [];
    if (is_string($filtro_tipos_ocorrencia_raw) && !empty($filtro_tipos_ocorrencia_raw)) {
        $filtro_tipos_ocorrencia_processados = explode(',', $filtro_tipos_ocorrencia_raw);
    } elseif (is_array($filtro_tipos_ocorrencia_raw)) {
        $filtro_tipos_ocorrencia_processados = $filtro_tipos_ocorrencia_raw;
    }
    $filtro_tipos_ocorrencia_processados = array_map('trim', array_map('strtoupper', $filtro_tipos_ocorrencia_processados));
    $filtro_tipos_ocorrencia_processados = array_filter($filtro_tipos_ocorrencia_processados);

    // Mapeamento para exibição correta dos tipos de ocorrência (Chave = Valor DB)
    $map_ocorrencia_db_para_display = [
        'FOLGA'        => 'Folga', 
        'FALTA'        => 'Falta', 
        'ATESTADO'     => 'Atestado', 
        'FÉRIAS'       => 'Férias',
        'FORADEESCALA' => 'Fora de Escala'
    ];

    if (!$pdo) { /* ... erro conexão ... */ exit; }
    $html_output = "";
    $nome_arquivo_base_relatorio = "rel_escalas_";

    if (empty($filtro_data_inicio) || empty($filtro_data_fim)) { /* ... erro datas ... */ exit; }
    try { /* ... validação de datas ... */ } catch (Exception $e) { /* ... erro data inválida ... */ exit; }

    // =========================================================================
    // RELATÓRIO: TOTAL DE HORAS TRABALHADAS POR FUNCIONÁRIO
    // =========================================================================
    if ($tipo_relatorio_escala === 'total_horas_trabalhadas') {
        $nome_arquivo_base_relatorio = "rel_total_horas_trabalhadas_";
        try {
            $dados_agregados_funcionarios = [];
            $status_a_ignorar_horas = ['FOLGA', 'FALTA', 'FORADEESCALA', 'FÉRIAS', 'ATESTADO'];

            $fontes_a_consultar_horas = [];
            if ($filtro_tipo_escala_fonte === 'planejada' || $filtro_tipo_escala_fonte === 'ambas') {
                $fontes_a_consultar_horas['Planejada'] = ['tabela' => 'motorista_escalas', 'alias' => 'e'];
            }
            if ($filtro_tipo_escala_fonte === 'diaria' || $filtro_tipo_escala_fonte === 'ambas') {
                 $fontes_a_consultar_horas['Diária'] = ['tabela' => 'motorista_escalas_diaria', 'alias' => 'ed'];
            }

            // Preparar placeholders nomeados para a cláusula NOT IN
            $placeholders_ignorar_named_array = [];
            $params_ignorar_values = [];
            if (!empty($status_a_ignorar_horas)) {
                $idx_ign = 0;
                foreach ($status_a_ignorar_horas as $status_ign) {
                    $key_ph_ign = ":status_ign_" . $idx_ign; // Placeholders nomeados
                    $placeholders_ignorar_named_array[] = $key_ph_ign;
                    $params_ignorar_values[$key_ph_ign] = strtoupper($status_ign);
                    $idx_ign++;
                }
            }
            $not_in_clause_sql_ignorar = "";
            if (!empty($placeholders_ignorar_named_array)){
                $not_in_clause_sql_ignorar = implode(',', $placeholders_ignorar_named_array);
            }

            foreach ($fontes_a_consultar_horas as $config_fonte_horas) {
                $sql_horas = "SELECT m.id as motorista_id, m.nome as nome_motorista, m.matricula, 
                                     {$config_fonte_horas['alias']}.data, 
                                     {$config_fonte_horas['alias']}.hora_inicio_prevista, 
                                     {$config_fonte_horas['alias']}.hora_fim_prevista, 
                                     {$config_fonte_horas['alias']}.eh_extra, 
                                     {$config_fonte_horas['alias']}.work_id,
                                     {$config_fonte_horas['alias']}.funcao_operacional_id
                              FROM {$config_fonte_horas['tabela']} {$config_fonte_horas['alias']}
                              JOIN motoristas m ON {$config_fonte_horas['alias']}.motorista_id = m.id
                              WHERE {$config_fonte_horas['alias']}.data BETWEEN :data_inicio AND :data_fim";
                
                $params_horas_exec = [':data_inicio' => $filtro_data_inicio, ':data_fim' => $filtro_data_fim];

                if ($filtro_motorista_id) {
                    $sql_horas .= " AND m.id = :motorista_id";
                    $params_horas_exec[':motorista_id'] = $filtro_motorista_id;
                }
                
                if (!empty($not_in_clause_sql_ignorar)) {
                    $sql_horas .= " AND UPPER({$config_fonte_horas['alias']}.work_id) NOT IN ({$not_in_clause_sql_ignorar})";
                }
                
                $sql_horas .= " AND ({$config_fonte_horas['alias']}.funcao_operacional_id IS NULL OR 
                                   (SELECT fo.ignorar_validacao_jornada FROM funcoes_operacionais fo WHERE fo.id = {$config_fonte_horas['alias']}.funcao_operacional_id) = 0)";

                $stmt_horas = $pdo->prepare($sql_horas);
                // Todos os parâmetros são nomeados agora
                $execute_params_final_horas = array_merge($params_horas_exec, $params_ignorar_values);
                $stmt_horas->execute($execute_params_final_horas);
                
                while ($escala = $stmt_horas->fetch(PDO::FETCH_ASSOC)) {
                    if ($escala['hora_inicio_prevista'] && $escala['hora_fim_prevista']) {
                        $duracao_segundos = calcularDuracaoSegundos($escala['data'], $escala['hora_inicio_prevista'], $escala['hora_fim_prevista']);
                        $motorista_id_atual = $escala['motorista_id'];
                        if (!isset($dados_agregados_funcionarios[$motorista_id_atual])) {
                            $dados_agregados_funcionarios[$motorista_id_atual] = [
                                'nome' => $escala['nome_motorista'], 'matricula' => $escala['matricula'],
                                'total_segundos_normais' => 0, 'total_segundos_extras' => 0
                            ];
                        }
                        if ($escala['eh_extra'] == 1) {
                            $dados_agregados_funcionarios[$motorista_id_atual]['total_segundos_extras'] += $duracao_segundos;
                        } else {
                            $dados_agregados_funcionarios[$motorista_id_atual]['total_segundos_normais'] += $duracao_segundos;
                        }
                    }
                }
            }
            
            uasort($dados_agregados_funcionarios, function($a, $b) { return strcmp($a['nome'], $b['nome']); });

            // Geração de HTML para Total de Horas
            if ($modo_exibicao === 'html') {
                if (!empty($dados_agregados_funcionarios)) {
                    $html_output .= "<div class='table-responsive mt-3'><table class='table table-sm table-striped table-hover table-bordered'>";
                    $html_output .= "<thead class='thead-light'><tr><th>Matrícula</th><th>Nome</th><th class='text-right'>Horas Normais</th><th class='text-right'>Horas Extras</th><th class='text-right'>Total Horas</th></tr></thead><tbody>";
                    foreach ($dados_agregados_funcionarios as $dados_func) {
                        $total_geral_segundos = $dados_func['total_segundos_normais'] + $dados_func['total_segundos_extras'];
                        $html_output .= "<tr>";
                        $html_output .= "<td>" . htmlspecialchars($dados_func['matricula']) . "</td>";
                        $html_output .= "<td>" . htmlspecialchars($dados_func['nome']) . "</td>";
                        $html_output .= "<td class='text-right'>" . formatarSegundosParaHHMM($dados_func['total_segundos_normais']) . "</td>";
                        $html_output .= "<td class='text-right'>" . formatarSegundosParaHHMM($dados_func['total_segundos_extras']) . "</td>";
                        $html_output .= "<td class='text-right'><strong>" . formatarSegundosParaHHMM($total_geral_segundos) . "</strong></td>";
                        $html_output .= "</tr>";
                    }
                    $html_output .= "</tbody></table></div>";
                } else {
                    $html_output = "<p class='text-info mt-2'>Nenhuma hora trabalhada encontrada para os filtros selecionados.</p>";
                }
                echo $html_output;
            } 
            // Geração de CSV para Total de Horas
            elseif ($modo_exibicao === 'download_csv') {
                $filename = $nome_arquivo_base_relatorio . date('Ymd_His') . ".csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                $output_csv = fopen('php://output', 'w');
                fputcsv($output_csv, ['Matrícula', 'Nome', 'Horas Normais (HH:MM)', 'Horas Extras (HH:MM)', 'Total Horas (HH:MM)']);
                if (!empty($dados_agregados_funcionarios)) {
                    foreach ($dados_agregados_funcionarios as $dados_func) {
                        fputcsv($output_csv, [
                            $dados_func['matricula'], $dados_func['nome'],
                            formatarSegundosParaHHMM($dados_func['total_segundos_normais']),
                            formatarSegundosParaHHMM($dados_func['total_segundos_extras']),
                            formatarSegundosParaHHMM($dados_func['total_segundos_normais'] + $dados_func['total_segundos_extras'])
                        ]);
                    }
                } else { fputcsv($output_csv, ['Nenhum dado encontrado.']);}
                fclose($output_csv);
            } elseif ($modo_exibicao === 'download_pdf_simples') { 
                echo "PDF para Total de Horas Trabalhadas não implementado ainda.";
            }
            exit;

        } catch (PDOException $e) { 
            error_log("Erro ao gerar relatório de total de horas: " . $e->getMessage() . (isset($sql_horas) ? " SQL: " . $sql_horas : "") . " Params: " . json_encode($execute_params_final_horas ?? []));
            $error_msg_user_horas = "Erro ao gerar relatório de horas.";
            if ($modo_exibicao === 'html') { echo '<div class="alert alert-danger m-3">' . htmlspecialchars($error_msg_user_horas) . ' Consulte o log (' . $e->getCode() .').</div>'; }
            else { die($error_msg_user_horas); }
            exit;
         }
    } 
    // =========================================================================
    // RELATÓRIO DE OCORRÊNCIAS NA ESCALA
    // =========================================================================
    elseif ($tipo_relatorio_escala === 'ocorrencias_escala') {
        $nome_arquivo_base_relatorio = "rel_ocorrencias_escala_";
        try {
            $ocorrencias_encontradas = [];
            $tipos_ocorrencia_sql_padrao = ['FOLGA', 'FALTA', 'ATESTADO', 'FÉRIAS', 'FORADEESCALA']; 
            $tipos_ocorrencia_para_query = !empty($filtro_tipos_ocorrencia_processados) ? $filtro_tipos_ocorrencia_processados : $tipos_ocorrencia_sql_padrao;
            
            if (empty($tipos_ocorrencia_para_query)){ // Segurança
                 $tipos_ocorrencia_para_query = $tipos_ocorrencia_sql_padrao;
            }

            $placeholders_ocor_named_array = [];
            $params_in_ocor_values = [];
            if (!empty($tipos_ocorrencia_para_query)) {
                $idx_ocor = 0;
                foreach ($tipos_ocorrencia_para_query as $tipo_occ) {
                    $key_ph = ":ocor_ph_" . $idx_ocor;
                    $placeholders_ocor_named_array[] = $key_ph;
                    $params_in_ocor_values[$key_ph] = strtoupper($tipo_occ);
                    $idx_ocor++;
                }
            }
            $in_clause_sql_ocor = "";
            if(!empty($placeholders_ocor_named_array)){
                $in_clause_sql_ocor = implode(',', $placeholders_ocor_named_array);
            }

            if (empty($in_clause_sql_ocor)) { /* ... Tratar caso de nenhum tipo de ocorrência ... */ exit; }

            $sql_ocorrencias_template = "SELECT 
                                        {alias}.data as data_ocorrencia, 
                                        m.matricula, 
                                        m.nome as nome_motorista, 
                                        UPPER({alias}.work_id) as tipo_ocorrencia_db, 
                                        '%s' as fonte_escala, 
                                        {obs_col} as observacoes
                                     FROM {tabela} {alias}
                                     JOIN motoristas m ON {alias}.motorista_id = m.id
                                     WHERE {alias}.data BETWEEN :data_inicio AND :data_fim
                                       AND UPPER({alias}.work_id) IN (" . $in_clause_sql_ocor . ")";
            
            $params_base_ocorrencias = [':data_inicio' => $filtro_data_inicio, ':data_fim' => $filtro_data_fim];
            
            $fontes_a_consultar_ocor = [];
            if ($filtro_tipo_escala_fonte === 'planejada' || $filtro_tipo_escala_fonte === 'ambas') {
                $fontes_a_consultar_ocor['Planejada'] = ['tabela' => 'motorista_escalas', 'alias' => 'esc_p', 'obs_col' => "NULL"];
            }
            if ($filtro_tipo_escala_fonte === 'diaria' || $filtro_tipo_escala_fonte === 'ambas') {
                $fontes_a_consultar_ocor['Diária'] = ['tabela' => 'motorista_escalas_diaria', 'alias' => 'esc_d', 'obs_col' => 'esc_d.observacoes_ajuste'];
            }

            foreach ($fontes_a_consultar_ocor as $nome_fonte_str => $config_fonte) {
                $sql_final_ocorrencias = str_replace(
                    ['{alias}', '{tabela}', '{obs_col}'],
                    [$config_fonte['alias'], $config_fonte['tabela'], $config_fonte['obs_col']],
                    $sql_ocorrencias_template
                );
                $sql_final_ocorrencias = sprintf($sql_final_ocorrencias, $nome_fonte_str);
                
                $current_params_exec_ocor = array_merge($params_base_ocorrencias, $params_in_ocor_values);
                if ($filtro_motorista_id) {
                    $sql_final_ocorrencias .= " AND m.id = :motorista_id";
                    $current_params_exec_ocor[':motorista_id'] = $filtro_motorista_id;
                }
                $sql_final_ocorrencias .= " ORDER BY {$config_fonte['alias']}.data ASC, m.nome ASC";
                
                $stmt_ocorrencias = $pdo->prepare($sql_final_ocorrencias);
                $stmt_ocorrencias->execute($current_params_exec_ocor);
                $resultados_fonte = $stmt_ocorrencias->fetchAll(PDO::FETCH_ASSOC);
                $ocorrencias_encontradas = array_merge($ocorrencias_encontradas, $resultados_fonte);
            }

            if (count($fontes_a_consultar_ocor) > 1 && !empty($ocorrencias_encontradas)) {
                usort($ocorrencias_encontradas, function($a, $b) { 
                    $cmp_data = strcmp($a['data_ocorrencia'], $b['data_ocorrencia']);
                    if ($cmp_data == 0) { return strcmp($a['nome_motorista'], $b['nome_motorista']); }
                    return $cmp_data;
                });
            }
            
            if ($modo_exibicao === 'html') {
                if (!empty($ocorrencias_encontradas)) {
                    $html_output .= "<div class='table-responsive mt-3'><table class='table table-sm table-striped table-hover table-bordered'>";
                    $html_output .= "<thead class='thead-light'><tr><th>Data</th><th>Matrícula</th><th>Nome</th><th>Tipo de Ocorrência</th><th>Fonte</th><th>Observações</th></tr></thead><tbody>";
                    foreach ($ocorrencias_encontradas as $ocor) {
                        $html_output .= "<tr>";
                        $html_output .= "<td>" . date('d/m/Y', strtotime($ocor['data_ocorrencia'])) . "</td>";
                        $html_output .= "<td>" . htmlspecialchars($ocor['matricula']) . "</td>";
                        $html_output .= "<td>" . htmlspecialchars($ocor['nome_motorista']) . "</td>";
                        $tipo_ocor_db_val = strtoupper($ocor['tipo_ocorrencia_db']);
                        $tipo_ocor_display = $map_ocorrencia_db_para_display[strtoupper($tipo_ocor_db_val)] ?? ucfirst(strtolower(str_replace("_", " ", $tipo_ocor_db_val)));
                        $html_output .= "<td>" . htmlspecialchars($tipo_ocor_display) . "</td>";
                        $html_output .= "<td>" . htmlspecialchars($ocor['fonte_escala']) . "</td>";
                        $html_output .= "<td>" . htmlspecialchars($ocor['observacoes'] ?: '-') . "</td>";
                        $html_output .= "</tr>";
                    }
                    $html_output .= "</tbody></table></div>";
                } else {
                    $html_output = "<p class='text-info mt-2'>Nenhuma ocorrência encontrada para os filtros selecionados.</p>";
                }
                echo $html_output;
            } elseif ($modo_exibicao === 'download_csv') {
                $filename = $nome_arquivo_base_relatorio . date('Ymd_His') . ".csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                $output_csv_ocor = fopen('php://output', 'w');
                fputcsv($output_csv_ocor, ['Data', 'Matrícula', 'Nome', 'Tipo de Ocorrência', 'Fonte da Escala', 'Observações']);
                if (!empty($ocorrencias_encontradas)) {
                    foreach ($ocorrencias_encontradas as $ocor) {
                        $tipo_ocor_db_val_csv = strtoupper($ocor['tipo_ocorrencia_db']);
                        $tipo_ocor_display_csv = $map_ocorrencia_db_para_display[strtoupper($tipo_ocor_db_val_csv)] ?? ucfirst(strtolower(str_replace("_", " ", $tipo_ocor_db_val_csv)));
                        fputcsv($output_csv_ocor, [
                            date('d/m/Y', strtotime($ocor['data_ocorrencia'])),
                            $ocor['matricula'], $ocor['nome_motorista'],
                            $tipo_ocor_display_csv,
                            $ocor['fonte_escala'], $ocor['observacoes'] ?: ''
                        ]);
                    }
                } else { fputcsv($output_csv_ocor, ['Nenhuma ocorrência encontrada.']); }
                fclose($output_csv_ocor);
            } elseif ($modo_exibicao === 'download_pdf_simples') { 
                echo "PDF para Relatório de Ocorrências não implementado ainda.";
            }
            exit;

        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de ocorrências: " . $e->getMessage() . (isset($sql_final_ocorrencias) ? " SQL: " . $sql_final_ocorrencias : "") . " Params: " . json_encode($current_params_exec_ocor ?? []));
            $error_msg_user_ocor = "Erro ao gerar relatório de ocorrências.";
            if ($modo_exibicao === 'html') { echo '<div class="alert alert-danger m-3">' . htmlspecialchars($error_msg_user_ocor) . ' Consulte o log (' . $e->getCode() .').</div>'; }
            else { die($error_msg_user_ocor); }
            exit;
        }
    }
    else {
        $unknown_report_msg_escala = "Tipo de relatório de escala desconhecido ou não implementado.";
        if ($modo_exibicao === 'html') { echo '<div class="alert alert-warning m-3">'.htmlspecialchars($unknown_report_msg_escala).'</div>'; }
        else { die($unknown_report_msg_escala); }
        exit;
    }
} else {
    $_SESSION['admin_feedback'] = ['type' => 'error', 'message' => 'Requisição inválida para gerar relatório de escalas.'];
    header('Location: escalas_filtros.php'); 
    exit;
}
?>