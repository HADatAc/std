(function ($, Drupal, once) {
  const DEFAULT_WEIGHTS = {
    match: 0.5,
    type: 0.2,
    completeness: 0.3,
  };

  const getWeights = function () {
    const settings = (typeof drupalSettings !== 'undefined' && drupalSettings.stdStudySearch)
      ? drupalSettings.stdStudySearch
      : {};

    const candidate = settings.weights || {};
    const parsed = {
      match: Number(candidate.match),
      type: Number(candidate.type),
      completeness: Number(candidate.completeness),
    };

    const safe = {
      match: Number.isFinite(parsed.match) ? parsed.match : DEFAULT_WEIGHTS.match,
      type: Number.isFinite(parsed.type) ? parsed.type : DEFAULT_WEIGHTS.type,
      completeness: Number.isFinite(parsed.completeness) ? parsed.completeness : DEFAULT_WEIGHTS.completeness,
    };

    const total = safe.match + safe.type + safe.completeness;
    if (!Number.isFinite(total) || total <= 0) {
      return DEFAULT_WEIGHTS;
    }

    return {
      match: safe.match / total,
      type: safe.type / total,
      completeness: safe.completeness / total,
    };
  };

  const splitTags = function (raw) {
    return String(raw || '')
      .split('|')
      .map((item) => item.trim())
      .filter(Boolean);
  };

  const updateSelectedPreview = function (root, selectedVariableChecks, selectedOntologyChecks) {
    const preview = root.querySelector('#std-selected-preview');
    if (!preview) {
      return;
    }

    const selectedItems = [];
    selectedVariableChecks.forEach((checkbox) => {
      selectedItems.push({
        type: 'variable',
        value: checkbox.value,
        label: checkbox.dataset.label || checkbox.value,
      });
    });

    selectedOntologyChecks.forEach((checkbox) => {
      const ontology = checkbox.dataset.ontology || 'ontology';
      selectedItems.push({
        type: 'ontology',
        value: checkbox.value,
        ontology,
        label: `${checkbox.dataset.label || checkbox.value} (${ontology.toUpperCase()})`,
      });
    });

    if (selectedItems.length === 0) {
      preview.innerHTML = '';
      preview.style.display = 'none';
      return;
    }

    const chips = selectedItems
      .map((item) => {
        const typeAttr = item.type === 'ontology' ? ` data-ontology="${item.ontology}"` : '';
        return `<span class="std-selected-chip"><span>${item.label}</span><button type="button" class="std-chip-remove" data-target="${item.type}" data-value="${item.value}"${typeAttr} aria-label="Remove ${item.label}">×</button></span>`;
      })
      .join('');

    preview.innerHTML = `<div class="std-selected-title">Selected filters</div><div class="std-selected-list">${chips}</div>`;
    preview.style.display = '';
  };

  const computeRanking = function (card, selectedTags, selectedSources, weights) {
    const allTags = splitTags(card.dataset.tags);
    const matchedTags = selectedTags.filter((tag) => allTags.includes(tag));
    const matchedCount = matchedTags.length;

    const matchedSources = new Set();
    selectedSources.forEach((source) => {
      const sourceTags = splitTags(card.dataset[`${source}Tags`] || '');
      const hasMatchInSource = selectedTags.some((tag) => sourceTags.includes(tag));
      if (hasMatchInSource) {
        matchedSources.add(source);
      }
    });

    const matchRatio = selectedTags.length > 0 ? matchedCount / selectedTags.length : 0;
    const sourceCoverage = selectedSources.size > 0 ? matchedSources.size / selectedSources.size : 0;
    const completeness = Math.max(0, Math.min(1, Number(card.dataset.completenessScore || 0)));

    const score =
      (matchRatio * weights.match)
      + (sourceCoverage * weights.type)
      + (completeness * weights.completeness);

    return {
      score,
      matchedCount,
      sourceCoverage,
      completeness,
    };
  };

  const applyFilters = function (root) {
    const selectedVariableChecks = Array.from(root.querySelectorAll('.study-variable-checkbox:checked'));
    const selectedOntologyChecks = Array.from(root.querySelectorAll('.std-ontology-checkbox:checked'));

    const selected = selectedVariableChecks.map((el) => el.value);
    const selectedSources = new Set(selectedVariableChecks.map((el) => el.dataset.source || '').filter(Boolean));

    const selectedUberon = selectedOntologyChecks
      .filter((el) => (el.dataset.ontology || '') === 'uberon')
      .map((el) => el.value);
    const selectedNcit = selectedOntologyChecks
      .filter((el) => (el.dataset.ontology || '') === 'ncit')
      .map((el) => el.value);

    const logicInput = root.querySelector('input[name="std-search-logic"]:checked');
    const logic = logicInput ? logicInput.value : 'or';
    const emptyState = root.querySelector('#std-study-empty-state');
    const rankingIndicator = root.querySelector('#std-ranking-indicator');
    const cardsContainer = root.querySelector('#std-study-cards');
    const weights = getWeights();

    const cards = Array.from(root.querySelectorAll('.std-study-card'));
    const visibleCards = [];
    let visibleCount = 0;

    cards.forEach((card) => {
      const tags = splitTags(card.dataset.tags);
      const cardUberonTags = splitTags(card.dataset.uberonTags);
      const cardNcitTags = splitTags(card.dataset.ncitTags);

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

      if (visible && selectedUberon.length > 0) {
        visible = selectedUberon.some((term) => cardUberonTags.includes(term));
      }

      if (visible && selectedNcit.length > 0) {
        visible = selectedNcit.some((term) => cardNcitTags.includes(term));
      }

      if (visible) {
        const ranking = computeRanking(card, selected, selectedSources, weights);
        card.dataset.rankScore = ranking.score.toFixed(6);
        card.dataset.matchedCount = String(ranking.matchedCount);
        visibleCards.push(card);
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

    if (cardsContainer && visibleCards.length > 1) {
      visibleCards.sort((a, b) => {
        const scoreA = Number(a.dataset.rankScore || 0);
        const scoreB = Number(b.dataset.rankScore || 0);
        if (scoreA !== scoreB) {
          return scoreB - scoreA;
        }

        const matchedA = Number(a.dataset.matchedCount || 0);
        const matchedB = Number(b.dataset.matchedCount || 0);
        if (matchedA !== matchedB) {
          return matchedB - matchedA;
        }

        const titleA = (a.querySelector('h4')?.textContent || '').trim();
        const titleB = (b.querySelector('h4')?.textContent || '').trim();
        return titleA.localeCompare(titleB);
      });

      visibleCards.forEach((card) => {
        cardsContainer.appendChild(card);
      });
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

    if (rankingIndicator) {
      if (selected.length > 0 && visibleCount > 0) {
        rankingIndicator.textContent = 'Sorted by relevance';
      }
      else {
        rankingIndicator.textContent = '';
      }
    }

    updateSelectedPreview(root, selectedVariableChecks, selectedOntologyChecks);
  };

  Drupal.behaviors.stdStudyVariableSearch = {
    attach: function (context) {
      once('std-study-variable-search', '#std-study-variable-search', context).forEach(function (root) {
        const checkboxes = root.querySelectorAll('.study-variable-checkbox');
        const ontologyCheckboxes = root.querySelectorAll('.std-ontology-checkbox');
        const radios = root.querySelectorAll('input[name="std-search-logic"]');
        const clearButton = root.querySelector('#std-search-clear');
        const preview = root.querySelector('#std-selected-preview');

        checkboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            applyFilters(root);
          });
        });

        ontologyCheckboxes.forEach((checkbox) => {
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
            ontologyCheckboxes.forEach((checkbox) => {
              checkbox.checked = false;
            });
            const orRadio = root.querySelector('input[name="std-search-logic"][value="or"]');
            if (orRadio) {
              orRadio.checked = true;
            }
            applyFilters(root);
          });
        }

        if (preview) {
          preview.addEventListener('click', function (event) {
            const button = event.target.closest('.std-chip-remove');
            if (!button) {
              return;
            }

            const targetType = button.getAttribute('data-target');
            const value = button.getAttribute('data-value') || '';
            if (!value) {
              return;
            }

            if (targetType === 'variable') {
              checkboxes.forEach((checkbox) => {
                if (checkbox.value === value) {
                  checkbox.checked = false;
                }
              });
            }
            else if (targetType === 'ontology') {
              const ontologyType = button.getAttribute('data-ontology') || '';
              ontologyCheckboxes.forEach((checkbox) => {
                if (checkbox.value === value && checkbox.dataset.ontology === ontologyType) {
                  checkbox.checked = false;
                }
              });
            }

            applyFilters(root);
          });
        }

        applyFilters(root);
      });
    },
  };
})(jQuery, Drupal, once);
