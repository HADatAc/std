<?php

namespace Drupal\std\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\rep\ManageOwnerFilter;

/**
 * Study search page with variable sidebar and AND/OR filtering.
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
    $form['#attached']['library'][] = 'std/study_variable_search';

    $api = \Drupal::service('rep.api_connector');
    $currentUser = \Drupal::currentUser();
    $userEmail = trim((string) $currentUser->getEmail());
    $isAdmin = ManageOwnerFilter::isAdmin();

    $studies = $this->loadStudies($api, $userEmail, $isAdmin, $currentUser->isAuthenticated());
    $workflowPool = $this->loadWorkflowPool($api, $userEmail, $isAdmin, $currentUser->isAuthenticated());

    $allCodebookFields = [];
    $allDetectorComponents = [];
    $studyCards = [];

    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }

      $studyUri = trim((string) ($study->uri ?? ''));
      if ($studyUri === '') {
        continue;
      }

      $studyLabel = trim((string) ($study->label ?? $study->title ?? $studyUri));
      $studyDescription = trim((string) ($study->comment ?? ''));

      $codebookFields = $this->extractCodebookFields($api, $studyUri);
      $associatedWorkflows = $this->findAssociatedWorkflowsForStudy($workflowPool, $studyUri);
      $detectorComponents = $this->extractDetectorComponents($associatedWorkflows);

      foreach ($codebookFields as $field) {
        $allCodebookFields[$field] = $field;
      }
      foreach ($detectorComponents as $component) {
        $allDetectorComponents[$component] = $component;
      }

      $tags = [];
      foreach (array_merge($codebookFields, $detectorComponents) as $value) {
        $slug = $this->slugify($value);
        if ($slug !== '') {
          $tags[$slug] = $slug;
        }
      }

      $manageUrl = Url::fromRoute('std.manage_study_elements', [
        'studyuri' => base64_encode($studyUri),
      ])->toString();

      $studyCards[] = [
        'label' => $studyLabel,
        'uri' => $studyUri,
        'description' => $studyDescription,
        'manage_url' => $manageUrl,
        'codebook_count' => count($codebookFields),
        'component_count' => count($detectorComponents),
        'tags' => implode('|', array_values($tags)),
      ];
    }

    ksort($allCodebookFields, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($allDetectorComponents, SORT_NATURAL | SORT_FLAG_CASE);

    $sidebarHtml = '';

    $sidebarHtml .= '<div class="std-search-section">';
    $sidebarHtml .= '<h4 class="std-search-section-title">Codebook Fields</h4>';
    if (empty($allCodebookFields)) {
      $sidebarHtml .= '<p class="text-muted mb-0">No codebook fields were found.</p>';
    }
    else {
      foreach ($allCodebookFields as $field) {
        $slug = $this->slugify($field);
        if ($slug === '') {
          continue;
        }
        $sidebarHtml .= '<label class="std-search-checkbox">'
          . '<input type="checkbox" class="study-variable-checkbox" data-group="codebook" value="' . Html::escape($slug) . '">'
          . '<span>' . Html::escape($field) . '</span>'
          . '</label>';
      }
    }
    $sidebarHtml .= '</div>';

    $sidebarHtml .= '<div class="std-search-section mt-4">';
    $sidebarHtml .= '<h4 class="std-search-section-title">Detector Components</h4>';
    if (empty($allDetectorComponents)) {
      $sidebarHtml .= '<p class="text-muted mb-0">No detector components were found.</p>';
    }
    else {
      foreach ($allDetectorComponents as $component) {
        $slug = $this->slugify($component);
        if ($slug === '') {
          continue;
        }
        $sidebarHtml .= '<label class="std-search-checkbox">'
          . '<input type="checkbox" class="study-variable-checkbox" data-group="detector" value="' . Html::escape($slug) . '">'
          . '<span>' . Html::escape($component) . '</span>'
          . '</label>';
      }
    }
    $sidebarHtml .= '</div>';

    $cardsHtml = '';
    foreach ($studyCards as $card) {
      $cardsHtml .= '<article class="std-study-card" data-tags="' . Html::escape($card['tags']) . '">';
      $cardsHtml .= '<div class="std-study-card-header">';
      $cardsHtml .= '<h4>' . Html::escape($card['label']) . '</h4>';
      $cardsHtml .= '<p class="std-study-uri mb-2">' . Html::escape($card['uri']) . '</p>';
      $cardsHtml .= '</div>';

      if ($card['description'] !== '') {
        $cardsHtml .= '<p class="std-study-description">' . Html::escape($card['description']) . '</p>';
      }

      $cardsHtml .= '<div class="std-study-meta">'
        . '<span class="badge bg-light text-dark">Codebook: ' . (int) $card['codebook_count'] . '</span>'
        . '<span class="badge bg-light text-dark">Components: ' . (int) $card['component_count'] . '</span>'
        . '</div>';

      $cardsHtml .= '<div class="std-study-actions mt-3">'
        . '<a class="btn btn-sm btn-primary" href="' . Html::escape($card['manage_url']) . '">Manage Study</a>'
        . '</div>';
      $cardsHtml .= '</article>';
    }

    if ($cardsHtml === '') {
      $cardsHtml = '<p class="text-muted">No studies are available in the current context.</p>';
    }

    $form['search_page'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section id="std-study-variable-search" class="std-study-search">'
          . '<header class="std-search-header">'
          . '<p class="text-muted mb-3">Select variables from the left panel to filter studies using AND/OR logic.</p>'
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
          . '<div class="std-results-header mb-2">'
          . '<strong id="std-visible-results">0</strong> studies visible'
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

  private function loadStudies($api, string $userEmail, bool $isAdmin, bool $isAuthenticated): array {
    try {
      if ($isAuthenticated && !$isAdmin && $userEmail !== '') {
        $raw = $api->listByManagerEmail('study', $userEmail, 9999, 0);
        $items = $api->parseObjectResponse($raw, 'listByManagerEmail');
      }
      else {
        $raw = $api->listByKeyword('study', '_', 9999, 0);
        $items = $api->parseObjectResponse($raw, 'listByKeyword');
      }

      return is_array($items) ? $items : [];
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  private function loadWorkflowPool($api, string $userEmail, bool $isAdmin, bool $isAuthenticated): array {
    try {
      if ($isAuthenticated && !$isAdmin && $userEmail !== '') {
        $raw = $api->listByManagerEmail('workflow', $userEmail, 9999, 0);
        $items = $api->parseObjectResponse($raw, 'listByManagerEmail');
      }
      else {
        $raw = $api->listByKeyword('workflow', '_', 9999, 0);
        $items = $api->parseObjectResponse($raw, 'listByKeyword');
      }

      return is_array($items) ? $items : [];
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  private function extractCodebookFields($api, string $studyUri): array {
    $fields = [];

    try {
      $virtualColumnsRaw = $api->virtualColumnsByStudy($studyUri);
      $virtualColumns = $api->parseObjectResponse($virtualColumnsRaw, 'virtualColumnsByStudy');

      if (is_array($virtualColumns)) {
        foreach ($virtualColumns as $virtualColumn) {
          if (!is_object($virtualColumn)) {
            continue;
          }

          $candidates = [
            (string) ($virtualColumn->label ?? ''),
            (string) ($virtualColumn->socreference ?? ''),
            (string) ($virtualColumn->groundingLabel ?? ''),
          ];

          foreach ($candidates as $candidate) {
            $clean = trim($candidate);
            if ($clean !== '') {
              $fields[$clean] = $clean;
            }
          }
        }
      }
    }
    catch (\Throwable $e) {
      return [];
    }

    ksort($fields, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($fields);
  }

  private function findAssociatedWorkflowsForStudy(array $workflowPool, string $studyUri): array {
    $out = [];
    foreach ($workflowPool as $workflow) {
      if (!is_object($workflow)) {
        continue;
      }

      if ($this->valueMatchesStudy($workflow, $studyUri)) {
        $out[] = $workflow;
      }
    }
    return $out;
  }

  private function valueMatchesStudy($value, string $studyUri): bool {
    if (is_string($value)) {
      $candidate = trim($value);
      if ($candidate === '') {
        return FALSE;
      }

      if ($candidate === $studyUri) {
        return TRUE;
      }

      $decoded = base64_decode($candidate, TRUE);
      return is_string($decoded) && trim($decoded) === $studyUri;
    }

    if (is_object($value)) {
      foreach (get_object_vars($value) as $key => $nested) {
        if (in_array($key, ['study', 'studyUri', 'hasStudy', 'hasStudyUri', 'hasAssociatedStudy'], TRUE)) {
          if ($this->valueMatchesStudy($nested, $studyUri)) {
            return TRUE;
          }
        }
        elseif ($this->valueMatchesStudy($nested, $studyUri)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    if (is_array($value)) {
      foreach ($value as $nested) {
        if ($this->valueMatchesStudy($nested, $studyUri)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  private function extractDetectorComponents(array $workflows): array {
    $components = [];
    $keywords = ['component', 'detector'];

    foreach ($workflows as $workflow) {
      $this->collectStringsByKey($workflow, $keywords, $components, 0);
    }

    ksort($components, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($components);
  }

  private function collectStringsByKey($value, array $keywords, array &$collector, int $depth): void {
    if ($depth > 6) {
      return;
    }

    if (is_string($value)) {
      $clean = trim($value);
      if ($clean !== '' && strlen($clean) <= 120 && !str_starts_with($clean, 'http://') && !str_starts_with($clean, 'https://')) {
        $collector[$clean] = $clean;
      }
      return;
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        $this->collectStringsByKey($item, $keywords, $collector, $depth + 1);
      }
      return;
    }

    if (is_object($value)) {
      foreach (get_object_vars($value) as $key => $item) {
        $keyText = strtolower((string) $key);
        $matchesKey = FALSE;
        foreach ($keywords as $keyword) {
          if (str_contains($keyText, $keyword)) {
            $matchesKey = TRUE;
            break;
          }
        }

        if ($matchesKey) {
          if (is_object($item) || is_array($item)) {
            if (is_object($item) && isset($item->label) && is_string($item->label)) {
              $label = trim($item->label);
              if ($label !== '') {
                $collector[$label] = $label;
              }
            }
            $this->collectStringsByKey($item, $keywords, $collector, $depth + 1);
            continue;
          }

          if (is_string($item)) {
            $clean = trim($item);
            if ($clean !== '' && strlen($clean) <= 120 && !str_starts_with($clean, 'http://') && !str_starts_with($clean, 'https://')) {
              $collector[$clean] = $clean;
            }
          }
        }
      }
    }
  }

  private function slugify(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
      return '';
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim((string) $value, '-');
  }

}
