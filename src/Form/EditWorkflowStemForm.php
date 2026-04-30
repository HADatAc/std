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

class EditWorkflowStemForm extends FormBase {

  protected $componentStemUri;

  protected $componentStem;

  protected $sourceWorkflowStem;

  public function getWorkflowStemUri() {
    return $this->componentStemUri;
  }

  public function setWorkflowStemUri($uri) {
    return $this->componentStemUri = $uri;
  }

  public function getWorkflowStem() {
    return $this->componentStem;
  }

  public function setWorkflowStem($obj) {
    return $this->componentStem = $obj;
  }

  public function getSourceWorkflowStem() {
    return $this->sourceWorkflowStem;
  }

  public function setSourceWorkflowStem($obj) {
    return $this->sourceWorkflowStem = $obj;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_workflowstem_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $workflowstemUri = NULL) {

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

    $form['#attached']['library'][] = 'std/std_workflowstem';

    $uri=$workflowstemUri;
    $uri_decode=base64_decode($uri);
    $this->setWorkflowStemUri($uri_decode);

    $this->setWorkflowStem($this->retrieveWorkflowStem($this->getWorkflowStemUri()));
    if ($this->getWorkflowStem() == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve Workflow."));
      self::backUrl();
      return;
    }

    $tables = new Tables;
    $languages = $tables->getLanguages();
    $derivations = $tables->getGenerationActivities();

    // IN CASE ITS A DERIVATION ORIGINAL MUST BE REMOVED ALSO
    if ($this->getWorkflowStem()->hasStatus === VSTOI::CURRENT || $this->getWorkflowStem()->hasVersion > 1) {
      unset($derivations[Constant::DEFAULT_WAS_GENERATED_BY]);
    }

    $languages = ['' => $this->t('Select one please')] + $languages;
    $derivations = ['' => $this->t('Select one please')] + $derivations;

    $form['workflowstem_uri'] = [
      '#type' => 'item',
      '#title' => $this->t('URI: '),
      '#markup' => Markup::create(Utils::describeAnchor((string) $this->getWorkflowStemUri(), (string) $this->getWorkflowStemUri())),
    ];
    // dpm($this->getWorkflowStem());
    if ($this->getWorkflowStem()->superUri) {
      $form['workflowstem_type'] = [
        'top' => [
          '#type' => 'markup',
          '#markup' => '<div class="col border border-white">',
        ],
        'main' => [
          '#type' => 'textfield',
          '#title' => $this->t('Parent Type'),
          '#name' => 'workflowstem_type',
          '#default_value' => $this->getWorkflowStem()->superUri ? Utils::fieldToAutocomplete($this->getWorkflowStem()->superUri, $this->getWorkflowStem()->superClassLabel) : '',
          '#id' => 'workflowstem_type',
          '#parents' => ['workflowstem_type'],
          '#attributes' => [
            'class' => ['open-tree-modal'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => json_encode(['width' => 800]),
            'data-url' => Url::fromRoute('rep.tree_form', [
              'mode' => 'modal',
              'elementtype' => 'workflowstem',
            ], ['query' => ['field_id' => 'workflowstem_type']])->toString(),
            'data-field-id' => 'workflowstem_type',
            'data-elementtype' => 'workflowstem',
            'data-search-value' => $this->getWorkflowStem()->superUri ?? '',
          ],
        ],
        'bottom' => [
          '#type' => 'markup',
          '#markup' => '</div>',
        ],
      ];
    }

    $form['workflowstem_content'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $this->getWorkflowStem()->hasContent,
    ];
    $form['workflowstem_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $languages,
      '#default_value' => $this->getWorkflowStem()->hasLanguage,
      '#attributes' => [
        'id' => 'workflowstem_language'
      ]
    ];
    $form['workflowstem_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' =>
        ($this->getWorkflowStem()->hasStatus === VSTOI::CURRENT || $this->getWorkflowStem()->hasStatus === VSTOI::DEPRECATED) ?
        $this->getWorkflowStem()->hasVersion + 1 : $this->getWorkflowStem()->hasVersion,
      '#attributes' => [
        'disabled' => 'disabled',
      ],
    ];
    $form['workflowstem_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->getWorkflowStem()->comment,
    ];

    if ($this->getWorkflowStem()->wasDerivedFrom !== NULL) {
      $form['workflowstem_df_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['d-flex', 'align-items-center', 'w-100'], // Flex container para alinhamento correto
          'style' => "width: 100%; gap: 10px;", // Garante espaçamento correto
        ],
      ];

      if ($this->getWorkflowStem()->wasDerivedFrom !== NULL) {
        $form['workflowstem_df_wrapper']['workflowstem_wasderivedfrom'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Derived From'),
          '#default_value' => $this->getWorkflowStem()->wasDerivedFrom,
          '#attributes' => [
            'class' => ['flex-grow-1'],
            'style' => "width: 100%; min-width: 0;",
            'disabled' => 'disabled',
          ],
        ];
      }

      $url = Utils::describeHref((string) $this->getWorkflowStem()->wasDerivedFrom);

      $form['workflowstem_df_wrapper']['workflowstem_wasderivedfrom_button'] = [
        '#type' => 'markup',
        '#markup' => '<a href="' . $url . '" class="btn btn-primary text-nowrap mt-2 rep-nav-guard" style="min-width: 160px; height: 38px; display: flex; align-items: center; justify-content: center;">' . $this->t('Check Element') . '</a>',
      ];
    }
    $form['workflowstem_was_generated_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Was Derived By'),
      '#options' => $derivations,
      '#default_value' => $this->getWorkflowStem()->wasGeneratedBy,
      '#attributes' => [
        'id' => 'workflowstem_was_generated_by'
      ],
      '#disabled' => ($this->getWorkflowStem()->wasGeneratedBy === Constant::WGB_ORIGINAL ? true:false)
    ];

    // **** IMAGE ****
    // Retrieve the current image value.
    // Retrieve the current workflowstem and its image.
    $workflowstem = $this->getWorkflowStem();
    $workflowstem_uri = Utils::namespaceUri($this->getWorkflowStemUri());
    $workflowstem_image = $workflowstem->hasImageUri ?? '';

    // Determine if the existing web document is a URL or a file.
    $image_type = '';
    if (!empty($workflowstem_image) && stripos(trim($workflowstem_image), 'http') === 0) {
      $image_type = 'url';
    }
    elseif (!empty($workflowstem_image)) {
      $image_type = 'upload';
    }

    $modUri = '';
    if (!empty($workflowstem_uri)) {
      // Split the URI into parts using ':/'
      $parts = explode(':/', $workflowstem_uri);
      if (count($parts) > 1) {
        $modUri = $parts[1];
      }
    }

    // Image Type selector (URL or Upload).
    $form['workflowstem_information']['workflowstem_image_type'] = [
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
    $form['workflowstem_information']['workflowstem_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#default_value' => ($image_type === 'url') ? $workflowstem_image : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="workflowstem_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['workflowstem_information']['workflowstem_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="workflowstem_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the image is not a URL.
    $existing_image_fid = NULL;
    if ($image_type === 'upload' && !empty($workflowstem_image)) {
      $existing_image_fid = Utils::resolvePrivateResourceFid($modUri, 'image', (string) $workflowstem_image);
    }

    // 5. Managed file element for uploading a new document.
    $form['workflowstem_information']['workflowstem_image_upload_wrapper']['workflowstem_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_size' => [2097152],
      ],
      // If a file already exists, pass its ID so Drupal can display it.
      '#default_value' => $existing_image_fid ? [$existing_image_fid] : NULL,
    ];

    // Image preview (thumbnail -> modal).
    $image_preview_url = '';
    if ($image_type === 'url' && !empty($workflowstem_image)) {
      $image_preview_url = $workflowstem_image;
    }
    elseif ($existing_image_fid) {
      $existing_image_file = File::load($existing_image_fid);
      if ($existing_image_file) {
        $image_preview_url = \Drupal::service('file_url_generator')->generateAbsoluteString($existing_image_file->getFileUri());
      }
    }

    if (!empty($image_preview_url)) {
      $escaped_image_url = Html::escape($image_preview_url);
      $form['workflowstem_information']['workflowstem_image_preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Image Preview'),
        '#markup' => Markup::create(
          '<button type="button" class="btn btn-link p-0 view-media-button" data-view-url="' . $escaped_image_url . '" aria-label="' . Html::escape((string) $this->t('Preview image')) . '">' .
          '<img src="' . $escaped_image_url . '" class="img-thumbnail" style="max-width: 120px; height: auto;" />' .
          '</button>'
        ),
        '#states' => [
          'visible' => [
            ':input[name="workflowstem_image_type"]' => [
              ['value' => 'url'],
              ['value' => 'upload'],
            ],
          ],
        ],
      ];
    }

    // **** WEBDOCUMENT ****
    // Retrieve the current web document value.
    $workflowstem_webdocument = $workflowstem->hasWebDocument ?? '';

    // Determine if the existing web document is a URL or a file.
    $webdocument_type = '';
    if (!empty($workflowstem_webdocument) && stripos(trim($workflowstem_webdocument), 'http') === 0) {
      $webdocument_type = 'url';
    }
    elseif (!empty($workflowstem_webdocument)) {
      $webdocument_type = 'upload';
    }

    // Web Document Type selector (URL or Upload).
    $form['workflowstem_information']['workflowstem_webdocument_type'] = [
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
    $form['workflowstem_information']['workflowstem_webdocument_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web Document'),
      '#default_value' => ($webdocument_type === 'url') ? $workflowstem_webdocument : '',
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="workflowstem_webdocument_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Container for the file upload elements (only visible when type = 'upload').
    $form['workflowstem_information']['workflowstem_webdocument_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="workflowstem_webdocument_type"]' => ['value' => 'upload'],
        ],
      ],
    ];

    // Attempt to load an existing file if the document is not a URL.
    $existing_fid = NULL;
    if ($webdocument_type === 'upload' && !empty($workflowstem_webdocument)) {
      $existing_fid = Utils::resolvePrivateResourceFid($modUri, 'webdoc', (string) $workflowstem_webdocument, ['webdocument', 'image']);
    }

    // 5. Managed file element for uploading a new document.
    $form['workflowstem_information']['workflowstem_webdocument_upload_wrapper']['workflowstem_webdocument_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Document'),
      '#upload_location' => 'private://resources/' . $modUri . '/webdoc',
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf doc docx txt xls xlsx'],
      ],
      // If a file already exists, pass its ID so Drupal can display it.
      '#default_value' => $existing_fid ? [$existing_fid] : NULL,
    ];

    // Web document preview (thumbnail/button -> modal).
    $webdoc_preview_url = '';
    if ($webdocument_type === 'url' && !empty($workflowstem_webdocument)) {
      $webdoc_preview_url = $workflowstem_webdocument;
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

      $form['workflowstem_information']['workflowstem_webdocument_preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Web Document Preview'),
        '#markup' => Markup::create(
          '<button type="button" class="btn btn-link p-0 view-media-button" data-view-url="' . $escaped_doc_url . '" aria-label="' . Html::escape((string) $this->t('Preview document')) . '">' .
          $thumb_markup .
          '</button>'
        ),
        '#states' => [
          'visible' => [
            ':input[name="workflowstem_webdocument_type"]' => [
              ['value' => 'url'],
              ['value' => 'upload'],
            ],
          ],
        ],
      ];
    }

    if ($this->getWorkflowStem()->hasReviewNote !== NULL && $this->getWorkflowStem()->hasStatus !== null) {
      $form['workflowstem_hasreviewnote'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Review Notes'),
        '#default_value' => $this->getWorkflowStem()->hasReviewNote,
        '#disabled' => TRUE
      ];
      $form['workflowstem_haseditoremail'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Reviewer Email'),
        '#default_value' => \Drupal::currentUser()->getEmail(),
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
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'cancel-button'],
        'id' => 'cancel_button'
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

    if ($button_name != 'back') {
      if(strlen($form_state->getValue('workflowstem_content')) < 1) {
        $form_state->setErrorByName('workflowstem_content', $this->t('Please enter a valid Name'));
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

    $api = \Drupal::service('rep.api_connector');

    try{

      $useremail = \Drupal::currentUser()->getEmail();

      // CHECK if Status is CURRENT OR DEPRECATED FOR NEW CREATION
      if ($this->getWorkflowStem()->hasStatus === VSTOI::CURRENT || $this->getWorkflowStem()->hasStatus === VSTOI::DEPRECATED) {

        $workflowStemJson = '{"uri":"'.Utils::uriGen('workflowstem').'",'.
          '"superUri":"'.Utils::uriFromAutocomplete($this->getWorkflowStem()->superUri).'",'.
          '"label":"'.$form_state->getValue('workflowstem_content').'",'.
          '"hascoTypeUri":"'.VSTOI::WORKFLOWSTEM.'",'.
          '"hasStatus":"'.VSTOI::DRAFT.'",'.
          '"hasContent":"'.$form_state->getValue('workflowstem_content').'",'.
          '"hasLanguage":"'.$form_state->getValue('workflowstem_language').'",'.
          '"hasVersion":"'.$form_state->getValue('workflowstem_version').'",'.
          '"comment":"'.$form_state->getValue('workflowstem_description').'",'.
          '"hasWebDocument":"",'.
          '"hasImageUri":"",' .
          '"wasDerivedFrom":"'.$this->getWorkflowStem()->uri.'",'. //Previous Version is the New Derivation Value
          '"wasGeneratedBy":"'.$form_state->getValue('workflowstem_was_generated_by').'",'.
          '"hasSIRManagerEmail":"'.$useremail.'"}';

        // UPDATE BY DELETING AND CREATING
        $api->elementAdd('workflowstem', $workflowStemJson);
        \Drupal::messenger()->addMessage(t("New Version Workflow Stem has been created successfully."));

      } else {

        // Determine the chosen document type.
        $doc_type = $form_state->getValue('workflowstem_webdocument_type');
        $workflowstem_webdocument = $this->getWorkflowStem()->hasWebDocument ?? '';

        // Compute module-ish URI segment used for private file storage.
        $workflowstem_uri = Utils::namespaceUri($this->getWorkflowStemUri());
        $modUri = '';
        if (!empty($workflowstem_uri)) {
          $parts = explode(':/', $workflowstem_uri);
          if (count($parts) > 1) {
            $modUri = $parts[1];
          }
        }

        // If user selected URL, use the textfield value.
        if ($doc_type === 'url') {
          $workflowstem_webdocument = $form_state->getValue('workflowstem_webdocument_url');
        }
        // If user selected Upload, load the file entity and get its filename.
        elseif ($doc_type === 'upload') {
          $fids = $form_state->getValue('workflowstem_webdocument_upload') ?: [];
          $submitted_fid = !empty($fids) ? (int) reset($fids) : NULL;
          $existing_fid = Utils::resolvePrivateResourceFid($modUri, 'webdoc', (string) ($this->getWorkflowStem()->hasWebDocument ?? ''), ['webdocument', 'image']);

          if ($submitted_fid && (!$existing_fid || $submitted_fid !== (int) $existing_fid)) {
            $file = File::load($submitted_fid);
            if ($file) {
              $file->setPermanent();
              $file->save();
              \Drupal::service('file.usage')->add($file, 'std', 'workflowstem', 1);
              $workflowstem_webdocument = $file->getFilename();
            }
          }
        }

        // Determine the chosen image type.
        $image_type = $form_state->getValue('workflowstem_image_type');
        $workflowstem_image = $this->getWorkflowStem()->hasImageUri ?? '';

        // If user selected URL, use the textfield value.
        if ($image_type === 'url') {
          $workflowstem_image = $form_state->getValue('workflowstem_image_url');
        }
        // If user selected Upload, load the file entity and get its filename.
        elseif ($image_type === 'upload') {
          $fids = $form_state->getValue('workflowstem_image_upload') ?: [];
          $submitted_fid = !empty($fids) ? (int) reset($fids) : NULL;
          $existing_fid = Utils::resolvePrivateResourceFid($modUri, 'image', (string) ($this->getWorkflowStem()->hasImageUri ?? ''));

          if ($submitted_fid && (!$existing_fid || $submitted_fid !== (int) $existing_fid)) {
            $file = File::load($submitted_fid);
            if ($file) {
              $file->setPermanent();
              $file->save();
              \Drupal::service('file.usage')->add($file, 'std', 'workflowstem', 1);
              $workflowstem_image = $file->getFilename();
            }
          }
        }

        $workflowStemJson = '{"uri":"'.$this->getWorkflowStem()->uri.'",'.
        '"superUri":"'.Utils::uriFromAutocomplete($this->getWorkflowStem()->superUri).'",'.
        '"label":"'.$form_state->getValue('workflowstem_content').'",'.
        '"hascoTypeUri":"'.VSTOI::WORKFLOWSTEM.'",'.
        '"hasStatus":"'.$this->getWorkflowStem()->hasStatus.'",'.
        '"hasContent":"'.$form_state->getValue('workflowstem_content').'",'.
        '"hasLanguage":"'.$form_state->getValue('workflowstem_language').'",'.
        '"hasVersion":"'.$form_state->getValue('workflowstem_version').'",'.
        '"comment":"'.$form_state->getValue('workflowstem_description').'",'.
        '"hasWebDocument":"' . $workflowstem_webdocument . '",' .
        '"hasImageUri":"' . $workflowstem_image . '",' .
        '"wasDerivedFrom":"'.$this->getWorkflowStem()->wasDerivedFrom.'",'.
        '"wasGeneratedBy":"'.$form_state->getValue('workflowstem_was_generated_by').'",'.
        '"hasReviewNote":"'.($this->getWorkflowStem()->hasStatus !== null ? $this->getWorkflowStem()->hasReviewNote : '').'",'.
        '"hasEditorEmail":"'.($this->getWorkflowStem()->hasStatus !== null ? $this->getWorkflowStem()->hasEditorEmail : '').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

        $api->elementDel('workflowstem', $this->getWorkflowStemUri());
        $api->elementAdd('workflowstem', $workflowStemJson);
        \Drupal::messenger()->addMessage(t("Workflow Stem has been updated successfully."));
      }

      self::backUrl();
      return;

    }catch(\Exception $e){
      \Drupal::messenger()->addError(t("An error occurred while updating the Workflow Stem: ".$e->getMessage()));
      self::backUrl();
      return;
    }
  }

  public function retrieveWorkflowStem($componentStemUri) {
    $api = \Drupal::service('rep.api_connector');
    $rawresponse = $api->getUri($componentStemUri);
    $obj = json_decode($rawresponse);
    if ($obj->isSuccessful) {
      return $obj->body;
    }
    return NULL;
  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, \Drupal::request()->getRequestUri());
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}






