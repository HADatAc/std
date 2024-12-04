<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class AddVirtualColumnForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_virtualcolumn_form';
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
      $form['virtualcolumn_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#disabled' => TRUE,
      ];
    } else {
      $form['virtualcolumn_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#autocomplete_route_name' => 'std.study_autocomplete',
      ];
    }

    $form['virtualcolumn_soc_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t("SOC Reference (must starts with '??')"),
    ];
    $form['virtualcolumn_groundinglabel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Grounding Label (optional)'),
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
      if(strlen($form_state->getValue('virtualcolumn_soc_reference')) < 1) {
        $form_state->setErrorByName('virtualcolumn_soc_reference', $this->t('Please enter a valid SOC Reference for Virtual Column'));
      }
      if(strlen($form_state->getValue('virtualcolumn_study')) < 1) {
        $form_state->setErrorByName('virtualcolumn_study', $this->t('Please enter a valid study for the Virtual Column'));
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

    $studyUri = NULL;
    if ($form_state->getValue('virtualcolumn_study') != NULL && $form_state->getValue('virtualcolumn_study') != '') {
      $studyUri = Utils::uriFromAutocomplete($form_state->getValue('virtualcolumn_study'));
    }

    $newVirtualColumnUri = Utils::uriGen('virtualcolumn');
    $virtualColumnJSON = '{"uri":"'. $newVirtualColumnUri .'",'.
        '"typeUri":"'.HASCO::VIRTUAL_COLUMN.'",'.
        '"hascoTypeUri":"'.HASCO::VIRTUAL_COLUMN.'",'.
        '"label":"'.$form_state->getValue('virtualcolumn_soc_reference').'",'.
        '"socreference":"'.$form_state->getValue('virtualcolumn_soc_reference').'",'.
        '"isMemberOfUri":"' . $studyUri . '",' .
        '"groundingLabel":"'.$form_state->getValue('virtualcolumn_groundinglabel').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      $api = \Drupal::service('rep.api_connector');
      $message = $api->parseObjectResponse($api->elementAdd('virtualcolumn',$virtualColumnJSON),'elementAdd');
      if ($message != null) {
        \Drupal::messenger()->addMessage(t("Virtual column has been added successfully."));
      }
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while adding a virtual column: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }
  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_virtualcolumn');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
