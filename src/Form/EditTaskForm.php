<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\AfterCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\std\Entity\Task;
use Drupal\Core\Ajax\HtmlCommand;

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


    // INITIALIZE NS TABLE
    $tables = new Tables;
    $languages = $tables->getLanguages();

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
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

    if ($state === 'init') {
      // RESET STATE TO BASIC
      $state = 'basic';

      // POPULATE DATA STRUCTURES
      $basic = $this->populateBasic();
      $instruments = $this->populateInstruments();
      $tasks = $this->getTask()->subtask;

    } else {

      $basic = \Drupal::state()->get('my_form_basic') ?? $this->populateBasic();;
      $instruments = \Drupal::state()->get('my_form_instruments') ?? $this->populateInstruments();
      $tasks = \Drupal::state()->get('my_form_tasks') ?? $this->getTask()->subtask;

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
      '#markup' => '<h3 class="mt-5">Edit Task</h3><br>',
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
      'instrument' => 'Instruments and detectors',
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

      $taskstem = '';
      if (isset($basic['taskstem'])) {
        $taskstem = $basic['taskstem'];
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

      $form['task_taskstem_hid'] = [
        'top' => [
          '#type' => 'markup',
          '#markup' => '<div class="pt-3 col border border-white">',
        ],
        'main' => [
          '#type' => 'textfield',
          '#title' => $this->t('Task Stem'),
          '#name' => 'task_taskstem',
          '#default_value' => $taskstem,
          '#id' => 'task_taskstem',
          '#parents' => ['task_taskstem'],
          '#attributes' => [
            'class' => ['open-tree-modal'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => json_encode(['width' => 800]),
            'data-url' => Url::fromRoute('rep.tree_form', [
              'mode' => 'modal',
              'elementtype' => 'taskstem',
            ], ['query' => ['field_id' => 'task_taskstem']])->toString(),
            'data-field-id' => 'task_taskstem',
            'data-elementtype' => 'taskstem',
            'autocomplete' => 'off',
          ],
        ],
        'bottom' => [
          '#type' => 'markup',
          '#markup' => '</div>',
        ],
      ];
      $form['task_taskstem'] = [
        '#type' => 'hidden',
        '#value' => $taskstem,
      ];
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
      $form['task_webdocument'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Web Document'),
        '#default_value' => $webDocument,
        '#attributes' => [
          'placeholder' => 'http://',
        ]
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

    if ($this->getState() == 'instrument') {

      /*
      *      INSTRUMENTS
      */

      // $form['instruments_title'] = [
      //   '#type' => 'markup',
      //   '#markup' => 'Instruments',
      // ];

      $form['instruments'] = array(
        '#type' => 'container',
        '#title' => $this->t('instruments'),
        '#attributes' => array(
          'class' => array('p-3', 'bg-light', 'text-dark', 'row', 'border', 'border-secondary', 'rounded'),
          'id' => 'custom-table-wrapper',
        ),
      );

      $form['instruments']['header'] = array(
        '#type' => 'markup',
        '#markup' =>
          '<div class="p-2 col bg-secondary text-white border border-white">Instrument</div>' .
          '<div class="p-2 col bg-secondary text-white border border-white">Detectors</div>' .
          '<div class="p-2 col-md-1 bg-secondary text-white border border-white">Operations</div>' . $separator,
      );

      $form['instruments']['rows'] = $this->renderInstrumentRows($instruments);

      $form['instruments']['space_3'] = [
        '#type' => 'markup',
        '#markup' => $separator,
      ];

      $form['instruments']['actions']['top'] = array(
        '#type' => 'markup',
        '#markup' => '<div class="p-3 col">',
      );

      $form['instruments']['actions']['add_row'] = [
        '#type' => 'submit',
        '#value' => $this->t('New Instrument'),
        '#name' => 'new_instrument',
        '#attributes' => array('class' => array('btn', 'btn-sm', 'save-button')),
      ];

      $form['instruments']['actions']['bottom'] = array(
        '#type' => 'markup',
        '#markup' => '</div>' . $separator,
      );

    }

    /* ======================= TASKS ======================= */

    if ($this->getState() == 'tasks') {

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
      $form['subtasks']['header']['title'] = [
        '#markup' => $this->buildBreadcrumb(),
      ];

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

      $form['subtasks']['new_subtask_form']['actions']['create_subtask'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create Sub-Task'),
        '#limit_validation_errors' => [
          ['subtasks', 'new_subtask_form', 'subtask_name'],
        ],
        '#validate' => ['::validateSubtaskName'],
        '#submit' => ['::createSubtaskSubmit'],
        '#ajax' => [
          'callback' => '::ajaxSubtasksCallback',
          'wrapper'  => 'subtasks-wrapper',
          'effect'   => 'fade',
        ],
        '#attributes' => [
          'class' => ['mt-2', 'ms-2', 'add-element-button']
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
      $basic['taskstem'] = $input['task_taskstem'] ?? $this->getTask()->typeUri;
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
      'taskstem' => UTILS::fieldToAutocomplete($this->getTask()->typeUri, $this->getTask()->typeLabel),
      'name' => $this->getTask()->label,
      'language' => $this->getTask()->hasLanguage,
      'version' => $this->getTask()->hasVersion,
      'description' => $this->getTask()->comment,
      'webdocument' => $this->getTask()->hasWebDocument,
      'status' => $this->getTask()->hasStatus,
      'typeUri' => $this->getTask()->typeUri,
    ];
    \Drupal::state()->set('my_form_basic', $basic);
    return $basic;
  }

  /******************************
   *
   *    instruments' FUNCTIONS
   *
   ******************************/

   protected function renderInstrumentRows(array $instruments) {
    //dpm($instruments);

    $form_rows = [];
    $separator = '<div class="w-100"></div>';
    foreach ($instruments as $delta => $instrument) {

      $detectors_component = [];
      if (empty($instrument['detectors'])) {
        $detectors_component['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('#'),
            $this->t('Name'),
            $this->t('URI'),
            $this->t('Status'),
          ],
          '#rows' => [],
          '#empty' => $this->t('No detectors yet.'),
        ];
      }
      else {
        //WHEN THERE ARE DETECTORS, MUST GET ALL AND SELECT ONLY THE ONES IN ARRAY
        $intURI = UTILS::uriFromAutocomplete($instrument['instrument']);

        $detectorsList = [];
        foreach ($instruments as $inst) {
            $instrumentUri = UTILS::uriFromAutocomplete($inst['instrument']);
            if ($instrumentUri === $intURI) {
                $detectorsList = $inst['detectors'] ?? [];
                break;
            }
        }

        $detectors_component = $this->buildDetectorTable($this->getComponents($intURI), 'instrument_detectors_' . $delta, $detectorsList);

      }

      $form_row = array(
        'instrument' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col border border-white">',
          ),
          'instrument_instrument_'. $delta => array(
            '#type' => 'textfield',
            '#name' => 'instrument_instrument_' . $delta,
            '#id' => 'instrument_instrument_' . $delta,
            '#value' => $instrument['instrument'],
            '#attributes' => [
             'class' => ['open-tree-modal'],
             'data-dialog-type' => 'modal',
             'data-dialog-options' => json_encode(['width' => 800]),
             'data-url' => Url::fromRoute(
               'rep.tree_form',
               [
                 'mode' => 'modal',
                 'elementtype' => 'instrument',
               ],
               [
                 'query' => ['field_id' => 'instrument_instrument_' . $delta]
               ])->toString(),
             'data-field-id' => 'instrument_instrument_' . $delta,
             'data-search-value' => UTILS::uriFromAutocomplete($instrument['instrument']),
             'data-elementtype' => 'instrument',
            "autocomplete" => 'off',
            ],
            "#autocomplete" => 'off',
            '#ajax' => [
             'callback' => '::addDetectorCallback',
             'event' => 'change',
             'wrapper' => 'instrument_detectors_' . $delta,
             'method' => 'replaceWith',
             'effect' => 'fade',
            ],

          ),
          'bottom' => array(
            '#type' => 'markup',
            '#markup' => '</div>',
          ),
        ),

        'detectors' => [
          'top' => [
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col border border-white">',
          ],
          // Mescla a configuração básica do container com o array condicional.
          'instrument_detectors_' . $delta => array_merge([
            '#type' => 'container',
            '#attributes' => [
              'id' => 'instrument_detectors_' . $delta,
            ],
          ], $detectors_component),
          'bottom' => [
            '#type' => 'markup',
            '#markup' => '</div>',
          ],
        ],

        'operations' => array(
          'top' => array(
            '#type' => 'markup',
            '#markup' => '<div class="pt-3 col-md-1 border border-white">',
          ),
          'main' => array(
            '#type' => 'submit',
            '#name' => 'instrument_remove_' . $delta,
            '#value' => $this->t('Remove'),
            '#attributes' => array(
              'class' => array('remove-row', 'btn', 'btn-sm', 'btn-danger' , 'delete-element-button'),
              'id' => 'instrument-' . $delta,
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

  public function addDetectorCallback(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $delta = str_replace('instrument_instrument_', '', $triggering_element['#name']);
    $container_id = 'instrument_detectors_' . $delta;
    $instrumentURI = $form_state->getValue('instrument_instrument_' . $delta) !== '' ? $form_state->getValue('instrument_instrument_' . $delta) : $form_state->getUserInput()['instrument_instrument_' . $delta];
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
    if (!isset($form['instruments']['rows'][$delta]['row'.$delta]['detectors'][$container_id])) {
        \Drupal::logger('custom_module')->error('Container not found for delta: @delta', ['@delta' => $delta]);
        return [
            '#markup' => $this->t('Error: Container not found for delta @delta.', ['@delta' => $delta]),
        ];
    }

    // Get detectors from API
    $components = $this->getComponents($instrument_uri);

    // Add detectors to instrument
    self::updateInstruments($form_state);

    // Render detectors
    $detectorTable = $this->buildDetectorTable($components, $container_id);

    // Replace the existing detector container with the updated table
    $response->addCommand(new ReplaceCommand('#' . $container_id, $detectorTable));

    return $response;
  }

  protected function updateInstruments(FormStateInterface $form_state) {
    $instruments = \Drupal::state()->get('my_form_instruments');

    $input = $form_state->getUserInput();
    if (isset($input) && is_array($input) &&
        isset($instruments) && is_array($instruments)) {

      foreach ($instruments as $instrument_id => $instrument) {
        if (isset($instrument_id) && isset($instrument)) {
          $instruments[$instrument_id]['instrument'] = $input['instrument_instrument_' . $instrument_id] ?? '';
          $detector = [];
          foreach ($input['instrument_detectors_' . $instrument_id]  as $key => $value) {
            $detector[] = $value;
          }
          //$instruments[$instrument_id]['detectors'] = $input['instrument_detectors_' . $instrument_id] ?? '';
          $instruments[$instrument_id]['detectors'] = $detector ?? [];
        }
      }
    }

    //dpm($instruments);
    \Drupal::state()->set('my_form_instruments', $instruments);
    return;
  }

  protected function populateInstruments() {

    $instruments = $this->getTask()->requiredInstrumentation;

    $instrumentData = [];

    foreach ($instruments as $instrument) {

        $instrumentUri = $instrument->instrument->uri ?? null;
        $instrumentLabel = $instrument->instrument->label ?? 'Unknown Instrument';

        if ($instrumentUri) {

            $detectors = isset($instrument->detectors) && is_array($instrument->detectors)
                ? array_map(fn($detector) => $detector->uri, $instrument->detectors)
                : [];

            $instrumentData[] = [
                'instrument' => UTILS::fieldToAutocomplete($instrumentUri,$instrumentLabel),
                'detectors' => $detectors
            ];
        }
    }

    \Drupal::state()->set('my_form_instruments', $instrumentData);
    return $instruments;
  }

  protected function saveInstruments($taskUri, array $instruments) {
    if (!isset($taskUri)) {
        \Drupal::messenger()->addError(t("No task URI has been provided to save instruments."));
        return;
    }
    if (empty($instruments)) {
        // \Drupal::messenger()->addWarning(t("Task has no instrument to be saved."));
        return;
    }

    $api = \Drupal::service('rep.api_connector');

    $requiredInstrumentation = [];

    foreach ($instruments as $instrument) {
        if (!empty($instrument['instrument'])) {
            $instrumentUri = Utils::uriFromAutocomplete($instrument['instrument']);
            $detectors = [];

            // Adiciona os detectores ao array se existirem
            if (!empty($instrument['detectors'])) {
                foreach ($instrument['detectors'] as $detector) {
                    $detectors[] = $detector;
                }
            }

            $requiredInstrumentation[] = [
                'instrumentUri' => $instrumentUri,
                'detectors' => $detectors
            ];
        }
    }

    $taskData = [
        'taskuri' => $taskUri,
        'requiredInstrumentation' => $requiredInstrumentation
    ];

    $api->taskInstrumentUpdate($taskData);

    return;
  }

  public function addInstrumentRow() {
    $instruments = \Drupal::state()->get('my_form_instruments') ?? [];

    // Add a new row to the table.
    $instruments[] = [
      'instrument' => '',
      'detectors' => '',
    ];
    \Drupal::state()->set('my_form_instruments', $instruments);

    // Rebuild the table rows.
    $form['instruments']['rows'] = $this->renderInstrumentRows($instruments);
    return;
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

    // If not leaving then UPDATE STATE OF VARIABLES, OBJECTS AND CODES
    // according to the current state of the editor
    if ($this->getState() === 'basic') {
      $this->updateBasic($form_state);
    }

    if ($this->getState() === 'instruments') {
      $this->updateInstruments($form_state);
    }

    if ($this->getState() === 'tasks') {
      $this->updateCodes($form_state);
    }

    // Get the latest cached versions of values in the editor

    $basic = \Drupal::state()->get('my_form_basic');
    $this->updateInstruments($form_state);
    $instruments = \Drupal::state()->get('my_form_instruments');
    $tasks = \Drupal::state()->get('my_form_tasks');

    if ($button_name === 'new_instrument') {
      $this->addInstrumentRow();
      return;
    }

    if (str_starts_with($button_name,'instrument_remove_')) {
      $this->removeInstrumentRow($button_name);
      return;
    }

    if ($button_name === 'new_code') {
      $this->addCodeRow();
      return;
    }

    if (str_starts_with($button_name,'code_remove_')) {
      $this->removeCodeRow($button_name);
      return;
    }

    if ($button_name === 'save') {

      $errors = false;

      //VALIDATIONS BEFORE SUBMIT PREVENT THE USE OF validationForm core....
      $basic = \Drupal::state()->get('my_form_basic');

      if (!empty($basic)) {

        if(strlen($basic['name']) < 1 || strlen($basic['taskstem']) < 1 ) {
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

        try {
          $useremail = \Drupal::currentUser()->getEmail();

          $taskJSON = '{"uri":"' . $basic['uri'] . '",'
            . '"typeUri":"' . UTILS::uriFromAutocomplete($basic['taskstem']) . '",'
            . '"hascoTypeUri":"' . VSTOI::TASK . '",'
            . '"hasStatus":"' . $basic['status'] . '",'
            . '"label":"' . $basic['name'] . '",'
            . '"hasLanguage":"' . $basic['language'] . '",'
            . '"hasVersion":"' . $basic['version'] . '",'
            . '"comment":"' . $basic['description'] . '",'
            . '"hasWebDocument":"'. $basic['webdocument'] .'",'
            . '"hasSIRManagerEmail":"' . $useremail . '"}';

          $api = \Drupal::service('rep.api_connector');

          // The DELETE of the task will also delete the
          // instruments, objects and codes of the dictionary
          $api->elementDel('task',$basic['uri']);

          // In order to update the task it is necessary to
          // add the following to the task: the task itself, its
          // instruments, its detectors and its codes
          $api->elementAdd('task',$taskJSON);

          if (isset($instruments)) {
            $this->saveInstruments($basic['uri'],$instruments);
          }
          // if (isset($codes)) {
          //   $this->saveCodes($basic['uri'],$codes);
          // }

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

    // manual “required” check:
    // $desc = $form_state->getValue('task_description');
    // if (strlen(trim($desc)) === 0 && $trigger['#name'] === 'save') {
    //   $form_state->setErrorByName('task_description', $this->t('Description is required.'));
    // }

    // and any other manual checks you need…
  }

  public function getComponents($instrumentUri) {
    $root_url = \Drupal::request()->getBaseUrl();
    // Call to get Detectors
    $api = \Drupal::service('rep.api_connector');
    $response = $api->componentListFromInstrument($instrumentUri);

    // Decode JSON reply
    $data = json_decode($response, true);
    if (!$data || !isset($data['body'])) {
      return [];
    }

    // Decode Body
    $urls = json_decode($data['body'], true);

    // Task detectors
    $components = [];
    foreach ($urls as $url) {
      $componentData = $api->getUri($url);
      $obj = json_decode($componentData);
      $components[] = [
        'name' => isset($obj->body->label) ? $obj->body->label : '',
        'uri' => isset($obj->body->uri) ? $obj->body->uri : '',
        'status' => isset($obj->body->hasStatus) ? Utils::plainStatus($obj->body->hasStatus) : '',
        'hasStatus' => isset($obj->body->hasStatus) ? $obj->body->hasStatus : null,
      ];
    }
    return $components;
  }

  protected function buildDetectorTable(array $detectors, $container_id, $arraySelected = []) {

    $root_url = \Drupal::request()->getBaseUrl();

    $header = [
      $this->t("#"),
      $this->t('Name'),
      $this->t('URI'),
      $this->t('Status'),
    ];

    // Get the renderer service.
    $renderer = \Drupal::service('renderer');

    $rows = [];
    foreach ($detectors as $detector) {

      // Build an inline template render array for the checkbox.
      $checkbox = [
        '#type' => 'checkbox',
        '#name' => $container_id . '[]',
        '#return_value' => $detector['uri'],
        '#checked' => !empty($arraySelected) ? in_array($detector['uri'], $arraySelected) : 1,
        '#ajax' => [
          'callback' => '::addNewInstrumentRow',
          'event' => 'change', // Garante que está ouvindo o evento correto
          'wrapper' => $container_id,
          'progress' => [
            'type' => 'throbber',
            'message' => NULL,
          ],
          'method' => 'replace', // Use replace para garantir atualização
        ],
        '#executes_submit_callback' => TRUE, // Força o submit para garantir o disparo
        '#attributes' => [
          'class' => ['instrument-detector-ajax'],
          //'data-container-id' => 'body'
        ],
      ];


      // Manually render the inline template.
      $checkbox_rendered = $renderer->render($checkbox);

      $rows[] = [
        'data' => [
          $checkbox_rendered,
          t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($detector['uri']).'">' . $detector['name'] . '</a>'),
          t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($detector['uri']).'">' . UTILS::namespaceUri($detector['uri']) . '</a>'),
          $detector['status'],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['id' => $container_id],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No detectors found.'),
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
        'subtasks][new_subtask_form][subtask_name',
        $this->t('You must enter a name for the sub-task.')
      );
    }
  }

  public function createSubtaskSubmit(array &$form, FormStateInterface $form_state) {
    // Pull the new task name
    $name = $form_state->getValue(['subtasks','new_subtask_form','subtask_name']);

    $api = \Drupal::service('rep.api_connector');
    $parentUri = $this->getTask()->uri;
    $useremail = \Drupal::currentUser()->getEmail();

    $newTaskUri = Utils::uriGen('task');
    $newSubtask = [
      'uri'               => $newTaskUri,
      'typeUri'           => VSTOI::TASK,
      'hascoTypeUri'      => VSTOI::TASK,
      'hasStatus'         => VSTOI::DRAFT,
      'label'             => $name,
      'hasLanguage'       => $this->getTask()->hasLanguage,
      'hasSupertaskUri'   => $parentUri,
      'hasVersion'        => "1",
      'comment'           => "",
      'hasWebDocument'    => "",
      'hasSIRManagerEmail'=> $useremail,
    ];
    $api->parseObjectResponse($api->elementAdd('task', json_encode($newSubtask)), 'getUri');
    \Drupal::logger('std')->debug('Created subtask message: <pre>@r</pre>', ['@r' => print_r($newSubtask, TRUE)]);

    $form_state->setValue(['subtasks','new_subtask_form','subtask_name'], '');

    // Feedback + rebuild
    \Drupal::messenger()->addStatus($this->t('Sub‑Task “@name” created.', ['@name' => $name]));
    $form_state->setRebuild(TRUE);
    // return $form['subtasks'];
  }

  public function ajaxSubtasksCallback(array &$form, FormStateInterface $form_state) {
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
    $process = $api->parseObjectResponse($api->getUri($this->getProcessUri()), 'getUri');
    $labels[] = $process->label;

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
