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
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ScrollCommand;

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
    // // Retrieve keyword, page and pagesize from query parameters.
    $keyword = $keyword;
    $page = $page ?? 1;
    $pagesize = $pagesize ?? 9;

    // Get total number of elements.
    $this->setListSize(-1);
    if ($elementtype != NULL) {
      $this->setListSize(ListKeywordPage::total($elementtype, $keyword));
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
        $next_page_link = ListKeywordPage::link($elementtype, $keyword, $next_page, $pagesize);
      }
      else {
        $next_page_link = '';
      }
      if ($page > 1) {
        $previous_page = $page - 1;
        $previous_page_link = ListKeywordPage::link($elementtype, $keyword, $previous_page, $pagesize);
      }
      else {
        $previous_page_link = '';
      }
    }

    // For card view with infinite scroll, double the pagesize on each AJAX update.
    if ($view_type == 'cards') {
      $current_pagesize = $form_state->get('pagesize');
      if (empty($current_pagesize)) {
        $current_pagesize = $pagesize;
      }
      else {
        $current_pagesize *= 2;
      }
      $pagesize = $current_pagesize;
      $form_state->set('pagesize', $current_pagesize);
      // For infinite scroll, keep the page number fixed (e.g., 1).
      $page = 1;
    }

    // Retrieve elements using a custom method.
    $this->setList(ListKeywordPage::exec($elementtype, $keyword, $page, $pagesize));

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
        $class_name = "Studies";
        if ($view_type == 'table') {
          $header = Study::generateHeader();
          $output = Study::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = Study::generateOutputAsCard($this->getList(), \Drupal::currentUser()->getEmail());
        }
        break;

      case "studyrole":
        $class_name = "Study Role";
        if ($view_type == 'table') {
          $header = StudyRole::generateHeader();
          $output = StudyRole::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = StudyRole::generateOutputCards($this->getList());
        }
        break;

      case "studyobjectcollection":
        $class_name = "Study Object Collections";
        if ($view_type == 'table') {
          $header = StudyObjectCollection::generateHeader();
          $output = StudyObjectCollection::generateOutput($this->getList());
        }
        else if ($view_type == 'cards') {
          $output = StudyObjectCollection::generateOutputCards($this->getList());
        }
        break;

      case "studyobject":
        $class_name = "Study Object";
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

      default:
        $class_name = "Objects of Unknown Types";
    }

    // Build header container with title and view toggle buttons.
    $current_route = \Drupal::routeMatch()->getRouteName();
    $current_parameters = \Drupal::routeMatch()->getParameters()->all();
    $table_url = Url::fromRoute($current_route, $current_parameters, ['query' => ['view_type' => 'table']]);
    $cards_url = Url::fromRoute($current_route, $current_parameters, ['query' => ['view_type' => 'cards']]);

    $form['header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['header-container'],
        'style' => 'display: flex; justify-content: space-between; align-items: center;',
      ],
    ];
    $form['header']['title'] = [
      '#type' => 'item',
      '#markup' => t('<h3>Available <font style="color:DarkGreen;">' . $class_name . '</font></h3>'),
    ];

    $form['header']['view_toggle'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-toggle', 'd-flex', 'justify-content-end']],
    ];

    // Table view button.
    $form['header']['view_toggle']['table_view'] = [
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
    $form['header']['view_toggle']['card_view'] = [
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
      $form['header']['view_toggle']['table_view']['#attributes']['class'][] = 'view-active';
    } elseif ($view_type == 'cards') {
      $form['header']['view_toggle']['card_view']['#attributes']['class'][] = 'view-active';
    }

    // Build form content based on view type.
    if ($view_type == 'table') {
      // Render table view.
      $form['content'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $output,
        '#empty' => $this->t('No response options found'),
      ];
      $form['pager'] = [
        '#theme' => 'list-page',
        '#items' => [
          'page' => strval($page),
          'first' => ListKeywordPage::link($elementtype, $keyword, 1, $pagesize),
          'last' => ListKeywordPage::link($elementtype, $keyword, $total_pages, $pagesize),
          'previous' => $previous_page_link,
          'next' => $next_page_link,
          'last_page' => strval($total_pages),
          'links' => null,
          'title' => ' ',
        ],
      ];
    }
    else if ($view_type == 'cards') {
      // Render card view container with AJAX wrapper.
      $form['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'card-container-wrapper',
          'class' => ['card-container'],
        ],
      ];

      // Add each card render array as a child of the container.
      foreach ($output as $card) {
        $form['content'][] = $card;
      }

      // Only add the "Load More" button if the total number of elements is greater than the pagesize.
      // dpm($current_pagesize);
      if ($this->list_size > $current_pagesize) {
        $form['content']['load_more_wrapper'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['d-flex', 'justify-content-center', 'mt-4', 'w-100'],
          ],
        ];

        $form['content']['load_more_wrapper']['load_more'] = [
          '#type' => 'button',
          '#value' => $this->t('Load More'),
          '#ajax' => [
            'callback' => '::loadMoreCallback',
            'wrapper' => 'card-container-wrapper',
            'effect' => 'fade',
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

    // Substitui o container com os novos cards.
    $response->addCommand(new ReplaceCommand('#card-container-wrapper', $form['content']));

    // Executa um trecho de JavaScript para rolar a página até o container.
    $response->addCommand(new InvokeCommand('html, body', 'animate', [
      ['scrollTop' => 99999],
      'slow'
    ]));


    return $response;
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
