<?php

namespace Drupal\std\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;

class Stream {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_pattern' => t('Datafile Pattern'),
      'element_semanticdatadictionary' => t('Semantic Data Dictionary'),
      'element_deployment' => t('Deployment'),
    ];
  
  }

  public static function generateOutput($list) {

    //dpm($list);

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $semanticDataDictionary = ' ';
      if ($element->semanticDataDictionary != NULL && $element->semanticDataDictionary->label != NULL) {
        $semanticDataDictionary = $element->semanticDataDictionary->label;
      }
      $deployment = ' ';
      if ($element->deployment != NULL && $element->deployment->label != NULL) {
        $deployment = $element->deployment->label;
      }
      $pattern = ' ';
      if ($element->datasetPattern != NULL) {
        $pattern = $element->datasetPattern;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),  
        'element_name' => t($element->label),   
        'element_pattern' => t($pattern),     
        'element_semanticdatadictionary' => t($semanticDataDictionary),     
        'element_deployment' => t($deployment),     
      ];
    }
    return $output;

  }

}