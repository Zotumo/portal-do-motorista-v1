<?php
// buscar_horario.php (v15 FINAL - Busca WID por workid_eventos)

require_once 'db_config.php';
$nomes_dias = ['Uteis'=>'Dias Úteis','Sabado'=>'Sábado','DomingoFeriado'=>'Domingos/Feriados'];
$eventos_para_exibir = []; // Variável global para includes

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['tipo_busca']) && isset($_POST['valor'])) {
    $tipo_busca = trim($_POST['tipo_busca']); $valor_busca = trim($_POST['valor']);
    $html_resultado = ""; $erro_busca_geral = false;

    if (empty($valor_busca) || !in_array($tipo_busca, ['linha', 'workid'])) { exit("<p class='text-warning mt-3'>Dados inválidos.</p>"); }
    if ($pdo === null) { exit("<p class='text-danger mt-3'>Erro DB Conn.</p>"); }

    // ==========================================================
    // === BUSCA POR LINHA (Agrupa por Bloco, mostra WID Evento) ===
    // ==========================================================
    if ($tipo_busca == 'linha') {
        $resultados_por_dia_bloco = [];
        try {
            // 1. Encontra Blocos que contêm a linha buscada
            $sql_find_blocos = "SELECT DISTINCT prog.id, prog.dia_semana_tipo, prog.work_id AS work_id_bloco FROM linhas AS l JOIN diario_bordo_eventos AS ev ON l.id = ev.linha_atual_id JOIN programacao_diaria AS prog ON ev.programacao_id = prog.id WHERE l.numero = :valor_busca AND prog.dia_semana_tipo IS NOT NULL ORDER BY FIELD(prog.dia_semana_tipo, 'Uteis', 'Sabado', 'DomingoFeriado'), prog.work_id ASC";
            $stmt_find = $pdo->prepare($sql_find_blocos); $stmt_find->bindParam(':valor_busca', $valor_busca, PDO::PARAM_STR); $stmt_find->execute(); $blocos_encontrados = $stmt_find->fetchAll(PDO::FETCH_ASSOC);

            if ($blocos_encontrados) {
                $ids_programacao = array_column($blocos_encontrados, 'id');
                if (!empty($ids_programacao)) {
                    $placeholders = implode(',', array_fill(0, count($ids_programacao), '?'));
                    // 2. Busca detalhes dos eventos desses Blocos
                    $sql_detalhes = "SELECT ev.sequencia, ev.programacao_id, ev.local_id, ev.linha_atual_id, ev.numero_tabela_evento AS tabela_diario, ev.workid_eventos, l.numero AS numero_linha, l.nome AS nome_linha, l.imagem_ponto_ida_path, l.imagem_ponto_volta_path, prog.dia_semana_tipo, prog.work_id AS work_id_bloco, TIME_FORMAT(ev.horario_chegada, '%H:%i') AS chegada_fmt, TIME_FORMAT(ev.horario_saida, '%H:%i') AS saida_fmt, loc.nome AS nome_local, ev.info, ev.horario_saida FROM diario_bordo_eventos AS ev JOIN programacao_diaria AS prog ON ev.programacao_id = prog.id JOIN linhas AS l ON ev.linha_atual_id = l.id JOIN locais AS loc ON ev.local_id = loc.id WHERE ev.programacao_id IN ($placeholders) ORDER BY prog.id, ev.sequencia ASC";
                    $stmt_detalhes = $pdo->prepare($sql_detalhes); $stmt_detalhes->execute($ids_programacao); $todos_eventos = $stmt_detalhes->fetchAll(PDO::FETCH_ASSOC);
                    // Agrupa por dia e Bloco
                    foreach ($todos_eventos as $evento) { $dia = $evento['dia_semana_tipo']; $bloco_id = $evento['work_id_bloco']; if (!isset($resultados_por_dia_bloco[$dia])) $resultados_por_dia_bloco[$dia] = []; if (!isset($resultados_por_dia_bloco[$dia][$bloco_id])) $resultados_por_dia_bloco[$dia][$bloco_id] = []; $resultados_por_dia_bloco[$dia][$bloco_id][] = $evento; }
                }
            }
        } catch (PDOException $e) { error_log("Erro buscar_horario.php (linha): ".$e->getMessage()); $erro_busca_geral = true; }

        // 3. Montar HTML (Dia -> Bloco -> Detalhes com 3 sub-abas)
        if ($erro_busca_geral) { $html_resultado = "<p class='text-danger mt-3'>Ocorreu um erro (linha).</p>"; }
        elseif (!empty($resultados_por_dia_bloco)) {
            // HTML das abas aninhadas (Dia -> Bloco/WorkID Mestre -> Detalhes Tabela/Rota/Pontos)
             $html_resultado .= '<ul class="nav nav-pills mb-3 mt-3" id="pills-tab-dias" role="tablist">'; $is_first_dia=true; foreach($nomes_dias as $kd=>$nd){if(isset($resultados_por_dia_bloco[$kd])){$html_resultado.='<li class="nav-item"><a class="nav-link '.($is_first_dia?'active':'').'" id="pills-'.$kd.'-tab" data-toggle="pill" href="#pills-'.$kd.'" role="tab">'.$nd.'</a></li>';$is_first_dia=false;}} $html_resultado .= '</ul>';
             $html_resultado .= '<div class="tab-content" id="pills-tabContent-dias">'; $is_first_dia=true; foreach($nomes_dias as $kd=>$nd){if(isset($resultados_por_dia_bloco[$kd])){ $html_resultado.='<div class="tab-pane fade '.($is_first_dia?'show active':'').'" id="pills-'.$kd.'" role="tabpanel">'; $blocos_dia=$resultados_por_dia_bloco[$kd]; $html_resultado.='<ul class="nav nav-tabs mt-2" id="tab-bloco-'.$kd.'" role="tablist">'; $is_first_bloco=true; foreach($blocos_dia as $bid=>$evt_w){$tidn="tab-".$kd."-".preg_replace('/[^a-zA-Z0-9]/','',$bid);$tref=$evt_w[0]['tabela_diario']??'?';$html_resultado.='<li class="nav-item"><a class="nav-link '.($is_first_bloco?'active':'').'" id="'.$tidn.'-tab" data-toggle="tab" href="#'.$tidn.'-content" role="tab">Tabela '.htmlspecialchars($bid).'</a></li>';$is_first_bloco=false;} $html_resultado.='</ul>'; $html_resultado.='<div class="tab-content mt-3 border p-2 mb-3" id="tabContent-bloco-'.$kd.'">'; $is_first_bloco=true; foreach($blocos_dia as $bid=>$evt_exibir){$tid="tab-".$kd."-".preg_replace('/[^a-zA-Z0-9]/','',$bid);$html_resultado.='<div class="tab-pane fade '.($is_first_bloco?'show active':'').'" id="'.$tid.'-content" role="tabpanel">'; $eventos_para_exibir=$evt_exibir; $html_resultado.='<ul class="nav nav-pills nav-fill mb-3" id="pills-subtab-'.$tid.'" role="tablist">';$html_resultado.='<li class="nav-item"><a class="nav-link active" data-toggle="pill" href="#subtab-tabela-'.$tid.'"><i class="fas fa-list-alt"></i> Diário de Bordo</a></li>';$html_resultado.='<li class="nav-item"><a class="nav-link" data-toggle="pill" href="#subtab-rota-'.$tid.'"><i class="fas fa-route"></i> Rota</a></li>';$html_resultado.='<li class="nav-item"><a class="nav-link" data-toggle="pill" href="#subtab-pontos-'.$tid.'"><i class="fas fa-map-marker-alt"></i> Pontos</a></li>';$html_resultado.='</ul>'; $html_resultado.='<div class="tab-content" id="pills-subtabContent-'.$tid.'">'; $html_resultado.='<div class="tab-pane fade show active" id="subtab-tabela-'.$tid.'" role="tabpanel">'; if(file_exists('parts/display_tabela_detalhada.php')){ob_start();include 'parts/display_tabela_detalhada.php';$html_resultado.=ob_get_clean();}else{$html_resultado.="Erro Tabela";} $html_resultado.='</div>'; $html_resultado.='<div class="tab-pane fade" id="subtab-rota-'.$tid.'" role="tabpanel">'; if(file_exists('parts/display_tracado_rota.php')){ob_start();include 'parts/display_tracado_rota.php';$html_resultado.=ob_get_clean();}else{$html_resultado.="Erro Rota";} $html_resultado.='</div>'; $html_resultado.='<div class="tab-pane fade" id="subtab-pontos-'.$tid.'" role="tabpanel">'; if(file_exists('parts/display_pontos_referencia.php')){ob_start();include 'parts/display_pontos_referencia.php';$html_resultado.=ob_get_clean();}else{$html_resultado.="Erro Pontos";} $html_resultado.='</div>'; $html_resultado.='</div>'; $html_resultado.='</div>'; $is_first_bloco=false;} $html_resultado.='</div>'; $html_resultado.='</div>';$is_first_dia=false;}} $html_resultado.='</div>';
        } else { $html_resultado = "<p class='text-info mt-3'>Não existe dados para a linha ".htmlspecialchars($valor_busca).".</p>"; }

    // ========================================================================
    // === BUSCA POR WORKID (Busca e mostra SÓ eventos do workid_eventos) ===
    // ========================================================================
    } elseif ($tipo_busca == 'workid') {
        $eventos_para_exibir = []; // <- Será preenchido com os eventos DO WORKID BUSCADO
        $tabela_ref = 'N/A';
        $workid_buscado = $valor_busca;

        try {
            // Busca diretamente os eventos que têm o workid_eventos pesquisado
            $sql = "SELECT ev.sequencia, ev.programacao_id, ev.local_id, ev.linha_atual_id,
                       ev.numero_tabela_evento AS tabela_diario, -- Tabela do Evento
                       ev.workid_eventos, -- WorkID do Evento (será igual ao buscado)
                       l.numero AS numero_linha, l.nome AS nome_linha, l.imagem_ponto_ida_path, l.imagem_ponto_volta_path,
                       prog.work_id AS work_id_bloco, -- WorkID Mestre/Bloco (informativo)
                       TIME_FORMAT(ev.horario_chegada, '%H:%i') AS chegada_fmt, TIME_FORMAT(ev.horario_saida, '%H:%i') AS saida_fmt,
                       loc.nome AS nome_local, ev.info, ev.horario_saida
                    FROM diario_bordo_eventos AS ev
                    LEFT JOIN programacao_diaria AS prog ON ev.programacao_id = prog.id -- LEFT JOIN para caso programação seja deletada
                    JOIN linhas AS l ON ev.linha_atual_id = l.id
                    JOIN locais AS loc ON ev.local_id = loc.id
                    WHERE ev.workid_eventos = :valor_busca -- <<< FILTRA PELO WORKID DO EVENTO
                    ORDER BY ev.sequencia ASC"; // Ordena pela sequencia original
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':valor_busca', $workid_buscado, PDO::PARAM_STR);
            $stmt->execute();
            $eventos_para_exibir = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Pega a Tabela de referência do primeiro evento encontrado
            if (!empty($eventos_para_exibir)) {
                 $tabela_ref = $eventos_para_exibir[0]['tabela_diario'] ?? 'N/A';
                 // O workid_ref continua sendo o que foi buscado
            }

        } catch (PDOException $e) { $erro_busca_geral = true; error_log("Erro buscar_horario.php (workid por evento): ".$e->getMessage()); }

        // Montar o HTML usando as 3 ABAS (mostra só os eventos do WorkID específico)
        if ($erro_busca_geral) { $html_resultado = "<p class='text-danger mt-3'>Ocorreu um erro (workid).</p>"; }
        elseif (!empty($eventos_para_exibir)) {
             // Título informa o WorkID específico buscado e sua Tabela
             $html_resultado .= "<h5 class='mt-3'>Tudo sobre o WorkID: ".htmlspecialchars($workid_buscado)."</h5>";
             $tab_id_base = "workid-" . preg_replace('/[^a-zA-Z0-9]/','', $workid_buscado);
             // HTML das 3 abas (igual antes, chama includes)
             $html_resultado .= '<ul class="nav nav-pills nav-fill mb-3 mt-2" id="pills-subtab-' . $tab_id_base . '" role="tablist">'; $html_resultado .= '<li class="nav-item"><a class="nav-link active" data-toggle="pill" href="#subtab-tabela-' . $tab_id_base . '"><i class="fas fa-list-alt"></i> Diário de Bordo</a></li>'; $html_resultado .= '<li class="nav-item"><a class="nav-link" data-toggle="pill" href="#subtab-rota-' . $tab_id_base . '"><i class="fas fa-route"></i> Rota</a></li>'; $html_resultado .= '<li class="nav-item"><a class="nav-link" data-toggle="pill" href="#subtab-pontos-' . $tab_id_base . '"><i class="fas fa-map-marker-alt"></i> Pontos</a></li>'; $html_resultado .= '</ul>';
             $html_resultado .= '<div class="tab-content border p-2" id="pills-subtabContent-' . $tab_id_base . '">';
             // Includes (eles usarão $eventos_para_exibir que agora contém só os eventos do workid buscado)
             $html_resultado .= '<div class="tab-pane fade show active" id="subtab-tabela-' . $tab_id_base . '" role="tabpanel">'; if (file_exists('parts/display_tabela_detalhada.php')) { ob_start(); include 'parts/display_tabela_detalhada.php'; $html_resultado .= ob_get_clean(); } else {$html_resultado .= "<p>Erro Include Tabela</p>";} $html_resultado .= '</div>';
             $html_resultado .= '<div class="tab-pane fade" id="subtab-rota-' . $tab_id_base . '" role="tabpanel">'; if (file_exists('parts/display_tracado_rota.php')) { ob_start(); include 'parts/display_tracado_rota.php'; $html_resultado .= ob_get_clean(); } else {$html_resultado .= "<p>Erro Include Rota</p>";} $html_resultado .= '</div>';
             $html_resultado .= '<div class="tab-pane fade" id="subtab-pontos-' . $tab_id_base . '" role="tabpanel">'; if (file_exists('parts/display_pontos_referencia.php')) { ob_start(); include 'parts/display_pontos_referencia.php'; $html_resultado .= ob_get_clean(); } else {$html_resultado .= "<p>Erro Include Pontos</p>";} $html_resultado .= '</div>';
             $html_resultado .= '</div>'; // Fim subtab-content
        } else { $html_resultado = "<p class='text-info mt-3'>Não existe dados para o WorkID ".htmlspecialchars($valor_busca).".</p>"; }
    }

    echo $html_resultado;
} else { echo "<p class='text-danger'>Requisição inválida.</p>"; }
?>