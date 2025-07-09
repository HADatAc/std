<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class EditStudyObjectForm extends FormBase {

  protected $study;

  protected $studyObject;

  protected $entity;

  public function getStudy() {
    return $this->study;
  }
  public function setStudy($study) {
    return $this->study = $study;
  }

  public function getStudyObject() {
    return $this->studyObject;
  }
  public function setStudyObject($studyObject) {
    return $this->studyObject = $studyObject;
  }

  public function getEntity() {
    return $this->entity;
  }
  public function setEntity($entity) {
    return $this->entity = $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_studyobject_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyobjecturi = NULL) {

    // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';

    # SET CONTEXT
    $uri=base64_decode($studyobjecturi);

    $uemail = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $username = $user->name->value;

    // RETRIEVE STUDY OBJECT BY URI
    $api = \Drupal::service('rep.api_connector');
    $studyObject = $api->parseObjectResponse($api->getUri($uri),'getUri');
    if ($studyObject == NULL) {
      \Drupal::messenger()->addError(t("Could not retrieve Study Object with uri=".$uri));
      self::backurl();
      return;
    }
    $this->setStudyObject($studyObject);

    // RETRIEVE ENTITY BY URI
    $entityContent = ' ';
    if ($studyObject != NULL &&
        $studyObject->typeUri != NULL &&
        $studyObject->typeUri != HASCO::STUDY_OBJECT) {
      $entity = $api->parseObjectResponse($api->getUri($studyObject->typeUri),'getUri');
      $this->setEntity($entity);
      $entityContent = Utils::fieldToAutocomplete(
        $this->getEntity()->uri,
        $this->getEntity()->label
      );
    }
    if ($studyObject->typeUri == HASCO::STUDY_OBJECT) {
      $studyObject->typeUri = NULL;
    }

    $studyContent = ' ';
    if ($this->getStudyObject()->isMemberOf != NULL &&
        $this->getStudyObject()->isMemberOf->isMemberOf != NULL &&
        $this->getStudyObject()->isMemberOf->isMemberOf->uri != NULL &&
        $this->getStudyObject()->isMemberOf->isMemberOf->label != NULL) {
      $studyContent = Utils::fieldToAutocomplete(
        $this->getStudyObject()->isMemberOf->isMemberOf->uri,
        $this->getStudyObject()->isMemberOf->isMemberOf->label);
    }

    $socContent = ' ';
    if ($this->getStudyObject() != NULL &&
        $this->getStudyObject()->isMemberOf != NULL &&
        $this->getStudyObject()->isMemberOf->uri != NULL &&
        $this->getStudyObject()->isMemberOf->label != NULL) {
      $socContent = Utils::fieldToAutocomplete(
        $this->getStudyObject()->isMemberOf->uri,
        $this->getStudyObject()->isMemberOf->label);
    }

    $form['studyobject_study'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study'),
      '#default_value' => $studyContent,
      '#disabled' => TRUE,
    ];
    $form['studyobject_soc'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study Object Collection (SOC)'),
      '#default_value' => $socContent,
      '#disabled' => TRUE,
    ];
    $form['studyobject_original_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Original ID (required)'),
      '#default_value' => $this->getStudyObject()->originalId,
    ];
    $form['studyobject_entity'] = [
      'top' => [
        '#type' => 'markup',
        '#markup' => '<div class="pt-3 col border border-white">',
      ],
      'main' => [
        '#type' => 'textfield',
        '#title' => t('Entity (required)'),
        '#name' => 'studyobject_entity',
        '#default_value' => '',
        '#id' => 'studyobject_entity',
        '#parents' => ['studyobject_entity'],
        '#attributes' => [
          'class' => ['open-tree-modal'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url' => Url::fromRoute('rep.tree_form', [
            'mode' => 'modal',
            'elementtype' => 'entity',
          ], ['query' => ['field_id' => 'studyobject_entity']])->toString(),
          'data-field-id' => 'studyobject_entity',
          'data-elementtype' => 'entity',
          'autocomplete' => 'off',
        ],
      ],
      'bottom' => [
        '#type' => 'markup',
        '#markup' => '</div>',
      ],
    ];
    $form['studyobject_domainscope_object'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Domain Scope's Object (if required)"),
    ];
    $form['studyobject_timescope_object'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Time Scope's Object (if required)"),
    ];
    $form['studyobject_spacescope_object'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Space Scope's Object (if required)"),
    ];
    $form['studyobject_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
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
      ],
    ];
    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br>'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'save') {
      if(strlen($form_state->getValue('studyobject_original_id')) < 1) {
        $form_state->setErrorByName('studyobject_original_id', $this->t('Please enter an original ID for the Study Object'));
      }
      if(strlen($form_state->getValue('studyobject_entity')) < 1) {
        $form_state->setErrorByName('studyobject_entity', $this->t('Please enter a valid entity for the Study Object'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backurl();
      return;
    }

    $useremail = \Drupal::currentUser()->getEmail();

    $entityUri = NULL;
    if ($form_state->getValue('studyobject_entity') != NULL && $form_state->getValue('studyobject_entity') != '') {
      $entityUri = Utils::uriFromAutocomplete($form_state->getValue('studyobject_entity'));
    } else {
      $entityUri = HASCO::STUDY_OBJECT;
    }

    $socUri = NULL;
    if ($this->getStudyObject() != NULL &&
        $this->getStudyObject()->isMemberOf != NULL &&
        $this->getStudyObject()->isMemberOf->uri != NULL) {
        $socUri = $this->getStudyObject()->isMemberOf->uri;
    }

    $studyObjectJSON = '{"uri":"'. $this->getStudyObject()->uri .'",'.
        '"typeUri":"'.$entityUri.'",'.
        '"hascoTypeUri":"'.HASCO::STUDY_OBJECT.'",'.
        '"isMemberOfUri":"'.$socUri.'",'.
        '"label":"'.$form_state->getValue('studyobject_original_id').'",'.
        '"originalId":"'.$form_state->getValue('studyobject_original_id').'",'.
        '"comment":"'.$form_state->getValue('studyobject_description').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      $api = \Drupal::service('rep.api_connector');
      $api->elementDel('studyobject',$this->getStudyObject()->uri);
      $message = $api->parseObjectResponse($api->elementAdd('studyobject',$studyObjectJSON),'elementAdd');
      if ($message != null) {
        \Drupal::messenger()->addMessage(t("Study Object has been added successfully."));
      } else {
        \Drupal::messenger()->addError(t("Study Object failed to be added."));
      }
      self::backurl();
      return;
    } catch(\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while adding a Study Object: ".$e->getMessage()));
      self::backurl();
      return;
    }
  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_studyobject');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }


}
