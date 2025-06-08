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

  /** Helper: Show a Bootstrap toast. */
  function showToast(message, type) {
    var id    = 'toast-' + Date.now();
    var $toast = $(
      '<div id="' + id + '" class="toast align-items-center text-white bg-' + type + '" ' +
        'role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">' +
        '<div class="d-flex">' +
          '<div class="toast-body">' + message + '</div>' +
          '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
        '</div>' +
      '</div>'
    );
    $('#toast-container').append($toast);
    new bootstrap.Toast(document.getElementById(id)).show();
  }

  /** Utility: hide all AJAX-loaded cards and wrapper. */
  function hideAllCards() {
    $('#edit-ajax-cards-container').hide();
    $('#stream-topic-list-container').hide();
    $('#stream-data-files-container').hide();
    $('#message-stream-container').hide();
  }

  /** Behavior: Download file when clicking on ".download-url". */
  Drupal.behaviors.fileDownload = {
    attach: function (context) {
      $(document).off('click.fileDownload', '.download-url');
      $(document).on('click.fileDownload', '.download-url', function (e) {
        e.preventDefault();
        var path = $(this).data('download-url');
        if (!path) {
          showToast('Download URL not found.', 'danger');
          return;
        }
        var url = path.charAt(0) === '/'
          ? path
          : drupalSettings.path.baseUrl + path;
        window.location.href = url;
      });
    }
  };

  /** Behavior: Stream selection – show either files (full width) or topics. */
  Drupal.behaviors.streamSelection = {
    attach: function (context) {
      var $table = $('#dpl-streams-table', context);
      if ($table.data('bound-stream-selection')) return;
      $table.data('bound-stream-selection', true);

      var lastRadio = null;
      $table.on('click', 'input[type=radio]', function () {
        // Stop polling now.
        clearInterval(messageStreamInterval);
        messageStreamInterval = null;

        // If same clicked twice: unselect and hide all.
        if (this === lastRadio) {
          $(this).prop('checked', false);
          lastRadio = null;
          hideAllCards();
          return;
        }

        lastRadio = this;
        $(this).prop('checked', true);
        currentStreamUri = this.value;
        hideAllCards();

        // Load topics, files and streamType
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri
        })
        .done(function (data) {
          // Always show wrapper
          $('#edit-ajax-cards-container').show();

          var type = (data.streamType || '').toLowerCase().trim();
          if (type === 'files') {
            // FILES ONLY: populate & show files card full-width
            $('#data-files-table').html(data.files);
            $('#data-files-pager').html(data.filesPager);
            $('#message-stream-container').hide();
            $('#stream-topic-list-container').hide();

            $('#stream-data-files-container')
              .removeClass('col-md-6').addClass('col-md-12')
              .show();
          }
          else {
            // MESSAGE/TOPIC: populate & show topic list only
            $('#topic-list-table').html(data.topics);
            $('#stream-topic-list-container').show();
          }
        })
        .fail(function () {
          showToast('Failed to load stream data. Please try again.', 'danger');
        });
      });

      // Initial page load
      hideAllCards();
    }
  };

  /** Behavior: Topic selection – show both files & messages side by side. */
  Drupal.behaviors.streamTopicSelection = {
    attach: function (context) {
      $(document).off('click.streamTopicSelection', '.topic-radio');
      $(document).on('click.streamTopicSelection', '.topic-radio', function () {
        // Stop polling now.
        clearInterval(messageStreamInterval);
        messageStreamInterval = null;

        // Hide everything then keep topics visible
        hideAllCards();
        $('#edit-ajax-cards-container').show();
        $('#stream-topic-list-container').show();

        var topicUri = this.value;
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri,
          topicUri:  topicUri
        })
        .done(function (data) {
          // Populate tables
          $('#data-files-table').html(data.files);
          $('#data-files-pager').html(data.filesPager);
          $('#message-stream-table').html(data.messages);

          // Show both cards half-width
          $('#stream-data-files-container')
            .removeClass('col-md-12').addClass('col-md-7')
            .show();
          $('#message-stream-container')
            .removeClass('col-md-5').addClass('col-md-5')
            .show();

          // Restart polling every 20s
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

  /** Behavior: Ingest / Uningest file buttons. */
  Drupal.behaviors.dplFileIngest = {
    attach: function (context) {
      if ($(context).data('bound-dpl-file-ingest')) return;
      $(context).data('bound-dpl-file-ingest', true);

      var ingestUrl   = drupalSettings.std.fileIngestUrl;
      var uningestUrl = drupalSettings.std.fileUningestUrl;

      $(document)
        .off('click.dplFileIngest', '.ingest-button, .uningest-button')
        .on('click.dplFileIngest', '.ingest-button, .uningest-button', function (e) {
          e.preventDefault();
          var uri = $(this).data('elementuri');
          var url = $(this).hasClass('ingest-button') ? ingestUrl : uningestUrl;
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
            showToast(xhr.responseJSON?.message || 'An unexpected error occurred.', 'danger');
          });
        });
    }
  };

  // Flag for delete behavior
  var streamDataFileDeleteBound = false;

  /** Behavior: Delete a Stream Data File and refresh view. */
  Drupal.behaviors.streamDataFileDelete = {
    attach: function () {
      if (streamDataFileDeleteBound) return;
      streamDataFileDeleteBound = true;

      $(document)
        .off('click.streamDelete', '.delete-stream-file-button')
        .on('click.streamDelete', '.delete-stream-file-button', function (e) {
          e.preventDefault();
          if (!confirm('Are you sure you want to delete this stream data file?')) return;
          var url = $(this).data('url');

          $.post(url, {}, function (resp) {
            showToast(resp.message || 'File deleted successfully!', resp.status === 'success' ? 'success' : 'danger');
            // Re-trigger current view: topic if selected, else stream
            if ($('.topic-radio:checked').length) {
              $('.topic-radio:checked').trigger('click');
            }
            else {
              $('#dpl-streams-table input[type=radio]:checked').trigger('click');
            }
          }, 'json')
          .fail(function () {
            showToast('Communication error while deleting the stream data file.', 'danger');
          });
        });
    }
  };

})(jQuery, Drupal, drupalSettings);
