<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class EditVirtualColumnForm extends FormBase {

  protected $virtualColumnUri;

  protected $virtualColumn;

  public function getVirtualColumnUri() {
    return $this->virtualColumnUri;
  }

  public function setVirtualColumnUri($uri) {
    return $this->virtualColumnUri = $uri;
  }

  public function getVirtualColumn() {
    return $this->virtualColumn;
  }

  public function setVirtualColumn($vc) {
    return $this->virtualColumn = $vc;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_virtualcolumn_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $virtualcolumnuri = NULL, $fixstd = NULL) {
    $uri=$virtualcolumnuri ?? 'default';
    $uri_decode=base64_decode($uri);
    $this->setVirtualColumnUri($uri_decode);

    $api = \Drupal::service('rep.api_connector');
    $virtualColumn = $api->parseObjectResponse($api->getUri($this->getVirtualColumnUri()),'getUri');
    if ($virtualColumn == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Virtual Column."));
      self::backUrl();
      return;
    } else {
      $this->setVirtualColumn($virtualColumn);
      //dpm($virtualColumn);
    }

    $study = ' ';
    if ($this->getVirtualColumn()->isMemberOf != NULL &&
        $this->getVirtualColumn()->isMemberOf->uri != NULL &&
        $this->getVirtualColumn()->isMemberOf->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getVirtualColumn()->isMemberOf->uri,$this->getVirtualColumn()->isMemberOf->label);
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
      '#default_value' => $this->getVirtualColumn()->label,
    ];
    $form['virtualcolumn_groundinglabel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Grounding Label (optional)'),
      '#default_value' => $this->getVirtualColumn()->groundingLabel,
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
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    $useremail = \Drupal::currentUser()->getEmail();

    $studyUri = 'null';
    if ($form_state->getValue('virtualcolumn_study') != NULL && $form_state->getValue('virtualcolumn_study') != '') {
      $studyUri = Utils::uriFromAutocomplete($form_state->getValue('virtualcolumn_study'));
    }

    $virtualColumnJSON = '{"uri":"'. $this->getVirtualColumn()->uri .'",'.
      '"typeUri":"'.HASCO::VIRTUAL_COLUMN.'",'.
      '"hascoTypeUri":"'.HASCO::VIRTUAL_COLUMN.'",'.
      '"label":"'.$form_state->getValue('virtualcolumn_soc_reference').'",'.
      '"socreference":"'.$form_state->getValue('virtualcolumn_soc_reference').'",'.
      '"isMemberOfUri":"' . $studyUri . '",' .
      '"groundingLabel":"'.$form_state->getValue('virtualcolumn_groundinglabel').'",'.
      '"hasSIRManagerEmail":"'.$useremail.'"}';


    try {
      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $api->elementDel('virtualcolumn',$this->getVirtualColumn()->uri);
      $api->elementAdd('virtualcolumn',$virtualColumnJSON);

      \Drupal::messenger()->addMessage(t("Virtual column has been updated successfully."));
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while updating Virtual Column: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_virtualcolumn');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
