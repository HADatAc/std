<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class AddStreamForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_stream_form';
  }

  protected $study;

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
        $study = $api->parseObjectResponse($api->getUri($studyuri_decoded),'getUri');
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
      $form['stream_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#disabled' => TRUE,
      ];
    } else {
      $form['stream_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#autocomplete_route_name' => 'std.study_autocomplete',
      ];
    }

    $form['stream_semanticdatadictionary'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Semantic Data Dictionary"),
      '#autocomplete_route_name' => 'std.semanticdatadictionary_autocomplete',
    ];

    $form['stream_deployment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment'),
      '#autocomplete_route_name' => 'std.deployment_autocomplete',
    ];

    $form['stream_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
    ];

    $method_options = [
      ' ',
      'message',
      'file',
    ];

    $form['stream_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Method'),
      '#default_value' => 'none',
      '#options' => $method_options,
    ];

    $form['stream_dataset_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset Pattern'),
    ];

    $form['stream_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
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
      if(strlen($form_state->getValue('stream_study')) < 1) {
        $form_state->setErrorByName('stream_study', $this->t('Please enter a valid study for the Stream'));
      }
      if(strlen($form_state->getValue('stream_semanticdatadictionary')) < 1) {
        $form_state->setErrorByName('stream_semanticdatadictionary', $this->t('Please enter a valid semantic data dictionary for the Stream'));
      }
      if(strlen($form_state->getValue('stream_deployment')) < 1) {
        $form_state->setErrorByName('stream_deployment', $this->t('Please enter a valid Deployment for the Stream'));
      }
      if($form_state->getValue('stream_method') == ' ') {
        $form_state->setErrorByName('stream_method', $this->t('Please enter a valid method for the Stream'));
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
    if ($form_state->getValue('stream_study') != NULL && $form_state->getValue('stream_study') != '') {
      $studyUri = Utils::uriFromAutocomplete($form_state->getValue('stream_study'));
    }

    $semanticDataDictionaryUri = NULL;
    if ($form_state->getValue('stream_semanticdatadictionary') != NULL && $form_state->getValue('stream_semanticdatadictionary') != '') {
      $semanticDataDictionaryUri = Utils::uriFromAutocomplete($form_state->getValue('stream_semanticdatadictionary'));
    }

    $deploymentUri = NULL;
    if ($form_state->getValue('stream_deployment') != NULL && $form_state->getValue('stream_deployment') != '') {
      $deploymentUri = Utils::uriFromAutocomplete($form_state->getValue('stream_deployment'));
    }

    $newStreamUri = Utils::uriGen('stream');
    $streamJSON = '{"uri":"'. $newStreamUri .'",'.
        '"typeUri":"'.HASCO::STREAM.'",'.
        '"hascoTypeUri":"'.HASCO::STREAM.'",'.
        '"label":"'.$form_state->getValue('stream_name').'",'.
        '"studyUri":"' . $studyUri . '",' .
        '"semanticDataDictionaryUri":"'.$semanticDataDictionaryUri.'",'.
        '"deploymentUri":"'.$deploymentUri.'",'.
        '"method":"'.$form_state->getValue('stream_method').'",'.
        '"datasetPattern":"'.$form_state->getValue('stream_dataset_pattern').'",'.
        '"hasVersion":"'.$form_state->getValue('stream_version').'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      $api = \Drupal::service('rep.api_connector');
      $message = $api->parseObjectResponse($api->elementAdd('stream',$streamJSON),'elementAdd');
      if ($message != null) {
        \Drupal::messenger()->addMessage(t("Stream has been added successfully."));
      }
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while adding a Stream: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }
  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_stream');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
