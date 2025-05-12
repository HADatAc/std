<?php

namespace Drupal\std\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;

class StudyRole {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_study' => t('Study'),
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
      $study = ' ';
      if ($element->isMemberOf != NULL && $element->isMemberOf->label != NULL) {
        $study = $element->isMemberOf->label;
      }
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_study' => t($study),
        'element_name' => t($label),
      ];
    }
    return $output;

  }

  public static function generateOutputCards($list) {
    $output = [];
    // Get the root URL.
    $root_url = \Drupal::request()->getBaseUrl();

    // If the list is empty, return an empty array.
    if (empty($list)) {
      return $output;
    }

    $index = 0;
    foreach ($list as $element) {
      $index++;

      // Retrieve and process the element's URI.
      $uri = !empty($element->uri) ? $element->uri : ' ';
      // Process the URI using your namespace utility.
      $uri = \Drupal\rep\Utils::namespaceUri($uri);

      // Retrieve the study from isMemberOf, if available.
      $study = ' ';
      if (!empty($element->isMemberOf) && !empty($element->isMemberOf->label)) {
        $study = $element->isMemberOf->label;
      }

      // Retrieve the role label.
      $label = !empty($element->label) ? $element->label : ' ';

      // Create a link for the element. This builds a link to the description page.
      // Adjust REPGUI::DESCRIBE_PAGE to your actual constant/path.
      $describe_url = Url::fromUserInput($root_url . REPGUI::DESCRIBE_PAGE . base64_encode($element->uri));

      // Build the card output.
      $output[$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card', 'mb-3'],
        ],
        // Wrap each card in a column for grid layout.
        '#prefix' => '<div class="col-md-6">',
        '#suffix' => '</div>',
        'card_body_' . $index => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['card-body'],
          ],
          // Card title displays the role label.
          'title' => [
            '#markup' => '<h5 class="card-title">' . $label . '</h5>',
          ],
          // Card text displays the study and URI.
          'details' => [
            '#markup' => '<p class="card-text"><strong>Study:</strong> ' . $study . '<br><strong>URI:</strong> ' . $uri . '</p>',
          ],
          // Add a "View" button linking to the description page.
          'view_link_' . $index => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
            '#url' => $describe_url,
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary'],
            ],
          ],
        ],
      ];
    }
    return $output;
  }

}
