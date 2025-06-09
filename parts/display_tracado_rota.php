<?php
// parts/display_tracado_rota.php
// Recebe $eventos_para_exibir (array de eventos) e $pdo (conexão)
// Exibe os iframes das rotas Ida/Volta

if (!isset($eventos_para_exibir) || empty($eventos_para_exibir)) {
    echo "<p class='text-info'>Sem eventos para determinar as rotas.</p>";
    return;
}
if (!isset($pdo) || $pdo === null) {
    echo "<p class='text-warning'>Conexão com banco indisponível para buscar detalhes das rotas.</p>";
    return;
}

// 1. Identificar rotas únicas (linha + via)
$rotas_identificadas = []; $ids_linhas_unicas = [];
$linha_anterior = null; $via_atual_db = 'Padrão'; $via_nome_original = 'Padrão';
foreach ($eventos_para_exibir as $evento) { /* ... (Lógica para identificar $rotas_identificadas e $ids_linhas_unicas - igual à anterior) ... */
    if (!isset($evento['linha_atual_id']) || !isset($evento['numero_linha'])) continue;
    $id_linha = $evento['linha_atual_id']; $num_linha = $evento['numero_linha']; $nome_linha = $evento['nome_linha'] ?? null;
    if (!isset($ids_linhas_unicas[$id_linha])) { $ids_linhas_unicas[$id_linha] = ['numero' => $num_linha, 'nome' => $nome_linha]; }
    $variacao_atual_db = 'Padrão'; $variacao_display = 'Padrão';
    if (!empty($evento['info']) && ($pos = stripos($evento['info'], 'Via ')) !== false) { $variacao_atual_db = trim(substr($evento['info'], $pos)); $variacao_display = $variacao_atual_db; }
    $chave_rota = $id_linha . '-' . $variacao_atual_db;
    if (!isset($rotas_identificadas[$chave_rota])) { $rotas_identificadas[$chave_rota] = [ 'linha_id' => $id_linha, 'numero_linha' => $num_linha, 'nome_linha' => $nome_linha, 'variacao_db' => $variacao_atual_db, 'variacao_display' => $variacao_display ]; }
}

// 2. Buscar os iframes para todas as rotas necessárias
$iframes_rotas = [];
if (!empty($rotas_identificadas)) { /* ... (Lógica para buscar iframes da tabela rotas_linha - igual à anterior) ... */
    $query_params = []; $sql_parts = [];
    foreach ($rotas_identificadas as $rota) { $sql_parts[] = "(linha_id = ? AND variacao_nome = ?)"; $query_params[] = $rota['linha_id']; $query_params[] = $rota['variacao_db']; }
    if (!empty($sql_parts)) {
        try {
            $sql_iframes = "SELECT linha_id, variacao_nome, mapa_iframe_ida, mapa_iframe_volta FROM rotas_linha WHERE " . implode(" OR ", $sql_parts);
            $stmt_iframes = $pdo->prepare($sql_iframes); $stmt_iframes->execute($query_params);
            while ($rota_db = $stmt_iframes->fetch(PDO::FETCH_ASSOC)) { $iframes_rotas[$rota_db['linha_id']][$rota_db['variacao_nome']] = [ 'ida' => $rota_db['mapa_iframe_ida'], 'volta' => $rota_db['mapa_iframe_volta'] ]; }
        } catch (PDOException $e) { error_log("Erro ao buscar iframes rotas (include): ".$e->getMessage()); echo "<div class='alert alert-warning'>Não foi possível carregar os mapas das rotas.</div>"; }
    }
}

// 3. Exibir os cards com os iframes
if (empty($rotas_identificadas)) { echo "<p class='text-info'>Não foi possível determinar as rotas.</p>"; }
else {
    echo "<div class='row'>";
    foreach ($rotas_identificadas as $chave => $rota) { /* ... (HTML para exibir os cards com iframes IDA/VOLTA - igual à anterior) ... */
        $id_linha = $rota['linha_id']; $variacao_db = $rota['variacao_db']; $iframes = $iframes_rotas[$id_linha][$variacao_db] ?? ['ida' => null, 'volta' => null];
        ?>
         <div class="col-md-6 mb-3"> <div class="card shadow-sm h-100"> <div class="card-header bg-light"> <strong>Linha: <?php echo htmlspecialchars($rota['numero_linha']); ?> <?php echo ($rota['variacao_display'] != 'Padrão' ? ' (' . htmlspecialchars($rota['variacao_display']) . ')' : ''); ?> </strong> </div> <div class="card-body"> <div class="row text-center"> <div class="col-12 mb-3"> <h6><i class="fas fa-route text-primary"></i> Traçado IDA</h6> <div class="embed-responsive embed-responsive-16by9 border rounded"> <?php if (!empty($iframes['ida'])) { echo $iframes['ida']; } else { echo "<div class='d-flex justify-content-center align-items-center h-100'><p class='text-muted'>(Mapa de Ida não disponível)</p></div>"; } ?> </div> </div> <div class="col-12"> <h6><i class="fas fa-undo-alt text-info"></i> Traçado VOLTA</h6> <div class="embed-responsive embed-responsive-16by9 border rounded"> <?php if (!empty($iframes['volta'])) { echo $iframes['volta']; } else { echo "<div class='d-flex justify-content-center align-items-center h-100'><p class='text-muted'>(Mapa de Volta não disponível)</p></div>"; } ?> </div> </div> </div> </div> </div> </div>
        <?php
    } echo "</div>"; // Fim row
}
?>