<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class EditStudyForm extends FormBase {

  protected $studyUri;

  protected $study;

  public function getStudyUri() {
    return $this->studyUri;
  }

  public function setStudyUri($uri) {
    return $this->studyUri = $uri;
  }

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($sem) {
    return $this->study = $sem;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_study_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL) {

    $uri=$studyuri ?? 'default';
    $uri_decode=base64_decode($uri);
    $this->setStudyUri($uri_decode);

    $api = \Drupal::service('rep.api_connector');
    $study = $api->parseObjectResponse($api->getUri($this->getStudyUri()),'getUri');
    if ($study == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Study."));
      self::backUrl();
      return;
    } else {
      $this->setStudy($study);
    }

    $form['study_short_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short Name'),
      '#default_value' => $this->getStudy()->label,
    ];
    $form['study_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Long Name'),
      '#default_value' => $this->getStudy()->title,
    ];
    $form['study_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->getStudy()->comment,
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
      if(strlen($form_state->getValue('study_short_name')) < 1) {
        $form_state->setErrorByName('study_short_name', $this->t('Please enter a short name for the Study'));
      }
      if(strlen($form_state->getValue('study_name')) < 1) {
        $form_state->setErrorByName('study_name', $this->t('Please enter a name for the Study'));
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

    $studyJson = '{"uri":"'. $this->getStudy()->uri .'",'.
      '"typeUri":"'.HASCO::STUDY.'",'.
      '"hascoTypeUri":"'.HASCO::STUDY.'",'.
      '"label":"'.$form_state->getValue('study_short_name').'",'.
      '"title":"'.$form_state->getValue('study_name').'",'.
      '"comment":"'.$form_state->getValue('study_description').'",'.
      '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $api->studyDel($this->getStudy()->uri);
      $api->studyAdd($studyJson);

      \Drupal::messenger()->addMessage(t("Study has been updated successfully."));
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while updating Study: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl($back_url = NULL) {
    if ($back_url) {
      // Se uma URL específica for fornecida, usa essa para o redirecionamento
      $response = new RedirectResponse($back_url);
      $response->send();
      return;
    } else {
      // Se não houver uma URL de retorno específica, usa a URL da página inicial com a página e o tamanho de página
      $uid = \Drupal::currentUser()->id();
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_study');

      // Verifica se a URL retornada é uma URL de /load-more-data, em caso positivo, gera a URL correta da listagem
      if ($previousUrl && strpos($previousUrl, '/load-more-data') !== false) {
        // Extrair o número da página da query string da URL
        parse_str(parse_url($previousUrl, PHP_URL_QUERY), $params);
        $page = isset($params['page']) ? $params['page'] : 1;
        $element_type = isset($params['element_type']) ? $params['element_type'] : 'study';
        $pagesize = 9; // Utilize o valor padrão do tamanho da página

        // Gera a URL correta da listagem usando a rota "std.select_study"
        $previousUrl = Url::fromRoute('std.select_study', [
          'elementtype' => $element_type,
          'page' => $page,
          'pagesize' => $pagesize,
        ])->toString();
      }

      if ($previousUrl) {
        $response = new RedirectResponse($previousUrl);
        $response->send();
        return;
      } else {
        // Rota padrão caso nenhuma URL seja encontrada
        $default_url = Url::fromRoute('std.select_study', [
          'elementtype' => 'study',
          'page' => 1,
          'pagesize' => 9,
        ])->toString();
        $response = new RedirectResponse($default_url);
        $response->send();
      }
    }
  }

}
