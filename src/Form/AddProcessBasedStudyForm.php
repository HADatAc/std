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
 * Form for adding a Process-Based Study
 * 
 * This form allows manual creation of a ProcessBasedStudy by:
 * 1. Selecting an existing Workflow/Process
 * 2. Entering study metadata (optional - will be auto-generated if empty)
 * 
 * Related to PMSR ProcessBasedStudy migration (Phase 2)
 */
class AddProcessBasedStudyForm extends FormBase {

  protected $studyUri;

  public function setStudyUri() {
    $this->studyUri = Utils::uriGen('study');
  }

  public function getStudyUri() {
    return $this->studyUri;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_processbasedstudy_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Check if the study URI already exists in the form state.
    // If not, generate a new URI and store it in the form state.
    if (!$form_state->has('study_uri')) {
      $this->setStudyUri();
      $form_state->set('study_uri', $this->getStudyUri());
    }
    else {
      // Retrieve the persisted URI from form state.
      $this->studyUri = $form_state->get('study_uri');
    }

    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h3>Add Process-Based Study</h3>'),
    ];

    $form['study_uri_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Study URI'),
      '#markup' => $this->studyUri,
      '#description' => $this->t('This is the URI that will be assigned to the new study.'),
    ];

    // Hidden field to preserve the URI across form rebuilds
    $form['study_uri'] = [
      '#type' => 'hidden',
      '#value' => $this->studyUri,
    ];

    // REQUIRED: Workflow/Process selection
    $form['process_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow/Process URI'),
      '#required' => TRUE,
      '#description' => $this->t('Enter the URI of the Process/Workflow (e.g., http://localhost/kb/pmsr/WKF-X/PROC/0001). The Study ID will be derived from the Workflow ID.'),
      '#maxlength' => 512,
    ];

    // OPTIONAL: Study metadata fields (auto-generated if empty)
    $form['study_metadata'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Study Metadata (Optional)'),
      '#description' => $this->t('These fields are optional. If left empty, they will be auto-generated from the Workflow metadata.'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['study_metadata']['study_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study ID'),
      '#description' => $this->t('Study identifier (e.g., STD-001). Must start with "STD-". Leave empty to auto-derive from Workflow ID.'),
      '#maxlength' => 128,
    ];

    $form['study_metadata']['study_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study Title'),
      '#description' => $this->t('Full title of the study. Leave empty to use Workflow label.'),
      '#maxlength' => 512,
    ];

    $form['study_metadata']['specific_aims'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specific Aims'),
      '#description' => $this->t('Research aims and objectives.'),
      '#rows' => 3,
    ];

    $form['study_metadata']['significance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Significance'),
      '#description' => $this->t('Why this study is important.'),
      '#rows' => 3,
    ];

    $form['study_metadata']['institution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Institution'),
      '#description' => $this->t('Name of the institution conducting the study.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['principal_investigator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Principal Investigator'),
      '#description' => $this->t('Name of the PI.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact Email'),
      '#description' => $this->t('Email address for study contact.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#description' => $this->t('Study start date (ISO 8601 format: YYYY-MM-DD).'),
    ];

    $form['study_metadata']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
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
    $process_uri = trim($form_state->getValue('process_uri'));
    $study_id = trim($form_state->getValue('study_id'));
    $contact_email = trim($form_state->getValue('contact_email'));

    // Validate Process URI format
    if (!empty($process_uri)) {
      if (!filter_var($process_uri, FILTER_VALIDATE_URL)) {
        $form_state->setErrorByName('process_uri', $this->t('Process URI must be a valid URL.'));
      }
    }

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
      $useremail = \Drupal::currentUser()->getEmail();
      $newStudyUri = $form_state->getValue('study_uri');
      $process_uri = trim($form_state->getValue('process_uri'));

      // Build JSON payload for ProcessBasedStudy
      // Include all metadata fields (empty strings will trigger auto-generation in backend)
      $studyData = [
        'uri' => $newStudyUri,
        'typeUri' => HASCO::PROCESS_BASED_STUDY,
        'hascoTypeUri' => HASCO::PROCESS_BASED_STUDY,
        'processUri' => $process_uri,
        'studyID' => trim($form_state->getValue('study_id')),
        'studyTitle' => trim($form_state->getValue('study_title')),
        'specificAims' => trim($form_state->getValue('specific_aims')),
        'significance' => trim($form_state->getValue('significance')),
        'institution' => trim($form_state->getValue('institution')),
        'principalInvestigator' => trim($form_state->getValue('principal_investigator')),
        'contactEmail' => trim($form_state->getValue('contact_email')),
        'startDate' => $form_state->getValue('start_date') ?: '',
        'endDate' => $form_state->getValue('end_date') ?: '',
        'hasSIRManagerEmail' => $useremail,
      ];

      $studyJSON = json_encode($studyData);

      // Use generic API to create ProcessBasedStudy
      $api = \Drupal::service('rep.api_connector');
      $addResponse = $api->elementAdd('processbasedstudy', $studyJSON);
      $created = $api->parseObjectResponse($addResponse, 'elementAdd');
      
      if ($created === NULL) {
        throw new \RuntimeException('API rejected ProcessBasedStudy creation payload. Check that the Process URI exists and is valid.');
      }

      // Verify creation
      $verify = $api->parseObjectResponse($api->getUri($newStudyUri), 'getUri');
      if ($verify === NULL) {
        throw new \RuntimeException('ProcessBasedStudy was not persisted after create call.');
      }

      \Drupal::messenger()->addMessage($this->t('Process-Based Study has been added successfully.'));
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addError($this->t('An error occurred while adding Process-Based Study: @message', ['@message' => $e->getMessage()]));
      self::backUrl();
      return;
    }
  }

  /**
   * Navigate back to previous page
   */
  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_processbasedstudy');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
