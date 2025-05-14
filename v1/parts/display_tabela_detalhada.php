<?php
// parts/display_tabela_detalhada.php
// Recebe $eventos_para_exibir (array de eventos)
// Exibe a tabela HTML detalhada (COM Tabela formatada do evento)

if (!isset($eventos_para_exibir) || !is_array($eventos_para_exibir) || empty($eventos_para_exibir)) {
    global $erro_busca_dia; // Ou variável similar do contexto pai
    if(!isset($erro_busca_dia) || !$erro_busca_dia) {
         echo "<p class='text-info'>Nenhum evento de escala para exibir.</p>";
    }
} else {
?>
<div class="table-responsive">
    <table class="table table-striped table-bordered table-hover table-sm">
        <thead class="thead-light">
            <tr>
                <th>Linha</th>
                <th>Tabela</th>
                <th>WorkID</th>
                <th>Chegada</th>
                <th>Saída</th>
                <th>Local</th>
                <th>Info</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($eventos_para_exibir as $evento): ?>
            <tr>
                <td><?php echo htmlspecialchars($evento['numero_linha'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars(sprintf("%02d", $evento['tabela_diario'] ?? 0)); ?></td>
                <td><?php echo htmlspecialchars($evento['workid_eventos'] ?? $evento['work_id_bloco'] ?? 'N/A'); ?></td>
				<td><?php echo $evento['chegada_fmt'] ?? '--:--'; ?></td>
                <td><?php echo $evento['saida_fmt'] ?? '--:--'; ?></td>
                <td><?php echo htmlspecialchars($evento['nome_local'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($evento['info'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}
?>