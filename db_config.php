<?php
// db_config.php - Configurações de Conexão com o Banco de Dados

// Defina aqui as suas credenciais do banco de dados MySQL
define('DB_HOST', 'localhost');      // Geralmente 'localhost' ou o IP do servidor de banco de dados
define('DB_NAME', 'portal_motorista-v1'); // <<< Substitua pelo nome do seu banco de dados
define('DB_USER', 'root'); // <<< Substitua pelo seu usuário do MySQL
define('DB_PASS', ''); // <<< Substitua pela sua senha do MySQL
define('DB_CHARSET', 'utf8mb4');     // Charset recomendado

// Opções do PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em caso de erros
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna resultados como array associativo
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Desativa a emulação de prepared statements (mais seguro)
];

// DSN (Data Source Name) - String de conexão
$dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;

try {
    // Cria a instância do PDO para a conexão
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    // echo "Conexão bem-sucedida!"; // Descomente para testar a conexão
} catch (\PDOException $e) {
    // Em caso de erro na conexão, exibe uma mensagem genérica e loga o erro real
    // Em produção, você não deve exibir $e->getMessage() diretamente para o usuário
    error_log("Erro de conexão com o banco de dados: " . $e->getMessage()); // Loga o erro
    die("Erro ao conectar com o banco de dados. Por favor, tente mais tarde."); // Mensagem para o usuário
}

// A variável $pdo estará disponível para os scripts que incluírem este arquivo
?>