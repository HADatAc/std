<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;

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
  protected $processUri = '';

  /**
   * Preferred study noun from configuration (lowercase).
   */
  private function preferredStudyNoun(): string {
    $configured = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';
    $normalized = trim((string) $configured);
    return $normalized !== '' ? strtolower($normalized) : 'study';
  }

  /**
   * Preferred study label from configuration (title case).
   */
  private function preferredStudyLabel(): string {
    return ucfirst($this->preferredStudyNoun());
  }

  /**
   * Convert API values to safe scalar strings for form rendering.
   */
  private function toSafeString($value) {
    if (is_string($value) || is_numeric($value)) {
      return trim((string) $value);
    }
    if (is_object($value)) {
      foreach (['uri', 'label', 'name', 'title', 'value'] as $key) {
        if (isset($value->{$key}) && (is_string($value->{$key}) || is_numeric($value->{$key}))) {
          return trim((string) $value->{$key});
        }
      }
      return '';
    }
    if (is_array($value)) {
      foreach ($value as $item) {
        $normalized = $this->toSafeString($item);
        if ($normalized !== '') {
          return $normalized;
        }
      }
    }
    return '';
  }

  /**
   * Resolve the linked process URI from a Process-Based Study object.
   */
  private function resolveProcessUriFromStudy($study): string {
    $processUri = '';
    if (is_object($study) && isset($study->processUri)) {
      if (is_object($study->processUri)) {
        $processUri = trim((string) ($study->processUri->uri ?? ''));
      }
      else {
        $processUri = trim((string) $study->processUri);
      }
    }
    if ($processUri === '' && is_object($study) && isset($study->hasProcess)) {
      if (is_object($study->hasProcess)) {
        $processUri = trim((string) ($study->hasProcess->uri ?? ''));
      }
      else {
        $processUri = trim((string) $study->hasProcess);
      }
    }
    if ($processUri === '' && is_object($study) && isset($study->hasProcessUri)) {
      $processUri = trim((string) $study->hasProcessUri);
    }
    return $processUri;
  }

  /**
   * Resolve a canonical study URI from form state, object state, or route.
   */
  private function resolveStudyUriForSubmit(FormStateInterface $form_state): string {
    $candidate = trim((string) $form_state->getValue('study_uri'));
    if ($candidate === '') {
      $candidate = trim((string) ($this->studyUri ?? ''));
    }

    if ($candidate === '' && is_object($this->study) && isset($this->study->uri)) {
      $candidate = trim((string) $this->study->uri);
    }

    if ($candidate === '') {
      $routeParam = \Drupal::routeMatch()->getParameter('studyuri');
      $encoded = is_string($routeParam) ? rawurldecode($routeParam) : '';
      $decoded = $encoded !== '' ? base64_decode($encoded, TRUE) : FALSE;
      if (is_string($decoded) && trim($decoded) !== '') {
        $candidate = trim($decoded);
      }
      elseif ($encoded !== '') {
        // Support plain URI route parameters when not base64-encoded.
        $candidate = trim($encoded);
      }
    }

    if ($candidate === '') {
      $buildInfo = $form_state->getBuildInfo();
      $arg0 = $buildInfo['args'][0] ?? '';
      $encoded = is_string($arg0) ? rawurldecode($arg0) : '';
      $decoded = $encoded !== '' ? base64_decode($encoded, TRUE) : FALSE;
      if (is_string($decoded) && trim($decoded) !== '') {
        $candidate = trim($decoded);
      }
      elseif ($encoded !== '') {
        $candidate = trim($encoded);
      }
    }

    return Utils::canonicalizePmsrUri($candidate);
  }

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
    $preferredStudyNoun = $this->preferredStudyNoun();
    $preferredStudyLabel = $this->preferredStudyLabel();

    // Decode and validate the URI parameter.
    $encodedStudyUri = is_string($studyuri) ? rawurldecode($studyuri) : '';
    $decodedStudyUri = $encodedStudyUri !== '' ? base64_decode($encodedStudyUri, TRUE) : FALSE;
    if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
      \Drupal::messenger()->addError($this->t('Invalid Process-Based @study URI.', ['@study' => $preferredStudyLabel]));
      $form_state->setRedirect('std.edit_study', ['studyuri' => $studyuri]);
      return [];
    }
    $this->studyUri = Utils::canonicalizePmsrUri(trim($decodedStudyUri));

    // Fetch the existing ProcessBasedStudy from the API
    $api = \Drupal::service('rep.api_connector');
    try {
      $studyResponse = $api->getUri($this->studyUri);
      $this->study = $api->parseObjectResponse($studyResponse, 'getUri');
    }
    catch (\Throwable $e) {
      \Drupal::messenger()->addError($this->t('Failed to load Process-Based @study: @message', [
        '@study' => $preferredStudyLabel,
        '@message' => $e->getMessage(),
      ]));
      $form_state->setRedirect('std.edit_study', ['studyuri' => $studyuri]);
      return [];
    }

    if ($this->study === NULL) {
      \Drupal::messenger()->addError($this->t('Failed to load ProcessBasedStudy with URI: @uri', ['@uri' => $this->studyUri]));
      $form_state->setRedirect('std.edit_study', ['studyuri' => $studyuri]);
      return [];
    }

    // Normalize process URI values that may arrive as structured objects.
    $processUri = Utils::canonicalizePmsrUri($this->resolveProcessUriFromStudy($this->study));
    $this->processUri = $processUri;

    $form['process_header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'align-items-center', 'mb-3'],
      ],
    ];

    $form['process_header']['process_actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ms-auto', 'btn-group'],
        'role' => 'group',
        'aria-label' => $this->t('Workflow actions'),
      ],
    ];

    $form['process_header']['process_actions']['validate_task_model'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate Task Model'),
      '#submit' => ['::validateTaskModel'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'check-button', 'top-icon'],
        'style' => 'max-width: 120px;',
      ],
    ];

    $form['process_header']['process_actions']['execute_task_model'] = [
      '#type' => 'submit',
      '#value' => $this->t('Execute Task Model'),
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'execute-button'],
        'style' => 'max-width: 120px;',
      ],
      '#disabled' => TRUE,
    ];

    $form['process_header']['process_actions']['edit_task'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model'),
      '#submit' => ['::openLegacyEditor'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'edit-task-button', 'edit-element-button', 'top-icon'],
        'style' => 'max-width: 120px; border-color: #006600;',
      ],
    ];

    $form['process_header']['process_actions']['edit_task_canvas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model - Canvas'),
      '#submit' => ['::openCanvasEditor'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-task-button', 'edit-element-button', 'top-icon', 'edit-task-canvas-button'],
        'style' => 'max-width: 120px; border-color: #006600;',
      ],
    ];

    $form['study_uri_display'] = [
      '#type' => 'item',
      '#title' => $this->t('@study URI', ['@study' => $preferredStudyLabel]),
      '#markup' => Html::escape($this->studyUri),
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
      '#markup' => $processUri !== '' ? Html::escape($processUri) : 'N/A',
      '#description' => $this->t('The associated Process/Workflow cannot be changed after creation.'),
    ];

    if ($processUri !== '') {
      $processDetailsUrl = Url::fromRoute('rep.describe_element', [
        'elementuri' => rawurlencode(base64_encode($processUri)),
      ])->toString();

      $taskModelUrl = Url::fromUri('internal:/ctt/editor', [
        'query' => [
          'processUri' => $processUri,
          'studyUri' => $this->studyUri,
          'execution' => '1',
        ],
      ])->toString();

      $form['procedure_links'] = [
        '#type' => 'item',
        '#title' => $this->t('Procedure / Task Model'),
        '#markup' => '<a href="' . $processDetailsUrl . '">' . $this->t('Open Procedure Details') . '</a>' .
          ' | <a href="' . $taskModelUrl . '">' . $this->t('Open Task Model Canvas') . '</a>',
      ];
    }

    // EDITABLE: Study metadata fields
    $form['study_metadata'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('@study Metadata', ['@study' => $preferredStudyLabel]),
      '#collapsible' => FALSE,
    ];

    $form['study_metadata']['study_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@study ID', ['@study' => $preferredStudyLabel]),
      '#default_value' => $this->toSafeString($this->study->studyID ?? ''),
      '#description' => $this->t('@study ID is read-only in edit mode because URI renaming is not supported.', ['@study' => $preferredStudyLabel]),
      '#maxlength' => 128,
      '#disabled' => TRUE,
    ];

    $form['study_metadata']['study_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@study Title', ['@study' => $preferredStudyLabel]),
      '#default_value' => $this->toSafeString($this->study->studyTitle ?? ''),
      '#description' => $this->t('Full title of the @study.', ['@study' => $preferredStudyNoun]),
      '#maxlength' => 512,
    ];

    $form['study_metadata']['specific_aims'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specific Aims'),
      '#default_value' => $this->toSafeString($this->study->specificAims ?? ''),
      '#description' => $this->t('Research aims and objectives.'),
      '#rows' => 3,
    ];

    $form['study_metadata']['significance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Significance'),
      '#default_value' => $this->toSafeString($this->study->significance ?? ''),
      '#description' => $this->t('Why this @study is important.', ['@study' => $preferredStudyNoun]),
      '#rows' => 3,
    ];

    $form['study_metadata']['institution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Institution'),
      '#default_value' => $this->toSafeString($this->study->institutionName ?? ($this->study->institution ?? '')),
      '#description' => $this->t('Name of the institution conducting the @study.', ['@study' => $preferredStudyNoun]),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['principal_investigator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Principal Investigator'),
      '#default_value' => $this->toSafeString($this->study->principalInvestigator ?? ($this->study->pi ?? '')),
      '#description' => $this->t('Name of the PI.'),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact Email'),
      '#default_value' => $this->toSafeString($this->study->contactEmail ?? ''),
      '#description' => $this->t('Email address for @study contact.', ['@study' => $preferredStudyNoun]),
      '#maxlength' => 256,
    ];

    $form['study_metadata']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#default_value' => $this->toSafeString($this->study->startDate ?? ''),
      '#description' => $this->t('@study start date (ISO 8601 format: YYYY-MM-DD).', ['@study' => $preferredStudyLabel]),
    ];

    $form['study_metadata']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
      '#default_value' => $this->toSafeString($this->study->endDate ?? ''),
      '#description' => $this->t('@study end date (ISO 8601 format: YYYY-MM-DD).', ['@study' => $preferredStudyLabel]),
    ];

    $form['study_metadata']['learning_objectives'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Learning Objectives'),
      '#default_value' => $this->toSafeString($this->study->hasLearningObjectives ?? ''),
      '#description' => $this->t('Measurable learning outcomes, semicolon-separated (INACSL Criterion 3).'),
      '#rows' => 3,
    ];

    $form['study_metadata']['critical_actions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Critical Actions'),
      '#default_value' => $this->toSafeString($this->study->hasCriticalActions ?? ''),
      '#description' => $this->t('Essential performance criteria for assessment, semicolon-separated (INACSL Criterion 5/10).'),
      '#rows' => 3,
    ];

    $form['study_metadata']['debriefing_focus'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Debriefing Focus'),
      '#default_value' => $this->toSafeString($this->study->hasDebriefingFocus ?? ''),
      '#description' => $this->t('Structured reflection topics/questions, semicolon-separated (INACSL Criterion 9).'),
      '#rows' => 3,
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

    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete'),
      '#name' => 'delete',
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button', 'button--danger'],
        'onclick' => 'return confirm("Are you sure you want to delete this scenario?");',
      ],
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
    $study_id = trim((string) $form_state->getValue('study_id'));
    $contact_email = trim($form_state->getValue('contact_email'));
    $preferredStudyLabel = $this->preferredStudyLabel();

    // Auto-normalize legacy identifiers such as STD_ABC to STD-ABC.
    if ($study_id !== '') {
      $normalizedStudyId = str_replace('_', '-', $study_id);
      if ($normalizedStudyId !== $study_id) {
        $form_state->setValue('study_id', $normalizedStudyId);
      }
      $study_id = $normalizedStudyId;
    }

    // Validate Study ID format if provided
    if (!empty($study_id)) {
      if (!preg_match('/^STD-/', $study_id)) {
        $form_state->setErrorByName('study_id', $this->t('@study ID must start with "STD-".', ['@study' => $preferredStudyLabel]));
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

    if ($button_name === 'delete') {
      try {
        $studyUri = $this->resolveStudyUriForSubmit($form_state);
        if ($studyUri === '') {
          throw new \RuntimeException('Missing ProcessBasedStudy URI for delete operation.');
        }
        $api = \Drupal::service('rep.api_connector');
        $deleteEndpoint = '/hascoapi/api/processbasedstudy/delete/' . rawurlencode($studyUri);
        $deleteResponse = $api->perform_http_request('POST', $api->getApiUrl() . $deleteEndpoint, $api->getHeader());
        if ($deleteResponse === NULL) {
          // Fallback for deployments where delete is exposed via GET.
          $deleteResponse = $api->elementDel('processbasedstudy', $studyUri);
        }
        $deleted = $api->parseObjectResponse($deleteResponse, 'deleteProcessBasedStudy');
        if ($deleted === NULL && $deleteResponse !== NULL) {
          // Some API builds return a success payload shape with empty body.
          $decodedDelete = json_decode((string) $deleteResponse);
          if (is_object($decodedDelete) && !empty($decodedDelete->isSuccessful)) {
            $deleted = TRUE;
          }
        }

        if ($deleted === NULL) {
          throw new \RuntimeException('API rejected ProcessBasedStudy delete request.');
        }

        \Drupal::messenger()->addMessage($this->t('Process-Based @study has been deleted successfully.', ['@study' => $this->preferredStudyLabel()]));
        \Drupal\std\Service\StudyVariableSearchService::invalidateCache($studyUri);
        self::backUrl();
        return;
      }
      catch (\Exception $e) {
        \Drupal::messenger()->addError($this->t('An error occurred while deleting Process-Based @study: @message', [
          '@study' => $this->preferredStudyLabel(),
          '@message' => $e->getMessage(),
        ]));
        self::backUrl();
        return;
      }
    }

    try {
      $studyUri = $this->resolveStudyUriForSubmit($form_state);
      if ($studyUri === '') {
        throw new \RuntimeException('Missing ProcessBasedStudy URI for update operation.');
      }

      // Build JSON payload for ProcessBasedStudy update
      // Include all editable metadata fields
      $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
      $processUri = Utils::canonicalizePmsrUri($processUri);

      $originalStudyId = trim((string) ($this->study->studyID ?? ''));
      $studyId = $originalStudyId;
      $submittedStudyId = trim((string) $form_state->getValue('study_id'));
      if ($submittedStudyId !== '') {
        $studyId = $submittedStudyId;
      }
      if ($studyId !== '') {
        $studyId = str_replace('_', '-', $studyId);
        if (str_starts_with($studyId, 'STD_')) {
          $studyId = 'STD-' . substr($studyId, 4);
        }
      }
      $studyData = [
        'uri' => $studyUri,
        'typeUri' => HASCO::PROCESS_BASED_STUDY,
        'hascoTypeUri' => HASCO::PROCESS_BASED_STUDY,
        'processUri' => $processUri, // Cannot be changed
        'studyID' => $studyId,
        'studyTitle' => trim($form_state->getValue('study_title')),
        'specificAims' => trim($form_state->getValue('specific_aims')),
        'significance' => trim($form_state->getValue('significance')),
        'institutionName' => trim($form_state->getValue('institution')),
        'principalInvestigator' => trim($form_state->getValue('principal_investigator')),
        'contactEmail' => trim($form_state->getValue('contact_email')),
        'startDate' => $form_state->getValue('start_date') ?: '',
        'endDate' => $form_state->getValue('end_date') ?: '',
        'hasLearningObjectives' => trim($form_state->getValue('learning_objectives')),
        'hasCriticalActions' => trim($form_state->getValue('critical_actions')),
        'hasDebriefingFocus' => trim($form_state->getValue('debriefing_focus')),
      ];

      $studyJSON = json_encode($studyData);

      // Use dedicated API to update ProcessBasedStudy
      $api = \Drupal::service('rep.api_connector');

      $updateEndpoint = '/hascoapi/api/processbasedstudy/update/' . rawurlencode($studyUri);
      // Primary strategy: hascoapi runtime in this environment binds `json`
      // reliably from query string parameters.
      $updateResponse = $api->perform_http_request(
        'POST',
        $api->getApiUrl() . $updateEndpoint . '?json=' . rawurlencode($studyJSON),
        $api->getHeader()
      );
      $updated = $api->parseObjectResponse($updateResponse, 'updateProcessBasedStudyQuery');

      if ($updated === NULL) {
        // Fallback: form-encoded json parameter.
        $updateOptions = $api->getHeader();
        $updateOptions['form_params'] = ['json' => $studyJSON];
        $updateResponse = $api->perform_http_request('POST', $api->getApiUrl() . $updateEndpoint, $updateOptions);
        $updated = $api->parseObjectResponse($updateResponse, 'updateProcessBasedStudyForm');
      }

      if ($updated === NULL) {
        // Final fallback: raw JSON body for environments with body parser support.
        $updateOptions = $api->getHeader();
        $updateOptions['headers']['Content-Type'] = 'application/json';
        $updateOptions['body'] = $studyJSON;
        $updateResponse = $api->perform_http_request('POST', $api->getApiUrl() . $updateEndpoint, $updateOptions);
        $updated = $api->parseObjectResponse($updateResponse, 'updateProcessBasedStudyBody');
      }
      
      if ($updated === NULL) {
        throw new \RuntimeException('API rejected ProcessBasedStudy update payload.');
      }

      // Verify update
      $verify = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
      if ($verify === NULL) {
        throw new \RuntimeException('ProcessBasedStudy was not persisted after update call.');
      }

      \Drupal::messenger()->addMessage($this->t('Process-Based @study has been updated successfully.', ['@study' => $this->preferredStudyLabel()]));
      
      // Invalidate study search cache for this study
      \Drupal\std\Service\StudyVariableSearchService::invalidateCache($studyUri);
      
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addError($this->t('An error occurred while updating Process-Based @study: @message', [
        '@study' => $this->preferredStudyLabel(),
        '@message' => $e->getMessage(),
      ]));
      self::backUrl();
      return;
    }
  }

  /**
   * Open the visual React canvas editor for the linked workflow process.
   */
  public function openCanvasEditor(array &$form, FormStateInterface $form_state) {
    $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
    if ($processUri === '') {
      \Drupal::messenger()->addError($this->t('No Process URI is linked to this Process-Based @study.', ['@study' => $this->preferredStudyLabel()]));
      return;
    }

    $previousUrl = \Drupal::request()->getRequestUri();
    $url = NULL;

    try {
      $routeProvider = \Drupal::service('router.route_provider');
      try {
        $routeProvider->getRouteByName('hasco_workflow.editor.page');
        $url = Url::fromRoute('hasco_workflow.editor.page', [], [
          'query' => [
            'processUri' => $processUri,
            'studyUri' => $this->studyUri,
          ],
        ]);
      }
      catch (\Exception $e) {
        $routeProvider->getRouteByName('ctt.editor');
        $url = Url::fromRoute('ctt.editor', [], [
          'query' => [
            'processUri' => $processUri,
            'studyUri' => $this->studyUri,
          ],
        ]);
      }
    }
    catch (\Exception $e) {
      $url = $this->legacyEditorUrl($processUri, $previousUrl);
    }

    $form_state->setRedirectUrl($url);
  }

  /**
   * Open the legacy Drupal-forms task-model editor.
   */
  public function openLegacyEditor(array &$form, FormStateInterface $form_state) {
    $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
    if ($processUri === '') {
      \Drupal::messenger()->addError($this->t('No Process URI is linked to this Process-Based @study.', ['@study' => $this->preferredStudyLabel()]));
      return;
    }

    $previousUrl = \Drupal::request()->getRequestUri();
    $url = $this->legacyEditorUrl($processUri, $previousUrl);
    $form_state->setRedirectUrl($url);
  }

  /**
   * Build the URL for the legacy task-model editor route (std.edit_task).
   */
  protected function legacyEditorUrl(string $processUri, ?string $backTo = NULL) {
    $api = \Drupal::service('rep.api_connector');
    $process = $api->parseObjectResponse($api->getUri($processUri), 'getUri');
    $topTaskUri = is_object($process) ? trim((string) ($process->hasTopTaskUri ?? '')) : '';
    if ($topTaskUri === '' && is_object($process) && isset($process->hasTopTask) && is_object($process->hasTopTask)) {
      $topTaskUri = trim((string) ($process->hasTopTask->uri ?? ''));
    }
    if ($topTaskUri === '') {
      \Drupal::messenger()->addWarning($this->t('Legacy task editor is unavailable because this workflow has no Top Task. Opening workflow editor instead.'));
      return Url::fromRoute('std.edit_workflow', [
        'workflowuri' => base64_encode($processUri),
      ]);
    }

    $topTask = $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri');
    $state = ($topTask && ($topTask->typeUri ?? '') === VSTOI::ABSTRACT_TASK) ? 'tasks' : 'basic';

    $options = [];
    if (is_string($backTo) && trim($backTo) !== '') {
      $options['query'] = [
        'back_to' => base64_encode($backTo),
      ];
    }

    return Url::fromRoute('std.edit_task', [
      'workflowuri' => base64_encode($processUri),
      'state' => $state,
      'taskuri' => base64_encode($topTaskUri),
    ], $options);
  }

  /**
   * Validate the linked workflow task model.
   */
  public function validateTaskModel(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
    if ($processUri === '') {
      \Drupal::messenger()->addError($this->t('Validation failed: this Process-Based @study has no linked Process URI.', ['@study' => $this->preferredStudyLabel()]));
      return;
    }

    $api = \Drupal::service('rep.api_connector');
    $process = $api->parseObjectResponse($api->getUri($processUri), 'getUri');
    if (!$process) {
      \Drupal::messenger()->addError($this->t('Validation failed: linked Process <em>@uri</em> returned no object from the knowledge graph.', ['@uri' => $processUri]));
      return;
    }

    $topTaskUri = trim((string) ($process->hasTopTaskUri ?? ''));
    if ($topTaskUri === '' && isset($process->hasTopTask) && is_object($process->hasTopTask)) {
      $topTaskUri = trim((string) ($process->hasTopTask->uri ?? ''));
    }
    if ($topTaskUri === '') {
      \Drupal::messenger()->addError($this->t('Validation failed: this workflow has no Top Task (hasTopTask), so the editor cannot render a task tree.'));
      return;
    }

    $top = $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri');
    if (!$top) {
      \Drupal::messenger()->addError($this->t('Validation failed: the Top Task <em>@uri</em> returned no object from the knowledge graph. The canvas shows the empty "Main Task" placeholder. Re-ingest the workflow metatemplate to restore it.', ['@uri' => $topTaskUri]));
      return;
    }

    $visited = [];
    $unresolved = [];
    $queue = [$topTaskUri];
    $count = 0;
    while (!empty($queue)) {
      $uri = trim((string) array_shift($queue));
      if ($uri === '' || isset($visited[$uri])) {
        continue;
      }
      $visited[$uri] = TRUE;
      $task = ($uri === $topTaskUri) ? $top : $api->parseObjectResponse($api->getUri($uri), 'getUri');
      if (!$task) {
        $unresolved[] = $uri;
        continue;
      }
      $count++;
      $subs = $task->hasSubtaskUris ?? [];
      if (is_string($subs)) {
        $subs = [$subs];
      }
      if (is_array($subs)) {
        foreach ($subs as $sub) {
          if (is_string($sub) && trim($sub) !== '') {
            $queue[] = trim($sub);
          }
        }
      }
    }

    if (!empty($unresolved)) {
      \Drupal::messenger()->addWarning($this->t('@n subtask reference(s) do not resolve in the knowledge graph (broken links): @list', [
        '@n' => count($unresolved),
        '@list' => implode(', ', array_slice($unresolved, 0, 5)) . (count($unresolved) > 5 ? ', ...' : ''),
      ]));
      return;
    }

    \Drupal::messenger()->addStatus($this->t('Task model is valid: @n task(s) reachable from a single Top Task "@label".', [
      '@n' => $count,
      '@label' => $top->label ?? $topTaskUri,
    ]));
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
