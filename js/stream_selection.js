/**
 * @file
 * JavaScript behaviors for the Manage Study page (Drupal 10):
 *  - fileDownload: download files when clicking on ".download-url"
 *  - showToast: display Bootstrap toasts
 *  - streamSelection: initial stream click → load topics OR files (with pager)
 *  - streamTopicSelection: topic click → load files + messages
 *  - streamFilesPagination: handle clicks on the files-only pager links
 *  - dplFileIngest: ingest / uningest buttons
 *  - streamDataFileDelete: delete a file & refresh
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  var messageStreamInterval = null;
  var currentStreamUri      = null;

  /** Show a Bootstrap toast inside #toast-container */
  function showToast(text, type) {
    var id = 'toast-' + Date.now();
    var $t = $(
      '<div id="' + id + '" class="toast align-items-center text-white bg-' + type +
      '" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">' +
        '<div class="d-flex">' +
          '<div class="toast-body">' + text + '</div>' +
          '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
        '</div>' +
      '</div>'
    );
    $('#toast-container').append($t);
    new bootstrap.Toast(document.getElementById(id)).show();
  }

  /** Hide all of our AJAX cards at once */
  function hideAllCards() {
    $('#edit-ajax-cards-container').hide();
    $('#stream-topic-list-container').hide();
    $('#stream-data-files-container').hide();
    $('#message-stream-container').hide();
  }

  /** Download link behavior */
  Drupal.behaviors.fileDownload = {
    attach: function (context) {
      $(document).off('click.fileDownload', '.download-url')
                 .on('click.fileDownload', '.download-url', function (e) {
        e.preventDefault();
        var p = $(this).data('download-url');
        if (!p) return showToast('Download URL not found.', 'danger');
        window.location.href = p.charAt(0) === '/'
          ? p
          : drupalSettings.path.baseUrl + p;
      });
    }
  };

  /** Stream-row click: load either topic list or files+pager */
  Drupal.behaviors.streamSelection = {
    attach: function (context) {
      var $tbl = $('#dpl-streams-table', context);
      if ($tbl.data('bound-stream-selection')) return;
      $tbl.data('bound-stream-selection', true);

      var last = null;
      $tbl.on('click', 'input[type=radio]', function () {
        clearInterval(messageStreamInterval);
        messageStreamInterval = null;

        // de-select if same clicked
        if (this === last) {
          $(this).prop('checked', false);
          last = null;
          return hideAllCards();
        }
        last = this;
        $(this).prop('checked', true);
        currentStreamUri = this.value;
        hideAllCards();

        // fetch topics/files + streamType
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri
        })
        .done(function (data) {
          $('#edit-ajax-cards-container').show();
          var type = (data.streamType || '').toLowerCase().trim();

          if (type === 'files') {
            // ——— FILES-ONLY STREAM ———
            $('#data-files-table').html(data.files);
            $('#data-files-pager').html(data.filesPager);
            $('#stream-data-files-container')
              .removeClass('col-md-6').addClass('col-md-12')
              .show();
            // hide topics + messages
            $('#stream-topic-list-container, #message-stream-container').hide();
          }
          else {
            // ——— MESSAGE/TOPIC STREAM ———
            $('#topic-list-table').html(data.topics);
            $('#stream-topic-list-container').show();
            // restore half-width if previously changed
            $('#stream-data-files-container')
              .removeClass('col-md-12').addClass('col-md-7');
            $('#message-stream-container')
              .removeClass('col-md-12').addClass('col-md-5');
          }
        })
        .fail(function () {
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      });

      hideAllCards();
    }
  };

  /** Topic-row click: load both files + messages side-by-side */
  Drupal.behaviors.streamTopicSelection = {
    attach: function (context) {
      $(document).off('click.streamTopicSelection', '.topic-radio')
                 .on('click.streamTopicSelection', '.topic-radio', function () {
        clearInterval(messageStreamInterval);
        messageStreamInterval = null;
  
        hideAllCards();
        $('#edit-ajax-cards-container, #stream-topic-list-container').show();
  
        var topicUri = this.value;
  
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri,
          topicUri:  topicUri
        })
        .done(function (data) {
          $('#data-files-table').html(data.files);
          $('#data-files-pager').html(data.filesPager);
          $('#message-stream-table').html(data.messages);
  
          $('#stream-data-files-container')
            .removeClass('col-md-12').addClass('col-md-7')
            .show();
          $('#message-stream-container')
            .removeClass('col-md-5').addClass('col-md-5')
            .show();
  
          if (!window.messageWebSocket) {
            window.messageWebSocket = new WebSocket('ws://127.0.0.1:8081');
  
            window.messageWebSocket.onopen = function () {
              console.log('WS conectado. Subscrição tópico:', topicUri);
              window.messageWebSocket.send(JSON.stringify({ action: 'subscribe', topic: topicUri }));
              window.currentTopic = topicUri;  // Guardar tópico atual
            };
  
            window.messageWebSocket.onmessage = function (event) {
              var data = JSON.parse(event.data);
              if (data.topic === window.currentTopic) {
                var $msgCard = $('<div class="mqtt-card" style="border:1px solid #ccc; margin-bottom:10px; padding:15px; border-radius:8px; background:#fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1); font-family: Arial, sans-serif;"></div>');
  
                if (typeof data.message === 'object') {
                  $msgCard.html('<pre>' + JSON.stringify(data.message, null, 2) + '</pre>');
                } else {
                  $msgCard.text(data.message);
                }
  
                $('#message-stream-table').append($msgCard);
              }
            };
  
            window.messageWebSocket.onerror = function (error) {
              console.error('WS erro:', error);
            };
  
            window.messageWebSocket.onclose = function () {
              console.log('WS fechado');
            };
          } else {
            // Unsubscribe do tópico antigo antes de subscrever o novo
            if (window.currentTopic && window.currentTopic !== topicUri) {
              window.messageWebSocket.send(JSON.stringify({ action: 'unsubscribe', topic: window.currentTopic }));
            }
  
            window.currentTopic = topicUri;
  
            // Limpar mensagens antigas antes de receber novas
            $('#message-stream-table').empty();
  
            window.messageWebSocket.send(JSON.stringify({ action: 'subscribe', topic: topicUri }));
            console.log('WS já aberto. Mudando tópico para:', topicUri);
          }
        })
        .fail(function () {
          showToast('Failed to load topic data. Please try again.', 'danger');
        });
      });
    }
  };

  /**
   * Files-only pagination clicks.
   * Delegated so it works on newly-injected links.
   */
  Drupal.behaviors.streamFilesPagination = {
    attach: function (context) {
      $(document).off('click.streamFilesPagination', '.dpl-files-page')
                 .on('click.streamFilesPagination', '.dpl-files-page', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        hideAllCards();
        $('#edit-ajax-cards-container, #stream-data-files-container')
          .removeClass('col-md-6').addClass('col-md-12')
          .show();

        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri,
          page:      page
        })
        .done(function (data) {
          $('#data-files-table').html(data.files);
          $('#data-files-pager').html(data.filesPager);
        })
        .fail(function () {
          showToast('Failed to load files page. Please try again.', 'danger');
        });
      });
    }
  };

  /** Ingest / Uningest file buttons */
  Drupal.behaviors.dplFileIngest = {
    attach: function (context) {
      if ($(context).data('bound-dpl-file-ingest')) return;
      $(context).data('bound-dpl-file-ingest', true);

      var ingestUrl   = drupalSettings.std.fileIngestUrl;
      var uningestUrl = drupalSettings.std.fileUningestUrl;
      $(document).off('click.dplFileIngest', '.ingest-button, .uningest-button')
                 .on('click.dplFileIngest', '.ingest-button, .uningest-button', function (e) {
        e.preventDefault();
        var uri = $(this).data('elementuri');
        var url = $(this).hasClass('ingest-button') ? ingestUrl : uningestUrl;
        $.getJSON(url, { elementuri: uri })
         .done(function (resp) {
           showToast(resp.message, resp.status === 'success' ? 'success' : 'danger');
         })
         .fail(function () {
           showToast('An unexpected error occurred.', 'danger');
         });
      });
    }
  };

  /** Delete a data file & refresh whichever view is active */
  Drupal.behaviors.streamDataFileDelete = {
    attach: function () {
      $(document).off('click.streamDelete', '.delete-stream-file-button')
                 .on('click.streamDelete', '.delete-stream-file-button', function (e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to delete this stream data file?')) return;
        var url = $(this).data('url');
        $.post(url, {}, function (resp) {
          showToast(resp.message || 'Deleted!', resp.status === 'success' ? 'success' : 'danger');
          // re-trigger current view
          if ($('.topic-radio:checked').length) {
            $('.topic-radio:checked').trigger('click');
          } else {
            $('#dpl-streams-table input[type=radio]:checked').trigger('click');
          }
        }, 'json')
        .fail(function () {
          showToast('Failed to delete file.', 'danger');
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
