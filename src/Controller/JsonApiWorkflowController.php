<?php

namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;
use Drupal\rep\Utils;
/**
 * Class JsonApiWorkflowController
 * @package Drupal\std\Controller
 */
class JsonApiWorkflowController extends ControllerBase{

  /**
   * @return JsonResponse
   */
  public function handleAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');
    if (!$input) {
      return new JsonResponse($results);
    }
    $keyword = Xss::filter($input);
    $api = \Drupal::service('rep.api_connector');
    $workflow_list = $api->listByKeyword('workflow',$keyword,10,0);
    $obj = json_decode($workflow_list);
    $workflows = [];
    if ($obj->isSuccessful) {
      $workflows = $obj->body;
    }
    foreach ($workflows as $workflow) {
      $results[] = [
        'value' => Utils::trimPreserveBracket(Utils::fieldToAutocomplete($workflow->uri, $workflow->label), 127),
        'label' => $workflow->label,
      ];
    }
    return new JsonResponse($results);
  }

  public function loadComponents(Request $request) {
    $api = \Drupal::service('rep.api_connector');
    $response = $api->componentListFromInstrument($request->query->get('instrument_id'));

    // Decode Main JSON
    $data = json_decode($response, true);
    // Decode Body JSON
    $urls = json_decode($data['body'], true);

    $components = [];
    foreach ($urls as $url) {
      $componentData = $api->getUri($url);
      $obj = json_decode($componentData);
      $components[] = [
        'name' => isset($obj->body->label) ? $obj->body->label : '',
        'uri' => isset($obj->body->uri) ? $obj->body->uri : '',
        'status' => isset($obj->body->hasStatus) ? Utils::plainStatus($obj->body->hasStatus) : '',
        'hasStatus' => isset($obj->body->hasStatus) ? $obj->body->hasStatus : null,
      ];
    }
    return new JsonResponse($components);
  }

  public function updateComponentWrapper(array &$form, FormStateInterface $form_state) {
    // Obtém o elemento que disparou o callback
    $trigger = $form_state->getTriggeringElement();

    // Extrai o índice do instrumento a partir do ID do campo
    $field_id = $trigger['#id'];
    if (preg_match('/instrument_selected_(\d+)/', $field_id, $matches)) {
      $i = $matches[1];
    }
    else {
      // Se não conseguir determinar o índice, retorna o wrapper completo
      return $form['workflow_instruments']['wrapper'];
    }

    // Obtém o valor selecionado (URI do instrumento)
    $instrument_uri = $form_state->getValue("instrument_selected_$i");

    if (!$instrument_uri) {
      // Se não houver URI, limpa o conteúdo do wrapper
      $form_state->set("instrument_component_wrapper_$i", []);
      return $form['workflow_instruments']['wrapper']["instrument_$i"]['instrument_component_wrapper_'.$i];
    }

    // Chama a API para obter a lista de componentes
    $api = \Drupal::service('rep.api_connector');
    $response = $api->componentListFromInstrument($instrument_uri);

    // Decodifica o JSON da resposta
    $data = json_decode($response, true);
    if (!$data || !isset($data['body'])) {
      // Em caso de resposta inválida, limpa o conteúdo do wrapper
      $form_state->set("instrument_component_wrapper_$i", []);
      return $form['workflow_instruments']['wrapper']["instrument_$i"]['instrument_component_wrapper_'.$i];
    }

    // Decodifica o corpo da resposta
    $urls = json_decode($data['body'], true);

    // Processa os componentes
    $components = [];
    foreach ($urls as $url) {
      $componentData = $api->getUri($url);
      $obj = json_decode($componentData);
      $components[] = [
        'name' => isset($obj->body->label) ? $obj->body->label : '',
        'uri' => isset($obj->body->uri) ? $obj->body->uri : '',
        'status' => isset($obj->body->hasStatus) ? Utils::plainStatus($obj->body->hasStatus) : '',
        'hasStatus' => isset($obj->body->hasStatus) ? $obj->body->hasStatus : null,
      ];
    }

    // Armazena os componentes no Form State
    $form_state->set("instrument_component_wrapper_$i", $components);

    // Retorna o wrapper atualizado
    return $form['workflow_instruments']['wrapper']["instrument_$i"]['instrument_component_wrapper_'.$i];
  }

  /**
   * @return JsonResponse
   */
  public function handleTasksAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');
    if (!$input) {
      return new JsonResponse($results);
    }
    $keyword = Xss::filter($input);
    $api = \Drupal::service('rep.api_connector');
    $task_list = $api->listByKeyword('task',$keyword,10,0);
    $obj = json_decode($task_list);
    $tasks = [];
    if ($obj->isSuccessful) {
      $tasks = $obj->body;
    }
    foreach ($tasks as $task) {
      $results[] = [
        'value' => Utils::trimPreserveBracket(Utils::fieldToAutocomplete($task->uri, $task->label), 127),
        'label' => $task->label,
      ];
    }
    return new JsonResponse($results);
  }
}
