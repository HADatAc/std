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
use Drupal\Component\Utility\Html;

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

    // Media preview modal (PDF/image viewer).
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';
    $form['#attached']['library'][] = 'rep/pdfjs';
    $form['#attached']['library'][] = 'rep/webdoc_modal';
    $base_url = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://' : 'http://')
      . \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];

    $uri=$studyuri ?? 'default';
    $uri_decode=base64_decode($uri);
    $this->setStudyUri($uri_decode);
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    $api = \Drupal::service('rep.api_connector');
    $study = $api->parseObjectResponse($api->getUri($this->getStudyUri()),'getUri');
    if ($study == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve ".ucfirst($preferred_study)."."));
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

    $institutionDefault = '';
    if (isset($this->getStudy()->institution) && is_object($this->getStudy()->institution)) {
      $institutionLabel = $this->getStudy()->institution->name ?? ($this->getStudy()->institution->label ?? '');
      $institutionUri = $this->getStudy()->institution->uri ?? ($this->getStudy()->institutionUri ?? '');
      if ($institutionUri !== '' && $institutionLabel !== '') {
        $institutionDefault = Utils::fieldToAutocomplete($institutionUri, $institutionLabel);
      }
    }
    elseif (isset($this->getStudy()->institutionUri) && $this->getStudy()->institutionUri != '') {
      $institutionDefault = Utils::fieldToAutocomplete($this->getStudy()->institutionUri, $this->getStudy()->institutionUri);
    }

    $form['study_institution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Institution'),
      '#default_value' => $institutionDefault,
      '#autocomplete_route_name'       => 'rep.social_autocomplete',
      '#autocomplete_route_parameters' => [
        'entityType' => 'organization',
      ],
      '#attributes' => [
        'autocomplete' => 'off',
      ],
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

    // Attempt to load an existing file if the image is not a URL.
    $existing_image_fid = NULL;
    if ($image_type === 'upload' && !empty($study_image)) {
      $existing_image_fid = Utils::resolvePrivateResourceFid($modUri, 'image', (string) $study_image);
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
      '#default_value' => $existing_image_fid ? [$existing_image_fid] : NULL,
      // Description in red: allowed file types and a warning that choosing a new image will remove the previous one.
      '#description' => Markup::create('<span style="color: red;">Allowed file types: png, jpg, jpeg. Selecting a new image will remove the previous one.</span>'),
    ];

    // Image preview (thumbnail -> modal).
    $image_preview_url = '';
    if ($image_type === 'url' && !empty($study_image)) {
      $image_preview_url = $study_image;
    }
    elseif ($existing_image_fid) {
      $existing_image_file = File::load($existing_image_fid);
      if ($existing_image_file) {
        $image_preview_url = \Drupal::service('file_url_generator')->generateAbsoluteString($existing_image_file->getFileUri());
      }
    }

    if (!empty($image_preview_url)) {
      $escaped_image_url = Html::escape($image_preview_url);
      $form['study_information']['study_image_preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Image Preview'),
        '#markup' => Markup::create(
          '<button type="button" class="btn btn-link p-0 view-media-button" data-view-url="' . $escaped_image_url . '" aria-label="' . Html::escape((string) $this->t('Preview image')) . '">' .
          '<img src="' . $escaped_image_url . '" class="img-thumbnail" style="max-width: 120px; height: auto;" />' .
          '</button>'
        ),
        '#states' => [
          'visible' => [
            ':input[name="study_image_type"]' => [
              ['value' => 'url'],
              ['value' => 'upload'],
            ],
          ],
        ],
      ];
    }

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
      $existing_fid = Utils::resolvePrivateResourceFid($modUri, 'webdoc', (string) $study_webdocument, ['webdocument', 'image']);
    }

    // 5. Managed file element for uploading a new document.
    $form['study_information']['study_webdocument_upload_wrapper']['study_webdocument_upload'] = [
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
    if ($webdocument_type === 'url' && !empty($study_webdocument)) {
      $webdoc_preview_url = $study_webdocument;
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
        $thumb_markup =
          '<div class="border rounded" style="width: 120px; height: 160px; overflow: hidden;">' .
          '<embed src="' . $escaped_doc_url . '#page=1&zoom=70" type="application/pdf" style="width: 120px; height: 160px; pointer-events: none;" />' .
          '</div>';
      }
      else {
        $label = $parsed_path ? basename($parsed_path) : (string) $this->t('View document');
        $thumb_markup = '<span class="small">' . Html::escape($label) . '</span>';
      }

      $form['study_information']['study_webdocument_preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Web Document Preview'),
        '#markup' => Markup::create(
          '<button type="button" class="btn btn-link p-0 view-media-button" data-view-url="' . $escaped_doc_url . '" aria-label="' . Html::escape((string) $this->t('Preview document')) . '">' .
          $thumb_markup .
          '</button>'
        ),
        '#states' => [
          'visible' => [
            ':input[name="study_webdocument_type"]' => [
              ['value' => 'url'],
              ['value' => 'upload'],
            ],
          ],
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
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    if ($button_name === 'save') {
      if(strlen($form_state->getValue('study_short_name')) < 1) {
        $form_state->setErrorByName('study_short_name', $this->t('Please enter a short name for the '.ucfirst($preferred_study)));
      }
      if(strlen($form_state->getValue('study_name')) < 1) {
        $form_state->setErrorByName('study_name', $this->t('Please enter a name for the '.ucfirst($preferred_study)));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    try {
      $useremail = \Drupal::currentUser()->getEmail();

      // Compute module-ish URI segment used for private file storage.
      $study_uri = Utils::namespaceUri($this->getStudyUri());
      $modUri = '';
      if (!empty($study_uri)) {
        $parts = explode(':/', $study_uri);
        if (count($parts) > 1) {
          $modUri = $parts[1];
        }
      }

      // Determine the chosen document type.
      $doc_type = $form_state->getValue('study_webdocument_type');
      $study_webdocument = $this->getStudy()->hasWebDocument;

      // If user selected URL, use the textfield value.
      if ($doc_type === 'url') {
        $study_webdocument = $form_state->getValue('study_webdocument_url');
      }
      // If user selected Upload, load the file entity and get its filename.
      elseif ($doc_type === 'upload') {
        $fids = $form_state->getValue('study_webdocument_upload') ?: [];
        $submitted_fid = !empty($fids) ? (int) reset($fids) : NULL;
        $existing_fid = Utils::resolvePrivateResourceFid($modUri, 'webdoc', (string) $this->getStudy()->hasWebDocument, ['webdocument', 'image']);

        // Only treat as "new upload" if user actually selected a different file.
        if ($submitted_fid && (!$existing_fid || $submitted_fid !== (int) $existing_fid)) {
          $file = File::load($submitted_fid);
          if ($file) {
            $file->setPermanent();
            $file->save();
            \Drupal::service('file.usage')->add($file, 'sir', 'study', 1);
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
        $fids = $form_state->getValue('study_image_upload') ?: [];
        $submitted_fid = !empty($fids) ? (int) reset($fids) : NULL;
        $existing_fid = Utils::resolvePrivateResourceFid($modUri, 'image', (string) $this->getStudy()->hasImageUri);

        if ($submitted_fid && (!$existing_fid || $submitted_fid !== (int) $existing_fid)) {
          $file = File::load($submitted_fid);
          if ($file) {
            $file->setPermanent();
            $file->save();
            \Drupal::service('file.usage')->add($file, 'sir', 'study', 1);
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
        '"institutionUri":"' . Utils::uriFromAutocomplete((string) $form_state->getValue('study_institution')) . '",'.
        // '"pi":"'.$form_state->getValue('study_pi').'",'.
        '"hasWebDocument":"' . $study_webdocument . '",' .
        '"hasImageUri":"' . $study_image . '",' .
        '"hasSIRManagerEmail":"'.$useremail.'"}';

      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $api->elementDel('study',$this->getStudy()->uri);
      $message = $api->elementAdd('study',$studyJson);

      if ($message != null)
        \Drupal::messenger()->addMessage(t(ucfirst($preferred_study)." has been updated successfully."));

      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while updating ".ucfirst($preferred_study).": ".$e->getMessage()));
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
