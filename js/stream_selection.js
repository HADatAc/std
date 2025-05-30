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
   */
  Drupal.behaviors.streamSelection = {
    attach: function (context) {
      var $table = $('#dpl-streams-table', context);
      if ($table.data('bound-stream-selection')) {
        return;
      }
      $table.data('bound-stream-selection', true);

      var lastRadio = null;
      function hideCards() {
        $('#stream-data-files-container, #message-stream-container')
          .removeClass('col-md-6 col-md-12')
          .hide();
      }

      function loadMessages(streamUri) {
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: streamUri
        })
        .done(function (data) {
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
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      }

      $table.on('click', 'input[type=radio]', function () {
        var radio = this;
        if (radio === lastRadio) {
          $(radio).prop('checked', false);
          lastRadio = null;
          hideCards();
          return;
        }
        lastRadio = radio;
        $(radio).prop('checked', true);

        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: radio.value
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

          if (messageStreamInterval) {
            clearInterval(messageStreamInterval);
          }
          messageStreamInterval = setInterval(function () {
            loadMessages(currentStreamUri);
          }, 20000);
                    
        })
        .fail(function () {
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      });

      hideCards();
    }
  };

  /**
   * Behavior #2: Ingest / Uningest file buttons
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

              $.getJSON(drupalSettings.std.ajaxUrl, {
                studyUri:  drupalSettings.std.studyUri,
                streamUri: selected
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
