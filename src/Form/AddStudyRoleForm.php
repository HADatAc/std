<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class AddStudyRoleForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_study_role_form';
  }

  protected $studyUri;

  protected $study;

  public function getStudyUri() {
    return $this->studyUri;
  }

  public function setStudyUri($studyUri) {
    return $this->studyUri = $studyUri;
  }

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($study) {
    return $this->study = $study;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL, $fixstd = NULL) {

    $api = \Drupal::service('rep.api_connector');

    // HANDLE STUDYURI AND STUDY, IF ANY
    if ($studyuri != NULL) {
      if ($studyuri == 'none') {
        $this->setStudyUri(NULL);
      } else {
        $studyuri_decoded = base64_decode($studyuri);
        $this->setStudyUri($studyuri_decoded);
        $study = $api->parseObjectResponse($api->getUri($this->getStudyUri()),'getUri');
        if ($study == NULL) {
          \Drupal::messenger()->addMessage(t("Failed to retrieve Study."));
          self::backUrl();
          return;
        } else {
          $this->setStudy($study);
        }
      }
    }

    $study = ' ';
    if ($this->getStudy() != NULL &&
        $this->getStudy()->uri != NULL &&
        $this->getStudy()->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getStudy()->uri,$this->getStudy()->label);
    }

    if ($fixstd == 'T') {
      $form['studyrole_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#disabled' => TRUE,
      ];
    } else {
      $form['studyrole_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#autocomplete_route_name' => 'std.study_autocomplete',
      ];
    }

    $form['studyrole_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
    ];

    $form['studyrole_description'] = [
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
      if(strlen($form_state->getValue('studyrole_study')) < 1) {
        $form_state->setErrorByName('studyrole_study', $this->t('Please enter a valid study for the Study Role'));
      }
      if(strlen($form_state->getValue('studyrole_name')) < 1) {
        $form_state->setErrorByName('studyrole_name', $this->t('Please enter a valid name for the Study Role'));
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
      self::backUrl();
      return;
    }

    $useremail = \Drupal::currentUser()->getEmail();

    $studyUri = 'null';
    if ($form_state->getValue('studyrole_study') != NULL && $form_state->getValue('studyrole_study') != '') {
      $studyUri = Utils::uriFromAutocomplete($form_state->getValue('studyrole_study'));
    }

    $newStudyRoleUri = Utils::uriGen('studyrole');
    $studyRoleJSON = '{"uri":"'. $newStudyRoleUri .'",'.
        '"typeUri":"'.HASCO::STUDY_ROLE.'",'.
        '"hascoTypeUri":"'.HASCO::STUDY_ROLE.'",'.
        '"label":"'.$form_state->getValue('studyrole_name').'",'.
        '"isMemberOfUri":"'.$studyUri.'",'.
        '"comment":"'.$form_state->getValue('studyrole_description').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      $api = \Drupal::service('rep.api_connector');
      $message = $api->parseObjectResponse($api->elementAdd('studyrole',$studyRoleJSON),'elementAdd');
      if ($message != null) {
        \Drupal::messenger()->addMessage(t("Study Role has been added successfully."));
      }
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while adding a study role: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_studyrole');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }


}
