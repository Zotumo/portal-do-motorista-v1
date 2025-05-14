<?php
// footer.php (Versão Limpa e Correta)
?>
    <div class="modal fade" id="loginModal" tabindex="-1" role="dialog" aria-labelledby="loginModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form action="processa_login.php" method="POST">
            <div class="modal-header" style="background-color: var(--cmtu-azul); color: white;">
              <h5 class="modal-title" id="loginModalLabel"><i class="fas fa-sign-in-alt"></i> Login do Motorista</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: white;">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              <div id="login-error-message" class="alert alert-danger" style="display: none;" role="alert">
                <?php
                    // Exibe erro de login da sessão, se existir (variável definida no header.php)
                    if (!empty($login_error_message)) {
                        echo htmlspecialchars($login_error_message);
                        // Faz o modal já abrir via JS se houver erro (ver bloco JS abaixo)
                    }
                ?>
              </div>
              <div class="form-group"> <label for="matriculaInput"><i class="fas fa-id-card"></i> Matrícula</label> <input type="text" class="form-control" id="matriculaInput" name="matricula" placeholder="Digite sua matrícula" required> </div>
              <div class="form-group"> <label for="senhaInput"><i class="fas fa-lock"></i> Senha</label> <input type="password" class="form-control" id="senhaInput" name="senha" placeholder="Digite sua senha" required> </div>
            </div>
            <div class="modal-footer"> <button type="button" class="btn btn-secondary" data-dismiss="modal">Fechar</button> <button type="submit" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Entrar</button> </div>
          </form>
        </div>
      </div>
    </div>
    <div class="modal fade" id="imageZoomModal" tabindex="-1" role="dialog" aria-labelledby="imageZoomModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="imageZoomModalLabel">Imagem Ampliada</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"> <span aria-hidden="true">&times;</span> </button>
          </div>
          <div class="modal-body text-center">
            <img src="" id="zoomedImage" class="img-fluid" alt="Imagem Ampliada">
          </div>
        </div>
      </div>
    </div>
    <footer class="bg-dark text-white text-center py-3 mt-4">
        <p>&copy; <?php echo date("Y"); ?> Portal do Motorista - Londrina</p>
    </footer>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script> <script src="script.js?v=<?php echo filemtime('script.js'); ?>"></script>

    <script>

        // Código para exibir erro de login vindo da sessão PHP
         $(document).ready(function() {
            <?php if (!empty($login_error_message)): ?>
              // Se o PHP encontrou uma mensagem de erro, preenche a div e mostra o modal
              $('#login-error-message').text("<?php echo addslashes($login_error_message); ?>").show();
              // Tenta abrir o modal de login - se der erro aqui, o bootstrap JS não carregou
              try {
                 $('#loginModal').modal('show');
              } catch(e) {
                 console.error("Erro ao tentar abrir o modal de login via JS. Verifique se Bootstrap JS está carregado.", e);
                 // Mostra o erro na div de qualquer forma, caso o modal não abra
                  $('#login-error-message').show();
              }
            <?php endif; ?>

            // Limpar mensagem de erro ao fechar o modal manualmente (listener ainda útil aqui)
            $('#loginModal').on('hidden.bs.modal', function (e) {
              $('#login-error-message').hide().text('');
            });

             // Listener de zoom e limpeza do modal de zoom (se não estiver no script.js)
            // É melhor estar no script.js, mas pode ficar aqui também
            /*
            $(document).on('click', '.zoomable-image', function(event) { ... });
            $('#imageZoomModal').on('hidden.bs.modal', function () { ... });
            */
         });
    </script>

</body>
</html>