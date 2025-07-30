<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;

class STDSearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_search_form';
  }

  protected $elementtype;

  protected $keyword;

  protected $page;

  protected $pagesize;

  public function getElementType() {
    return $this->elementtype;
  }

  public function setElementType($type) {
    return $this->elementtype = $type;
  }

  public function getKeyword() {
    return $this->keyword;
  }

  public function setKeyword($kw) {
    return $this->keyword = $kw;
  }

  public function getPage() {
    return $this->page;
  }

  public function setPage($pg) {
    return $this->page = $pg;
  }

  public function getPageSize() {
    return $this->pagesize;
  }

  public function setPageSize($pgsize) {
    return $this->pagesize = $pgsize;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
  $form['#attached']['library'][] = 'std/std_icons';

  // GET URL INFO
  $request = \Drupal::request();
  $pathInfo = $request->getPathInfo();
  $pathElements = explode('/', $pathInfo);

  $this->setElementType('study');
  $this->setKeyword('');
  $this->setPage(1);
  $this->setPageSize(9);

  if (count($pathElements) >= 7) {
    $this->setElementType($pathElements[3]);
    $this->setKeyword($pathElements[4] === '_' ? '' : $pathElements[4]);
    $this->setPage((int) $pathElements[5]);
    $this->setPageSize((int) $pathElements[6]);
  }

  $form['element_icons'] = [
    '#type' => 'container',
    '#attributes' => ['class' => ['element-icons-grid-wrapper']],
  ];

  $form['element_icons']['grid'] = [
    '#type' => 'container',
    '#attributes' => ['class' => ['element-icons-grid']],
  ];

  $element_types = [
    'da' => ['label' => 'DAs', 'image' => 'da_placeholder.png'],
    'study' => ['label' => 'Studies', 'image' => 'study_placeholder.png'],
    'studyrole' => ['label' => 'Study Roles', 'image' => 'studyrole_placeholder.png'],
    'virtualcolumn' => ['label' => 'Virtual Columns', 'image' => 'virtualcolumn_placeholder.png'],
    'studyobjectcollection' => ['label' => 'Object Collections', 'image' => 'studyobjectcollection_placeholder.png'],
    'studyobject' => ['label' => 'Study Objects', 'image' => 'studyobject_placeholder.png'],
    'processstem' => ['label' => 'Process Stems', 'image' => 'processstem_placeholder.png'],
    'process' => ['label' => 'Processes', 'image' => 'process_placeholder.png'],
  ];

    

  foreach ($element_types as $type => $info) {

    $module_path = \Drupal::request()->getBaseUrl() . '/' . \Drupal::service('extension.list.module')->getPath('rep');
    $placeholder_image = $module_path . '/images/placeholders/' . $info['image'];

    $button_classes = ['element-icon-button'];
    if ($type === $this->getElementType()) {
    $button_classes[] = 'selected';
    }

    $form['element_icons']['grid'][$type] = [
      '#type' => 'submit',
      '#value' => '',
      '#attributes' => [
        'class' => $button_classes,
        'style' => "background-image: url('$placeholder_image');",
        'title' => $this->t($info['label']),
        'aria-label' => $this->t($info['label']),
      ],
      '#name' => $type,
      '#submit' => ['::iconSubmitForm'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxSubmitForm',
        'progress' => ['type' => 'none'],
      ],
    ];
  }

  $form['search_keyword'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Keyword'),
    '#default_value' => $this->getKeyword(),
  ];

  $form['search_submit'] = [
    '#type' => 'submit',
    '#value' => $this->t('Search'),
    '#attributes' => ['class' => ['btn', 'btn-primary', 'search-button']],
  ];

  return $form;
}

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if(strlen($form_state->getValue('search_element_type')) < 1) {
      $form_state->setErrorByName('search_element_type', $this->t('Please select an element type'));
    }
  }


  /**
   * {@inheritdoc}
   */
  private function redirectUrl(FormStateInterface $form_state) {
    $this->setKeyword($form_state->getValue('search_keyword'));
    if ($this->getKeyword() == NULL || $this->getKeyword() == '') {
      $this->setKeyword("_");
    }
    $url = Url::fromRoute('std.list_element');
    $url->setRouteParameter('elementtype', $form_state->getValue('search_element_type'));
    $url->setRouteParameter('keyword', $this->getKeyword());
    $url->setRouteParameter('page', $this->getPage());
    $url->setRouteParameter('pagesize', $this->getPageSize());
    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function ajaxSubmitForm(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $this->setPage(1);
    $this->setPageSize(12);
    $url = $this->redirectUrl($form_state);
    $response->addCommand(new RedirectCommand($url->toString()));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  $url = $this->redirectUrl($form_state);
  $form_state->setRedirectUrl($url);
  }

  public function iconSubmitForm(array &$form, FormStateInterface $form_state) {
  $clicked_button = $form_state->getTriggeringElement()['#name'];
  $form_state->setValue('search_element_type', $clicked_button);
  $form_state->setValue('search_keyword', '');
}
}