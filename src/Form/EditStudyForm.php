<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\file\Entity\File;
use Drupal\Core\Render\Markup;

class EditStudyForm extends FormBase {

  protected $studyUri;

  protected $study;

  public function getStudyUri() {
    return $this->studyUri;
  }

  public function setStudyUri($uri) {
    return $this->studyUri = $uri;
  }

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($sem) {
    return $this->study = $sem;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_study_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL) {

    $uri=$studyuri ?? 'default';
    $uri_decode=base64_decode($uri);
    $this->setStudyUri($uri_decode);

    $api = \Drupal::service('rep.api_connector');
    $study = $api->parseObjectResponse($api->getUri($this->getStudyUri()),'getUri');
    if ($study == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Study."));
      self::backUrl();
      return;
    } else {
      $this->setStudy($study);
    }

    $form['study_short_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short Name'),
      '#default_value' => $this->getStudy()->label,
    ];
    $form['study_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Long Name'),
      '#default_value' => $this->getStudy()->title,
    ];
    $form['study_pi'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PI'),
      '#default_value' => $this->getStudy()->pi,
    ];
    $form['study_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->getStudy()->comment,
    ];

    // **** IMAGE ****
    // Retrieve the current image value.
    // Retrieve the current study and its image.
    $study = $this->getStudy();
    $study_uri = Utils::namespaceUri($this->getStudyUri());
    $study_image = $study->hasImageUri ?? '';

    // Determine if the existing web document is a URL or a file.
    $image_type = '';
    if (!empty($study_image) && stripos(trim($study_image), 'http') === 0) {
      $image_type = 'url';
    }
    elseif (!empty($study_image)) {
      $image_type = 'upload';
    }

    $modUri = '';
    if (!empty($study_uri)) {
      // Example of extracting part of the URI. Adjust or remove if not needed.
      $parts = explode(':/', $study_uri);
      if (count($parts) > 1) {
        $modUri = $parts[1];
      }
    }

    // Image Type selector (URL or Upload).
    $form['study_information']['study_image_type'] = [
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
    $form['study_information']['study_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#default_value' => ($image_type === 'url') ? $study_image : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="study_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['study_information']['study_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="study_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_image_fid = NULL;
    if ($image_type === 'upload' && !empty($study_image)) {
      // Build the expected file URI in the private filesystem.
      $desired_uri = 'private://resources/' . $modUri . '/image/' . $study_image;
      $files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $desired_uri]);
      $file = reset($files);
      if ($file) {
        $existing_image_fid = $file->id();
      }
    }

    // 5. Managed file element for uploading a new document.
    $form['study_information']['study_image_upload_wrapper']['study_image_upload'] = [
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
    $study_webdocument = $study->hasWebDocument ?? '';

    // Determine if the existing web document is a URL or a file.
    $webdocument_type = '';
    if (!empty($study_webdocument) && stripos(trim($study_webdocument), 'http') === 0) {
      $webdocument_type = 'url';
    }
    elseif (!empty($study_webdocument)) {
      $webdocument_type = 'upload';
    }

    // Web Document Type selector (URL or Upload).
    $form['study_information']['study_webdocument_type'] = [
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
    $form['study_information']['study_webdocument_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web Document'),
      '#default_value' => ($webdocument_type === 'url') ? $study_webdocument : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="study_webdocument_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['study_information']['study_webdocument_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="study_webdocument_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_fid = NULL;
    if ($webdocument_type === 'upload' && !empty($study_webdocument)) {
      // Build the expected file URI in the private filesystem.
      $desired_uri = 'private://resources/' . $modUri . '/webdoc/' . $study_webdocument;
      $files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $desired_uri]);
      $file = reset($files);
      if ($file) {
        $existing_fid = $file->id();
      }
    }

    // 5. Managed file element for uploading a new document.
    $form['study_information']['study_webdocument_upload_wrapper']['study_webdocument_upload'] = [
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

    if ($button_name === 'save') {
      if(strlen($form_state->getValue('study_short_name')) < 1) {
        $form_state->setErrorByName('study_short_name', $this->t('Please enter a short name for the Study'));
      }
      if(strlen($form_state->getValue('study_name')) < 1) {
        $form_state->setErrorByName('study_name', $this->t('Please enter a name for the Study'));
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

      // Determine the chosen document type.
      $doc_type = $form_state->getValue('study_webdocument_type');
      $study_webdocument = $this->getStudy()->hasWebDocument;

      // If user selected URL, use the textfield value.
      if ($doc_type === 'url') {
        $study_webdocument = $form_state->getValue('study_webdocument_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($doc_type === 'upload') {
        // Get the file IDs from the managed_file element.
        $fids = $form_state->getValue('study_webdocument_upload');
        if (!empty($fids)) {
          // Load the first file (file ID is returned, e.g. "374").
          $file = File::load(reset($fids));
          if ($file) {
            // Mark the file as permanent and save it.
            $file->setPermanent();
            $file->save();
            // Optionally register file usage to prevent cleanup.
            \Drupal::service('file.usage')->add($file, 'sir', 'study', 1);
            // Now get the filename from the file entity.
            $study_webdocument = $file->getFilename();
          }
        }
      }

      // Determine the chosen image type.
      $image_type = $form_state->getValue('study_image_type');
      $study_image = $this->getStudy()->hasImageUri;

      // If user selected URL, use the textfield value.
      if ($image_type === 'url') {
        $study_image = $form_state->getValue('study_image_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($image_type === 'upload') {
        // Get the file IDs from the managed_file element.
        $fids = $form_state->getValue('study_image_upload');
        if (!empty($fids)) {
          // Load the first file (file ID is returned, e.g. "374").
          $file = File::load(reset($fids));
          if ($file) {
            // Mark the file as permanent and save it.
            $file->setPermanent();
            $file->save();
            // Optionally register file usage to prevent cleanup.
            \Drupal::service('file.usage')->add($file, 'sir', 'study', 1);
            // Now get the filename from the file entity.
            $study_image = $file->getFilename();
          }
        }
      }

      $studyJson = '{"uri":"'. $this->getStudy()->uri .'",'.
        '"typeUri":"'.HASCO::STUDY.'",'.
        '"hascoTypeUri":"'.HASCO::STUDY.'",'.
        '"label":"'.$form_state->getValue('study_short_name').'",'.
        '"title":"'.$form_state->getValue('study_name').'",'.
        '"comment":"'.$form_state->getValue('study_description').'",'.
        // '"pi":"'.$form_state->getValue('study_pi').'",'.
        '"hasWebDocument":"' . $study_webdocument . '",' .
        '"hasImageUri":"' . $study_image . '",' .
        '"hasSIRManagerEmail":"'.$useremail.'"}';

      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $api->elementDel('study',$this->getStudy()->uri);
      $message = $api->elementAdd('study',$studyJson);

      // UPLOAD IMAGE TO API
      if ($image_type === 'upload' && $study_image !== $this->getStudy()->hasImageUri) {
        $fids = $form_state->getValue('study_image_upload');
        $msg = $api->parseObjectResponse($api->uploadFile($this->getStudyUri(), reset($fids)), 'uploadFile');
        if ($msg == NULL) {
          \Drupal::messenger()->addError(t("The Uploaded Image FAILED to be submited to API."));
        }
      }

      // UPLOAD DOCUMENT TO API
      if ($doc_type === 'upload' && $study_webdocument !== $this->getStudy()->hasWebDocument) {
        $fids = $form_state->getValue('study_webdocument_upload');
        $msg = $api->parseObjectResponse($api->uploadFile($this->getStudyUri(), reset($fids)), 'uploadFile');
        if ($msg == NULL) {
          \Drupal::messenger()->addError(t("The Uploaded WebDocument FAILED to be submited to API."));
        }
      }

      if ($message != null)
        \Drupal::messenger()->addMessage(t("Study has been updated successfully."));

      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while updating Study: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl($back_url = NULL) {
    if ($back_url) {
      $response = new RedirectResponse($back_url);
      $response->send();
      return;
    } else {
      $uid = \Drupal::currentUser()->id();
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_study');

      if ($previousUrl && strpos($previousUrl, '/load-more-data') !== false) {
        parse_str(parse_url($previousUrl, PHP_URL_QUERY), $params);
        $page = isset($params['page']) ? $params['page'] : 1;
        $element_type = isset($params['element_type']) ? $params['element_type'] : 'study';
        $pagesize = 9;

        $previousUrl = Url::fromRoute('std.select_study', [
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
        $default_url = Url::fromRoute('std.select_study', [
          'elementtype' => 'study',
          'page' => 1,
          'pagesize' => 9,
        ])->toString();
        $response = new RedirectResponse($default_url);
        $response->send();
      }
    }
  }

}
