(function ($, Drupal) {
  Drupal.behaviors.stdInfiniteScroll = {
    attach: function (context, settings) {
      // Verificar se o comportamento já está anexado
      if (window.infiniteScrollInitialized) {
        return;
      }
      window.infiniteScrollInitialized = true; // Define a flag como true para indicar que o comportamento já foi anexado

      let isLoading = false;
      let page = 1;

      function loadMoreItems() {
        if (isLoading) return;
        isLoading = true;

        // Fazer a requisição AJAX usando o sistema padrão do Drupal
        $.ajax({
          url: settings.std_select_study_form.ajaxUrl + '?page=' + (page + 1) + '&element_type=' + settings.std_select_study_form.elementType,
          method: 'GET',
          dataType: 'json',
          success: function (response, status, xhr) {
            if (xhr.status === 200 && response.cards.trim() !== "") {
              // Adiciona os novos cartões ao final do contêiner existente, evitando a duplicação
              $('#cards-wrapper').append(response.cards);
              page++; // Incrementa somente quando há sucesso
            } else {
              // Se não houver mais itens, remova o evento de rolagem
              $(window).off('scroll', onScroll);
            }
            isLoading = false;
          },
          error: function () {
            console.error('Failed to load more items.');
            isLoading = false;
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

      // Vincular o evento de rolagem à janela com debounce
      $(window).on('scroll', debounce(onScroll, 500));
    }
  };
})(jQuery, Drupal);
