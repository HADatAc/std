<?php

namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;
use Drupal\rep\Utils;

/**
 * Class JsonApiController
 * @package Drupal\std\Controller
 */
class JsonApiController extends ControllerBase{

  /**
   * @return JsonResponse
   */
  public function studyAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');
    if (!$input) {
      return new JsonResponse($results);
    }
    $keyword = Xss::filter($input);
    $api = \Drupal::service('rep.api_connector');
    $study_list = $api->listByKeyword('study',$keyword,10,0);
    $obj = json_decode($study_list);
    $studies = [];
    if ($obj->isSuccessful) {
      $studies = $obj->body;
    }
    foreach ($studies as $study) {
      $results[] = [
        'value' => $study->label . ' [' . $study->uri . ']',
        'label' => $study->label,
      ];
    }
    return new JsonResponse($results);
  }

  /**
   * @return JsonResponse
   */
  public function semanticDataDictionaryAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');
    if (!$input) {
      return new JsonResponse($results);
    }
    $keyword = Xss::filter($input);
    $api = \Drupal::service('rep.api_connector');
    $semanticdatadictionary_list = $api->listByKeyword('semanticdatadictionary',$keyword,10,0);
    $obj = json_decode($semanticdatadictionary_list);
    $semanticdatadictionaries = [];
    if ($obj->isSuccessful) {
      $semanticdatadictionaries = $obj->body;
    }
    foreach ($semanticdatadictionaries as $semanticdatadictionary) {
      $results[] = [
        'value' => $semanticdatadictionary->label . ' [' . $semanticdatadictionary->uri . ']',
        'label' => $semanticdatadictionary->label,
      ];
    }
    return new JsonResponse($results);
  }

  /**
   * @return JsonResponse
   */
  public function deploymentAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');
    if (!$input) {
      return new JsonResponse($results);
    }
    $keyword = Xss::filter($input);
    $api = \Drupal::service('rep.api_connector');
    $deployment_list = $api->listByKeyword('deployment',$keyword,10,0);
    $obj = json_decode($deployment_list);
    $deployments = [];
    if ($obj->isSuccessful) {
      $deployments = $obj->body;
    }
    foreach ($deployments as $deployment) {
      $results[] = [
        // 'value' => $deployment->label . ' [' . $deployment->uri . ']',
        'value' => UTILS::trimAutoCompleteString($deployment->label, $deployment->uri),
        'label' => $deployment->label,
      ];
    }
    return new JsonResponse($results);
  }

}
