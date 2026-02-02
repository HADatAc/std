(function ($, Drupal) {
  Drupal.behaviors.componentAjaxTrigger = {
    attach: function (context, settings) {
      $('.instrument-component-ajax', context).off('change').on('change', function (e) {
        //console.log('Checkbox alterado:', $(this).val());

        const element = $(this);
        const ajaxSettings = {
          url: window.location.href,
          event: 'change',
          wrapper: element.attr('data-container-id'),
          progress: { type: 'throbber', message: null }
        };

        const ajaxInstance = new Drupal.Ajax(false, element[0], ajaxSettings);

        ajaxInstance.execute()
          .done(function () {
            // console.log('AJAX concluído com sucesso.');
          })
          .fail(function (jqXHR, textStatus, errorThrown) {
            console.error('AJAX Error:', textStatus, errorThrown);
          });
      });
    }
  };
})(jQuery, Drupal);

(function ($, Drupal) {
  // As soon as any jQuery‑UI dialog opens, remove all ".button" classes inside it.
  $(document).on('dialogopen', function (event) {
    var $dialog = $(event.target).closest('.ui-dialog');
    // Remove the extra “button” class from anything in this dialog.
    $dialog.find('.button').removeClass('button');
  });
})(jQuery, Drupal);
