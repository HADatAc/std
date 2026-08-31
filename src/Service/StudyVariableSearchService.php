<?php

declare(strict_types=1);

namespace Drupal\std\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\std\Support\StudyFileTypeResolver;

/**
 * Aggregates study-search data sources and prepares normalized UI context.
 */
final class StudyVariableSearchService {

  private const CONTEXT_CACHE_SCHEMA = 1;
  private const CONTEXT_CACHE_TAG = 'std_study_search_context';
  private const CONTEXT_CACHE_KEY_PREFIX = 'std_study_context:';
  private const SCENARIO_CACHE_KEY_PREFIX = 'std_study_scenario:';
  private const SOC_VARIABLE_CACHE_SCHEMA = 2;

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

  /**
   * Cache organization ownership scopes (organization + parent organization).
   *
   * @var array<string, string[]>
   */
  private array $organizationScopeCache = [];

  public function __construct(
    private readonly object $apiConnector,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function buildContext(string $userEmail, bool $isAdmin, bool $isAuthenticated): array {
    $cacheBin = \Drupal::service('cache.std_study_search');
    $contextCacheKey = $this->buildContextCacheKey($userEmail, $isAdmin, $isAuthenticated);
    // Always rebuild search context so newly ingested scenarios appear
    // immediately without requiring manual cache invalidation.

    $errors = [];
    $ontologyDefinitions = $this->getOntologyDefinitions();
    $ontologyKeys = array_keys($ontologyDefinitions);
    $contextCacheTags = [self::CONTEXT_CACHE_TAG, 'std_study_soc_vars'];

    $workflowPool = $this->normalizeItems($this->loadElementsByType('workflow', $errors));
    // Load all Study entities (plus ProcessBasedStudy via dedicated endpoint).
    $studies = $this->normalizeItems($this->loadElementsByType('study', $errors));
    $codebookPool = $this->normalizeItems($this->loadElementsByType('codebook', $errors));
    $semanticVariablePool = $this->normalizeItems($this->loadElementsByType('semanticvariable', $errors));

    $studies = $this->applyVisibilityFilter($studies, $userEmail, $isAdmin, $isAuthenticated);
    $workflowPool = $this->applyVisibilityFilter($workflowPool, $userEmail, $isAdmin, $isAuthenticated);
    $codebookPool = $this->applyVisibilityFilter($codebookPool, $userEmail, $isAdmin, $isAuthenticated);
    $semanticVariablePool = $this->applyVisibilityFilter($semanticVariablePool, $userEmail, $isAdmin, $isAuthenticated);

    // Extract metadata for new filters
    $organizations = $this->extractOrganizations($studies);
    $processMetadataMap = $this->extractProcessMetadata($studies, $errors);
    $platformCandidatesByOrganization = $this->extractPlatformCandidatesByOrganization($studies, $errors);
    $platformFilters = [];

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

      $studyUri = $this->normalizePmsrDisplayUri(trim((string) ($study->uri ?? '')));
      if ($studyUri === '') {
        continue;
      }
      $processUri = $this->normalizePmsrDisplayUri(trim((string) ($study->processUri ?? '')));
      $contextCacheTags[] = 'std_study:' . md5($studyUri);

      $semanticVariableFields = $this->extractSemanticVariableFields($studyUri, $semanticVariablePool);
      $codebookFields = !empty($semanticVariableFields)
        ? $semanticVariableFields
        : $this->extractCodebookFields($studyUri, $study, $codebookPool, $errors);
      $associatedWorkflows = $this->findAssociatedWorkflowsForStudy($workflowPool, $studyUri);
      $workflowVariablesBySource = $this->extractWorkflowVariablesBySource($associatedWorkflows);
      $socVariablesBySource = $this->extractSocVariablesBySourceCached($studyUri, $errors);
      $sourceVariablesByStudy = $this->mergeSourceVariableBuckets(
        $workflowVariablesBySource,
        $socVariablesBySource,
      );
      $sourceVariablesByStudy = $this->mergeSourceVariableBuckets(
        $sourceVariablesByStudy,
        $this->extractProcessVariablesBySource($processUri, $errors),
      );
      $sourceVariablesByStudy = $this->mergeSimulatorIntoInstrument($sourceVariablesByStudy);
      $processInstanceCounts = $this->extractProcessInstanceCounts($processUri, $errors);

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

      // Determine study type and extract ProcessBasedStudy metadata
      $studyType = !empty($processUri) && $processUri !== 'None' ? 'processbasedstudy' : 'study';

      // Get process metadata if available (including ProcessStem for filtering)
      $processLabel = '';
      $processSlug = '';
      $processStemUri = '';
      $processStemLabel = '';
      $processStemSlug = '';
      if (!empty($processUri) && $processUri !== 'None' && isset($processMetadataMap[$processUri])) {
        $processLabel = $processMetadataMap[$processUri]['label'] ?? '';
        $processSlug = $processMetadataMap[$processUri]['slug'] ?? '';
        $processStemUri = $processMetadataMap[$processUri]['stem_uri'] ?? '';
        $processStemLabel = $processMetadataMap[$processUri]['stem_label'] ?? '';
        $processStemSlug = $processMetadataMap[$processUri]['stem_slug'] ?? '';
      }

      // Ensure the Procedure Type (NCIT-PMSR) facet is fed from study process data,
      // even when no ontology token is present in variable labels.
      if (isset($ontologyDefinitions['workflowstem'])) {
        $stemFacetSlug = '';
        if ($processStemUri !== '') {
          $stemFacetSlug = $this->slugify($processStemUri);
        }
        elseif ($processStemSlug !== '') {
          $stemFacetSlug = $processStemSlug;
        }

        if ($stemFacetSlug !== '') {
          $stemFacetLabel = $processStemLabel !== '' ? $processStemLabel : ($processLabel !== '' ? $processLabel : $stemFacetSlug);
          $ontologyFilters['workflowstem'][$stemFacetSlug] = [
            'slug' => $stemFacetSlug,
            'uri' => $processStemUri,
            'label' => $stemFacetLabel,
          ];
          $studyOntologyTags['workflowstem'][$stemFacetSlug] = $stemFacetSlug;
        }
      }

      $studyLabel = trim((string) ($study->label ?? $study->title ?? $study->studyTitle ?? $study->hasStudyTitle ?? $studyUri));

      // Resolve organization from study metadata and platform from PlatformInstance partOf links.
      [$organizationUri, $organization] = $this->resolveStudyOrganization($study);
      $platform = $this->resolveStudyPlatform(
        $studyLabel,
        $processLabel,
        $organizationUri,
        $platformCandidatesByOrganization,
      );
      $platformLabel = (string) ($platform['label'] ?? '');
      $platformSlug = (string) ($platform['slug'] ?? '');
      $platformUri = (string) ($platform['uri'] ?? '');

      if ($studyType === 'processbasedstudy' && $platformLabel === '') {
        $platformLabel = 'Unmapped Platform';
        $platformSlug = 'unmapped-platform';
        $platformUri = '';
      }

      if ($platformLabel !== '' && $platformSlug !== '') {
        if (!isset($platformFilters[$platformSlug])) {
          $platformFilters[$platformSlug] = [
            'uri' => $platformUri,
            'label' => $platformLabel,
            'slug' => $platformSlug,
            'count' => 0,
          ];
        }
        $platformFilters[$platformSlug]['count']++;
      }

      if ($processLabel !== '' && $organization !== '' && str_ends_with($processLabel, ' at Unknown Organization')) {
        $processLabel = preg_replace('/\s+at\s+Unknown\s+Organization$/i', ' at ' . $organization, $processLabel) ?? $processLabel;
      }

      if ($studyLabel !== '' && $organization !== '' && str_ends_with($studyLabel, ' at Unknown Organization')) {
        $studyLabel = preg_replace('/\s+at\s+Unknown\s+Organization$/i', ' at ' . $organization, $studyLabel) ?? $studyLabel;
      }

      $description = trim((string) ($study->comment ?? ''));
      if ($description !== '') {
        $description = preg_replace('#https?://pmsr\.net/ont/WKF\#/?#i', 'https://pmsr.net/ont/', $description) ?? $description;
      }

      $studyCards[] = [
        'label' => $studyLabel,
        'uri' => $studyUri,
        'study_type' => $studyType,
        'study_id' => trim((string) ($study->studyID ?? $study->hasStudyID ?? '')),
        'description' => $description,
        'organization' => $organization,
        'organization_slug' => $this->slugify($organization),
        'platform_label' => $platformLabel,
        'platform_slug' => $platformSlug,
        'process_uri' => $processUri,
        'process_label' => $processLabel,
        'process_slug' => $processSlug,
        'process_stem_uri' => $processStemUri,
        'process_stem_label' => $processStemLabel,
        'process_stem_slug' => $processStemSlug,
        'principal_investigator' => is_object($piValue = $study->principalInvestigator ?? $study->hasPrincipalInvestigator ?? null) 
          ? trim((string) ($piValue->label ?? $piValue->uri ?? '')) 
          : trim((string) ($piValue ?? '')),
        // Canonical start date source is Study/ProcessBasedStudy startDate.
        // Do not fall back to deprecated Process.hasStartDate.
        'start_date' => trim((string) ($study->startDate ?? '')),
        'upload_size' => trim((string) ($study->uploadSize ?? $study->hasUploadSize ?? '')),
        'manage_url' => $this->buildManageStudyUrlWithTracking($studyUri),
        'edit_url' => $studyType === 'processbasedstudy'
          ? Url::fromRoute('std.edit_processbasedstudy', ['studyuri' => base64_encode($studyUri)])->toString()
          : Url::fromRoute('std.edit_study', ['studyuri' => base64_encode($studyUri)])->toString(),
        'codebook_count' => count($studyTagsBySource['questionnaire']),
        'component_count' => $processInstanceCounts['component_instances'] > 0
          ? $processInstanceCounts['component_instances']
          : count($studyTagsBySource['component']),
        'simulator_count' => $processInstanceCounts['instrument_instances'] > 0
          ? $processInstanceCounts['instrument_instances']
          : count($studyTagsBySource['simulator']),
        'instrument_count' => count($studyTagsBySource['instrument']),
        'tags' => array_values($studyTags),
        'source_tags' => [
          'simulator' => array_values($studyTagsBySource['simulator']),
          'instrument' => array_values($studyTagsBySource['instrument']),
          'questionnaire' => array_values($studyTagsBySource['questionnaire']),
          'component' => array_values($studyTagsBySource['component']),
        ],
        'simulator_instances' => array_values(is_array($sourceVariablesByStudy['simulator'] ?? NULL) ? $sourceVariablesByStudy['simulator'] : []),
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

    $context = [
      'variables_by_source' => $variablesBySource,
      'ontology_definitions' => $ontologyTitles,
      'ontology_filters' => $ontologyFilters,
      'organizations' => $organizations,
      'process_filters' => array_values($processMetadataMap),
      'platform_filters' => array_values($this->sortPlatformFilters($platformFilters)),
      'study_cards' => $studyCards,
      'errors' => array_values(array_unique($errors)),
    ];

    $this->persistScenarioCardsCache($studyCards);
    $cacheBin->set(
      $contextCacheKey,
      [
        '_schema' => self::CONTEXT_CACHE_SCHEMA,
        'context' => $context,
      ],
      \Drupal\Core\Cache\CacheBackendInterface::CACHE_PERMANENT,
      array_values(array_unique($contextCacheTags))
    );

    return $context;
  }

  /**
   * Read one cached scenario card by study URI.
   */
  public function getCachedScenarioCard(string $studyUri): ?array {
    $normalizedStudyUri = $this->normalizePmsrDisplayUri(trim($studyUri));
    if ($normalizedStudyUri === '') {
      return NULL;
    }

    $cacheBin = \Drupal::service('cache.std_study_search');
    $cacheKey = self::SCENARIO_CACHE_KEY_PREFIX . md5($normalizedStudyUri);
    $item = $cacheBin->get($cacheKey);
    if (!$item || !is_array($item->data)) {
      return NULL;
    }

    $schema = (int) ($item->data['_schema'] ?? 0);
    $card = $item->data['card'] ?? NULL;
    if ($schema !== self::CONTEXT_CACHE_SCHEMA || !is_array($card)) {
      return NULL;
    }

    return $card;
  }

  /**
   * Persist per-scenario cached payloads.
   *
   * @param array<int,array<string,mixed>> $studyCards
   */
  private function persistScenarioCardsCache(array $studyCards): void {
    $cacheBin = \Drupal::service('cache.std_study_search');
    foreach ($studyCards as $card) {
      if (!is_array($card)) {
        continue;
      }

      $studyUri = isset($card['uri']) ? $this->normalizePmsrDisplayUri(trim((string) $card['uri'])) : '';
      if ($studyUri === '') {
        continue;
      }

      $cacheBin->set(
        self::SCENARIO_CACHE_KEY_PREFIX . md5($studyUri),
        [
          '_schema' => self::CONTEXT_CACHE_SCHEMA,
          'card' => $card,
        ],
        \Drupal\Core\Cache\CacheBackendInterface::CACHE_PERMANENT,
        [self::CONTEXT_CACHE_TAG, 'std_study:' . md5($studyUri)]
      );
    }
  }

  /**
   * Build a stable context cache key for visibility scope.
   */
  private function buildContextCacheKey(string $userEmail, bool $isAdmin, bool $isAuthenticated): string {
    $scope = [
      'user' => strtolower(trim($userEmail)),
      'admin' => $isAdmin ? 1 : 0,
      'auth' => $isAuthenticated ? 1 : 0,
    ];

    return self::CONTEXT_CACHE_KEY_PREFIX . md5(json_encode($scope));
  }

  /**
   * Resolve organization URI and display label from a Study-like object.
   *
   * @return array{0:string,1:string}
   */
  private function resolveStudyOrganization(object $study): array {
    $institutionCandidates = [
      $study->institution ?? NULL,
      $study->hasInstitution ?? NULL,
      $study->institutionUri ?? NULL,
      $study->hasInstitutionUri ?? NULL,
      $study->organization ?? NULL,
      $study->organizationUri ?? NULL,
      $study->hasOrganizationUri ?? NULL,
    ];

    $institutionValue = NULL;
    foreach ($institutionCandidates as $candidate) {
      $candidateUri = Utils::canonicalizePmsrUri($this->extractUriString($candidate));
      if ($candidateUri !== '') {
        $institutionValue = $candidate;
        break;
      }

      if (is_object($candidate)) {
        $institutionValue = $candidate;
        break;
      }

      if (is_string($candidate) && trim($candidate) !== '') {
        $institutionValue = $candidate;
        break;
      }
    }

    $organizationUri = Utils::canonicalizePmsrUri($this->extractUriString($institutionValue));
    if ($organizationUri === '') {
      foreach (['institutionUri', 'hasInstitutionUri', 'organizationUri', 'hasOrganizationUri'] as $field) {
        if (!isset($study->{$field}) || !is_string($study->{$field})) {
          continue;
        }
        $organizationUri = Utils::canonicalizePmsrUri(trim((string) $study->{$field}));
        if ($organizationUri !== '') {
          break;
        }
      }
    }

    $labelCandidates = [
      is_object($institutionValue) ? (string) ($institutionValue->label ?? '') : '',
      (string) ($study->institutionName ?? $study->hasInstitutionName ?? ''),
      (string) ($study->organizationName ?? ''),
      is_string($institutionValue) ? $institutionValue : '',
    ];

    $organizationLabel = '';
    foreach ($labelCandidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate === '' || strcasecmp($candidate, 'None') === 0) {
        continue;
      }
      if (preg_match('/^https?:\/\//i', $candidate) === 1) {
        continue;
      }
      $organizationLabel = $candidate;
      break;
    }

    if ($organizationUri === 'https://pmsr.net/ont/ORG/ESS') {
      $organizationLabel = 'UCP';
    }

    if ($organizationLabel === '' && $organizationUri !== '') {
      $organizationLabel = $organizationUri;
    }

    return [$organizationUri, $organizationLabel];
  }

  /**
   * Resolve ownership scope URIs for a given organization.
   *
   * Scope includes the organization itself plus its immediate parent
   * organization when available.
   *
   * @return string[]
   */
  private function resolveOrganizationScopeUris(string $organizationUri): array {
    $organizationUri = Utils::canonicalizePmsrUri(trim($organizationUri));
    if ($organizationUri === '') {
      return [];
    }

    if (isset($this->organizationScopeCache[$organizationUri])) {
      return $this->organizationScopeCache[$organizationUri];
    }

    $scope = [$organizationUri => TRUE];

    try {
      $raw = $this->apiConnector->getUri($organizationUri);
      $org = $this->apiConnector->parseObjectResponse($raw, 'getUri');
      if (is_object($org)) {
        foreach (['parentOrganizationUri', 'hasParentOrganizationUri', 'parentOrganization', 'hasParentOrganization', 'isPartOf', 'partOf'] as $key) {
          if (!isset($org->{$key})) {
            continue;
          }

          $parentUri = Utils::canonicalizePmsrUri($this->extractUriString($org->{$key}));
          if ($parentUri !== '') {
            $scope[$parentUri] = TRUE;
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Keep default scope with the provided organization URI.
    }

    // Authoritative hierarchy predicate in KG: schema:isPartOf (child -> parent).
    if (method_exists($this->apiConnector, 'sparqlQuery')) {
      try {
        $sparql = 'SELECT DISTINCT ?parent WHERE {' .
          ' <' . $organizationUri . '> <https://schema.org/isPartOf> ?parent .' .
          '}';
        $raw = $this->apiConnector->sparqlQuery($sparql);
        $decoded = json_decode((string) $raw, TRUE);
        $bindings = $decoded['results']['bindings'] ?? [];

        if (is_array($bindings)) {
          foreach ($bindings as $binding) {
            if (!is_array($binding)) {
              continue;
            }

            $parentUri = Utils::canonicalizePmsrUri(trim((string) ($binding['parent']['value'] ?? '')));
            if ($parentUri !== '') {
              $scope[$parentUri] = TRUE;
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Keep scope resolved from object payload when SPARQL lookup is unavailable.
      }
    }

    $this->organizationScopeCache[$organizationUri] = array_keys($scope);
    return $this->organizationScopeCache[$organizationUri];
  }

  /**
   * Select a platform for a study from organization-linked platform instances.
   *
   * @param array<string,array<int,array{uri:string,label:string,slug:string}>> $platformCandidatesByOrganization
   * @return array{uri:string,label:string,slug:string}
   */
  private function resolveStudyPlatform(string $studyLabel, string $processLabel, string $organizationUri, array $platformCandidatesByOrganization): array {
    $organizationUri = Utils::canonicalizePmsrUri(trim($organizationUri));
    if ($organizationUri === '' || !isset($platformCandidatesByOrganization[$organizationUri])) {
      return ['uri' => '', 'label' => '', 'slug' => ''];
    }

    $candidates = $platformCandidatesByOrganization[$organizationUri];
    if (count($candidates) === 1) {
      return $candidates[0];
    }

    $studyLower = mb_strtolower($studyLabel);
    $processLower = mb_strtolower($processLabel);
    $matched = [];

    foreach ($candidates as $candidate) {
      $label = trim((string) ($candidate['label'] ?? ''));
      if ($label === '') {
        continue;
      }

      $needle = mb_strtolower($label);
      if ($needle !== '' && (str_contains($studyLower, $needle) || str_contains($processLower, $needle))) {
        $matched[] = $candidate;
      }
    }

    if (count($matched) === 1) {
      return $matched[0];
    }

    return ['uri' => '', 'label' => '', 'slug' => ''];
  }

  /**
   * Stable sorting for platform filters.
   *
   * @param array<string,array{uri:string,label:string,slug:string,count:int}> $platformFilters
   * @return array<string,array{uri:string,label:string,slug:string,count:int}>
   */
  private function sortPlatformFilters(array $platformFilters): array {
    uasort($platformFilters, fn(array $a, array $b) => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
    return $platformFilters;
  }

  private function loadElementsByType(string $elementType, array &$errors): array {
    // Study Search must include workflow-derived studies as well.
    if ($elementType === 'study') {
      $studies = [];

      try {
        $response = $this->apiConnector->listByKeyword('study', '_', 9999, 0);
        $items = $this->apiConnector->parseObjectResponse($response, 'listByKeyword');
        if (is_array($items)) {
          $studies = $items;
        }
      }
      catch (\Throwable $e) {
        $errors[] = 'Unable to load study data from HASCOAPI.';
      }

      // Merge explicit ProcessBasedStudy entries via dedicated endpoint.
      try {
        $raw = $this->apiConnector->listProcessBasedStudies(9999, 0);
        $pbsItems = $this->apiConnector->parseObjectResponse($raw, 'processbasedstudy_elements');
        if (is_array($pbsItems)) {
          // Merge by URI, preferring explicit ProcessBasedStudy entries.
          $indexed = [];
          foreach ($studies as $item) {
            if (is_object($item) && !empty($item->uri)) {
              $indexed[(string) $item->uri] = $item;
            }
            else {
              $indexed[] = $item;
            }
          }
          foreach ($pbsItems as $item) {
            if (is_object($item) && !empty($item->uri)) {
              $indexed[(string) $item->uri] = $item;
            }
            else {
              $indexed[] = $item;
            }
          }
          return array_values($indexed);
        }
      }
      catch (\Throwable $e) {
        $errors[] = 'Unable to load process-based study data from HASCOAPI.';
      }

      return $studies;
    }

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

  private function extractSemanticVariableFields(string $studyUri, array $semanticVariablePool): array {
    $fields = [];

    foreach ($semanticVariablePool as $semanticVariable) {
      if (!is_object($semanticVariable) || !$this->semanticVariableBelongsToStudy($semanticVariable, $studyUri)) {
        continue;
      }

      $label = $this->resolveSemanticVariableDisplayLabel($semanticVariable);
      if ($label === '') {
        continue;
      }

      $fields[$label] = $label;
    }

    ksort($fields, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($fields);
  }

  private function semanticVariableBelongsToStudy(object $semanticVariable, string $studyUri): bool {
    $studyCandidates = [
      $semanticVariable->entityUri ?? NULL,
      $semanticVariable->hasEntityUri ?? NULL,
      $semanticVariable->hasEntity ?? NULL,
    ];

    foreach ($studyCandidates as $candidate) {
      if ($this->valueMatchesStudy($candidate, $studyUri, 0)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function resolveSemanticVariableDisplayLabel(object $semanticVariable): string {
    $candidates = [
      (string) ($semanticVariable->label ?? ''),
      (string) ($semanticVariable->originalIdLabel ?? ''),
      (string) ($semanticVariable->hasContent ?? ''),
      (string) ($semanticVariable->slug ?? ''),
      (string) ($semanticVariable->name ?? ''),
    ];

    foreach ($candidates as $candidate) {
      $clean = trim($candidate);
      if ($clean !== '') {
        return $clean;
      }
    }

    return '';
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

  /**
   * Cached wrapper for extractSocVariablesBySource to avoid expensive API calls on every page load.
   * Cache is per-study and persists across cache rebuilds (drush cr) for performance.
   * Only invalidated when study or SOC data changes.
   */
  private function extractSocVariablesBySourceCached(string $studyUri, array &$errors): array {
    $cacheKey = 'std_study_soc_vars:' . md5($studyUri);
    $cacheBin = \Drupal::service('cache.std_study_search');
    $cache = $cacheBin->get($cacheKey);

    if ($cache && is_array($cache->data)) {
      $schema = (int) ($cache->data['_schema'] ?? 0);
      $payload = $cache->data['payload'] ?? NULL;
      if ($schema === self::SOC_VARIABLE_CACHE_SCHEMA && is_array($payload)) {
        return $payload;
      }
    }
    
    // Cache miss - extract SOC variables (expensive: 1 + N API calls)
    $result = $this->extractSocVariablesBySource($studyUri, $errors);
    
    // Cache permanently with study-specific tag for selective invalidation
    // This cache persists across 'drush cr' for better performance
    $cacheBin->set(
      $cacheKey,
      [
        '_schema' => self::SOC_VARIABLE_CACHE_SCHEMA,
        'payload' => $result,
      ],
      \Drupal\Core\Cache\CacheBackendInterface::CACHE_PERMANENT,
      ['std_study_soc_vars', 'std_study:' . md5($studyUri)]
    );
    
    return $result;
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
            (string) ($object->uri ?? ''),
            (string) ($object->originalId ?? ''),
            (string) ($object->originalID ?? ''),
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

  /**
   * Keep a single instrument bucket in Scenario Search UI by folding
    * simulator values into instrument values.
    *
    * Simulator bucket is intentionally preserved for card-level instance display.
   */
  private function mergeSimulatorIntoInstrument(array $bucket): array {
    $out = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];

    foreach (self::SOURCE_KEYS as $source) {
      $out[$source] = is_array($bucket[$source] ?? NULL) ? array_values($bucket[$source]) : [];
    }

    $instrumentMap = [];
    foreach (array_merge($out['instrument'], $out['simulator']) as $value) {
      $label = trim((string) $value);
      if ($label === '') {
        continue;
      }
      $instrumentMap[$label] = $label;
    }

    ksort($instrumentMap, SORT_NATURAL | SORT_FLAG_CASE);
    $out['instrument'] = array_values($instrumentMap);

    return $out;
  }

  private function inferSourceFromHints(array $hints): string {
    foreach ($hints as $hint) {
      $text = strtolower(trim((string) $hint));
      if ($text === '') {
        continue;
      }

      if (
        str_contains($text, 'simulator')
        || str_contains($text, 'physicalmedicalsimulator')
        || str_contains($text, 'simulador')
        || preg_match('/\b(sim|simu)\b/i', $text) === 1
      ) {
        return 'simulator';
      }

      if (
        str_contains($text, 'instrument')
        || str_contains($text, 'device')
        || str_contains($text, 'equipment')
        || str_contains($text, 'medicaldevice')
        || preg_match('/\b(ins|instr)\b/i', $text) === 1
      ) {
        return 'instrument';
      }

      if (
        str_contains($text, 'component')
        || str_contains($text, 'componentinstance')
        || str_contains($text, 'actuator')
        || str_contains($text, 'detector')
        || str_contains($text, 'sensor')
        || str_contains($text, 'componente')
        || str_contains($text, 'atuador')
        || str_contains($text, 'detetor')
        || preg_match('/\b(comp|cmp)\b/i', $text) === 1
      ) {
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
      'workflowstem' => [
        'title' => 'Procedure Type (NCIT-PMSR)',
        'token_regexes' => ['/\bNCIT[:_ ]?C?\d{2,}\b/i', '/\bC\d{4,}\b/i'],
        'uri_template' => 'NCIT:C%s',
        'uri_keywords' => ['ncit', 'pmsr', 'procedure'],
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

  /**
   * Build manage study URL with back-tracking support.
   */
  private function buildManageStudyUrlWithTracking(string $studyUri): string {
    // Get current request URI as the previous URL
    $previousUrl = base64_encode(\Drupal::request()->getRequestUri());
    
    // Build the manage study elements URL
    $manageStudyUrl = Url::fromRoute('std.manage_study_elements', [
      'studyuri' => base64_encode($studyUri),
    ])->toString();
    $currentUrl = base64_encode($manageStudyUrl);
    
    // Use rep.back_url to track the previous URL
    return Url::fromRoute('rep.back_url', [
      'previousurl' => $previousUrl,
      'currenturl' => $currentUrl,
      'currentroute' => 'std.manage_study_elements',
    ])->toString();
  }

  /**
   * Invalidate the study search cache.
   * Call this when studies, SOCs, workflows, or related data changes.
   * 
   * @param string|null $studyUri Optional study URI to invalidate cache for specific study only
   */
  public static function invalidateCache(?string $studyUri = NULL): void {
    $cacheBin = \Drupal::service('cache.std_study_search');
    
    if ($studyUri !== NULL) {
      // Invalidate cache for specific study only
      $cacheBin->invalidateTags(['std_study:' . md5($studyUri)]);
    } else {
      // Invalidate all study caches (use sparingly - only when global changes occur)
      $cacheBin->invalidateTags(['std_study_soc_vars', self::CONTEXT_CACHE_TAG]);
    }
  }

  /**
   * Invalidate and warm search caches immediately after scenario updates.
   */
  public static function refreshCachesForScenarioUpdate(string $studyUri): void {
    $normalizedStudyUri = Utils::canonicalizePmsrUri(trim($studyUri));
    if ($normalizedStudyUri === '') {
      self::invalidateCache();
      return;
    }

    self::invalidateCache($normalizedStudyUri);

    if (!\Drupal::hasService('std.study_variable_search')) {
      return;
    }

    $service = \Drupal::service('std.study_variable_search');
    if (!$service instanceof self) {
      return;
    }

    try {
      // Warm global/public visibility cache and admin visibility cache.
      $service->buildContext('', FALSE, FALSE);
      $service->buildContext('', TRUE, TRUE);
    }
    catch (\Throwable $e) {
      \Drupal::logger('std')->warning('Failed to warm study search cache after scenario update: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Completely clear the persistent study search cache.
   * 
   * This method explicitly clears ALL cached study search data, including data
   * that normally persists across 'drush cr' operations. Use this only when
   * you need to force a complete cache rebuild (e.g., after major data imports,
   * structural changes, or troubleshooting).
   * 
   * For selective cache invalidation, use invalidateCache() instead.
   */
  public static function clearAllCache(): void {
    $cacheBin = \Drupal::service('cache.std_study_search');
    
    // Check if this is our persistent backend that has clearPersistentCache()
    if (method_exists($cacheBin, 'clearPersistentCache')) {
      $cacheBin->clearPersistentCache();
    } else {
      // Fallback to standard deleteAll if not using persistent backend
      $cacheBin->deleteAll();
    }
  }

  /**
   * Extract unique organizations from studies.
   * 
   * Organizations are schema:Organization instances (or subclasses).
   * Organization names MUST be preserved exactly as provided (no normalization).
   *
   * @param array $studies
   *   Array of study objects.
   *
   * @return array
   *   Array of unique organization data with counts.
   */
  private function extractOrganizations(array $studies): array {
    $organizations = [];
    
    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }
      
      [, $institution] = $this->resolveStudyOrganization($study);
      
      if ($institution === '' || $institution === 'None') {
        continue;
      }
      
      $slug = $this->slugify($institution);
      
      if (!isset($organizations[$slug])) {
        $organizations[$slug] = [
          'label' => $institution,
          'slug' => $slug,
          'count' => 0,
          'type' => 'schema:Organization',
        ];
      }
      
      $organizations[$slug]['count']++;
    }
    
    uasort($organizations, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    
    return array_values($organizations);
  }

  /**
   * Extract process metadata for ProcessBasedStudy entities.
   * 
   * Architecture: ProcessBasedStudy → processUri → Process → hasStem → ProcessStem (hierarchical)
   * Processes are NOT hierarchical; ProcessStems ARE hierarchical.
   *
   * @param array $studies
   *   Array of study objects.
   * @param array &$errors
   *   Error collection array.
   *
   * @return array
   *   Map of process URI to process metadata (including ProcessStem data).
   */
  private function extractProcessMetadata(array $studies, array &$errors): array {
    $processMap = [];
    
    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }
      
      $processUri = $this->normalizePmsrDisplayUri(trim((string) ($study->processUri ?? '')));
      if ($processUri === '' || $processUri === 'None') {
        continue;
      }
      
      if (isset($processMap[$processUri])) {
        continue;
      }
      
      try {
        $response = $this->apiConnector->getUri($processUri);
        $process = $this->apiConnector->parseObjectResponse($response, 'getUri');
        
        if (is_object($process)) {
          $processLabel = trim((string) ($process->label ?? $process->rdfsLabel ?? ''));
          if ($processLabel === '') {
            $processLabel = 'Unnamed Process';
          }
          
          $processStemUri = '';
          $stemCandidates = [
            $process->hasStem ?? NULL,
            $process->hasProcessStem ?? NULL,
            $process->processStem ?? NULL,
            $process->wasDerivedFrom ?? NULL,
          ];
          foreach ($stemCandidates as $stemCandidate) {
            $candidateUri = $this->extractUriString($stemCandidate);
            if ($candidateUri !== '') {
              $processStemUri = $candidateUri;
              break;
            }
          }
          $processStemLabel = '';
          $processStemSlug = '';
          
          if ($processStemUri !== '') {
            try {
              $stemResponse = $this->apiConnector->getUri($processStemUri);
              $processStem = $this->apiConnector->parseObjectResponse($stemResponse, 'getUri');
              
              if (is_object($processStem)) {
                $processStemLabel = trim((string) ($processStem->label ?? $processStem->rdfsLabel ?? ''));
                if ($processStemLabel !== '') {
                  $processStemSlug = $this->slugify($processStemLabel);
                }
              }
            }
            catch (\Throwable $stemError) {
              $errors[] = sprintf('Failed to load ProcessStem: %s', $processStemUri);
            }
          }

          if ($processStemSlug === '' && $processStemUri !== '') {
            $processStemSlug = $this->slugify($processStemUri);
          }
          
          $processMap[$processUri] = [
            'uri' => $processUri,
            'label' => $processLabel,
            'slug' => $this->slugify($processLabel),
            'stem_uri' => $processStemUri,
            'stem_label' => $processStemLabel,
            'stem_slug' => $processStemSlug,
            'count' => 0,
          ];
        }
      }
      catch (\Throwable $e) {
        $errors[] = sprintf('Failed to load Process metadata: %s', $processUri);
      }
    }
    
    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }
      
      $processUri = trim((string) ($study->processUri ?? ''));
      if ($processUri !== '' && $processUri !== 'None' && isset($processMap[$processUri])) {
        $processMap[$processUri]['count']++;
      }
    }
    
    return $processMap;
  }

  /**
   * Resolve a URI-like string from scalar/object/array API values.
   */
  private function extractUriString($value): string {
    if (is_string($value)) {
      return trim($value);
    }

    if (is_object($value)) {
      foreach (['uri', 'hasUri', 'value', 'id'] as $field) {
        if (isset($value->{$field}) && is_string($value->{$field})) {
          $candidate = trim($value->{$field});
          if ($candidate !== '') {
            return $candidate;
          }
        }
      }

      foreach (get_object_vars($value) as $innerValue) {
        $candidate = $this->extractUriString($innerValue);
        if ($candidate !== '') {
          return $candidate;
        }
      }
    }

    if (is_array($value)) {
      foreach ($value as $innerValue) {
        $candidate = $this->extractUriString($innerValue);
        if ($candidate !== '') {
          return $candidate;
        }
      }
    }

    return '';
  }

  /**
   * Extract platform metadata from studies.
   *
   * @param array $studies
   *   Array of study objects.
   * @param array &$errors
   *   Error collection array.
   *
   * @return array
   *   Map of platform reference to platform metadata.
   */
  private function extractPlatformCandidatesByOrganization(array $studies, array &$errors): array {
    $organizationScopeMap = [];
    $allScopeUris = [];

    foreach ($studies as $study) {
      if (!is_object($study)) {
        continue;
      }
      [$organizationUri, ] = $this->resolveStudyOrganization($study);
      if ($organizationUri === '') {
        continue;
      }

      if (!isset($organizationScopeMap[$organizationUri])) {
        $organizationScopeMap[$organizationUri] = $this->resolveOrganizationScopeUris($organizationUri);
      }

      foreach ($organizationScopeMap[$organizationUri] as $scopeUri) {
        $allScopeUris[$scopeUri] = TRUE;
      }
    }

    if (empty($allScopeUris)) {
      return [];
    }

    $candidatesByPartOf = [];

    // Preferred path: deployments link instruments to platform instances, and
    // platform instances link to organizations via hasco:partOf.
    if (method_exists($this->apiConnector, 'sparqlQuery')) {
      try {
        $values = implode(' ', array_map(fn(string $uri): string => '<' . $uri . '>', array_keys($allScopeUris)));
        $sparql = 'SELECT DISTINCT ?org ?platform ?label WHERE {' .
          ' ?dep <http://hadatac.org/ont/vstoi#hasPlatformInstance> ?platform .' .
          ' ?platform <http://hadatac.org/ont/hasco/partOf> ?org .' .
          ' VALUES ?org { ' . $values . ' } ' .
          ' OPTIONAL { ?platform <http://www.w3.org/2000/01/rdf-schema#label> ?label . }' .
          '}';
        $raw = $this->apiConnector->sparqlQuery($sparql);
        $decoded = json_decode((string) $raw, TRUE);
        $bindings = $decoded['results']['bindings'] ?? [];

        if (is_array($bindings)) {
          foreach ($bindings as $binding) {
            if (!is_array($binding)) {
              continue;
            }

            $partOfUri = Utils::canonicalizePmsrUri(trim((string) ($binding['org']['value'] ?? '')));
            $platformUri = Utils::canonicalizePmsrUri(trim((string) ($binding['platform']['value'] ?? '')));
            $platformLabel = trim((string) ($binding['label']['value'] ?? ''));
            if ($partOfUri === '' || $platformUri === '') {
              continue;
            }
            if ($platformLabel === '') {
              $platformLabel = $platformUri;
            }

            if (!isset($candidatesByPartOf[$partOfUri])) {
              $candidatesByPartOf[$partOfUri] = [];
            }

            $exists = FALSE;
            foreach ($candidatesByPartOf[$partOfUri] as $existing) {
              if ((string) ($existing['uri'] ?? '') === $platformUri) {
                $exists = TRUE;
                break;
              }
            }

            if (!$exists) {
              $candidatesByPartOf[$partOfUri][] = [
                'uri' => $platformUri,
                'label' => $platformLabel,
                'slug' => $this->slugify($platformLabel),
              ];
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Keep fallback path below.
      }
    }

    if (empty($candidatesByPartOf)) {
      // Fallback: list platform instances through hascoapi search endpoints.
      try {
        $instances = $this->apiConnector->parseObjectResponse(
          $this->apiConnector->listByKeyword('platforminstance', '_', 5000, 0),
          'listByKeyword'
        );

        if (!is_array($instances)) {
          return [];
        }

        foreach ($instances as $instance) {
          if (!is_object($instance)) {
            continue;
          }

          $partOfUri = Utils::canonicalizePmsrUri($this->extractUriString($instance->partOf ?? NULL));
          if ($partOfUri === '' || !isset($allScopeUris[$partOfUri])) {
            continue;
          }

          $platformUri = Utils::canonicalizePmsrUri(trim((string) ($instance->uri ?? '')));
          $platformLabel = trim((string) ($instance->label ?? $instance->rdfsLabel ?? ''));
          if ($platformLabel === '') {
            $platformLabel = $platformUri;
          }
          if ($platformLabel === '') {
            continue;
          }

          $candidate = [
            'uri' => $platformUri,
            'label' => $platformLabel,
            'slug' => $this->slugify($platformLabel !== '' ? $platformLabel : $platformUri),
          ];

          if (!isset($candidatesByPartOf[$partOfUri])) {
            $candidatesByPartOf[$partOfUri] = [];
          }

          $dedupeKey = ($platformUri !== '' ? $platformUri : $platformLabel);
          $exists = FALSE;
          foreach ($candidatesByPartOf[$partOfUri] as $existing) {
            $existingKey = (($existing['uri'] ?? '') !== '' ? (string) $existing['uri'] : (string) ($existing['label'] ?? ''));
            if ($existingKey === $dedupeKey) {
              $exists = TRUE;
              break;
            }
          }

          if (!$exists) {
            $candidatesByPartOf[$partOfUri][] = $candidate;
          }
        }
      }
      catch (\Throwable $e) {
        $errors[] = 'Failed to load PlatformInstance metadata for Scenario Search.';
        return [];
      }
    }

    $candidatesByOrg = [];
    foreach ($organizationScopeMap as $organizationUri => $scopeUris) {
      foreach ($scopeUris as $scopeUri) {
        if (!isset($candidatesByPartOf[$scopeUri])) {
          continue;
        }

        if (!isset($candidatesByOrg[$organizationUri])) {
          $candidatesByOrg[$organizationUri] = [];
        }

        foreach ($candidatesByPartOf[$scopeUri] as $candidate) {
          $dedupeKey = (($candidate['uri'] ?? '') !== '' ? (string) $candidate['uri'] : (string) ($candidate['label'] ?? ''));
          $exists = FALSE;
          foreach ($candidatesByOrg[$organizationUri] as $existing) {
            $existingKey = (($existing['uri'] ?? '') !== '' ? (string) $existing['uri'] : (string) ($existing['label'] ?? ''));
            if ($existingKey === $dedupeKey) {
              $exists = TRUE;
              break;
            }
          }
          if (!$exists) {
            $candidatesByOrg[$organizationUri][] = $candidate;
          }
        }
      }
    }

    foreach ($candidatesByOrg as &$candidates) {
      usort($candidates, fn(array $a, array $b) => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
    }
    unset($candidates);

    return $candidatesByOrg;
  }

  private function normalizePmsrDisplayUri(string $uri): string {
    $value = Utils::canonicalizePmsrUri($uri);
    if ($value === '') {
      return '';
    }

    // Defensive normalization for fragment-like WKF URI drift variants.
    $value = str_ireplace(['/WKF#/', '/WKF#'], '/', $value);
    return $value;
  }

  /**
   * Process-based fallback for Scenario Search variable extraction.
   *
   * For recently ingested ProcessBasedStudy records, SOC/workflow links may be
   * incomplete. In that case we still derive instrument/component variables
   * from the linked Process payload and related instrument components.
   */
  private function extractProcessVariablesBySource(?string $processUri, array &$errors): array {
    $bySource = [
      'simulator' => [],
      'instrument' => [],
      'questionnaire' => [],
      'component' => [],
    ];

    $processUri = $this->normalizePmsrDisplayUri(trim((string) $processUri));
    if ($processUri === '' || $processUri === 'None' || !method_exists($this->apiConnector, 'getUri')) {
      return $bySource;
    }

    try {
      $componentInstanceUris = $this->extractComponentInstanceUrisFromProcessTasks($processUri, $errors);
      foreach ($componentInstanceUris as $componentInstanceUri) {
        $componentObj = $this->safeLoadEntityByUri($componentInstanceUri);
        $componentLabel = $this->extractEntityLabel($componentObj);
        if ($componentLabel !== '') {
          $bySource['component'][$componentLabel] = $componentLabel;
        }
      }

      $instrumentInstanceUrisFromComponents = $this->resolveInstrumentInstanceUrisByComponentInstances($componentInstanceUris);
      foreach ($instrumentInstanceUrisFromComponents as $instrumentInstanceUri) {
        $instrumentInstanceObj = $this->safeLoadEntityByUri($instrumentInstanceUri);
        $instrumentInstanceLabel = $this->extractEntityLabel($instrumentInstanceObj);
        if ($instrumentInstanceLabel !== '') {
          $bySource['simulator'][$instrumentInstanceLabel] = $instrumentInstanceLabel;
        }

        $instrumentModelUri = '';
        if (is_object($instrumentInstanceObj)) {
          $instrumentModelUri = trim((string) ($instrumentInstanceObj->typeUri ?? $instrumentInstanceObj->hasInstrument ?? ''));
        }

        if ($instrumentModelUri === '') {
          continue;
        }

        $instrumentModelObj = $this->safeLoadEntityByUri($instrumentModelUri);
        $instrumentModelLabel = $this->extractEntityLabel($instrumentModelObj);
        if ($instrumentModelLabel !== '') {
          $bySource['instrument'][$instrumentModelLabel] = $instrumentModelLabel;
        }
      }

      $instrumentUrisFromComponents = $this->resolveInstrumentUrisByComponentInstances($componentInstanceUris);
      foreach ($instrumentUrisFromComponents as $instrumentUri) {
        $instrumentObj = $this->safeLoadEntityByUri($instrumentUri);
        $instrumentLabel = $this->extractEntityLabel($instrumentObj);
        if ($instrumentLabel !== '') {
          $bySource['instrument'][$instrumentLabel] = $instrumentLabel;
        }
      }

      $processRaw = $this->apiConnector->getUri($processUri);
      $processObj = $this->apiConnector->parseObjectResponse($processRaw, 'getUri');
      if (!is_object($processObj)) {
        foreach ($bySource as $source => $values) {
          ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
          $bySource[$source] = array_values($values);
        }
        return $bySource;
      }

      $uriHints = [];
      $this->collectProcessAssetUris($processObj, $uriHints, 0, 'process');

      $instrumentUris = [];
      foreach ($uriHints as $uri => $hint) {
        if (!$this->isHttpUri($uri)) {
          continue;
        }

        $entity = $this->safeLoadEntityByUri($uri);
        $label = $this->extractEntityLabel($entity);
        $source = $this->inferSourceFromHints([
          (string) $hint,
          (string) ($entity->hascoTypeUri ?? ''),
          (string) ($entity->hascoType ?? ''),
          (string) ($entity->{'rdf:type'} ?? ''),
          (string) ($entity->typeUri ?? ''),
          (string) ($entity->label ?? ''),
          $uri,
        ]);

        if ($source !== '' && $label !== '') {
          $bySource[$source][$label] = $label;
        }

        if ($source === 'instrument' || $source === 'simulator') {
          $instrumentUris[$uri] = $uri;
        }
      }

      foreach (array_values($instrumentUris) as $instrumentUri) {
        foreach ($this->loadComponentUrisByInstrument($instrumentUri) as $componentUri) {
          if (!$this->isHttpUri($componentUri)) {
            continue;
          }

          $componentObj = $this->safeLoadEntityByUri($componentUri);
          $componentLabel = $this->extractEntityLabel($componentObj);
          if ($componentLabel !== '') {
            $bySource['component'][$componentLabel] = $componentLabel;
          }
        }
      }
    }
    catch (\Throwable $e) {
      $errors[] = sprintf('Unable to derive process assets for process %s.', $processUri);
    }

    foreach ($bySource as $source => $values) {
      ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
      $bySource[$source] = array_values($values);
    }

    return $bySource;
  }

  /**
   * Return instance-level counts for process task assets.
   *
   * @return array{component_instances:int,instrument_instances:int}
   */
  private function extractProcessInstanceCounts(?string $processUri, array &$errors): array {
    $componentInstanceUris = $this->extractComponentInstanceUrisFromProcessTasks($processUri, $errors);
    $instrumentInstanceUris = $this->resolveInstrumentInstanceUrisByComponentInstances($componentInstanceUris);

    return [
      'component_instances' => count($componentInstanceUris),
      'instrument_instances' => count($instrumentInstanceUris),
    ];
  }

  /**
   * Extract component-instance URIs from process task graph payload.
   *
   * @return string[]
   */
  private function extractComponentInstanceUrisFromProcessTasks(?string $processUri, array &$errors): array {
    $processUri = $this->normalizePmsrDisplayUri(trim((string) $processUri));
    if ($processUri === '' || !method_exists($this->apiConnector, 'processTasks')) {
      return [];
    }

    try {
      $raw = (string) $this->apiConnector->processTasks($processUri);
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded) || empty($decoded['isSuccessful'])) {
        return [];
      }

      $body = $decoded['body'] ?? [];
      if (is_string($body)) {
        $bodyDecoded = json_decode($body, TRUE);
        if (is_array($bodyDecoded)) {
          $body = $bodyDecoded;
        }
      }

      if (!is_array($body)) {
        return [];
      }

      $tasks = is_array($body['tasks'] ?? NULL) ? $body['tasks'] : [];
      $uriMap = [];
      foreach ($tasks as $task) {
        if (!is_array($task)) {
          continue;
        }

        $uses = is_array($task['usesComponentInstanceUris'] ?? NULL)
          ? $task['usesComponentInstanceUris']
          : [];
        foreach ($uses as $value) {
          $uri = trim((string) $value);
          if ($uri !== '' && $this->isHttpUri($uri)) {
            $uriMap[$uri] = $uri;
          }
        }
      }

      return array_values($uriMap);
    }
    catch (\Throwable $e) {
      $errors[] = sprintf('Unable to load process tasks for %s.', $processUri);
      return [];
    }
  }

  /**
   * Resolve instrument/model URIs connected to component instances.
   *
   * @param string[] $componentInstanceUris
   * @return string[]
   */
  private function resolveInstrumentUrisByComponentInstances(array $componentInstanceUris): array {
    if (empty($componentInstanceUris) || !method_exists($this->apiConnector, 'sparqlQuery')) {
      return [];
    }

    $normalized = [];
    foreach ($componentInstanceUris as $uri) {
      $value = trim((string) $uri);
      if ($value !== '' && $this->isHttpUri($value)) {
        $normalized[$value] = $value;
      }
    }

    if (empty($normalized)) {
      return [];
    }

    $values = implode(' ', array_map(fn(string $uri): string => '<' . $uri . '>', array_values($normalized)));
    $sparql = 'SELECT DISTINCT ?ii ?instrument WHERE {'
      . ' VALUES ?cpi { ' . $values . ' } '
      . ' ?cd (<http://hadatac.org/ont/hasco/hasComponentInstance>|<http://hadatac.org/ont/vstoi#hasComponentInstance>) ?cpi . '
      . ' ?cd (<http://hadatac.org/ont/hasco/hascoDeployment>|<http://hadatac.org/ont/hasco/hasDeployment>) ?dpl . '
      . ' ?dpl (<http://hadatac.org/ont/vstoi#hasInstrumentInstance>|<http://hadatac.org/ont/hasco/hasInstrumentInstance>) ?ii . '
      . ' OPTIONAL { ?ii (<http://hadatac.org/ont/vstoi#hasInstrument>|<http://hadatac.org/ont/hasco/hasInstrument>) ?instrument . } '
      . '}';

    try {
      $raw = $this->apiConnector->sparqlQuery($sparql);
      $decoded = json_decode((string) $raw, TRUE);
      $bindings = $decoded['results']['bindings'] ?? [];
      if (!is_array($bindings)) {
        return [];
      }

      $instrumentUriMap = [];
      foreach ($bindings as $binding) {
        if (!is_array($binding)) {
          continue;
        }

        $instrumentUri = trim((string) ($binding['instrument']['value'] ?? ''));
        if ($instrumentUri === '') {
          $instrumentUri = trim((string) ($binding['ii']['value'] ?? ''));
        }

        if ($instrumentUri !== '' && $this->isHttpUri($instrumentUri)) {
          $instrumentUriMap[$instrumentUri] = $instrumentUri;
        }
      }

      return array_values($instrumentUriMap);
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * Resolve instrument-instance URIs connected to component instances.
   *
   * @param string[] $componentInstanceUris
   * @return string[]
   */
  private function resolveInstrumentInstanceUrisByComponentInstances(array $componentInstanceUris): array {
    if (empty($componentInstanceUris) || !method_exists($this->apiConnector, 'sparqlQuery')) {
      return [];
    }

    $normalized = [];
    foreach ($componentInstanceUris as $uri) {
      $value = trim((string) $uri);
      if ($value !== '' && $this->isHttpUri($value)) {
        $normalized[$value] = $value;
      }
    }

    if (empty($normalized)) {
      return [];
    }

    $values = implode(' ', array_map(fn(string $uri): string => '<' . $uri . '>', array_values($normalized)));
    $sparql = 'SELECT DISTINCT ?ii WHERE {'
      . ' VALUES ?cpi { ' . $values . ' } '
      . ' { '
      . '   ?cd (<http://hadatac.org/ont/hasco/hasComponentInstance>|<http://hadatac.org/ont/vstoi#hasComponentInstance>) ?cpi . '
      . '   ?cd (<http://hadatac.org/ont/hasco/hascoDeployment>|<http://hadatac.org/ont/hasco/hasDeployment>) ?dpl . '
      . '   ?dpl (<http://hadatac.org/ont/vstoi#hasInstrumentInstance>|<http://hadatac.org/ont/hasco/hasInstrumentInstance>) ?ii . '
      . ' } UNION { '
      . '   ?dpl (<http://hadatac.org/ont/vstoi#hasComponentInstance>|<http://hadatac.org/ont/hasco/hasComponentInstance>) ?cpi . '
      . '   ?dpl (<http://hadatac.org/ont/vstoi#hasInstrumentInstance>|<http://hadatac.org/ont/hasco/hasInstrumentInstance>) ?ii . '
      . ' } '
      . '}';

    try {
      $raw = $this->apiConnector->sparqlQuery($sparql);
      $decoded = json_decode((string) $raw, TRUE);
      $bindings = $decoded['results']['bindings'] ?? [];
      if (!is_array($bindings)) {
        return [];
      }

      $iiMap = [];
      foreach ($bindings as $binding) {
        if (!is_array($binding)) {
          continue;
        }
        $iiUri = trim((string) ($binding['ii']['value'] ?? ''));
        if ($iiUri !== '' && $this->isHttpUri($iiUri)) {
          $iiMap[$iiUri] = $iiUri;
        }
      }

      if (!empty($iiMap)) {
        return array_values($iiMap);
      }
    }
    catch (\Throwable $e) {
      // Keep fallback resolution path below.
    }

    // Fallback: recover instrument instances from component-instance objects
    // and deterministic URI pattern used by CPI local IDs.
    $fallbackMap = [];
    foreach ($componentInstanceUris as $componentInstanceUri) {
      $cpiUri = trim((string) $componentInstanceUri);
      if ($cpiUri === '' || !$this->isHttpUri($cpiUri)) {
        continue;
      }

      foreach ($this->extractInstrumentInstancesFromComponentInstance($cpiUri) as $iiUri) {
        if ($iiUri !== '' && $this->isHttpUri($iiUri)) {
          $fallbackMap[$iiUri] = $iiUri;
        }
      }
    }

    return array_values($fallbackMap);
  }

  /**
   * Infer related instrument-instance URIs from component-instance payload.
   *
   * @return string[]
   */
  private function extractInstrumentInstancesFromComponentInstance(string $componentInstanceUri): array {
    $out = [];

    $component = $this->safeLoadEntityByUri($componentInstanceUri);
    if (is_object($component)) {
      $deploymentUris = [];
      foreach (['hascoDeployment', 'hasDeployment', 'deploymentUri', 'hasDeploymentUri', 'isPartOf'] as $field) {
        if (!isset($component->{$field})) {
          continue;
        }
        foreach ($this->extractUriValues($component->{$field}) as $uri) {
          $deploymentUris[$uri] = $uri;
        }
      }

      foreach (array_values($deploymentUris) as $deploymentUri) {
        $deployment = $this->safeLoadEntityByUri($deploymentUri);
        if (!is_object($deployment)) {
          continue;
        }

        foreach (['hasInstrumentInstance', 'instrumentInstance', 'instrumentInstanceUri', 'hasInstrument'] as $field) {
          if (!isset($deployment->{$field})) {
            continue;
          }
          foreach ($this->extractUriValues($deployment->{$field}) as $uri) {
            $out[$uri] = $uri;
          }
        }
      }
    }

    // URI pattern fallback: .../CPI-INIxxxx-COMxxxx[-N] -> .../INIxxxx
    // and generic local names containing INI token.
    $local = basename($componentInstanceUri);
    if (preg_match('/^CPI-(INI[^-]+)-/i', $local, $m) === 1 && !empty($m[1])) {
      $iiLocal = trim((string) $m[1]);
      $base = preg_replace('#/[^/]+$#', '', $componentInstanceUri) ?? '';
      if ($iiLocal !== '' && $base !== '') {
        $out[$base . '/' . $iiLocal] = $base . '/' . $iiLocal;
      }
    }
    if (preg_match('/(?:^|[-_\/])(INI[0-9A-Za-z]+)(?:[-_\/]|$)/i', $local, $m2) === 1 && !empty($m2[1])) {
      $iiLocal = trim((string) $m2[1]);
      $base = preg_replace('#/[^/]+$#', '', $componentInstanceUri) ?? '';
      if ($iiLocal !== '' && $base !== '') {
        $out[$base . '/' . $iiLocal] = $base . '/' . $iiLocal;
      }
    }

    return array_values($out);
  }

  /**
   * Normalize URI values from mixed scalar/object/array payloads.
   *
   * @return string[]
   */
  private function extractUriValues($value): array {
    $out = [];

    if (is_string($value)) {
      $candidate = trim($value);
      if ($candidate !== '' && $this->isHttpUri($candidate)) {
        $out[$candidate] = $candidate;
      }
      return array_values($out);
    }

    if (is_array($value)) {
      foreach ($value as $inner) {
        foreach ($this->extractUriValues($inner) as $uri) {
          $out[$uri] = $uri;
        }
      }
      return array_values($out);
    }

    if (is_object($value)) {
      foreach (['uri', 'hasUri', 'value', 'id'] as $field) {
        if (isset($value->{$field}) && is_string($value->{$field})) {
          $candidate = trim((string) $value->{$field});
          if ($candidate !== '' && $this->isHttpUri($candidate)) {
            $out[$candidate] = $candidate;
          }
        }
      }

      foreach (get_object_vars($value) as $inner) {
        foreach ($this->extractUriValues($inner) as $uri) {
          $out[$uri] = $uri;
        }
      }
    }

    return array_values($out);
  }

  /**
   * Recursively collect URI candidates from Process payload fields likely
   * related to instruments/simulators/components.
   *
   * @param array<string,string> $out
   */
  private function collectProcessAssetUris($value, array &$out, int $depth, string $hint): void {
    if ($depth > 8) {
      return;
    }

    $hintText = strtolower(trim($hint));

    if (is_string($value)) {
      $candidate = trim($value);
      if ($candidate !== '' && $this->isHttpUri($candidate)) {
        if (
          str_contains($hintText, 'instrument')
          || str_contains($hintText, 'simulator')
          || str_contains($hintText, 'component')
          || str_contains($hintText, 'device')
          || str_contains($hintText, 'actuator')
          || str_contains($hintText, 'detector')
        ) {
          $out[$candidate] = $hintText;
        }
      }
      return;
    }

    if (is_array($value)) {
      foreach ($value as $inner) {
        $this->collectProcessAssetUris($inner, $out, $depth + 1, $hintText);
      }
      return;
    }

    if (!is_object($value)) {
      return;
    }

    if (isset($value->uri) && is_string($value->uri)) {
      $uri = trim((string) $value->uri);
      $entityHint = strtolower(trim((string) (($value->hascoTypeUri ?? '') . ' ' . ($value->label ?? '') . ' ' . $hintText)));
      if ($uri !== '' && $this->isHttpUri($uri)) {
        if (
          str_contains($entityHint, 'instrument')
          || str_contains($entityHint, 'simulator')
          || str_contains($entityHint, 'component')
          || str_contains($entityHint, 'device')
          || str_contains($entityHint, 'actuator')
          || str_contains($entityHint, 'detector')
        ) {
          $out[$uri] = $entityHint;
        }
      }
    }

    foreach (get_object_vars($value) as $field => $innerValue) {
      $nextHint = strtolower(trim((string) $field));
      $this->collectProcessAssetUris($innerValue, $out, $depth + 1, $nextHint);
    }
  }

  /**
   * Load entity object by URI without throwing on transient retrieval errors.
   */
  private function safeLoadEntityByUri(string $uri): ?object {
    $uri = trim($uri);
    if ($uri === '' || !method_exists($this->apiConnector, 'getUri')) {
      return NULL;
    }

    try {
      $raw = $this->apiConnector->getUri($uri);
      $obj = $this->apiConnector->parseObjectResponse($raw, 'getUri');
      return is_object($obj) ? $obj : NULL;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Extract the best display label from an entity payload.
   */
  private function extractEntityLabel(?object $entity): string {
    if (!is_object($entity)) {
      return '';
    }

    foreach (['label', 'rdfsLabel', 'name', 'title', 'originalIdLabel'] as $field) {
      if (!isset($entity->{$field}) || !is_string($entity->{$field})) {
        continue;
      }
      $value = trim((string) $entity->{$field});
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  /**
   * Read component URIs connected to an instrument via available HASCOAPI endpoints.
   *
   * @return string[]
   */
  private function loadComponentUrisByInstrument(string $instrumentUri): array {
    $instrumentUri = trim($instrumentUri);
    if ($instrumentUri === '') {
      return [];
    }

    $uriMap = [];

    if (method_exists($this->apiConnector, 'componentListFromInstrument')) {
      foreach ($this->extractUriListFromApiResponse((string) $this->apiConnector->componentListFromInstrument($instrumentUri)) as $uri) {
        $uriMap[$uri] = $uri;
      }
    }

    if (empty($uriMap) && method_exists($this->apiConnector, 'containersListFromInstrument')) {
      foreach ($this->extractUriListFromApiResponse((string) $this->apiConnector->containersListFromInstrument($instrumentUri)) as $uri) {
        $uriMap[$uri] = $uri;
      }
    }

    return array_values($uriMap);
  }

  /**
   * Decode HASCOAPI wrapper responses and return URI-like string lists.
   *
   * @return string[]
   */
  private function extractUriListFromApiResponse(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }

    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    $body = $decoded['body'] ?? [];
    if (is_string($body)) {
      $bodyDecoded = json_decode($body, TRUE);
      if (is_array($bodyDecoded)) {
        $body = $bodyDecoded;
      }
    }

    if (!is_array($body)) {
      return [];
    }

    $out = [];
    foreach ($body as $item) {
      if (is_string($item)) {
        $uri = trim($item);
        if ($uri !== '' && $this->isHttpUri($uri)) {
          $out[$uri] = $uri;
        }
        continue;
      }

      if (is_array($item) && isset($item['uri']) && is_string($item['uri'])) {
        $uri = trim((string) $item['uri']);
        if ($uri !== '' && $this->isHttpUri($uri)) {
          $out[$uri] = $uri;
        }
      }

      if (is_object($item) && isset($item->uri) && is_string($item->uri)) {
        $uri = trim((string) $item->uri);
        if ($uri !== '' && $this->isHttpUri($uri)) {
          $out[$uri] = $uri;
        }
      }
    }

    return array_values($out);
  }

  /**
   * Cheap URI guard for crawler-style traversal.
   */
  private function isHttpUri(string $value): bool {
    return preg_match('/^https?:\/\//i', trim($value)) === 1;
  }

}
