<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\file\Entity\File;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;

class EditWorkflowForm extends FormBase {

  protected $workflowUri;

  protected $process;

  protected $sourceProcess;

  public function getWorkflowUri() {
    return $this->workflowUri;
  }

  public function setWorkflowUri($uri) {
    return $this->workflowUri = $uri;
  }

  public function getWorkflow() {
    return $this->workflow;
  }

  public function setWorkflow($obj) {
    return $this->workflow = $obj;
  }

  public function getSourceWorkflow() {
    return $this->sourceWorkflow;
  }

  public function setSourceWorkflow($obj) {
    return $this->sourceWorkflow = $obj;
  }

  public function getProcess() {
    return $this->process;
  }

  public function setProcess($obj) {
    return $this->process = $obj;
  }

  public function getSourceProcess() {
    return $this->sourceProcess;
  }

  public function setSourceProcess($obj) {
    return $this->sourceProcess = $obj;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_workflow_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $workflowUri = NULL) {

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';
    // Media preview modal (PDF/image viewer).
    $form['#attached']['library'][] = 'rep/pdfjs';
    $form['#attached']['library'][] = 'rep/webdoc_modal';
    $base_url = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://' : 'http://')
      . \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];

    $encoded_workflow_uri = $workflowUri ?? \Drupal::routeMatch()->getParameter('workflowuri');
    if (!is_string($encoded_workflow_uri) || trim($encoded_workflow_uri) === '') {
      \Drupal::messenger()->addError($this->t('Missing workflow URI for edit operation.'));
      self::backUrl();
      return $form;
    }

    $uri_decode = base64_decode($encoded_workflow_uri, TRUE);
    if ($uri_decode === FALSE || $uri_decode === '') {
      $uri_decode = $encoded_workflow_uri;
    }
    $this->setWorkflowUri($uri_decode);

    $tables = new Tables;
    $languages = $tables->getLanguages();
    $informants = $tables->getInformants();

    //SELECT ONE
    $languages = ['' => $this->t('Select language please')] + $languages;
    $informants = ['' => $this->t('Select Informant please')] + $informants;

    // Get Workflow Data

    $api = \Drupal::service('rep.api_connector');
    $process = $api->parseObjectResponse($api->getUri($uri_decode),'getUri');
    if ($process == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Workflow."));
      self::backUrl();
      return;
    } else {
      $this->setProcess($process);
    }

    $form['process_header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex','align-items-center','mb-3'],
      ],
    ];

    $form['process_header']['workflow_uri'] = [
      '#type' => 'item',
      '#title' => $this->t('URI: '),
      '#markup' => Markup::create(Utils::describeAnchor((string) $this->getWorkflowUri(), (string) $this->getWorkflowUri())),
    ];

    $form['process_header']['process_actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ms-auto','btn-group'],
        'role'  => 'group',
        'aria-label' => $this->t('Workflow actions'),
      ],
    ];

    $form['process_header']['process_actions']['validate_task_model'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate Task Model'),
      '#submit' => ['::validateTaskModel'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn','btn-primary','me-2', 'check-button', 'top-icon'],
        'style' => 'max-width: 120px;'
      ],
    ];
    $form['process_header']['process_actions']['execute_task_model'] = [
      '#type' => 'submit',
      '#value' => $this->t('Execute Task Model'),
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn','btn-primary', 'me-2', 'execute-button'],
        'style' => 'max-width: 120px;'
      ],
      '#disabled' => TRUE,
    ];
    // Legacy Drupal-forms task editor (Basic / Sub-Tasks / Instruments tabs).
    // The global .btn-primary override paints the background green but leaves
    // the Bootstrap blue border; match the border to green and keep the me-2
    // gap so the adjacent Canvas button does not touch this one.
    $form['process_header']['process_actions']['edit_task'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model'),
      '#submit' => ['::openLegacyEditor'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'edit-task-button', 'edit-element-button', 'top-icon'],
        'style' => 'max-width: 120px; border-color: #006600;'
      ],
    ];
    // Visual React canvas editor. Same green edit-tile identity as the legacy
    // "Edit Task Model" button; the extra edit-task-canvas-button class is a
    // targeting hook.
    $form['process_header']['process_actions']['edit_task_canvas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model - Canvas'),
      '#submit' => ['::openCanvasEditor'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-task-button', 'edit-element-button', 'top-icon', 'edit-task-canvas-button'],
        'style' => 'max-width: 120px; border-color: #006600;'
      ],
    ];

    $form['workflow_workflowstem'] = [
      'top' => [
        '#type' => 'markup',
        '#markup' => '<div class="col border border-white">',
      ],
      'main' => [
        '#type' => 'textfield',
        '#title' => $this->t('Workflow Stem'),
        '#name' => 'workflow_workflowstem',
        '#default_value' => UTILS::fieldToAutocomplete($this->getProcess()->typeUri, $this->getProcess()->typeLabel),
        '#maxlength' => 512,
        '#id' => 'workflow_workflowstem',
        '#parents' => ['workflow_workflowstem'],
        '#attributes' => [
          'class' => ['open-tree-modal'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url' => Url::fromRoute('rep.tree_form', [
            'mode' => 'modal',
            'elementtype' => 'workflowstem',
          ], ['query' => ['field_id' => 'workflow_workflowstem']])->toString(),
          'data-field-id' => 'workflow_workflowstem',
          'data-elementtype' => 'workflowstem',
          'autocomplete' => 'off',
        ],
      ],
      'bottom' => [
        '#type' => 'markup',
        '#markup' => '</div>',
      ],
    ];
    $form['workflow_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $this->getProcess()->label,
      '#required' => true
    ];
    $form['workflow_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $languages,
      '#default_value' => $this->getProcess()->hasLanguage,
    ];
    $form['workflow_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' =>
        ($this->getProcess()->hasStatus === VSTOI::CURRENT || $this->getProcess()->hasStatus === VSTOI::DEPRECATED) ?
        $this->getProcess()->hasVersion + 1 : $this->getProcess()->hasVersion,
      '#attributes' => [
        'disabled' => 'disabled',
      ],
    ];
    $form['workflow_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->getProcess()->comment,
      '#required' => true
    ];

    // Educational (INACSL) properties — scenario-wide, semicolon-separated. Optional.
    // Defaults come from the current Process so a save preserves them (the save
    // does elementDel+elementAdd, so any field omitted here is dropped).
    $form['workflow_learning_objectives'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Learning Objectives'),
      '#default_value' => $this->getProcess()->hasLearningObjectives ?? '',
      '#description' => $this->t('Measurable learning outcomes, semicolon-separated (INACSL Criterion 3). Recommended for educational/simulation workflows.'),
    ];
    $form['workflow_critical_actions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Critical Actions'),
      '#default_value' => $this->getProcess()->hasCriticalActions ?? '',
      '#description' => $this->t('Essential performance criteria for assessment, semicolon-separated (INACSL Criterion 5/10).'),
    ];
    $form['workflow_debriefing_focus'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Debriefing Focus'),
      '#default_value' => $this->getProcess()->hasDebriefingFocus ?? '',
      '#description' => $this->t('Structured reflection topics/questions, semicolon-separated (INACSL Criterion 9).'),
    ];
    // $form['process_toptask'] = [
    //   '#type' => 'textfield',
    //   '#title' => $this->t('Top Task'),
    //   '#default_value' => (isset($this->getProcess()->hasTopTaskUri) ? UTILS::fieldToAutocomplete($this->getProcess()->hasTopTaskUri, $this->getProcess()->hasTopTask->label) : ''),
    //   // '#autocomplete_route_name' => 'std.process_task_autocomplete',
    //   '#disabled' => TRUE
    // ];
    $form['process_toptask_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display: flex; align-items: center; gap: 1em;',
        'class' => ['process-toptask-wrapper'],
      ],
    ];

    // Render the Top Task textfield inside the wrapper
    $form['process_toptask_wrapper']['process_toptask'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Top Task'),
      '#default_value' => isset($this->getProcess()->hasTopTaskUri)
        ? UTILS::fieldToAutocomplete(
            $this->getProcess()->hasTopTaskUri,
            $this->getProcess()->hasTopTask->label
          )
        : '',
      '#disabled' => TRUE,
      '#attributes'  => [
        // make it take up remaining space
        'style' => 'flex: 1 1 0;',
      ],
    ];

    $topTaskType = $api->parseObjectResponse($api->getUri($this->getProcess()->hasTopTaskUri),'getUri');
    $form['process_toptask_wrapper']['process_toptask_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Top Task Type'),
      '#default_value' => isset($topTaskType->uri)
        ? UTILS::fieldToAutocomplete($topTaskType->typeUri, $topTaskType->typeLabel)
        : '',
      '#disabled' => TRUE,
      '#attributes'  => [
        // make it take up remaining space
        'style' => 'flex: 1 1 0;',
      ],
    ];

    $form['process_toptask_wrapper']['process_toptask']['#size'] = 80;


    // **** IMAGE ****
    // Retrieve the current image value.
    // Retrieve the current process and its image.
    $process = $this->getProcess();
    $process_uri = Utils::namespaceUri($this->getWorkflowUri());
    $workflow_image = $process->hasImageUri ?? '';

    // Determine if the existing web document is a URL or a file.
    $image_type = '';
    if (!empty($workflow_image) && stripos(trim($workflow_image), 'http') === 0) {
      $image_type = 'url';
    }
    elseif (!empty($workflow_image)) {
      $image_type = 'upload';
    }

    $modUri = '';
    if (!empty($process_uri)) {
      // Example of extracting part of the URI. Adjust or remove if not needed.
      $parts = explode(':/', $process_uri);
      if (count($parts) > 1) {
        $modUri = $parts[1];
      }
    }

    // Image Type selector (URL or Upload).
    $form['process_information']['workflow_image_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Type'),
      '#options' => [
        '' => $this->t('Select Image Type'),
        'url' => $this->t('URL'),
        'upload' => $this->t('Upload'),
      ],
      '#default_value' => $image_type,
    ];

    // Textfield for URL mode (only visible when type = 'url').
    $form['process_information']['workflow_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#default_value' => ($image_type === 'url') ? $workflow_image : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="workflow_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['process_information']['workflow_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="workflow_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_image_fid = NULL;
    if ($image_type === 'upload' && !empty($workflow_image)) {
      // Build the expected file URI in the private filesystem.
      $desired_uri = 'private://resources/' . $modUri . '/image/' . $workflow_image;
      $files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $desired_uri]);
      $file = reset($files);
      if ($file) {
        $existing_image_fid = $file->id();
      }
    }

    // 5. Managed file element for uploading a new document.
    $form['process_information']['workflow_image_upload_wrapper']['workflow_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'], // Allowed file extensions.
        'file_validate_size' => [2097152], // Maximum file size (in bytes).
      ],
      '#default_value' => $existing_image_fid ? [$existing_image_fid] : NULL,
      // Description in red: allowed file types and a warning that choosing a new image will remove the previous one.
      '#description' => Markup::create('<span style="color: red;">Allowed file types: png, jpg, jpeg. Selecting a new image will remove the previous one.</span>'),
    ];

    // Image preview (thumbnail -> modal).
    $image_preview_url = '';
    if ($image_type === 'url' && !empty($workflow_image)) {
      $image_preview_url = $workflow_image;
    }
    elseif ($existing_image_fid) {
      $existing_image_file = File::load($existing_image_fid);
      if ($existing_image_file) {
        $image_preview_url = \Drupal::service('file_url_generator')->generateAbsoluteString($existing_image_file->getFileUri());
      }
    }

    if (!empty($image_preview_url)) {
      $escaped_image_url = Html::escape($image_preview_url);
      $form['process_information']['workflow_image_preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Image Preview'),
        '#markup' => Markup::create(
          '<button type="button" class="btn btn-link p-0 view-media-button" data-view-url="' . $escaped_image_url . '" aria-label="' . Html::escape((string) $this->t('Preview image')) . '">' .
          '<img src="' . $escaped_image_url . '" class="img-thumbnail" style="max-width: 120px; height: auto;" />' .
          '</button>'
        ),
        '#states' => [
          'visible' => [
            ':input[name="workflow_image_type"]' => [
              ['value' => 'url'],
              ['value' => 'upload'],
            ],
          ],
        ],
      ];
    }

    // **** WEBDOCUMENT ****
    // Retrieve the current web document value.
    $workflow_webdocument = $process->hasWebDocument ?? '';

    // Determine if the existing web document is a URL or a file.
    $webdocument_type = '';
    if (!empty($workflow_webdocument) && stripos(trim($workflow_webdocument), 'http') === 0) {
      $webdocument_type = 'url';
    }
    elseif (!empty($workflow_webdocument)) {
      $webdocument_type = 'upload';
    }

    // Web Document Type selector (URL or Upload).
    $form['process_information']['workflow_webdocument_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Web Document Type'),
      '#options' => [
        '' => $this->t('Select Document Type'),
        'url' => $this->t('URL'),
        'upload' => $this->t('Upload'),
      ],
      '#default_value' => $webdocument_type,
    ];

    // Textfield for URL mode (only visible when type = 'url').
    $form['process_information']['workflow_webdocument_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web Document'),
      '#default_value' => ($webdocument_type === 'url') ? $workflow_webdocument : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="workflow_webdocument_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['process_information']['workflow_webdocument_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="workflow_webdocument_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_fid = NULL;
    if ($webdocument_type === 'upload' && !empty($workflow_webdocument)) {
      // Build the expected file URI in the private filesystem.
      $desired_uri = 'private://resources/' . $modUri . '/webdoc/' . $workflow_webdocument;
      $files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $desired_uri]);
      $file = reset($files);

      // Legacy fallback: older versions of this form stored web documents
      // under the image folder.
      if (!$file) {
        $legacy_uri = 'private://resources/' . $modUri . '/image/' . $workflow_webdocument;
        $legacy_files = \Drupal::entityTypeManager()
          ->getStorage('file')
          ->loadByProperties(['uri' => $legacy_uri]);
        $file = reset($legacy_files);
      }

      if ($file) {
        $existing_fid = $file->id();
      }
    }

    // 5. Managed file element for uploading a new document.
    $form['process_information']['workflow_webdocument_upload_wrapper']['workflow_webdocument_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Document'),
      '#upload_location' => 'private://resources/' . $modUri . '/webdoc',
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf doc docx txt xls xlsx'],
        'file_validate_size' => [2097152], // Maximum file size (in bytes).
      ],
      '#default_value' => $existing_fid ? [$existing_fid] : NULL,
      // Description in red: allowed file types and a warning that choosing a new image will remove the previous one.
      '#description' => Markup::create('<span style="color: red;">Allowed file types: pdf, doc, docx, txt, xls, xlsx. Selecting a new document will remove the previous one.</span>'),
    ];

    // Web document preview (thumbnail/button -> modal).
    $webdoc_preview_url = '';
    if ($webdocument_type === 'url' && !empty($workflow_webdocument)) {
      $webdoc_preview_url = $workflow_webdocument;
    }
    elseif ($existing_fid) {
      $existing_doc_file = File::load($existing_fid);
      if ($existing_doc_file) {
        $webdoc_preview_url = \Drupal::service('file_url_generator')->generateAbsoluteString($existing_doc_file->getFileUri());
      }
    }

    if (!empty($webdoc_preview_url)) {
      $escaped_doc_url = Html::escape($webdoc_preview_url);
      $extension = '';
      $parsed_path = parse_url($webdoc_preview_url, PHP_URL_PATH);
      if (is_string($parsed_path)) {
        $extension = strtolower(pathinfo($parsed_path, PATHINFO_EXTENSION));
      }

      $thumb_markup = '';
      if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], TRUE)) {
        $thumb_markup = '<img src="' . $escaped_doc_url . '" class="img-thumbnail" style="max-width: 120px; height: auto;" />';
      }
      elseif ($extension === 'pdf') {
        // PDF thumbnail (first page) – click opens the full viewer modal.
        $thumb_markup =
          '<div class="border rounded" style="width: 120px; height: 160px; overflow: hidden;">' .
          '<embed src="' . $escaped_doc_url . '#page=1&zoom=70" type="application/pdf" style="width: 120px; height: 160px; pointer-events: none;" />' .
          '</div>';
      }
      else {
        $label = $parsed_path ? basename($parsed_path) : (string) $this->t('View document');
        $thumb_markup = '<span class="small">' . Html::escape($label) . '</span>';
      }

      $form['process_information']['workflow_webdocument_preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Web Document Preview'),
        '#markup' => Markup::create(
          '<button type="button" class="btn btn-link p-0 view-media-button" data-view-url="' . $escaped_doc_url . '" aria-label="' . Html::escape((string) $this->t('Preview document')) . '">' .
          $thumb_markup .
          '</button>'
        ),
        '#states' => [
          'visible' => [
            ':input[name="workflow_webdocument_type"]' => [
              ['value' => 'url'],
              ['value' => 'upload'],
            ],
          ],
        ],
      ];
    }

    if ($this->getProcess()->hasReviewNote !== NULL && $this->getProcess()->hasSatus !== null) {
      $form['process_hasreviewnote'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Review Notes'),
        '#default_value' => $this->getProcess()->hasReviewNote,
        '#attributes' => [
          'disabled' => 'disabled',
        ]
      ];
      $form['process_haseditoremail'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Reviewer Email'),
        '#default_value' => $this->getProcess()->hasEditorEmail,
        '#attributes' => [
          'disabled' => 'disabled',
        ],
      ];
    }
    $form['update_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#name' => 'save',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'save-button'],
      ],
    ];
    $form['cancel_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'back',
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'cancel-button'],
      ],
    ];
    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br>'),
    ];


    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    $workflow_stem = (string) $form_state->getValue('workflow_workflowstem');
    if ($workflow_stem !== '') {
      $form_state->setValue('workflow_workflowstem', Utils::trimPreserveBracket($workflow_stem, 128));
    }

    if ($button_name !== 'back') {
      if(strlen($form_state->getValue('workflow_workflowstem')) < 1) {
        $form_state->setErrorByName('workflow_workflowstem', $this->t('Please enter a valid Workflow stem'));
      }
    } else {
      self::backUrl();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    try{

      $uid = \Drupal::currentUser()->id();
      $useremail = \Drupal::currentUser()->getEmail();

      // Determine the chosen document type.
      $doc_type = $form_state->getValue('workflow_webdocument_type');
      $workflow_webdocument = $this->getProcess()->hasWebDocument;

      // Compute module-ish URI segment used for private file storage.
      $process_uri = Utils::namespaceUri($this->getWorkflowUri());
      $modUri = '';
      if (!empty($process_uri)) {
        $parts = explode(':/', $process_uri);
        if (count($parts) > 1) {
          $modUri = $parts[1];
        }
      }

      // If user selected URL, use the textfield value.
      if ($doc_type === 'url') {
        $workflow_webdocument = $form_state->getValue('workflow_webdocument_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($doc_type === 'upload') {
        $fids = $form_state->getValue('workflow_webdocument_upload') ?: [];
        $submitted_fid = !empty($fids) ? (int) reset($fids) : NULL;

        $existing_fid = $this->resolvePrivateResourceFid($modUri, 'webdoc', (string) $this->getProcess()->hasWebDocument, TRUE);

        // Only treat as "new upload" if user actually selected a different file.
        if ($submitted_fid && (!$existing_fid || $submitted_fid !== (int) $existing_fid)) {
          $file = File::load($submitted_fid);
          if ($file) {
            $file->setPermanent();
            $file->save();
            \Drupal::service('file.usage')->add($file, 'sir', 'workflow', 1);
            $workflow_webdocument = $file->getFilename();
          }
        }
      }

      // Determine the chosen image type.
      $image_type = $form_state->getValue('workflow_image_type');
      $workflow_image = $this->getProcess()->hasImageUri;

      // If user selected URL, use the textfield value.
      if ($image_type === 'url') {
        $workflow_image = $form_state->getValue('workflow_image_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($image_type === 'upload') {
        $fids = $form_state->getValue('workflow_image_upload') ?: [];
        $submitted_fid = !empty($fids) ? (int) reset($fids) : NULL;

        $existing_fid = $this->resolvePrivateResourceFid($modUri, 'image', (string) $this->getProcess()->hasImageUri, FALSE);

        if ($submitted_fid && (!$existing_fid || $submitted_fid !== (int) $existing_fid)) {
          $file = File::load($submitted_fid);
          if ($file) {
            $file->setPermanent();
            $file->save();
            \Drupal::service('file.usage')->add($file, 'sir', 'workflow', 1);
            $workflow_image = $file->getFilename();
          }
        }
      }

      // GET THE PROCESS STEM URI
      $rawresponse = $api->getUri(Utils::uriFromAutocomplete($form_state->getValue('process_stem')));
      $obj = json_decode($rawresponse);
      $result = $obj->body;

      // CHECK if Status is CURRENT OR DEPRECATED FOR NEW CREATION
      if ($this->getProcess()->hasStatus === VSTOI::CURRENT || $this->getProcess()->hasStatus === VSTOI::DEPRECATED) {

        $newProcessUri = Utils::uriGen('workflow');
        $processJSON = '{"uri":"' . $newProcessUri . '",'
          . '"typeUri":"' .Utils::uriFromAutocomplete($form_state->getValue('workflow_workflowstem')) . '",'
          . '"hascoTypeUri":"' . VSTOI::WORKFLOW . '",'
          . '"hasStatus":"' . VSTOI::DRAFT . '",'
          . '"label":"' . $form_state->getValue('workflow_name') . '",'
          . '"hasLanguage":"' . $form_state->getValue('workflow_language') . '",'
          . '"hasVersion":"' . $form_state->getValue('workflow_version') . '",'
          . '"comment":"' . $form_state->getValue('workflow_description') . '",'
          . '"hasWebDocument":"",'
          . '"hasImageUri":"",'
          . '"hasTopTaskUri":"'. $this->getProcess()->hasTopTaskUri .'",'
          . '"hasSIRManagerEmail":"' . $useremail .'",'
          . '"hasLearningObjectives":' . json_encode((string) $form_state->getValue('workflow_learning_objectives')) . ','
          . '"hasCriticalActions":' . json_encode((string) $form_state->getValue('workflow_critical_actions')) . ','
          . '"hasDebriefingFocus":' . json_encode((string) $form_state->getValue('workflow_debriefing_focus')) . ','
          . '"hasReviewNote":"'.($this->getProcess()->hasSatus !== null ? $this->getProcess()->hasReviewNote : '').'",'
          . '"hasEditorEmail":"'.($this->getProcess()->hasSatus !== null ? $this->getProcess()->hasEditorEmail : '').'"}';

        $message = $api->elementAdd('workflow',$processJSON);

        if ($message != null)
          \Drupal::messenger()->addMessage(t("New Version Workflow has been created successfully."));

      } else {

        $processJSON = '{"uri":"'.$this->getWorkflowUri().'",'.
          '"typeUri":"'.Utils::uriFromAutocomplete($form_state->getValue('workflow_workflowstem')).'",'.
          '"hascoTypeUri":"'.VSTOI::WORKFLOW.'",'.
          '"hasStatus":"'.VSTOI::DRAFT.'",'.
          '"hasSIRManagerEmail":"'.$useremail.'",'.
          '"hasLearningObjectives":'.json_encode((string) $form_state->getValue('workflow_learning_objectives')).','.
          '"hasCriticalActions":'.json_encode((string) $form_state->getValue('workflow_critical_actions')).','.
          '"hasDebriefingFocus":'.json_encode((string) $form_state->getValue('workflow_debriefing_focus')).','.
          '"hasLanguage":"' . $form_state->getValue('workflow_language') . '",'.
          '"label":"'.$form_state->getValue('workflow_name').'",'.
          '"hasVersion":"'.$form_state->getValue('workflow_version').'",'.
          '"comment":"' . $form_state->getValue('workflow_description') . '",'.
          '"hasTopTaskUri":"'. $this->getProcess()->hasTopTaskUri .'",'.
          '"hasWebDocument":"' . $workflow_webdocument . '",' .
          '"hasImageUri":"' . $workflow_image . '",' .
          '"hasReviewNote":"'.$this->getProcess()->hasReviewNote.'",'.
          '"hasEditorEmail":"'.$this->getProcess()->hasEditorEmail.'"}';

        // dpm($processJSON, 'Workflow JSON');return false;
        // UPDATE BY DELETING AND CREATING
        $api->elementDel('workflow',$this->getWorkflowUri());
        $message = $api->elementAdd('workflow',$processJSON);

        if ($message != null)
          \Drupal::messenger()->addMessage(t("Workflow has been updated successfully."));
      }

      self::backUrl();
      return;

    }catch(\Exception $e){
      \Drupal::messenger()->addError(t("An error occurred while updating the Workflow: ".$e->getMessage()));
      self::backUrl();
      return;
    }
  }

  public function retrieveProcess($workflowUri) {
    $api = \Drupal::service('rep.api_connector');
    $rawresponse = $api->getUri($workflowUri);
    $obj = json_decode($rawresponse);
    if ($obj->isSuccessful) {
      return $obj->body;
    }
    return NULL;
  }

  function backUrl($back_url = NULL) {
    if ($back_url) {
      $response = new RedirectResponse($back_url);
      $response->send();
      return;
    } else {
      $uid = \Drupal::currentUser()->id();
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_workflow');

      if ($previousUrl && strpos($previousUrl, '/load-more-data') !== false) {
        parse_str(parse_url($previousUrl, PHP_URL_QUERY), $params);
        $page = isset($params['page']) ? $params['page'] : 1;
        $element_type = isset($params['element_type']) ? $params['element_type'] : 'workflow';
        $pagesize = 9;

        $previousUrl = Url::fromRoute('std.select_process', [
          'elementtype' => $element_type,
          'page' => $page,
          'pagesize' => $pagesize,
        ])->toString();
      }

      if ($previousUrl) {
        $response = new RedirectResponse($previousUrl);
        $response->send();
        return;
      } else {

        $default_url = Url::fromRoute('std.select_process', [
          'elementtype' => 'workflow',
          'page' => 1,
          'pagesize' => 9,
        ])->toString();
        $response = new RedirectResponse($default_url);
        $response->send();
      }
    }
  }

  /**
   * Open the visual React canvas editor for the workflow's task model.
   *
   * Note: this intentionally does NOT rewrite the task graph. A previous
   * implementation re-typed every task-with-children to AbstractTask via
   * delete+add, which destroyed the CTT task types and corrupted the tree
   * (the Top Task became unresolvable and the canvas rendered empty).
   */
  public function openCanvasEditor(array &$form, FormStateInterface $form_state) {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    $url = NULL;

    // Prefer opening the React CTT editor inside Drupal when available.
    try {
      $routeProvider = \Drupal::service('router.route_provider');
      try {
        $routeProvider->getRouteByName('hasco_workflow.editor.page');
        $url = Url::fromRoute('hasco_workflow.editor.page', [], [
          'query' => ['processUri' => $this->getWorkflowUri()],
        ]);
      }
      catch (\Exception $e) {
        $routeProvider->getRouteByName('ctt.editor');
        $url = Url::fromRoute('ctt.editor', [], [
          'query' => ['processUri' => $this->getWorkflowUri()],
        ]);
      }
    }
    catch (\Exception $e) {
      // Fallback to the legacy task-model editor when the canvas route is absent.
      $url = $this->legacyEditorUrl();
    }

    Utils::trackingStoreUrls($uid, $previousUrl, $url->toString());
    $form_state->setRedirectUrl($url);
  }

  /**
   * Open the legacy Drupal-forms task-model editor (Basic / Sub-Tasks tabs).
   */
  public function openLegacyEditor(array &$form, FormStateInterface $form_state) {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    $url = $this->legacyEditorUrl();
    Utils::trackingStoreUrls($uid, $previousUrl, $url->toString());
    $form_state->setRedirectUrl($url);
  }

  /**
   * Build the URL for the legacy task-model editor route (std.edit_task).
   */
  protected function legacyEditorUrl() {
    $api = \Drupal::service('rep.api_connector');
    $topTaskUri = (string) ($this->getProcess()->hasTopTaskUri ?? '');
    $topTask = $topTaskUri !== '' ? $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri') : NULL;
    $state = ($topTask && ($topTask->typeUri ?? '') === VSTOI::ABSTRACT_TASK) ? 'tasks' : 'basic';
    return Url::fromRoute('std.edit_task', [
      'workflowuri' => base64_encode($this->getWorkflowUri()),
      'state' => $state,
      'taskuri' => base64_encode($topTaskUri),
    ]);
  }

  /**
   * Validate the workflow's task model.
   *
   * Checks that there is a single, resolvable Top Task and that its task tree
   * is fully reachable. Surfaces the exact reason the canvas would be empty
   * (e.g. Top Task not in the knowledge graph) instead of failing silently.
   */
  public function validateTaskModel(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    $api = \Drupal::service('rep.api_connector');
    $process = $this->getProcess();
    $topTaskUri = is_object($process) ? trim((string) ($process->hasTopTaskUri ?? '')) : '';

    if ($topTaskUri === '') {
      \Drupal::messenger()->addError($this->t('Validation failed: this workflow has no Top Task (hasTopTask), so the editor cannot render a task tree.'));
      return;
    }

    $top = $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri');
    if (!$top) {
      \Drupal::messenger()->addError($this->t('Validation failed: the Top Task <em>@uri</em> returned no object from the knowledge graph. The canvas shows the empty "Main Task" placeholder. Re-ingest the workflow metatemplate to restore it.', ['@uri' => $topTaskUri]));
      return;
    }

    // Breadth-first walk of the task tree from the Top Task.
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
        '@list' => implode(', ', array_slice($unresolved, 0, 5)) . (count($unresolved) > 5 ? ', …' : ''),
      ]));
      return;
    }

    \Drupal::messenger()->addStatus($this->t('Task model is valid: @n task(s) reachable from a single Top Task "@label".', [
      '@n' => $count,
      '@label' => $top->label ?? $topTaskUri,
    ]));
  }

  /**
   * Resolve an existing file entity id (fid) for a private resource file.
   */
  protected function resolvePrivateResourceFid(string $modUri, string $subdir, string $filename, bool $legacyFallbackToImage = FALSE): ?int {
    if ($modUri === '' || $filename === '') {
      return NULL;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('file');
    $desired_uri = 'private://resources/' . $modUri . '/' . $subdir . '/' . $filename;
    $files = $storage->loadByProperties(['uri' => $desired_uri]);
    $file = reset($files);

    if (!$file && $legacyFallbackToImage) {
      $legacy_uri = 'private://resources/' . $modUri . '/image/' . $filename;
      $legacy_files = $storage->loadByProperties(['uri' => $legacy_uri]);
      $file = reset($legacy_files);
    }

    if ($file && method_exists($file, 'id')) {
      return (int) $file->id();
    }

    return NULL;
  }

}



