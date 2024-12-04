<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class AddStudyForm extends FormBase {

  public $pi = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_study_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    //dpm($form_state->getValue('study_pi'));

    $form['study_short_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short Name'),
    ];

    $form['study_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Long Name'),
    ];

    $form['study_pi'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PI'),
    ];

    $form['study_description'] = [
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
      if(strlen($form_state->getValue('study_short_name')) < 1) {
        $form_state->setErrorByName('study_short_name', $this->t('Please enter a valid short name for the Study'));
      }
      if(strlen($form_state->getValue('study_name')) < 1) {
        $form_state->setErrorByName('study_name', $this->t('Please enter a valid name for the Study'));
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

    if ($button_name === 'save') {
      $useremail = \Drupal::currentUser()->getEmail();

      $newStudyUri = Utils::uriGen('study');
      $studyJSON = '{"uri":"'. $newStudyUri .'",'.
          '"typeUri":"'.HASCO::STUDY.'",'.
          '"hascoTypeUri":"'.HASCO::STUDY.'",'.
          '"label":"'.$form_state->getValue('study_short_name').'",'.
          '"title":"'.$form_state->getValue('study_name').'",'.
          '"comment":"'.$form_state->getValue('study_description').'",'.
          '"hasSIRManagerEmail":"'.$useremail.'"}';

      try {
        $api = \Drupal::service('rep.api_connector');
        $message = $api->parseObjectResponse($api->elementAdd('study',$studyJSON),'elementAdd');
        if ($message != null) {
          \Drupal::messenger()->addMessage(t("Study has been added successfully."));
        }
        self::backUrl();
        return;

      } catch(\Exception $e) {
        \Drupal::messenger()->addMessage(t("An error occurred while adding a study: ".$e->getMessage()));
        self::backUrl();
        return;
      }
    }

    return;

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.add_study');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }



}
