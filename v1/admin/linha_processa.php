<?php
// admin/linha_processa.php
// ATUALIZADO: Inclui processamento do campo status_linha e ajusta validação de imagens.

require_once 'auth_check.php';

$niveis_permitidos_processar_linhas = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_processar_linhas)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para salvar dados de linhas.";
    header('Location: linhas_listar.php');
    exit;
}

require_once '../db_config.php';

$pasta_upload_servidor = dirname(__DIR__) . '/img/pontos/';

$params_retorno_lista_array_lp = [];
if (isset($_POST['pagina'])) $params_retorno_lista_array_lp['pagina'] = $_POST['pagina'];
elseif (isset($_GET['pagina'])) $params_retorno_lista_array_lp['pagina'] = $_GET['pagina'];
if (isset($_POST['busca_numero'])) $params_retorno_lista_array_lp['busca_numero'] = $_POST['busca_numero'];
elseif (isset($_GET['busca_numero'])) $params_retorno_lista_array_lp['busca_numero'] = $_GET['busca_numero'];
if (isset($_POST['busca_nome'])) $params_retorno_lista_array_lp['busca_nome'] = $_POST['busca_nome'];
elseif (isset($_GET['busca_nome'])) $params_retorno_lista_array_lp['busca_nome'] = $_GET['busca_nome'];
if (isset($_POST['status_filtro'])) $params_retorno_lista_array_lp['status_filtro'] = $_POST['status_filtro']; // Adicionado para manter filtro de status
elseif (isset($_GET['status_filtro'])) $params_retorno_lista_array_lp['status_filtro'] = $_GET['status_filtro'];

$query_string_retorno_proc_lp = http_build_query($params_retorno_lista_array_lp);
$link_voltar_lista_proc_lp = 'linhas_listar.php' . ($query_string_retorno_proc_lp ? '?' . $query_string_retorno_proc_lp : '');

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['salvar_linha'])) {

    $linha_id = filter_input(INPUT_POST, 'linha_id', FILTER_VALIDATE_INT);
    $numero_linha = trim($_POST['numero_linha'] ?? '');
    $nome_linha = trim($_POST['nome_linha'] ?? '');
    $status_linha = trim($_POST['status_linha'] ?? 'ativa'); // NOVO CAMPO, padrão 'ativa'

    $remover_imagem_ida_chk = isset($_POST['remover_imagem_ida']) && $_POST['remover_imagem_ida'] == '1';
    $remover_imagem_volta_chk = isset($_POST['remover_imagem_volta']) && $_POST['remover_imagem_volta'] == '1';

    $imagem_ida_nome_db = null;
    $imagem_volta_nome_db = null;
    $erros_validacao_linha = [];

    // --- Validações ---
    if (empty($numero_linha)) { $erros_validacao_linha[] = "O Número da Linha é obrigatório."; }
    // ... (outras validações de número e nome)
    if (empty($nome_linha)) { $erros_validacao_linha[] = "O Nome da Linha é obrigatório."; }

    // Validação do Status da Linha
    if (!in_array($status_linha, ['ativa', 'inativa'])) {
        $erros_validacao_linha[] = "Status da linha inválido.";
        $status_linha = 'ativa'; // Força um padrão seguro em caso de valor inválido
    }

    // Verificar duplicidade de Número da Linha (como antes)
    if ($pdo && !empty($numero_linha)) {
        try {
            $sql_check_num = "SELECT id FROM linhas WHERE numero = :numero";
            $params_check_num = [':numero' => $numero_linha];
            if ($linha_id) { 
                $sql_check_num .= " AND id != :id_linha";
                $params_check_num[':id_linha'] = $linha_id;
            }
            $stmt_check_num = $pdo->prepare($sql_check_num);
            $stmt_check_num->execute($params_check_num);
            if ($stmt_check_num->fetch()) {
                $erros_validacao_linha[] = "Já existe uma linha com o número '" . htmlspecialchars($numero_linha) . "'.";
            }
        } catch (PDOException $e) { /* ... */ }
    } elseif (!$pdo) { /* ... */ }

    // --- Processamento de Imagens ---
    // Função auxiliar (como antes)
    function processarUploadImagem($file_input_name, $remover_chk, $imagem_atual_db_path, $pasta_upload, &$nome_imagem_para_db, &$erros_array, $campo_label) {
        if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
            if (!is_dir($pasta_upload) && !mkdir($pasta_upload, 0775, true) && !is_dir($pasta_upload)) { /* ... */ }
            if (!is_writable($pasta_upload)) { /* ... */ }

            $nome_arquivo_original = basename($_FILES[$file_input_name]['name']);
            $extensao = strtolower(pathinfo($nome_arquivo_original, PATHINFO_EXTENSION));
            $tipos_permitidos = ['jpg', 'jpeg', 'png', 'gif'];
            $tamanho_maximo = 2 * 1024 * 1024; 

            if (!in_array($extensao, $tipos_permitidos)) { /* ... erro formato ... */ }
            elseif ($_FILES[$file_input_name]['size'] > $tamanho_maximo) { /* ... erro tamanho ... */ }
            else {
                if (!empty($imagem_atual_db_path) && file_exists($pasta_upload . $imagem_atual_db_path)) {
                    unlink($pasta_upload . $imagem_atual_db_path);
                }
                $novo_nome_arquivo = "linha_" . uniqid() . "_" . time() . "_" . rand(100,999) . "." . $extensao; // Adicionado rand para mais unicidade
                if (move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $pasta_upload . $novo_nome_arquivo)) {
                    $nome_imagem_para_db = $novo_nome_arquivo;
                } else { /* ... erro upload ... */ }
            }
        } elseif ($remover_chk && !empty($imagem_atual_db_path)) {
            if (file_exists($pasta_upload . $imagem_atual_db_path)) {
                unlink($pasta_upload . $imagem_atual_db_path);
            }
            $nome_imagem_para_db = null; 
        } elseif (!empty($imagem_atual_db_path) && !$remover_chk) {
            $nome_imagem_para_db = $imagem_atual_db_path;
        } else {
             $nome_imagem_para_db = null; // Se não há imagem atual e nenhuma nova foi enviada
        }
    }

    $db_imagem_ida_path = null;
    $db_imagem_volta_path = null;
    if ($linha_id && $pdo) {
        try { /* ... busca paths das imagens atuais (como antes) ... */
            $stmt_img_paths = $pdo->prepare("SELECT imagem_ponto_ida_path, imagem_ponto_volta_path FROM linhas WHERE id = :id_linha_img");
            $stmt_img_paths->bindParam(':id_linha_img', $linha_id, PDO::PARAM_INT);
            $stmt_img_paths->execute();
            $paths = $stmt_img_paths->fetch(PDO::FETCH_ASSOC);
            if ($paths) {
                $db_imagem_ida_path = $paths['imagem_ponto_ida_path'];
                $db_imagem_volta_path = $paths['imagem_ponto_volta_path'];
            }
        } catch (PDOException $e_img) { /* ... */ }
    }

    processarUploadImagem('imagem_ponto_ida', $remover_imagem_ida_chk, $db_imagem_ida_path, $pasta_upload_servidor, $imagem_ida_nome_db, $erros_validacao_linha, "Imagem Ponto Ida");
    processarUploadImagem('imagem_ponto_volta', $remover_imagem_volta_chk, $db_imagem_volta_path, $pasta_upload_servidor, $imagem_volta_nome_db, $erros_validacao_linha, "Imagem Ponto Volta");

    // Validação final de obrigatoriedade das imagens
    // Uma imagem é obrigatória se não existir uma atual E nenhuma nova foi enviada (ou a atual foi marcada para remover e nenhuma nova foi enviada)
    if (empty($imagem_ida_nome_db)) { // Se após todo o processamento, ainda estiver vazia, é erro.
        $erros_validacao_linha[] = "A Imagem do Ponto Ida é obrigatória.";
    }
    if (empty($imagem_volta_nome_db)) { // Se após todo o processamento, ainda estiver vazia, é erro.
        $erros_validacao_linha[] = "A Imagem do Ponto Volta é obrigatória.";
    }


    if (!empty($erros_validacao_linha)) {
        $_SESSION['admin_form_error_linha'] = implode("<br>", $erros_validacao_linha);
        $repop_data = $_POST;
        unset($repop_data['imagem_ponto_ida'], $repop_data['imagem_ponto_volta']);
        $_SESSION['form_data_linha'] = $repop_data; 
        
        $redirect_url_form_lp = $linha_id ? 'linha_formulario.php?id=' . $linha_id : 'linha_formulario.php';
        $redirect_url_form_lp .= ($query_string_retorno_proc_lp ? (strpos($redirect_url_form_lp, '?') === false ? '?' : '&') . $query_string_retorno_proc_lp : '');
        header('Location: ' . $redirect_url_form_lp);
        exit;
    }

    if ($pdo) {
        try {
            if ($linha_id) { 
                // ATUALIZADO: Incluir status_linha no UPDATE
                $sql_linha = "UPDATE linhas SET numero = :numero, nome = :nome, 
                                       status_linha = :status_linha, 
                                       imagem_ponto_ida_path = :img_ida, imagem_ponto_volta_path = :img_volta
                              WHERE id = :id";
                $stmt_linha_op = $pdo->prepare($sql_linha);
                $stmt_linha_op->bindParam(':id', $linha_id, PDO::PARAM_INT);
            } else { 
                // ATUALIZADO: Incluir status_linha no INSERT
                $sql_linha = "INSERT INTO linhas (numero, nome, status_linha, imagem_ponto_ida_path, imagem_ponto_volta_path) 
                              VALUES (:numero, :nome, :status_linha, :img_ida, :img_volta)";
                $stmt_linha_op = $pdo->prepare($sql_linha);
            }

            $stmt_linha_op->bindParam(':numero', $numero_linha, PDO::PARAM_STR);
            $stmt_linha_op->bindParam(':nome', $nome_linha, PDO::PARAM_STR);
            $stmt_linha_op->bindParam(':status_linha', $status_linha, PDO::PARAM_STR); // BIND NOVO CAMPO
            $stmt_linha_op->bindParam(':img_ida', $imagem_ida_nome_db, $imagem_ida_nome_db === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt_linha_op->bindParam(':img_volta', $imagem_volta_nome_db, $imagem_volta_nome_db === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

            if ($stmt_linha_op->execute()) {
                // ... (mensagem de sucesso e redirect como antes)
                $mensagem_sucesso_linha = $linha_id ? "Linha '" . htmlspecialchars($numero_linha) . "' atualizada com sucesso!" : "Nova linha '" . htmlspecialchars($numero_linha) . "' cadastrada com sucesso!";
                $_SESSION['admin_success_message'] = $mensagem_sucesso_linha;
                header('Location: ' . $link_voltar_lista_proc_lp);
                exit;
            } else { /* ... */ }
        } catch (PDOException $e) { /* ... */ }
    } else { /* ... */ }

    // Fallback redirect (como antes)
    $_SESSION['form_data_linha'] = $_POST; // Repopula com o POST original
    $redirect_url_form_lp_err = $linha_id ? 'linha_formulario.php?id=' . $linha_id : 'linha_formulario.php';
    $redirect_url_form_lp_err .= ($query_string_retorno_proc_lp ? (strpos($redirect_url_form_lp_err, '?') === false ? '?' : '&') . $query_string_retorno_proc_lp : '');
    header('Location: ' . $redirect_url_form_lp_err);
    exit;

} else {
    $_SESSION['admin_error_message'] = "Acesso inválido ao processamento de linhas.";
    header('Location: ' . $link_voltar_lista_proc_lp);
    exit;
}
?>