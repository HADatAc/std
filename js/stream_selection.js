/**
 * @file
 * JavaScript behaviors for the Manage Study page (Drupal 10):
 *  - fileDownload: download files when clicking on ".download-url"
 *  - showToast: display Bootstrap toasts
 *  - streamSelection: handle initial stream selection and show only the topic list or files
 *  - streamTopicSelection: handle selecting a topic to load messages and files
 *  - dplFileIngest: handle ingest / uningest buttons with toasts
 *  - streamDataFileDelete: handle deleting a data file and refreshing the view
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  // Holds the polling interval ID for message updates.
  var messageStreamInterval = null;
  // The URI of the currently selected stream.
  var currentStreamUri = null;

  /**
   * Behavior: Download file when clicking on ".download-url"
   */
  Drupal.behaviors.fileDownload = {
    attach: function (context) {
      // Unbind previous handlers to avoid duplicates
      $(document).off('click.fileDownload', '.download-url');

      $(document).on('click.fileDownload', '.download-url', function (e) {
        e.preventDefault();

        // Read the download path from data attribute
        var downloadPath = $(this).attr('data-download-url');
        if (!downloadPath) {
          showToast('Download URL not found.', 'danger');
          return;
        }

        // If path is relative, prefix with baseUrl
        var fullUrl = downloadPath.charAt(0) === '/'
          ? downloadPath
          : (drupalSettings.path.baseUrl + downloadPath);

        // Trigger browser download
        window.location.href = fullUrl;
      });
    }
  };

  /**
   * Helper: Show a Bootstrap toast in the page's toast-container.
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
   * Behavior: Stream selection
   *
   * On clicking a radio in the streams table:
   *  - If streamType === 'files': show only the data-files card.
   *  - Otherwise: show only the topic-list card (no files/messages yet).
   *  - In both cases, stop any existing polling.
   */
  Drupal.behaviors.streamSelection = {
    attach: function (context) {
      var $table = $('#dpl-streams-table', context);
      if ($table.data('bound-stream-selection')) {
        return;
      }
      $table.data('bound-stream-selection', true);

      var lastRadio = null;

      // Utility: hide all AJAX-loaded cards
      function hideCards() {
        $('#stream-topic-list-container').hide();
        $('#edit-ajax-cards-container').hide();
        $('#stream-data-files-container').hide();
        $('#message-stream-container').hide();
      }

      $table.on('click', 'input[type=radio]', function () {
        var radio = this;

        // If clicking the same radio again, unselect and hide everything
        if (radio === lastRadio) {
          $(radio).prop('checked', false);
          lastRadio = null;
          clearInterval(messageStreamInterval);
          messageStreamInterval = null;
          hideCards();
          return;
        }

        // Select new stream
        lastRadio = radio;
        $(radio).prop('checked', true);
        currentStreamUri = radio.value;
        hideCards();

        // AJAX: load topic list (and get streamType)
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri
        })
        .done(function (data) {
          // Populate topic list
          $('#topic-list-table').html(data.topics);
          $('#edit-ajax-cards-container').show();

          var type = (data.streamType || '').toLowerCase().trim();

          // Stop any ongoing polling
          clearInterval(messageStreamInterval);
          messageStreamInterval = null;

          if (type === 'files') {
            // File stream → show files only
            $('#stream-data-files-container').show();
            $('#stream-data-files-container').hide();
            $('#message-stream-container').hide();
          }
          else {
            // Message/topic stream → show only topic list for now
            $('#stream-topic-list-container').show();
            $('#stream-data-files-container').hide();
            $('#message-stream-container').hide();
          }
        })
        .fail(function () {
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      });

      // Hide all AJAX cards on initial page load
      hideCards();
    }
  };

  /**
   * Behavior: Topic selection within a non-file stream
   *
   * On clicking a radio in the topic list:
   *  - Load both data-files and messages for that topic via AJAX.
   *  - Show both cards.
   *  - Start polling messages every 20 seconds.
   */
  Drupal.behaviors.streamTopicSelection = {
    attach: function (context) {
      // Unbind and rebind on document to catch dynamically loaded radios
      $(document).off('click.streamTopicSelection', '.topic-radio');
      $(document).on('click.streamTopicSelection', '.topic-radio', function () {
        var topicUri = this.value;

        // Stop previous polling
        clearInterval(messageStreamInterval);
        messageStreamInterval = null;

        // AJAX: load files + messages for the selected topic
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri,
          topicUri:  topicUri
        })
        .done(function (data) {
          // Populate files and messages tables
          $('#data-files-table').html(data.files);
          $('#data-files-pager').html(data.filesPager);
          $('#message-stream-table').html(data.messages);

          // Show both cards
          $('#stream-data-files-container').show();
          $('#message-stream-container').show();

          // Start polling messages every 20 seconds
          messageStreamInterval = setInterval(function () {
            $.getJSON(drupalSettings.std.ajaxUrl, {
              studyUri:  drupalSettings.std.studyUri,
              streamUri: currentStreamUri,
              topicUri:  topicUri
            })
            .done(function (upd) {
              $('#message-stream-table').html(upd.messages);
            });
          }, 20000);
        })
        .fail(function () {
          showToast('Failed to load topic data. Please try again.', 'danger');
        });
      });
    }
  };

  /**
   * Behavior: Ingest / Uningest file buttons
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
   * Behavior: Delete a Stream Data File and refresh that card
   *
   * When the delete button is clicked, confirm, delete via AJAX, show toast,
   * then reload the current stream & topic tables.
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

              var selectedStream = $('#dpl-streams-table input[type=radio]:checked').val();
              var selectedTopic  = $('#topic-list-table input[type=radio]:checked').val();

              if (!selectedStream || !selectedTopic) {
                $('#stream-topic-list-container').hide();
                $('#stream-data-files-container').hide();
                $('#message-stream-container').hide();
                return;
              }

              // Reload the current topic
              $.getJSON(drupalSettings.std.ajaxUrl, {
                studyUri:  drupalSettings.std.studyUri,
                streamUri: selectedStream,
                topicUri:  selectedTopic
              })
              .done(function (data) {
                $('#data-files-table').html(data.files);
                $('#data-files-pager').html(data.filesPager);
                $('#message-stream-table').html(data.messages);
                $('#topic-list-table').html(data.topics);
                $('#edit-ajax-cards-container').show();
                $('#stream-topic-list-container').show();
                $('#stream-data-files-container').show();
                $('#message-stream-container').show();
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
