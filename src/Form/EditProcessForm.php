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

class EditProcessForm extends FormBase {

  protected $processUri;

  protected $process;

  protected $sourceProcess;

  public function getProcessUri() {
    return $this->processUri;
  }

  public function setProcessUri($uri) {
    return $this->processUri = $uri;
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
    return 'edit_process_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $processuri = NULL) {

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';


    $uri=$processuri;
    $uri_decode=base64_decode($uri);
    $this->setProcessUri($uri_decode);

    $tables = new Tables;
    $languages = $tables->getLanguages();
    $informants = $tables->getInformants();

    //SELECT ONE
    $languages = ['' => $this->t('Select language please')] + $languages;
    $informants = ['' => $this->t('Select Informant please')] + $informants;

    // Get Process Data

    $api = \Drupal::service('rep.api_connector');
    $uri_decode=base64_decode($processuri);
    $process = $api->parseObjectResponse($api->getUri($uri_decode),'getUri');
    if ($process == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Process."));
      self::backUrl();
      return;
    } else {
      $this->setProcess($process);
    }

    $form['process_uri'] = [
      '#type' => 'item',
      '#title' => $this->t('URI: '),
      '#markup' => t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($this->getProcessUri()).'">'.$this->getProcessUri().'</a>'),
    ];
    $form['process_processstem'] = [
      'top' => [
        '#type' => 'markup',
        '#markup' => '<div class="col border border-white">',
      ],
      'main' => [
        '#type' => 'textfield',
        '#title' => $this->t('Process Stem'),
        '#name' => 'process_processstem',
        '#default_value' => UTILS::fieldToAutocomplete($this->getProcess()->typeUri, $this->getProcess()->typeLabel),
        '#id' => 'process_processstem',
        '#parents' => ['process_processstem'],
        '#attributes' => [
          'class' => ['open-tree-modal'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url' => Url::fromRoute('rep.tree_form', [
            'mode' => 'modal',
            'elementtype' => 'processstem',
          ], ['query' => ['field_id' => 'process_processstem']])->toString(),
          'data-field-id' => 'process_processstem',
          'data-elementtype' => 'processstem',
          'autocomplete' => 'off',
        ],
      ],
      'bottom' => [
        '#type' => 'markup',
        '#markup' => '</div>',
      ],
    ];
    $form['process_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $this->getProcess()->label,
      '#required' => true
    ];
    $form['process_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $languages,
      '#default_value' => $this->getProcess()->hasLanguage,
    ];
    $form['process_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' =>
        ($this->getProcess()->hasStatus === VSTOI::CURRENT || $this->getProcess()->hasStatus === VSTOI::DEPRECATED) ?
        $this->getProcess()->hasVersion + 1 : $this->getProcess()->hasVersion,
      '#attributes' => [
        'disabled' => 'disabled',
      ],
    ];
    $form['process_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->getProcess()->comment,
      '#required' => true
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

    // Add the Edit Task button next to the Top Task element
    $form['process_toptask_wrapper']['edit_task'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model'),
      '#submit' => ['::setBackUrl'],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-task-button', 'mt-2'],
        'style' => 'margin-left: 1em;',
      ],
    ];

    $form['process_toptask_wrapper']['process_toptask']['#size'] = 80;


    // **** IMAGE ****
    // Retrieve the current image value.
    // Retrieve the current process and its image.
    $process = $this->getProcess();
    $process_uri = Utils::namespaceUri($this->getProcessUri());
    $process_image = $process->hasImageUri ?? '';

    // Determine if the existing web document is a URL or a file.
    $image_type = '';
    if (!empty($process_image) && stripos(trim($process_image), 'http') === 0) {
      $image_type = 'url';
    }
    elseif (!empty($process_image)) {
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
    $form['process_information']['process_image_type'] = [
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
    $form['process_information']['process_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#default_value' => ($image_type === 'url') ? $process_image : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="process_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['process_information']['process_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="process_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_image_fid = NULL;
    if ($image_type === 'upload' && !empty($process_image)) {
      // Build the expected file URI in the private filesystem.
      $desired_uri = 'private://resources/' . $modUri . '/image/' . $process_image;
      $files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $desired_uri]);
      $file = reset($files);
      if ($file) {
        $existing_image_fid = $file->id();
      }
    }

    // 5. Managed file element for uploading a new document.
    $form['process_information']['process_image_upload_wrapper']['process_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'], // Allowed file extensions.
        'file_validate_size' => [2097152], // Maximum file size (in bytes).
      ],
      // Description in red: allowed file types and a warning that choosing a new image will remove the previous one.
      '#description' => Markup::create('<span style="color: red;">Allowed file types: png, jpg, jpeg. Selecting a new image will remove the previous one.</span>'),
    ];

    // **** WEBDOCUMENT ****
    // Retrieve the current web document value.
    $process_webdocument = $process->hasWebDocument ?? '';

    // Determine if the existing web document is a URL or a file.
    $webdocument_type = '';
    if (!empty($process_webdocument) && stripos(trim($process_webdocument), 'http') === 0) {
      $webdocument_type = 'url';
    }
    elseif (!empty($process_webdocument)) {
      $webdocument_type = 'upload';
    }

    // Web Document Type selector (URL or Upload).
    $form['process_information']['process_webdocument_type'] = [
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
    $form['process_information']['process_webdocument_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web Document'),
      '#default_value' => ($webdocument_type === 'url') ? $process_webdocument : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="process_webdocument_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['process_information']['process_webdocument_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="process_webdocument_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_fid = NULL;
    if ($webdocument_type === 'upload' && !empty($process_webdocument)) {
      // Build the expected file URI in the private filesystem.
      $desired_uri = 'private://resources/' . $modUri . '/webdoc/' . $process_webdocument;
      $files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $desired_uri]);
      $file = reset($files);
      if ($file) {
        $existing_fid = $file->id();
      }
    }

    // 5. Managed file element for uploading a new document.
    $form['process_information']['process_webdocument_upload_wrapper']['process_webdocument_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf doc docx txt xls xlsx'],
        'file_validate_size' => [2097152], // Maximum file size (in bytes).
      ],
      // Description in red: allowed file types and a warning that choosing a new image will remove the previous one.
      '#description' => Markup::create('<span style="color: red;">Allowed file types: pdf, doc, docx, txt, xls, xlsx. Selecting a new document will remove the previous one.</span>'),
    ];

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

    if ($button_name !== 'back') {
      if(strlen($form_state->getValue('process_processstem')) < 1) {
        $form_state->setErrorByName('process_processstem', $this->t('Please enter a valid Process stem'));
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
      $doc_type = $form_state->getValue('process_webdocument_type');
      $process_webdocument = $this->getProcess()->hasWebDocument;

      // If user selected URL, use the textfield value.
      if ($doc_type === 'url') {
        $process_webdocument = $form_state->getValue('process_webdocument_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($doc_type === 'upload') {
        // Get the file IDs from the managed_file element.
        $fids = $form_state->getValue('process_webdocument_upload');
        if (!empty($fids)) {
          // Load the first file (file ID is returned, e.g. "374").
          $file = File::load(reset($fids));
          if ($file) {
            // Mark the file as permanent and save it.
            $file->setPermanent();
            $file->save();
            // Optionally register file usage to prevent cleanup.
            \Drupal::service('file.usage')->add($file, 'sir', 'process', 1);
            // Now get the filename from the file entity.
            $process_webdocument = $file->getFilename();
          }
        }
      }

      // Determine the chosen image type.
      $image_type = $form_state->getValue('process_image_type');
      $process_image = $this->getProcess()->hasImageUri;

      // If user selected URL, use the textfield value.
      if ($image_type === 'url') {
        $process_image = $form_state->getValue('process_image_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($image_type === 'upload') {
        // Get the file IDs from the managed_file element.
        $fids = $form_state->getValue('process_image_upload');
        if (!empty($fids)) {
          // Load the first file (file ID is returned, e.g. "374").
          $file = File::load(reset($fids));
          if ($file) {
            // Mark the file as permanent and save it.
            $file->setPermanent();
            $file->save();
            // Optionally register file usage to prevent cleanup.
            \Drupal::service('file.usage')->add($file, 'sir', 'process', 1);
            // Now get the filename from the file entity.
            $process_image = $file->getFilename();
          }
        }
      }

      // GET THE PROCESS STEM URI
      $rawresponse = $api->getUri(Utils::uriFromAutocomplete($form_state->getValue('process_stem')));
      $obj = json_decode($rawresponse);
      $result = $obj->body;

      // CHECK if Status is CURRENT OR DEPRECATED FOR NEW CREATION
      if ($this->getProcess()->hasStatus === VSTOI::CURRENT || $this->getProcess()->hasStatus === VSTOI::DEPRECATED) {

        $newProcessUri = Utils::uriGen('process');
        $processJSON = '{"uri":"' . $newProcessUri . '",'
          . '"typeUri":"' .Utils::uriFromAutocomplete($form_state->getValue('process_processstem')) . '",'
          . '"hascoTypeUri":"' . VSTOI::PROCESS . '",'
          . '"hasStatus":"' . VSTOI::DRAFT . '",'
          . '"label":"' . $form_state->getValue('process_name') . '",'
          . '"hasLanguage":"' . $form_state->getValue('process_language') . '",'
          . '"hasVersion":"' . $form_state->getValue('process_version') . '",'
          . '"comment":"' . $form_state->getValue('process_description') . '",'
          . '"hasWebDocument":"",'
          . '"hasImageUri":"",'
          . '"hasTopTaskUri":"'. $this->getProcess()->hasTopTaskUri .'",'
          . '"hasSIRManagerEmail":"' . $useremail .'",'
          . '"hasReviewNote":"'.($this->getProcess()->hasSatus !== null ? $this->getProcess()->hasReviewNote : '').'",'
          . '"hasEditorEmail":"'.($this->getProcess()->hasSatus !== null ? $this->getProcess()->hasEditorEmail : '').'"}';

        $message = $api->elementAdd('process',$processJSON);

        if ($message != null)
          \Drupal::messenger()->addMessage(t("New Version Process has been created successfully."));

      } else {

        // UPLOAD IMAGE TO API
        if ($image_type === 'upload' && $process_image !== $this->getProcess()->hasImageUri) {
          $fids = $form_state->getValue('process_image_upload');
          $msg = $api->parseObjectResponse($api->uploadFile($this->getProcessUri(), reset($fids)), 'uploadFile');
          if ($msg == NULL) {
            \Drupal::messenger()->addError(t("The Uploaded Image FAILED to be submited to API."));
          }
        }

        // UPLOAD DOCUMENT TO API
        if ($doc_type === 'upload' && $process_webdocument !== $this->getProcess()->hasWebDocument) {
          $fids = $form_state->getValue('process_webdocument_upload');
          $msg = $api->parseObjectResponse($api->uploadFile($this->getProcessUri(), reset($fids)), 'uploadFile');
          if ($msg == NULL) {
            \Drupal::messenger()->addError(t("The Uploaded WebDocument FAILED to be submited to API."));
          }
        }

        $processJSON = '{"uri":"'.$this->getProcessUri().'",'.
          '"typeUri":"'.Utils::uriFromAutocomplete($form_state->getValue('process_processstem')).'",'.
          '"hascoTypeUri":"'.VSTOI::PROCESS.'",'.
          '"hasStatus":"'.VSTOI::DRAFT.'",'.
          '"hasSIRManagerEmail":"'.$useremail.'",'.
          '"hasLanguage":"' . $form_state->getValue('process_language') . '",'.
          '"label":"'.$form_state->getValue('process_name').'",'.
          '"hasVersion":"'.$form_state->getValue('process_version').'",'.
          '"comment":"' . $form_state->getValue('process_description') . '",'.
          '"hasTopTaskUri":"'. $this->getProcess()->hasTopTaskUri .'",'.
          '"hasWebDocument":"' . $process_webdocument . '",' .
          '"hasImageUri":"' . $process_image . '",' .
          '"hasReviewNote":"'.$this->getProcess()->hasReviewNote.'",'.
          '"hasEditorEmail":"'.$this->getProcess()->hasEditorEmail.'"}';

        // dpm($processJSON, 'Process JSON');return false;
        // UPDATE BY DELETING AND CREATING
        $api->elementDel('process',$this->getProcessUri());
        $message = $api->elementAdd('process',$processJSON);

        if ($message != null)
          \Drupal::messenger()->addMessage(t("Process has been updated successfully."));
      }

      self::backUrl();
      return;

    }catch(\Exception $e){
      \Drupal::messenger()->addError(t("An error occurred while updating the Process: ".$e->getMessage()));
      self::backUrl();
      return;
    }
  }

  public function retrieveProcess($processUri) {
    $api = \Drupal::service('rep.api_connector');
    $rawresponse = $api->getUri($processUri);
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
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_process');

      if ($previousUrl && strpos($previousUrl, '/load-more-data') !== false) {
        parse_str(parse_url($previousUrl, PHP_URL_QUERY), $params);
        $page = isset($params['page']) ? $params['page'] : 1;
        $element_type = isset($params['element_type']) ? $params['element_type'] : 'process';
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
          'elementtype' => 'process',
          'page' => 1,
          'pagesize' => 9,
        ])->toString();
        $response = new RedirectResponse($default_url);
        $response->send();
      }
    }
  }

  /**
   * Submit handler for editing an element in card view.
   */
  public function setBackUrl(array &$form, FormStateInterface $form_state) {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();

    $api = \Drupal::service('rep.api_connector');
    $topTask = $api->parseObjectResponse($api->getUri($this->getProcess()->hasTopTaskUri),'getUri');

    $url = Url::fromRoute('std.edit_task', [
        'processuri' => base64_encode($this->getProcessUri()),
        'state' => $topTask->typeUri === VSTOI::ABSTRACT_TASK ? 'tasks' : 'basic',
        'taskuri' => base64_encode($this->getProcess()->hasTopTaskUri),
    ]);

    // Definir redirecionamento explícito
    Utils::trackingStoreUrls($uid,$previousUrl,$url->toString());
    $form_state->setRedirectUrl($url);
  }

}
