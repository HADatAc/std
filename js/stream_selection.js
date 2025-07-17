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
  var currentTopicUri       = null;

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

    function reloadTopicList() {
    if (!currentStreamUri) {
      return;
    }
    $.getJSON(drupalSettings.std.ajaxUrl, {
      studyUri:  drupalSettings.std.studyUri,
      streamUri: currentStreamUri
    })
    .done(function (data) {
      // injeta só os tópicos
      $('#topic-list-table').html(data.topics);
      // restaura a seleção anterior
      if (currentTopicUri) {
        var $radio = $('#topic-list-table')
          .find('input.topic-radio[value="' + currentTopicUri + '"]');
        if ($radio.length) {
          $radio.prop('checked', true);
        }
      }
    })
    .fail(function () {
      console.warn('Failed to reload topic list.');
    });
  }

  setInterval(reloadTopicList, 20000);

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
            $('#data-files-pager').html(data.filesPager).show();
            $('#topic-files-pager').hide();
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
            $('#topic-files-pager').html(data.filesPager).show();
            $('#data-files-pager').hide();
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
      $('#data-files-pager, #topic-files-pager').hide();
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
        currentTopicUri = topicUri;
        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri,
          topicUri:  topicUri,
          page:      $(this).data('page'),
          pagesize:  $(this).data('pagesize')
        })
        .done(function (data) {
          $('#data-files-table').html(data.files);
          $('#topic-files-pager').html(data.filesPager).show();
          $('#data-files-pager').hide();
          $('#message-stream-table').html(data.messages);

          $('#stream-data-files-container')
            .removeClass('col-md-12').addClass('col-md-7')
            .show();
          $('#message-stream-container')
            .removeClass('col-md-5').addClass('col-md-5')
            .show();

          function updateMessageStream() {
            $.getJSON(drupalSettings.std.latestUrl + topicUri)
              .done(function (upd) {
                var html = upd.messages;

                if (html.trim() === 'Stream topic not found') {
                  html = '<p>Stream Topic not Subscribed</p><p>To subscribe, please press <a href="" class="btn btn-sm btn-green me-1 stream-topic-subscribe" title="Non working button"><i class="fa-solid fa-gears"></i></a>button on above Stream Topic table.</p>';
                }

                $('#message-stream-table').html(html);
              });
          }

          updateMessageStream();
          messageStreamInterval = setInterval(updateMessageStream, 20000);
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
      $(document).off('click.streamFilesPagination')
                 .on('click.streamFilesPagination', '.dpl-files-page', function (e) {
        if (currentTopicUri) {
          return;
        }
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
          $('#data-files-pager').html(data.filesPager).show();
          $('#topic-files-pager').hide();
        })
        .fail(function () {
          showToast('Failed to load files page. Please try again.', 'danger');
        });
      });
    }
  };

  Drupal.behaviors.streamTopicFilesPagination = {
    attach: function (context) {
      $(document).off('click.streamTopicFilesPagination', '.dpl-files-page')
                 .on('click.streamTopicFilesPagination', '.dpl-files-page', function (e) {
        if (!currentTopicUri) {
          return;
        }
        e.preventDefault();
        var page = $(this).data('page');
        var pagesize = $(this).data('pagesize');

        hideAllCards();
        $('#edit-ajax-cards-container').show();
        $('#stream-topic-list-container').show();
        $('#stream-data-files-container')
          .removeClass('col-md-12').addClass('col-md-7')
          .show();
        $('#message-stream-container')
          .removeClass('col-md-12').addClass('col-md-5')
          .show();

        $.getJSON(drupalSettings.std.ajaxUrl, {
          studyUri:  drupalSettings.std.studyUri,
          streamUri: currentStreamUri,
          topicUri:  currentTopicUri,
          page:      page,
          pagesize:  pagesize
        })
        .done(function (data) {
          $('#data-files-table').html(data.files);
          $('#topic-files-pager').html(data.filesPager).show();
          $('#data-files-pager').hide();
        })
        .fail(function () {
          showToast('Failed to load topic files page. Please try again.', 'danger');
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

  Drupal.behaviors.toggleCards = {
    attach: function (context, settings) {
      // RESUMO toggle
      $('#toggleResumo', context)
        .off('click.toggleResumo')
        .on('click.toggleResumo', function (e) {
          e.preventDefault();
          $('#cardsCollapse').slideToggle();
          $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
        });

      $('#toggleAuxAreas', context)
        .off('click.toggleAuxAreas')
        .on('click.toggleAuxAreas', function (e) {
          e.preventDefault();
          $('#cardsAuxCollapse').slideToggle();
          $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
        });


      // DROP‐CARD toggle
      $('#toggleDropCard', context)
      .off('click.toggleDropCard')
      .on('click.toggleDropCard', function (e) {
        e.preventDefault();
        $('#card1-container').slideToggle();
        $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
