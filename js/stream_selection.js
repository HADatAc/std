(function ($, Drupal, drupalSettings) {
  /**
   * 1) STREAM SELECTION
   */
  Drupal.behaviors.streamSelection = {
    attach: function (context, settings) {
      var table = $('#dpl-streams-table', context);
      if (table.data('dpl-bound')) {
        return;
      }
      table.data('dpl-bound', true);

      // mantém referência ao último rádio selecionado
      var lastRadio = null;

      function hideCards() {
        $('#stream-data-files-container, #message-stream-container')
          .removeClass('col-md-6 col-md-12')
          .hide();
      }

      // handler único de click
      table.on('click', 'input[type=radio]', function (e) {
        var radio = this;

        // se clicar de novo no mesmo rádio
        if (radio === lastRadio) {
          // desmarca e esconde tudo
          $(radio).prop('checked', false);
          lastRadio = null;
          hideCards();
          return;
        }

        // caso contrário, é uma nova seleção
        lastRadio = radio;
        $(radio).prop('checked', true);

        // faz o AJAX e mostra os cards
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: radio.value
        })
        .done(function (data) {
          $('#data-files-table'  ).html(data.files);
          $('#data-files-pager'  ).html(data.filesPager);
          $('#message-stream-table').html(data.messages);

          var type = (data.streamType||'').toLowerCase();
          if (type === 'file' || type === 'files') {
            $('#stream-data-files-container')
              .removeClass('col-md-6').addClass('col-md-12').show();
            $('#message-stream-container').hide();
          }
          else {
            $('#stream-data-files-container')
              .removeClass('col-md-12').addClass('col-md-6').show();
            $('#message-stream-container')
              .removeClass('col-md-12').addClass('col-md-6').show();
          }
        })
        .fail(function () {
          alert('Failed to load stream data. Please try again.');
        });
      });

      // inicialmente esconde
      hideCards();
    }
  };

  /**
   * 2) INGEST / UNINGEST
   */
  Drupal.behaviors.dplFileIngest = {
    attach: function (context, settings) {
      var ingestUrl   = drupalSettings.std.fileIngestUrl;
      var uningestUrl = drupalSettings.std.fileUningestUrl;

      // para garantir que não duplica bindings:
      $(document)
        .off('click.dplFileIngest', '.ingest-button, .uningest-button')
        .on('click.dplFileIngest', '.ingest-button, .uningest-button', function (e) {
          e.preventDefault();
          var $btn   = $(this);
          var uri    = $btn.data('elementuri');
          var isIngest = $btn.hasClass('ingest-button');
          var url    = isIngest ? ingestUrl : uningestUrl;
          var $toastC = $('#toast-container');

          $.ajax({
            url: url,
            type: 'GET',
            data: { elementuri: uri },
            dataType: 'json'
          })
          .done(function (resp) {
            var $t = $('<div class="toast align-items-center text-white"></div>');
            $t.addClass(resp.status === 'success' ? 'bg-success' : 'bg-danger')
              .attr('role','alert')
              .html('<div class="d-flex"><div class="toast-body">'+resp.message+'</div>'
                   +'<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>');
            $toastC.append($t);
            var bs = new bootstrap.Toast($t[0], { delay: 5000 });
            bs.show();
            // se quiser, desabilitar botão após sucesso:
            // if (resp.status==='success') { $btn.prop('disabled',true); }
          })
          .fail(function (xhr) {
            var msg = xhr.responseJSON?.message || Drupal.t('Ocorreu um erro inesperado.');
            var $t = $('<div class="toast align-items-center text-white bg-danger"></div>')
              .attr('role','alert')
              .html('<div class="d-flex"><div class="toast-body">'+msg+'</div>'
                   +'<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>');
            $toastC.append($t);
            new bootstrap.Toast($t[0], { delay: 5000 }).show();
          });
        });
    }
  };

})(jQuery, Drupal, drupalSettings);

function attachStreamDataFileEvents() {
  $(document).off("click", ".delete-stream-file-button");
  $(document).on("click", ".delete-stream-file-button", function (e) {
    e.preventDefault();
    var deleteUrl = $(this).data("url");
    if (!confirm("Deseja eliminar este ficheiro de stream?")) {
      return;
    }
    $.ajax({
      url: deleteUrl,
      type: "POST",
      dataType: "json"
    })
    .done(function (resp) {
      if (resp.status === "success") {
        showToast("Ficheiro apagado!", "success");
        // 1) recarrega a tabela de Stream Data Files:
        var sel = $('#dpl-streams-table input[type=radio]:checked').val();
        if (sel) {
          $.getJSON(drupalSettings.std.ajaxUrl, {
            studyUri:  drupalSettings.std.studyUri,
            streamUri: sel
          })
          .done(function (data) {
            $('#data-files-table').html(data.files);
            $('#data-files-pager').html(data.filesPager);
            // (reaplica as mesmas lógicas de show/hide do streamSelection)
            var type = (data.streamType||'').toLowerCase();
            if (type === 'file' || type === 'files') {
              $('#stream-data-files-container')
                .removeClass('col-md-6').addClass('col-md-12').show();
              $('#message-stream-container').hide();
            }
            else {
              $('#stream-data-files-container')
                .removeClass('col-md-12').addClass('col-md-6').show();
              $('#message-stream-container')
                .removeClass('col-md-12').addClass('col-md-6').show();
            }
          });
        }
      }
      else {
        showToast(resp.message || "Erro ao apagar.", "danger");
      }
    })
    .fail(function () {
      showToast("Falha na comunicação.", "danger");
    });
  });
}
