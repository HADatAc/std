<?php

namespace Drupal\std\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;

class Stream {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_sdd' => t('SDD'),
      'element_deployment' => t('Deployment'),
    ];
  
  }

  public static function generateOutput($list) {

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $sdd = ' ';
      if ($element->hasSDD != NULL && $element->hasSDD->label != NULL) {
        $sdd = $element->hasSDD->label;
      }
      $deployment = ' ';
      if ($element->hasDeployment != NULL && $element->hasDeployment->label != NULL) {
        $soc = $element->hasDeployment->label;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_study' => t($sdd),     
        'element_soc_reference' => t($deployment),     
      ];
    }
    return $output;

  }

}