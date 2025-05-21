<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\file\Entity\File;

class AddStudyForm extends FormBase {

  protected $studyUri;

  public function setStudyUri() {
    $this->studyUri = Utils::uriGen('study');
  }

  public function getStudyUri() {
    return $this->studyUri;
  }

  public $pi = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_study_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    //dpm($form_state->getValue('study_pi'));

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

    $form['study_short_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short Name'),
    ];

    $form['study_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Long Name'),
    ];

    $form['study_pi'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PI'),
    ];

    $form['study_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
    ];

    // Add a hidden field to persist the study URI between form rebuilds.
    $form['study_uri'] = [
      '#type' => 'hidden',
      '#value' => $this->studyUri,
    ];

    // Add a select box to choose between URL and Upload.
    $form['study_image_type'] = [
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
    $form['study_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="study_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Because File Upload Path (use the persisted study URI for file uploads)
    $modUri = (explode(":/", utils::namespaceUri($this->studyUri)))[1];
    $form['study_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="study_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];
    $form['study_image_upload_wrapper']['study_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'], // Adjust allowed extensions as needed.
        'file_validate_size' => [2097152],
      ],
    ];

    // Add a select box to choose between URL and Upload.
    $form['study_webdocument_type'] = [
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
    $form['study_webdocument_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web Document'),
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="study_webdocument_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Because File Upload Path (use the persisted study URI for file uploads)
    $form['study_webdocument_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="study_webdocument_type"]' => ['value' => 'upload'],
        ],
      ],
    ];
    $form['study_webdocument_upload_wrapper']['study_webdocument_upload'] = [
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
        $form_state->setErrorByName('study_short_name', $this->t('Please enter a valid short name for the Study'));
      }
      if(strlen($form_state->getValue('study_name')) < 1) {
        $form_state->setErrorByName('study_name', $this->t('Please enter a valid name for the Study'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    if ($button_name === 'save') {

      try {
        $useremail = \Drupal::currentUser()->getEmail();

        $newStudyUri = $form_state->getValue('study_uri');

        // Determine the chosen document type.
        $doc_type = $form_state->getValue('study_webdocument_type');
        $study_webdocument = '';

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
        $study_image = '';

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

        $studyJSON = '{"uri":"'. $newStudyUri .'",'.
          '"typeUri":"'.HASCO::STUDY.'",'.
          '"hascoTypeUri":"'.HASCO::STUDY.'",'.
          '"label":"'.$form_state->getValue('study_short_name').'",'.
          '"title":"'.$form_state->getValue('study_name').'",'.
          '"comment":"'.$form_state->getValue('study_description').'",'.
          // '"pi":"'.$form_state->getValue('study_pi').'",'.
          '"hasWebDocument":"' . $study_webdocument . '",' .
          '"hasImageUri":"' . $study_image . '",' .
          '"hasSIRManagerEmail":"'.$useremail.'"}';


        $api = \Drupal::service('rep.api_connector');
        $message = $api->parseObjectResponse($api->elementAdd('study',$studyJSON),'elementAdd');
        if ($message != null) {

          // UPLOAD IMAGE TO API
          if ($image_type === 'upload') {
            $fids = $form_state->getValue('study_image_upload');
            $msg = $api->parseObjectResponse($api->uploadFile($newStudyUri, reset($fids)), 'uploadFile');
            if ($msg == NULL) {
              \Drupal::messenger()->addError(t("The Uploaded Image FAILED to be submited to API."));
            }
          }

          if ($doc_type === 'upload') {
            $fids = $form_state->getValue('study_webdocument_upload');
            $msg = $api->parseObjectResponse($api->uploadFile($newStudyUri, reset($fids)), 'uploadFile');
            if ($msg == NULL) {
              \Drupal::messenger()->addError(t("The Uploaded WebDocument FAILED to be submited to API."));
            }
          }

          \Drupal::messenger()->addMessage(t("Study has been added successfully."));
        }
        self::backUrl();
        return;

      } catch(\Exception $e) {
        \Drupal::messenger()->addMessage(t("An error occurred while adding a study: ".$e->getMessage()));
        self::backUrl();
        return;
      }
    }

    return;

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_study');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }



}
