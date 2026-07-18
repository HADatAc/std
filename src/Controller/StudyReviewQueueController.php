<?php

namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use GuzzleHttp\Exception\RequestException;

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
    // Get hascoapi base URL from settings
    $config = \Drupal::config('rep.settings');
    $hascoapi_url = $config->get('hascoapi_url') ?: 'http://localhost:9001';
    
    // Make direct HTTP request to hascoapi
    $client = \Drupal::httpClient();
    $response = NULL;
    $studyObjects = [];
    
    try {
      $result = $client->get($hascoapi_url . '/hascoapi/api/processbasedstudy/elements/100/0');
      $body = (string) $result->getBody();
      $json = json_decode($body);
      
      if ($json && isset($json->isSuccessful) && $json->isSuccessful && isset($json->body)) {
        $studyObjects = is_array($json->body) ? $json->body : [];
      }
    } catch (RequestException $e) {
      \Drupal::logger('std')->error('Failed to fetch ProcessBasedStudy list: @message', ['@message' => $e->getMessage()]);
    }
    
    // Handle API error
    if ($studyObjects === NULL) {
      \Drupal::messenger()->addWarning($this->t('Unable to load Process-Based Studies for review.'));
      return [
        '#markup' => $this->t('Error loading studies.'),
      ];
    }
    
    // Handle empty database
    if (empty($studyObjects)) {
      \Drupal::messenger()->addMessage($this->t('No Process-Based Studies found. Create studies by uploading workflow files or manually adding them.'));
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['study-review-queue-empty']],
        'message' => [
          '#type' => 'markup',
          '#markup' => '<div class="empty-state">' .
            '<h2>' . $this->t('No Studies to Review') . '</h2>' .
            '<p>' . $this->t('Process-Based Studies will appear here once they are created.') . '</p>' .
            '<p>' . $this->t('To create studies:') . '</p>' .
            '<ul>' .
            '<li>' . $this->t('Upload a Workflow (WKF) file to automatically generate studies') . '</li>' .
            '<li>' . $this->t('Or manually add a Process-Based Study') . '</li>' .
            '</ul>' .
            '</div>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['empty-state-actions']],
          'add_workflow' => [
            '#type' => 'link',
            '#title' => $this->t('Upload Workflow'),
            '#url' => Url::fromRoute('std.add_workflow', ['state' => 'upload']),
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
          'add_study' => [
            '#type' => 'link',
            '#title' => $this->t('Add Process-Based Study'),
            '#url' => Url::fromRoute('std.add_processbasedstudy'),
            '#attributes' => ['class' => ['button']],
          ],
        ],
      ];
    }
    
    // Filter for auto-generated studies (those with empty or default metadata)
    $needsReview = [];
    foreach ($studyObjects as $study) {
      if ($this->needsMetadataEnrichment($study)) {
        $needsReview[] = $study;
      }
    }
    
    // Handle case where all studies have complete metadata
    if (empty($needsReview)) {
      \Drupal::messenger()->addMessage($this->t('All Process-Based Studies have complete metadata. Great job!'));
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['study-review-queue-complete']],
        'message' => [
          '#type' => 'markup',
          '#markup' => '<div class="complete-state">' .
            '<h2>' . $this->t('Review Queue Empty') . '</h2>' .
            '<p>' . $this->t('All @count Process-Based Studies have complete metadata.', ['@count' => count($studyObjects)]) . '</p>' .
            '</div>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['complete-state-actions']],
          'view_studies' => [
            '#type' => 'link',
            '#title' => $this->t('View All Studies'),
            '#url' => Url::fromRoute('std.select_study', [
              'elementtype' => 'processbasedstudy',
              'page' => '1',
              'pagesize' => '12',
            ]),
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ],
      ];
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
