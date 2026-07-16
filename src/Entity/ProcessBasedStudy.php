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
   * Create ProcessBasedStudy from Workflow via hascoapi
   * 
   * @param string $wkfUri The workflow URI
   * @param string $creator The creator's email
   * @return object|null The created study object or null on failure
   */
  public static function createFromWorkflow($wkfUri, $creator) {
    $api = \Drupal::service('rep.api_connector');
    
    // Call hascoapi to generate ProcessBasedStudy
    $response = $api->post('/api/processbasedstudy/create', [
      'workflowUri' => $wkfUri,
      'creator' => $creator,
    ]);
    
    if ($response && isset($response->uri)) {
      return $response;
    }
    
    \Drupal::logger('std')->error('Failed to create ProcessBasedStudy from workflow: @wkf', [
      '@wkf' => $wkfUri,
    ]);
    
    return NULL;
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
    
    // Extract ID from WKF URI
    // Example: http://pmsr.net/ont/pmsr#/WKF_SECRETION_001 → STD_SECRETION_001
    if (preg_match('/WKF[-_](.+?)(?:\/|$)/', $wkfUri, $matches)) {
      return 'STD-' . str_replace('_', '-', $matches[1]);
    }
    
    // Fallback: just replace WKF with STD
    return str_replace(['WKF-', 'WKF_'], ['STD-', 'STD_'], $wkfUri);
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
