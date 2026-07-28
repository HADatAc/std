(function (Drupal, once, drupalSettings) {
  'use strict';

  function appendModeBadge(form) {
    var settings = drupalSettings.stdManageStudy || {};
    var modeLabel = String(settings.modeLabel || '').trim();
    if (!modeLabel) {
      return;
    }

    var pageTitle = null;
    var candidates = document.querySelectorAll('h1, h2, .page-title');
    candidates.forEach(function (node) {
      if (pageTitle) {
        return;
      }

      var text = String(node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (text.indexOf('manage study elements') !== -1) {
        pageTitle = node;
      }
    });

    if (!pageTitle) {
      pageTitle = document.querySelector('h1.page-title, .page-title, main h1');
    }

    if (!pageTitle || pageTitle.querySelector('.std-manage-mode-badge, .std-title-mode-badge')) {
      return;
    }

    var isOwner = settings.isOwner === true;
    var badge = document.createElement('span');
    badge.className = 'std-manage-mode-badge ' + (isOwner ? 'std-manage-mode-edit' : 'std-manage-mode-view');
    badge.textContent = modeLabel;
    pageTitle.appendChild(badge);
  }

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
      once('std-manage-study-badge', 'body', context).forEach(function () {
        appendModeBadge(document);

        var titleRetries = 0;
        var titleTimer = window.setInterval(function () {
          appendModeBadge(document);
          titleRetries += 1;
          if (titleRetries >= 20) {
            window.clearInterval(titleTimer);
          }
        }, 250);
      });

      once('std-manage-study-fix', '.manage-study-form', context).forEach(function (form) {
        enforceVisibleCollapse(form);
        appendModeBadge(form);

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
})(Drupal, once, drupalSettings);
