<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\std\Entity\ProcessBasedStudy;

/**
 * Form for editing a Process-Based Study
 * 
 * This form allows editing ProcessBasedStudy metadata.
 * The Process URI cannot be changed after creation.
 * 
 * Related to PMSR ProcessBasedStudy migration (Phase 2)
 */
class EditProcessBasedStudyForm extends FormBase {

  protected $studyUri;
  protected $study;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_processbasedstudy_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL) {

    // Decode the URI parameter
    $this->studyUri = base64_decode($studyuri);

    // Fetch the existing ProcessBasedStudy from the API
    $api = \Drupal::service('rep.api_connector');
    $studyResponse = $api->getUri($this->studyUri);
    $this->study = $api->parseObjectResponse($studyResponse, 'getUri');

    if ($this->study === NULL) {
      \Drupal::messenger()->addError($this->t('Failed to load ProcessBasedStudy with URI: @uri', ['@uri' => $this->studyUri]));
      self::backUrl();
      return [];
    }

    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h3>Edit Process-Based Study</h3>'),
    ];

    $form['study_uri_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Study URI'),
      '#markup' => $this->studyUri,
    ];

    // Hidden field to preserve the URI
    $form['study_uri'] = [
      '#type' => 'hidden',
      '#value' => $this->studyUri,
    ];

    // Display Process URI (read-only, cannot be changed)
    $form['process_uri_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Process/Workflow URI'),
      '#markup' => $this->study->processUri ?? 'N/A',
      '#description' => $this->t('The associated Process/Workflow cannot be changed after creation.'),
    ];

    // EDITABLE: Study metadata fields
    $form['study_metadata'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Study Metadata'),
      '#collapsible' => FALSE,
    ];

    $form['study_metadata']['study_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study ID'),
      '#default_value' => $this->study->studyID ?? '',
      '#description' => $this->t('Study identifier (e.g., STD-001). Must start with "STD-".'),
      '#maxlength' => 128,
    ];

    $form['study_metadata']['study_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study Title'),
      '#default_value' => $this->study->studyTitle ?? '',
      '#description' => $this->t('Full title of the study.'),
      '#maxlength' => 512,
    ];

    $form['study_metadata']['specific_aims'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specific Aims'),
      '#default_value' => $this->study->specificAims ?? '',
      '#description' => $this->t('Research aims and objectives.'),
      '#rows' => 3,
    ];

    $form['study_metadata']['significance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Significance'),
      '#default_value' => $this->study->significance ?? '',
      '#description' => $this->t('Why this study is important.'),
      '#rows' => 3,
    ];

    $form['study_metadata']['institution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Institution'),
      '#default_value' => $this->study->institution ?? '',
      '#description' => $this->t('Name of the institution conducting the study.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['principal_investigator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Principal Investigator'),
      '#default_value' => $this->study->principalInvestigator ?? '',
      '#description' => $this->t('Name of the PI.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact Email'),
      '#default_value' => $this->study->contactEmail ?? '',
      '#description' => $this->t('Email address for study contact.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#default_value' => $this->study->startDate ?? '',
      '#description' => $this->t('Study start date (ISO 8601 format: YYYY-MM-DD).'),
    ];

    $form['study_metadata']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
      '#default_value' => $this->study->endDate ?? '',
      '#description' => $this->t('Study end date (ISO 8601 format: YYYY-MM-DD).'),
    ];

    // Submit buttons
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#name' => 'save',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $study_id = trim($form_state->getValue('study_id'));
    $contact_email = trim($form_state->getValue('contact_email'));

    // Validate Study ID format if provided
    if (!empty($study_id)) {
      if (!preg_match('/^STD-/', $study_id)) {
        $form_state->setErrorByName('study_id', $this->t('Study ID must start with "STD-".'));
      }
    }

    // Validate email format if provided
    if (!empty($contact_email)) {
      if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('contact_email', $this->t('Contact Email must be a valid email address.'));
      }
    }

    // Validate date order if both provided
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    if (!empty($start_date) && !empty($end_date)) {
      if (strtotime($start_date) > strtotime($end_date)) {
        $form_state->setErrorByName('end_date', $this->t('End Date must be after Start Date.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    try {
      $studyUri = $form_state->getValue('study_uri');

      // Build JSON payload for ProcessBasedStudy update
      // Include all editable metadata fields
      $studyData = [
        'uri' => $studyUri,
        'typeUri' => HASCO::PROCESS_BASED_STUDY,
        'hascoTypeUri' => HASCO::PROCESS_BASED_STUDY,
        'processUri' => $this->study->processUri, // Cannot be changed
        'studyID' => trim($form_state->getValue('study_id')),
        'studyTitle' => trim($form_state->getValue('study_title')),
        'specificAims' => trim($form_state->getValue('specific_aims')),
        'significance' => trim($form_state->getValue('significance')),
        'institution' => trim($form_state->getValue('institution')),
        'principalInvestigator' => trim($form_state->getValue('principal_investigator')),
        'contactEmail' => trim($form_state->getValue('contact_email')),
        'startDate' => $form_state->getValue('start_date') ?: '',
        'endDate' => $form_state->getValue('end_date') ?: '',
      ];

      $studyJSON = json_encode($studyData);

      // Use dedicated API to update ProcessBasedStudy
      $api = \Drupal::service('rep.api_connector');
      
      // Call the update endpoint: POST /api/processbasedstudy/update/:uri
      $updateUrl = '/hascoapi/api/processbasedstudy/update/' . urlencode($studyUri);
      $updateResponse = $api->apiCall($updateUrl, 'POST', $studyJSON);
      $updated = $api->parseObjectResponse($updateResponse, 'update');
      
      if ($updated === NULL) {
        throw new \RuntimeException('API rejected ProcessBasedStudy update payload.');
      }

      // Verify update
      $verify = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
      if ($verify === NULL) {
        throw new \RuntimeException('ProcessBasedStudy was not persisted after update call.');
      }

      \Drupal::messenger()->addMessage($this->t('Process-Based Study has been updated successfully.'));
      
      // Invalidate study search cache for this study
      \Drupal\std\Service\StudyVariableSearchService::invalidateCache($studyUri);
      
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addError($this->t('An error occurred while updating Process-Based Study: @message', ['@message' => $e->getMessage()]));
      self::backUrl();
      return;
    }
  }

  /**
   * Navigate back to previous page
   */
  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_processbasedstudy');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
