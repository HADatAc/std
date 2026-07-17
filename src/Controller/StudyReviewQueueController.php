<?php

namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\rep\Utils;

/**
 * Controller for Study Review Queue
 * 
 * Displays auto-generated ProcessBasedStudy instances that need metadata enrichment.
 * 
 * Related to: PMSR ProcessBasedStudy migration - Phase 3 (Study Review Queue)
 */
class StudyReviewQueueController extends ControllerBase {

  /**
   * Display study review queue
   */
  public function reviewQueue() {
    $api = \Drupal::service('rep.api_connector');
    
    // Get all ProcessBasedStudies
    $response = $api->parseObjectResponse(
      $api->apiCall('/hascoapi/api/processbasedstudy/elements/100/0', 'GET'),
      'getElements'
    );
    
    if ($response === NULL) {
      \Drupal::messenger()->addWarning($this->t('Unable to load Process-Based Studies for review.'));
      return [
        '#markup' => $this->t('Error loading studies.'),
      ];
    }
    
    // Filter for auto-generated studies (those with empty or default metadata)
    $needsReview = [];
    if (is_array($response)) {
      foreach ($response as $study) {
        if ($this->needsMetadataEnrichment($study)) {
          $needsReview[] = $study;
        }
      }
    }
    
    return [
      '#theme' => 'study_review_queue',
      '#studies' => $needsReview,
      '#title' => $this->t('Studies Needing Metadata Review'),
      '#attached' => [
        'library' => [
          'std/std_js_css',
        ],
      ],
    ];
  }

  /**
   * Check if study needs metadata enrichment
   */
  private function needsMetadataEnrichment($study) {
    // Check if critical metadata fields are empty or have default values
    $needsReview = false;
    
    // Empty specific aims
    if (empty($study->specificAims) || $study->specificAims === 'Clinical simulation study') {
      $needsReview = true;
    }
    
    // Empty significance
    if (empty($study->significance) || $study->significance === 'Clinical simulation study') {
      $needsReview = true;
    }
    
    // Study ID looks auto-generated (contains workflow pattern)
    if (isset($study->studyID) && (strpos($study->studyID, 'WKF') !== false || strpos($study->studyID, 'PROC') !== false)) {
      $needsReview = true;
    }
    
    return $needsReview;
  }

  /**
   * Download DSG for a ProcessBasedStudy
   */
  public function downloadDSG($studyuri) {
    $uri = base64_decode($studyuri);
    
    $api = \Drupal::service('rep.api_connector');
    
    // Call hascoapi DSG generation endpoint
    $dsgUrl = '/hascoapi/api/processbasedstudy/dsg/' . urlencode($uri);
    
    try {
      // Redirect to DSG download URL
      return $this->redirect('rep.api_proxy', [
        'path' => $dsgUrl,
      ]);
    } catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Error generating DSG: @error', ['@error' => $e->getMessage()]));
      return $this->redirect('std.select_study', [
        'elementtype' => 'processbasedstudy',
        'page' => '1',
        'pagesize' => '9',
      ]);
    }
  }
}
