<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\HtmlTag;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Component\Serialization\Json;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Utils;
use Drupal\std\Entity\Study;

class STDSelectStudyForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_select_study_form';
  }

  public $element_type;

  public $manager_email;

  public $manager_name;

  public $single_class_name;

  public $plural_class_name;

  protected $list;

  protected $list_size;

  public function getList() {
    return $this->list;
  }

  public function setList($list) {
    return $this->list = $list;
  }

  public function getListSize() {
    return $this->list_size;
  }

  public function setListSize($list_size) {
    return $this->list_size = $list_size;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype=NULL, $page=NULL, $pagesize=NULL) {

    // GET MANAGER EMAIL
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
    $this->element_type = $elementtype;
    $this->setListSize(-1);
    if ($this->element_type != NULL) {
        $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
    }
    if (gettype($this->list_size) == 'string') {
        $total_pages = "0";
    } else {
        if ($this->list_size % $pagesize == 0) {
            $total_pages = $this->list_size / $pagesize;
        } else {
            $total_pages = floor($this->list_size / $pagesize) + 1;
        }
    }

    // CREATE LINK FOR NEXT PAGE AND PREVIOUS PAGE
    if ($page < $total_pages) {
        $next_page = $page + 1;
        $next_page_link = ListManagerEmailPage::link($this->element_type, $next_page, $pagesize);
    } else {
        $next_page_link = '';
    }
    if ($page > 1) {
        $previous_page = $page - 1;
        $previous_page_link = ListManagerEmailPage::link($this->element_type, $previous_page, $pagesize);
    } else {
        $previous_page_link = '';
    }

    // RETRIEVE ELEMENTS
    $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, $page, $pagesize));

    $this->single_class_name = "";
    $this->plural_class_name = "";
    switch ($this->element_type) {
      case "study":
        $this->single_class_name = "Study";
        $this->plural_class_name = "Studies";
        $header = Study::generateHeader();
        $output = Study::generateOutputAsCard($this->getList());
        break;
      default:
        $this->single_class_name = "Object of Unknown Type";
        $this->plural_class_name = "Objects of Unknown Types";
    }

    // PUT FORM TOGETHER
    $form['page_title'] = [
        '#type' => 'item',
        '#title' => $this->t('<h3 class="mt-5">Manage ' . $this->plural_class_name . '</h3>'),
    ];
    $form['page_subtitle'] = [
        '#type' => 'item',
        '#title' => $this->t('<h4>' . $this->plural_class_name . ' maintained by <font color="DarkGreen">' . $this->manager_name . ' (' . $this->manager_email . ')</font></h4>'),
    ];
    $form['add_element'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add New ' . $this->single_class_name),
        '#name' => 'add_element',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'add-element-button'],
        ],
    ];
    $form['space1'] = [
      '#type' => 'item',
      '#value' => $this->t('<br>'),
    ];

    // Loop through $output and creates two cards per row
    $index = 0;
    foreach (array_chunk($output, 2, true) as $row) {
        $index++;
        $form['row_' . $index] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['row', 'mb-3'],
            ],
        ];
        $indexCard = 0;
        foreach ($row as $uri => $card) {
            $indexCard++;
            $form['row_' . $index]['element_' . $indexCard] = $card;
        }
    }

    $form['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
          'page' => strval($page),
          'first' => ListManagerEmailPage::link($this->element_type, 1, $pagesize),
          'last' => ListManagerEmailPage::link($this->element_type, $total_pages, $pagesize),
          'previous' => $previous_page_link,
          'next' => $next_page_link,
          'last_page' => strval($total_pages),
          'links' => null,
          'title' => ' ',
      ],
    ];
    $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#name' => 'back',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'back-button'],
        ],
    ];
    $form['space2'] = [
        '#type' => 'item',
        '#value' => $this->t('<br><br><br>'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // SET USER ID AND PREVIOUS URL FOR TRACKING STORE URLS
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();

    // RETRIEVE SELECTED ROWS, IF ANY
    $selected_rows = $form_state->getValue('element_table');
    $rows = [];
    if ($selected_rows != NULL) {
      foreach ($selected_rows as $index => $selected) {
        if ($selected) {
          $rows[$index] = $index;
        }
      }
    }

    // ADD ELEMENT
    if ($button_name === 'add_element') {
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_study');
        $url = Url::fromRoute('std.add_study');
      $form_state->setRedirectUrl($url);
    }

    if ($button_name === 'back') {
      $url = Url::fromRoute('std.search');
      $form_state->setRedirectUrl($url);
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function backSelect($elementType) {
    $url = Url::fromRoute('rep.home');
    return $url;
  }

}
