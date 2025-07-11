<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\AfterCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\std\Entity\Task;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Render\Markup;
use Drupal\file\Entity\File;

class EditTaskForm extends FormBase {

  protected $state;

  protected $task;

  protected $processUri;

  public function getState() {
    return $this->state;
  }
  public function setState($state) {
    return $this->state = $state;
  }

  public function getTask() {
    return $this->task;
  }
  public function setTask($task) {
    return $this->task = $task;
  }

  public function getProcessUri() {
    return $this->processUri;
  }
  public function setProcessUri($processUri) {
    return $this->processUri = $processUri;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_task_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $processuri=NULL, $state=NULL, $taskuri=NULL) {

    if (!isset($processuri) || !isset($state) || !isset($taskuri)) {
      \Drupal::messenger()->addMessage(t("Invalid parameters for Edit Task Form."), 'error');
      self::backUrl();
      return;
    }

    // INITIALIZE NS TABLE
    $tables = new Tables;
    $languages = $tables->getLanguages();

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'std/std_process';
    $form['#attached']['library'][] = 'core/drupal.dialog';

    // READ TASK
    $api = \Drupal::service('rep.api_connector');
    $uri_decode=base64_decode($taskuri);
    $task = $api->parseObjectResponse($api->getUri($uri_decode),'getUri');
    if ($task == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Task."));
      self::backUrl();
      return;
    } else {
      $this->setTask($task);
      $this->setProcessUri(base64_decode($processuri));
      //dpm($this->getTask());
    }

    // 1) Find Task Type
    $taskTypeUri = $this->getTask()->typeUri;
    $isAbstract = ($taskTypeUri === VSTOI::ABSTRACT_TASK);

    // 2) Define flags
    $showSubTasks     = $isAbstract;
    $showInstruments  = !$isAbstract;

    if ($state === 'init') {

      // Release values cached in the editor because sometimes the form crash and old values keep in the cache
      \Drupal::state()->delete('my_form_basic');
      \Drupal::state()->delete('my_form_instruments');
      \Drupal::state()->delete('my_form_tasks');

      // RESET STATE TO BASIC
      $state = 'basic';

      // POPULATE DATA STRUCTURES
      $basic = $this->populateBasic();
      $instruments = $this->populateInstruments();
      $tasks = $this->getTask()->hasSubtaskUris;

    } else {
      $basic = $this->populateBasic();;
      $instruments = \Drupal::state()->get('my_form_instruments', []);
      $tasks = $this->getTask()->hasSubtaskUris;

    }

    // SAVE STATE
    $this->setState($state);

    // SET SEPARATOR
    $separator = '<div class="w-100"></div>';

    $form['status_messages'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'subtask-messages'],
    ];

    $form['task_title'] = [
      '#type' => 'markup',
      '#markup' => '<h3 class="mt-5">Edit Task Form</h3>',
    ];

    $process = $api->parseObjectResponse($api->getUri($this->getProcessUri()), 'getUri');
    $form['process_label'] = [
      '#type' => 'markup',
      '#markup' => '<h4 class="text-secondary"><span class="text-dark">Process: </span>' . $process->label . '</h4>',
    ];

    $form['breakcrumb'] = [
      '#markup' => $this->buildBreadcrumb().'<br>',
    ];

    $form['current_state'] = [
      '#type' => 'hidden',
      '#value' => $state,
    ];

    // Container for pills and content.
    $form['pills_card'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['nav', 'nav-pills', 'nav-justified', 'mb-3'],
        'id' => 'pills-card-container',
        'role' => 'tablist',
      ],
    ];

    // Define pills as links with AJAX callback.
    $states = [
      'basic' => 'Basic task properties',
      'tasks' => 'Sub-Tasks',
      'instrument' => 'Instruments and Components',
    ];

    foreach ($states as $key => $label) {

      $form['pills_card'][$key] = [
        '#type' => 'button',
        '#value' => $label,
        '#name' => 'button_' . $key,
        '#attributes' => [
          'class' => ['nav-link', $state === $key ? 'active' : ''],
          'data-state' => $key,
          'role' => 'presentation',
        ],
        '#access' => ($key === 'basic')
                || ($key === 'tasks'      && $showSubTasks)
                || ($key === 'instrument' && $showInstruments),
        '#ajax' => [
          'callback' => '::pills_card_callback',
          'event' => 'click',
          'wrapper' => 'pills-card-container',
          'progress' => ['type' => 'none'],
        ],
      ];
    }

    // Add a hidden field to capture the current state.
    $form['state'] = [
      '#type' => 'hidden',
      '#value' => $state,
    ];

    /* ========================== BASIC ========================= */

    if ($this->getState() == 'basic') {

      $tasktype = '';
      if (isset($basic['tasktype'])) {
        $tasktype = $basic['tasktype'];
      }
      if (isset($basic['tasktemporaldependency'])) {
        $tasktemporalddependency = $basic['tasktemporaldependency'] ?? '';
      }
      $name = '';
      if (isset($basic['name'])) {
        $name = $basic['name'];
      }
      $language = '';
      if (isset($basic['language'])) {
        $language = $basic['language'];
      }
      $version = '1';
      if (isset($basic['version'])) {
        $version = $basic['version'];
      }
      $description = '';
      if (isset($basic['description'])) {
        $description = $basic['description'];
      }
      $webDocument = '';
      if (isset($basic['webdocument'])) {
        $webDocument = $basic['webdocument'];
      }
      $status = '';
      if (isset($basic['status'])) {
        $status = $basic['status'];
      }
      $typeuri = '';
      if (isset($basic['typeUri'])) {
        $typeuri = $basic['typeUri'];
      }

      $form['task_tasktype_hid'] = [
        'top' => [
          '#type' => 'markup',
          '#markup' => '<div class="pt-3 col border border-white">',
        ],
        'main' => [
          '#type' => 'textfield',
          '#title' => $this->t('Task Type'),
          '#name' => 'task_tasktype',
          '#default_value' => $tasktype,
          '#id' => 'task_tasktype',
          '#parents' => ['task_tasktype'],
          '#attributes' => [
            'disabled' => true,
            'class' => ['open-tree-modal'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => json_encode(['width' => 800]),
            'data-url' => Url::fromRoute('rep.tree_form', [
              'mode' => 'modal',
              'elementtype' => 'task',
            ], ['query' => ['field_id' => 'task_tasktype']])->toString(),
            'data-field-id' => 'task_tasktype',
            'data-elementtype' => 'task',
            'autocomplete' => 'off',
          ],
        ],
        'bottom' => [
          '#type' => 'markup',
          '#markup' => '</div>',
        ],
      ];

      $form['task_tasktype'] = [
        '#type' => 'hidden',
        '#value' => $tasktype,
      ];

      // Only If is Abstract Type
      if ($isAbstract) {
        $form['task_tasktemporaldependency'] = [
          'top' => [
            '#type' => 'markup',
            '#markup' => '<div class="col border border-white">',
          ],
          'main' => [
            '#type' => 'textfield',
            '#title' => $this->t('Task Temporal Dependency'),
            '#name' => 'task_tasktemporaldependency',
            '#default_value' => $tasktemporalddependency,
            '#id' => 'task_tasktemporaldependency',
            '#parents' => ['task_tasktemporaldependency'],
            '#attributes' => [
              'class' => ['open-tree-modal'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => json_encode(['width' => 800]),
              'data-url' => Url::fromRoute('rep.tree_form', [
                'mode' => 'modal',
                'elementtype' => 'tasktemporaldependency',
              ], ['query' => ['field_id' => 'task_tasktemporaldependency']])->toString(),
              'data-field-id' => 'task_tasktemporaldependency',
              'data-elementtype' => 'tasktemporaldependency',
              'autocomplete' => 'off',
            ],
          ],
          'bottom' => [
            '#type' => 'markup',
            '#markup' => '</div>',
          ],
        ];
      }

      $form['task_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => $name,
        '#required' => true
      ];
      $form['task_language'] = [
        '#type' => 'select',
        '#title' => $this->t('Language'),
        '#options' => $languages,
        '#default_value' => $language,
        '#disabled' => true,
      ];
      $form['task_version_hid'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Version'),
        '#default_value' => $version,
        '#disabled' => true
      ];
      $form['task_version'] = [
        '#type' => 'hidden',
        '#value' => $version,
      ];
      $form['task_description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => $description,
        // '#required' => true
      ];
      // -------------- WebDocument --------------
      $task_webdocument = $webDocument ?? '';
      $webdocument_type = '';
      if ($task_webdocument && str_starts_with(trim($task_webdocument),'http')) {
        $webdocument_type = 'url';
      }
      elseif ($task_webdocument) {
        $webdocument_type = 'upload';
      }

      $form['task_webdocument_type'] = [
        '#type'   => 'select',
        '#title'  => $this->t('Web Document Type'),
        '#options'=> [
          ''       => $this->t('Select Document Type'),
          'url'    => $this->t('URL'),
          'upload' => $this->t('Upload'),
        ],
        '#default_value' => $webdocument_type,
      ];

      $form['task_webdocument_url'] = [
        '#type'   => 'textfield',
        '#title'  => $this->t('Web Document'),
        '#default_value' => $webdocument_type === 'url' ? $task_webdocument : '',
        '#attributes' => ['placeholder' => 'http://'],
        '#states' => [
          'visible' => [
            ':input[name="task_webdocument_type"]' => ['value' => 'url'],
          ],
        ],
      ];

      $form['task_webdocument_upload_wrapper'] = [
        '#type' => 'container',
        '#states' => [
          'visible' => [
            ':input[name="task_webdocument_type"]' => ['value' => 'upload'],
          ],
        ],
      ];

      $form['task_webdocument_upload_wrapper']['task_webdocument_upload'] = [
        '#type'            => 'managed_file',
        '#title'           => $this->t('Upload Document'),
        '#upload_location' => 'private://resources/'. Utils::namespaceUri($this->getProcessUri()) .'/webdoc',
        '#upload_validators' => [
          'file_validate_extensions' => ['pdf doc docx txt xls xlsx'],
          'file_validate_size'       => [2097152],
        ],
        '#description' => Markup::create(
          '<span style="color:red;">pdf, doc, docx, txt, xls, xlsx. '.
          $this->t('Selecting a new document will remove the previous one.').'</span>'
        ),
      ];
      $form['task_status'] = [
        '#type' => 'hidden',
        '#value' => $status,
      ];
      $form['task_typeuri'] = [
        '#type' => 'hidden',
        '#value' => $typeuri,
      ];

    }

    if ($showInstruments && $this->getState() == 'instrument') {

      /*
      *      INSTRUMENTS
      */

      // $form['instruments_title'] = [
      //   '#type' => 'markup',
      //   '#markup' => 'Instruments',
      // ];

      // Wrap the instruments block in a container with an ID we can replace.
      $form['instruments'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => [
          'class' => ['p-3','bg-light','text-dark','row','border','border-secondary','rounded'],
          'id' => 'instrument-wrapper',
        ],
      ];

      // 1) Header row
      $form['instruments']['header'] = [
        '#type' => 'markup',
        '#markup' =>
          '<div class="row mb-2">' .
            '<div class="col bg-secondary text-white p-2">Instrument</div>' .
            '<div class="col bg-secondary text-white p-2 ps-4">Components</div>' .
            '<div class="col-md-1 bg-secondary text-white p-2 ps-4">Operations</div>' .
          '</div>',
      ];

      // 2) Data rows
      $form['instruments']['rows'] = $this->renderInstrumentRows($instruments);

      // 3) Button numa nova row full-width
      $form['instruments']['add_row'] = [
        '#type' => 'submit',
        '#value' => $this->t('New Instrument'),
        '#name' => 'new_instrument',
        '#limit_validation_errors' => [],
        '#submit' => ['::onAddInstrumentRow'],
        '#ajax' => [
          'callback' => '::ajaxAddInstrumentRow',
          'wrapper'  => 'instrument-wrapper',
          'effect'   => 'fade',
        ],
        '#attributes' => ['class' => ['btn','btn-primary', 'col-1', 'mt-2','add-element-button']],
      ];

      $form['instruments']['actions']['bottom_space'] = [
        '#type'   => 'markup',
        '#markup' => '<div class="w-100"></div>',
      ];

    }

    /* ======================= TASKS ======================= */

    if ($showSubTasks && $this->getState() == 'tasks') {

      // *
      // *      TASKS
      // *

      // 1) Make this container preserve its hierarchy
      $form['subtasks'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => [
          'id' => 'subtasks-wrapper',
          'class' => ['p-3','bg-light','rounded','row'],
        ],
      ];

      $form['subtasks']['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['d-flex','justify-content-between','align-items-center','mb-3']],
      ];

      // Tell Drupal “this string is already safe HTML”
      // $form['subtasks']['header']['title'] = [
      //   '#markup' => $this->buildBreadcrumb(),
      // ];

      // 2) Same for o mini-form
      $form['subtasks']['new_subtask_form'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => [
          'class' => ['d-flex', 'align-items-center', 'mb-3', 'mt-2'],
        ],
      ];

      $form['subtasks']['new_subtask_form']['subtask_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('New Sub-Task Name'),
        // '#required' => TRUE,
        '#attributes' => [
          'class' => ['me-2']
        ],
      ];

      $form['subtasks']['new_subtask_form']['subtask_type'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Task Type'),
        '#prefix'        => '<div class="col-md-3 ms-3">',
        '#suffix'        => '</div>',
        '#default_value' => '',
        '#id'            => 'subtask_type',
        '#attributes'    => [
          'class'               => ['open-tree-modal'],
          'data-dialog-type'    => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url'            => Url::fromRoute('rep.tree_form', [
                                    'mode'        => 'modal',
                                    'elementtype' => 'task',
                                  ], ['query' => ['field_id' => 'subtask_type']])
                                  ->toString(),
          'data-field-id'       => 'subtask_type',
          'data-elementtype'    => 'task',
          'autocomplete'        => 'off',
        ],
      ];

      $form['subtasks']['new_subtask_form']['actions']['create_subtask'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create Sub-Task'),
        '#limit_validation_errors' => [
          ['subtasks', 'new_subtask_form', 'subtask_name'],
          ['subtasks', 'new_subtask_form', 'subtask_type'],
        ],
        '#validate' => ['::validateSubtaskName'],
        '#submit'   => ['::createSubtaskSubmit'],
        '#ajax' => [
          'callback' => '::ajaxSubtasksCallback',
          'wrapper'  => 'subtasks-wrapper',
          'effect'   => 'fade',
        ],
        // DESATIVA o botão até as duas condições serem verdadeiras.
        '#states' => [
          // Só habilita quando name **e** type estiverem “filled”
          'enabled' => [
            ':input[name="subtasks[new_subtask_form][subtask_name]"]' => ['filled' => TRUE],
            ':input[name="subtasks[new_subtask_form][subtask_type]"]' => ['filled' => TRUE],
          ],
        ],
        '#attributes' => [
          'class' => ['mt-2', 'ms-2', 'add-element-button'],
        ],
      ];

      $form['subtasks']['table'] = [
        '#type' => 'table',
        '#header' => Task::generateHeader(),
        '#rows'   => Task::generateOutput($tasks,base64_encode($this->getProcessUri()))['output'],
        '#empty'  => $this->t('No records found'),
        '#attributes' => ['class'=>['table','table-striped']],
      ];
    }

    /* ======================= COMMON BOTTOM ======================= */

    $form['space'] = [
      '#type' => 'markup',
      '#markup' => '<br><br>',
    ];

    $form['save_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and Go to ' . ($this->getTask()->hasSupertaskUri === null ? 'Process':' Parent Task')),
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

    //$form['#attached']['library'][] = 'std/std_list';

    return $form;
  }

  public function pills_card_callback(array &$form, FormStateInterface $form_state) {

    // RETRIEVE CURRENT STATE AND SAVE IT ACCORDINGLY
    $currentState = $form_state->getValue('state');
    $process = base64_encode($this->getProcessUri());

    if ($currentState == 'basic') {
      $this->updateBasic($form_state);
    }
    if ($currentState == 'instrument') {
      $this->updateInstruments($form_state);
    }
    if ($currentState == 'tasks') {
     $this->updateCodes($form_state);
    }

    // Need to retrieve $basic because it contains the task's URI
    $basic = \Drupal::state()->get('my_form_basic');
    $instruments = \Drupal::state()->get('my_form_instruments');

    // RETRIEVE FUTURE STATE
    $triggering_element = $form_state->getTriggeringElement();
    $parts = explode('_', $triggering_element['#name']);
    $state = (isset($parts) && is_array($parts)) ? end($parts) : null;

    // BUILD NEW URL
    $root_url = \Drupal::request()->getBaseUrl();
    $newUrl = $root_url . REPGUI::EDIT_TASK . '/' . $process . '/' . $state . '/' . base64_encode($this->getTask()->uri);

    // REDIRECT TO NEW URL
    $response = new AjaxResponse();
    $response->addCommand(new RedirectCommand($newUrl));

    return $response;
  }

  /******************************
   *
   *    BASIC'S FUNCTIONS
   *
   ******************************/

  /**
   * {@inheritdoc}
   */
  public function updateBasic(FormStateInterface $form_state) {
    $basic = \Drupal::state()->get('my_form_basic');
    $input = $form_state->getUserInput();

    if (isset($input) && is_array($input) &&
        isset($basic) && is_array($basic)) {
      $basic['tasktype'] = $input['task_tasktype'] ?? UTILS::fieldToAutocomplete($this->getTask()->typeUri, $this->getTask()->typeLabel);
      $basic['tasktemporaldependency'] = $input['task_tasktemporaldependency'] ?? ($basic['tasktemporaldependency'] ?? UTILS::fieldToAutocomplete($this->getTask()->hasTemporalDependency, $this->getTask()->temporalDependencyLabel));
      $basic['name']        = $input['task_name'] ?? $this->getTask()->label;
      $basic['language']    = $input['task_language'] ?? $this->getTask()->hasLanguage;
      $basic['version']     = $input['task_version'] ?? $this->getTask()->hasVersion;
      $basic['description'] = $input['task_description'] ?? $this->getTask()->comment;
      $basic['webdocument'] = $input['task_webdocument'] ?? $this->getTask()->hasWebDocument;
      $basic['status'] = $input['task_status'] ?? $this->getTask()->hasStatus;
      $basic['typeUri'] = $input['task_typeuri'] ?? $this->getTask()->typeUri;
      \Drupal::state()->set('my_form_basic', $basic);
    }
    $response = new AjaxResponse();
    return $response;
  }

  public function populateBasic() {

    $basic = [
      'uri' => $this->getTask()->uri,
      'tasktype' => UTILS::fieldToAutocomplete($this->getTask()->typeUri,$this->getTask()->typeLabel),
      'tasktemporaldependency' => UTILS::fieldToAutocomplete($this->getTask()->hasTemporalDependency, $this->getTask()->temporalDependencyLabel),
      'name' => $this->getTask()->label,
      'language' => $this->getTask()->hasLanguage,
      'version' => $this->getTask()->hasVersion,
      'description' => $this->getTask()->comment,
      'webdocument' => $this->getTask()->hasWebDocument,
      'status' => $this->getTask()->hasStatus
    ];
    \Drupal::state()->set('my_form_basic', $basic);
    return $basic;
  }

  /******************************
   *
   *    instruments' FUNCTIONS
   *
   ******************************/

  /**
   * Render all instrument rows, always showing the full component list,
   * with any previously selected components still checked.
   *
   * @param array $instruments
   *   Array of instruments, each item:
   *     - instrument: autocomplete label
   *     - components:   array of URIs that were selected
   *
   * @return array
   *   Render array for the rows.
   */
  protected function renderInstrumentRows(array $instruments) {
    $rows = [];
    $separator = '<div class="w-100"></div>';

    foreach ($instruments as $delta => $instrument) {
      // Decode URI from autocomplete label.
      $instrument_uri = Utils::uriFromAutocomplete($instrument['instrument']);

      // Always fetch full component list.
      $components = $instrument_uri
        ? $this->getComponents($instrument_uri)
        : [];

      // Load persisted selections.
      $selected = $instrument['components'] ?? [];

      // Build the component table (always, even if $selected is empty).
      $component_table = $this->buildComponentTable(
        $components,
        'instrument_components_' . $delta,
        $selected
      );

      // Build the instrument field with your existing modal settings.
      $instrument_field = [
        '#type' => 'textfield',
        '#name' => "instrument_instrument_$delta",
        '#id' => "instrument_instrument_$delta",
        '#value' => $instrument['instrument'],
        '#attributes' => [
          'class' => ['open-tree-modal'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url' => Url::fromRoute('rep.tree_form', [
            'mode' => 'modal',
            'elementtype' => 'instrument',
          ], ['query' => ['field_id' => "instrument_instrument_$delta"]])->toString(),
          'data-field-id' => "instrument_instrument_$delta",
          'data-search-value' => Html::escape($instrument_uri),
          'data-elementtype' => 'instrument',
          'autocomplete' => 'off',
        ],
        '#autocomplete' => 'off',
        '#ajax' => [
          'callback' => '::addComponentCallback',
          'event'    => 'change',
          'wrapper'  => 'instrument_components_' . $delta,
          'method'   => 'replaceWith',
          'effect'   => 'fade',
        ],
      ];

      // Assemble the row.
      $rows[] = [
        "row$delta" => [
          'instrument' => [
            'top'   => ['#markup' => '<div class="pt-3 col border border-white">'],
            'field' => $instrument_field,
            'bottom'=> ['#markup' => '</div>'],
          ],
          'components' => [
            'top'   => ['#markup' => '<div class="pt-3 col border border-white">'],
            // Ensure '#tree' so selections persist:
            'instrument_components_' . $delta => array_merge(
              ['#type' => 'container', '#attributes' => ['id' => 'instrument_components_' . $delta], '#tree' => TRUE],
              $component_table
            ),
            'bottom'=> ['#markup' => '</div>'],
          ],
          'operations' => [
            'top'   => ['#markup' => '<div class="pt-3 col-md-1 border border-white">'],
            'remove'=> [
              '#type' => 'submit',
              '#name' => "instrument_remove_$delta",
              '#value'=> $this->t('Remove'),
              '#attributes' => [
                'class' => ['remove-row','btn','btn-sm','btn-danger','delete-element-button'],
              ],
            ],
            'bottom'=> ['#markup' => '</div>' . $separator],
          ],
        ],
      ];
    }

    return $rows;
  }

  public function addComponentCallback(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $delta = str_replace('instrument_instrument_', '', $triggering_element['#name']);
    $container_id = 'instrument_components_' . $delta;
    $input = $form_state->getUserInput();
    $instrumentURI = $input['instrument_instrument_' . $delta] ?? '';
    $instrument_uri = Utils::uriFromAutocomplete($instrumentURI);
    $instruments = \Drupal::state()->get('my_form_instruments');

    $response = new AjaxResponse();

    // Always remove any previous error messages
    $response->addCommand(new RemoveCommand('.instrument-error-message'));

    // Check if the instrument has already been added
    $filtered = array_filter($instruments, fn($instrument) => $instrument['instrument'] === $instrumentURI);
    if (!empty($filtered)) {
        $form_state->setValue('instrument_instrument_' . $delta, '');
        $form_state->setRebuild(TRUE);

        // Clear the input field in the frontend
        $response->addCommand(new InvokeCommand('input[name="instrument_instrument_' . $delta . '"]', 'val', ['']));

        // Display the error message below the input field
        $response->addCommand(new AfterCommand(
            'input[name="instrument_instrument_' . $delta . '"]',
            '<div class="instrument-error-message text-danger" style="margin-top:10px; margin-left:5px;">' .
            $this->t('You already have "@instrument" in this list.', ['@instrument' => $instrumentURI]) .
            '</div>'
        ));

        return $response;
    }

    // Check if container exists
    if (!isset($form['instruments']['rows'][$delta]['row'.$delta]['components'][$container_id])) {
        \Drupal::logger('custom_module')->error('Container not found for delta: @delta', ['@delta' => $delta]);
        return [
            '#markup' => $this->t('Error: Container not found for delta @delta.', ['@delta' => $delta]),
        ];
    }

    // Get components from API
    $components = $this->getComponents($instrument_uri);

    // Add components to instrument
    $this->updateInstruments($form_state);

    // Render components
    // $componentTable = $this->buildComponentTable($components, $container_id);
    $componentTable = $this->buildComponentTable(
      $components,
      $container_id,
      \Drupal::state()->get('my_form_instruments')[$delta]['components'] ?? []
    );

    // Replace the existing component container with the updated table
    $response->addCommand(new ReplaceCommand('#' . $container_id, $componentTable));

    return $response;
  }

  protected function updateInstruments(FormStateInterface $form_state) {
    $instruments = \Drupal::state()->get('my_form_instruments', []);
    $input = $form_state->getUserInput();

    foreach ($instruments as $instrument_id => $instrument) {
      $instruments[$instrument_id]['instrument'] =
        $input['instrument_instrument_' . $instrument_id] ?? '';

      $compKey = 'instrument_components_' . $instrument_id;

      $selectedComponents = [];
      if (isset($input[$compKey]) && is_array($input[$compKey])) {
        $selectedComponents = $input[$compKey];
      }

      $instruments[$instrument_id]['components'] = $selectedComponents;
    }

    \Drupal::state()->set('my_form_instruments', $instruments);
  }

  protected function populateInstruments() {
    $required = $this->getTask()->requiredInstrument;
    $instrumentData = [];
    foreach ($required as $reqInstr) {
      $uri   = $reqInstr->instrument->uri;
      $label = $reqInstr->instrument->label;
      $components = [];
      if (!empty($reqInstr->components) && is_array($reqInstr->components)) {
        foreach ($reqInstr->components as $c) {
          $components[] = $c->uri;
        }
      }
      $instrumentData[] = [
        'instrument' => UTILS::fieldToAutocomplete($uri, $label),
        'components' => $components,
      ];
    }
    \Drupal::state()->set('my_form_instruments', $instrumentData);
    return $instrumentData;
  }

  protected function saveInstruments(string $taskUri, array $instruments) {
    $requiredInstrument = [];
    foreach ($instruments as $inst) {
      $instrumentUri = Utils::uriFromAutocomplete($inst['instrument']);
      if (!$instrumentUri) {
        \Drupal::logger('std')->warning('Ignorando instrumento sem URI: @label', ['@label' => $inst['instrument']]);
        continue;
      }
      $requiredComponents = array_map(
        fn($compUri) => [
          // 'slotUri' => $slotUri
          'componentUri' => $compUri],
        $inst['components'] ?? []
      );
      $requiredInstrument[] = [
        'instrumentUri'      => $instrumentUri,
        'requiredComponent' => $requiredComponents,
      ];
    }

    $payload = [
      'taskuri'            => $taskUri,
      'requiredInstrument' => $requiredInstrument,
    ];

    \Drupal::logger('std')->notice('» saveInstruments payload: <pre>@json</pre>', [
      '@json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ]);

    /** @var \Drupal\std\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');

    try {
      $response = $api->taskSetRequiredInstruments($payload);

      // 3) Log da resposta decodificada e crua
      \Drupal::logger('std')->notice('« API response (decoded): <pre>@resp</pre>', [
        '@resp' => print_r($response, TRUE),
      ]);

      // Se vier string, log também
      if (is_string($response)) {
        \Drupal::logger('std')->notice('« API raw response: @raw', ['@raw' => $response]);
      }

      \Drupal::messenger()->addStatus($this->t('Instrumentos enviados, verifique logs para detalhes.'));
    }
    catch (\Exception $e) {
      \Drupal::logger('std')->error('Erro em saveInstruments(): @msg', ['@msg' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Falha ao guardar instrumentos, veja os logs.'));
    }
  }

  /**
   * Push a new empty instrument into storage and flag a rebuild.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function addInstrumentRow(FormStateInterface $form_state) {
    $instruments = \Drupal::state()->get('my_form_instruments') ?? [];
    $instruments[] = ['instrument'=>'', 'components'=>[]];
    \Drupal::state()->set('my_form_instruments', $instruments);
    $form_state->setRebuild(TRUE);
  }

  public function removeInstrumentRow($button_name) {
    $instruments = \Drupal::state()->get('my_form_instruments') ?? [];

    // from button name's value, determine which row to remove.
    $parts = explode('_', $button_name);
    $instrument_to_remove = (isset($parts) && is_array($parts)) ? (int) (end($parts)) : null;

    if (isset($instrument_to_remove) && $instrument_to_remove > -1) {
      unset($instruments[$instrument_to_remove]);
      $instruments = array_values($instruments);
      \Drupal::state()->set('my_form_instruments', $instruments);
    }
    return;
  }

  /******************************
   *
   *    SUBTASK'S FUNCTIONS
   *
   ******************************/

  protected function renderSubTasks(array $subtasks) {
    $form_rows = TASK::generateOutput($subtasks, base64_encode($this->getProcessUri()));

    return $form_rows;
  }

  /******************************
   *
   *    CODE'S FUNCTIONS
   *
   ******************************/


   protected function renderCodeRows(array $codes) {
    $form_rows = [];
    $separator = '<div class="w-100"></div>';
    foreach ($codes as $delta => $code) {

      $form_row = array(
        'column' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col border border-white">',
          ),
          'main' => array(
            '#type' => 'textfield',
            '#name' => 'code_column_' . $delta,
            '#default_value' => $code['column'],
          ),
          'bottom' => array(
            '#type' => 'markup',
            '#markup' => '</div>',
          ),
        ),
        'code' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col border border-white">',
          ),
          'main' => array(
            '#type' => 'textfield',
            '#name' => 'code_code_' . $delta,
            '#default_value' => $code['code'],
          ),
          'bottom' => array(
            '#type' => 'markup',
            '#markup' => '</div>',
          ),
        ),
        'label' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col border border-white">',
          ),
          'main' => array(
            '#type' => 'textfield',
            '#name' => 'code_label_' . $delta,
            '#default_value' => $code['label'],
          ),
          'bottom' => array(
            '#type' => 'markup',
            '#markup' => '</div>',
          ),
        ),
        'class' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col border border-white">',
          ),
          'main' => array(
            '#type' => 'textfield',
            '#name' => 'code_class_' . $delta,
            '#default_value' => $code['class'],
          ),
          'bottom' => array(
            '#type' => 'markup',
            '#markup' => '</div>',
          ),
        ),
        'operations' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col-md-1 border border-white">',
          ),
          'main' => array(
            '#type' => 'submit',
            '#name' => 'code_remove_' . $delta,
            '#value' => $this->t('Remove'),
            '#attributes' => array(
              'class' => array('remove-row', 'btn', 'btn-sm', 'delete-element-button'),
              'id' => 'code-' . $delta,
            ),
          ),
          'bottom' => array(
            '#type' => 'markup',
            '#markup' => '</div>' . $separator,
          ),
        ),
      );

      $rowId = 'row' . $delta;
      $form_rows[] = [
        $rowId => $form_row,
      ];

    }
    return $form_rows;
  }

  protected function updateCodes(FormStateInterface $form_state) {
    $codes = \Drupal::state()->get('my_form_tasks');
    $input = $form_state->getUserInput();
    if (isset($input) && is_array($input) &&
        isset($codes) && is_array($codes)) {

      foreach ($codes as $code_id => $code) {
        if (isset($code_id) && isset($code)) {
          $codes[$code_id]['column']  = $input['code_column_' . $code_id] ?? '';
          $codes[$code_id]['code']    = $input['code_code_' . $code_id] ?? '';
          $codes[$code_id]['label']   = $input['code_label_' . $code_id] ?? '';
          $codes[$code_id]['class']   = $input['code_class_' . $code_id] ?? '';
        }
      }
      \Drupal::state()->set('my_form_tasks', $codes);
    }
    return;
  }

  protected function populateCodes($namespaces) {
    $codes = [];
    $possibleValues = $this->getTask()->possibleValues;
    if (count($possibleValues) > 0) {
      foreach ($possibleValues as $possibleValue_id => $possibleValue) {
        if (isset($possibleValue_id) && isset($possibleValue)) {
          $listPosition = $possibleValue->listPosition;
          $codes[$listPosition]['column']  = $possibleValue->isPossibleValueOf;
          $codes[$listPosition]['code']    = $possibleValue->hasCode;
          $codes[$listPosition]['label']   = $possibleValue->hasCodeLabel;
          $codes[$listPosition]['class']   = Utils::namespaceUriWithNS($possibleValue->hasClass,$namespaces);
        }
      }
      ksort($codes);
    }
    \Drupal::state()->set('my_form_tasks', $codes);
    return $codes;
  }

  protected function saveCodes($taskUri, array $codes) {
    if (!isset($taskUri)) {
      \Drupal::messenger()->addError(t("No task's URI have been provided to save possible values."));
      return;
    }
    if (!isset($codes) || !is_array($codes)) {
      \Drupal::messenger()->addWarning(t("Task has no possible values to be saved."));
      return;
    }

    foreach ($codes as $code_id => $code) {
      if (isset($code_id) && isset($code)) {
        try {
          $useremail = \Drupal::currentUser()->getEmail();

          $column = ' ';
          if ($codes[$code_id]['column'] != NULL && $codes[$code_id]['column'] != '') {
            $column = $codes[$code_id]['column'];
          }

          $codeStr = ' ';
          if ($codes[$code_id]['code'] != NULL && $codes[$code_id]['code'] != '') {
            $codeStr = $codes[$code_id]['code'];
          }

          $codeLabel = ' ';
          if ($codes[$code_id]['label'] != NULL && $codes[$code_id]['label'] != '') {
            $codeLabel = $codes[$code_id]['label'];
          }

          $class = ' ';
          if ($codes[$code_id]['class'] != NULL && $codes[$code_id]['class'] != '') {
            $class = $codes[$code_id]['class'];
          }

          $codeUri = str_replace(
            Constant::PREFIX_SEMANTIC_DATA_DICTIONARY,
            Constant::PREFIX_POSSIBLE_VALUE,
            $taskUri) . '/' . $code_id;
          $codeJSON = '{"uri":"'. $codeUri .'",'.
              '"superUri":"'.HASCO::POSSIBLE_VALUE.'",'.
              '"hascoTypeUri":"'.HASCO::POSSIBLE_VALUE.'",'.
              '"partOfSchema":"'.$taskUri.'",'.
              '"listPosition":"'.$code_id.'",'.
              '"isPossibleValueOf":"'.$column.'",'.
              '"label":"'.$column.'",'.
              '"hasCode":"' . $codeStr . '",' .
              '"hasCodeLabel":"' . $codeLabel . '",' .
              '"hasClass":"' . $class . '",' .
              '"comment":"Possible value ' . $column . ' of ' . $column . ' of SDD ' . $taskUri . '",'.
              '"hasSIRManagerEmail":"'.$useremail.'"}';
          $api = \Drupal::service('rep.api_connector');
          $api->elementAdd('possiblevalue',$codeJSON);

          //dpm($codeJSON);

        } catch(\Exception $e){
          \Drupal::messenger()->addError(t("An error occurred while saving possible value(s): ".$e->getMessage()));
        }
      }
    }
    return;
  }

  public function addCodeRow() {
    $codes = \Drupal::state()->get('my_form_tasks') ?? [];

    // Add a new row to the table.
    $codes[] = [
      'column' => '',
      'code' => '',
      'label' => '',
      'class' => '',
    ];
    \Drupal::state()->set('my_form_tasks', $codes);

    // Rebuild the table rows.
    $form['codes']['rows'] = $this->renderCodeRows($codes);
    return;
  }

  public function removeCodeRow($button_name) {
    $codes = \Drupal::state()->get('my_form_tasks') ?? [];

    // from button name's value, determine which row to remove.
    $parts = explode('_', $button_name);
    $code_to_remove = (isset($parts) && is_array($parts)) ? (int) (end($parts)) : null;

    if (isset($code_to_remove) && $code_to_remove > -1) {
      unset($codes[$code_to_remove]);
      $codes = array_values($codes);
      \Drupal::state()->set('my_form_tasks', $codes);
    }
    return;
  }

  /* ================================================================================ *
   *
   *                                 SUBMIT FORM
   *
   * ================================================================================ */

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // IDENTIFY NAME OF BUTTON triggering submitForm()
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      // Release values cached in the editor before leaving it
      \Drupal::state()->delete('my_form_basic');
      \Drupal::state()->delete('my_form_instruments');
      \Drupal::state()->delete('my_form_tasks');
      self::backUrl();
      return false;
    }

    // Delete a sub-task
    if (str_starts_with($button_name, 'subtask_remove_')) {
      $encoded = substr($button_name, strlen('subtask_remove_'));
      $uri = base64_decode($encoded);
      \Drupal::service('rep.api_connector')->elementDel('task', $uri);
      \Drupal::messenger()->addStatus($this->t('Sub-task removida.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // If not leaving then UPDATE STATE OF VARIABLES, OBJECTS AND CODES
    // according to the current state of the editor
    if ($this->getState() === 'basic') {
      $this->updateBasic($form_state);
    }

    $this->updateInstruments($form_state);

    // Basic update
    $basic = \Drupal::state()->get('my_form_basic');
    $instruments = \Drupal::state()->get('my_form_instruments', []);
    $tasks = \Drupal::state()->get('my_form_tasks');

    // if ($button_name === 'new_instrument') {
    //   $this->addInstrumentRow($form_state);
    //   return;
    // }

    if (str_starts_with($button_name,'instrument_remove_')) {
      $this->removeInstrumentRow($button_name);
      return;
    }

    if ($button_name === 'save') {

      $errors = false;

      //VALIDATIONS BEFORE SUBMIT PREVENT THE USE OF validationForm core....
      $basic = \Drupal::state()->get('my_form_basic');

      if (!empty($basic)) {

        if(strlen($basic['name']) < 1 || strlen($basic['tasktype']) < 1 ) {
          $errors = true;
          \Drupal::messenger()->addError(t("Mandatory fields are required to be filled! Check 'Basic Task Porperties Tab'"));
        }
      } else {
        if(strlen($submitted_values['task_name']) < 1) {
          $form_state->setErrorByName(
            'task_name',
            \Drupal::messenger()->addError(t('Please enter a valid name for the Simulation Task'))
          );
        }
        if(strlen($submitted_values['task_taskstem']) < 1) {
          $form_state->setErrorByName(
            'task_taskstem',
            \Drupal::messenger()->addError(t('Please select a valid Task Stem'))
          );
        }
        if(strlen($submitted_values['task_description']) < 1) {
          $form_state->setErrorByName(
            'task_description',
            \Drupal::messenger()->addError(t('Please enter a description'))
          );
        }
      }

      //TRY TO RELOAD....
      if ($errors) {
        $form_state->setRebuild(TRUE);

      } else {

        $api = \Drupal::service('rep.api_connector');

        // ------------------------------------------------ WebDocument
        $doc_type = $form_state->getValue('task_webdocument_type');
        $task_webdocument = $basic['webdocument'];   // valor actual

        if ($doc_type === 'url') {
          $task_webdocument = $form_state->getValue('task_webdocument_url');

        } elseif ($doc_type === 'upload') {
          $fids = $form_state->getValue('task_webdocument_upload');
          if ($fids) {
            $file = File::load(reset($fids));
            if ($file) {
              $file->setPermanent();
              $file->save();
              \Drupal::service('file.usage')->add($file, 'sir', 'task', 1);
              $task_webdocument = $file->getFilename();

              if ($task_webdocument !== $this->getTask()->hasWebDocument) {
                $api->parseObjectResponse(
                  $api->uploadFile($this->getTask()->uri, $file->id()),
                  'uploadFile'
                );
              }
            }
          }
        }

        // dpm($basic);return false;

        try {
          $useremail = \Drupal::currentUser()->getEmail();

          $taskData = [
            'uri'                   => $this->getTask()->uri,
            'typeUri'               => UTILS::uriFromAutocomplete($basic['tasktype']),
            'hascoTypeUri'          => VSTOI::TASK,
            'hasTemporalDependency' =>
              ($this->getTask()->typeUri === VSTOI::ABSTRACT_TASK
                ? Utils::uriFromAutocomplete($basic['tasktemporaldependency'])
                : ''),
            'hasStatus'             => $this->getTask()->hasStatus,
            'label'                 => $basic['name'],
            'hasLanguage'           => $this->getTask()->hasLanguage,
            'hasVersion'            => $this->getTask()->hasVersion,
            'hasSupertaskUri'       => $this->getTask()->hasSupertaskUri,
            'comment'               => $basic['description'],
            'hasWebDocument'        => $task_webdocument,
            'hasSubtaskUris'        => $this->getTask()->hasSubtaskUris,
            'hasSIRManagerEmail'    => $useremail,
          ];

          // dpm(json_encode($taskData)); return false;

          $taskJSON = json_encode($taskData);

          // Delete the task before updating it
          // This is necessary because the task is not updated
          // dpm($taskJSON);
          // dpm($basic['name']);return false;
          $api->elementDel('task',$this->getTask()->uri);

          // In order to update the task it is necessary to
          // add the following to the task: the task itself, its
          // instruments, its components and its codes
          $api->elementAdd('task',$taskJSON);

          // Update the task's instruments to ensure last AJAX has been proccessed
          if ($this->getTask()->typeUri !== VSTOI::ABSTRACT_TASK) {
            $this->saveInstruments($this->getTask()->uri, $instruments);
          }

          // Release values cached in the editor
          \Drupal::state()->delete('my_form_basic');
          \Drupal::state()->delete('my_form_instruments');
          \Drupal::state()->delete('my_form_tasks');

          \Drupal::messenger()->addMessage(t("Task has been updated successfully."));
          self::backUrl();
          return;

        } catch(\Exception $e){
          \Drupal::messenger()->addMessage(t("An error occurred while updating a task: ".$e->getMessage()));
          self::backUrl();
          return false;
        }
        return false;
      }
    }

  }

  /**
   * Override the validator so that the Cancel/Back button skips all validation.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#name']) && $trigger['#name'] === 'back') {
      return;
    }
  }

  public function getComponents($instrumentUri) {
    $root_url = \Drupal::request()->getBaseUrl();
    // Call to get Components
    $api = \Drupal::service('rep.api_connector');
    $response = $api->componentListFromInstrument($instrumentUri);

    // Decode JSON reply
    $data = json_decode($response, true);
    if (!$data || !isset($data['body'])) {
      return [];
    }

    // Decode Body
    $urls = json_decode($data['body'], true);

    // Task components
    $components = [];
    foreach ($urls as $url) {
      $componentData = $api->getUri($url);
      $obj = json_decode($componentData);
      $components[] = [
        'name' => isset($obj->body->label) ? $obj->body->label : '',
        'uri' => isset($obj->body->uri) ? $obj->body->uri : '',
        'type' => isset($obj->body->hascoTypeUri) ? Utils::namespaceUri($obj->body->hascoTypeUri) : '',
        'status' => isset($obj->body->hasStatus) ? Utils::plainStatus($obj->body->hasStatus) : '',
        'hasStatus' => isset($obj->body->hasStatus) ? $obj->body->hasStatus : null,
      ];
    }
    return $components;
  }

  /**
   * Build a components table grouped by their 'type' field.
   *
   * @param array $components
   *   A list of component arrays, each containing:
   *     - name:   string label
   *     - uri:    string URI
   *     - type:   string type name
   *     - status: string status label
   * @param string $container_id
   *   The HTML id attribute for the outer container.
   * @param array $arraySelected
   *   An array of URIs that should be pre-checked.
   *
   * @return array
   *   A renderable array for a Drupal table, wrapped in a container.
   */
  protected function buildComponentTable(array $components, $container_id, array $arraySelected = []) {
    // Build table header: checkbox + name + URI + type + status.
    $header = [
      $this->t('#'),
      $this->t('Name'),
      $this->t('URI'),
      // $this->t('Type'),
      $this->t('Status'),
    ];

    // Sort components by their 'type' so grouping works.
    usort($components, function($a, $b) {
      return strcmp($a['type'], $b['type']);
    });

    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');
    $root_url = \Drupal::request()->getBaseUrl();

    $rows = [];
    $current_type = NULL;

    foreach ($components as $component) {
      // Whenever the type changes, inject a full-width grouping row.
      if ($component['type'] !== $current_type) {
        $current_type = $component['type'];
        $rows[] = [
          // The 'data' array holds a single cell with colspan == number of columns.
          'data' => [
            [
              'data'    => [
                '#markup' => '<strong>' . Html::escape($current_type) . '</strong>',
              ],
              'colspan' => count($header),
              'class' => ['component-type-row'],
            ],
          ],
          'class' => ['component-type-header'],
        ];
      }

      // Build the checkbox render array.
      $checkbox = [
        '#type'         => 'checkbox',
        '#name'         => $container_id . '[]',
        '#return_value' => $component['uri'],
        '#checked'      => in_array($component['uri'], $arraySelected),
        '#attributes'   => ['class' => ['instrument-component-ajax']],
      ];
      $checkbox_rendered = $renderer->render($checkbox);

      // Append the normal component row.
      $rows[] = [
        'data' => [
          $checkbox_rendered,
          // Link to describe page.
          t('<a target="_new" href="@url">@name</a>', [
            '@url' => $root_url . REPGUI::DESCRIBE_PAGE . base64_encode($component['uri']),
            '@name' => Html::escape($component['name']),
          ]),
          // Link to URI.
          t('<a target="_new" href="@url">@uri</a>', [
            '@url' => $root_url . REPGUI::DESCRIBE_PAGE . base64_encode($component['uri']),
            '@uri' => Html::escape(Utils::namespaceUri($component['uri'])),
          ]),
          // Render type and status safely.
          // Html::escape($component['type']),
          Html::escape($component['status']),
        ],
      ];
    }

    // Wrap the table in a container so AJAX can replace it cleanly.
    return [
      '#type' => 'container',
      '#attributes' => ['id' => $container_id],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No components found.'),
        '#attributes' => ['class' => ['table', 'table-striped']],
      ],
    ];
  }

  /**
   * Validates befor submit sub-task.
   */
  public function validateSubtaskName(array &$form, FormStateInterface $form_state) {
    // Pega o valor do campo dentro da árvore:
    $name = $form_state->getValue(['subtasks', 'new_subtask_form', 'subtask_name']);
    if (trim($name) === '') {
      $form_state->setErrorByName(
        'subtasks][new_subtask_form][subtask_name]',
        $this->t('You must enter a name for the sub-task.')
      );
    }
    $type = $form_state->getValue(['subtasks', 'new_subtask_form', 'subtask_type']);
    if (trim($type) === '') {
      $form_state->setErrorByName(
        'subtasks][new_subtask_form][subtask_type]',
        $this->t('You must enter a Type for the sub-task.')
      );
    }
  }

  public function createSubtaskSubmit(array &$form, FormStateInterface $form_state) {
    // Pull the new task name
    $name = $form_state->getValue(['subtasks','new_subtask_form','subtask_name']);
    $type = $form_state->getValue(['subtasks','new_subtask_form','subtask_type']);

    $api = \Drupal::service('rep.api_connector');
    $parentUri = $this->getTask()->uri;
    $useremail = \Drupal::currentUser()->getEmail();

    $newTaskUri = Utils::uriGen('task');
    $newSubtask = [
      'uri'                       => $newTaskUri,
      'typeUri'                   => UTILS::uriFromAutocomplete($type),
      'hascoTypeUri'              => VSTOI::TASK,
      'hasStatus'                 => VSTOI::DRAFT,
      'hasTemporalDependency'     => '',
      'label'                     => $name,
      'hasLanguage'               => $this->getTask()->hasLanguage,
      'hasSupertaskUri'           => $parentUri,
      'hasVersion'                => "1",
      'comment'                   => "",
      'hasWebDocument'            => "",
      'hasSIRManagerEmail'        => $useremail,
    ];
    $api->parseObjectResponse($api->elementAdd('task', json_encode($newSubtask)), 'getUri');

    $form_state->setValue(['subtasks','new_subtask_form','subtask_name'], '');
    $form_state->setValue(['subtasks','new_subtask_form','subtask_type'], '');

    $form_state->setRebuild(TRUE);
  }

  public function ajaxSubtasksCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();

    // Render the updated subtasks table / form.
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '#subtasks-wrapper',
      $form['subtasks']
    ));

    // Now clear out the textfield on the client side.
    // Adjust the selector to exactly match your field's name attribute.
    $response->addCommand(new InvokeCommand(
      'input[name="subtasks[new_subtask_form][subtask_name]"]',
      'val',
      ['']
    ));

    $response->addCommand(new InvokeCommand(
      'input[name="subtasks[new_subtask_form][subtask_type]"]',
      'val',
      ['']
    ));

    $renderer = \Drupal::service('renderer');
    $messages = [
      '#type' => 'status_messages',
      '#weight' => -1000,
    ];
    $html = $renderer->renderRoot($messages);
    // adjust the selector to match where your theme prints messages.
    $response->addCommand(new HtmlCommand(
      '#subtask-messages',
      $html
    ));

    return $response;
  }

  /**
   * Build an array of labels from the top process down to the current task.
   *
   * @return string
   *   A safe HTML string of the full breadcrumb trail.
   */
  protected function buildBreadcrumb(): string {
    $api = \Drupal::service('rep.api_connector');
    $labels = [];

    // 1) Start with the top‐level process.
    // $process = $api->parseObjectResponse($api->getUri($this->getProcessUri()), 'getUri');
    // $labels[] = $process->label;

    // 2) Then walk down the supertask chain.
    $currentTask = $this->getTask();
    $stack = [];
    while ($currentTask->hasSupertaskUri) {
      // Fetch the parent.
      $parent = $api->parseObjectResponse(
        $api->getUri($currentTask->hasSupertaskUri),
        'getUri'
      );
      // Prepend to our stack (we’ll reverse later).
      array_unshift($stack, $parent->label);
      // Continue up one level:
      $currentTask = $parent;
    }

    // 3) Now our $stack is [ topParent, ..., directParent ]. Append them:
    foreach ($stack as $parentLabel) {
      $labels[] = '<span style="color:blue"><strong>' . $parentLabel . '</strong></span>';
    }

    // 4) Finally the current task itself:
    $labels[] = $this->getTask()->label;

    // 5) Join with “ > ”
    $html = implode(' &gt; ', $labels);
    // Wrap it in your heading tag:
    return '<h5>Sub-tasks of: ' . $html . '</h5>';
  }


  /**
   * AJAX CALLBACKS
   */

  /**
   * “Submit handler” create new line.
   */
  public function onAddInstrumentRow(array &$form, FormStateInterface $form_state) {
    $this->updateInstruments($form_state);
    $this->addInstrumentRow($form_state);
    $form_state->setRebuild(TRUE);
  }

  /**
   * AJAX callback to add one blank instrument-row and re-render instruments.
   */
  public function ajaxAddInstrumentRow(array &$form, FormStateInterface $form_state) {
    // Return the portion of the form we're replacing.
    return $form['instruments'];
  }



  // BACK Function
  function backUrl() {
    // $root_url = \Drupal::request()->getBaseUrl();
    // $response = new RedirectResponse($root_url . '/std/select/task/1/9');
    // $response->send();
    // return;

    if ($this->getTask()->hasSupertaskUri !== null) {
      $default_url = Url::fromRoute('std.edit_task', [
        'processuri' => base64_encode($this->getProcessUri()),
        'state' => 'tasks',
        'taskuri' => base64_encode($this->getTask()->hasSupertaskUri),
      ])->toString();
    } else {
      $default_url = Url::fromRoute('std.edit_process', [
        'processuri' => base64_encode($this->getProcessUri()),
      ])->toString();
    }

    $response = new RedirectResponse($default_url);
    $response->send();

  }

}
