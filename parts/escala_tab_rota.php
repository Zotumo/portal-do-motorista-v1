<?php
// parts/escala_tab_rota.php
// Conteúdo da Aba "Traçado/Rota" - CORRIGIDO para não repetir rotas

// Assume que $escala_eventos, $erro_busca, $usuario_logado, $pdo já existem

if ($usuario_logado && $pdo !== null && !$erro_busca && !empty($escala_eventos)) {

    // 1. Identificar as combinações únicas de (linha_id, variacao_nome) da escala
    $rotas_identificadas = []; // Guarda [chave_unica] => [dados da rota como numero_linha, via_display, via_db]
    $ids_linhas_unicas = []; // Guarda só os IDs das linhas para buscar no DB
    $linha_anterior = null;
    $via_atual_db = 'Padrão'; // Nome da via como deve estar no DB (default)

    foreach ($escala_eventos as $evento) {
        if (!isset($evento['linha_atual_id']) || !isset($evento['numero_linha'])) continue;

        $id_linha = $evento['linha_atual_id'];
        $num_linha = $evento['numero_linha'];
        $nome_linha = $evento['nome_linha'] ?? null; // Opcional

        // Guarda info básica da linha
        if (!isset($ids_linhas_unicas[$id_linha])) {
             $ids_linhas_unicas[$id_linha] = ['numero' => $num_linha, 'nome' => $nome_linha];
        }

        // Determina a variação da rota ('Padrão' ou baseada no campo 'info')
        // O nome da variação aqui DEVE corresponder ao que está na coluna 'variacao_nome' da tabela 'rotas_linha'
        $variacao_atual_db = 'Padrão'; // Default
        $variacao_display = 'Padrão';  // Para exibir
        if (!empty($evento['info']) && ($pos = stripos($evento['info'], 'Via ')) !== false) {
             // Assume que o nome da variação no DB é exatamente o texto após "Via "
             $variacao_atual_db = trim(substr($evento['info'], $pos));
             $variacao_display = $variacao_atual_db; // Usa o mesmo para exibir
        }

        // Cria uma chave única para esta combinação linha-via
        $chave_rota = $id_linha . '-' . $variacao_atual_db;

        // Adiciona à lista de rotas necessárias se ainda não estiver lá
        if (!isset($rotas_identificadas[$chave_rota])) {
             $rotas_identificadas[$chave_rota] = [
                 'linha_id' => $id_linha,
                 'numero_linha' => $num_linha,
                 'nome_linha' => $nome_linha,
                 'variacao_db' => $variacao_atual_db, // Nome para buscar no DB
                 'variacao_display' => $variacao_display // Nome para exibir
             ];
        }
    }

    // 2. Buscar os iframes para todas as rotas necessárias identificadas
    $iframes_rotas = []; // Guarda [linha_id][variacao_nome_db] => ['ida' => iframe, 'volta' => iframe]
    if (!empty($rotas_identificadas)) {
        $query_params = [];
        $sql_parts = [];
        // Monta a query para buscar todas as combinações linha/via de uma vez
        foreach ($rotas_identificadas as $rota) {
             $sql_parts[] = "(linha_id = ? AND variacao_nome = ?)";
             $query_params[] = $rota['linha_id'];
             $query_params[] = $rota['variacao_db']; // Usa o nome da variação como está no DB
        }

        if (!empty($sql_parts)) {
            try {
                $sql_iframes = "SELECT linha_id, variacao_nome, mapa_iframe_ida, mapa_iframe_volta
                                FROM rotas_linha
                                WHERE " . implode(" OR ", $sql_parts);
                $stmt_iframes = $pdo->prepare($sql_iframes);
                $stmt_iframes->execute($query_params);

                // Guarda os iframes encontrados, organizados por linha e variação
                while ($rota_db = $stmt_iframes->fetch(PDO::FETCH_ASSOC)) {
                    $iframes_rotas[$rota_db['linha_id']][$rota_db['variacao_nome']] = [
                        'ida' => $rota_db['mapa_iframe_ida'],
                        'volta' => $rota_db['mapa_iframe_volta']
                    ];
                }
            } catch (PDOException $e) {
                 error_log("Erro ao buscar iframes das rotas: ".$e->getMessage());
                 echo "<div class='alert alert-warning'>Não foi possível carregar os mapas das rotas.</div>";
            }
        }
    }


    // 3. Exibir um card para cada ROTA ÚNICA identificada na escala
    if (empty($rotas_identificadas)) {
        echo "<p class='text-info'>Não foi possível determinar as rotas da sua escala.</p>";
    } else {
        echo "<p>Traçados incluídos na sua escala:</p>";
        echo "<div class='row'>";
        // **** LOOP CORRIGIDO: Itera sobre as rotas únicas identificadas ****
        foreach ($rotas_identificadas as $chave => $rota) {
            $id_linha = $rota['linha_id'];
            $variacao_db = $rota['variacao_db'];

            // Busca os iframes para esta rota específica (pode ser vazio se não cadastrado)
            $iframes = $iframes_rotas[$id_linha][$variacao_db] ?? ['ida' => null, 'volta' => null];

            ?>
            <div class="col-md-6 mb-3"> <div class="card shadow-sm h-100"> <div class="card-header bg-light">
                         <strong>Linha: <?php echo htmlspecialchars($rota['numero_linha']); ?>
                         <?php echo ($rota['variacao_display'] != 'Padrão' ? ' (' . htmlspecialchars($rota['variacao_display']) . ')' : ''); ?>
                         </strong>
                         </div>
                     <div class="card-body">
                         <div class="row text-center">
                              <div class="col-12 mb-3"> <h6><i class="fas fa-route text-primary"></i> Traçado IDA</h6>
                                   <div class="embed-responsive embed-responsive-16by9 border rounded">
                                       <?php
                                           if (!empty($iframes['ida'])) { echo $iframes['ida']; }
                                           else { echo "<div class='d-flex justify-content-center align-items-center h-100'><p class='text-muted'>(Mapa de Ida não disponível)</p></div>"; }
                                       ?>
                                   </div>
                              </div>
                              <div class="col-12"> <h6><i class="fas fa-undo-alt text-info"></i> Traçado VOLTA</h6>
                                   <div class="embed-responsive embed-responsive-16by9 border rounded">
                                        <?php
                                           if (!empty($iframes['volta'])) { echo $iframes['volta']; }
                                           else { echo "<div class='d-flex justify-content-center align-items-center h-100'><p class='text-muted'>(Mapa de Volta não disponível)</p></div>"; }
                                       ?>
                                   </div>
                              </div>
                         </div>
                     </div>
                 </div>
            </div><?php
        } // Fim foreach $rotas_identificadas
        echo "</div>"; // Fim row
    } // Fim else empty $rotas_identificadas

// Mensagens de fallback
} elseif (!$usuario_logado || $pdo === null) {
    echo "<p class='text-warning'>Faça login e verifique a conexão para ver a rota.</p>";
} elseif ($erro_busca) {
     echo "<p class='text-danger'>Não foi possível gerar a rota devido a um erro ao buscar a escala.</p>";
} else { // $escala_eventos está vazio
    echo "<p class='text-info'>Sem escala para exibir a rota.</p>";
}
?>