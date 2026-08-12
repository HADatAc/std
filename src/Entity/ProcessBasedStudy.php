<?php

namespace Drupal\std\Entity;

use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\Core\Render\Markup;

/**
 * ProcessBasedStudy - A Study that is defined by an executable Process/Workflow
 * 
 * This class represents studies that have an associated workflow (Process).
 * It extends Study and adds process-specific handling.
 * 
 * Related to PMSR ProcessBasedStudy migration (Phase 1)
 */
class ProcessBasedStudy extends Study {

  /**
   * Parse list-style API response body into an array of objects.
   *
   * @return array<int, mixed>
   */
  protected static function parseApiListBody($raw): array {
    if ($raw === NULL) {
      return [];
    }

    $decoded = NULL;
    if (is_string($raw)) {
      $decoded = json_decode($raw);
    }
    elseif (is_object($raw) || is_array($raw)) {
      $decoded = json_decode(json_encode($raw));
    }

    if (!is_object($decoded) || empty($decoded->isSuccessful)) {
      return [];
    }

    $body = $decoded->body ?? NULL;
    if (is_string($body)) {
      $body = json_decode($body);
    }

    if (is_array($body)) {
      return $body;
    }

    if (is_object($body) && isset($body->elements) && is_array($body->elements)) {
      return $body->elements;
    }

    return [];
  }

  /**
   * Resolve KGR Person label from owner credential email.
   */
  public static function resolvePersonLabelByEmail(string $email): string {
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
      return '';
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $people = self::parseApiListBody($api->listByKeyword('person', $normalizedEmail, 25, 0));
      foreach ($people as $person) {
        if (!is_object($person)) {
          continue;
        }

        $emailCandidates = [
          $person->mbox ?? NULL,
          $person->userEmail ?? NULL,
          $person->hasSIRManagerEmail ?? NULL,
          $person->email ?? NULL,
        ];

        foreach ($emailCandidates as $candidate) {
          $candidateEmail = is_string($candidate) ? strtolower(trim($candidate)) : '';
          if (strpos($candidateEmail, 'mailto:') === 0) {
            $candidateEmail = trim(substr($candidateEmail, 7));
          }
          if ($candidateEmail !== '' && $candidateEmail === $normalizedEmail) {
            $label = trim((string) ($person->label ?? $person->name ?? ''));
            if ($label !== '') {
              return $label;
            }
          }
        }
      }
    }
    catch (\Throwable $ignored) {
      // Fallback handled below.
    }

    return $normalizedEmail;
  }

  /**
   * Resolve ProcessStem URI and label from a process URI.
   *
   * @return array{processStemUri:string, processStemLabel:string, processLabel:string}
   */
  public static function resolveProcessStemInfo(string $processUri): array {
    $processUri = Utils::canonicalizePmsrUri(trim($processUri));
    if ($processUri === '') {
      return [
        'processStemUri' => '',
        'processStemLabel' => '',
        'processLabel' => '',
      ];
    }

    $processStemUri = '';
    $processStemLabel = '';
    $processLabel = '';

    try {
      $api = \Drupal::service('rep.api_connector');
      $process = $api->parseObjectResponse($api->getUri($processUri), 'getUri');
      if (is_object($process)) {
        $processLabel = trim((string) ($process->label ?? $process->title ?? ''));

        if (isset($process->wasDerivedFrom)) {
          if (is_object($process->wasDerivedFrom)) {
            $processStemUri = trim((string) ($process->wasDerivedFrom->uri ?? ''));
          }
          elseif (is_string($process->wasDerivedFrom)) {
            $processStemUri = trim((string) $process->wasDerivedFrom);
          }
        }

        if ($processStemUri === '' && isset($process->processStemUri) && is_string($process->processStemUri)) {
          $processStemUri = trim((string) $process->processStemUri);
        }

        if ($processStemUri === '' && isset($process->hasProcessStemUri) && is_string($process->hasProcessStemUri)) {
          $processStemUri = trim((string) $process->hasProcessStemUri);
        }

        if ($processStemLabel === '') {
          $processStemLabel = trim((string) ($process->label ?? $process->title ?? ''));
        }
      }

      $processStemUri = Utils::canonicalizePmsrUri($processStemUri);
      if ($processStemUri !== '') {
        $processStem = $api->parseObjectResponse($api->getUri($processStemUri), 'getUri');
        if (is_object($processStem)) {
          $candidateStemLabel = trim((string) ($processStem->label ?? $processStem->title ?? ''));
          if ($candidateStemLabel !== '') {
            $processStemLabel = $candidateStemLabel;
          }
        }
      }
    }
    catch (\Throwable $ignored) {
      // Fallback handled below.
    }

    if ($processStemLabel === '') {
      $processStemLabel = $processUri;
    }
    if ($processLabel === '') {
      $processLabel = $processStemLabel;
    }

    return [
      'processStemUri' => $processStemUri,
      'processStemLabel' => $processStemLabel,
      'processLabel' => $processLabel,
    ];
  }

  /**
   * Compose canonical scenario label.
   */
  public static function composeScenarioLabel(string $personLabel, string $processStemLabel, string $institutionLabel, string $startDateYmd, string $startTimeHm): string {
    $personLabel = trim($personLabel);
    $processStemLabel = trim($processStemLabel);
    $startDateYmd = trim($startDateYmd);
    $startTimeHm = trim($startTimeHm);

    if ($personLabel === '') {
      $personLabel = 'Unknown Person';
    }
    if ($processStemLabel === '') {
      $processStemLabel = 'Unknown Process';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $startDateYmd, $matches)) {
      $startDateYmd = $matches[1] . '/' . $matches[2] . '/' . $matches[3];
    }
    elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $startDateYmd, $matches)) {
      $startDateYmd = $matches[1] . '/' . $matches[2] . '/' . $matches[3];
    }
    if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $startDateYmd)) {
      $startDateYmd = gmdate('Y/m/d');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $startTimeHm)) {
      $startTimeHm = gmdate('H:i');
    }

    return $personLabel . "'s " . $processStemLabel . ' at ' . $startDateYmd . ' ' . $startTimeHm;
  }

  /**
   * Compose scenario label and resolved context values.
   *
   * @return array{label:string,startDateYmd:string,startTimeHm:string,personLabel:string,processStemUri:string,processStemLabel:string}
   */
  public static function composeLabelForStudyPayload(string $ownerEmail, string $processUri, string $startDateIso = '', string $existingLabel = '', string $institutionName = ''): array {
    $personLabel = self::resolvePersonLabelByEmail($ownerEmail);
    $processStem = self::resolveProcessStemInfo($processUri);

    $processLabel = trim((string) ($processStem['processLabel'] ?? ''));
    if ($processLabel === '') {
      $processLabel = trim((string) ($processStem['processStemLabel'] ?? ''));
    }

    $startDateYmd = '';
    $normalizedStartDateIso = trim($startDateIso);
    if ($normalizedStartDateIso !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $normalizedStartDateIso, $matches)) {
      $startDateYmd = $matches[1] . '/' . $matches[2] . '/' . $matches[3];
    }

    if ($startDateYmd === '' && preg_match('/ at (\d{4}\/\d{2}\/\d{2}) \d{2}:\d{2}$/', trim($existingLabel), $matches)) {
      $startDateYmd = $matches[1];
    }
    if ($startDateYmd === '' && preg_match('/ at (\d{8}) \d{2}:\d{2}$/', trim($existingLabel), $matches)) {
      $startDateYmd = substr($matches[1], 0, 4) . '/' . substr($matches[1], 4, 2) . '/' . substr($matches[1], 6, 2);
    }
    if ($startDateYmd === '') {
      $startDateYmd = gmdate('Y/m/d');
    }

    $startTimeHm = '';
    if (preg_match('/ at \d{8} (\d{2}:\d{2})$/', trim($existingLabel), $matches)) {
      $startTimeHm = $matches[1];
    }
    if ($startTimeHm === '') {
      $startTimeHm = gmdate('H:i');
    }

    return [
      'label' => self::composeScenarioLabel($personLabel, $processLabel, '', $startDateYmd, $startTimeHm),
      'startDateYmd' => $startDateYmd,
      'startTimeHm' => $startTimeHm,
      'personLabel' => $personLabel,
      'institutionLabel' => '',
      'processStemUri' => (string) ($processStem['processStemUri'] ?? ''),
      'processStemLabel' => (string) ($processStem['processStemLabel'] ?? ''),
      'processLabel' => $processLabel,
    ];
  }

  /**
   * Generate table header with Process column
   */
  public static function generateHeader() {
    $header = parent::generateHeader();
    
    // Add Process column after element_name
    $new_header = [];
    foreach ($header as $key => $value) {
      $new_header[$key] = $value;
      if ($key === 'element_name') {
        $new_header['element_process'] = t('Process/Workflow');
      }
    }
    
    return $new_header;
  }

  /**
   * Generate table output with Process information
   */
  public static function generateOutput($list) {
    $root_url = \Drupal::request()->getBaseUrl();
    
    if ($list == NULL) {
      return [];
    }

    $output = [];
    
    foreach ($list as $element) {
      // Get base study row from parent
      $row = parent::generateStudyRow($element);
      
      // Add Process column if processUri exists
      if (isset($element->processUri) && !empty($element->processUri)) {
        $processUri = Utils::namespaceUri($element->processUri);
        
        // Create link to process/workflow
        $processUriEncoded = base64_encode($element->processUri);
        $process_view_url = Url::fromRoute('rep.describe_element', [
          'elementuri' => $processUriEncoded,
        ]);
        
        $process_link = [
          '#type' => 'link',
          '#title' => $processUri,
          '#url' => $process_view_url,
          '#attributes' => [
            'class' => ['process-link'],
          ],
        ];
        
        $row['element_process'] = \Drupal::service('renderer')->render($process_link);
      } else {
        $row['element_process'] = t('No process associated');
      }
      
      $output[$element->uri] = $row;
    }
    
    return $output;
  }

  /**
   * Helper method to generate a single study row
   * (Extracted from parent's generateOutput for reuse)
   */
  protected static function generateStudyRow($element) {
    $root_url = \Drupal::request()->getBaseUrl();
    
    $uri = ' ';
    if ($element->uri != NULL) {
      $uri = Utils::namespaceUri($element->uri);
    }
    
    $label = ' ';
    if ($element->label != NULL) {
      $label = $element->label;
    }
    
    $title = ' ';
    if ($element->title != NULL) {
      $title = $element->title;
    }

    // Build action links
    $path = \Drupal::request()->getPathInfo();
    $safe_previousUrl = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    $safe_previousUrl_str = base64_encode($safe_previousUrl);
    $studyUriEncoded = base64_encode($element->uri);

    // Manage Elements link
    $manage_elements_str = base64_encode(Url::fromRoute('std.manage_study_elements', [
      'studyuri' => $studyUriEncoded,
    ])->toString());

    $manage_elements = Url::fromRoute('rep.back_url', [
      'previousurl' => $safe_previousUrl_str,
      'currenturl' => $manage_elements_str,
      'currentroute' => 'std.manage_study_elements',
    ]);

    // View link
    $view_study_str = base64_encode(Utils::describeHref((string) ($element->uri ?? ''), [], FALSE));
    $view_study = Url::fromRoute('rep.back_url', [
      'previousurl' => $safe_previousUrl_str,
      'currenturl' => $view_study_str,
      'currentroute' => 'rep.describe_element',
    ]);

    $actions = [
      'manage_element' => [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa-solid fa-folder-tree"></i> Manage'),
        '#url' => $manage_elements,
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-sm'],
        ],
      ],
      'view' => [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
        '#url' => $view_study,
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-sm', 'mx-1'],
        ],
      ],
    ];

    $actions_render = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'flex-wrap', 'gap-1'],
      ],
      'manage_element' => $actions['manage_element'],
      'view' => $actions['view'],
    ];
    // Add DSG Download button for ProcessBasedStudy
    $dsg_download_str = base64_encode(Url::fromRoute('std.download_dsg', [
      'studyuri' => $studyUriEncoded,
    ])->toString());
    
    $download_dsg = Url::fromRoute('rep.back_url', [
      'previousurl' => $safe_previousUrl_str,
      'currenturl' => $dsg_download_str,
      'currentroute' => 'std.download_dsg',
    ]);
    
    $actions_render['download_dsg'] = [
      '#type' => 'link',
      '#title' => Markup::create('<i class="fa-solid fa-download"></i> Download DSG'),
      '#url' => $download_dsg,
      '#attributes' => [
        'class' => ['btn', 'btn-success', 'btn-sm', 'mx-1'],
        'title' => t('Download study design specification (DSG) for formal registration'),
      ],
    ];
    return [
      'element_uri' => $uri,
      'element_short_name' => $label,
      'element_name' => $title,
      'element_n_roles' => $element->numberOfRoles ?? 0,
      'element_n_vcs' => $element->numberOfVCs ?? 0,
      'element_n_socs' => $element->numberOfSOCs ?? 0,
      'element_actions' => \Drupal::service('renderer')->render($actions_render),
    ];
  }

  /**
   * Create ProcessBasedStudy from Workflow/Process URI
   * 
   * This method creates a ProcessBasedStudy by calling the backend API.
   * The backend will auto-generate study metadata from the Process/Workflow.
   * 
   * @param string $processUri The Process/Workflow URI (e.g., pmsr:WKF-X/PROC/0001)
   * @param string $creator The creator's email
   * @return object|null The created study object or null on failure
   */
  public static function createFromWorkflow($processUri, $creator) {
    if (empty($processUri)) {
      \Drupal::logger('std')->error('Cannot create ProcessBasedStudy: empty processUri');
      return NULL;
    }

    $processUri = Utils::canonicalizePmsrUri((string) $processUri);

    $api = \Drupal::service('rep.api_connector');
    
    // Generate study URI
    $studyUri = Utils::canonicalizePmsrUri(Utils::uriGen('study'));
    
    $composedLabelData = self::composeLabelForStudyPayload((string) $creator, (string) $processUri, gmdate('Y-m-d'));

    // Build minimal JSON payload.
    $studyData = [
      'uri' => $studyUri,
      'typeUri' => \Drupal\rep\Vocabulary\HASCO::PROCESS_BASED_STUDY,
      'hascoTypeUri' => \Drupal\rep\Vocabulary\HASCO::PROCESS_BASED_STUDY,
      'processUri' => $processUri,
      'hasSIRManagerEmail' => $creator,
      'label' => (string) ($composedLabelData['label'] ?? ''),
      'studyTitle' => (string) ($composedLabelData['label'] ?? ''),
      // Keep metadata fields explicit.
      'studyID' => '',
      'specificAims' => '',
      'significance' => '',
      'institutionName' => '',
      'institutionUri' => '',
      'principalInvestigator' => '',
      'contactEmail' => '',
      'startDate' => '',
      'endDate' => '',
      'hasLearningObjectives' => '',
      'hasCriticalActions' => '',
      'hasDebriefingFocus' => '',
    ];
    
    $studyJSON = json_encode($studyData);
    
    try {
      // Use generic API to create ProcessBasedStudy
      $addResponse = $api->elementAdd('processbasedstudy', $studyJSON);
      $created = $api->parseObjectResponse($addResponse, 'elementAdd');
      
      if ($created === NULL) {
        throw new \RuntimeException('API rejected ProcessBasedStudy creation');
      }
      
      // Verify creation
      $verify = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
      if ($verify === NULL) {
        throw new \RuntimeException('ProcessBasedStudy was not persisted');
      }
      
      \Drupal::logger('std')->info('Created ProcessBasedStudy @study from Process @process', [
        '@study' => $studyUri,
        '@process' => $processUri,
      ]);
      
      return $verify;
      
    } catch (\Exception $e) {
      \Drupal::logger('std')->error('Failed to create ProcessBasedStudy from process @process: @error', [
        '@process' => $processUri,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Derive Study ID from Workflow URI
   * Pattern: pmsr:WKF-{id} → STD-{id}
   * 
   * @param string $wkfUri The workflow URI
   * @return string The derived study ID
   */
  public static function deriveStudyIdFromWorkflow($wkfUri) {
    if (empty($wkfUri)) {
      return '';
    }

    $wkfUri = Utils::canonicalizePmsrUri((string) $wkfUri);
    
    // Extract ID from WKF URI
    // Example: https://pmsr.net/ont/WKF-SECRETION-001/... -> STD-SECRETION-001
    if (preg_match('/WKF[-_](.+?)(?:\/|$)/', $wkfUri, $matches)) {
      return 'STD-' . str_replace('_', '-', $matches[1]);
    }
    
    // Fallback: just replace WKF with STD
    $fallback = str_replace(['WKF-', 'WKF_', 'WFK-', 'WFK_'], ['STD-', 'STD-', 'STD-', 'STD-'], $wkfUri);
    return str_replace('_', '-', $fallback);
  }

  /**
   * Check if a study is a ProcessBasedStudy
   * 
   * @param object $study The study object
   * @return bool TRUE if it has a process, FALSE otherwise
   */
  public static function isProcessBasedStudy($study) {
    return isset($study->processUri) && !empty($study->processUri);
  }

}
