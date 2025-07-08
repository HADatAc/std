<?php

namespace Drupal\std\Form;

use Abraham\TwitterOAuth\Util;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\file\Entity\File;
use Drupal\Core\Link;
use Drupal\Component\Serialization\Json;

class AddProcessForm extends FormBase {

  protected $processUri;

  public function setProcessUri() {
    $this->processUri = Utils::uriGen('process');
  }

  public function getProcessUri() {
    return $this->processUri;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_process_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Check if the process URI already exists in the form state.
    // If not, generate a new URI and store it in the form state.
    if (!$form_state->has('process_uri')) {
      $this->setProcessUri();
      $form_state->set('process_uri', $this->getProcessUri());
    }
    else {
      // Retrieve the persisted URI from form state.
      $this->processUri = $form_state->get('process_uri');
    }

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'std/std_process';
    $form['#attached']['library'][] = 'core/drupal.dialog';

    $tables = new Tables;
    $languages = $tables->getLanguages();
    $informants = $tables->getInformants();

    //SELECT ONE
    if ($languages)
      $languages = ['' => $this->t('Select language please')] + $languages;
    if ($informants)
      $informants = ['' => $this->t('Select Informant please')] + $informants;

    // Wrap everything in a div we can AJAX‑replace.
    $form['#prefix'] = '<div id="add-process-modal-content">';
    $form['#suffix'] = '</div>';

    $form['process_processstem'] = [
      'top' => [
        '#type' => 'markup',
        '#markup' => '<div class="pt-3 col border border-white">',
      ],
      'main' => [
        '#type' => 'textfield',
        '#title' => $this->t('Process Stem'),
        '#name' => 'process_processstem',
        '#default_value' => '',
        '#id' => 'process_processstem',
        '#parents' => ['process_processstem'],
        '#required' => true,
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
      '#default_value' => '',
      '#required' => true,
    ];
    $form['process_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $languages,
      '#default_value' => 'en',
    ];
    $form['process_version_hid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' => 1,
      '#disabled' => true
    ];
    $form['process_version'] = [
      '#type' => 'hidden',
      '#value' => $version ?? 1,
    ];
    $form['process_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => '',
      '#required' => true,
    ];

    $form['top_task_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    $form['top_task_row']['process_top_task'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Top Task Name'),
      '#default_value' => '',
      '#required' => TRUE,
      '#wrapper_attributes' => [
        'class' => ['col-md-6'],
      ],
    ];

    $form['top_task_row']['process_top_task_type'] = [
      'top' => [
        '#type' => 'markup',
        '#markup' => '<div class="col-md-6">',
      ],
      'main' => [
        '#type' => 'textfield',
        '#title' => $this->t('Top Task Type'),
        '#name' => 'process_top_task_type',
        '#default_value' => '',
        '#id' => 'process_top_task_type',
        '#parents' => ['process_top_task_type'],
        '#required' => TRUE,
        '#attributes' => [
          'class' => ['open-tree-modal'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url' => Url::fromRoute('rep.tree_form', [
            'mode' => 'modal',
            'elementtype' => 'task',
          ], ['query' => ['field_id' => 'process_top_task_type']])->toString(),
          'data-field-id' => 'process_top_task_type',
          'data-elementtype' => 'task',
          'autocomplete' => 'off',
        ],
      ],
      'bottom' => [
        '#type' => 'markup',
        '#markup' => '</div>',
      ],
    ];

    // Add a hidden field to persist the process URI between form rebuilds.
    $form['process_uri'] = [
      '#type' => 'hidden',
      '#value' => $this->processUri,
    ];

    // Add a select box to choose between URL and Upload.
    $form['process_image_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Type'),
      '#options' => [
        '' => $this->t('Select Image Type'),
        'url' => $this->t('URL'),
        'upload' => $this->t('Upload'),
      ],
      '#default_value' => '',
    ];

    // The textfield for entering a URL.
    // It is only visible when the select box value is 'url'.
    $form['process_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="process_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Because File Upload Path (use the persisted process URI for file uploads)
    $modUri = (explode(":/", utils::namespaceUri($this->processUri)))[1];
    $form['process_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="process_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];
    $form['process_image_upload_wrapper']['process_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'], // Adjust allowed extensions as needed.
        'file_validate_size' => [2097152],
      ],
    ];

    // Add a select box to choose between URL and Upload.
    $form['process_webdocument_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Web Document Type'),
      '#options' => [
        '' => $this->t('Select Document Type'),
        'url' => $this->t('URL'),
        'upload' => $this->t('Upload'),
      ],
      '#default_value' => '',
    ];

    // The textfield for entering a URL.
    // It is only visible when the select box value is 'url'.
    $form['process_webdocument_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web Document'),
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="process_webdocument_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Because File Upload Path (use the persisted process URI for file uploads)
    $form['process_webdocument_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="process_webdocument_type"]' => ['value' => 'upload'],
        ],
      ],
    ];
    $form['process_webdocument_upload_wrapper']['process_webdocument_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Document'),
      '#upload_location' => 'private://resources/' . $modUri . '/webdoc',
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf doc docx txt xls xlsx'], // Adjust allowed extensions as needed.
        'file_validate_size' => [2097152],
      ],
    ];

    $form['save_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
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
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // dpm($button_name) ;
    if ($button_name !== "back") {
      if(empty($form_state->getValue('process_processstem'))) {
        $form_state->setErrorByName('process_processstem', $this->t('Please select a valid Process Stem'));
      }
      if(strlen($form_state->getValue('process_name')) < 1) {
        $form_state->setErrorByName('process_name', $this->t('Please enter a valid Name'));
      }
      if(strlen($form_state->getValue('process_language')) < 1) {
        $form_state->setErrorByName('process_language', $this->t('Please enter a valid Language'));
      }
      if(strlen($form_state->getValue('process_top_task')) < 1) {
        $form_state->setErrorByName('process_top_task', $this->t('Please enter a valid Top Task Name'));
      }
      if(strlen($form_state->getValue('process_top_task_type')) < 1) {
        $form_state->setErrorByName('process_top_task_type', $this->t('Please enter a valid Top Task Type'));
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
      $useremail = \Drupal::currentUser()->getEmail();

      // $newProcessUri = Utils::uriGen('process');
      $newProcessUri = $form_state->getValue('process_uri');

      // Determine the chosen image type.
      $image_type = $form_state->getValue('process_image_type');
      $process_image = '';

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
            \Drupal::service('file.usage')->add($file, 'socialm', 'process', 1);
            // Now get the filename from the file entity.
            $process_image = $file->getFilename();
          }
        }
      }

      // Determine the chosen document type.
      $doc_type = $form_state->getValue('process_webdocument_type');
      $process_webdocument = '';

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

      // CREATE A TOP TASK FIRST
      $newTaskUri = Utils::uriGen('task');
      $taskJSON = '{"uri":"' . $newTaskUri . '",'
        . '"typeUri":"' . VSTOI::TASK . '",'
        . '"hascoTypeUri":"' . VSTOI::TASK . '",'
        . '"hasType":"' . Utils::uriFromAutocomplete($form_state->getValue('process_top_task_type')) . '",'
        . '"hasStatus":"' . VSTOI::DRAFT . '",'
        . '"label":"' . $form_state->getValue('process_top_task') . '",'
        . '"hasLanguage":"' . $form_state->getValue('process_language') . '",'
        . '"hasVersion":"1",'
        . '"comment":"",'
        . '"hasWebDocument":"",'
        . '"hasSIRManagerEmail":"' . $useremail . '"}';
      $api->elementAdd('task',$taskJSON);

      // Prepare data to be sent to the external service
      $processJSON = '{"uri":"' . $newProcessUri . '",'
        . '"typeUri":"' .Utils::uriFromAutocomplete($form_state->getValue('process_processstem')) . '",'
        . '"hascoTypeUri":"' . VSTOI::PROCESS . '",'
        . '"hasStatus":"' . VSTOI::DRAFT . '",'
        . '"label":"' . $form_state->getValue('process_name') . '",'
        . '"hasLanguage":"' . $form_state->getValue('process_language') . '",'
        . '"hasVersion":"' . $form_state->getValue('process_version') . '",'
        . '"comment":"' . $form_state->getValue('process_description') . '",'
        . '"hasWebDocument":"' . $process_webdocument . '",'
        . '"hasImageUri":"' . $process_image . '",'
        . '"hasTopTaskUri":"'. $newTaskUri .'",'
        . '"hasSIRManagerEmail":"' . $useremail . '"}';

      $message = $api->elementAdd('process',$processJSON);
      if ($message != null)
        \Drupal::messenger()->addMessage(t("Process has been added successfully."));

      // UPLOAD IMAGE AND WEBDOCUMENT TO API
      if ($image_type === 'upload') {
        $fids = $form_state->getValue('process_image_upload');
        $msg = $api->parseObjectResponse($api->uploadFile($newProcessUri, reset($fids)), 'uploadFile');
        if ($msg == NULL) {
          \Drupal::messenger()->addError(t("The Uploaded Image FAILED to be submited to API."));
        }
      }

      if ($doc_type === 'upload') {
        $fids = $form_state->getValue('process_webdocument_upload');
        $msg = $api->parseObjectResponse($api->uploadFile($newProcessUri, reset($fids)), 'uploadFile');
        if ($msg == NULL) {
          \Drupal::messenger()->addError(t("The Uploaded WebDocument FAILED to be submited to API."));
        }
      }

      self::backUrl();
      return;

    }catch(\Exception $e){
      \Drupal::messenger()->addMessage(t("An error occurred while adding process: ".$e->getMessage()));
      self::backUrl();
      return;
    }
  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_process');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
