<?php

namespace Drupal\std\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\rep\ManageOwnerFilter;
use Drupal\std\Service\StudyVariableSearchService;
use Drupal\std\Support\StudySearchRanking;

/**
 * Study search page with hierarchical variable browser and ranking metadata.
 */
class StudyVariableSearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_study_variable_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $preferredStudy = trim((string) (\Drupal::config('rep.settings')->get('preferred_study') ?: 'Study'));
    $preferredInstrument = trim((string) (\Drupal::config('rep.settings')->get('preferred_instrument') ?: 'instrument'));
    $preferredComponent = trim((string) (\Drupal::config('rep.settings')->get('preferred_component') ?: 'component'));
    $sourceTitles = [
      'instrument' => ucfirst($preferredInstrument) . 's',
      'questionnaire' => 'Variables',
      'component' => ucfirst($preferredComponent) . 's',
    ];
    $studyLabels = $this->buildStudyLabels($preferredStudy);

    $session = \Drupal::request()->getSession();
    $usageCount = (int) $session->get('std.study_search.usage_count', 0);
    $isInitialUsage = ($usageCount === 0);
    $session->set('std.study_search.usage_count', $usageCount + 1);

    $configuredMaxInitialStudies = \Drupal::config('std.settings')->get('study_search_max_initial_studies');
    $maxInitialStudies = (is_numeric($configuredMaxInitialStudies) && (int) $configuredMaxInitialStudies > 0)
      ? (int) $configuredMaxInitialStudies
      : 20;

    $form['#attached']['library'][] = 'std/study_variable_search';
    $form['#attached']['library'][] = 'rep/rep_modal';

    $hasAnatomyPanel = FALSE;
    try {
      $form['#attached']['library'][] = 'sir/sir_anatomy';
      $form['#attached']['drupalSettings']['sirAnatomy'] = [
        'listUrl' => Url::fromRoute('sir.anatomy_mappings')->toString(),
        'resolveUrl' => Url::fromRoute('sir.anatomy_resolve')->toString(),
        'saveUrl' => Url::fromRoute('sir.anatomy_mapping_save')->toString(),
        'instrumentByAnatomyUrlTemplate' => Url::fromRoute('sir.anatomy_instruments', ['uberon' => '__uberon__'])->toString(),
        'organizationUri' => '',
        'deleteUrlTemplate' => Url::fromUri('base:/sir/anatomy/mapping/__id__')->toString(),
        'configMode' => FALSE,
        'isAdmin' => FALSE,
        'configToggleUrl' => '',
        'configToggleLabel' => '',
      ];
      $hasAnatomyPanel = TRUE;
    }
    catch (\Throwable $e) {
      $hasAnatomyPanel = FALSE;
    }
    $form['#attached']['drupalSettings']['stdStudySearch'] = [
      'weights' => StudySearchRanking::defaultWeights(),
      'totalStudies' => 0, // Will be updated after loading studies
      'pageSize' => 12,
      'isInitialUsage' => $isInitialUsage,
      'usageCount' => $usageCount,
      'maxInitialStudies' => $maxInitialStudies,
      'studyLabels' => $studyLabels,
      'hasAnatomyPanel' => $hasAnatomyPanel,
    ];

    /** @var \Drupal\std\Service\StudyVariableSearchService $searchService */
    if (\Drupal::hasService('std.study_variable_search')) {
      $searchService = \Drupal::service('std.study_variable_search');
    }
    else {
      // Fallback keeps page functional if container cache is stale.
      $searchService = new StudyVariableSearchService(
        \Drupal::service('rep.api_connector'),
        \Drupal::service('file_system'),
      );
    }
    $currentUser = \Drupal::currentUser();
    $userEmail = trim((string) $currentUser->getEmail());
    $isAdmin = ManageOwnerFilter::isAdmin() || $currentUser->hasPermission('administer study search');

    $context = $searchService->buildContext(
      $userEmail,
      $isAdmin,
      $currentUser->isAuthenticated(),
    );

    $variablesBySource = is_array($context['variables_by_source'] ?? NULL)
      ? $context['variables_by_source']
      : [];
    $ontologyDefinitions = is_array($context['ontology_definitions'] ?? NULL)
      ? $context['ontology_definitions']
      : [];
    if (empty($ontologyDefinitions)) {
      $ontologyDefinitions = [
        'uberon' => 'Anatomical Category (UBERON)',
        'workflowstem' => 'Procedure Type (NCIT-PMSR)',
      ];
    }
    $ontologyFilters = is_array($context['ontology_filters'] ?? NULL)
      ? $context['ontology_filters']
      : [];
    $studyCards = is_array($context['study_cards'] ?? NULL)
      ? $context['study_cards']
      : [];
    $errors = is_array($context['errors'] ?? NULL)
      ? $context['errors']
      : [];

    // Update total studies count in drupalSettings
    $totalStudies = count($studyCards);
    $form['#attached']['drupalSettings']['stdStudySearch']['totalStudies'] = $totalStudies;

    // Extract new filter data for ProcessBasedStudy
    $organizations = is_array($context['organizations'] ?? NULL)
      ? $context['organizations']
      : [];
    $processFilters = is_array($context['process_filters'] ?? NULL)
      ? $context['process_filters']
      : [];

    // Aggregate processFilters by ProcessStem (not by individual Process)
    // ProcessStems are hierarchical, Processes are not
    $processStemAggregated = [];
    foreach ($processFilters as $processData) {
      $stemSlug = $processData['stem_slug'] ?? '';
      $stemLabel = $processData['stem_label'] ?? '';
      
      if ($stemSlug === '' || $stemLabel === '') {
        continue;
      }
      
      if (!isset($processStemAggregated[$stemSlug])) {
        $processStemAggregated[$stemSlug] = [
          'label' => $stemLabel,
          'slug' => $stemSlug,
          'count' => 0,
        ];
      }
      
      $processStemAggregated[$stemSlug]['count'] += $processData['count'] ?? 0;
    }

    $platformFilters = is_array($context['platform_filters'] ?? NULL)
      ? $context['platform_filters']
      : [];

    $form['#attached']['drupalSettings']['stdStudySearch']['ontologyKeys'] = array_values(array_map('strval', array_keys($ontologyDefinitions)));

    $sidebarHtml = '';

    // Render new filter sections for ProcessBasedStudy
    if (!empty($organizations)) {
      $sidebarHtml .= $this->renderFilterSection(
        'Organizations',
        $organizations,
        'organization',
        FALSE
      );
    }

    $sidebarHtml .= $this->renderFilterSection(
      'Platforms',
      $platformFilters,
      'platform',
      TRUE
    );

    $sidebarHtml .= $this->renderFilterSection(
      'Clinical Processes',
      array_values($processStemAggregated),
      'process',
      TRUE,
      'workflowstem'
    );

    // Render existing variable filter sections
    foreach ($sourceTitles as $sourceKey => $sourceTitle) {
      $sidebarHtml .= $this->renderSourceSection(
        $sourceTitle,
        is_array($variablesBySource[$sourceKey] ?? NULL) ? $variablesBySource[$sourceKey] : [],
        $sourceKey,
      );
    }
    foreach ($ontologyDefinitions as $ontology => $ontologyTitle) {
      $ontologyKey = trim((string) $ontology);
      if ($ontologyKey === '') {
        continue;
      }

      if ($ontologyKey === 'workflowstem') {
        continue;
      }

      $sidebarHtml .= $this->renderOntologySection(
        trim((string) $ontologyTitle) !== '' ? (string) $ontologyTitle : strtoupper($ontologyKey),
        is_array($ontologyFilters[$ontologyKey] ?? NULL) ? $ontologyFilters[$ontologyKey] : [],
        $ontologyKey,
      );
    }

    $cardsHtml = $this->renderStudyCards(
      $studyCards,
      $ontologyDefinitions,
      $studyLabels,
      $preferredInstrument,
      $preferredComponent,
    );
    $errorBanner = $this->renderErrorBanner($errors);

    $form['search_page'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section id="std-study-variable-search" class="std-study-search">'
          . $errorBanner
          . '<header class="std-search-header">'
          . '<p class="text-muted mb-3">Use the hierarchical variable browser or other filters (Organizations, Platforms, etc.) to select and rank related ' . Html::escape($studyLabels['plural_lower']) . ' by relevance.</p>'
          . '<div class="std-filter-topbar">'
          . '<div class="std-logic-toggle" role="radiogroup" aria-label="Filter logic">'
          . '<label><input type="radio" name="std-search-logic" value="and" checked> AND</label>'
          . '<label><input type="radio" name="std-search-logic" value="or"> OR</label>'
          . '</div>'
          . '<button type="button" class="btn btn-sm btn-outline-secondary" id="std-search-clear">Clear selection</button>'
          . '</div>'
          . '</header>'
          . '<div class="row g-3">'
          . '<aside class="col-12 col-lg-4">'
          . '<div class="std-search-sidebar">'
          . $sidebarHtml
          . '</div>'
          . '</aside>'
          . '<div class="col-12 col-lg-8">'
          . '<div id="std-selected-preview" class="std-selected-preview mb-2" aria-live="polite"></div>'
          . '<div class="std-results-header mb-2">'
          . '<strong id="std-visible-results">0</strong> of <strong id="std-total-studies">' . $totalStudies . '</strong> ' . Html::escape($studyLabels['plural_lower']) . ' visible'
          . '<span class="text-muted ms-2" id="std-ranking-indicator"></span>'
          . '</div>'
          . '<p id="std-study-empty-state" class="text-muted mb-3">Select at least one filter to display ' . Html::escape($studyLabels['plural_lower']) . '.</p>'
          . '<div id="std-study-cards" class="std-study-grid">'
          . $cardsHtml
          . '</div>'
          . '<nav id="std-study-pagination" class="std-study-pagination" aria-label="Scenario Search pagination">'
          . '<button type="button" class="btn btn-sm btn-outline-secondary" id="std-page-prev" disabled>Previous</button>'
          . '<span id="std-page-indicator" class="std-page-indicator">Page 1 of 1</span>'
          . '<button type="button" class="btn btn-sm btn-outline-secondary" id="std-page-next" disabled>Next</button>'
          . '</nav>'
          . '</div>'
          . '</div>'
          . ($hasAnatomyPanel
            ? '<div id="std-anatomy-modal" class="std-anatomy-modal" aria-hidden="true">'
              . '<div class="std-anatomy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="std-anatomy-modal-title">'
              . '<div class="std-anatomy-modal__header">'
              . '<h5 id="std-anatomy-modal-title" class="std-anatomy-modal__title">Search Simulators by Anatomy</h5>'
              . '<button type="button" class="btn btn-sm btn-outline-secondary" id="std-anatomy-modal-close">Close</button>'
              . '</div>'
              . '<div class="std-anatomy-modal__content">'
              . '<div id="sir-anatomy-sidebar-block" class="sir-anatomy-sidebar-block">'
              . '<h2>Search Simulators by Anatomy</h2>'
              . '<div id="sir-anatomy-panel-host"></div>'
              . '</div>'
              . '</div>'
              . '</div>'
              . '</div>'
            : '')
          . '</section>'
      ),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // No submit action for this page.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submit action for this page.
  }

  private function renderSourceSection(string $title, array $variables, string $source): string {
    $groups = [];
    $totalVariables = 0;
    foreach ($variables as $variable) {
      if (!is_array($variable)) {
        continue;
      }

      $label = trim((string) ($variable['label'] ?? ''));
      $slug = trim((string) ($variable['slug'] ?? ''));
      if ($label === '' || $slug === '') {
        continue;
      }

      $first = strtoupper(substr($label, 0, 1));
      if ($first === '' || !preg_match('/[A-Z0-9]/', $first)) {
        $first = '#';
      }

      $groups[$first][] = [
        'label' => $label,
        'slug' => $slug,
      ];
      $totalVariables++;
    }

    $html = '<details class="std-search-section std-search-source-section">';
    $html .= '<summary class="std-search-section-summary">';
    $html .= '<span class="std-search-section-title">' . Html::escape($title) . ' (' . $totalVariables . ')</span>';
    $html .= '</summary>';

    if ($totalVariables === 0) {
      $html .= '</details>';
      return $html;
    }

    $html .= '<div class="std-search-section-body">';

    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($groups as $groupKey => $groupItems) {
      usort($groupItems, fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

      $html .= '<details class="std-search-subgroup" open>';
      $html .= '<summary>' . Html::escape($groupKey) . ' (' . count($groupItems) . ')</summary>';
      $html .= '<div class="std-search-subgroup-body">';
      foreach ($groupItems as $item) {
        $html .= '<label class="std-search-checkbox">'
          . '<input type="checkbox" class="study-variable-checkbox" data-source="' . Html::escape($source) . '" data-label="' . Html::escape($item['label']) . '" value="' . Html::escape($item['slug']) . '">'
          . '<span>' . Html::escape($item['label']) . '</span>'
          . '</label>';
      }
      $html .= '</div>';
      $html .= '</details>';
    }

    $html .= '</div>';
    $html .= '</details>';
    return $html;
  }

  private function renderOntologySection(string $title, array $options, string $ontology): string {
    $terms = [];
    foreach ($options as $option) {
      if (!is_array($option)) {
        continue;
      }

      $slug = trim((string) ($option['slug'] ?? ''));
      $label = trim((string) ($option['label'] ?? ''));
      $uri = trim((string) ($option['uri'] ?? ''));
      if ($slug === '' || $label === '') {
        continue;
      }

      $terms[] = [
        'slug' => $slug,
        'label' => $label,
        'uri' => $uri,
      ];
    }

    // Hidden field to receive selected term URI
    $fieldId = 'std-ontology-' . $ontology . '-selection';
    
    // Add Browse button using the tree modal system
    $treeUrl = \Drupal\Core\Url::fromRoute('rep.tree_form', [
      'mode' => 'modal',
      'elementtype' => $ontology,
      'silent' => 'false',
      'prefix' => 'false',
    ], ['query' => ['field_id' => $fieldId]])->toString();
    
    $html = '<input type="hidden" id="' . Html::escape($fieldId) . '" name="' . Html::escape($fieldId) . '" value="" data-ontology="' . Html::escape($ontology) . '" class="std-ontology-selection-field" />';
    
    $html .= '<details class="std-search-section std-search-source-section std-search-ontology-section mt-4" data-ontology="' . Html::escape($ontology) . '">';
    $html .= '<summary class="std-search-section-summary">';
    $html .= '<span class="std-search-section-title">' . Html::escape($title) . ' (' . count($terms) . ')</span>';
    
    $html .= '<button type="button" class="btn btn-sm btn-outline-secondary open-tree-modal std-ontology-browse-btn" '
      . 'data-ontology="' . Html::escape($ontology) . '" '
      . 'data-url="' . Html::escape($treeUrl) . '" '
      . 'data-field-id="' . Html::escape($fieldId) . '" '
      . 'data-elementtype="' . Html::escape($ontology) . '" '
      . 'data-dialog-type="modal" '
      . 'title="Browse ' . Html::escape($title) . '">'
      . '<span class="std-btn-icon std-btn-icon--folder" aria-hidden="true">'
      . '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M3 6h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6zm2 4v8h14v-8H5z"/></svg>'
      . '</span><span>Browse</span>'
      . '</button>';

    if ($ontology === 'uberon') {
      $html .= '<button type="button" class="btn btn-sm btn-outline-primary std-open-anatomy-modal ms-2" '
        . 'title="Search Simulators by Anatomy" '
        . 'aria-label="Search Simulators by Anatomy">'
        . '<span class="std-btn-icon std-btn-icon--anatomy" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 2a4 4 0 0 1 4 4c0 1.6-1.1 3-2.6 3.6l.6 3.2h2a2 2 0 0 1 2 2V20h-2v-5h-2.3l-.7-3.8h-2l-.7 3.8H8V20H6v-5a2 2 0 0 1 2-2h2l.6-3.2A4 4 0 0 1 8 6a4 4 0 0 1 4-4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>'
        . '</span>'
        . '</button>';
    }
    
    $html .= '</summary>';

    if (empty($terms)) {
      $html .= '</details>';
      return $html;
    }

    $html .= '<div class="std-search-section-body">';

    foreach ($terms as $term) {
      $slug = $term['slug'];
      $label = $term['label'];
      $uri = $term['uri'];

      $html .= '<label class="std-search-checkbox">'
        . '<input type="checkbox" class="std-ontology-checkbox" data-ontology="' . Html::escape($ontology) . '" data-label="' . Html::escape($label) . '" data-uri="' . Html::escape($uri) . '" value="' . Html::escape($slug) . '">'
        . '<span>' . Html::escape($label) . '</span>';
      if ($uri !== '') {
        $html .= '<small class="std-ontology-uri">' . Html::escape($uri) . '</small>';
      }
      $html .= '</label>';
    }

    $html .= '</div>';
    $html .= '</details>';
    return $html;
  }

  private function renderStudyCards(array $studyCards, array $ontologyDefinitions, array $studyLabels, string $preferredInstrument, string $preferredComponent): string {
    $cardsHtml = '';
    foreach ($studyCards as $card) {
      if (!is_array($card)) {
        continue;
      }

      $tags = is_array($card['tags'] ?? NULL) ? $card['tags'] : [];
      $sourceTags = is_array($card['source_tags'] ?? NULL) ? $card['source_tags'] : [];
      $ontologyTags = is_array($card['ontology_tags'] ?? NULL) ? $card['ontology_tags'] : [];

      $ontologyAttributes = '';
      foreach ($ontologyDefinitions as $ontologyKey => $ontologyTitle) {
        $normalizedOntologyKey = Html::getClass((string) $ontologyKey);
        if ($normalizedOntologyKey === '') {
          continue;
        }

        $ontologyValues = is_array($ontologyTags[$ontologyKey] ?? NULL) ? $ontologyTags[$ontologyKey] : [];
        $ontologyAttributes .= ' data-ontology-' . $normalizedOntologyKey . '-tags="' . Html::escape($this->joinTags($ontologyValues)) . '"';
      }

      $cardsHtml .= '<article class="std-study-card"'
        . ' data-study-type="' . Html::escape($card['study_type'] ?? 'study') . '"'
        . ' data-organization-slug="' . Html::escape($card['organization_slug'] ?? '') . '"'
        . ' data-platform-slug="' . Html::escape($card['platform_slug'] ?? '') . '"'
        . ' data-process-stem-slug="' . Html::escape($card['process_stem_slug'] ?? '') . '"'
        . ' data-process-label="' . Html::escape($card['process_label'] ?? '') . '"'
        . ' data-tags="' . Html::escape($this->joinTags($tags)) . '"'
        . ' data-simulator-tags="' . Html::escape($this->joinTags(is_array($sourceTags['simulator'] ?? NULL) ? $sourceTags['simulator'] : [])) . '"'
        . ' data-instrument-tags="' . Html::escape($this->joinTags(is_array($sourceTags['instrument'] ?? NULL) ? $sourceTags['instrument'] : [])) . '"'
        . ' data-questionnaire-tags="' . Html::escape($this->joinTags(is_array($sourceTags['questionnaire'] ?? NULL) ? $sourceTags['questionnaire'] : [])) . '"'
        . ' data-component-tags="' . Html::escape($this->joinTags(is_array($sourceTags['component'] ?? NULL) ? $sourceTags['component'] : [])) . '"'
        . $ontologyAttributes
        . ' data-completeness-score="' . Html::escape(number_format((float) ($card['completeness_score'] ?? 0.0), 4, '.', '')) . '"'
        . '>';

      $cardsHtml .= '<div class="std-study-card-header">';
      
      // Add study type badge for ProcessBasedStudy
      $studyType = $card['study_type'] ?? 'study';
      if ($studyType === 'processbasedstudy') {
        $cardsHtml .= '<span class="badge bg-primary me-2">ProcessBasedStudy</span>';
      }
      $studyId = trim((string) ($card['study_id'] ?? ''));
      if ($studyId !== '') {
        $cardsHtml .= '<span class="badge bg-secondary me-2">' . Html::escape($studyId) . '</span>';
      }
      
      $cardsHtml .= '<h4>' . Html::escape((string) ($card['label'] ?? '')) . '</h4>';
      $cardsHtml .= '<p class="std-study-uri mb-2">' . Html::escape((string) ($card['uri'] ?? '')) . '</p>';
      $cardsHtml .= '</div>';

      $description = trim((string) ($card['description'] ?? ''));
      if ($description !== '') {
        $cardsHtml .= '<p class="std-study-description">' . Html::escape($description) . '</p>';
      }

      // Add ProcessBasedStudy metadata
      if ($studyType === 'processbasedstudy') {
        $cardsHtml .= '<div class="std-study-metadata mt-2">';
        
        $organization = trim((string) ($card['organization'] ?? ''));
        if ($organization !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>Organization:</strong> ' . Html::escape($organization) . '</p>';
        }
        
        $platformLabel = trim((string) ($card['platform_label'] ?? ''));
        if ($platformLabel === '') {
          $platformLabel = 'Unmapped Platform';
        }
        $cardsHtml .= '<p class="mb-1"><strong>Platform:</strong> ' . Html::escape($platformLabel) . '</p>';
        
        $processLabel = trim((string) ($card['process_label'] ?? ''));
        if ($processLabel !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>Process:</strong> ' . Html::escape($processLabel) . '</p>';
        }
        
        $pi = trim((string) ($card['principal_investigator'] ?? ''));
        if ($pi !== '') {
          $canonicalPi = \Drupal\rep\Utils::canonicalizePmsrUri($pi);
          if ($canonicalPi === 'https://pmsr.net/ont/PER/PI-001') {
            $pi = 'Curator at Universidade Catolica Portuguesa';
          }
        }
        if ($pi !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>PI:</strong> ' . Html::escape($pi) . '</p>';
        }
        
        $startDate = trim((string) ($card['start_date'] ?? ''));
        if ($startDate !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>Start Date:</strong> ' . Html::escape($startDate) . '</p>';
        }
        
        $uploadSize = trim((string) ($card['upload_size'] ?? ''));
        if ($uploadSize !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>Upload Size:</strong> ' . Html::escape($uploadSize) . '</p>';
        }
        
        $cardsHtml .= '</div>';
      }

      $componentLabel = ucfirst(trim($preferredComponent) !== '' ? $preferredComponent : 'component') . 's';
      $simulatorTotal = (int) ($card['simulator_count'] ?? 0);

      $cardsHtml .= '<div class="std-study-meta">'
        . '<span class="badge bg-light text-dark">Variables: ' . (int) ($card['codebook_count'] ?? 0) . '</span>'
        . '<span class="badge bg-light text-dark">' . Html::escape($componentLabel) . ': ' . (int) ($card['component_count'] ?? 0) . '</span>'
        . '<span class="badge bg-light text-dark">Simulators: ' . $simulatorTotal . '</span>'
        . '<span class="badge bg-light text-dark">Completeness: ' . (int) round(((float) ($card['completeness_score'] ?? 0.0)) * 100) . '%</span>'
        . '</div>';

      $simulatorInstances = is_array($card['simulator_instances'] ?? NULL) ? $card['simulator_instances'] : [];
      $simulatorInstances = array_values(array_filter(array_map(static fn($value) => trim((string) $value), $simulatorInstances), static fn($value) => $value !== ''));
      if (!empty($simulatorInstances)) {
        $cardsHtml .= '<p class="mb-1 mt-2"><strong>Simulators:</strong> ' . Html::escape(implode('; ', $simulatorInstances)) . '</p>';
      }

      $cardsHtml .= '<div class="std-study-capabilities mt-2">';
      if ((int) ($card['has_data'] ?? 0) === 1) {
        $cardsHtml .= '<span class="badge bg-success-subtle text-success-emphasis border">Data</span>';
      }

      if ((int) ($card['has_images'] ?? 0) === 1) {
        $cardsHtml .= '<span class="badge bg-info-subtle text-info-emphasis border">Images</span>';
      }

      if ((int) ($card['has_workflow'] ?? 0) === 1) {
        $cardsHtml .= '<span class="badge bg-primary-subtle text-primary-emphasis border">Workflow</span>';
      }

      $cardsHtml .= '</div>';

      $cardsHtml .= '<div class="std-study-actions mt-3">'
        . '<a class="btn btn-sm btn-primary" href="' . Html::escape((string) ($card['manage_url'] ?? '#')) . '">Manage ' . Html::escape($studyLabels['singular']) . '</a>';
      if (!empty($card['can_edit'])) {
        $cardsHtml .= ' <a class="btn btn-sm btn-secondary" href="' . Html::escape((string) ($card['edit_url'] ?? '#')) . '">Edit</a>';
      }
      $cardsHtml .= '</div>';
      $cardsHtml .= '</article>';
    }

    if ($cardsHtml === '') {
      $cardsHtml = '<p class="text-muted">No ' . Html::escape($studyLabels['plural_lower']) . ' are available in the current context.</p>';
    }

    return $cardsHtml;
  }

  private function joinTags(array $tags): string {
    $clean = [];
    foreach ($tags as $tag) {
      $value = trim((string) $tag);
      if ($value !== '') {
        $clean[$value] = $value;
      }
    }
    return implode('|', array_values($clean));
  }

  private function renderErrorBanner(array $errors): string {
    if (empty($errors)) {
      return '';
    }

    $html = '<div class="alert alert-warning" role="alert">';
    $html .= '<strong>Partial data warning:</strong>';
    $html .= '<ul class="mb-0 mt-2">';
    foreach ($errors as $error) {
      $text = trim((string) $error);
      if ($text !== '') {
        $html .= '<li>' . Html::escape($text) . '</li>';
      }
    }
    $html .= '</ul>';
    $html .= '</div>';
    return $html;
  }

  /**
   * Render a filter section (organization, platform, process).
   *
   * @param string $title
   *   Section title.
   * @param array $items
   *   Filter items with label, slug, count.
   * @param string $type
   *   Filter type (organization, platform, process).
   * @param bool $hierarchical
   *   Whether this filter should have hierarchical modal button.
   *
   * @return string
   *   Rendered HTML.
   */
  private function renderFilterSection(string $title, array $items, string $type, bool $hierarchical = FALSE, ?string $browseElementtype = NULL): string {
    $fieldId = 'std-filter-' . $type . '-selection';
    $treeUrl = '';
    if ($hierarchical) {
      $treeElementtype = trim((string) ($browseElementtype ?? $type));
      if ($treeElementtype === '') {
        $treeElementtype = $type;
      }

      $treeUrl = \Drupal\Core\Url::fromRoute('rep.tree_form', [
        'mode' => 'modal',
        'elementtype' => $treeElementtype,
        'silent' => 'false',
        'prefix' => 'false',
      ], ['query' => ['field_id' => $fieldId]])->toString();
    }

    $html = '<input type="hidden" id="' . Html::escape($fieldId) . '" name="' . Html::escape($fieldId) . '" value="" class="std-filter-selection-field" />';
    $html .= '<details class="std-search-section std-search-source-section mt-4">';
    $html .= '<summary class="std-search-section-summary">';
    $html .= '<span class="std-search-section-title">' . Html::escape($title) . ' (' . count($items) . ')';
    if ($hierarchical) {
      $html .= ' <button type="button" class="btn btn-sm btn-secondary open-tree-modal" '
        . 'data-elementtype="' . Html::escape($treeElementtype) . '" '
        . 'data-dialog-type="modal" '
        . 'data-url="' . Html::escape($treeUrl) . '" '
        . 'data-field-id="' . Html::escape($fieldId) . '" '
        . 'title="Browse ' . Html::escape($title) . '">🔍</button>';
    }
    $html .= '</span>';
    $html .= '</summary>';

    if (empty($items)) {
      $html .= '</details>';
      return $html;
    }

    $html .= '<div class="std-search-section-body">';

    foreach ($items as $item) {
      $slug = $item['slug'] ?? '';
      $label = $item['label'] ?? '';
      $count = $item['count'] ?? 0;

      if ($slug === '' || $label === '') {
        continue;
      }

      $html .= '<label class="std-search-checkbox">'
        . '<input type="checkbox" class="std-' . Html::escape($type) . '-checkbox" data-label="' . Html::escape($label) . '" value="' . Html::escape($slug) . '">'
        . '<span>' . Html::escape($label) . ' (' . (int) $count . ')</span>'
        . '</label>';
    }

    $html .= '</div>';
    $html .= '</details>';
    return $html;
  }

  private function buildStudyLabels(string $preferredStudy): array {
    $singular = trim($preferredStudy) === '' ? 'Study' : trim($preferredStudy);
    $plural = $this->derivePluralLabel($singular);

    return [
      'singular' => $singular,
      'plural' => $plural,
      'singular_lower' => mb_strtolower($singular),
      'plural_lower' => mb_strtolower($plural),
    ];
  }

  private function derivePluralLabel(string $singular): string {
    if ($singular === '') {
      return 'Studies';
    }

    $len = mb_strlen($singular);
    if ($len >= 2) {
      $last = mb_substr($singular, -1);
      $prev = mb_substr($singular, -2, 1);
      $endsWithY = strtolower($last) === 'y';
      $prevIsVowel = (bool) preg_match('/[aeiou]/i', $prev);
      if ($endsWithY && !$prevIsVowel) {
        return mb_substr($singular, 0, $len - 1) . 'ies';
      }
    }

    if (preg_match('/(s|x|z|ch|sh)$/i', $singular)) {
      return $singular . 'es';
    }

    return $singular . 's';
  }

}
