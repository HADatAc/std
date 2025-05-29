/**
 * @file
 * JavaScript behaviors for the Manage Study page (Drupal 10):
 *  - streamSelection: toggle/select-stream rows and load the two cards via AJAX
 *  - dplFileIngest: handle ingest / uningest buttons with toasts
 *  - streamDataFileDelete: handle delete within the Stream Data Files card and re-load it
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Behavior #1: Stream selection
   *
   * - Allows deselecting a radio by clicking it again.
   * - On new selection, fires an AJAX request (drupalSettings.std.ajaxUrl)
   *   passing studyUri and streamUri.
   * - Populates two cards: Stream Data Files and Message Stream.
   * - Adjusts col-widths: if it's a “file” stream, show only the data-files card;
   *   otherwise show both side by side.
   */
  Drupal.behaviors.streamSelection = {
    attach: function (context, settings) {
      var $table = $('#dpl-streams-table', context);

      // Only bind once per table instance
      if ($table.data('bound-stream-selection')) {
        return;
      }
      $table.data('bound-stream-selection', true);

      // Keep track of the last selected radio for toggle behavior
      var lastRadio = null;

      // Helper to hide both detail cards
      function hideCards() {
        $('#stream-data-files-container, #message-stream-container')
          .removeClass('col-md-6 col-md-12')
          .hide();
      }

      // Attach click handler to radio inputs within the table
      $table.on('click', 'input[type=radio]', function () {
        var radio = this;

        // If clicking the same radio again: deselect + hide cards
        if (radio === lastRadio) {
          $(radio).prop('checked', false);
          lastRadio = null;
          hideCards();
          return;
        }

        // Otherwise, new selection
        lastRadio = radio;
        $(radio).prop('checked', true);

        // AJAX to fetch the files/messages for this stream
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: radio.value
        })
        .done(function (data) {
          // Populate HTML
          $('#data-files-table'  ).html(data.files);
          $('#data-files-pager'  ).html(data.filesPager);
          $('#message-stream-table').html(data.messages);

          // Adjust layout based on streamType
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

      // At initial page load, hide both cards
      hideCards();
    }
  };


  /**
   * Behavior #2: Ingest / Uningest file buttons
   *
   * On click of .ingest-button or .uningest-button:
   *  - fires a GET to the appropriate ingest/uningest URL
   *  - shows a Bootstrap toast with success or failure
   */
  Drupal.behaviors.dplFileIngest = {
    attach: function (context, settings) {
      // Prevent duplicate binding
      if ($(context).data('bound-dpl-file-ingest')) {
        return;
      }
      $(context).data('bound-dpl-file-ingest', true);

      var ingestUrl   = drupalSettings.std.fileIngestUrl;
      var uningestUrl = drupalSettings.std.fileUningestUrl;

      // Delegate on document so dynamically injected buttons are handled
      $(document)
        .off('click.dplFileIngest', '.ingest-button, .uningest-button')
        .on('click.dplFileIngest', '.ingest-button, .uningest-button', function (e) {
          e.preventDefault();
          var $btn     = $(this);
          var uri      = $btn.data('elementuri');
          var isIngest = $btn.hasClass('ingest-button');
          var url      = isIngest ? ingestUrl : uningestUrl;
          var $toastC  = $('#toast-container');

          $.ajax({
            url: url,
            type: 'GET',
            data: { elementuri: uri },
            dataType: 'json'
          })
          .done(function (resp) {
            var $t = $('<div class="toast align-items-center text-white"></div>')
              .addClass(resp.status === 'success' ? 'bg-success' : 'bg-danger')
              .attr('role','alert')
              .html(
                '<div class="d-flex">' +
                  '<div class="toast-body">'+ resp.message +'</div>' +
                  '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
                '</div>'
              );
            $toastC.append($t);
            new bootstrap.Toast($t[0], { delay: 5000 }).show();
          })
          .fail(function (xhr) {
            var msg = xhr.responseJSON?.message || 'An unexpected error occurred.';
            var $t  = $('<div class="toast align-items-center text-white bg-danger"></div>')
              .attr('role','alert')
              .html(
                '<div class="d-flex">' +
                  '<div class="toast-body">'+ msg +'</div>' +
                  '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
                '</div>'
              );
            $('#toast-container').append($t);
            new bootstrap.Toast($t[0], { delay: 5000 }).show();
          });
        });
    }
  };


  // Global flag to ensure delete behavior binds only once
  var streamDataFileDeleteBound = false;

  /**
   * Behavior #3: Delete a Stream Data File and refresh that card
   *
   * - Listens for clicks on .delete-stream-file-button anywhere on the page.
   * - On success, re-fires the same AJAX used in streamSelection to re-render
   *   only the Stream Data Files (and hide message-stream if needed).
   */
  Drupal.behaviors.streamDataFileDelete = {
    attach: function (context, settings) {
      if (streamDataFileDeleteBound) {
        return;
      }
      streamDataFileDeleteBound = true;

      // Namespaced .off()/.on() to prevent multiple bindings
      $(document)
        .off('click.streamDataFileDelete', '.delete-stream-file-button')
        .on('click.streamDataFileDelete', '.delete-stream-file-button', function (e) {
          e.preventDefault();
          if (!confirm('Are you sure you want to delete this stream data file?')) {
            return;
          }

          var $btn = $(this);
          var url  = $btn.data('url');

          // POST to the delete endpoint, expect JSON { status: "...", message?: "..." }
          $.post(url, {}, function (resp) {
            if (resp.status === 'success') {
              // Re-load the Stream Data Files card just like a radio click
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
                $('#data-files-table'  ).html(data.files);
                $('#data-files-pager'  ).html(data.filesPager);
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
                alert('Failed to refresh stream data files card.');
              });
            }
            else {
              alert(resp.message || 'Error deleting the stream data file.');
            }
          }, 'json')
          .fail(function () {
            alert('Communication error while deleting the stream data file.');
          });
        });
    }
  };

})(jQuery, Drupal, drupalSettings);
