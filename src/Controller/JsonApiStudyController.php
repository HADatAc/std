<?php

namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;

/**
 * Class JsonApiStudyController
 * @package Drupal\std\Controller
 */
class JsonApiStudyController extends ControllerBase{

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
    $attribute_list = $api->listByKeyword('study',$keyword,10,0);
    $obj = json_decode($attribute_list);
    $attributes = [];
    if ($obj->isSuccessful) {
      $attributes = $obj->body;
    }
    foreach ($attributes as $attribute) {
      $results[] = [
        'value' => $attribute->label . ' [' . $attribute->uri . ']',
        'label' => $attribute->label,
      ];
    }
    return new JsonResponse($results);
  }

}