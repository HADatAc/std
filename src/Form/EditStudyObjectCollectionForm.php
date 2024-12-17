<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class EditStudyObjectCollectionForm extends FormBase {

  protected $studyObjectCollectionUri;

  protected $studyObjectCollection;

  public function getStudyObjectCollectionUri() {
    return $this->studyObjectCollectionUri;
  }

  public function setStudyObjectCollectionUri($uri) {
    return $this->studyObjectCollectionUri = $uri;
  }

  public function getStudyObjectCollection() {
    return $this->studyObjectCollection;
  }

  public function setStudyObjectCollection($soc) {
    return $this->studyObjectCollection = $soc;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_studyobjectcollection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyobjectcollectionuri = NULL) {
    $uri_decode=base64_decode($studyobjectcollectionuri);
    $this->setStudyObjectCollectionUri($uri_decode);

    $api = \Drupal::service('rep.api_connector');
    $studyObjectCollection = $api->parseObjectResponse($api->getUri($this->getStudyObjectCollectionUri()),'getUri');
    if ($studyObjectCollection == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve Study Object Collection."));
      self::backUrl();
      return;
    } else {
      $this->setStudyObjectCollection($studyObjectCollection);
      //dpm($studyObjectCollection);
    }

    // RETRIEVE VCs FOR GIVEN STUDY
    $vcs = array();
    $vcs_response = json_decode($api->virtualColumnsByStudy($this->getStudyObjectCollection()->isMemberOf->uri));
    if ($vcs_response != NULL && isset($vcs_response->body)) {
      $raw_vcs = $vcs_response->body;
      foreach ($raw_vcs as $raw_vc) {
        $vcs[$raw_vc->uri] = Utils::fieldToAutocomplete($raw_vc->uri,$raw_vc->label);
      }
    }

    // RETRIEVE SOCs FOR GIVEN STUDY
    $socs = array();
    $socs[] = ' ';
    $socs_response =  json_decode($api->studyObjectCollectionsByStudy($this->getStudyObjectCollection()->isMemberOf->uri));
    if ($socs_response != NULL && isset($socs_response->body)) {
      $raw_socs = $socs_response->body;
      foreach ($raw_socs as $raw_soc) {
        $socs[$raw_soc->uri] = Utils::fieldToAutocomplete($raw_soc->uri,$raw_soc->label);
      }
    }

    $study = ' ';
    if ($this->getStudyObjectCollection()->isMemberOf != NULL &&
        $this->getStudyObjectCollection()->isMemberOf->uri != NULL &&
        $this->getStudyObjectCollection()->isMemberOf->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getStudyObjectCollection()->isMemberOf->uri,$this->getStudyObjectCollection()->isMemberOf->label);
    }

    $form['soc_study'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Study'),
      '#default_value' => $study,
      '#disabled' => TRUE,
    ];
    $form['soc_virtualcolumn'] = [
      '#type' => 'select',
      '#title' => $this->t('Virtual Column'),
      '#options' => $vcs,
      '#default_value' => $this->getStudyObjectCollection()->virtualColumnUri,
    ];
    $form['soc_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->getStudyObjectCollection()->label,
    ];
    $form['soc_definition'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Definition'),
      '#default_value' => $this->getStudyObjectCollection()->comment,
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
    $form['update_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
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
      if(strlen($form_state->getValue('soc_virtualcolumn')) < 1) {
        $form_state->setErrorByName('soc_virtualcolumn', $this->t('Please enter a valid Virtual Column for Study Object Collection'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
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

    $studyObjectCollectionJSON = '{"uri":"'. $this->getStudyObjectCollection()->uri .'",'.
      '"typeUri":"'.HASCO::STUDY_OBJECT_COLLECTION.'",'.
      '"hascoTypeUri":"'.HASCO::STUDY_OBJECT_COLLECTION.'",'.
      '"isMemberOfUri":"'.$studyUri.'",'.
      '"virtualColumnUri":"'.$form_state->getValue('soc_virtualcolumn').'",'.
      '"label":"'.$form_state->getValue('soc_label').'",'.
      '"comment":"'.$form_state->getValue('soc_definition').'",'.
      '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $api->elementDel('studyobjectcollection',$this->getStudyObjectCollection()->uri);
      $api->elementAdd('studyobjectcollection',$studyObjectCollectionJSON);

      \Drupal::messenger()->addMessage(t("Study Object Collection has been updated successfully."));
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while updating Study Object Collection: ".$e->getMessage()));
      self::backUrl();
      return;
    }
  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_studyobjectcollection');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
