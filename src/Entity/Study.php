<?php

namespace Drupal\std\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;

class Study {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_short_name' => t('Short Name'),
      'element_name' => t('Name'),
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
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $title = ' ';
      if ($element->title != NULL) {
        $title = $element->title;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_short_name' => t($label),    
        'element_name' => t($title),
      ];
    }
    return $output;
  }

}