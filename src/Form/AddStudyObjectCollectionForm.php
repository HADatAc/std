<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class AddStudyObjectCollectionForm extends FormBase {

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
  public function getFormId() {
    return 'add_studyobjectcollection_form';
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

    // RETRIEVE VCs FOR GIVEN STUDY
    $vcs = array();
    $vcs['none'] = ' ';
    $vcs_response = json_decode($api->virtualColumnsByStudy($this->getStudy()->uri));
    if ($vcs_response != NULL && isset($vcs_response->body)) {
      $raw_vcs = $vcs_response->body;
      foreach ($raw_vcs as $raw_vc) {
        $vcs[$raw_vc->uri] = Utils::fieldToAutocomplete($raw_vc->uri,$raw_vc->label);
      }
    }

    // RETRIEVE SOCs FOR GIVEN STUDY
    $socs = array();
    $socs[] = ' ';
    $socs_response =  json_decode($api->studyObjectCollectionsByStudy($this->getStudy()->uri));
    if ($socs_response != NULL && isset($socs_response->body)) {
      $raw_socs = $socs_response->body;
      foreach ($raw_socs as $raw_soc) {
        $socs[$raw_soc->uri] = $raw_soc->label;
      }
    }

    $study = ' ';
    if ($this->getStudy() != NULL &&
        $this->getStudy()->uri != NULL &&
        $this->getStudy()->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getStudy()->uri,$this->getStudy()->label);
    }

    if ($fixstd == 'T') {
      $form['soc_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#disabled' => TRUE,
      ];
    } else {
      $form['soc_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#autocomplete_route_name' => 'std.study_autocomplete',
      ];
    }

    $form['soc_virtualcolumn'] = [
      '#type' => 'select',
      '#title' => $this->t('Virtual Column'),
      '#default_value' => 'none',
      '#options' => $vcs,
    ];
    $form['soc_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
    ];
    $form['soc_definition'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Definition'),
    ];
    $form['soc_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Domain Restriction (optional)'),
      '#options' => $socs,
    ];
    $form['soc_time_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Time Restriction (optional)'),
      '#options' => $socs,
    ];
    $form['soc_space_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Space Restriction (optional)'),
      '#options' => $socs,
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
      if(strlen($form_state->getValue('soc_study')) < 1) {
        $form_state->setErrorByName('soc_study', $this->t('Please enter a valid study for the Study Object Collection'));
      }
      if ((strlen($form_state->getValue('soc_virtualcolumn')) < 1) ||
          ($form_state->getValue('soc_virtualcolumn') == 'none')) {
        $form_state->setErrorByName('soc_virtualcolumn', $this->t('Please enter a valid virtual column for the Study Object Collection'));
      }
      if(strlen($form_state->getValue('soc_label')) < 1) {
        $form_state->setErrorByName('soc_label', $this->t('Please enter a label for the Study Object Collection'));
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
    if ($form_state->getValue('soc_study') != NULL && $form_state->getValue('soc_study') != '') {
      $studyUri = Utils::uriFromAutocomplete($form_state->getValue('soc_study'));
    }

    if ($studyUri == NULL) {
      \Drupal::messenger()->addError(t("An error occurred while adding a study object collection: could not find valid URI for study."));
      self::backUrl();
      return;
    }

    $vcUri = NULL;
    if ($form_state->getValue('soc_virtualcolumn') != NULL && $form_state->getValue('soc_virtualcolumn') != '') {
      //dpm($form_state->getValue('soc_virtualcolumn'));
      //$vcUri = Utils::uriFromAutocomplete($form_state->getValue('soc_virtualcolumn'));
      $vcUri = $form_state->getValue('soc_virtualcolumn');
      //dpm($vcUri);
    }

    if ($vcUri == NULL) {
      \Drupal::messenger()->addError(t("An error occurred while adding a study object collection: could not find valid URI for its virtual column."));
      self::backUrl();
      return;
    }

    $newStudyObjectCollectionUri = Utils::uriGen('studyobjectcollection');
    $studyObjectCollectionJSON = '{"uri":"'. $newStudyObjectCollectionUri .'",'.
        '"typeUri":"'.HASCO::STUDY_OBJECT_COLLECTION.'",'.
        '"hascoTypeUri":"'.HASCO::STUDY_OBJECT_COLLECTION.'",'.
        '"isMemberOfUri":"'.$studyUri.'",'.
        '"virtualColumnUri":"'.$vcUri.'",'.
        '"label":"'.$form_state->getValue('soc_label').'",'.
        '"comment":"'.$form_state->getValue('soc_definition').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      $api = \Drupal::service('rep.api_connector');
      $message = $api->parseObjectResponse($api->elementAdd('studyobjectcollection',$studyObjectCollectionJSON),'elementAdd');
      if ($message != null) {
        \Drupal::messenger()->addMessage(t("Study Object Collection has been added successfully."));
      } else {
        \Drupal::messenger()->addError(t("Study Object Collection failed to be added."));
      }
      self::backUrl();
      return;
    } catch(\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while adding a Study Object Collection: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_studyobjectcollection');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
