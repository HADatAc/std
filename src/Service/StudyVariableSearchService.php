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

  /**
   * Cache response-option labels resolved per codebook URI.
   *
   * @var array<string, string[]>
   */
  private array $codebookResponseOptionCache = [];

  /**
   * Cache response-option display labels resolved per response option URI.
   *
   * @var array<string, string>
   */
  private array $responseOptionLabelCache = [];

  public function __construct(
    private readonly object $apiConnector,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function buildContext(string $userEmail, bool $isAdmin, bool $isAuthenticated): array {
    $errors = [];
    $ontologyDefinitions = $this->getOntologyDefinitions();
    $ontologyKeys = array_keys($ontologyDefinitions);

    $studies = $this->normalizeItems($this->loadElementsByType('study', $errors));
    $workflowPool = $this->normalizeItems($this->loadElementsByType('workflow', $errors));
    $codebookPool = $this->normalizeItems($this->loadElementsByType('codebook', $errors));

    $studies = $this->applyVisibilityFilter($studies, $userEmail, $isAdmin, $isAuthenticated);
    $workflowPool = $this->applyVisibilityFilter($workflowPool, $userEmail, $isAdmin, $isAuthenticated);
    $codebookPool = $this->applyVisibilityFilter($codebookPool, $userEmail, $isAdmin, $isAuthenticated);

    $variablesBySource = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];
    $ontologyFilters = $this->emptyOntologyTagMap($ontologyKeys);
    $studyCards = [];

    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }

      $studyUri = trim((string) ($study->uri ?? ''));
      if ($studyUri === '') {
        continue;
      }

      $codebookFields = $this->extractCodebookFields($studyUri, $study, $codebookPool, $errors);
      $associatedWorkflows = $this->findAssociatedWorkflowsForStudy($workflowPool, $studyUri);
      $workflowVariablesBySource = $this->extractWorkflowVariablesBySource($associatedWorkflows);
      $socVariablesBySource = $this->extractSocVariablesBySource($studyUri, $errors);
      $sourceVariablesByStudy = $this->mergeSourceVariableBuckets(
        $workflowVariablesBySource,
        $socVariablesBySource,
      );

      $studyTags = [];
      $studyTagsBySource = [
        'simulator' => [],
        'instrument' => [],
        'questionnaire' => [],
        'component' => [],
      ];
      $studyOntologyTags = $this->emptyOntologyTagMap($ontologyKeys);

      foreach ($codebookFields as $fieldLabel) {
        $record = $this->registerVariable('questionnaire', $fieldLabel, $variablesBySource, $ontologyFilters, $ontologyDefinitions);
        if ($record === NULL) {
          continue;
        }

        $studyTags[$record['slug']] = $record['slug'];
        $studyTagsBySource['questionnaire'][$record['slug']] = $record['slug'];
        $this->mergeOntologyTags($studyOntologyTags, $record['ontology'], $ontologyDefinitions);
      }

      foreach ($sourceVariablesByStudy as $source => $labels) {
        foreach ($labels as $label) {
          $record = $this->registerVariable($source, $label, $variablesBySource, $ontologyFilters, $ontologyDefinitions);
          if ($record === NULL) {
            continue;
          }

          $studyTags[$record['slug']] = $record['slug'];
          $studyTagsBySource[$source][$record['slug']] = $record['slug'];
          $this->mergeOntologyTags($studyOntologyTags, $record['ontology'], $ontologyDefinitions);
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
        'ontology_tags' => $this->normalizeOntologyTagMap($studyOntologyTags, $ontologyKeys),
        'has_data' => $hasData,
        'has_workflow' => $hasWorkflow,
        'has_images' => $hasImages,
        'completeness_score' => $completenessScore,
      ];
    }

    $variablesBySource = $this->normalizeSourceVariables($variablesBySource);
    $ontologyFilters = $this->normalizeOntologyFilters($ontologyFilters, $ontologyDefinitions);
    usort($studyCards, fn(array $a, array $b) => strcasecmp((string) $a['label'], (string) $b['label']));

    $ontologyTitles = [];
    foreach ($ontologyDefinitions as $ontologyKey => $ontologyDefinition) {
      $ontologyTitles[$ontologyKey] = (string) ($ontologyDefinition['title'] ?? strtoupper($ontologyKey));
    }

    return [
      'variables_by_source' => $variablesBySource,
      'ontology_definitions' => $ontologyTitles,
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

  private function extractCodebookFields(string $studyUri, ?object $study, array $codebookPool, array &$errors): array {
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
    }

    // Fallback for freshly-created studies/codebooks where virtual columns
    // are not populated yet: expose response-option labels from codebooks.
    if (empty($fields)) {
      foreach ($this->extractFallbackCodebookFieldsForStudy($study, $codebookPool, $errors) as $label) {
        $clean = trim($label);
        if ($clean !== '') {
          $fields[$clean] = $clean;
        }
      }
    }

    ksort($fields, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($fields);
  }

  private function extractFallbackCodebookFieldsForStudy(?object $study, array $codebookPool, array &$errors): array {
    $fields = [];
    $studyOwner = strtolower(trim((string) ($study->hasSIRManagerEmail ?? '')));

    foreach ($codebookPool as $codebook) {
      if (!is_object($codebook)) {
        continue;
      }

      $codebookUri = trim((string) ($codebook->uri ?? ''));
      if ($codebookUri === '') {
        continue;
      }

      $codebookOwner = strtolower(trim((string) ($codebook->hasSIRManagerEmail ?? '')));
      if ($studyOwner !== '' && $codebookOwner !== '' && $studyOwner !== $codebookOwner) {
        continue;
      }

      foreach ($this->getCodebookResponseOptionLabels($codebookUri, $errors) as $label) {
        $clean = trim($label);
        if ($clean !== '') {
          $fields[$clean] = $clean;
        }
      }
    }

    ksort($fields, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($fields);
  }

  private function getCodebookResponseOptionLabels(string $codebookUri, array &$errors): array {
    if (isset($this->codebookResponseOptionCache[$codebookUri])) {
      return $this->codebookResponseOptionCache[$codebookUri];
    }

    if (!method_exists($this->apiConnector, 'codebookSlotList')) {
      $this->codebookResponseOptionCache[$codebookUri] = [];
      return [];
    }

    $labels = [];
    try {
      $slotsRaw = $this->apiConnector->codebookSlotList($codebookUri);
      $slots = $this->apiConnector->parseObjectResponse($slotsRaw, 'codebookSlotList');
      $slots = is_array($slots) ? $slots : [];

      foreach ($slots as $slot) {
        if (is_array($slot)) {
          $slot = (object) $slot;
        }
        if (!is_object($slot)) {
          continue;
        }

        $responseOptionUri = trim((string) ($slot->hasResponseOption ?? ''));
        if ($responseOptionUri === '') {
          continue;
        }

        $label = $this->getResponseOptionDisplayLabel($responseOptionUri, $errors);
        if ($label !== '') {
          $labels[$label] = $label;
        }
      }
    }
    catch (\Throwable $e) {
      $errors[] = sprintf('Unable to load codebook slot data for codebook %s.', $codebookUri);
    }

    ksort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    $this->codebookResponseOptionCache[$codebookUri] = array_values($labels);
    return $this->codebookResponseOptionCache[$codebookUri];
  }

  private function getResponseOptionDisplayLabel(string $responseOptionUri, array &$errors): string {
    if (isset($this->responseOptionLabelCache[$responseOptionUri])) {
      return $this->responseOptionLabelCache[$responseOptionUri];
    }

    if (!method_exists($this->apiConnector, 'getUri')) {
      $this->responseOptionLabelCache[$responseOptionUri] = '';
      return '';
    }

    $label = '';
    try {
      $responseOptionRaw = $this->apiConnector->getUri($responseOptionUri);
      $responseOption = $this->apiConnector->parseObjectResponse($responseOptionRaw, 'getUri');

      if (is_array($responseOption)) {
        $responseOption = (object) $responseOption;
      }

      if (is_object($responseOption)) {
        $content = trim((string) ($responseOption->hasContent ?? ''));
        $fallbackLabel = trim((string) ($responseOption->label ?? ''));
        $label = $content !== '' ? $content : $fallbackLabel;
      }
    }
    catch (\Throwable $e) {
      $errors[] = sprintf('Unable to load response option %s.', $responseOptionUri);
    }

    $this->responseOptionLabelCache[$responseOptionUri] = $label;
    return $label;
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

  private function extractSocVariablesBySource(string $studyUri, array &$errors): array {
    $bySource = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];

    if (!method_exists($this->apiConnector, 'studyObjectCollectionsByStudy') || !method_exists($this->apiConnector, 'studyObjectsBySOCwithPage')) {
      return $bySource;
    }

    try {
      $socsRaw = $this->apiConnector->studyObjectCollectionsByStudy($studyUri);
      $socs = $this->apiConnector->parseObjectResponse($socsRaw, 'studyObjectCollectionsByStudy');
      $socs = $this->normalizeItems(is_array($socs) ? $socs : []);

      foreach ($socs as $soc) {
        $socUri = trim((string) ($soc->uri ?? ''));
        if ($socUri === '') {
          continue;
        }

        $socSourceHint = $this->inferSourceFromHints([
          (string) ($soc->hasSOCReference ?? ''),
          (string) ($soc->socreference ?? ''),
          (string) ($soc->hasGroundingLabel ?? ''),
          (string) ($soc->groundingLabel ?? ''),
          (string) ($soc->label ?? ''),
          (string) ($soc->comment ?? ''),
          (string) (($soc->virtualColumn->hasSOCReference ?? '') ?: ($soc->virtualColumn->socreference ?? '')),
          (string) ($soc->virtualColumn->label ?? ''),
          (string) ($soc->virtualColumn->hasGroundingLabel ?? ''),
        ]);

        $objectsRaw = $this->apiConnector->studyObjectsBySOCwithPage($socUri, 999, 0);
        $objects = $this->apiConnector->parseObjectResponse($objectsRaw, 'studyObjectsBySOCwithPage');
        $objects = $this->normalizeItems(is_array($objects) ? $objects : []);

        foreach ($objects as $object) {
          $source = $this->inferSourceFromHints([
            (string) ($object->typeLabel ?? ''),
            (string) ($object->typeUri ?? ''),
            (string) ($object->hascoTypeLabel ?? ''),
            (string) ($object->hascoTypeUri ?? ''),
            (string) (($object->isMemberOf->hasSOCReference ?? '') ?: ($object->isMemberOf->socreference ?? '')),
            (string) ($object->isMemberOf->hasGroundingLabel ?? ''),
            (string) (($object->isMemberOf->virtualColumn->hasSOCReference ?? '') ?: ($object->isMemberOf->virtualColumn->socreference ?? '')),
            (string) ($object->isMemberOf->virtualColumn->label ?? ''),
            (string) ($object->isMemberOf->virtualColumn->hasGroundingLabel ?? ''),
            $socSourceHint,
          ]);

          if ($source === '') {
            continue;
          }

          $label = $this->resolveStudyObjectDisplayLabel($object);
          if ($label === '') {
            continue;
          }

          $bySource[$source][$label] = $label;
        }
      }
    }
    catch (\Throwable $e) {
      $errors[] = sprintf('Unable to load study object variables for study %s.', $studyUri);
    }

    foreach ($bySource as $source => $values) {
      ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
      $bySource[$source] = array_values($values);
    }

    return $bySource;
  }

  private function mergeSourceVariableBuckets(array ...$buckets): array {
    $merged = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];

    foreach ($buckets as $bucket) {
      foreach (self::SOURCE_KEYS as $source) {
        $values = is_array($bucket[$source] ?? NULL) ? $bucket[$source] : [];
        foreach ($values as $value) {
          $label = trim((string) $value);
          if ($label === '') {
            continue;
          }
          $merged[$source][$label] = $label;
        }
      }
    }

    foreach ($merged as $source => $values) {
      ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
      $merged[$source] = array_values($values);
    }

    return $merged;
  }

  private function inferSourceFromHints(array $hints): string {
    foreach ($hints as $hint) {
      $text = strtolower(trim((string) $hint));
      if ($text === '') {
        continue;
      }

      if (str_contains($text, 'simulator')) {
        return 'simulator';
      }

      if (str_contains($text, 'instrument') || str_contains($text, 'device')) {
        return 'instrument';
      }

      if (str_contains($text, 'component') || str_contains($text, 'actuator') || str_contains($text, 'detector')) {
        return 'component';
      }

      if (str_contains($text, 'questionnaire') || str_contains($text, 'codebook')) {
        return 'questionnaire';
      }
    }

    return '';
  }

  private function resolveStudyObjectDisplayLabel(object $object): string {
    $typeLabel = trim((string) ($object->typeLabel ?? ''));
    $normalizedTypeLabel = strtolower($typeLabel);
    $genericTypeLabels = [
      'entity entry point',
      'entity',
      'class',
      'study object',
    ];

    if ($typeLabel !== '' && !in_array($normalizedTypeLabel, $genericTypeLabels, TRUE)) {
      return $typeLabel;
    }

    $originalIdLabel = trim((string) ($object->originalIdLabel ?? ''));
    if ($originalIdLabel !== '') {
      return $originalIdLabel;
    }

    return trim((string) ($object->label ?? ''));
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

  private function registerVariable(string $source, string $label, array &$variablesBySource, array &$ontologyFilters, array $ontologyDefinitions): ?array {
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
        'ontology' => $this->emptyOntologyTagMap(array_keys($ontologyDefinitions)),
      ];
    }

    $ontologyTerms = $this->extractOntologyTermsFromLabel($cleanLabel, $ontologyDefinitions);
    foreach ($ontologyDefinitions as $ontology => $ontologyDefinition) {
      foreach (($ontologyTerms[$ontology] ?? []) as $term) {
        $variablesBySource[$source][$slug]['ontology'][$ontology][$term['slug']] = $term['slug'];
        $ontologyFilters[$ontology][$term['slug']] = $term;
      }
    }

    return [
      'slug' => $slug,
      'ontology' => $this->normalizeOntologyTagMap($variablesBySource[$source][$slug]['ontology'], array_keys($ontologyDefinitions)),
    ];
  }

  private function mergeOntologyTags(array &$target, array $incoming, array $ontologyDefinitions): void {
    foreach ($ontologyDefinitions as $ontology => $ontologyDefinition) {
      foreach (($incoming[$ontology] ?? []) as $termSlug) {
        $target[$ontology][$termSlug] = $termSlug;
      }
    }
  }

  private function extractOntologyTermsFromLabel(string $label, array $ontologyDefinitions): array {
    $terms = $this->emptyOntologyTagMap(array_keys($ontologyDefinitions));

    foreach ($ontologyDefinitions as $ontology => $ontologyDefinition) {
      $tokenRegexes = is_array($ontologyDefinition['token_regexes'] ?? NULL)
        ? $ontologyDefinition['token_regexes']
        : [];

      foreach ($tokenRegexes as $tokenRegex) {
        $regex = trim((string) $tokenRegex);
        if ($regex === '') {
          continue;
        }

        if (@preg_match_all($regex, $label, $matches) === FALSE) {
          continue;
        }

        foreach (($matches[0] ?? []) as $rawToken) {
          $uri = $this->buildOntologyUriFromToken((string) $rawToken, $ontologyDefinition);
          if ($uri === '') {
            continue;
          }

          $slug = $this->slugify($uri);
          if ($slug === '') {
            continue;
          }

          $terms[$ontology][$slug] = [
            'slug' => $slug,
            'uri' => $uri,
            'label' => $uri,
          ];
        }
      }
    }

    if (preg_match_all('/https?:\/\/\S+/i', $label, $matches) !== FALSE) {
      foreach (($matches[0] ?? []) as $uriRaw) {
        $uri = trim((string) $uriRaw);
        if ($uri === '') {
          continue;
        }

        $uriLower = strtolower($uri);
        foreach ($ontologyDefinitions as $ontology => $ontologyDefinition) {
          $keywords = is_array($ontologyDefinition['uri_keywords'] ?? NULL)
            ? $ontologyDefinition['uri_keywords']
            : [];

          foreach ($keywords as $keyword) {
            $keywordText = strtolower(trim((string) $keyword));
            if ($keywordText === '' || !str_contains($uriLower, $keywordText)) {
              continue;
            }

            $slug = $this->slugify($uri);
            if ($slug === '') {
              continue;
            }

            $terms[$ontology][$slug] = [
              'slug' => $slug,
              'uri' => $uri,
              'label' => $uri,
            ];
            break;
          }
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

  private function normalizeOntologyFilters(array $ontologyFilters, array $ontologyDefinitions): array {
    $out = $this->emptyOntologyTagMap(array_keys($ontologyDefinitions));
    foreach ($ontologyDefinitions as $ontology => $ontologyDefinition) {
      $values = array_values($ontologyFilters[$ontology] ?? []);
      usort($values, fn(array $a, array $b) => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
      $out[$ontology] = $values;
    }
    return $out;
  }

  private function normalizeOntologyTagMap(array $tagMap, array $ontologyKeys): array {
    $out = $this->emptyOntologyTagMap($ontologyKeys);
    foreach ($ontologyKeys as $ontology) {
      $out[$ontology] = array_values($tagMap[$ontology] ?? []);
    }
    return $out;
  }

  private function emptyOntologyTagMap(array $ontologyKeys): array {
    $out = [];
    foreach ($ontologyKeys as $ontology) {
      $out[(string) $ontology] = [];
    }
    return $out;
  }

  private function getOntologyDefinitions(): array {
    $defaults = [
      'uberon' => [
        'title' => 'Anatomical Category (UBERON)',
        'token_regexes' => ['/\bUBERON[:_]\d{3,}\b/i'],
        'uri_template' => 'UBERON:%s',
        'uri_keywords' => ['uberon'],
      ],
      'ncit' => [
        'title' => 'Procedure Type (NCIT)',
        'token_regexes' => ['/\bNCIT[:_ ]?C?\d{2,}\b/i'],
        'uri_template' => 'NCIT:C%s',
        'uri_keywords' => ['ncit'],
      ],
    ];

    $configured = \Drupal::config('std.settings')->get('study_search_ontologies');
    if (!is_array($configured) || empty($configured)) {
      return $defaults;
    }

    $out = [];
    foreach ($configured as $ontologyKey => $definition) {
      $normalizedKey = $this->normalizeOntologyKey((string) $ontologyKey);
      if ($normalizedKey === '' || !is_array($definition)) {
        continue;
      }

      $base = is_array($defaults[$normalizedKey] ?? NULL)
        ? $defaults[$normalizedKey]
        : [
          'title' => strtoupper($normalizedKey),
          'token_regexes' => [],
          'uri_template' => '',
          'uri_keywords' => [$normalizedKey],
        ];

      $title = trim((string) ($definition['title'] ?? $base['title']));
      $tokenRegexes = is_array($definition['token_regexes'] ?? NULL)
        ? array_values(array_filter(array_map(fn($value) => trim((string) $value), $definition['token_regexes']), fn(string $value) => $value !== ''))
        : $base['token_regexes'];
      $uriTemplate = trim((string) ($definition['uri_template'] ?? $base['uri_template']));
      $uriKeywords = is_array($definition['uri_keywords'] ?? NULL)
        ? array_values(array_filter(array_map(fn($value) => strtolower(trim((string) $value)), $definition['uri_keywords']), fn(string $value) => $value !== ''))
        : $base['uri_keywords'];

      if (empty($tokenRegexes) && empty($uriKeywords)) {
        continue;
      }

      $out[$normalizedKey] = [
        'title' => $title !== '' ? $title : strtoupper($normalizedKey),
        'token_regexes' => $tokenRegexes,
        'uri_template' => $uriTemplate,
        'uri_keywords' => $uriKeywords,
      ];
    }

    return !empty($out) ? $out : $defaults;
  }

  private function buildOntologyUriFromToken(string $token, array $ontologyDefinition): string {
    $cleanToken = trim($token);
    if ($cleanToken === '') {
      return '';
    }

    $uriTemplate = trim((string) ($ontologyDefinition['uri_template'] ?? ''));
    if ($uriTemplate !== '' && str_contains($uriTemplate, '%s')) {
      $digits = preg_replace('/\D+/', '', $cleanToken) ?? '';
      if ($digits !== '') {
        return sprintf($uriTemplate, $digits);
      }
    }

    if ($uriTemplate !== '' && !str_contains($uriTemplate, '%s')) {
      return $uriTemplate;
    }

    return str_replace('_', ':', $cleanToken);
  }

  private function normalizeOntologyKey(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
      return '';
    }

    $normalized = preg_replace('/[^a-z0-9_\-]+/', '-', $normalized) ?? '';
    return trim($normalized, '-');
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
