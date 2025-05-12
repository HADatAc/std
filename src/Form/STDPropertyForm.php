<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\file\Entity\File;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\social\Entity\KGR;
use Drupal\social\Entity\Place;
use Drupal\social\Entity\Organization;
use Drupal\social\Entity\Person;
use Drupal\social\Entity\PostalAddress;

class STDPropertyForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_property_form';
  }

  protected $element;

  protected $property;

  protected $elementType;

  protected $list;

  protected $list_size;

  public function getElement() {
    return $this->element;
  }

  public function setElement($element) {
    return $this->element = $element;
  }

  public function getProperty() {
    return $this->property;
  }

  public function setProperty($property) {
    return $this->property = $property;
  }

  public function getElementType() {
    return $this->elementType;
  }

  public function setElementType($elementType) {
    return $this->elementType = $elementType;
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
  public function buildForm(array $form, FormStateInterface $form_state, $elementuri=NULL, $property=NULL, $elementtype=NULL, $page=NULL, $pagesize=NULL) {

    $uri_decode=base64_decode($elementuri);
    $prop_decode=base64_decode($property);
    $type_decode=base64_decode($elementtype);
    if ($type_decode == '*NULL*') {
      $type_decode = NULL;
    }
    $this->setProperty($prop_decode);

    if ($type_decode != NULL) {
      $this->setElementType($type_decode);
    }

    $api = \Drupal::service('rep.api_connector');
    $element = $api->parseObjectResponse($api->getUri($uri_decode),'getUri');
    if ($element == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve element with uri [" . $uri_decode . "]"));
      $form_state->setRedirectUrl(backProperty($uri_decode));
    } else {
      $this->setElement($element);
    }

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
    $this->setListSize(-1);
    if ($this->getElement() != NULL) {
    $this->setListSize(ListPropertyPage::total($this->getElement(),$this->getProperty(),$this->getElementType()));
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
      $next_page_link = ListPropertyPage::link($this->getElement(), $this->getProperty(), $this->getElementType(), $next_page, $pagesize);
    } else {
      $next_page_link = '';
    }
    if ($page > 1) {
      $previous_page = $page - 1;
      $previous_page_link = ListPropertyPage::link($this->getElement(), $this->getProperty(), $this->getElementType(), $previous_page, $pagesize);
    } else {
      $previous_page_link = '';
    }

    // RETRIEVE ELEMENTS
    $this->setList(ListPropertyPage::exec($this->getElement(), $this->getProperty(), $this->getElementType(), $page, $pagesize));

    //dpm($this->getList());

    // PUT FORM TOGETHER
    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h3>' . $this->getElement()->name . '</h3>'),
    ];
    if ($this->getElementType() == NULL) {
      $form['beginArray'] = [
        '#type' => 'item',
        '#title' => $this->t('<h3>Property: ' . Utils::namespaceUri($this->getProperty()) . '</h3><ul>'),
      ];
    } else {
      $form['beginArray'] = [
        '#type' => 'item',
        '#title' => $this->t('<h3>Property: ' . Utils::namespaceUri($this->getProperty()) . '<br>ElementType: ' . $this->getElementType() . '</h3><ul>'),
      ];
    }
    foreach ($this->getList() as $propertyName => $propertyValue) {
      $form[$propertyName] = [
        '#type' => 'markup',
        '#markup' => $this->t("<li>" . Utils::link($propertyValue->label,$propertyValue->uri) . "-" . $propertyValue->name . "</li>"),
      ];
    }
    $form['endArray'] = [
      '#type' => 'markup',
      '#markup' => $this->t("</ul><br>"),
    ];
    $link_first = ListPropertyPage::link($this->getElement(), $this->getProperty(), $this->getElementType(), 1, $pagesize);
    $link_last = ListPropertyPage::link($this->getElement(), $this->getProperty(), $this->getElementType(), $total_pages, $pagesize);
    $form['pager'] = [
      '#theme' => 'list-modal',
      '#items' => [
        'page' => strval($page),
        'first' => $link_first,
        'last' => $link_last,
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
    $form['space'] = [
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

    // RETRIEVE SELECTED ROWS, IF ANY
    $selected_rows = $form_state->getValue('element_table');
    $rows = [];
    foreach ($selected_rows as $index => $selected) {
      if ($selected) {
        $rows[$index] = $index;
      }
    }

    // BACK TO MAIN PAGE
    if ($button_name === 'back') {
      $form_state->setRedirectUrl($this->backProperty($this->getElement()->uri));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function backProperty($uri) {
    $url = Url::fromRoute('rep.describe_element');
    $url->setRouteParameter('elementuri', base64_encode($uri));
    return $url;
  }


}
