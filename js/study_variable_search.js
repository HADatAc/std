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

  const getInitialUsageConfig = function () {
    const settings = (typeof drupalSettings !== 'undefined' && drupalSettings.stdStudySearch)
      ? drupalSettings.stdStudySearch
      : {};

    const maxInitial = Number.parseInt(settings.maxInitialStudies, 10);
    return {
      isInitialUsage: settings.isInitialUsage === true,
      maxInitialStudies: Number.isFinite(maxInitial) && maxInitial > 0 ? maxInitial : 20,
    };
  };

  const getPaginationConfig = function () {
    const settings = (typeof drupalSettings !== 'undefined' && drupalSettings.stdStudySearch)
      ? drupalSettings.stdStudySearch
      : {};

    const pageSize = Number.parseInt(settings.pageSize, 10);
    return {
      pageSize: Number.isFinite(pageSize) && pageSize > 0 ? pageSize : 12,
    };
  };

  const getStudyLabels = function () {
    const settings = (typeof drupalSettings !== 'undefined' && drupalSettings.stdStudySearch)
      ? drupalSettings.stdStudySearch
      : {};

    const labels = settings.studyLabels || {};
    const singular = String(labels.singular || 'Study').trim() || 'Study';
    const plural = String(labels.plural || 'Studies').trim() || 'Studies';

    return {
      singular,
      plural,
      singularLower: String(labels.singular_lower || singular.toLowerCase()),
      pluralLower: String(labels.plural_lower || plural.toLowerCase()),
    };
  };

  const splitTags = function (raw) {
    return String(raw || '')
      .split('|')
      .map((item) => item.trim())
      .filter(Boolean);
  };

  const normalizeOntologyKey = function (raw) {
    return String(raw || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  };

  const getOntologyDatasetKey = function (ontologyKey) {
    const normalized = normalizeOntologyKey(ontologyKey);
    if (!normalized) {
      return '';
    }

    const camel = normalized
      .split('-')
      .map((part, index) => {
        if (index === 0) {
          return part;
        }

        return part.charAt(0).toUpperCase() + part.slice(1);
      })
      .join('');

    return 'ontology' + camel.charAt(0).toUpperCase() + camel.slice(1) + 'Tags';
  };

  const updateSelectedPreview = function (root, selectedVariableChecks, selectedOntologyChecks, selectedOrganizationChecks, selectedPlatformChecks, selectedProcessChecks, logic) {
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

    selectedOrganizationChecks.forEach((checkbox) => {
      selectedItems.push({
        type: 'organization',
        value: checkbox.value,
        label: checkbox.dataset.label || checkbox.value,
      });
    });

    selectedPlatformChecks.forEach((checkbox) => {
      selectedItems.push({
        type: 'platform',
        value: checkbox.value,
        label: checkbox.dataset.label || checkbox.value,
      });
    });

    selectedProcessChecks.forEach((checkbox) => {
      selectedItems.push({
        type: 'process',
        value: checkbox.value,
        label: checkbox.dataset.label || checkbox.value,
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

    const mode = logic === 'or' ? 'OR' : 'AND';
    preview.innerHTML = `<div class="std-selected-title">Selected filters (${mode})</div><div class="std-selected-list">${chips}</div>`;
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

  const updateOntologyFacetOptions = function (root, matchedCards, hasAnyFilter) {
    const sections = Array.from(root.querySelectorAll('.std-search-ontology-section'));
    if (!sections.length) {
      return;
    }

    const availableByOntology = {};
    matchedCards.forEach((card) => {
      sections.forEach((section) => {
        const ontology = normalizeOntologyKey(section.getAttribute('data-ontology') || '');
        if (!ontology) {
          return;
        }

        const datasetKey = getOntologyDatasetKey(ontology);
        const tags = splitTags(datasetKey ? card.dataset[datasetKey] : '');
        if (!availableByOntology[ontology]) {
          availableByOntology[ontology] = new Set();
        }
        tags.forEach((tag) => availableByOntology[ontology].add(tag));
      });
    });

    sections.forEach((section) => {
      const ontology = normalizeOntologyKey(section.getAttribute('data-ontology') || '');
      const allowed = availableByOntology[ontology] || new Set();
      const checkboxes = Array.from(section.querySelectorAll('.std-ontology-checkbox'));

      let visibleCount = 0;
      let totalCount = 0;

      checkboxes.forEach((checkbox) => {
        const row = checkbox.closest('.std-search-checkbox');
        if (!row) {
          return;
        }

        totalCount += 1;
        const keepVisible = !hasAnyFilter || allowed.has(checkbox.value) || checkbox.checked;
        row.style.display = keepVisible ? '' : 'none';
        if (keepVisible) {
          visibleCount += 1;
        }
      });

      const titleNode = section.querySelector('.std-search-section-title');
      if (titleNode) {
        const storedBaseTitle = titleNode.getAttribute('data-base-title');
        const baseTitle = storedBaseTitle || (titleNode.textContent || '').replace(/\s*\(\d+\)\s*$/, '').trim();
        if (!storedBaseTitle) {
          titleNode.setAttribute('data-base-title', baseTitle);
        }
        titleNode.textContent = `${baseTitle} (${hasAnyFilter ? visibleCount : totalCount})`;
      }
    });
  };

  const applyFilters = function (root) {
    const studyLabels = getStudyLabels();
    const selectedVariableChecks = Array.from(root.querySelectorAll('.study-variable-checkbox:checked'));
    const selectedOntologyChecks = Array.from(root.querySelectorAll('.std-ontology-checkbox:checked'));
    const selectedOrganizationChecks = Array.from(root.querySelectorAll('.std-organization-checkbox:checked'));
    const selectedPlatformChecks = Array.from(root.querySelectorAll('.std-platform-checkbox:checked'));
    const selectedProcessChecks = Array.from(root.querySelectorAll('.std-process-checkbox:checked'));

    const selected = selectedVariableChecks.map((el) => el.value);
    const selectedSources = new Set(selectedVariableChecks.map((el) => el.dataset.source || '').filter(Boolean));
    const selectedOrganizations = selectedOrganizationChecks.map((el) => el.value);
    const selectedPlatforms = selectedPlatformChecks.map((el) => el.value);
    const selectedProcesses = selectedProcessChecks.map((el) => el.value);
    
    const selectedOntologyByType = {};
    selectedOntologyChecks.forEach((checkbox) => {
      const ontology = normalizeOntologyKey(checkbox.dataset.ontology || '');
      if (!ontology) {
        return;
      }

      if (!Array.isArray(selectedOntologyByType[ontology])) {
        selectedOntologyByType[ontology] = [];
      }

      selectedOntologyByType[ontology].push(checkbox.value);
    });

    // Determine if any filters are selected (moved outside forEach loop)
    const hasVariableFilter = selected.length > 0;
    const hasOntologyFilter = Object.keys(selectedOntologyByType).length > 0;
    const hasOrganizationFilter = selectedOrganizations.length > 0;
    const hasPlatformFilter = selectedPlatforms.length > 0;
    const hasProcessFilter = selectedProcesses.length > 0;
    const hasAnyFilter = hasVariableFilter || hasOntologyFilter || hasOrganizationFilter || hasPlatformFilter || hasProcessFilter;

    const logicInput = root.querySelector('input[name="std-search-logic"]:checked');
    const logic = logicInput ? logicInput.value : 'and';
    const emptyState = root.querySelector('#std-study-empty-state');
    const rankingIndicator = root.querySelector('#std-ranking-indicator');
    const cardsContainer = root.querySelector('#std-study-cards');
    const weights = getWeights();

    const cards = Array.from(root.querySelectorAll('.std-study-card'));
    const matchedCards = [];
    let visibleCount = 0;

    cards.forEach((card) => {
      const tags = splitTags(card.dataset.tags);

      const groupMatches = [];

      if (hasVariableFilter) {
        groupMatches.push(logic === 'and'
          ? selected.every((tag) => tags.includes(tag))
          : selected.some((tag) => tags.includes(tag)));
      }

      if (hasOntologyFilter) {
        const ontologyMatches = [];
        Object.keys(selectedOntologyByType).forEach((ontology) => {
          const selectedTerms = selectedOntologyByType[ontology];
          if (!Array.isArray(selectedTerms) || selectedTerms.length === 0) {
            return;
          }

          const datasetKey = getOntologyDatasetKey(ontology);
          const cardOntologyTags = splitTags(datasetKey ? card.dataset[datasetKey] : '');
          ontologyMatches.push(logic === 'and'
            ? selectedTerms.every((term) => cardOntologyTags.includes(term))
            : selectedTerms.some((term) => cardOntologyTags.includes(term)));
        });
        groupMatches.push(logic === 'and'
          ? ontologyMatches.every(Boolean)
          : ontologyMatches.some(Boolean));
      }

      if (hasOrganizationFilter) {
        const cardOrganization = card.dataset.organizationSlug || '';
        groupMatches.push(logic === 'and'
          ? selectedOrganizations.every((organization) => organization === cardOrganization)
          : selectedOrganizations.some((organization) => organization === cardOrganization));
      }

      if (hasPlatformFilter) {
        const cardPlatform = card.dataset.platformSlug || '';
        groupMatches.push(logic === 'and'
          ? selectedPlatforms.every((platform) => platform === cardPlatform)
          : selectedPlatforms.some((platform) => platform === cardPlatform));
      }

      if (hasProcessFilter) {
        const cardProcessStem = card.dataset.processStemSlug || '';
        groupMatches.push(logic === 'and'
          ? selectedProcesses.every((process) => process === cardProcessStem)
          : selectedProcesses.some((process) => process === cardProcessStem));
      }

      const visible = groupMatches.length === 0
        || (logic === 'and' ? groupMatches.every(Boolean) : groupMatches.some(Boolean));

      if (visible) {
        const ranking = computeRanking(card, selected, selectedSources, weights);
        card.dataset.rankScore = ranking.score.toFixed(6);
        card.dataset.matchedCount = String(ranking.matchedCount);
        matchedCards.push(card);
      }
    });

    if (cardsContainer && matchedCards.length > 1) {
      matchedCards.sort((a, b) => {
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

      matchedCards.forEach((card) => {
        cardsContainer.appendChild(card);
      });
    }

    const pagination = getPaginationConfig();
    const pageSize = pagination.pageSize;
    const totalMatched = matchedCards.length;
    const totalPages = Math.max(1, Math.ceil(totalMatched / pageSize));
    let currentPage = Number.parseInt(root.dataset.stdCurrentPage || '1', 10);
    if (!Number.isFinite(currentPage) || currentPage < 1) {
      currentPage = 1;
    }
    if (currentPage > totalPages) {
      currentPage = totalPages;
    }
    root.dataset.stdCurrentPage = String(currentPage);

    const startIndex = (currentPage - 1) * pageSize;
    const endIndex = startIndex + pageSize;

    cards.forEach((card) => {
      card.style.display = 'none';
    });

    matchedCards.forEach((card, index) => {
      const show = index >= startIndex && index < endIndex;
      card.style.display = show ? '' : 'none';
      if (show) {
        visibleCount += 1;
      }
    });

    const pager = root.querySelector('#std-study-pagination');
    const prevButton = root.querySelector('#std-page-prev');
    const nextButton = root.querySelector('#std-page-next');
    const pageIndicator = root.querySelector('#std-page-indicator');
    if (pager) {
      pager.style.display = totalMatched > 0 ? 'flex' : 'none';
    }
    if (prevButton) {
      prevButton.disabled = currentPage <= 1 || totalMatched === 0;
    }
    if (nextButton) {
      nextButton.disabled = currentPage >= totalPages || totalMatched === 0;
    }
    if (pageIndicator) {
      pageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;
    }

    const counter = root.querySelector('#std-visible-results');
    if (counter) {
      counter.textContent = String(visibleCount);
    }

    if (emptyState) {
      if (!hasAnyFilter) {
        if (visibleCount === 0) {
          emptyState.textContent = `No ${studyLabels.pluralLower} are available in the current context.`;
        }
        else if (totalMatched > visibleCount) {
          emptyState.textContent = `Showing ${visibleCount} of ${totalMatched} ${studyLabels.pluralLower}. Use pagination to view more.`;
        }
        else {
          emptyState.textContent = `Showing all ${studyLabels.pluralLower}. Select filters to narrow results.`;
        }
        emptyState.style.display = '';
      }
      else if (visibleCount === 0) {
        emptyState.textContent = `No ${studyLabels.pluralLower} match the selected filters.`;
        emptyState.style.display = '';
      }
      else if (totalMatched > visibleCount) {
        emptyState.textContent = `Showing ${visibleCount} of ${totalMatched} matching ${studyLabels.pluralLower}. Use pagination to view more.`;
        emptyState.style.display = '';
      }
      else {
        emptyState.style.display = 'none';
      }
    }

    if (rankingIndicator) {
      if (hasAnyFilter && visibleCount > 0) {
        rankingIndicator.textContent = 'Sorted by relevance';
      }
      else {
        rankingIndicator.textContent = '';
      }
    }

    updateOntologyFacetOptions(root, matchedCards, hasAnyFilter);

    updateSelectedPreview(root, selectedVariableChecks, selectedOntologyChecks, selectedOrganizationChecks, selectedPlatformChecks, selectedProcessChecks, logic);
  };

  Drupal.behaviors.stdStudyVariableSearch = {
    attach: function (context) {
      once('std-study-variable-search', '#std-study-variable-search', context).forEach(function (root) {
        const usageConfig = getInitialUsageConfig();
        const paginationConfig = getPaginationConfig();
        root.dataset.stdInitialUsage = usageConfig.isInitialUsage ? '1' : '0';
        root.dataset.stdInitialMax = String(usageConfig.maxInitialStudies);
        root.dataset.stdCurrentPage = '1';
        root.dataset.stdPageSize = String(paginationConfig.pageSize);

        const checkboxes = root.querySelectorAll('.study-variable-checkbox');
        const ontologyCheckboxes = root.querySelectorAll('.std-ontology-checkbox');
        const organizationCheckboxes = root.querySelectorAll('.std-organization-checkbox');
        const platformCheckboxes = root.querySelectorAll('.std-platform-checkbox');
        const processCheckboxes = root.querySelectorAll('.std-process-checkbox');
        const radios = root.querySelectorAll('input[name="std-search-logic"]');
        const clearButton = root.querySelector('#std-search-clear');
        const preview = root.querySelector('#std-selected-preview');
        const anatomyModal = root.querySelector('#std-anatomy-modal');
        const anatomyOpenButtons = root.querySelectorAll('.std-open-anatomy-modal');
        const anatomyCloseButton = root.querySelector('#std-anatomy-modal-close');
        const prevButton = root.querySelector('#std-page-prev');
        const nextButton = root.querySelector('#std-page-next');

        const closeAnatomyModal = function () {
          if (!anatomyModal) {
            return;
          }
          anatomyModal.classList.remove('is-open');
          anatomyModal.setAttribute('aria-hidden', 'true');
        };

        if (anatomyModal) {
          const openAnatomyModal = function () {
            anatomyModal.classList.add('is-open');
            anatomyModal.setAttribute('aria-hidden', 'false');
          };

          anatomyOpenButtons.forEach((button) => {
            button.addEventListener('click', function (event) {
              event.preventDefault();
              event.stopPropagation();
              openAnatomyModal();
            });
          });

          root.addEventListener('click', function (event) {
            const trigger = event.target.closest('.std-open-anatomy-modal');
            if (!trigger) {
              return;
            }

            event.preventDefault();
            event.stopPropagation();
            openAnatomyModal();
          });

          if (anatomyCloseButton) {
            anatomyCloseButton.addEventListener('click', function () {
              closeAnatomyModal();
            });
          }

          anatomyModal.addEventListener('click', function (event) {
            if (event.target === anatomyModal) {
              closeAnatomyModal();
            }
          });

          document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && anatomyModal.classList.contains('is-open')) {
              closeAnatomyModal();
            }
          });

          // Receive anatomy selections from SIR panel, map to UBERON facet,
          // close modal, and apply Scenario Search filters.
          document.addEventListener('sir:anatomy-selected', function (event) {
            const detail = event && event.detail ? event.detail : {};
            const uberonUri = String(detail.uberonUri || '').trim();
            const label = String(detail.label || '').trim();
            if (!uberonUri) {
              return;
            }

            const targetCheckbox = Array.from(root.querySelectorAll('.std-ontology-checkbox[data-ontology="uberon"]'))
              .find((checkbox) => String(checkbox.dataset.uri || '').trim() === uberonUri);

            if (targetCheckbox) {
              targetCheckbox.checked = true;
            }
            else {
              const hiddenField = root.querySelector('#std-ontology-uberon-selection');
              const ontologySection = hiddenField && hiddenField.nextElementSibling
                ? hiddenField.nextElementSibling.querySelector('.std-search-section-body')
                : null;
              if (hiddenField && ontologySection) {
                hiddenField.value = `${label || uberonUri} [${uberonUri}]`;
                hiddenField.dispatchEvent(new Event('change', { bubbles: true }));
              }
            }

            closeAnatomyModal();
            applyFilters(root);
          });
        }

        checkboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        });

        ontologyCheckboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        });

        organizationCheckboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        });

        platformCheckboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        });

        processCheckboxes.forEach((checkbox) => {
          checkbox.addEventListener('change', function () {
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        });

        radios.forEach((radio) => {
          radio.addEventListener('change', function () {
            root.dataset.stdCurrentPage = '1';
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
            organizationCheckboxes.forEach((checkbox) => {
              checkbox.checked = false;
            });
            platformCheckboxes.forEach((checkbox) => {
              checkbox.checked = false;
            });
            processCheckboxes.forEach((checkbox) => {
              checkbox.checked = false;
            });
            const andRadio = root.querySelector('input[name="std-search-logic"][value="and"]');
            if (andRadio) {
              andRadio.checked = true;
            }
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        }

        if (prevButton) {
          prevButton.addEventListener('click', function () {
            const current = Number.parseInt(root.dataset.stdCurrentPage || '1', 10) || 1;
            root.dataset.stdCurrentPage = String(Math.max(1, current - 1));
            applyFilters(root);
          });
        }

        if (nextButton) {
          nextButton.addEventListener('click', function () {
            const current = Number.parseInt(root.dataset.stdCurrentPage || '1', 10) || 1;
            root.dataset.stdCurrentPage = String(current + 1);
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
            else if (targetType === 'organization') {
              organizationCheckboxes.forEach((checkbox) => {
                if (checkbox.value === value) {
                  checkbox.checked = false;
                }
              });
            }
            else if (targetType === 'platform') {
              platformCheckboxes.forEach((checkbox) => {
                if (checkbox.value === value) {
                  checkbox.checked = false;
                }
              });
            }
            else if (targetType === 'process') {
              processCheckboxes.forEach((checkbox) => {
                if (checkbox.value === value) {
                  checkbox.checked = false;
                }
              });
            }

            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
          });
        }

        // Monitor hidden fields for ontology term selections from tree modal
        once('std-ontology-field-watch', '.std-ontology-selection-field', root).forEach((field) => {
          const ontology = field.getAttribute('data-ontology') || '';
          
          // Listen for change events (triggered when tree modal selects a node)
          field.addEventListener('change', () => {
            const value = field.value;
            if (!value) return;
            
            // Parse the selection value (format: "Label [URI]")
            const match = value.match(/^(.+?)\s*\[(.+?)\]$/);
            if (!match) return;
            
            const label = match[1].trim();
            const uri = match[2].trim();
            
            if (!label || !uri) return;
            
            // Find the corresponding details element (next sibling)
            const detailsElement = field.nextElementSibling;
            if (!detailsElement || !detailsElement.matches('details.std-ontology-section')) {
              console.warn('Could not find details element for ontology:', ontology);
              return;
            }
            
            const sectionBody = detailsElement.querySelector('.std-search-section-body');
            if (sectionBody) {
              addOntologyTerm(sectionBody, ontology, label, uri);
              field.value = ''; // Clear the field value
            }
          });
        });

        // Helper function to add ontology term to the section
        function addOntologyTerm(sectionBody, ontology, label, uri) {
          const slug = 'ont-' + ontology + '-' + uri.replace(/[^a-zA-Z0-9]/g, '_');
          
          // Check if term already exists
          const exists = sectionBody.querySelector(`input[value="${slug}"]`);
          if (exists) {
            exists.checked = true;
            root.dataset.stdCurrentPage = '1';
            applyFilters(root);
            return;
          }
          
          // Create new checkbox for the term
          const labelElement = document.createElement('label');
          labelElement.className = 'std-search-checkbox';
          labelElement.innerHTML = `
            <input type="checkbox" class="std-ontology-checkbox" 
              data-ontology="${ontology}" 
              data-label="${label}" 
              data-uri="${uri}" 
              value="${slug}" checked>
            <span>${label}</span>
            <small class="std-ontology-uri">${uri}</small>
          `;
          sectionBody.appendChild(labelElement);
          
          // Update the count in the summary
          const summary = sectionBody.closest('details')?.querySelector('.std-search-section-title');
          if (summary) {
            const currentText = summary.textContent || '';
            const match = currentText.match(/^(.+?)\s*\((\d+)\)$/);
            if (match) {
              const title = match[1];
              const count = parseInt(match[2], 10) + 1;
              summary.textContent = `${title} (${count})`;
            }
          }
          
          // Attach change handler to the new checkbox
          const newCheckbox = labelElement.querySelector('.std-ontology-checkbox');
          if (newCheckbox) {
            newCheckbox.addEventListener('change', () => {
              root.dataset.stdCurrentPage = '1';
              applyFilters(root);
            });
          }
          
          // Trigger filter update
          root.dataset.stdCurrentPage = '1';
          applyFilters(root);
        }

        applyFilters(root);
      });
    },
  };
})(jQuery, Drupal, once);
