<?php
// parts/escala_tab_pontos.php
// Conteúdo da Aba "Pontos" com ZOOM nas imagens

// Assume que $escala_eventos, $erro_busca, $usuario_logado, $pdo já existem

if ($usuario_logado && $pdo !== null && !$erro_busca && !empty($escala_eventos)) {

    // 1. Obter informações únicas das linhas (incluindo paths das imagens)
    $info_linhas = [];
    foreach ($escala_eventos as $evento) {
         if (isset($evento['linha_atual_id']) && !isset($info_linhas[$evento['linha_atual_id']])) {
             $info_linhas[$evento['linha_atual_id']] = [
                 'id' => $evento['linha_atual_id'],
                 'numero' => $evento['numero_linha'] ?? 'N/A',
                 'nome' => $evento['nome_linha'] ?? null,
                 'imagem_ponto_ida_path' => $evento['imagem_ponto_ida_path'] ?? null,
                 'imagem_ponto_volta_path' => $evento['imagem_ponto_volta_path'] ?? null
             ];
         }
    }

    // 2. Exibir um card para cada linha operada
    if (empty($info_linhas) && !empty($escala_eventos)) {
         echo "<p class='text-warning'>Informações de referência das linhas não encontradas.</p>";
    } elseif (!empty($info_linhas)) {
        $linhas_ja_exibidas = [];
        foreach ($escala_eventos as $evento) {
            if (!isset($evento['linha_atual_id'])) continue;
            $id_linha_atual = $evento['linha_atual_id'];
            if (!in_array($id_linha_atual, $linhas_ja_exibidas) && isset($info_linhas[$id_linha_atual])) {
                $linha = $info_linhas[$id_linha_atual];
                $linhas_ja_exibidas[] = $id_linha_atual;
                ?>
                <div class="card mb-3 shadow-sm">
                    <div class="card-header bg-light">
                        <strong>Linha: <?php echo htmlspecialchars($linha['numero']); ?>
                        <?php if(!empty($linha['nome'])) echo " - " . htmlspecialchars($linha['nome']); ?></strong>
                        <small>(Tabela: <?php echo htmlspecialchars(sprintf("%02d", $evento['tabela_diario'] ?? 0)); ?>, WorkID: <?php echo htmlspecialchars($evento['work_id'] ?? 'N/A'); ?>)</small>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-6 border-right"> <h6><i class="fas fa-sign-out-alt text-primary"></i> Ponto IDA</h6>
                                <?php
                                    $img_ida = $linha['imagem_ponto_ida_path'];
                                    if ($img_ida) {
                                        $caminho_img_ida = 'img/pontos/' . htmlspecialchars($img_ida);
                                        if (file_exists($caminho_img_ida)) {
                                            $alt_text_ida = "Ponto Ida Linha " . htmlspecialchars($linha['numero']);
                                            // Link para ativar o modal de zoom
                                            echo "<a href='#' data-toggle='modal' data-target='#imageZoomModal' data-imgsrc='" . $caminho_img_ida . "' class='zoomable-image' title='Clique para ampliar'>";
                                            echo "<img src='$caminho_img_ida' class='img-fluid img-thumbnail mt-2' style='max-height: 200px;' alt='" . $alt_text_ida . "'>";
                                            echo "</a>";
                                        } else { echo "<p class='text-muted mt-2'><small>(Imagem Ida não encontrada)</small></p>"; }
                                    } else { echo "<p class='text-muted mt-2'>(Sem imagem definida)</p>"; }
                                ?>
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0"> <h6><i class="fas fa-undo-alt text-info"></i> Ponto VOLTA</h6>
                                 <?php
                                    $img_volta = $linha['imagem_ponto_volta_path'];
                                    if ($img_volta) {
                                        $caminho_img_volta = 'img/pontos/' . htmlspecialchars($img_volta);
                                        if (file_exists($caminho_img_volta)) {
                                             $alt_text_volta = "Ponto Volta Linha " . htmlspecialchars($linha['numero']);
                                             // Link para ativar o modal de zoom
                                             echo "<a href='#' data-toggle='modal' data-target='#imageZoomModal' data-imgsrc='" . $caminho_img_volta . "' class='zoomable-image' title='Clique para ampliar'>";
                                             echo "<img src='$caminho_img_volta' class='img-fluid img-thumbnail mt-2' style='max-height: 200px;' alt='" . $alt_text_volta . "'>";
                                             echo "</a>";
                                        } else { echo "<p class='text-muted mt-2'><small>(Imagem Volta não encontrada)</small></p>"; }
                                    } else { echo "<p class='text-muted mt-2'>(Sem imagem definida)</p>"; }
                                ?>
                            </div>
                        </div>
                    </div>
                </div><?php
           } // Fim if linha já exibida
       } // Fim foreach escala_eventos
   } else { echo "<p class='text-info'>Não há informações de referência para as linhas da sua escala.</p>"; }

// Mensagens de fallback
} elseif (!$usuario_logado || $pdo === null) { echo "<p class='text-warning'>Faça login e verifique a conexão para ver os pontos.</p>"; }
elseif ($erro_busca) { echo "<p class='text-danger'>Não foi possível gerar os pontos.</p>"; }
else { echo "<p class='text-info'>Sem escala para exibir os pontos.</p>"; }
?>