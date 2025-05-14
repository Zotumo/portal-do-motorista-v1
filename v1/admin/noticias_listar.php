<?php
// admin/noticias_listar.php

// Passo 1: Autenticação e verificação de sessão/permissões.
require_once 'auth_check.php'; // Define $admin_nivel_acesso_logado

// Passo 2: Verificar permissão específica para ACESSAR a lista de notícias
$niveis_permitidos_ver_lista = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
if (!in_array($admin_nivel_acesso_logado, $niveis_permitidos_ver_lista)) {
    $_SESSION['admin_error_message'] = "Você não tem permissão para acessar a área de gerenciamento de notícias.";
    header('Location: index.php'); // Redireciona para o dashboard se não tiver permissão
    exit;
}

// Passo 3: Conexão com o banco de dados (se não for feita no auth_check ou header)
// No nosso admin_header.php atual, auth_check.php é incluído, que por sua vez já poderia incluir db_config.php.
// Mas para garantir, podemos incluir aqui também. require_once evita inclusões múltiplas.
require_once '../db_config.php';

// Passo 4: Definir o título específico desta página.
$page_title = 'Gerenciar Notícias';

// Passo 5: Incluir o cabeçalho comum do admin.
require_once 'admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <?php
        // Permissão para ADICIONAR nova notícia
        $niveis_permitidos_adicionar = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
        if (in_array($admin_nivel_acesso_logado, $niveis_permitidos_adicionar)):
        ?>
        <a href="noticia_formulario.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Adicionar Nova Notícia
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
// Exibir mensagens de feedback (sucesso/erro)
if (isset($_SESSION['admin_success_message'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_success_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    unset($_SESSION['admin_success_message']);
}
if (isset($_SESSION['admin_error_message'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['admin_error_message']) . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    unset($_SESSION['admin_error_message']);
}
?>

<div class="table-responsive">
    <table class="table table-striped table-sm table-hover">
        <thead class="thead-light">
            <tr>
                <th>ID</th>
                <th>Título</th>
                <th>Status</th>
                <th>Data Publicação</th>
                <th>Última Modificação</th>
                <th style="width: 150px;">Ações</th> </tr>
        </thead>
        <tbody>
            <?php
            if ($pdo) {
                try {
                    // Ordena para mostrar as mais recentes primeiro
                    $stmt = $pdo->query("SELECT id, titulo, status, data_publicacao, data_modificacao FROM noticias ORDER BY COALESCE(data_modificacao, data_publicacao) DESC, id DESC");
                    $noticias = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if ($noticias) {
                        foreach ($noticias as $noticia) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($noticia['id']) . "</td>";
                            echo "<td>" . htmlspecialchars($noticia['titulo']) . "</td>";
                            echo "<td><span class='status-" . htmlspecialchars(strtolower($noticia['status'])) . "'>" . htmlspecialchars(ucfirst($noticia['status'])) . "</span></td>";
                            echo "<td>" . ($noticia['data_publicacao'] ? date('d/m/Y H:i', strtotime($noticia['data_publicacao'])) : 'Não publicada') . "</td>";
                            echo "<td>" . ($noticia['data_modificacao'] ? date('d/m/Y H:i', strtotime($noticia['data_modificacao'])) : '-') . "</td>";
                            echo "<td class='action-buttons'>";

                            // Botão VER no portal (sempre visível se o usuário pode ver a lista)
                            echo "<a href='../ver_noticia.php?id=" . $noticia['id'] . "' class='btn btn-info btn-sm' title='Ver no Portal' target='_blank'><i class='fas fa-eye'></i></a> ";

                            // Botão EDITAR (Verifica permissão)
                            $niveis_permitidos_editar = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
                            if (in_array($admin_nivel_acesso_logado, $niveis_permitidos_editar)) {
                                echo "<a href='noticia_formulario.php?id=" . $noticia['id'] . "' class='btn btn-primary btn-sm' title='Editar'><i class='fas fa-edit'></i></a> ";
                            }

                            // Botão APAGAR (Verifica permissão)
                            // Lembre-se de definir esta lista no script noticia_apagar.php também!
                            $niveis_permitidos_apagar = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador']; // Exemplo de quem pode apagar
                            if (in_array($admin_nivel_acesso_logado, $niveis_permitidos_apagar)) {
                                echo "<a href='noticia_apagar.php?id=" . $noticia['id'] . "' class='btn btn-danger btn-sm' title='Apagar' onclick='return confirm(\"Tem certeza que deseja apagar a notícia ID: " . $noticia['id'] . " - Título: " . htmlspecialchars(addslashes($noticia['titulo'])) . "? Esta ação não pode ser desfeita.\");'><i class='fas fa-trash-alt'></i></a>";
                            }
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='text-center'>Nenhuma notícia encontrada.</td></tr>";
                    }
                } catch (PDOException $e) {
                    echo "<tr><td colspan='6' class='text-danger text-center'>Erro ao buscar notícias: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                }
            } else {
                 echo "<tr><td colspan='6' class='text-danger text-center'>Falha na conexão com o banco de dados.</td></tr>";
            }
            ?>
        </tbody>
    </table>
</div>
<?php
// Passo 6: Incluir o rodapé comum do admin.
require_once 'admin_footer.php';
?>