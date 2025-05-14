<?php
// admin/admin_header.php
// ATUALIZADO: Reintroduzido item "Gerenciar Tabelas" e ajustada ordem com "Relatórios".

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($admin_id_logado) || !isset($admin_username_logado) || !isset($admin_nivel_acesso_logado)) {
    if (file_exists('auth_check.php')) {
        require_once 'auth_check.php';
    } else {
        header('Location: login.php');
        exit;
    }
}

if (!isset($page_title)) {
    $page_title = 'Admin Portal';
}

// --- Definindo permissões para os itens de menu ---
$niveis_acesso_ver_noticias = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_acesso_ver_mensagens = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_gerenciar_escala_planejada = ['CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_consultar_escala_diaria = ['Agente de Terminal', 'Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
// NOVO: Permissão para Gerenciar Tabelas (leitura) - igual a relatórios
$niveis_acesso_ver_tabelas = ['CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_acesso_ver_linhas_rotas = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_acesso_ver_locais = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_acesso_ver_relatorios = ['CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_acesso_ver_motoristas = ['Operacional', 'CIOP Monitoramento', 'Instrutores', 'CIOP Planejamento', 'Supervisores', 'Gerência', 'Administrador'];
$niveis_acesso_ver_usuarios_admin = ['Supervisores', 'Gerência', 'Administrador'];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Portal do Motorista Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="admin_style.css?v=<?php echo file_exists('admin_style.css') ? filemtime('admin_style.css') : time(); ?>">
    <link rel="stylesheet" href="../style.css?v=<?php echo file_exists(dirname(__DIR__) . '/style.css') ? filemtime(dirname(__DIR__) . '/style.css') : time(); ?>">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@x.x.x/dist/select2-bootstrap4.min.css">
</head>
<body>
    <nav class="navbar navbar-expand-md navbar-dark bg-danger fixed-top">
        <a class="navbar-brand" href="index.php"><i class="fas fa-user-shield"></i> Admin Portal</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>" href="index.php">
                        <i class="fas fa-tachometer-alt fa-fw"></i> Dashboard
                    </a>
                </li>
                <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_noticias)): ?>
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php $pags_noticias = ['noticias_listar.php', 'noticia_formulario.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_noticias) ? 'active' : ''; ?>" href="noticias_listar.php">
                        <i class="fas fa-newspaper fa-fw"></i> Gerenciar Notícias
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_mensagens)): ?>
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php $pags_mensagens = ['mensagens_listar.php', 'mensagem_formulario.php', 'mensagem_visualizar.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_mensagens) ? 'active' : ''; ?>" href="mensagens_listar.php">
                        <i class="fas fa-envelope fa-fw"></i> Gerenciar Mensagens
                    </a>
                </li>
                <?php endif; ?>
				<?php if (in_array($admin_nivel_acesso_logado, $niveis_gerenciar_escala_planejada)): ?>
					<li class="nav-item d-md-none nav-admin-main-item">
						<a class="nav-link <?php $pags_esc_plan = ['escala_planejada_listar.php', 'escala_planejada_formulario.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_esc_plan) ? 'active' : ''; ?>" href="escala_planejada_listar.php">
							<i class="fas fa-calendar-alt fa-fw"></i> Escala Planejada
						</a>
					</li>
				<?php endif; ?>
				<?php if (in_array($admin_nivel_acesso_logado, $niveis_consultar_escala_diaria)): ?>
					<li class="nav-item d-md-none nav-admin-main-item">
						<a class="nav-link <?php $pags_esc_diaria = ['escala_diaria_consultar.php', 'escala_diaria_formulario.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_esc_diaria) ? 'active' : ''; ?>" href="escala_diaria_consultar.php">
							<i class="fas fa-calendar-day fa-fw"></i> Escala Diária
						</a>
					</li>
				<?php endif; ?>

                <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_tabelas)): ?>
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php 
                        $pags_tabelas = ['tabelas_hub.php', 'tabela_formulario.php']; // Exemplo de páginas
                        echo in_array(basename($_SERVER['PHP_SELF']), $pags_tabelas) ? 'active' : ''; 
                    ?>" href="tabelas_hub.php"> <i class="fas fa-list fa-fw"></i> Gerenciar Tabelas
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_linhas_rotas)): ?>
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php 
                        $pags_linhas_rotas = ['linhas_listar.php', 'linha_formulario.php', 'rotas_linha_gerenciar.php']; 
                        echo in_array(basename($_SERVER['PHP_SELF']), $pags_linhas_rotas) ? 'active' : ''; 
                    ?>" href="linhas_listar.php">
                        <i class="fas fa-bus-alt fa-fw"></i> Gerenciar Linhas e Rotas
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_locais)): ?>
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php 
                        $pags_locais = ['locais_listar.php', 'local_formulario.php']; 
                        echo in_array(basename($_SERVER['PHP_SELF']), $pags_locais) ? 'active' : ''; 
                    ?>" href="locais_listar.php">
                        <i class="fas fa-map-marker-alt fa-fw"></i> Gerenciar Locais
                    </a>
                </li>
                <?php endif; ?>
                
				<?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_motoristas)): ?>
				<li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php
                        $pags_motoristas = ['motoristas_listar.php', 'motorista_formulario.php']; 
                        echo in_array(basename($_SERVER['PHP_SELF']), $pags_motoristas) ? 'active' : '';
                    ?>" href="motoristas_listar.php">
                        <i class="fas fa-id-card fa-fw"></i> Gerenciar Funcionários
                    </a>
                </li>
				<?php endif; ?>
				<?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_usuarios_admin)):?>
					<li class="nav-item d-md-none nav-admin-main-item">
						<a class="nav-link <?php
							$pags_usuarios_admin = ['usuarios_listar.php', 'usuario_formulario.php'];
							echo in_array(basename($_SERVER['PHP_SELF']), $pags_usuarios_admin) ? 'active' : '';
						?>" href="usuarios_listar.php">
							<i class="fas fa-user-cog fa-fw"></i> Gerenciar Usuários
						</a>
					</li>
				<?php endif; ?>

                <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_relatorios)): ?>
                <li class="nav-item d-md-none nav-admin-main-item">
                    <a class="nav-link <?php 
                        $pags_relatorios = ['relatorios_index.php', 'relatorio_especifico.php']; 
                        echo in_array(basename($_SERVER['PHP_SELF']), $pags_relatorios) ? 'active' : ''; 
                    ?>" href="relatorios_index.php">
                        <i class="fas fa-chart-bar fa-fw"></i> Relatórios 
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ml-auto"> 
                 <li class="nav-item d-md-none nav-admin-main-item"> 
                     <span class="navbar-text">
                         <i class="fas fa-user"></i> <?php echo htmlspecialchars($admin_username_logado); ?>
                         <small>(<?php echo htmlspecialchars($admin_nivel_acesso_logado); ?>)</small>
                     </span>
                 </li>
                 <li class="nav-item"> 
                    <a class="nav-link" href="logout.php" title="Sair">
                        <i class="fas fa-sign-out-alt"></i> <span class="d-md-none">Sair</span>
                    </a>
                 </li>
            </ul>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-none d-md-block bg-light sidebar">
                <div class="sidebar-sticky">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>" href="index.php">
                                <i class="fas fa-tachometer-alt fa-fw"></i> Dashboard
                            </a>
                        </li>
                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_noticias)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php $pags_noticias = ['noticias_listar.php', 'noticia_formulario.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_noticias) ? 'active' : ''; ?>" href="noticias_listar.php">
                                <i class="fas fa-newspaper fa-fw"></i> Gerenciar Notícias
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_mensagens)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php $pags_mensagens = ['mensagens_listar.php', 'mensagem_formulario.php', 'mensagem_visualizar.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_mensagens) ? 'active' : ''; ?>" href="mensagens_listar.php">
                                <i class="fas fa-envelope fa-fw"></i> Gerenciar Mensagens
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_gerenciar_escala_planejada)): ?>
							<li class="nav-item">
								<a class="nav-link <?php $pags_esc_plan = ['escala_planejada_listar.php', 'escala_planejada_formulario.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_esc_plan) ? 'active' : ''; ?>" href="escala_planejada_listar.php">
									<i class="fas fa-calendar-alt fa-fw"></i> Escala Planejada
								</a>
							</li>
						<?php endif; ?>
						<?php if (in_array($admin_nivel_acesso_logado, $niveis_consultar_escala_diaria)): ?>
							<li class="nav-item">
								<a class="nav-link <?php $pags_esc_diaria = ['escala_diaria_consultar.php', 'escala_diaria_formulario.php']; echo in_array(basename($_SERVER['PHP_SELF']), $pags_esc_diaria) ? 'active' : ''; ?>" href="escala_diaria_consultar.php">
									<i class="fas fa-calendar-day fa-fw"></i> Escala Diária
								</a>
							</li>
						<?php endif; ?>

                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_tabelas)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php 
                                $pags_tabelas = ['tabelas_hub.php', 'tabela_formulario.php']; // Exemplo
                                echo in_array(basename($_SERVER['PHP_SELF']), $pags_tabelas) ? 'active' : ''; 
                            ?>" href="tabelas_hub.php"> <i class="fas fa-list fa-fw"></i> Gerenciar Tabelas
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_linhas_rotas)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php 
                                $pags_linhas_rotas = ['linhas_listar.php', 'linha_formulario.php', 'rotas_linha_gerenciar.php']; 
                                echo in_array(basename($_SERVER['PHP_SELF']), $pags_linhas_rotas) ? 'active' : ''; 
                            ?>" href="linhas_listar.php">
                                <i class="fas fa-bus-alt fa-fw"></i> Gerenciar Linhas e Rotas
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_locais)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php 
                                $pags_locais = ['locais_listar.php', 'local_formulario.php']; 
                                echo in_array(basename($_SERVER['PHP_SELF']), $pags_locais) ? 'active' : ''; 
                            ?>" href="locais_listar.php">
                                <i class="fas fa-map-marker-alt fa-fw"></i> Gerenciar Locais
                            </a>
                        </li>
                        <?php endif; ?>
                        
						<?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_motoristas)): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php
                                    $pags_motoristas = ['motoristas_listar.php', 'motorista_formulario.php'];
                                    echo in_array(basename($_SERVER['PHP_SELF']), $pags_motoristas) ? 'active' : '';
                                ?>" href="motoristas_listar.php">
                                    <i class="fas fa-id-card fa-fw"></i> Gerenciar Funcionários
                                </a>
                            </li>
						<?php endif; ?>
						<?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_usuarios_admin)): ?>
							<li class="nav-item">
								<a class="nav-link <?php
									$pags_usuarios_admin = ['usuarios_listar.php', 'usuario_formulario.php'];
									echo in_array(basename($_SERVER['PHP_SELF']), $pags_usuarios_admin) ? 'active' : '';
								?>" href="usuarios_listar.php">
									<i class="fas fa-user-cog fa-fw"></i> Gerenciar Usuários
								</a>
							</li>
						<?php endif; ?>

                        <?php if (in_array($admin_nivel_acesso_logado, $niveis_acesso_ver_relatorios)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php 
                                $pags_relatorios = ['relatorios_index.php', 'relatorio_especifico.php']; 
                                echo in_array(basename($_SERVER['PHP_SELF']), $pags_relatorios) ? 'active' : ''; 
                            ?>" href="relatorios_index.php"> 
                                <i class="fas fa-chart-bar fa-fw"></i> Relatórios
                            </a>
                        </li>
                        <?php endif; ?>
					</ul>

                    <div class="sidebar-user-info">
                         <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-3 mb-1 text-muted" style="font-size: 0.7rem;">
                            <span>LOGADO COMO</span>
                         </h6>
                         <ul class="nav flex-column mb-2">
                            <li class="nav-item px-3">
                                <small>
                                    <i class="fas fa-user fa-fw"></i> <?php echo htmlspecialchars($admin_username_logado); ?><br>
                                    <i class="fas fa-shield-alt fa-fw"></i> <span class="text-muted">(<?php echo htmlspecialchars($admin_nivel_acesso_logado); ?>)</span>
                                </small>
                            </li>
                         </ul>
                    </div>
                </div>
            </nav>

            <main role="main" class="col-md-9 ml-sm-auto col-lg-10 px-md-4 main-content">