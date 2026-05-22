(function ($, Drupal) {
  Drupal.behaviors.stdInfiniteScroll = {
    attach: function (context, settings) {
      if (window.stdInfiniteScrollInitialized) {
        return;
      }

      const cfg = settings.std_select_study_form || null;
      if (!cfg || !cfg.ajaxUrl || !cfg.elementType) {
        return;
      }

      if (!$('#cards-wrapper', context).length) {
        return;
      }

      window.stdInfiniteScrollInitialized = true;

      let isLoading = false;
      let page = 1;
      let stopped = cfg.hasMoreInitial === false;
      let debouncedOnScroll = null;

      const $statusHost = $('#std-select-study-load-status');
      const messages = cfg.messages || {};
      const statusNoMoreText = messages.noMore || 'No more items to load.';
      const statusLoadingText = messages.loadingMore || 'Loading more items...';
      const statusLoadFailedText = messages.loadFailed || 'Could not load more items. Scroll again to retry.';

      const statusNoMore = '<span class="text-muted">' + statusNoMoreText + '</span>';
      const statusLoading = '<div class="d-inline-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>' + statusLoadingText + '</span></div>';
      const statusLoadFailed = '<span class="text-danger">' + statusLoadFailedText + '</span>';

      const $cardsWrapper = $('#cards-wrapper');

      function getAjaxErrorToastHost() {
        const hostId = 'std-ajax-error-toast-host';
        let $host = $('#' + hostId);
        if ($host.length) {
          return $host;
        }

        $host = $('<div/>', { id: hostId }).css({
          position: 'fixed',
          right: '16px',
          bottom: '16px',
          width: 'min(420px, calc(100vw - 24px))',
          zIndex: 20000,
          display: 'flex',
          flexDirection: 'column',
          gap: '10px',
          pointerEvents: 'none'
        });
        $('body').append($host);
        return $host;
      }

      function showAjaxErrorToast(message) {
        const $host = getAjaxErrorToastHost();
        const $toast = $('<div/>').css({
          pointerEvents: 'auto',
          background: '#fff4f4',
          border: '1px solid #f1b8b8',
          borderLeft: '4px solid #d93f3f',
          color: '#7b1f1f',
          borderRadius: '8px',
          boxShadow: '0 10px 24px rgba(0, 0, 0, 0.16)',
          padding: '10px 12px',
          fontSize: '13px',
          lineHeight: '1.4',
          position: 'relative'
        });

        const $close = $('<button/>', {
          type: 'button',
          'aria-label': Drupal.t('Close'),
          text: 'x'
        }).css({
          position: 'absolute',
          top: '6px',
          right: '8px',
          border: 'none',
          background: 'transparent',
          color: '#7b1f1f',
          fontWeight: 700,
          fontSize: '14px',
          cursor: 'pointer',
          padding: 0,
          lineHeight: 1
        });

        const $content = $('<div/>').text(message).css({ paddingRight: '18px' });

        $close.on('click', function () {
          $toast.remove();
        });

        $toast.append($close).append($content);
        $host.append($toast);

        setTimeout(function () {
          $toast.fadeOut(180, function () {
            $toast.remove();
          });
        }, 7000);
      }

      function updateCardsCounter(loaded, total) {
        if (!$('#count-cards').length) {
          return;
        }

        const loadedNum = Number.isFinite(Number(loaded)) ? Number(loaded) : $('#cards-wrapper [id^="card-item-"]').length;
        const totalNum = Number.isFinite(Number(total)) ? Number(total) : loadedNum;
        const label = cfg.pluralClassName || 'items';
        $('#count-cards').text('Currently viewing ' + loadedNum + ' of ' + totalNum + ' ' + label);
      }

      function setStatus(markup) {
        if ($statusHost.length) {
          $statusHost.html(markup);
        }
      }

      function stopInfiniteScroll(withStatus) {
        stopped = true;
        $(window).off('scroll.stdInfiniteScroll');
        if (withStatus) {
          setStatus(statusNoMore);
        }
      }

      function bindScrollHandler() {
        if (!debouncedOnScroll) {
          debouncedOnScroll = debounce(onScroll, 350);
        }
        $(window).off('scroll.stdInfiniteScroll').on('scroll.stdInfiniteScroll', debouncedOnScroll);
      }

      function loadMoreItems() {
        if (isLoading || stopped) return;
        isLoading = true;
        if ($cardsWrapper.length) {
          $cardsWrapper.attr('aria-busy', 'true');
        }
        setStatus(statusLoading);

        $.ajax({
          url: cfg.ajaxUrl + '?page=' + (page + 1) + '&element_type=' + encodeURIComponent(cfg.elementType),
          method: 'GET',
          dataType: 'json',
          success: function (response, status, xhr) {
            const cardsHtml = typeof response.cards === 'string' ? response.cards.trim() : '';

            if (xhr.status === 200 && cardsHtml !== '') {
              $('#cards-wrapper').append(cardsHtml);

              const nextPage = parseInt(response.page, 10);
              page = Number.isNaN(nextPage) ? (page + 1) : nextPage;

              updateCardsCounter(response.loaded, response.total);

              if (response.has_more === false) {
                stopInfiniteScroll(true);
              }
              else {
                setStatus('');
              }
            } else {
              stopInfiniteScroll(true);
            }
            isLoading = false;
            if ($cardsWrapper.length) {
              $cardsWrapper.attr('aria-busy', 'false');
            }
          },
          error: function (xhr) {
            const statusInfo = xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
            showAjaxErrorToast('Could not load more results. Please try again.' + statusInfo);
            setStatus(statusLoadFailed);
            isLoading = false;
            if ($cardsWrapper.length) {
              $cardsWrapper.attr('aria-busy', 'false');
            }
          }
        });
      }

      function debounce(func, wait) {
        let timeout;
        return function () {
          const context = this, args = arguments;
          clearTimeout(timeout);
          timeout = setTimeout(() => func.apply(context, args), wait);
        };
      }

      function onScroll() {
        const scrollThreshold = 20;

        if ($(window).scrollTop() + $(window).height() >= $(document).height() - scrollThreshold) {
          loadMoreItems();
        }
      }

      $(document).off('ajaxComplete.stdInfiniteScroll').on('ajaxComplete.stdInfiniteScroll', function (event, xhr, ajaxSettings) {
        if (!$('#cards-wrapper').length) {
          return;
        }

        let payload = '';
        if (ajaxSettings && ajaxSettings.data) {
          if (typeof ajaxSettings.data === 'string') {
            payload = ajaxSettings.data;
          }
          else {
            payload = $.param(ajaxSettings.data);
          }
        }

        if (payload && payload.indexOf('load_more') === -1 && (payload.indexOf('status_filter') !== -1 || payload.indexOf('manager_filter') !== -1)) {
          page = 1;
          stopped = false;
          isLoading = false;
          setStatus('');
          bindScrollHandler();
        }
      });

      if (stopped) {
        setStatus(statusNoMore);
        return;
      }

      bindScrollHandler();
    }
  };
})(jQuery, Drupal);
