<?php

namespace Drupal\std\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\rep\ManageOwnerFilter;
use Drupal\std\Service\StudyVariableSearchService;
use Drupal\std\Support\StudySearchRanking;

/**
 * Study search page with hierarchical variable browser and ranking metadata.
 */
class StudyVariableSearchForm extends FormBase {

  private const SOURCE_TITLES = [
    'simulator' => 'Simulators',
    'instrument' => 'Medical Instruments',
    'questionnaire' => 'Variables',
    'component' => 'Components / Actuators',
  ];

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
    $form['#attached']['library'][] = 'std/study_variable_search';
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['drupalSettings']['stdStudySearch'] = [
      'weights' => StudySearchRanking::defaultWeights(),
      'totalStudies' => 0, // Will be updated after loading studies
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

    if (!empty($platformFilters)) {
      $sidebarHtml .= $this->renderFilterSection(
        'Platforms',
        $platformFilters,
        'platform',
        TRUE
      );
    }

    if (!empty($processStemAggregated)) {
      $sidebarHtml .= $this->renderFilterSection(
        'Clinical Processes',
        array_values($processStemAggregated),
        'process',
        TRUE
      );
    }

    // Render existing variable filter sections
    foreach (self::SOURCE_TITLES as $sourceKey => $sourceTitle) {
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

      $sidebarHtml .= $this->renderOntologySection(
        trim((string) $ontologyTitle) !== '' ? (string) $ontologyTitle : strtoupper($ontologyKey),
        is_array($ontologyFilters[$ontologyKey] ?? NULL) ? $ontologyFilters[$ontologyKey] : [],
        $ontologyKey,
      );
    }

    $cardsHtml = $this->renderStudyCards($studyCards, $ontologyDefinitions);
    $errorBanner = $this->renderErrorBanner($errors);

    $form['search_page'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section id="std-study-variable-search" class="std-study-search">'
          . $errorBanner
          . '<header class="std-search-header">'
          . '<p class="text-muted mb-3">Use the hierarchical variable browser or other filters (Organizations, Platforms, etc.) to select and rank related studies by relevance.</p>'
          . '<div class="std-filter-topbar">'
          . '<div class="std-logic-toggle" role="radiogroup" aria-label="Filter logic">'
          . '<label><input type="radio" name="std-search-logic" value="and"> AND</label>'
          . '<label><input type="radio" name="std-search-logic" value="or" checked> OR</label>'
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
          . '<strong id="std-visible-results">0</strong> of <strong id="std-total-studies">' . $totalStudies . '</strong> studies visible'
          . '<span class="text-muted ms-2" id="std-ranking-indicator"></span>'
          . '</div>'
          . '<p id="std-study-empty-state" class="text-muted mb-3">Select at least one filter to display studies.</p>'
          . '<div id="std-study-cards" class="std-study-grid">'
          . $cardsHtml
          . '</div>'
          . '</div>'
          . '</div>'
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
      . '<i class="bi bi-folder2-open"></i> Browse'
      . '</button>';
    
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

  private function renderStudyCards(array $studyCards, array $ontologyDefinitions): string {
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
        if ($platformLabel !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>Platform:</strong> ' . Html::escape($platformLabel) . '</p>';
        }
        
        $processLabel = trim((string) ($card['process_label'] ?? ''));
        if ($processLabel !== '') {
          $cardsHtml .= '<p class="mb-1"><strong>Process:</strong> ' . Html::escape($processLabel) . '</p>';
        }
        
        $pi = trim((string) ($card['principal_investigator'] ?? ''));
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

      $cardsHtml .= '<div class="std-study-meta">'
        . '<span class="badge bg-light text-dark">Variables: ' . (int) ($card['codebook_count'] ?? 0) . '</span>'
        . '<span class="badge bg-light text-dark">Components: ' . (int) ($card['component_count'] ?? 0) . '</span>'
        . '<span class="badge bg-light text-dark">Simulators: ' . (int) ($card['simulator_count'] ?? 0) . '</span>'
        . '<span class="badge bg-light text-dark">Instruments: ' . (int) ($card['instrument_count'] ?? 0) . '</span>'
        . '<span class="badge bg-light text-dark">Completeness: ' . (int) round(((float) ($card['completeness_score'] ?? 0.0)) * 100) . '%</span>'
        . '</div>';

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
        . '<a class="btn btn-sm btn-primary" href="' . Html::escape((string) ($card['manage_url'] ?? '#')) . '">Manage Study</a>'
        . ' <a class="btn btn-sm btn-secondary" href="' . Html::escape((string) ($card['edit_url'] ?? '#')) . '">Edit</a>'
        . '</div>';
      $cardsHtml .= '</article>';
    }

    if ($cardsHtml === '') {
      $cardsHtml = '<p class="text-muted">No studies are available in the current context.</p>';
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
  private function renderFilterSection(string $title, array $items, string $type, bool $hierarchical = FALSE): string {
    $html = '<details class="std-search-section std-search-source-section mt-4">';
    $html .= '<summary class="std-search-section-summary">';
    $html .= '<span class="std-search-section-title">' . Html::escape($title) . ' (' . count($items) . ')';
    if ($hierarchical) {
      $html .= ' <button type="button" class="btn btn-sm btn-secondary open-tree-modal" data-elementtype="' . Html::escape($type) . '" data-mode="modal">🔍</button>';
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

}
