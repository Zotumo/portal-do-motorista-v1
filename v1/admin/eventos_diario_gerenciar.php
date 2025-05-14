<?php
// admin/eventos_diario_gerenciar.php
// Gerencia os eventos do Diário de Bordo para uma Programação Diária (Bloco) específica.

require_once 'auth_check.php';

$niveis_permitidos_gerenciar_eventos = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_permitidos_ver_eventos = ['CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];

if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_ver_eventos)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para visualizar os eventos do diário de bordo.";
    header('Location: eventos_diario_pesquisar.php');
    exit;
}
$pode_gerenciar_eventos = in_array($admin_nivel_acesso_logado, $niveis_permitidos_gerenciar_eventos);

require_once '../db_config.php';

// --- Obter e Validar Parâmetros da URL ---
$programacao_id = isset($_REQUEST['programacao_id']) ? filter_var($_REQUEST['programacao_id'], FILTER_VALIDATE_INT) : null;
$nome_bloco_display_param = isset($_REQUEST['nome_bloco']) ? urldecode($_REQUEST['nome_bloco']) : null;
$dia_tipo_param = isset($_REQUEST['dia_tipo']) ? urldecode($_REQUEST['dia_tipo']) : null;

$tipos_dia_semana_map_eventos = ['Uteis' => 'Dias Úteis', 'Sabado' => 'Sábado', 'DomingoFeriado' => 'Domingo/Feriado'];
$dia_tipo_legivel_display = $tipos_dia_semana_map_eventos[$dia_tipo_param] ?? $dia_tipo_param;

if (!$programacao_id) {
    $_SESSION['admin_error_message'] = "ID da Programação (Bloco) não fornecido.";
    header('Location: eventos_diario_pesquisar.php');
    exit;
}

// Verificar e obter informações do Bloco (programacao_diaria)
$bloco_info = null;
if ($pdo) {
    try {
        $stmt_bloco = $pdo->prepare("SELECT id, work_id, dia_semana_tipo FROM programacao_diaria WHERE id = :pid");
        $stmt_bloco->bindParam(':pid', $programacao_id, PDO::PARAM_INT);
        $stmt_bloco->execute();
        $bloco_info = $stmt_bloco->fetch(PDO::FETCH_ASSOC);
        if (!$bloco_info) {
            $_SESSION['admin_error_message'] = "Bloco de Programação ID {$programacao_id} não encontrado.";
            header('Location: eventos_diario_pesquisar.php');
            exit;
        }
        // Usa os dados do banco para o display, mais confiável que os da URL
        $nome_bloco_display = htmlspecialchars($bloco_info['work_id']);
        $dia_tipo_legivel_display = htmlspecialchars($tipos_dia_semana_map_eventos[$bloco_info['dia_semana_tipo']] ?? $bloco_info['dia_semana_tipo']);
    } catch (PDOException $e) {
        $_SESSION['admin_error_message'] = "Erro ao buscar informações do Bloco.";
        error_log("Erro PDO ao buscar bloco para eventos_diario_gerenciar: " . $e->getMessage());
        header('Location: eventos_diario_pesquisar.php');
        exit;
    }
}


$page_title = 'Diário de Bordo: ' . $nome_bloco_display . ' (' . $dia_tipo_legivel_display . ')';
require_once 'admin_header.php';

// --- Inicialização de Variáveis para o Formulário de Evento ---
$evento_id_edicao = null;
$modo_edicao_evento = false;
$sequencia_form = ''; $linha_atual_id_form = ''; $numero_tabela_evento_form = '';
$workid_eventos_form = ''; $local_id_form = ''; $horario_chegada_form = '';
$horario_saida_form = ''; $info_form = '';

// Carregar listas para selects do formulário de evento
$lista_linhas_eventos_select = []; $lista_locais_eventos_select = [];
if($pdo) {
    try {
        // Carregar apenas linhas ATIVAS para seleção
        $stmt_linhas_ev = $pdo->query("SELECT id, numero, nome FROM linhas WHERE status_linha = 'ativa' ORDER BY CAST(numero AS UNSIGNED), numero, nome");
        $lista_linhas_eventos_select = $stmt_linhas_ev->fetchAll(PDO::FETCH_ASSOC);

        $stmt_locais_ev = $pdo->query("SELECT id, nome FROM locais ORDER BY nome ASC");
        $lista_locais_eventos_select = $stmt_locais_ev->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* erro */ $_SESSION['admin_warning_message'] = "Erro ao carregar listas de Linhas/Locais para o formulário."; }
}

// --- Lógica de Processamento: APAGAR EVENTO (via GET) ---
if (isset($_GET['acao_evento_del']) && $_GET['acao_evento_del'] == 'apagar' && isset($_GET['evento_id_del']) && filter_var($_GET['evento_id_del'], FILTER_VALIDATE_INT) && $pode_gerenciar_eventos) {
    $evento_id_apagar_get = (int)$_GET['evento_id_del'];
    if($pdo){
        try {
            // Adicionar verificação de token CSRF aqui
            $stmt_del_ev = $pdo->prepare("DELETE FROM diario_bordo_eventos WHERE id = :eid_del AND programacao_id = :pid_del_ev");
            $stmt_del_ev->bindParam(':eid_del', $evento_id_apagar_get, PDO::PARAM_INT);
            $stmt_del_ev->bindParam(':pid_del_ev', $programacao_id, PDO::PARAM_INT); // Garante que apague do bloco correto
            if ($stmt_del_ev->execute() && $stmt_del_ev->rowCount() > 0) {
                $_SESSION['admin_success_message'] = "Evento do diário de bordo apagado com sucesso.";
            } else { $_SESSION['admin_warning_message'] = "Evento não encontrado para apagar ou não pertence a este bloco.";}
        } catch (PDOException $e_del_ev) { $_SESSION['admin_error_message'] = "Erro ao apagar evento: " . $e_del_ev->getMessage();}
        // Redireciona para a mesma página para limpar os parâmetros GET da ação de apagar e recarregar a lista
        $redirect_url_after_action = "eventos_diario_gerenciar.php?programacao_id=" . $programacao_id . "&nome_bloco=" . urlencode($bloco_info['work_id']) . "&dia_tipo=" . urlencode($bloco_info['dia_semana_tipo']);
        header("Location: " . $redirect_url_after_action);
        exit;
    }
}


// --- Lógica de Processamento: Formulário ADICIONAR/EDITAR EVENTO (via POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['salvar_evento_diario']) && $pode_gerenciar_eventos) {
    $evento_id_post = filter_input(INPUT_POST, 'evento_id_hidden', FILTER_VALIDATE_INT); // ID do evento se estiver editando
    $sequencia_post = filter_input(INPUT_POST, 'sequencia', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $linha_atual_id_post = filter_input(INPUT_POST, 'linha_atual_id', FILTER_VALIDATE_INT, ['options' => ['default' => null, 'min_range' => 1]]); // Default null se vazio
    $numero_tabela_evento_post = trim($_POST['numero_tabela_evento'] ?? '');
    $workid_eventos_post = trim($_POST['workid_eventos'] ?? '');
    $local_id_post = filter_input(INPUT_POST, 'local_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $horario_chegada_post_str = trim($_POST['horario_chegada'] ?? '');
    $horario_saida_post_str = trim($_POST['horario_saida'] ?? '');
    $info_post = trim($_POST['info'] ?? '');
    $erros_form_evento = [];

    // Validações
    if ($sequencia_post === false || $sequencia_post === null) { $erros_form_evento[] = "Sequência inválida ou não fornecida."; }
    if (empty($local_id_post)) { $erros_form_evento[] = "Local é obrigatório."; }
    // Opcional: Linha pode ser não obrigatória para todos os tipos de evento (ex: GARAGEM)
    // if (empty($linha_atual_id_post) && (empty($workid_eventos_post) || stripos($workid_eventos_post, 'GARAGEM') === false) ) {
    //     $erros_form_evento[] = "Linha é obrigatória, a menos que seja um evento de Garagem.";
    // }
    if (strlen($numero_tabela_evento_post) > 20) { $erros_form_evento[] = "Nº Tabela do Evento excede 20 caracteres.";}
    if (strlen($workid_eventos_post) > 50) { $erros_form_evento[] = "WorkID do Evento excede 50 caracteres.";}
    if (strlen($info_post) > 255) { $erros_form_evento[] = "Informações Adicionais excedem 255 caracteres.";}


    $horario_chegada_db = null;
    if (!empty($horario_chegada_post_str)) {
        if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $horario_chegada_post_str)) {
            $horario_chegada_db = $horario_chegada_post_str . ':00';
        } else { $erros_form_evento[] = "Formato da Hora de Chegada inválido (HH:MM)."; }
    }
    $horario_saida_db = null;
    if (!empty($horario_saida_post_str)) {
        if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $horario_saida_post_str)) {
            $horario_saida_db = $horario_saida_post_str . ':00';
        } else { $erros_form_evento[] = "Formato da Hora de Saída inválido (HH:MM)."; }
    }

    // Validação de sobreposição de sequência (se não estiver editando a própria sequência)
    if ($pdo && $sequencia_post !== false && $sequencia_post !== null && empty($erros_form_evento)) {
        $sql_check_seq = "SELECT id FROM diario_bordo_eventos WHERE programacao_id = :pid_seq AND sequencia = :seq_val";
        $params_seq = [':pid_seq' => $programacao_id, ':seq_val' => $sequencia_post];
        if($evento_id_post) { // Se editando, não comparar com o próprio evento
            $sql_check_seq .= " AND id != :eid_seq";
            $params_seq[':eid_seq'] = $evento_id_post;
        }
        $stmt_seq = $pdo->prepare($sql_check_seq);
        $stmt_seq->execute($params_seq);
        if($stmt_seq->fetch()) {
            $erros_form_evento[] = "A sequência " . htmlspecialchars($sequencia_post) . " já existe para este Diário de Bordo.";
        }
    }
    
    if (empty($erros_form_evento)) {
        if ($pdo) {
            try {
                if ($evento_id_post) { // Editar
                    $sql_ev = "UPDATE diario_bordo_eventos SET sequencia = :seq, linha_atual_id = :lid, numero_tabela_evento = :nte, workid_eventos = :we, local_id = :locid, horario_chegada = :hc, horario_saida = :hs, info = :info 
                               WHERE id = :eid AND programacao_id = :pid_ev_crud"; // Garante que só edite do bloco correto
                    $stmt_ev = $pdo->prepare($sql_ev);
                    $stmt_ev->bindParam(':eid', $evento_id_post, PDO::PARAM_INT);
                } else { // Adicionar
                    $sql_ev = "INSERT INTO diario_bordo_eventos (programacao_id, sequencia, linha_atual_id, numero_tabela_evento, workid_eventos, local_id, horario_chegada, horario_saida, info) 
                               VALUES (:pid_ev_crud, :seq, :lid, :nte, :we, :locid, :hc, :hs, :info)";
                    $stmt_ev = $pdo->prepare($sql_ev);
                }
                $stmt_ev->bindParam(':pid_ev_crud', $programacao_id, PDO::PARAM_INT);
                $stmt_ev->bindParam(':seq', $sequencia_post, PDO::PARAM_INT);
                $stmt_ev->bindParam(':lid', $linha_atual_id_post, $linha_atual_id_post ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt_ev->bindParam(':nte', $numero_tabela_evento_post, PDO::PARAM_STR);
                $stmt_ev->bindParam(':we', $workid_eventos_post, PDO::PARAM_STR);
                $stmt_ev->bindParam(':locid', $local_id_post, PDO::PARAM_INT);
                $stmt_ev->bindParam(':hc', $horario_chegada_db, $horario_chegada_db ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt_ev->bindParam(':hs', $horario_saida_db, $horario_saida_db ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt_ev->bindParam(':info', $info_post, PDO::PARAM_STR);

                if ($stmt_ev->execute()) {
                    $_SESSION['admin_success_message'] = "Evento do diário de bordo salvo com sucesso!";
                    $redirect_url_after_action = "eventos_diario_gerenciar.php?programacao_id=" . $programacao_id . "&nome_bloco=" . urlencode($bloco_info['work_id']) . "&dia_tipo=" . urlencode($bloco_info['dia_semana_tipo']);
                    header("Location: " . $redirect_url_after_action);
                    exit;
                } else { $_SESSION['admin_error_message'] = "Erro ao salvar evento."; }
            } catch (PDOException $e_ev_op) { $_SESSION['admin_error_message'] = "Erro DB ao salvar evento: " . $e_ev_op->getMessage(); }
        }
    } else {
        $_SESSION['admin_form_error_evento'] = implode("<br>", $erros_form_evento);
        // Repopular campos para o formulário se houver erro
        $evento_id_edicao = $evento_id_post; // Mantém o ID se estava editando
        $sequencia_form = $sequencia_post; $linha_atual_id_form = $linha_atual_id_post;
        $numero_tabela_evento_form = $numero_tabela_evento_post; $workid_eventos_form = $workid_eventos_post;
        $local_id_form = $local_id_post; $horario_chegada_form = $horario_chegada_post_str;
        $horario_saida_form = $horario_saida_post_str; $info_form = $info_post;
        $modo_edicao_evento = (bool)$evento_id_post;
    }
}

// --- Se uma ação de EDIÇÃO de evento foi solicitada via GET (para preencher o formulário) ---
// Esta lógica precisa vir DEPOIS do processamento do POST para não sobrescrever os dados do POST em caso de erro de validação.
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['acao_evento_edit']) && $_GET['acao_evento_edit'] == 'editar' && isset($_GET['evento_id_edit']) && filter_var($_GET['evento_id_edit'], FILTER_VALIDATE_INT)) {
    if ($pode_gerenciar_eventos) {
        $evento_id_para_preencher_form = (int)$_GET['evento_id_edit'];
        if ($pdo) {
            try {
                $stmt_ev_ed_get = $pdo->prepare("SELECT * FROM diario_bordo_eventos WHERE id = :eid_get AND programacao_id = :pid_ev_ed_get");
                $stmt_ev_ed_get->bindParam(':eid_get', $evento_id_para_preencher_form, PDO::PARAM_INT);
                $stmt_ev_ed_get->bindParam(':pid_ev_ed_get', $programacao_id, PDO::PARAM_INT);
                $stmt_ev_ed_get->execute();
                $evento_data_db_get = $stmt_ev_ed_get->fetch(PDO::FETCH_ASSOC);

                if ($evento_data_db_get) {
                    $modo_edicao_evento = true;
                    $evento_id_edicao = $evento_id_para_preencher_form; // ID para o campo hidden
                    $sequencia_form = $evento_data_db_get['sequencia'];
                    $linha_atual_id_form = $evento_data_db_get['linha_atual_id'];
                    $numero_tabela_evento_form = $evento_data_db_get['numero_tabela_evento'];
                    $workid_eventos_form = $evento_data_db_get['workid_eventos'];
                    $local_id_form = $evento_data_db_get['local_id'];
                    $horario_chegada_form = $evento_data_db_get['horario_chegada'] ? date('H:i', strtotime($evento_data_db_get['horario_chegada'])) : '';
                    $horario_saida_form = $evento_data_db_get['horario_saida'] ? date('H:i', strtotime($evento_data_db_get['horario_saida'])) : '';
                    $info_form = $evento_data_db_get['info'];
                } else { $_SESSION['admin_warning_message'] = "Evento não encontrado para edição."; }
            } catch (PDOException $e_get_edit) { $_SESSION['admin_error_message'] = "Erro ao carregar evento para edição."; }
        }
    } else { $_SESSION['admin_error_message'] = "Apenas administradores podem preparar a edição de eventos."; }
}


// --- Buscar Eventos Existentes para o Bloco Atual (sempre executa para mostrar a lista) ---
$eventos_do_bloco = [];
if ($pdo) {
    try {
        $stmt_eventos = $pdo->prepare(
            "SELECT dbe.*, l.numero as numero_linha_evento, loc.nome as nome_local_evento 
             FROM diario_bordo_eventos dbe
             LEFT JOIN linhas l ON dbe.linha_atual_id = l.id
             LEFT JOIN locais loc ON dbe.local_id = loc.id
             WHERE dbe.programacao_id = :pid_eventos 
             ORDER BY dbe.sequencia ASC, dbe.horario_chegada ASC, dbe.horario_saida ASC"
        );
        $stmt_eventos->bindParam(':pid_eventos', $programacao_id, PDO::PARAM_INT);
        $stmt_eventos->execute();
        $eventos_do_bloco = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e_get_ev) {
        $_SESSION['admin_error_message'] = "Erro ao carregar eventos do diário de bordo: " . $e_get_ev->getMessage();
    }
}

$url_base_pagina_eventos = "eventos_diario_gerenciar.php?programacao_id=" . $programacao_id . "&nome_bloco=" . urlencode($bloco_info['work_id']) . "&dia_tipo=" . urlencode($bloco_info['dia_semana_tipo']);
$link_voltar_pesquisa_eventos = 'eventos_diario_pesquisar.php?busca_tabela_work_id='.urlencode($bloco_info['work_id']).'&busca_dia_tipo='.urlencode($bloco_info['dia_semana_tipo']).'&pesquisar_bloco_submit=1';

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <a href="<?php echo $link_voltar_pesquisa_eventos; ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-search"></i> Voltar para Pesquisa de Blocos
    </a>
</div>

<?php
if (isset($_SESSION['admin_success_message'])) { echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_success_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_success_message']); }
if (isset($_SESSION['admin_error_message'])) { echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_error_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_error_message']); }
if (isset($_SESSION['admin_warning_message'])) { echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_warning_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_warning_message']); }
if (isset($_SESSION['admin_form_error_evento'])) { echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . nl2br($_SESSION['admin_form_error_evento']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['admin_form_error_evento']); }
?>

<?php if ($pode_gerenciar_eventos): ?>
<div class="card mt-4 mb-4">
    <div class="card-header">
        <?php echo $modo_edicao_evento ? 'Editando Evento (Sequência: ' . htmlspecialchars($sequencia_form) . ')' : 'Adicionar Novo Evento ao Diário de Bordo'; ?>
    </div>
    <div class="card-body">
        <form action="<?php echo $url_base_pagina_eventos; ?>" method="POST" id="form-evento-diario">
            <?php if ($modo_edicao_evento && $evento_id_edicao): ?>
                <input type="hidden" name="evento_id_hidden" value="<?php echo $evento_id_edicao; ?>">
            <?php endif; ?>
            <input type="hidden" name="programacao_id_form" value="<?php echo $programacao_id; ?>">

            <div class="form-row">
                <div class="form-group col-md-1">
                    <label for="sequencia">Seq. <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" id="sequencia" name="sequencia" value="<?php echo htmlspecialchars($sequencia_form); ?>" min="0" required placeholder="Ex: 0">
                </div>
                <div class="form-group col-md-2">
                    <label for="linha_atual_id">Linha</label>
                    <select class="form-control form-control-sm select2-eventos" id="linha_atual_id" name="linha_atual_id" data-placeholder="Selecione Linha...">
                        <option value="">Nenhuma</option>
                        <?php foreach($lista_linhas_eventos_select as $linha_ev): ?>
                            <option value="<?php echo $linha_ev['id']; ?>" <?php echo ($linha_atual_id_form == $linha_ev['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($linha_ev['numero'] . ($linha_ev['nome'] ? ' - ' . $linha_ev['nome'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label for="numero_tabela_evento">Tabela (Evento)</label>
                    <input type="text" class="form-control form-control-sm" id="numero_tabela_evento" name="numero_tabela_evento" value="<?php echo htmlspecialchars($numero_tabela_evento_form); ?>" maxlength="20" placeholder="Nº Tabela">
                </div>
                <div class="form-group col-md-2">
                    <label for="workid_eventos">WorkID (Evento)</label>
                    <input type="text" class="form-control form-control-sm" id="workid_eventos" name="workid_eventos" value="<?php echo htmlspecialchars($workid_eventos_form); ?>" maxlength="50" placeholder="WorkID Específico">
                </div>
                <div class="form-group col-md-3">
                    <label for="local_id">Local <span class="text-danger">*</span></label>
                    <select class="form-control form-control-sm select2-eventos" id="local_id" name="local_id" required data-placeholder="Selecione Local...">
                        <option value="">Selecione...</option>
                        <?php foreach($lista_locais_eventos_select as $local_ev): ?>
                            <option value="<?php echo $local_ev['id']; ?>" <?php echo ($local_id_form == $local_ev['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($local_ev['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-2">
                    <label for="horario_chegada">Chegada</label>
                    <input type="time" class="form-control form-control-sm" id="horario_chegada" name="horario_chegada" value="<?php echo htmlspecialchars($horario_chegada_form); ?>">
                </div>
                <div class="form-group col-md-2">
                    <label for="horario_saida">Saída</label>
                    <input type="time" class="form-control form-control-sm" id="horario_saida" name="horario_saida" value="<?php echo htmlspecialchars($horario_saida_form); ?>">
                </div>
                <div class="form-group col-md-6">
                    <label for="info">Informações Adicionais</label>
                    <input type="text" class="form-control form-control-sm" id="info" name="info" value="<?php echo htmlspecialchars($info_form); ?>" maxlength="255" placeholder="Ex: Início Operação, Fim Operação, Intervalo...">
                </div>
                <div class="form-group col-md-2 d-flex align-items-end">
                    <button type="submit" name="salvar_evento_diario" class="btn btn-primary btn-sm btn-block">
                        <i class="fas fa-save"></i> <?php echo $modo_edicao_evento ? 'Atualizar Evento' : 'Adicionar Evento'; ?>
                    </button>
                </div>
            </div>
             <?php if ($modo_edicao_evento): ?>
                <a href="<?php echo $url_base_pagina_eventos; ?>" class="btn btn-secondary btn-sm mt-2">Cancelar Edição / Adicionar Novo</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; // Fim do if $pode_gerenciar_eventos para o formulário ?>


<h4 class="mt-4">Eventos Cadastrados no Diário de Bordo</h4>
<?php if (empty($eventos_do_bloco)): ?>
    <p class="text-info">Nenhum evento cadastrado para este Bloco ainda.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered table-sm table-hover" id="tabela-eventos-diario">
            <thead class="thead-light">
                <tr>
                    <th>Seq.</th>
                    <th>Linha</th>
                    <th>Tabela (Ev.)</th>
                    <th>WorkID (Ev.)</th>
                    <th>Local</th>
                    <th>Chegada</th>
                    <th>Saída</th>
                    <th>Info</th>
                    <?php if($pode_gerenciar_eventos): ?>
                    <th style="width: 100px;">Ações</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventos_do_bloco as $evento): ?>
                <tr>
                    <td><?php echo htmlspecialchars($evento['sequencia']); ?></td>
                    <td><?php echo htmlspecialchars($evento['numero_linha_evento'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($evento['numero_tabela_evento'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($evento['workid_eventos'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($evento['nome_local_evento']); ?></td>
                    <td><?php echo $evento['horario_chegada'] ? date('H:i', strtotime($evento['horario_chegada'])) : '-'; ?></td>
                    <td><?php echo $evento['horario_saida'] ? date('H:i', strtotime($evento['horario_saida'])) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($evento['info'] ?: '-'); ?></td>
                    <?php if($pode_gerenciar_eventos): ?>
                    <td class="action-buttons">
                        <a href="<?php echo $url_base_pagina_eventos; ?>&acao_evento_edit=editar&evento_id_edit=<?php echo $evento['id']; ?>" class="btn btn-primary btn-xs" title="Editar Evento"><i class="fas fa-edit"></i></a>
                        <a href="<?php echo $url_base_pagina_eventos; ?>&acao_evento_del=apagar&evento_id_del=<?php echo $evento['id']; ?>&token_del_ev=<?php /* echo uniqid(); */?>" class="btn btn-danger btn-xs" title="Apagar Evento" onclick="return confirm('Tem certeza que deseja apagar este evento (Sequência: <?php echo htmlspecialchars($evento['sequencia']); ?>)?');"><i class="fas fa-trash-alt"></i></a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>


<?php
ob_start();
?>
<script>
$(document).ready(function() {
    $('.select2-eventos').each(function() {
        $(this).select2({
            theme: 'bootstrap4',
            placeholder: $(this).data('placeholder') || 'Selecione...',
            allowClear: true,
            width: '100%'
        });
    });

    // Validação básica do formulário de evento (pode ser aprimorada)
    $('#form-evento-diario').on('submit', function(e) {
        var seq = $('#sequencia').val();
        var local = $('#local_id').val();
        
        if (seq === '' || isNaN(parseInt(seq)) || parseInt(seq) < 0) {
            alert('A Sequência é obrigatória e deve ser um número não negativo.');
            $('#sequencia').focus();
            e.preventDefault(); return false;
        }
        if (local === '' || local === null) {
            alert('O Local é obrigatório.');
            // Como é Select2, focar pode não funcionar bem, mas alertar é o principal
            e.preventDefault(); return false;
        }
        // Adicionar mais validações se necessário (ex: linha obrigatória dependendo do WorkID)
    });

    // Lógica futura para edição inline e botão "+"
    // Para edição inline:
    // $('#tabela-eventos-diario td[data-editavel="true"]').on('dblclick', function() { ... });
    // Para botão "+":
    // $('#btn-adicionar-linha-evento').on('click', function() { ... clonar linha de formulário ... });
});
</script>
<?php
$page_specific_js = ob_get_clean();
require_once 'admin_footer.php';
?>