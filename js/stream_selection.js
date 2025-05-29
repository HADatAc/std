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

      function hideCards() {
        $('#stream-data-files-container')
          .removeClass('col-md-6 col-md-12')
          .hide();
        $('#message-stream-container')
          .removeClass('col-md-6 col-md-12')
          .hide();
      }
      hideCards();

      // suporte a desmarcar rádio clicado duas vezes
      table.on('mousedown', 'input[type=radio]', function () {
        $(this).data('wasCheckedOnMouseDown', this.checked);
      });

      table.on('click', 'input[type=radio]', function (e) {
        var $radio = $(this);
        var wasChecked = $radio.data('wasCheckedOnMouseDown') === true;

        if (wasChecked) {
          e.preventDefault();
          e.stopImmediatePropagation();
          var name = $radio.attr('name');
          $radio.removeAttr('name')
                .prop('checked', false)
                .data('waschecked', false)
                .closest('tr').removeClass('selected')
                .attr('name', name);
          hideCards();
          return false;
        }

        // nova seleção
        table.find('input[type=radio]')
          .data('waschecked', false)
          .closest('tr').removeClass('selected');

        $radio.prop('checked', true)
              .data('waschecked', true)
              .closest('tr').addClass('selected');

        // AJAX para carregar os cards
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: $radio.val()
        })
        .done(function (data) {
          $('#data-files-table').html(data.files);
          $('#data-files-pager').html(data.filesPager);
          $('#message-stream-table').html(data.messages);

          var type = (data.streamType || '').toLowerCase();
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
