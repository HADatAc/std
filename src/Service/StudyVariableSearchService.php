<?php

declare(strict_types=1);

namespace Drupal\std\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\std\Support\StudyFileTypeResolver;

/**
 * Aggregates study-search data sources and prepares normalized UI context.
 */
final class StudyVariableSearchService {

  private const SOURCE_KEYS = [
    'simulator',
    'instrument',
    'questionnaire',
    'component',
  ];

  public function __construct(
    private readonly object $apiConnector,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function buildContext(string $userEmail, bool $isAdmin, bool $isAuthenticated): array {
    $errors = [];

    $studies = $this->normalizeItems($this->loadElementsByType('study', $errors));
    $workflowPool = $this->normalizeItems($this->loadElementsByType('workflow', $errors));

    $studies = $this->applyVisibilityFilter($studies, $userEmail, $isAdmin, $isAuthenticated);
    $workflowPool = $this->applyVisibilityFilter($workflowPool, $userEmail, $isAdmin, $isAuthenticated);

    $variablesBySource = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];
    $ontologyFilters = [
      'uberon' => [],
      'ncit' => [],
    ];
    $studyCards = [];

    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }

      $studyUri = trim((string) ($study->uri ?? ''));
      if ($studyUri === '') {
        continue;
      }

      $codebookFields = $this->extractCodebookFields($studyUri, $errors);
      $associatedWorkflows = $this->findAssociatedWorkflowsForStudy($workflowPool, $studyUri);
      $workflowVariablesBySource = $this->extractWorkflowVariablesBySource($associatedWorkflows);

      $studyTags = [];
      $studyTagsBySource = [
        'simulator' => [],
        'instrument' => [],
        'questionnaire' => [],
        'component' => [],
      ];
      $studyOntologyTags = [
        'uberon' => [],
        'ncit' => [],
      ];

      foreach ($codebookFields as $fieldLabel) {
        $record = $this->registerVariable('questionnaire', $fieldLabel, $variablesBySource, $ontologyFilters);
        if ($record === NULL) {
          continue;
        }

        $studyTags[$record['slug']] = $record['slug'];
        $studyTagsBySource['questionnaire'][$record['slug']] = $record['slug'];
        $this->mergeOntologyTags($studyOntologyTags, $record['ontology']);
      }

      foreach ($workflowVariablesBySource as $source => $labels) {
        foreach ($labels as $label) {
          $record = $this->registerVariable($source, $label, $variablesBySource, $ontologyFilters);
          if ($record === NULL) {
            continue;
          }

          $studyTags[$record['slug']] = $record['slug'];
          $studyTagsBySource[$source][$record['slug']] = $record['slug'];
          $this->mergeOntologyTags($studyOntologyTags, $record['ontology']);
        }
      }

      $hasData = !empty($studyTagsBySource['questionnaire']) ? 1 : 0;
      $hasWorkflow = count($associatedWorkflows) > 0 ? 1 : 0;
      $hasImages = $this->studyHasMedicalImages($studyUri) ? 1 : 0;
      $completenessScore = round(($hasData + $hasWorkflow + $hasImages) / 3, 4);

      $studyCards[] = [
        'label' => trim((string) ($study->label ?? $study->title ?? $studyUri)),
        'uri' => $studyUri,
        'description' => trim((string) ($study->comment ?? '')),
        'manage_url' => Url::fromRoute('std.manage_study_elements', [
          'studyuri' => base64_encode($studyUri),
        ])->toString(),
        'codebook_count' => count($studyTagsBySource['questionnaire']),
        'component_count' => count($studyTagsBySource['component']),
        'simulator_count' => count($studyTagsBySource['simulator']),
        'instrument_count' => count($studyTagsBySource['instrument']),
        'tags' => array_values($studyTags),
        'source_tags' => [
          'simulator' => array_values($studyTagsBySource['simulator']),
          'instrument' => array_values($studyTagsBySource['instrument']),
          'questionnaire' => array_values($studyTagsBySource['questionnaire']),
          'component' => array_values($studyTagsBySource['component']),
        ],
        'ontology_tags' => [
          'uberon' => array_values($studyOntologyTags['uberon']),
          'ncit' => array_values($studyOntologyTags['ncit']),
        ],
        'has_data' => $hasData,
        'has_workflow' => $hasWorkflow,
        'has_images' => $hasImages,
        'completeness_score' => $completenessScore,
      ];
    }

    $variablesBySource = $this->normalizeSourceVariables($variablesBySource);
    $ontologyFilters = $this->normalizeOntologyFilters($ontologyFilters);
    usort($studyCards, fn(array $a, array $b) => strcasecmp((string) $a['label'], (string) $b['label']));

    return [
      'variables_by_source' => $variablesBySource,
      'ontology_filters' => $ontologyFilters,
      'study_cards' => $studyCards,
      'errors' => array_values(array_unique($errors)),
    ];
  }

  private function loadElementsByType(string $elementType, array &$errors): array {
    try {
      $response = $this->apiConnector->listByKeyword($elementType, '_', 9999, 0);
      $items = $this->apiConnector->parseObjectResponse($response, 'listByKeyword');
      return is_array($items) ? $items : [];
    }
    catch (\Throwable $e) {
      $errors[] = sprintf('Unable to load %s data from HASCOAPI.', $elementType);
      return [];
    }
  }

  private function applyVisibilityFilter(array $items, string $userEmail, bool $isAdmin, bool $isAuthenticated): array {
    $normalizedItems = $this->normalizeItems($items);

    if ($isAdmin) {
      return $normalizedItems;
    }

    $normalizedUserEmail = strtolower(trim($userEmail));

    return array_values(array_filter($normalizedItems, function ($item) use ($normalizedUserEmail, $isAuthenticated) {
      if (!is_object($item)) {
        return FALSE;
      }

      $status = $this->normalizeStatusValue((string) ($item->hasStatus ?? ''));
      $owner = strtolower(trim((string) ($item->hasSIRManagerEmail ?? '')));

      if ($isAuthenticated && $normalizedUserEmail !== '' && $owner !== '' && $owner === $normalizedUserEmail) {
        return TRUE;
      }

      return $status === 'current';
    }));
  }

  private function normalizeStatusValue(string $status): string {
    $raw = trim($status);
    if ($raw === '') {
      return '';
    }

    $fragment = parse_url($raw, PHP_URL_FRAGMENT);
    if (is_string($fragment) && $fragment !== '') {
      return strtolower($fragment);
    }

    return strtolower($raw);
  }

  private function extractCodebookFields(string $studyUri, array &$errors): array {
    $fields = [];

    try {
      $virtualColumnsRaw = $this->apiConnector->virtualColumnsByStudy($studyUri);
      $virtualColumns = $this->apiConnector->parseObjectResponse($virtualColumnsRaw, 'virtualColumnsByStudy');

      if (is_array($virtualColumns)) {
        foreach ($virtualColumns as $virtualColumn) {
          if (is_array($virtualColumn)) {
            $virtualColumn = (object) $virtualColumn;
          }

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
      $errors[] = sprintf('Unable to load codebook fields for study %s.', $studyUri);
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

      if ($this->valueMatchesStudy($workflow, $studyUri, 0)) {
        $out[] = $workflow;
      }
    }
    return $out;
  }

  private function valueMatchesStudy($value, string $studyUri, int $depth): bool {
    if ($depth > 7) {
      return FALSE;
    }

    if (is_string($value)) {
      $candidate = trim($value);
      if ($candidate === '') {
        return FALSE;
      }

      if ($candidate === $studyUri || rawurldecode($candidate) === $studyUri) {
        return TRUE;
      }

      $decoded = base64_decode($candidate, TRUE);
      if (is_string($decoded) && trim($decoded) !== '' && trim($decoded) === $studyUri) {
        return TRUE;
      }

      return FALSE;
    }

    if (is_array($value)) {
      foreach ($value as $innerValue) {
        if ($this->valueMatchesStudy($innerValue, $studyUri, $depth + 1)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    if (is_object($value)) {
      foreach (get_object_vars($value) as $field => $innerValue) {
        $fieldText = strtolower((string) $field);
        $studyField = in_array($fieldText, ['study', 'studyuri', 'hasstudy', 'hasstudyuri', 'hasassociatedstudy'], TRUE);

        if ($studyField && $this->valueMatchesStudy($innerValue, $studyUri, $depth + 1)) {
          return TRUE;
        }

        if ($this->valueMatchesStudy($innerValue, $studyUri, $depth + 1)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  private function extractWorkflowVariablesBySource(array $workflows): array {
    $bySource = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];

    $keywordMap = [
      'simulator' => ['simulator', 'platform'],
      'instrument' => ['instrument'],
      'questionnaire' => ['questionnaire', 'codebook'],
      'component' => ['component', 'detector', 'actuator'],
    ];

    foreach ($workflows as $workflow) {
      foreach ($keywordMap as $source => $keywords) {
        $this->collectStringsByKey($workflow, $keywords, $bySource[$source], 0, FALSE);
      }
    }

    foreach ($bySource as $source => $values) {
      ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
      $bySource[$source] = array_values($values);
    }

    return $bySource;
  }

  private function collectStringsByKey($value, array $keywords, array &$collector, int $depth, bool $collectAll): void {
    if ($depth > 7) {
      return;
    }

    if (is_string($value)) {
      if ($collectAll) {
        $clean = trim($value);
        if ($clean !== '' && strlen($clean) <= 140 && !str_starts_with($clean, 'http://') && !str_starts_with($clean, 'https://')) {
          $collector[$clean] = $clean;
        }
      }
      return;
    }

    if (is_array($value)) {
      foreach ($value as $innerValue) {
        $this->collectStringsByKey($innerValue, $keywords, $collector, $depth + 1, $collectAll);
      }
      return;
    }

    if (!is_object($value)) {
      return;
    }

    foreach (get_object_vars($value) as $key => $innerValue) {
      $keyText = strtolower((string) $key);
      $matchesKey = FALSE;
      foreach ($keywords as $keyword) {
        if (str_contains($keyText, $keyword)) {
          $matchesKey = TRUE;
          break;
        }
      }

      if (is_string($innerValue) && ($collectAll || $matchesKey)) {
        $clean = trim($innerValue);
        if ($clean !== '' && strlen($clean) <= 140 && !str_starts_with($clean, 'http://') && !str_starts_with($clean, 'https://')) {
          $collector[$clean] = $clean;
        }
      }

      if (is_object($innerValue) && isset($innerValue->label) && is_string($innerValue->label) && ($collectAll || $matchesKey)) {
        $label = trim($innerValue->label);
        if ($label !== '') {
          $collector[$label] = $label;
        }
      }

      if (is_array($innerValue) || is_object($innerValue)) {
        $this->collectStringsByKey($innerValue, $keywords, $collector, $depth + 1, $collectAll || $matchesKey);
      }
    }
  }

  private function registerVariable(string $source, string $label, array &$variablesBySource, array &$ontologyFilters): ?array {
    $cleanLabel = trim($label);
    if ($cleanLabel === '' || !isset($variablesBySource[$source])) {
      return NULL;
    }

    $slug = $this->slugify($cleanLabel);
    if ($slug === '') {
      return NULL;
    }

    if (!isset($variablesBySource[$source][$slug])) {
      $variablesBySource[$source][$slug] = [
        'slug' => $slug,
        'label' => $cleanLabel,
        'source' => $source,
        'ontology' => [
          'uberon' => [],
          'ncit' => [],
        ],
      ];
    }

    $ontologyTerms = $this->extractOntologyTermsFromLabel($cleanLabel);
    foreach (['uberon', 'ncit'] as $ontology) {
      foreach ($ontologyTerms[$ontology] as $term) {
        $variablesBySource[$source][$slug]['ontology'][$ontology][$term['slug']] = $term['slug'];
        $ontologyFilters[$ontology][$term['slug']] = $term;
      }
    }

    return [
      'slug' => $slug,
      'ontology' => [
        'uberon' => array_values($variablesBySource[$source][$slug]['ontology']['uberon']),
        'ncit' => array_values($variablesBySource[$source][$slug]['ontology']['ncit']),
      ],
    ];
  }

  private function mergeOntologyTags(array &$target, array $incoming): void {
    foreach (['uberon', 'ncit'] as $ontology) {
      foreach (($incoming[$ontology] ?? []) as $termSlug) {
        $target[$ontology][$termSlug] = $termSlug;
      }
    }
  }

  private function extractOntologyTermsFromLabel(string $label): array {
    $terms = [
      'uberon' => [],
      'ncit' => [],
    ];

    if (preg_match_all('/\bUBERON[:_]\d{3,}\b/i', $label, $matches)) {
      foreach ($matches[0] as $raw) {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
          continue;
        }
        $uri = 'UBERON:' . $digits;
        $slug = $this->slugify($uri);
        $terms['uberon'][$slug] = [
          'slug' => $slug,
          'uri' => $uri,
          'label' => $uri,
        ];
      }
    }

    if (preg_match_all('/\bNCIT[:_ ]?C?\d{2,}\b/i', $label, $matches)) {
      foreach ($matches[0] as $raw) {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
          continue;
        }
        $uri = 'NCIT:C' . $digits;
        $slug = $this->slugify($uri);
        $terms['ncit'][$slug] = [
          'slug' => $slug,
          'uri' => $uri,
          'label' => $uri,
        ];
      }
    }

    if (preg_match_all('/https?:\/\/\S+/i', $label, $matches)) {
      foreach ($matches[0] as $uriRaw) {
        $uri = trim($uriRaw);
        if ($uri === '') {
          continue;
        }

        $uriLower = strtolower($uri);
        if (str_contains($uriLower, 'uberon')) {
          $slug = $this->slugify($uri);
          $terms['uberon'][$slug] = [
            'slug' => $slug,
            'uri' => $uri,
            'label' => $uri,
          ];
        }
        if (str_contains($uriLower, 'ncit')) {
          $slug = $this->slugify($uri);
          $terms['ncit'][$slug] = [
            'slug' => $slug,
            'uri' => $uri,
            'label' => $uri,
          ];
        }
      }
    }

    return $terms;
  }

  private function normalizeSourceVariables(array $variablesBySource): array {
    $out = [];
    foreach (self::SOURCE_KEYS as $source) {
      $values = array_values($variablesBySource[$source] ?? []);
      usort($values, fn(array $a, array $b) => strcasecmp((string) $a['label'], (string) $b['label']));
      $out[$source] = $values;
    }
    return $out;
  }

  private function normalizeOntologyFilters(array $ontologyFilters): array {
    $out = ['uberon' => [], 'ncit' => []];
    foreach (['uberon', 'ncit'] as $ontology) {
      $values = array_values($ontologyFilters[$ontology] ?? []);
      usort($values, fn(array $a, array $b) => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
      $out[$ontology] = $values;
    }
    return $out;
  }

  private function studyHasMedicalImages(string $studyUri): bool {
    $studyKey = basename($studyUri);
    if ($studyKey === '') {
      return FALSE;
    }

    $directory = 'private://std/' . $studyKey . '/OHIF/';
    $realpath = $this->fileSystem->realpath($directory);
    if (!$realpath || !is_dir($realpath)) {
      return FALSE;
    }

    $entries = scandir($realpath);
    if (!is_array($entries)) {
      return FALSE;
    }

    foreach ($entries as $file) {
      if ($file === '.' || $file === '..') {
        continue;
      }
      if (StudyFileTypeResolver::isOhifFile((string) $file)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function slugify(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
      return '';
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim((string) $value, '-');
  }

  private function normalizeItems(array $items): array {
    $normalized = [];

    foreach ($items as $item) {
      if (is_object($item)) {
        $normalized[] = $item;
      }
      elseif (is_array($item)) {
        $normalized[] = (object) $item;
      }
    }

    return $normalized;
  }

}
