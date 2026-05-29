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
    'questionnaire' => 'Questionnaires / Codebooks',
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
    $form['#attached']['drupalSettings']['stdStudySearch'] = [
      'weights' => StudySearchRanking::defaultWeights(),
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
        'ncit' => 'Procedure Type (NCIT)',
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

    $form['#attached']['drupalSettings']['stdStudySearch']['ontologyKeys'] = array_values(array_map('strval', array_keys($ontologyDefinitions)));

    $sidebarHtml = '';
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
          . '<p class="text-muted mb-3">Use the hierarchical variable browser to select filters and rank related studies by relevance.</p>'
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
          . '<strong id="std-visible-results">0</strong> studies visible'
          . '<span class="text-muted ms-2" id="std-ranking-indicator"></span>'
          . '</div>'
          . '<p id="std-study-empty-state" class="text-muted mb-3">Select at least one variable to display studies.</p>'
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
    $html = '<div class="std-search-section">';
    $html .= '<h4 class="std-search-section-title">' . Html::escape($title) . '</h4>';

    if (empty($variables)) {
      $html .= '<p class="text-muted mb-0">No variables were found in this source.</p>';
      $html .= '</div>';
      return $html;
    }

    $groups = [];
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
    }

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
    return $html;
  }

  private function renderOntologySection(string $title, array $options, string $ontology): string {
    $html = '<div class="std-search-section mt-4">';
    $html .= '<h4 class="std-search-section-title">' . Html::escape($title) . '</h4>';

    if (empty($options)) {
      $html .= '<p class="text-muted mb-0">No ontology terms were found.</p>';
      $html .= '</div>';
      return $html;
    }

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

      $html .= '<label class="std-search-checkbox">'
        . '<input type="checkbox" class="std-ontology-checkbox" data-ontology="' . Html::escape($ontology) . '" data-label="' . Html::escape($label) . '" data-uri="' . Html::escape($uri) . '" value="' . Html::escape($slug) . '">'
        . '<span>' . Html::escape($label) . '</span>';
      if ($uri !== '') {
        $html .= '<small class="std-ontology-uri">' . Html::escape($uri) . '</small>';
      }
      $html .= '</label>';
    }

    $html .= '</div>';
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
        . ' data-tags="' . Html::escape($this->joinTags($tags)) . '"'
        . ' data-simulator-tags="' . Html::escape($this->joinTags(is_array($sourceTags['simulator'] ?? NULL) ? $sourceTags['simulator'] : [])) . '"'
        . ' data-instrument-tags="' . Html::escape($this->joinTags(is_array($sourceTags['instrument'] ?? NULL) ? $sourceTags['instrument'] : [])) . '"'
        . ' data-questionnaire-tags="' . Html::escape($this->joinTags(is_array($sourceTags['questionnaire'] ?? NULL) ? $sourceTags['questionnaire'] : [])) . '"'
        . ' data-component-tags="' . Html::escape($this->joinTags(is_array($sourceTags['component'] ?? NULL) ? $sourceTags['component'] : [])) . '"'
        . $ontologyAttributes
        . ' data-completeness-score="' . Html::escape(number_format((float) ($card['completeness_score'] ?? 0.0), 4, '.', '')) . '"'
        . '>';

      $cardsHtml .= '<div class="std-study-card-header">';
      $cardsHtml .= '<h4>' . Html::escape((string) ($card['label'] ?? '')) . '</h4>';
      $cardsHtml .= '<p class="std-study-uri mb-2">' . Html::escape((string) ($card['uri'] ?? '')) . '</p>';
      $cardsHtml .= '</div>';

      $description = trim((string) ($card['description'] ?? ''));
      if ($description !== '') {
        $cardsHtml .= '<p class="std-study-description">' . Html::escape($description) . '</p>';
      }

      $cardsHtml .= '<div class="std-study-meta">'
        . '<span class="badge bg-light text-dark">Questionnaires: ' . (int) ($card['codebook_count'] ?? 0) . '</span>'
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

}
