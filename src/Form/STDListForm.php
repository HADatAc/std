<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\ListKeywordPage;
use Drupal\rep\Entity\StudyObject;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\std\Entity\Study;
use Drupal\std\Entity\StudyRole;
use Drupal\std\Entity\StudyObjectCollection;
use Drupal\std\Entity\VirtualColumn;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\std\Entity\WorkflowStem;
use Drupal\std\Entity\Workflow;

/**
 * Provides a STD List Form with table and card view logic.
 *
 * By default, the view is "table" and the default element type is "dsg".
 */
class STDListForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_list_form';
  }

  /**
   * List of items.
   *
   * @var mixed
   */
  protected $list;

  /**
   * Total number of items.
   *
   * @var mixed
   */
  protected $list_size;

  /**
   * Returns the list.
   */
  public function getList() {
    return $this->list;
  }

  /**
   * Sets the list.
   */
  public function setList($list) {
    $this->list = $list;
  }

  /**
   * Returns the list size.
   */
  public function getListSize() {
    return $this->list_size;
  }

  /**
   * Sets the list size.
   */
  public function setListSize($list_size) {
    $this->list_size = $list_size;
  }

  /**
   * {@inheritdoc}
   *
   * The form builds a header with the title and two view toggle buttons (Table View
   * and Card View) placed on the right. The element type defaults to "dsg" if not provided.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype=NULL, $keyword=NULL, $page=NULL, $pagesize=NULL) {

    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';
    $preferred_process = \Drupal::config('rep.settings')->get('preferred_process') ?? 'workflow';

    // dpm($elementtype);
    // CSS and JS library
    $form['#attached']['library'][] = 'std/std_js_css';

    // Retrieve persistent view type from the session; default to 'table'.
    $session = \Drupal::request()->getSession();
    $view_type = $session->get('std_view_type', 'table');

    // Check if a view type is provided via query parameter; update session if so.
    $input_view_type = \Drupal::request()->query->get('view_type');
    if (!empty($input_view_type)) {
      $view_type = $input_view_type;
      $session->set('std_view_type', $view_type);
    }

    // Retrieve element type; default to 'dsg' if not provided.
    // $elementtype = \Drupal::request()->query->get('elementtype');
    if (empty($elementtype)) {
      $elementtype = 'dsg';
    }
    $form_state->set('std_current_elementtype', (string) $elementtype);
    // Route params + keyword filter (defaults from route)
    $page = $page ?? 1;
    $pagesize = $pagesize ?? 9;

    $text_filter = $form_state->getValue('text_filter');
    if ($text_filter === NULL) {
      $text_filter = ($keyword !== NULL && $keyword !== '_' ? $keyword : '');
    }
    $keyword_param = ($text_filter === NULL || $text_filter === '') ? '_' : $text_filter;

    // Get total number of elements.
    $this->setListSize(-1);
    if ($elementtype != NULL) {
      $this->setListSize(ListKeywordPage::total($elementtype, $keyword_param));
    }
    if (gettype($this->list_size) == 'string') {
      $total_pages = 0;
    }
    else {
      $total_pages = ($this->list_size % $pagesize === 0) ? $this->list_size / $pagesize : floor($this->list_size / $pagesize) + 1;
    }

    // Create next/previous page links for table view.
    if ($view_type == 'table') {
      if ($page < $total_pages) {
        $next_page = $page + 1;
        $next_page_link = ListKeywordPage::link($elementtype, $keyword_param, $next_page, $pagesize);
      }
      else {
        $next_page_link = '';
      }
      if ($page > 1) {
        $previous_page = $page - 1;
        $previous_page_link = ListKeywordPage::link($elementtype, $keyword_param, $previous_page, $pagesize);
      }
      else {
        $previous_page_link = '';
      }
    }

    // Card-view Load More handling.
    if ($view_type == 'cards') {
      $triggering_element = $form_state->getTriggeringElement();

      if ($triggering_element && (($triggering_element['#name'] ?? '') === 'load_more')) {
        $current_pagesize = (int) ($form_state->get('pagesize') ?? $pagesize);
        $form_state->set('previous_pagesize', $current_pagesize);
        $current_pagesize += 9;
        $form_state->set('pagesize', $current_pagesize);
        $pagesize = $current_pagesize;
      }
      else {
        $form_state->set('previous_pagesize', NULL);
        $form_state->set('pagesize', (int) $pagesize);
      }

      // For infinite scroll, keep the page number fixed (e.g., 1).
      $page = 1;
    }

    // Retrieve elements using a custom method.
    $this->setList(ListKeywordPage::exec($elementtype, $keyword_param, $page, $pagesize));

    // Initialize variables for class name, header, and output.
    $class_name = "";
    $header = [];
    $output = [];

    // dpm($elementtype);
    // Build output based on element type and view type.
    switch ($elementtype) {
      case "dsg":
        $class_name = "DSGs";
        if ($view_type == 'table') {
          $header = MetadataTemplate::generateHeader();
          $output = MetadataTemplate::generateOutput('dsg', $this->getList());
        }
        else if ($view_type == 'cards') {
          $output = MetadataTemplate::generateOutputAsCards('dsg', $this->getList());
        }
        break;

      case "dd":
        $class_name = "DDs";
        if ($view_type == 'table') {
          $header = MetadataTemplate::generateHeader();
          $output = MetadataTemplate::generateOutput('dd', $this->getList());
        }
        else if ($view_type == 'cards') {
          $output = MetadataTemplate::generateOutputAsCards('dd', $this->getList());
        }
        break;

      case "sdd":
        $class_name = "SDDs";
        if ($view_type == 'table') {
          $header = MetadataTemplate::generateHeader();
          $output = MetadataTemplate::generateOutput('sdd', $this->getList());
        }
        else if ($view_type == 'cards') {
          $output = MetadataTemplate::generateOutputAsCards('sdd', $this->getList());
        }
        break;

      case "da":
        $class_name = "DAs";
        if ($view_type == 'table') {
          $header = MetadataTemplate::generateHeader();
          $output = MetadataTemplate::generateOutput('da', $this->getList());
        }
        else if ($view_type == 'cards') {
          $output = MetadataTemplate::generateOutputAsCards('da', $this->getList());
        }
        break;

      case "study":
        $class_name = ucfirst($preferred_study)."s";
        if ($view_type == 'table') {
          $header = Study::generateHeader();
          $output = Study::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = Study::generateOutputAsCard($this->getList(), \Drupal::currentUser()->getEmail());
        }
        break;

      case "studyrole":
        $class_name = ucfirst($preferred_study)." Role";
        if ($view_type == 'table') {
          $header = StudyRole::generateHeader();
          $output = StudyRole::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = StudyRole::generateOutputCards($this->getList());
        }
        break;

      case "studyobjectcollection":
        $class_name = ucfirst($preferred_study)." Object Collections";
        if ($view_type == 'table') {
          $header = StudyObjectCollection::generateHeader();
          $output = StudyObjectCollection::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = StudyObjectCollection::generateOutputCards($this->getList());
        }
        break;

      case "studyobject":
        $class_name = ucfirst($preferred_study)." Object";
        if ($view_type == 'table') {
          $header = StudyObject::generateHeader();
          $output = StudyObject::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = StudyObject::generateOutputCards($this->getList());
        }
        break;

      case "virtualcolumn":
        $class_name = "Virtual Column";
        if ($view_type == 'table') {
          $header = VirtualColumn::generateHeader();
          $output = VirtualColumn::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = VirtualColumn::generateOutputCards($this->getList());
        }
        break;

      // PROCESS STEM
      case "workflowstem":
        $class_name = ucfirst($preferred_process)." Stems";
        $header = WorkflowStem::generateHeader();
        $output = WorkflowStem::generateOutput($this->getList());
        break;

      // PROCESS
      case "workflow":
        $class_name = ucfirst($preferred_process)."s";
        $header = Workflow::generateHeader();
        $output = Workflow::generateOutput($this->getList());
        break;

      default:
        $class_name = "Objects of Unknown Types";
    }

    $form_state->set('std_current_class_name', (string) $class_name);

    // Build header container with title and view toggle buttons.
    $current_route = \Drupal::routeMatch()->getRouteName();
    $current_parameters = \Drupal::routeMatch()->getParameters()->all();
    $table_url = Url::fromRoute($current_route, $current_parameters, ['query' => ['view_type' => 'table']]);
    $cards_url = Url::fromRoute($current_route, $current_parameters, ['query' => ['view_type' => 'cards']]);

    $form['header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['header-container'],
        'style' => 'display: flex; justify-content: space-between; align-items: flex-start;',
      ],
    ];
    $form['header']['title'] = [
      '#type' => 'item',
      '#markup' => t('<h3>Available <font style="color:DarkGreen;">' . $class_name . '</font></h3>'),
    ];

    // Right-side controls: view toggle on top, filters below.
    $form['header']['right_controls'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'flex-column', 'align-items-end', 'gap-2'],
      ],
    ];

    $form['header']['right_controls']['view_toggle'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-toggle', 'd-flex', 'justify-content-end']],
    ];

    // Table view button.
    $form['header']['right_controls']['view_toggle']['table_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_table',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => ['table-view-button', 'fa-xl', 'mx-1'],
        'title' => $this->t('Table View'),
      ],
      // Prevent form validation for this button.
      '#limit_validation_errors' => [],
      // Use a custom submit handler.
      '#submit' => ['::setTableView'],
    ];

    // Card view button.
    $form['header']['right_controls']['view_toggle']['card_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_card',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => ['card-view-button', 'fa-xl'],
        'title' => $this->t('Card View'),
      ],
      '#limit_validation_errors' => [],
      '#submit' => ['::setCardView'],
    ];

    // Add active class based on the current view type.
    if ($view_type == 'table') {
      $form['header']['right_controls']['view_toggle']['table_view']['#attributes']['class'][] = 'selected-button';
    } elseif ($view_type == 'cards') {
      $form['header']['right_controls']['view_toggle']['card_view']['#attributes']['class'][] = 'selected-button';
    }

    // Keyword filter UI for table and card views.
    $ajax_callback = ($view_type == 'cards') ? '::ajaxReloadCards' : '::ajaxReloadTable';
    $ajax_wrapper = ($view_type == 'cards') ? 'cards-lazy-wrapper' : 'element-table-wrapper';

    $form['header']['right_controls']['filter_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'ms-auto', 'mb-0'],
        'style' => 'margin-bottom:0!important;'
      ],
    ];

    $form['header']['right_controls']['filter_container']['filter_label'] = [
      '#type' => 'label',
      '#title' => $this->t('Filter(s): '),
      '#attributes' => [
        'class' => ['pt-3', 'me-2', 'fw-bold'],
      ]
    ];

    $form['header']['right_controls']['filter_container']['text_filter'] = [
      '#type' => 'textfield',
      '#default_value' => $text_filter,
      '#ajax' => [
        'callback' => $ajax_callback,
        'wrapper' => $ajax_wrapper,
        'event' => 'change',
      ],
      '#attributes' => [
        'class' => ['form-select', 'w-auto', 'mt-2', 'me-1'],
        'style' => 'max-width:230px;margin-bottom:0!important;float:right;',
        'placeholder' => 'Type in your search criteria',
        'onkeydown' => 'if (event.keyCode == 13) { event.preventDefault(); this.blur(); }',
      ],
    ];

    // Build form content based on view type.
    if ($view_type == 'table') {
      $form['element_table_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'element-table-wrapper'],
      ];

      // Render table view.
      $form['element_table_wrapper']['content'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $output,
        '#empty' => $this->t('No response options found'),
      ];
      $form['element_table_wrapper']['pager'] = [
        '#theme' => 'list-page',
        '#items' => [
          'page' => strval($page),
          'first' => ListKeywordPage::link($elementtype, $keyword_param, 1, $pagesize),
          'last' => ListKeywordPage::link($elementtype, $keyword_param, $total_pages, $pagesize),
          'previous' => $previous_page_link,
          'next' => $next_page_link,
          'last_page' => strval($total_pages),
          'links' => null,
          'title' => ' ',
        ],
      ];
    }
    else if ($view_type == 'cards') {
      $form['cards_lazy_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'cards-lazy-wrapper'],
      ];

      $form['cards_lazy_wrapper']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'card-container-wrapper',
          'class' => ['card-container', 'row'],
          'style' => 'margin-bottom: 2rem!important;',
        ],
      ];

      if (!empty($output)) {
        foreach ($output as $card_render) {
          $form['cards_lazy_wrapper']['content'][] = $card_render;
        }
      }
      else {
        $form['cards_lazy_wrapper']['content']['no_results'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['col-12']],
          'message' => [
            '#markup' => '<div class="alert alert-info mb-0">'
              . $this->t('No @items found for the current filters.', ['@items' => $class_name])
              . '</div>',
          ],
        ];
      }

      $form['cards_lazy_wrapper']['records_count'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div id="count-cards" style="font-weight:bold; margin-top:10px; padding-right:2rem;">Currently viewing @count of @total @class</div>', [
          '@count' => count($this->getList()),
          '@total' => (int) $this->getListSize(),
          '@class' => $class_name,
        ]),
      ];

      $form['cards_lazy_wrapper']['load_more_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'std-list-load-more-wrapper',
          'class' => ['d-flex', 'justify-content-center', 'mt-4', 'w-100'],
        ],
      ];

      $form['cards_lazy_wrapper']['load_more_status'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'std-list-load-more-status',
          'class' => ['text-center', 'text-muted', 'mt-2'],
          'role' => 'status',
          'aria-live' => 'polite',
          'aria-atomic' => 'true',
        ],
      ];

      // Only add the "Load More" button if there are more elements to load.
      if ((int) $this->list_size > (int) $pagesize) {
        $form['cards_lazy_wrapper']['load_more_wrapper']['load_more'] = [
          '#type' => 'button',
          '#value' => $this->t('Load More'),
          '#name' => 'load_more',
          '#ajax' => [
            'callback' => '::loadMoreCallback',
            'wrapper' => 'card-container-wrapper',
            'effect' => 'fade',
            'progress' => [
              'type' => 'throbber',
              'message' => $this->t('Loading more items...'),
            ],
          ],
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'w-25'],
          ],
        ];
      }
    }

    return $form;
  }

  /**
   * AJAX callback to reload table when filters change.
   */
  public function ajaxReloadTable(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['element_table_wrapper'];
  }

  /**
   * AJAX callback to reload cards when filters change.
   */
  public function ajaxReloadCards(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['cards_lazy_wrapper'];
  }

  /**
   * Custom submit handler for the Table View button.
   */
  public function setTableView(array &$form, FormStateInterface $form_state) {
    $session = \Drupal::request()->getSession();
    // Set the persistent view type to 'table'.
    $session->set('std_view_type', 'table');
    // Redirect to the current page.
    $form_state->setRedirect('<current>');
  }

  /**
   * Custom submit handler for the Card View button.
   */
  public function setCardView(array &$form, FormStateInterface $form_state) {
    $session = \Drupal::request()->getSession();
    // Set the persistent view type to 'cards'.
    $session->set('std_view_type', 'cards');
    // Redirect to the current page.
    $form_state->setRedirect('<current>');
  }

  /**
   * AJAX callback for "Load More" in card view.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The updated content container.
   */
  public function loadMoreCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $previous = (int) ($form_state->get('previous_pagesize') ?? 0);
    $currentList = is_array($this->getList()) ? $this->getList() : [];
    $currentCount = count($currentList);
    $previous = max(0, min($previous, $currentCount));

    $newItems = array_slice($currentList, $previous);
    $elementtype = (string) ($form_state->get('std_current_elementtype') ?? '');
    $newCardsBuild = $this->buildCardsForElementType($elementtype, $newItems);
    $has_more = ((int) $currentCount < (int) $this->getListSize());

    if (!empty($newCardsBuild)) {
      $rendered = (string) \Drupal::service('renderer')->renderPlain($newCardsBuild);
      if (trim($rendered) !== '') {
        $response->addCommand(new AppendCommand('#card-container-wrapper', $rendered));
      }
    }

    if (isset($form['cards_lazy_wrapper']['load_more_wrapper'])) {
      $response->addCommand(new ReplaceCommand('#std-list-load-more-wrapper', $form['cards_lazy_wrapper']['load_more_wrapper']));
    }

    $current_class_name = (string) ($form_state->get('std_current_class_name') ?? 'items');
    $count_markup = '<div id="count-cards" style="font-weight:bold; margin-top:10px; padding-right:2rem;">'
      . $this->t('Currently viewing @count of @total @class', [
        '@count' => $currentCount,
        '@total' => (int) $this->getListSize(),
        '@class' => $current_class_name,
      ])
      . '</div>';
    $response->addCommand(new ReplaceCommand('#count-cards', $count_markup));

    $status_markup = $has_more
      ? ''
      : '<span class="text-muted">' . $this->t('No more items to load.') . '</span>';
    $response->addCommand(new HtmlCommand('#std-list-load-more-status', $status_markup));

    $response->addCommand(new InvokeCommand('html, body', 'animate', [
      ['scrollTop' => 99999],
      'slow'
    ]));


    return $response;
  }

  protected function buildCardsForElementType(string $elementtype, array $items): array {
    switch ($elementtype) {
      case 'dsg':
        return MetadataTemplate::generateOutputAsCards('dsg', $items);
      case 'dd':
        return MetadataTemplate::generateOutputAsCards('dd', $items);
      case 'sdd':
        return MetadataTemplate::generateOutputAsCards('sdd', $items);
      case 'da':
        return MetadataTemplate::generateOutputAsCards('da', $items);
      case 'study':
        return Study::generateOutputAsCard($items, \Drupal::currentUser()->getEmail());
      case 'studyrole':
        return StudyRole::generateOutputCards($items);
      case 'studyobjectcollection':
        return StudyObjectCollection::generateOutputCards($items);
      case 'studyobject':
        return StudyObject::generateOutputCards($items);
      case 'virtualcolumn':
        return VirtualColumn::generateOutputCards($items);
      default:
        return [];
    }
  }

  /**
   * {@inheritdoc}
   *
   * No submit handler is needed.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submission logic required.
  }

}

