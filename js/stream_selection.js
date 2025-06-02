/**
 * @file
 * JavaScript behaviors for the Manage Study page (Drupal 10):
 *  - streamSelection: toggle/select-stream rows and load the two cards via AJAX
 *  - dplFileIngest: handle ingest / uningest buttons with toasts
 *  - streamDataFileDelete: handle delete within the Stream Data Files card and re-load it
 */

(function ($, Drupal, drupalSettings) {
  'use strict';
  var messageStreamInterval = null;
  var currentStreamUri = null;

  /**
   * Behavior adicional: Download file when clicking on ".download-url"
   */
  Drupal.behaviors.fileDownload = {
    attach: function (context) {
      // Unbind para evitar múltiplas vinculações
      $(document).off('click.fileDownload', '.download-url');

      $(document).on('click.fileDownload', '.download-url', function (e) {
        e.preventDefault();

        // Obtem o caminho de download (por exemplo: "/download-file/…/da")
        var downloadPath = $(this).attr('data-download-url');
        if (!downloadPath) {
          showToast('Download URL not found.', 'danger');
          return;
        }

        // Se começar com "/", consideramos que já é um caminho absoluto no domínio atual.
        // Caso contrário, prefixamos com baseUrl.
        var fullUrl = (downloadPath.charAt(0) === '/')
          ? downloadPath
          : (drupalSettings.path.baseUrl + downloadPath);

        // Redireciona o navegador para iniciar o download.
        window.location.href = fullUrl;
      });
    }
  };

  /**
   * Helper to show a Bootstrap toast in the page's toast-container.
   *
   * @param {string} message
   *   The text to display.
   * @param {string} type
   *   One of 'success', 'danger', 'warning', etc.
   */
  function showToast(message, type) {
    var toastId = 'toast-' + Date.now();
    var $toast = $(
      '<div id="' + toastId + '" class="toast align-items-center text-white bg-' + type + '" ' +
        'role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">' +
        '<div class="d-flex">' +
          '<div class="toast-body">' + message + '</div>' +
          '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
        '</div>' +
      '</div>'
    );
    $('#toast-container').append($toast);
    new bootstrap.Toast(document.getElementById(toastId)).show();
  }

  /**
   * Behavior #1: Stream selection
   *
   * On click of a radio button in the streams table, load the appropriate
   * cards via AJAX. If the selected streamType is 'files', only the
   * Stream Data Files card is shown (no periodic message loading). Otherwise,
   * show both cards and poll for new messages every 20 seconds.
   */
  Drupal.behaviors.streamSelection = {
    attach: function (context) {
      var $table = $('#dpl-streams-table', context);
      if ($table.data('bound-stream-selection')) {
        return;
      }
      $table.data('bound-stream-selection', true);

      var lastRadio = null;

      // Hide both cards (reset state).
      function hideCards() {
        $('#stream-data-files-container, #message-stream-container')
          .removeClass('col-md-6 col-md-12')
          .hide();
      }

      // Load only the messages for a given streamUri.
      function loadMessages(streamUri) {
        console.log('[DEBUG] loadMessages called with streamUri =', streamUri, new Date().toISOString());
        if (!streamUri) {
          return;
        }
        // console.time('[DEBUG] loadMessages AJAX time');

        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: streamUri
        })
        .done(function (data) {
          // console.timeEnd('[DEBUG] loadMessages AJAX time');
          // console.log('[DEBUG] loadMessages response data:', data);

          // Only update the messages table.
          $('#message-stream-table').html(data.messages);

          var type = (data.streamType || '').toLowerCase().trim();
          // console.log('[DEBUG] loadMessages streamType =', type);

          // If the streamType has changed to 'files', stop polling.
          if (type === 'files') {
            clearInterval(messageStreamInterval);
            messageStreamInterval = null;
            $('#stream-data-files-container')
              .removeClass('col-md-6').addClass('col-md-12').show();
            $('#message-stream-container').hide();
          }
          else {
            // If still a message-based stream, ensure both cards remain visible.
            $('#stream-data-files-container')
              .removeClass('col-md-12').addClass('col-md-6').show();
            $('#message-stream-container')
              .removeClass('col-md-12').addClass('col-md-6').show();
          }
        })
        .fail(function () {
          // console.timeEnd('[DEBUG] loadMessages AJAX time');
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      }

      $table.on('click', 'input[type=radio]', function () {
        // console.log('[DEBUG] radio click detected:', this.value, new Date().toISOString());
        var radio = this;

        // If the same radio is clicked again, unselect and hide cards.
        if (radio === lastRadio) {
          $(radio).prop('checked', false);
          lastRadio = null;
          clearInterval(messageStreamInterval);
          messageStreamInterval = null;
          hideCards();
          return;
        }

        // Otherwise, select the new radio.
        lastRadio = radio;
        $(radio).prop('checked', true);

        currentStreamUri = radio.value;
        // console.log('[DEBUG] currentStreamUri set to:', currentStreamUri);

        // Measure how long the initial AJAX call takes.
        // console.time('[DEBUG] initial AJAX time');
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri
        })
        .done(function (data) {
          // console.timeEnd('[DEBUG] initial AJAX time');
          // console.log('[DEBUG] initial AJAX response data:', data);

          // Populate both tables immediately.
          $('#data-files-table').html(data.files);
          $('#data-files-pager').html(data.filesPager);
          $('#message-stream-table').html(data.messages);

          var type = (data.streamType || '').toLowerCase().trim();
          // console.log('[DEBUG] streamType received =', type);

          if (type === 'files') {
            // If this is a file-based stream, show only the files container.
            $('#stream-data-files-container')
              .removeClass('col-md-6').addClass('col-md-12').show();
            $('#message-stream-container').hide();

            // Ensure any existing polling is stopped.
            clearInterval(messageStreamInterval);
            messageStreamInterval = null;
          }
          else {
            // If this is a message-based stream, show both containers.
            $('#stream-data-files-container')
              .removeClass('col-md-12').addClass('col-md-6').show();
            $('#message-stream-container')
              .removeClass('col-md-12').addClass('col-md-6').show();

            // Clear any previous interval before setting a new one.
            if (messageStreamInterval) {
              clearInterval(messageStreamInterval);
            }
            messageStreamInterval = setInterval(function () {
              loadMessages(currentStreamUri);
            }, 20000);
          }
        })
        .fail(function () {
          // console.timeEnd('[DEBUG] initial AJAX time');
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      });

      // Initially hide both cards on page load.
      hideCards();
    }
  };

  /**
   * Behavior #2: Ingest / Uningest file buttons
   *
   * Handle clicks on ingest/uningest buttons, send AJAX request, and show toast.
   */
  Drupal.behaviors.dplFileIngest = {
    attach: function (context) {
      if ($(context).data('bound-dpl-file-ingest')) {
        return;
      }
      $(context).data('bound-dpl-file-ingest', true);

      var ingestUrl   = drupalSettings.std.fileIngestUrl;
      var uningestUrl = drupalSettings.std.fileUningestUrl;

      $(document)
        .off('click.dplFileIngest', '.ingest-button, .uningest-button')
        .on('click.dplFileIngest', '.ingest-button, .uningest-button', function (e) {
          e.preventDefault();
          var $btn     = $(this);
          var uri      = $btn.data('elementuri');
          var isIngest = $btn.hasClass('ingest-button');
          var url      = isIngest ? ingestUrl : uningestUrl;

          $.ajax({
            url: url,
            type: 'GET',
            data: { elementuri: uri },
            dataType: 'json'
          })
          .done(function (resp) {
            showToast(resp.message, resp.status === 'success' ? 'success' : 'danger');
          })
          .fail(function (xhr) {
            var msg = xhr.responseJSON?.message || 'An unexpected error occurred.';
            showToast(msg, 'danger');
          });
        });
    }
  };

  // Flag to ensure delete behavior binds only once
  var streamDataFileDeleteBound = false;

  /**
   * Behavior #3: Delete a Stream Data File and refresh that card
   *
   * When the delete button is clicked, confirm, delete via AJAX, show toast,
   * then reload the appropriate card based on the currently selected stream.
   */
  Drupal.behaviors.streamDataFileDelete = {
    attach: function () {
      if (streamDataFileDeleteBound) {
        return;
      }
      streamDataFileDeleteBound = true;

      $(document)
        .off('click.streamDelete', '.delete-stream-file-button')
        .on('click.streamDelete', '.delete-stream-file-button', function (e) {
          e.preventDefault();
          if (!confirm('Are you sure you want to delete this stream data file?')) {
            return;
          }

          var $btn = $(this);
          var url  = $btn.data('url');

          $.post(url, {}, function (resp) {
            if (resp.status === 'success') {
              showToast(resp.message || 'File deleted successfully!', 'success');

              var selected = $('#dpl-streams-table input[type=radio]:checked').val();
              if (!selected) {
                $('#stream-data-files-container, #message-stream-container').hide();
                return;
              }

              // Reload the card for the currently selected stream.
              $.getJSON(drupalSettings.std.ajaxUrl, {
                studyUri:  drupalSettings.std.studyUri,
                streamUri: selected
              })
              .done(function (data) {
                $('#data-files-table').html(data.files);
                $('#data-files-pager').html(data.filesPager);
                $('#message-stream-table').html(data.messages);

                var type = (data.streamType || '').toLowerCase().trim();
                if (type === 'files') {
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
                showToast('Failed to refresh stream data files card.', 'danger');
              });
            }
            else {
              showToast(resp.message || 'Error deleting the stream data file.', 'danger');
            }
          }, 'json')
          .fail(function () {
            showToast('Communication error while deleting the stream data file.', 'danger');
          });
        });
    }
  };

})(jQuery, Drupal, drupalSettings);
