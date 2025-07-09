<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class AddStudyObjectForm extends FormBase {

  protected $studyObjectCollection;

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
    return 'add_studyobject_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyobjectcollectionuri = NULL) {

        // MODAL
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';

    # SET CONTEXT
    $uri=base64_decode($studyobjectcollectionuri);

    $uemail = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $username = $user->name->value;

    // RETRIEVE STUDY OBJECT COLLECTION BY URI
    $api = \Drupal::service('rep.api_connector');
    $studyObjectCollection = $api->parseObjectResponse($api->getUri($uri),'getUri');
    if ($studyObjectCollection == NULL) {
      \Drupal::messenger()->addError(t("Could not retrieve SOC with uri=".$uri));
      self::backurl();
      return;
    }
    $this->setStudyObjectCollection($studyObjectCollection);

    $study = ' ';
    if ($this->getStudyObjectCollection() != NULL &&
        $this->getStudyObjectCollection()->isMemberOf != NULL &&
        $this->getStudyObjectCollection()->isMemberOf->uri != NULL &&
        $this->getStudyObjectCollection()->isMemberOf->label != NULL) {
      $study = Utils::fieldToAutocomplete(
        $this->getStudyObjectCollection()->isMemberOf->uri,
        $this->getStudyObjectCollection()->isMemberOf->label);
    }

    $soc = ' ';
    if ($this->getStudyObjectCollection() != NULL &&
        $this->getStudyObjectCollection()->uri != NULL &&
        $this->getStudyObjectCollection()->label != NULL) {
      $soc = Utils::fieldToAutocomplete(
        $this->getStudyObjectCollection()->uri,
        $this->getStudyObjectCollection()->label);
    }

    $form['studyobject_study'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study'),
      '#default_value' => $study,
      '#disabled' => TRUE,
    ];
    $form['studyobject_soc'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study Object Collection (SOC)'),
      '#default_value' => $soc,
      '#disabled' => TRUE,
    ];
    $form['studyobject_original_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Original ID (required)'),
    ];
    // if (\Drupal::moduleHandler()->moduleExists('sem')) {
    //   $form['studyobject_entity'] = [
    //     '#type' => 'textfield',
    //     '#title' => $this->t('Entity (required)'),
    //     '#autocomplete_route_name' => 'sem.semanticvariable_entity_autocomplete',
    //   ];
    // } else {
    //   $form['studyobject_entity'] = [
    //     '#type' => 'textfield',
    //     '#title' => $this->t('Entity (required)'),
    //   ];
    // }
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
    if ($form_state->getValue('studyobject_entity') == NULL || $form_state->getValue('studyobject_entity') == '') {
      $entityUri = Utils::uriFromAutocomplete(HASCO::STUDY_OBJECT);
    } else {
      $entityUri = Utils::uriFromAutocomplete($form_state->getValue('studyobject_entity'));
    }

    $socUri = NULL;
    if ($this->getStudyObjectCollection() != NULL) {
      $socUri = $this->getStudyObjectCollection()->uri;
    }

    $newStudyObjectUri = Utils::uriGen('studyobject');
    $studyObjectJSON = '{"uri":"'. $newStudyObjectUri .'",'.
        '"typeUri":"'.$entityUri.'",'.
        '"hascoTypeUri":"'.HASCO::STUDY_OBJECT.'",'.
        '"isMemberOfUri":"'.$socUri.'",'.
        '"label":"'.$form_state->getValue('studyobject_original_id').'",'.
        '"originalId":"'.$form_state->getValue('studyobject_original_id').'",'.
        '"comment":"'.$form_state->getValue('studyobject_description').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      $api = \Drupal::service('rep.api_connector');
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
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_studyobject');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }



}
