(function (Drupal, once) {
  'use strict';

  function enforceVisibleCollapse(root) {
    var selectors = ['#collapseDescription', '#collapseAreas', '#collapseDropCard', '#collapseWorkflow'];

    selectors.forEach(function (selector) {
      var panel = root.querySelector(selector);
      if (!panel) {
        return;
      }

      // Keep visibility explicit even if external bundles redefine .collapse.
      panel.style.setProperty('visibility', 'visible', 'important');

      var body = panel.querySelector('.accordion-body');
      if (body) {
        body.style.setProperty('visibility', 'visible', 'important');
      }
    });
  }

  Drupal.behaviors.stdManageStudyFix = {
    attach: function (context) {
      once('std-manage-study-fix', '.manage-study-form', context).forEach(function (form) {
        enforceVisibleCollapse(form);

        var observer = new MutationObserver(function () {
          enforceVisibleCollapse(form);
        });

        observer.observe(form, {
          subtree: true,
          childList: true,
          attributes: true,
          attributeFilter: ['class', 'style']
        });

        // Guard the first seconds after lazy-loaded editor styles/scripts.
        var retries = 0;
        var timer = window.setInterval(function () {
          enforceVisibleCollapse(form);
          retries += 1;
          if (retries >= 20) {
            window.clearInterval(timer);
          }
        }, 250);
      });
    }
  };
})(Drupal, once);
