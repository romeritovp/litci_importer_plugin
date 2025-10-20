jQuery(document).ready(function ($) {
    // Botão "Carregar"
    $('#litci-load').on('click', function () {
        const endpoint = $('#litci-endpoint').val();
        $('#litci-results').html('<p>🔄 Carregando...</p>');

        $.post(litciImporter.ajaxUrl, {
            action: 'litci_fetch_posts',
            endpoint: endpoint,
            nonce: litciImporter.nonce
        }, function (response) {
            if (response.success) {
                $('#litci-results').html(response.data.html);
            } else {
                $('#litci-results').html('<p style="color:red;">' + response.data.message + '</p>');
            }
        }).fail(function () {
            $('#litci-results').html('<p style="color:red;">Erro na conexão com o servidor.</p>');
        });
    });

    // Botão "Importar"
    $(document).on('click', '.litci-import-btn', function () {
        const id = $(this).data('id');
        const endpoint = $(this).data('endpoint');
        const btn = $(this);

        btn.prop('disabled', true).text('Importando...');

        $.post(litciImporter.ajaxUrl, {
            action: 'litci_import_post',
            id: id,
            endpoint: endpoint,
            nonce: litciImporter.nonce
        }, function (response) {
            if (response.success) {
                btn.text('✅ Importado');
                if (response.data.edit_url) {
                    // Redireciona o usuário para a página de edição do post
                    window.location.href = response.data.edit_url;
                } else {
                    // Se não houver URL de edição, mostra a mensagem normal
                    statusArea.html('<p class="success">' + response.data.message + '</p>');
                }
            } else {
                btn.text('Erro').css('background', '#e74c3c');
                alert(response.data.message);
            }
        }).fail(function () {
            btn.text('Erro').css('background', '#e74c3c');
        });
    });
});