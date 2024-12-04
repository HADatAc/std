<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Component\Serialization\Json;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPageByStudy;
use Drupal\rep\Utils;
use Drupal\rep\Entity\MetadataTemplate;

class STDSelectByStudyCompactForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_select_bystudy_compact_form';
  }

  public $study;

  public $element_type;

  public $manager_email;

  public $manager_name;

  public $single_class_name;

  public $plural_class_name;

  protected $mode;

  protected $list;

  protected $list_size;

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($study) {
    return $this->study = $study;
  }

  public function getMode() {
    return $this->mode;
  }

  public function setMode($mode) {
    return $this->mode = $mode;
  }

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
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL, $elementtype = NULL, $mode = NULL, $page = NULL, $pagesize = NULL) {

    // GET MANAGER EMAIL
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;

    // GET MODE
    $this->mode = $mode;

    // GET STUDY
    $api = \Drupal::service('rep.api_connector');
    $decoded_studyuri = base64_decode($studyuri);
    $study = $api->parseObjectResponse($api->getUri($decoded_studyuri),'getUri');
    if ($study == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Study."));
      self::backUrl();
    } else {
      $this->setStudy($study);
    }

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
    $this->element_type = $elementtype;
    $this->setListSize(-1);
    if ($this->element_type != NULL) {
      $this->setListSize(ListManagerEmailPageByStudy::total($this->getStudy()->uri, $this->element_type, $this->manager_email));
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
      $next_page_link = ListManagerEmailPageByStudy::link($this->getStudy()->uri, $this->element_type, $next_page, $pagesize);
    } else {
      $next_page_link = '';
    }
    if ($page > 1) {
      $previous_page = $page - 1;
      $previous_page_link = ListManagerEmailPageByStudy::link($this->getStudy()->uri, $this->element_type, $previous_page, $pagesize);
    } else {
      $previous_page_link = '';
    }

    // RETRIEVE ELEMENTS
    $this->setList(ListManagerEmailPageByStudy::exec($this->getStudy()->uri, $this->element_type, $this->manager_email, $page, $pagesize));

    //dpm($this->element_type);
    //dpm($this->getList());

    $this->single_class_name = "";
    $this->plural_class_name = "";
    switch ($this->element_type) {

      // ELEMENTS
      case "da":
        $this->single_class_name = "DA";
        $this->plural_class_name = "DAs";
        $header = MetadataTemplate::generateHeaderCompact();
        $output = MetadataTemplate::generateOutputCompact('da',$this->getList());
        break;
      default:
        $this->single_class_name = "Object of Unknown Type";
        $this->plural_class_name = "Objects of Unknown Types";
    }

    // ADD TABLE
    $form['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => t('No ' . $this->plural_class_name . ' found'),
    ];

    $form['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
        'page' => strval($page),
        'first' => ListManagerEmailPageByStudy::link($this->getStudy()->uri, $this->element_type, 1, $pagesize),
        'last' => ListManagerEmailPageByStudy::link($this->getStudy()->uri, $this->element_type, $total_pages, $pagesize),
        'previous' => $previous_page_link,
        'next' => $next_page_link,
        'last_page' => strval($total_pages),
        'links' => null,
        'title' => ' ',
      ],
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
    if ($this->getMode() == 'table') {
      foreach ($selected_rows as $index => $selected) {
        if ($selected) {
          $rows[$index] = $index;
        }
      }
    }

    // BACK TO MAIN PAGE
    if ($button_name === 'back') {
      self::backUrl();
    }

    // ADD ELEMENT
    if ($button_name === 'add_element') {
      if ($this->element_type == 'da') {
        Utils::trackingStoreUrls($uid, $previousUrl, 'rep.add_mt');
        if ($this->getStudy() == NULL || $this->getStudy()->uri == NULL) {
          $url = Url::fromRoute('rep.add_mt', [
            'elementtype' => $this->element_type,
            'studyuri' => 'none',
            'fixstd' => 'F',
          ]);
        } else {
          $url = Url::fromRoute('rep.add_mt', [
            'elementtype' => $this->element_type,
            'studyuri' => base64_encode($this->getStudy()->uri),
            'fixstd' => 'T',
          ]);
        }
      }
      if ($this->element_type == 'studyrole') {
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_studyrole');
        if ($this->getStudy() == NULL || $this->getStudy()->uri == NULL) {
          $url = Url::fromRoute('std.add_studyrole', [
            'studyuri' => 'none',
            'fixstd' => 'F',
          ]);
        } else {
          $url = Url::fromRoute('std.add_studyrole', [
            'studyuri' => base64_encode($this->getStudy()->uri),
            'fixstd' => 'T',
          ]);
        }
      }
      if ($this->element_type == 'studyobjectcollection') {
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_studyobjectcollection');
        if ($this->getStudy() == NULL || $this->getStudy()->uri == NULL) {
          $url = Url::fromRoute('std.add_studyobjectcollection', [
            'studyuri' => 'none',
            'fixstd' => 'F',
          ]);
        } else {
          $url = Url::fromRoute('std.add_studyobjectcollection', [
            'studyuri' => base64_encode($this->getStudy()->uri),
            'fixstd' => 'T',
          ]);
        }
      }
      if ($this->element_type == 'studyobject') {
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_studyobject');
        $url = Url::fromRoute('std.add_studyobject');
      }
      if ($this->element_type == 'virtualcolumn') {
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_virtualcolumn');
        if ($this->getStudy() == NULL || $this->getStudy()->uri == NULL) {
          $url = Url::fromRoute('std.add_virtualcolumn', [
            'studyuri' => 'none',
            'fixstd' => 'F',
          ]);
        } else {
          $url = Url::fromRoute('std.add_virtualcolumn', [
            'studyuri' => base64_encode($this->getStudy()->uri),
            'fixstd' => 'T',
          ]);
        }
      }
      $form_state->setRedirectUrl($url);
    }

    // EDIT ELEMENT
    if ($button_name === 'edit_element') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select the exact " . $this->single_class_name . " to be edited."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("No more than one " . $this->single_class_name . " can be edited at once."));
      } else {
        $first = array_shift($rows);
        //if ($this->element_type == 'da') {
        //  Utils::trackingStoreUrls($uid, $previousUrl, 'rep.edit_mt');
        //  $url = Url::fromRoute('rep.edit_mt', [
        //    'elementtype' => $this->element_type,
        //    'elementuri' => base64_encode($first),
        //    'fixstd' => 'T',
        //  ]);
        //}
        if ($this->element_type == 'studyrole') {
          Utils::trackingStoreUrls($uid, $previousUrl, 'std.edit_studyrole');
          $url = Url::fromRoute('std.edit_studyrole', [
            'studyroleuri' => base64_encode($first),
            'fixstd' => 'T',
          ]);
        }
        if ($this->element_type == 'studyobjectcollection') {
          Utils::trackingStoreUrls($uid, $previousUrl, 'std.edit_studyobjectcollection');
          $url = Url::fromRoute('std.edit_studyobjectcollection', [
            'studyobjectcollectionuri' => base64_encode($first)
          ]);
        }
        if ($this->element_type == 'studyobject') {
          Utils::trackingStoreUrls($uid, $previousUrl, 'std.edit_studyobject');
          $url = Url::fromRoute('std.edit_studyobject', [
            'studyobjecturi' => base64_encode($first)
          ]);
        }
        if ($this->element_type == 'virtualcolumn') {
          Utils::trackingStoreUrls($uid, $previousUrl, 'std.edit_virtualcolumn');
          $url = Url::fromRoute('std.edit_virtualcolumn', [
            'virtualcolumnuri' => base64_encode($first),
            'fixstd' => 'T',
          ]);
        }
        $form_state->setRedirectUrl($url);
      }
    }

    // DELETE ELEMENT
    if ($button_name === 'delete_element') {
      if (sizeof($rows) <= 0) {
        \Drupal::messenger()->addWarning(t("At least one " . $this->single_class_name . " needs to be selected to be deleted."));
      } else {
        $api = \Drupal::service('rep.api_connector');
        foreach($rows as $uri) {
          //if ($this->element_type == 'da') {
          //  $mt = $api->parseObjectResponse($api->getUri($uri),'getUri');
          //  if ($mt != NULL && $mt->hasDataFile != NULL) {
          //    // DELETE FILE
          //    if (isset($mt->hasDataFile->id)) {
          //      $file = File::load($mt->hasDataFile->id);
          //      if ($file) {
          //        $file->delete();
          //        \Drupal::messenger()->addMessage(t("Deleted file with following ID: ".$mt->hasDataFile->id));
          //      }
          //    }
          //    // DELETE DATAFILE
          //    if (isset($mt->hasDataFile->uri)) {
          //      $api->dataFileDel($mt->hasDataFile->uri);
          //      \Drupal::messenger()->addMessage(t("Deleted DataFile with following URI: ".$mt->hasDataFile->uri));
          //    }
          //  }
          //}
          if ($this->element_type == 'studyrole') {
            $api->studyRoleDel($uri);
          }
          if ($this->element_type == 'studyobjectcollection') {
            $api->studyObjectCollectionDel($uri);
          }
          if ($this->element_type == 'studyobject') {
            $api->elementDel('studyobject',$uri);
          }
          if ($this->element_type == 'virtualcolumn') {
            $api->virtualColumnDel($uri);
          }
        }
        \Drupal::messenger()->addMessage(t("Selected " . $this->plural_class_name . " has/have been deleted successfully."));
      }
    }

    // MANAGE STUDY OBJECTS
    if ($button_name === 'manage_studyobject') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select the exact " . $this->single_class_name . "'s objects to be managed."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Objects from no more than one " . $this->single_class_name . " can be edited at once."));
      } else {
        $first = array_shift($rows);
        Utils::trackingStoreUrls($uid, $previousUrl, 'std.select_element_bysoc');
        $url = Url::fromRoute('std.select_element_bysoc', [
          'socuri' => base64_encode($first),
          'elementtype' => 'studyobject',
          'page' => '1',
          'pagesize' => '12',
        ]);
        $form_state->setRedirectUrl($url);
      }
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.select_element_bystudy');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }


}
