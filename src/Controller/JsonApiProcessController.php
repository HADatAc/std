<?php

namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;
use Drupal\rep\Utils;
/**
 * Class JsonApiProcessController
 * @package Drupal\std\Controller
 */
class JsonApiProcessController extends ControllerBase{

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
    $process_list = $api->listByKeyword('process',$keyword,10,0);
    $obj = json_decode($process_list);
    $processes = [];
    if ($obj->isSuccessful) {
      $processes = $obj->body;
    }
    foreach ($processes as $process) {
      $results[] = [
        'value' => $process->label . ' [' . $process->uri . ']',
        'label' => $process->label,
      ];
    }
    return new JsonResponse($results);
  }

  public function loadComponents(Request $request) {
    $api = \Drupal::service('rep.api_connector');
    $response = $api->componentsListFromInstrument($request->query->get('instrument_id'));

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
    $trigger = $form_state->getTriggeringElement();

    $field_id = $trigger['#id'];
    if (preg_match('/instrument_selected_(\d+)/', $field_id, $matches)) {
      $i = $matches[1];
    }
    else {
      return $form['process_instruments']['wrapper'];
    }

    $instrument_uri = $form_state->getValue("instrument_selected_$i");

    if (!$instrument_uri) {
      $form_state->set("instrument_component_wrapper_$i", []);
      return $form['process_instruments']['wrapper']["instrument_$i"]['instrument_component_wrapper_'.$i];
    }

    $api = \Drupal::service('rep.api_connector');
    $response = $api->componentListFromInstrument($instrument_uri);

    $data = json_decode($response, true);
    if (!$data || !isset($data['body'])) {
      $form_state->set("instrument_component_wrapper_$i", []);
      return $form['process_instruments']['wrapper']["instrument_$i"]['instrument_component_wrapper_'.$i];
    }

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

    $form_state->set("instrument_component_wrapper_$i", $components);

    return $form['process_instruments']['wrapper']["instrument_$i"]['instrument_component_wrapper_'.$i];
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
      $processes = $obj->body;
    }
    foreach ($tasks as $task) {
      $results[] = [
        'value' => $task->label . ' [' . $task->uri . ']',
        'label' => $task->label,
      ];
    }
    return new JsonResponse($results);
  }
}
