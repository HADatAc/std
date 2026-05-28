(function ($, Drupal, once) {
  const applyFilters = function (root) {
    const selected = Array.from(root.querySelectorAll('.study-variable-checkbox:checked')).map((el) => el.value);
    const logicInput = root.querySelector('input[name="std-search-logic"]:checked');
    const logic = logicInput ? logicInput.value : 'or';
    const emptyState = root.querySelector('#std-study-empty-state');

    const cards = Array.from(root.querySelectorAll('.std-study-card'));
    let visibleCount = 0;

    cards.forEach((card) => {
      const tagsRaw = card.getAttribute('data-tags') || '';
      const tags = tagsRaw.split('|').filter(Boolean);

      // Studies are shown only when at least one variable is selected.
      let visible = false;
      if (selected.length > 0) {
        if (logic === 'and') {
          visible = selected.every((tag) => tags.includes(tag));
        }
        else {
          visible = selected.some((tag) => tags.includes(tag));
        }
      }

      card.style.display = visible ? '' : 'none';
      if (visible) {
        visibleCount += 1;
      }
    });

    const counter = root.querySelector('#std-visible-results');
    if (counter) {
      counter.textContent = String(visibleCount);
    }

    if (emptyState) {
      if (selected.length === 0) {
        emptyState.textContent = 'Select at least one variable to display studies.';
        emptyState.style.display = '';
      }
      else if (visibleCount === 0) {
        emptyState.textContent = 'No studies match the selected filters.';
        emptyState.style.display = '';
      }
      else {
        emptyState.style.display = 'none';
      }
    }
  };

  Drupal.behaviors.stdStudyVariableSearch = {
    attach: function (context) {
      once('std-study-variable-search', '#std-study-variable-search', context).forEach(function (root) {
        const checkboxes = root.querySelectorAll('.study-variable-checkbox');
        const radios = root.querySelectorAll('input[name="std-search-logic"]');
        const clearButton = root.querySelector('#std-search-clear');

        checkboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            applyFilters(root);
          });
        });

        radios.forEach((radio) => {
          radio.addEventListener('change', function () {
            applyFilters(root);
          });
        });

        if (clearButton) {
          clearButton.addEventListener('click', function () {
            checkboxes.forEach((checkbox) => {
              checkbox.checked = false;
            });
            const orRadio = root.querySelector('input[name="std-search-logic"][value="or"]');
            if (orRadio) {
              orRadio.checked = true;
            }
            applyFilters(root);
          });
        }

        applyFilters(root);
      });
    },
  };
})(jQuery, Drupal, once);
