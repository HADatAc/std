<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Entity\StudyObject;
use Drupal\rep\ListManagerEmailPageBySOC;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\Component\Serialization\Json;

class ManageStudyObjectForm extends FormBase {

  public $element_type;

  public $manager_email;

  public $manager_name;

  public $single_class_name;

  public $plural_class_name;

  protected $list;

  protected $list_size;

  protected $studyObjectCollection;

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


  public function getStudyObjectCollection() {
    return $this->studyObjectCollection;
  }

  public function setStudyObjectCollection($studyObjectCollection) {
    return $this->studyObjectCollection = $studyObjectCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'manage_study_object_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $socuri = NULL, $elementtype = NULL, $page = NULL, $pagesize = NULL) {

    // GET MANAGER EMAIL
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;

    // GET SOC
    $api = \Drupal::service('rep.api_connector');
    $decoded_socuri = base64_decode($socuri);
    $soc = $api->parseObjectResponse($api->getUri($decoded_socuri),'getUri');
    if ($soc == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Study Object Collection."));
      self::backUrl();
    } else {
      $this->setStudyObjectCollection($soc);
    }

    kint($soc);

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
    $this->element_type = $elementtype;
    $this->setListSize(-1);
    if ($this->element_type != NULL) {
      $this->setListSize(ListManagerEmailPageBySOC::total($this->getStudyObjectCollection()->uri, $this->element_type, $this->manager_email));
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
      $next_page_link = ListManagerEmailPageBySOC::link($this->getStudyObjectCollection()->uri, $this->element_type, $next_page, $pagesize);
    } else {
      $next_page_link = '';
    }
    if ($page > 1) {
      $previous_page = $page - 1;
      $previous_page_link = ListManagerEmailPageBySOC::link($this->getStudyObjectCollection()->uri, $this->element_type, $previous_page, $pagesize);
    } else {
      $previous_page_link = '';
    }

    // RETRIEVE ELEMENTS
    $this->setList(ListManagerEmailPageBySOC::exec($this->getStudyObjectCollection()->uri, $this->element_type, $this->manager_email, $page, $pagesize));

    //dpm($this->element_type);
    //dpm($this->getList());

    $this->single_class_name = "Study Object";
    $this->plural_class_name = "Study Objects";
    $header = StudyObject::generateHeader();
    $output = StudyObject::generateOutput($this->getList());

    # PUT FORM TOGETHER

    $form['scope'] = [
      '#type' => 'item',
      '#title' => t('<h3>Study: <font color="DarkGreen">' . $this->getStudyObjectCollection()->isMemberOf->label . '</font></h3>'),
    ];
    $form['subscope'] = [
      '#type' => 'item',
      '#title' => t('<h4>Study Object Collection: <font color="DarkGreen">' . $this->getStudyObjectCollection()->label . '</font></h4>'),
    ];
    $form['subtitle'] = [
      '#type' => 'item',
      '#title' => t('<h4>Managed by: <font color="DarkGreen">' . $this->manager_name . ' (' . $this->manager_email . ')</font></h4>'),
    ];
    if ($this->getStudyObjectCollection() != NULL) {
      $form['add_so'] = [
        '#type' => 'submit',
        '#value' => $this->t("Add Study Object"),
        '#name' => 'add_so',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'add-element-button'],
        ],
      ];
    }
    $form['edit_so'] = [
      '#type' => 'submit',
      '#value' => $this->t("Edit Study Object"),
      '#name' => 'edit_so',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-element-button'],
      ],
    ];
    $form['delete_so'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete Selected Study Objects'),
      '#name' => 'delete_sos',
      '#attributes' => [
        'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        'class' => ['btn', 'btn-primary', 'delete-button'],
      ],
    ];

    $form['so_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => t('No response options found'),
    ];
    $form['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
          'page' => strval($page),
          'first' => ListManagerEmailPageBySOC::link($this->getStudyObjectCollection()->uri, $this->element_type, 1, $pagesize),
          'last' => ListManagerEmailPageBySOC::link($this->getStudyObjectCollection()->uri, $this->element_type, $total_pages, $pagesize),
          'previous' => $previous_page_link,
          'next' => $next_page_link,
          'last_page' => strval($total_pages),
          'links' => null,
          'title' => ' ',
      ],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to Study Object Collections'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];
    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br><br>'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];
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
    $selected_rows = $form_state->getValue('so_table');
    $rows = [];
    foreach ($selected_rows as $index => $selected) {
      if ($selected) {
        $rows[$index] = $index;
      }
    }

    // BACK
    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    // ADD STUDY OBJECT
    if ($button_name === 'add_so') {
      Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_studyobject');
      $url = Url::fromRoute('std.add_studyobject');
      $url->setRouteParameter('studyobjectcollectionuri', base64_encode($this->getStudyObjectCollection()->uri));
      $form_state->setRedirectUrl($url);
    }

    // EDIT STUDY OBJECT
    if ($button_name === 'edit_so') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select a Study Object to be edited."));
      } else if (sizeof($rows) > 1) {
        \Drupal::messenger()->addWarning(t("Select one Study Object to be edited. No more than one Study Object can be edited at once."));
      } else {
        $first = array_shift($rows);
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.edit_studyobject');
        $url = Url::fromRoute('std.edit_studyobject');
        $url->setRouteParameter('studyobjecturi', base64_encode($first));
        $form_state->setRedirectUrl($url);
      }
      return;
    }

    // DELETE STUDY OBJECT
    if ($button_name === 'delete_sos') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select Study Objects to be deleted."));
        return;
      } else {
        $api = \Drupal::service('rep.api_connector');
        //dpm($rows);
        foreach($rows as $shortUri) {
          $uri = Utils::plainUri($shortUri);
          try {
            $api->elementDel('studyobject',$uri);
          } catch(\Exception $e) {
            \Drupal::messenger()->addError(t("An error occurred while deleting a Study Object: ".$e->getMessage()));
            self::backUrl();
            return;
          }
        }
        \Drupal::messenger()->addMessage(t("Study object(s) has been deleted successfully."));
      }
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.select_element_bysoc');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
