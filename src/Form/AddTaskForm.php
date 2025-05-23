<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\AfterCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Component\Serialization\Json;
use Drupal\std\Entity\Task;

class AddTaskForm extends FormBase {

  protected $state;
  protected $topTaskUri;
  protected $topTask;

  public function getState() {
    return $this->state;
  }
  public function setState($state) {
    return $this->state = $state;
  }

  public function getTopTaskUri() {
    return $this->topTaskUri;
  }
  public function setTopTaskUri($topTaskUri) {
    return $this->topTaskUri = $topTaskUri;
  }

  public function getTopTask() {
    return $this->topTask;
  }
  public function setTopTask($topTask) {
    return $this->topTask = $topTask;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_task_form';
  }

  /**************************************************************
   **************************************************************
   ***                                                        ***
   ***                    BUILD FORM                          ***
   ***                                                        ***
   **************************************************************
   **************************************************************/

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $state=NULL, $toptaskuri = NULL) {

    $api = \Drupal::service('rep.api_connector');

    // FOUR groups of values are preserved in state: basic, instruments, objects and codes.
    // for each group, we have render*, update*, save*, add*, remove* (basic has no add* and remove*)
    //   - render* is from $ELEMENT to $form
    //     (used in buildForm())
    //   - update* is from $form_state to $ELEMENT and save state
    //     (used in pills_card_callback())
    //   - save* is from $ELEMENT to triple store
    //     (used in save operation of submitForm())

    // SET STATE, INSTRUMENTS AND OBJECTS
    if (isset($state) && $state === 'init') {
      \Drupal::state()->delete('my_form_basic');
      \Drupal::state()->delete('my_form_instruments');
      \Drupal::state()->delete('my_form_tasks');
      $basic = [
        'taskstem' => '',
        'name' => '',
        'language' => '',
        'version' => '1',
        'description' => '',
        'webdocument' => '',
      ];
      $instruments = [];
      $tasks = [];
      //$codes = [];
      $state = 'basic';
    } else {
      $basic = \Drupal::state()->get('my_form_basic') ?? [];
      $instruments = \Drupal::state()->get('my_form_instruments') ?? [];
      $tasks = \Drupal::state()->get('my_form_tasks') ?? [];
      //$codes = \Drupal::state()->get('my_form_codes') ?? [];
    }
    $this->setState($state);

    // SET TOP TASK AND HER VALUES
    if ($toptaskuri === NULL) {
      \Drupal::state()->delete('my_form_basic');
      \Drupal::state()->delete('my_form_instruments');
      \Drupal::state()->delete('my_form_tasks');
      return;
    }

    $uri_decode=base64_decode($toptaskuri);
    $toptask = $api->parseObjectResponse($api->getUri($uri_decode),'getUri');
    $this->setTopTask($toptask);
    $this->setTopTaskUri($uri_decode);

    // GET LANGUAGES
    $tables = new Tables;
    $languages = $tables->getLanguages();

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal'; // Biblioteca personalizada do módulo
    $form['#attached']['library'][] = 'core/drupal.dialog'; // Biblioteca do modal do Drupal
    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#attached']['library'][] = 'core/jquery';
    $form['#attached']['library'][] = 'std/std_task';


    // SET SEPARATOR
    $separator = '<div class="w-100"></div>';

    $form['task_title'] = [
      '#type' => 'markup',
      '#markup' => '<h3 class="mt-5">Add Task</h3><br>'
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
      'tasks' => 'Sub Tasks',
      'instrument' => 'Instruments and Elements',
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

    // Add a hidden field to capture the toptaskuri.
    $form['toptaskuri'] = [
      '#type' => 'hidden',
      '#value' => $this->getTopTaskUri(),
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

      // $form['task_taskstem'] = [
      //   'top' => [
      //     '#type' => 'markup',
      //     '#markup' => '<div class="pt-3 col border border-white">',
      //   ],
      //   'main' => [
      //     '#type' => 'textfield',
      //     '#title' => $this->t('Task Stem'),
      //     '#name' => 'task_taskstem',
      //     '#default_value' => $taskstem,
      //     '#id' => 'task_taskstem',
      //     '#parents' => ['task_taskstem'],
      //     '#required' => true,
      //     '#attributes' => [
      //       'class' => ['open-tree-modal'],
      //       'data-dialog-type' => 'modal',
      //       'data-dialog-options' => json_encode(['width' => 800]),
      //       'data-url' => Url::fromRoute('rep.tree_form', [
      //         'mode' => 'modal',
      //         'elementtype' => 'taskstem',
      //       ], ['query' => ['field_id' => 'task_taskstem']])->toString(),
      //       'data-field-id' => 'task_taskstem',
      //       'data-elementtype' => 'taskstem',
      //       'autocomplete' => 'off',
      //     ],
      //   ],
      //   'bottom' => [
      //     '#type' => 'markup',
      //     '#markup' => '</div>',
      //   ],
      // ];
      // Wrap the entire textfield in a styled container.
      $form['task_taskstem'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Task Stem'),
        '#default_value' => $taskstem,
        '#required' => TRUE,

        // Use #prefix/#suffix to wrap in a Bootstrap‑style bordered column.
        '#prefix' => '<div class="pt-3 col border border-white">',
        '#suffix' => '</div>',

        // Ensure this input opens the tree-selection modal.
        '#attributes' => [
          // Trigger our custom JS behavior.
          'class' => ['open-tree-modal'],

          // Tell Drupal AJAX to open a jQuery UI modal.
          'data-dialog-type'    => 'modal',
          'data-dialog-options' => Json::encode(['width' => 800]),

          // URL of the tree form, with query to identify field ID.
          'data-url' => Url::fromRoute(
            'rep.tree_form',
            [
              'mode'        => 'modal',
              'elementtype' => 'detectorstem',
            ],
            [
              'query' => [
                'field_id' => 'task_taskstem',
                'caller'   => 'add_task_form',
              ],
            ]
          )->toString(),

          // Pass through field identifier and element type.
          'data-field-id'    => 'task_taskstem',
          'data-elementtype' => 'detectorstem',

          // Disable native browser autocomplete.
          'autocomplete' => 'off',
        ],
      ];

      $form['task_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => $name,
        '#required' => true,
      ];
      $form['task_language'] = [
        '#type' => 'select',
        '#title' => $this->t('Language'),
        '#options' => $languages,
        '#default_value' => 'en',
      ];
      $form['task_version_hid'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Version'),
        '#default_value' => $version ?? 1,
        '#disabled' => true
      ];
      $form['task_version'] = [
        '#type' => 'hidden',
        '#value' => $version ?? 1,
      ];
      $form['task_description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => $description,
        '#required' => true,
      ];
      $form['task_webdocument'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Web Document'),
        '#default_value' => $webDocument,
        '#attributes' => [
          'placeholder' => 'http://',
        ]
      ];
      $form['task_issupertask'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select if this is a Super Task?'),
        '#default_value' => $this->getTopTaskUri() !== NULL ? 0:1,
        '#attributes' => [
          'class' => ['bootstrap-toggle'],
        ],
        '#disabled' => $this->getTopTaskUri() !== NULL ? 1:0,
      ];
      $form['task_supertask'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select Super Task'),
        '#autocomplete_route_name' => 'std.task_autocomplete',
        '#default_value' => isset($this->getTopTask()->uri)
        ? UTILS::fieldToAutocomplete(
            $this->getTopTaskUri(),
            $this->getTopTask()->label
          )
        : '',
        '#states' => [
          'visible' => [
            ':input[name="task_issupertask"]' => ['checked' => FALSE],
          ],
          'enabled' => [
            ':input[name="task_issupertask"]' => ['checked' => $this->getTopTaskUri() !== NULL ? 0:1],
          ],
        ],
      ];
    }

    /* ======================= INSTRUMENT ======================= */

    if ($this->getState() == 'instrument') {

      /*
      *      INSTRUMENTS
      */

      $form['instruments_title'] = [
        '#type' => 'markup',
        '#markup' => 'Instruments',
      ];

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

      $form['subtasks'] = array(
        '#type' => 'container',
        '#title' => $this->t('Sub-Tasks'),
        '#attributes' => array(
          'class' => array('p-3', 'bg-light', 'text-dark', 'row', 'border', 'border-secondary', 'rounded'),
          'id' => 'custom-table-wrapper',
        ),
      );

      $form['subtasks']['actions']['top'] = array(
        '#type' => 'markup',
        '#markup' => '<div class="p-3 col">',
      );

      $form['subtasks']['actions']['add_row'] = [
        '#type' => 'submit',
        '#value' => $this->t('New Sub-Task'),
        '#name' => 'new_code',
        '#attributes' => array('class' => array('btn', 'btn-sm', 'add-element-button')),
      ];

      $form['subtasks']['actions']['bottom'] = array(
        '#type' => 'markup',
        '#markup' => '</div>' . $separator,
      );

      $form['subtasks']['element_table_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'element-table-wrapper'],
      ];

      $form['subtasks']['element_table_wrapper']['element_table'] = [
          '#type' => 'table',
          '#header' => TASK::generateHeader(),
          '#empty' => $this->t('No records found'),
          '#attributes' => ['class' => ['table', 'table-striped']],
          '#js_select' => FALSE,
      ];

      $results = TASK::generateOutput($tasks);
      $output = $results['output'];

      foreach ($output as $key => $row) {
        $row_status = strtolower($row['element_hasStatus']);

        // Hide unnecessary columns
        foreach ($row as $field_key => $field_value) {
            if ($field_key !== 'element_hasStatus' && $field_key !== 'element_hasLanguage' && $field_key !== 'element_hasImageUri') {
                $form['subtasks']['element_table_wrapper']['element_table'][$key][$field_key] = [
                    '#markup' => $field_value,
                ];
            }
        }

        $form['subtasks']['space_3'] = [
          '#type' => 'markup',
          '#markup' => $separator,
        ];

      }
    }

    /* ======================= CODEBOOK ======================= */

    /*
    if ($this->getState() == 'codebook') {

      $form['codes_title'] = [
        '#type' => 'markup',
        '#markup' => 'Codes',
      ];

      $form['codes'] = array(
        '#type' => 'container',
        '#title' => $this->t('codes'),
        '#attributes' => array(
          'class' => array('p-3', 'bg-light', 'text-dark', 'row', 'border', 'border-secondary', 'rounded'),
          'id' => 'custom-table-wrapper',
        ),
      );

      $form['codes']['header'] = array(
        '#type' => 'markup',
        '#markup' =>
          '<div class="p-2 col bg-secondary text-white border border-white">Column</div>' .
          '<div class="p-2 col bg-secondary text-white border border-white">Code</div>' .
          '<div class="p-2 col bg-secondary text-white border border-white">Label</div>' .
          '<div class="p-2 col bg-secondary text-white border border-white">Class</div>' .
          '<div class="p-2 col-md-1 bg-secondary text-white border border-white">Operations</div>' . $separator,
      );

      $form['codes']['rows'] = $this->renderCodeRows($codes);

      $form['codes']['space_3'] = [
        '#type' => 'markup',
        '#markup' => $separator,
      ];

      $form['codes']['actions']['top'] = array(
        '#type' => 'markup',
        '#markup' => '<div class="p-3 col">',
      );

      $form['codes']['actions']['add_row'] = [
        '#type' => 'submit',
        '#value' => $this->t('New Code'),
        '#name' => 'new_code',
        '#attributes' => array('class' => array('btn', 'btn-sm', 'add-element-button')),
      ];

      $form['codes']['actions']['bottom'] = array(
        '#type' => 'markup',
        '#markup' => '</div>' . $separator,
      );

    }
    */

    /* ======================= COMMON BOTTOM ======================= */

    $form['space'] = [
      '#type' => 'markup',
      '#markup' => '<br><br>',
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
        // Prevent HTML5 required="required" from blocking the click
        'formnovalidate' => 'formnovalidate',
      ],
      // Prevent ANY server-side validation from being triggered on this button
      '#limit_validation_errors' => [],
      '#validate' => [],

      // Optionally, define your own submit callback specifically for "Cancel"
      '#submit' => ['::cancelSubmitCallback'],
    ];

    $form['selected_detectors'] = [
      '#type' => 'hidden',
      '#value' => [],
      '#attributes' => [
        'id' => 'selected-detectors',
      ],
    ];

    $form['debug_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'debug-container'],
    ];



    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br>'),
    ];

    //$form['#attached']['library'][] = 'std/std_list';

    return $form;
  }


  public function cancelSubmitCallback(array &$form, FormStateInterface $form_state) {
    // Perform the same logic you do in submitForm() if ($button_name === 'back').
    // For instance:
    \Drupal::state()->delete('my_form_basic');
    \Drupal::state()->delete('my_form_instruments');
    \Drupal::state()->delete('my_form_tasks');
    $this->backUrl();
  }


  /**************************************************************
   **************************************************************
   ***                                                        ***
   ***         VALIDATE FORM  AND AUXILIARY FUNCTIONS         ***
   ***                                                        ***
   **************************************************************
   **************************************************************/

   public function validateForm(array &$form, FormStateInterface $form_state) {

    //NOT TO BE USED LOTS OF FORM_STATE PROBLEMS



  //   $submitted_values = $form_state->cleanValues()->getValues();
  //   $triggering_element = $form_state->getTriggeringElement();
  //   $button_name = $triggering_element['#name'];

  //   if ($button_name === 'save') {
  //     // TODO

  //     //$this->updateBasic($form_state);
  //     $basic = \Drupal::state()->get('my_form_basic');

  //     dpm($basic);

  //     if (!empty($basic)) {

  //       if(strlen($basic['name']) < 1) {
  //         $form_state->setErrorByName(
  //           'task_name',
  //           $this->t('Please enter a valid name for the Simulation Task')
  //         );
  //       }
  //       if(strlen($basic['taskstem']) < 1) {
  //         $form_state->setErrorByName(
  //           'task_taskstem',
  //           $this->t('Please select a valid Task Stem')
  //         );
  //       }
  //       if(strlen($basic['description']) < 1) {
  //         $form_state->setErrorByName(
  //           'task_description',
  //           $this->t('Please enter a description')
  //         );
  //       }
  //     } else {
  //       if(strlen($submitted_values['task_name']) < 1) {
  //         $form_state->setErrorByName(
  //           'task_name',
  //           $this->t('Please enter a valid name for the Simulation Task')
  //         );
  //       }
  //       if(strlen($submitted_values['task_taskstem']) < 1) {
  //         $form_state->setErrorByName(
  //           'task_taskstem',
  //           $this->t('Please select a valid Task Stem')
  //         );
  //       }
  //       if(strlen($submitted_values['task_description']) < 1) {
  //         $form_state->setErrorByName(
  //           'task_description',
  //           $this->t('Please enter a description')
  //         );
  //       }
  //     }

  //     if ($form_state->getErrors())
  //       $form_state->setRebuild(TRUE);
  //   }

    return;
  }

  // public function pills_card_callback(array &$form, FormStateInterface $form_state) {

  //   // RETRIEVE CURRENT STATE AND SAVE IT ACCORDINGLY
  //   $currentState = $form_state->getValue('state');
  //   if ($currentState == 'basic') {
  //     $this->updateBasic($form_state);
  //   }
  //   if ($currentState == 'instrument') {
  //     $this->updateInstruments($form_state);
  //   }
  //   //if ($currentState == 'codebook') {
  //   //  $this->updateCodes($form_state);
  //   //}

  //   // RETRIEVE FUTURE STATE
  //   $triggering_element = $form_state->getTriggeringElement();
  //   $parts = explode('_', $triggering_element['#name']);
  //   $state = (isset($parts) && is_array($parts)) ? end($parts) : null;

  //   // BUILD NEW URL
  //   $root_url = \Drupal::request()->getBaseUrl();
  //   $newUrl = $root_url . REPGUI::ADD_TASK . $state;

  //   // REDIRECT TO NEW URL
  //   $response = new AjaxResponse();
  //   $response->addCommand(new RedirectCommand($newUrl));

  //   return $response;
  // }
  public function pills_card_callback(array &$form, FormStateInterface $form_state) {
    // 1) save whichever pane we were on
    $current = $form_state->getValue('state');
    if ($current === 'basic') {
      $this->updateBasic($form_state);
    }
    elseif ($current === 'instruments') {
      $this->updateInstruments($form_state);
    }
    elseif ($current == 'tasks') {
      $this->updateSubTasks($form_state);
    }

    // 2) figure out which new state we want
    $trigger = $form_state->getTriggeringElement();
    $parts = explode('_', $trigger['#name']);
    $new_state = end($parts);

    // persist new state
    $form_state->set('state', $new_state);

    // 3) rebuild the form for that new state
    //    pass along state + processUri from route
    $toptaskuri = $form_state->get('toptaskuri');
    $new_form = $this->buildForm([], $form_state, $new_state, $toptaskuri);

    // 4) render just our wrapper
    $renderer = \Drupal::service('renderer');
    $html = $renderer->renderRoot($new_form['#prefix'] . $new_form['pills_card'] . $new_form['#suffix']);

    // 5) send it back in an AJAX ReplaceCommand
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#add-task-modal-content', $html));
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
    $basic = \Drupal::state()->get('my_form_basic') ?? [
      'taskstem' => '',
      'name' => '',
      'language' => '',
      'version' => '1',
      'description' => '',
      'webdocument' => ''
    ];
    $input = $form_state->getUserInput();
    if (isset($input) && is_array($input) &&
        isset($basic) && is_array($basic)) {

      $basic['taskstem'] = $input['task_taskstem'] ?? '';
      $basic['name']        = $input['task_name'] ?? '';
      $basic['language']    = $input['task_language'] ?? '';
      $basic['version']     = $input['task_version'] ?? 1;
      $basic['description'] = $input['task_description'] ?? '';
      $basic['webdocument'] = $input['task_webdocument'] ?? '';

    }
    \Drupal::state()->set('my_form_basic', $basic);
    $response = new AjaxResponse();
    return $response;
  }

  /******************************
   *
   *    instruments' FUNCTIONS
   *
   ******************************/

  protected function renderInstrumentRows(array $instruments) {
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
        $intURI = Utils::uriFromAutocomplete($instrument['instrument']);
        $detectors_component = $this->buildDetectorTable($this->getDetectors($intURI), 'instrument_detectors_' . $delta, $instrument['detectors']);
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
             'data-search-value' => $instrument['instrument'],
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
        // 'detectors' => [
        //   'top' => [
        //     '#type' => 'markup',
        //     '#markup' => '<div class="pt-3 col border border-white">',
        //   ],
        //   'instrument_detectors_' . $delta => [
        //     '#type' => 'container',
        //     '#attributes' => [
        //       'id' => 'instrument_detectors_' . $delta,
        //     ],
        //       //WILL BE THIS CODE PART
        //       'table' => [
        //         '#type' => 'table',
        //         '#header' => [
        //             $this->t('#'),
        //             $this->t('Name'),
        //             $this->t('URI'),
        //             $this->t('Status'),
        //         ],
        //         '#rows' => [], // Começa vazio e será preenchido pelo AJAX
        //         '#empty' => $this->t('No detectors yet.'),
        //       ],
        //       // '#value' => $this->getDetectorsArray($instrument['detectors']),
        //   ],
        //   'bottom' => [
        //     '#type' => 'markup',
        //     '#markup' => '</div>',
        //   ],
        // ],
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
    $detectors = $this->getDetectors($instrument_uri);

    // Add detectors to instrument
    self::updateInstruments($form_state);

    // Render detectors
    $detectorTable = $this->buildDetectorTable($detectors, $container_id);

    // Replace the existing detector container with the updated table
    $response->addCommand(new ReplaceCommand('#' . $container_id, $detectorTable));

    return $response;
  }

  protected function updateInstruments(FormStateInterface $form_state) {
    $instruments = \Drupal::state()->get('my_form_instruments');

    //dpm($form_state->getUserInput());

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

  protected function saveInstruments($taskUri, array $instruments) {
    if (!isset($taskUri)) {
        \Drupal::messenger()->addError(t("No task URI has been provided to save instruments."));
        return;
    }
    if (empty($instruments)) {
        \Drupal::messenger()->addWarning(t("Task has no instrument to be saved."));
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

            // Estrutura o array com o URI do instrumento e o array de detectores
            $requiredInstrumentation[] = [
                'instrumentUri' => $instrumentUri,
                'detectors' => $detectors
            ];
        }
    }

    // Estrutura final do objeto JSON
    $taskData = [
        'taskuri' => $taskUri,
        'requiredInstrumentation' => $requiredInstrumentation
    ];

    // Envia o objeto para a API
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

  public function addNewInstrumentRow(array &$form, FormStateInterface $form_state) {
    $instruments = \Drupal::state()->get('my_form_instruments') ?? [];

    // Adiciona uma nova linha à tabela
    $instruments[] = [
        'instrument' => '',
        'detectors' => '',
    ];
    \Drupal::state()->set('my_form_instruments', $instruments);

    // Reconstrói as linhas da tabela
    $form['instruments']['rows'] = $this->renderInstrumentRows($instruments);

    // Retorna uma resposta AJAX para atualizar o container
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#' . $form['instruments']['#attributes']['id'], $form['instruments']));

    return $response;
  }

  // TASKS
  protected function updateSubTasks(FormStateInterface $form_state) {
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

  /******************************
   *
   *    CODE'S FUNCTIONS
   *
   ******************************/

  /*
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
            '#value' => $code['column'],
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
            '#value' => $code['code'],
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
            '#value' => $code['label'],
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
            '#value' => $code['class'],
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
    $codes = \Drupal::state()->get('my_form_codes');
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
    }
    \Drupal::state()->set('my_form_codes', $codes);
    return;
  }

  protected function saveCodes($taskUri, array $codes) {
    if (!isset($taskUri)) {
      \Drupal::messenger()->addError(t("No semantic data dictionary's URI have been provided to save possible values."));
      return;
    }
    if (!isset($codes) || !is_array($codes)) {
      \Drupal::messenger()->addWarning(t("Semantic data dictionary has no possible values to be saved."));
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
            Constant::PREFIX_PROCESS,
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
    $codes = \Drupal::state()->get('my_form_codes') ?? [];

    // Add a new row to the table.
    $codes[] = [
      'column' => '',
      'code' => '',
      'label' => '',
      'class' => '',
    ];
    \Drupal::state()->set('my_form_codes', $codes);

    // Rebuild the table rows.
    $form['codes']['rows'] = $this->renderCodeRows($codes);
    return;
  }

  public function removeCodeRow($button_name) {
    $codes = \Drupal::state()->get('my_form_codes') ?? [];

    // from button name's value, determine which row to remove.
    $parts = explode('_', $button_name);
    $code_to_remove = (isset($parts) && is_array($parts)) ? (int) (end($parts)) : null;

    if (isset($code_to_remove) && $code_to_remove > -1) {
      unset($codes[$code_to_remove]);
      $codes = array_values($codes);
      \Drupal::state()->set('my_form_codes', $codes);
    }
    return;
  }
  */

  /**************************************************************
   **************************************************************
   ***                                                        ***
   ***                    SUBMIT FORM                         ***
   ***                                                        ***
   **************************************************************
   **************************************************************/

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // IDENTIFY NAME OF BUTTON triggering submitForm()
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      \Drupal::state()->delete('my_form_basic');
      \Drupal::state()->delete('my_form_instruments');
      \Drupal::state()->delete('my_form_tasks');
      self::backUrl();
      return;
    }

    // If not leaving then UPDATE STATE OF INSTRUMENTS, OBJECTS AND CODES
    // according to the current state of the editor
    if ($this->getState() === 'basic') {
      $this->updateBasic($form_state);
    }

    if ($this->getState() === 'instrument') {
      $this->updateInstruments($form_state);
    }

    if ($this->getState() === 'tasks') {
      $this->updateSubTasks($form_state);
    }

    //if ($this->getState() === 'codebook') {
    //  $this->updateCodes($form_state);
    //}

    // Get the latest cached versions of values in the editor
    $basic = \Drupal::state()->get('my_form_basic');
    $instruments = \Drupal::state()->get('my_form_instruments');
    $tasks = \Drupal::state()->get('my_form_tasks');
    //$codes = \Drupal::state()->get('my_form_codes');

    if ($button_name === 'new_instrument') {
      $this->addInstrumentRow();
      return;
    }

    if (str_starts_with($button_name,'instrument_remove_')) {
      $this->removeInstrumentRow($button_name);
      return;
    }

    /*
    if ($button_name === 'new_code') {
      $this->addCodeRow();
      return;
    }

    if (str_starts_with($button_name,'code_remove_')) {
      $this->removeCodeRow($button_name);
      return;
    }
    */

    if ($button_name === 'save') {

      $errors = false;

      //VALIDATIONS BEFORE SUBMIT PREVENT THE USE OF validationForm core....
      $basic = \Drupal::state()->get('my_form_basic');

      if (!empty($basic)) {

        if(strlen($basic['name']) < 1 || strlen($basic['taskstem']) < 1 || strlen($basic['description']) < 1) {
          $errors = true;
          \Drupal::messenger()->addError(t("Mandatory fields are required to be filled!"));
        }
      } else {
        if(strlen($submitted_values['task_name']) < 1) {
          $form_state->setErrorByName(
            'task_name',
            $this->t('Please enter a valid name for the Simulation Task')
          );
        }
        if(strlen($submitted_values['task_taskstem']) < 1) {
          $form_state->setErrorByName(
            'task_taskstem',
            $this->t('Please select a valid Task Stem')
          );
        }
        if(strlen($submitted_values['task_description']) < 1) {
          $form_state->setErrorByName(
            'task_description',
            $this->t('Please enter a description')
          );
        }
      }

      //TRY TO RELOAD....
      if ($errors) {
        $form_state->setRebuild(TRUE);

      } else {

        try {
          $useremail = \Drupal::currentUser()->getEmail();

          // Prepare data to be sent to the external service
          $newTaskUri = Utils::uriGen('task');
          $taskJSON = '{"uri":"' . $newTaskUri . '",'
            . '"typeUri":"' .Utils::uriFromAutocomplete($basic['taskstem']) . '",'
            . '"hascoTypeUri":"' . VSTOI::TASK . '",'
            . '"hasStatus":"' . VSTOI::DRAFT . '",'
            . '"label":"' . $basic['name'] . '",'
            . '"hasLanguage":"' . $basic['language'] . '",'
            . '"hasVersion":"' . $basic['version'] . '",'
            . '"comment":"' . $basic['description'] . '",'
            . '"hasWebDocument":"'. $basic['webdocument'] .'",'
            . '"hasSIRManagerEmail":"' . $useremail . '"}';

          $api = \Drupal::service('rep.api_connector');

          $api->elementAdd('task',$taskJSON);
          if (isset($instruments)) {
            $this->saveInstruments($newTaskUri,$instruments);
          }
          /*
          if (isset($codes)) {
            $this->saveCodes($newTaskUri,$codes);
          }
          */

          \Drupal::state()->delete('my_form_basic');
          \Drupal::state()->delete('my_form_instruments');
          //\Drupal::state()->delete('my_form_codes');

          \Drupal::messenger()->addMessage(t("Task has been added successfully."));
          self::backUrl();
          return;

        } catch(\Exception $e){
          \Drupal::messenger()->addMessage(t("An error occurred while adding task: ".$e->getMessage()));
          self::backUrl();
          return false;
        }
        return false;
      }
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_task');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

  /**
   * get Detectors From Instrument
   */
  public function getDetectors($instrumentUri) {

    // Call to get Detectors
    $api = \Drupal::service('rep.api_connector');
    // $response = $api->detectorListFromInstrument($instrumentUri);
    $response = $api->componentListFromInstrument($instrumentUri);

    // Decode JSON reply
    $data = json_decode($response, true);
    if (!$data || !isset($data['body'])) {
      return [];
    }

    // Decode Body
    $urls = json_decode($data['body'], true);

    // Task detectors
    $detectors = [];
    foreach ($urls as $url) {
      $detectorData = $api->getUri($url);
      $obj = json_decode($detectorData);
      $detectors[] = [
        'name' => isset($obj->body->label) ? $obj->body->label : '',
        'uri' => isset($obj->body->uri) ? $obj->body->uri : '',
        'status' => isset($obj->body->hasStatus) ? Utils::plainStatus($obj->body->hasStatus) : '',
        'hasStatus' => isset($obj->body->hasStatus) ? $obj->body->hasStatus : null,
      ];
    }
    return $detectors;
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


  /*
  $form['task_instruments']['wrapper']['detectors_table_'.$i] = [
    '#type' => 'table',
    '#header' => [
      '#',
      $this->t('Detector Label'),
      $this->t('Detector Status'),
    ],
    '#rows' => [],
    '#attributes' => ['class' => ['detectors-table']],
  ];

  // Add line to table
  foreach ($detectors as $index => $item) {
    $form['task_instruments']['wrapper']['instrument_information_'.$i]["instrument_$i"]['fieldset_'.$i]['instrument_detector_wrapper_'.$i]['detectors_table_'.$i][$index]['checkbox'] = [
      '#type' => 'checkbox',
      '#attributes' => [
        'value' => $item['uri'],
        'disabled' => $item['status'] !== 'Draft',
      ],
      '#value' => TRUE
    ];
    $form['task_instruments']['wrapper']['instrument_information_'.$i]["instrument_$i"]['fieldset_'.$i]['instrument_detector_wrapper_'.$i]['detectors_table_'.$i][$index]['label'] = [
      '#plain_text' => $item['name'] ?: $this->t('Unknown'),
    ];
    $form['task_instruments']['wrapper']['instrument_information_'.$i]["instrument_$i"]['fieldset_'.$i]['instrument_detector_wrapper_'.$i]['detectors_table_'.$i][$index]['status'] = [
      '#plain_text' => $item['status'] ?: $this->t('Unknown'),
    ];
  }
  */

}
