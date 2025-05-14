<?php
// parts/display_pontos_referencia.php
// Recebe $eventos_para_exibir (array de eventos) e $pdo (conexão)
// Exibe as imagens dos pontos de referência Ida/Volta

if (!isset($eventos_para_exibir) || empty($eventos_para_exibir)) {
    echo "<p class='text-info'>Sem eventos para exibir os pontos.</p>";
    return;
}
 if (!isset($pdo) || $pdo === null) {
    echo "<p class='text-warning'>Conexão com banco indisponível para buscar detalhes dos pontos.</p>";
    return;
}

// 1. Obter lista única de IDs de linha E seus dados (incluindo paths das imagens)
// Assume que a query que gerou $eventos_para_exibir já selecionou l.imagem_ponto_ida_path e l.imagem_ponto_volta_path
$info_linhas = [];
foreach ($eventos_para_exibir as $evento) {
     if (isset($evento['linha_atual_id']) && !isset($info_linhas[$evento['linha_atual_id']])) {
         $info_linhas[$evento['linha_atual_id']] = [
             'id' => $evento['linha_atual_id'],
             'numero' => $evento['numero_linha'] ?? 'N/A',
             'nome' => $evento['nome_linha'] ?? null, // Requer 'l.nome as nome_linha' na query principal
             'imagem_ponto_ida_path' => $evento['imagem_ponto_ida_path'] ?? null,
             'imagem_ponto_volta_path' => $evento['imagem_ponto_volta_path'] ?? null
         ];
     }
}

 // 2. Exibir um card para cada linha operada (na ordem da escala)
 if (empty($info_linhas)) { echo "<p class='text-warning'>Informações de referência das linhas não encontradas nos dados da escala.</p>"; }
 else {
     $linhas_ja_exibidas = [];
     foreach ($eventos_para_exibir as $evento) {
         if (!isset($evento['linha_atual_id'])) continue;
         $id_linha_atual = $evento['linha_atual_id'];
         if (!in_array($id_linha_atual, $linhas_ja_exibidas) && isset($info_linhas[$id_linha_atual])) {
              $linha = $info_linhas[$id_linha_atual]; $linhas_ja_exibidas[] = $id_linha_atual;
              ?>
              <div class="card mb-3 shadow-sm">
                  <div class="card-header bg-light">
                      <strong>Linha: <?php echo htmlspecialchars($linha['numero']); ?>
                      <?php if(!empty($linha['nome'])) echo " - " . htmlspecialchars($linha['nome']); ?></strong>
                  </div>
                  <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-6 border-right"> <h6><i class="fas fa-sign-out-alt text-primary"></i> Ponto IDA</h6>
                                <?php
                                    $img_ida = $linha['imagem_ponto_ida_path'];
                                    if ($img_ida) {
                                        $caminho_img_ida = 'img/pontos/' . htmlspecialchars($img_ida); // Ajuste a pasta se necessário
                                        if (file_exists($caminho_img_ida)) {
                                            $alt_text_ida = "Ponto Ida Linha " . htmlspecialchars($linha['numero']);
                                            // ***** INÍCIO CÓDIGO DO ZOOM IDA *****
                                            echo "<a href='#' data-toggle='modal' data-target='#imageZoomModal' data-imgsrc='" . $caminho_img_ida . "' class='zoomable-image' title='Clique para ampliar'>";
                                            echo "<img src='$caminho_img_ida' class='img-fluid img-thumbnail mt-2' style='max-height: 200px;' alt='" . $alt_text_ida . "'>";
                                            echo "</a>";
                                            // ***** FIM CÓDIGO DO ZOOM IDA *****
                                        } else { echo "<p class='text-muted mt-2'><small>(Imagem Ida não encontrada)</small></p>"; }
                                    } else { echo "<p class='text-muted mt-2'>(Sem imagem definida)</p>"; }
                                ?>
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0"> <h6><i class="fas fa-undo-alt text-info"></i> Ponto VOLTA</h6>
                                 <?php
                                    $img_volta = $linha['imagem_ponto_volta_path'];
                                    if ($img_volta) {
                                        $caminho_img_volta = 'img/pontos/' . htmlspecialchars($img_volta); // Ajuste a pasta se necessário
                                        if (file_exists($caminho_img_volta)) {
                                             $alt_text_volta = "Ponto Volta Linha " . htmlspecialchars($linha['numero']);
                                             // ***** INÍCIO CÓDIGO DO ZOOM VOLTA *****
                                             echo "<a href='#' data-toggle='modal' data-target='#imageZoomModal' data-imgsrc='" . $caminho_img_volta . "' class='zoomable-image' title='Clique para ampliar'>";
                                             echo "<img src='$caminho_img_volta' class='img-fluid img-thumbnail mt-2' style='max-height: 200px;' alt='" . $alt_text_volta . "'>";
                                             echo "</a>";
                                             // ***** FIM CÓDIGO DO ZOOM VOLTA *****
                                        } else { echo "<p class='text-muted mt-2'><small>(Imagem Volta não encontrada)</small></p>"; }
                                    } else { echo "<p class='text-muted mt-2'>(Sem imagem definida)</p>"; }
                                ?>
                            </div>
                        </div>
                    </div>
              </div><?php
         } // Fim if
     } // Fim foreach
 } // Fim else
?>