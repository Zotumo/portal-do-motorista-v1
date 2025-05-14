// script.js (Completo v4 - Corrigido erro de sintaxe)

$(document).ready(function() {
	
	    // ==============================================
    // === LÓGICA PARA CARREGAR MENSAGENS via AJAX ===
    // ==============================================

    // Guarda o seletor da div onde as mensagens serão exibidas
    // (Certifique-se que 'placeholderDetalhes' não está sendo redeclarado se já existir)
    // Se já declarou 'placeholderDetalhes' antes, pode reutilizar ou usar outro nome.
    // Vamos usar um nome específico para clareza:
    const placeholderMensagens = $('#mensagens-lista');
    let mensagensCarregadas = false; // Flag para controlar se já carregou (opcional)

    // Função que faz a chamada AJAX para buscar e exibir mensagens
    function carregarMensagens() {
        // Se não achou o placeholder, não faz nada
        if (!placeholderMensagens || !placeholderMensagens.length) {
            console.error("Elemento #mensagens-lista não encontrado!");
            return;
        }

        // Opcional: Se quiser carregar apenas uma vez, descomente a linha abaixo
        // if (mensagensCarregadas) { console.log("Mensagens já carregadas."); return; }

        // Mostra mensagem de carregando
        placeholderMensagens.html("<p class='text-info p-3'><i class='fas fa-spinner fa-spin'></i> Carregando mensagens...</p>");

        $.ajax({
            url: 'parts/listar_mensagens.php', // Caminho para o script PHP que busca as mensagens
            method: 'GET',                     // Pode ser GET, pois só estamos buscando dados
            dataType: 'html',                  // Esperamos receber HTML como resposta
            success: function(response) {
                console.log("Sucesso AJAX Carregar Mensagens");
                placeholderMensagens.html(response); // Coloca o HTML retornado na div
                // mensagensCarregadas = true; // Marca como carregado (se usar a flag)
            },
            error: function(jqXHR, textStatus) {
                 console.error("Erro AJAX ao carregar mensagens:", textStatus);
                 placeholderMensagens.html("<p class='text-danger p-3'>Erro ao carregar mensagens ("+ textStatus +").</p>");
            }
        });
    }

    // Listener para o evento 'shown.bs.tab' da aba de Mensagens
    // Este evento é disparado pelo Bootstrap DEPOIS que a aba se torna visível
    $('a[data-toggle="tab"][href="#mensagens-content"]').on('shown.bs.tab', function (e) {
        console.log('Aba Mensagens foi mostrada, chamando carregarMensagens()...');
        carregarMensagens(); // Chama a função para buscar e exibir as mensagens
    });
	
    // --- Listener ATUALIZADO para Marcar Mensagem Como Lida (ao clicar no CABEÇALHO) ---
    // Escuta cliques no container #mensagens-lista, mas só age em elementos com a classe .mensagem-nao-lida
    $('#mensagens-lista').on('click', '.mensagem-nao-lida', function() {
        const $mensagemHeader = $(this); // O cabeçalho/trigger que foi clicado
        const msgId = $mensagemHeader.data('msg-id');

        if (!msgId) return; // Sai se não tem ID

        console.log("Clicou em msg não lida ID:", msgId, "- Tentando marcar como lida...");

        // Importante: Remove a classe IMEDIATAMENTE para evitar múltiplos cliques/AJAX calls
        // enquanto a requisição está em andamento. O Bootstrap ainda vai expandir/recolher.
        $mensagemHeader.removeClass('mensagem-nao-lida list-group-item-info'); // Remove classe de clique e destaque visual

        // Chamada AJAX para marcar como lida no backend
        $.ajax({
            url: 'parts/marcar_mensagem_lida.php', // Script PHP que faz o UPDATE
            method: 'POST',
            data: { msg_id: msgId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    console.log("Msg ID:", msgId, "marcada como lida com sucesso via AJAX.");
                    // Atualiza a aparência do CABEÇALHO da mensagem na tela
                    $mensagemHeader.find('.msg-summary').removeClass('font-weight-bold').addClass('font-weight-normal'); // Remove negrito
                    $mensagemHeader.find('.msg-status').html('<i class="fas fa-check-circle text-success"></i> Lida'); // Atualiza status visual no header

                    // Atualiza também o status dentro do corpo da mensagem (que vai expandir/já expandiu)
                    const collapseBodyId = $mensagemHeader.data('target'); // Pega o ID do corpo (ex: #collapse-msg-12)
                    if (collapseBodyId) {
                        $(collapseBodyId).find('.msg-status-detail').html('<i class="fas fa-check-circle text-success"></i> Lida agora'); // Atualiza status no detalhe
                    }

                    // Decrementa o contador no badge da aba
                    const $badge = $('#mensagens-count-badge'); // ID do badge na aba
                    if ($badge.length) {
                        let count = parseInt($badge.text()) || 0;
                        if (count > 0) {
                            count--;
                            $badge.text(count);
                            if (count === 0) { $badge.hide(); }
                        }
                    }
                } else {
                     console.warn("Backend não marcou msg ID:", msgId, "como lida. Resposta:", response.message);
                     // Poderia adicionar a classe de volta se falhou, mas pode causar loop se o erro persistir
                     // $mensagemHeader.addClass('mensagem-nao-lida list-group-item-info');
                     alert("Erro ao marcar mensagem como lida no servidor."); // Ou feedback mais sutil
                }
            },
            error: function(jqXHR, textStatus) {
                console.error("Erro AJAX ao marcar mensagem como lida:", textStatus);
                // Readiciona a classe para permitir nova tentativa se a comunicação falhou
                 $mensagemHeader.addClass('mensagem-nao-lida list-group-item-info');
                alert("Erro de comunicação ao marcar mensagem. Tente novamente.");
            }
        });
        // A ação de expandir/recolher é feita automaticamente pelo Bootstrap via data-toggle/data-target
    });
    // --- Fim Listener Marcar Lida ---

    // ==============================================
    // === FIM LÓGICA MENSAGENS =====================
    // ==============================================

    // --- Variáveis Globais Dentro do Ready ---
    // Define a variável UMA VEZ e CORRETAMENTE
    const placeholderDetalhes = $('#daily-details-placeholder');

    // --- Funções Auxiliares ---
    function rolarParaResultados() { if ($("#resultados-busca").length) { $('html, body').animate({ scrollTop: $("#resultados-busca").offset().top - 70 }, 800); } }
    function limparBuscaPublica() { $("#linha-error-msg").text(''); $("#workid-error-msg").text(''); $("#conteudo-resultados").html(''); $("#resultados-busca").hide(); }
    function htmlspecialchars(str) { if (typeof str !== 'string') return ''; return $('<div />').text(str).html(); }

    // --- Filtro de Input Numérico ---
    $('#numeroLinha, #workid').on('input', function() { this.value = this.value.replace(/\D/g, ''); });

    // --- Handlers Busca Pública ---
    $("#form-busca-linha").submit(function(event) {
        event.preventDefault(); limparBuscaPublica(); let numeroLinha = $("#numeroLinha").val().trim();
        if (!/^\d{3}$/.test(numeroLinha)) { $("#linha-error-msg").text("A Linha deve conter 3 números."); return; }
        $("#resultados-busca").show(); $("#conteudo-resultados").html("<p class='text-info p-3'>Buscando linha " + htmlspecialchars(numeroLinha) + "...</p>");
        $.ajax({ url: 'buscar_horario.php', method: 'POST', data: { tipo_busca: 'linha', valor: numeroLinha }, dataType: 'html',
            success: function(response) { $("#conteudo-resultados").html(''); $("#resultados-busca").show(); $("#conteudo-resultados").html(response); rolarParaResultados(); },
            error: function(jqXHR, textStatus) { limparBuscaPublica(); $("#linha-error-msg").text("Erro: "+ textStatus); } });
    });
    $("#form-busca-workid").submit(function(event) {
        event.preventDefault(); limparBuscaPublica(); let workId = $("#workid").val().trim();
        if (!/^\d{7,8}$/.test(workId)) { $("#workid-error-msg").text("WorkID deve ter 7 ou 8 números."); return; }
        $("#resultados-busca").show(); $("#conteudo-resultados").html("<p class='text-info p-3'>Buscando WorkID " + htmlspecialchars(workId) + "...</p>");
        $.ajax({ url: 'buscar_horario.php', method: 'POST', data: { tipo_busca: 'workid', valor: workId }, dataType: 'html',
            success: function(response) { $("#conteudo-resultados").html(''); $("#resultados-busca").show(); $("#conteudo-resultados").html(response); rolarParaResultados(); },
            error: function(jqXHR, textStatus) { limparBuscaPublica(); $("#workid-error-msg").text("Erro: "+ textStatus); } });
    });

    // ==========================================================
    // === LÓGICA PARA CARREGAR DETALHES DA MINHA ESCALA via AJAX ===
    // ==========================================================
    function carregarDetalhesEscala(dataSelecionada, workID) {
        if (!placeholderDetalhes.length) { console.error("Placeholder #daily-details-placeholder não encontrado!"); return; }
        if (!dataSelecionada || !/^\d{4}-\d{2}-\d{2}$/.test(dataSelecionada)) { placeholderDetalhes.html("<p class='text-warning p-3'>Data inválida.</p>"); return; }
        const dataFormatadaUser = dataSelecionada.split('-').reverse().join('/');

        if (typeof workID === 'string' && workID.toUpperCase() === 'FOLGA') { placeholderDetalhes.html("<p class='text-success font-weight-bold p-3'><i class='fas fa-bed'></i> FOLGA ("+ dataFormatadaUser +").</p>"); return; }
        if (workID == '0000000') { placeholderDetalhes.html('<p class="text-muted p-3">Nenhum detalhe para mostrar.</p>'); return; }
        if (workID === '00000000') { placeholderDetalhes.html('<p class="text-muted p-3">Nenhum detalhe para mostrar.</p>'); return; }
        if (!workID) { placeholderDetalhes.html('<p class="text-muted p-3">Nenhum detalhe para mostrar.</p>'); return; }

        placeholderDetalhes.html("<p class='text-info p-3'><i class='fas fa-spinner fa-spin'></i> Carregando WorkID " + htmlspecialchars(workID) + "...</p>");
        $.ajax({ url: 'buscar_horario.php', method: 'POST', data: { tipo_busca: 'workid', valor: workID }, dataType: 'html',
            success: function(response) { console.log("Sucesso AJAX Detalhes WorkID:", workID); placeholderDetalhes.html(response); placeholderDetalhes.find('.nav-pills a:first, .nav-tabs a:first').tab('show'); }, // Ativa a primeira aba do conteúdo carregado
            error: function(jqXHR, textStatus) { console.error("Erro AJAX Detalhes Escala:", textStatus); placeholderDetalhes.html("<p class='text-danger p-3'>Erro ao carregar detalhes ("+ textStatus +").</p>"); }
        });
    }

    // --- Listener para clique nas LINHAS do Resumo Semanal ---
    $('#minha-escala').on('click', 'tr.escala-row', function() {
        $('#minha-escala tr.escala-row').removeClass('table-active'); $(this).addClass('table-active');
        const clickedWorkId = $(this).data('workid'); const clickedDate = $(this).data('date');
        console.log("Clicou escala:", clickedWorkId, clickedDate);
        if (clickedDate) { carregarDetalhesEscala(clickedDate, clickedWorkId); } // Passa workId mesmo se for null ou FOLGA, a função trata
        else { placeholderDetalhes.html("<p class='text-warning p-3'>Data não identificada.</p>"); }
    });

    // --- Carregamento Inicial dos Detalhes ---
    if (typeof initialSelectedDateContext !== 'undefined' && initialSelectedDateContext) {
        let workIDInicialParaCarregar = null; let linhaInicial = $('#minha-escala tr.escala-row[data-date="' + initialSelectedDateContext + '"]').first();
        if (linhaInicial.length > 0) { workIDInicialParaCarregar = linhaInicial.data('workid'); if (workIDInicialParaCarregar && typeof workIDInicialParaCarregar === 'string' && workIDInicialParaCarregar.toUpperCase() !== 'FOLGA') { linhaInicial.addClass('table-active'); } }
        // Se achou linha ou não, chama a função que vai ou carregar ou mostrar msg de folga/sem escala
        console.log("Carregando detalhes iniciais para:", initialSelectedDateContext, workIDInicialParaCarregar);
         setTimeout(function() { carregarDetalhesEscala(initialSelectedDateContext, workIDInicialParaCarregar); }, 50); // Pequeno delay
    } else {
         placeholderDetalhes.html('<p class="text-muted p-3">Selecione uma data no resumo acima.</p>');
    }

    // --- Código do Zoom (com delegação) ---
    $(document).on('click', '.zoomable-image', function(event) { event.preventDefault(); var imgSrc = $(this).data('imgsrc'); var imgAlt = $(this).find('img').attr('alt'); if (imgSrc) { $('#zoomedImage').attr('src', imgSrc); $('#imageZoomModalLabel').text(imgAlt || 'Imagem Ampliada'); } });
    $('#imageZoomModal').on('hidden.bs.modal', function () { $('#zoomedImage').attr('src', ''); $('#imageZoomModalLabel').text('Imagem Ampliada'); });

    // --- Ativar Aba Baseada na Âncora da URL ---
    var hash = window.location.hash; // Pega a parte # da URL (ex: #senha-content)
    // Verifica se existe uma âncora e se corresponde a um link de aba conhecido
    if (hash && (hash === '#escala-principal-content' || hash === '#mensagens-content' || hash === '#senha-content')) {
        // Encontra o LINK da aba que aponta para essa âncora
        var tabLink = $('.nav-tabs a[href="' + hash + '"]');
        if (tabLink.length) {
             console.log("Ativando aba via hash:", hash);
             // Usa o método .tab('show') do Bootstrap para ativar a aba
             tabLink.tab('show');

             // Opcional: Rolar suavemente para o início da seção de abas
             // para garantir que a aba ativada fique visível
             setTimeout(function() { // Pequeno delay para garantir que a aba foi mostrada
                 if ($(hash).length) { // Verifica se o conteúdo da aba existe
                      $('html, body').animate({
                          scrollTop: $('#minhaEscalaPrincipalTab').offset().top - 70 // Rola para o topo da navegação das abas (ajuste o -70 da navbar)
                      }, 400);
                 }
             }, 150); // Delay de 150ms
        }
    }
    // --- Fim Ativar Aba ---

}); // Fim do document ready