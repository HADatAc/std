<?php

namespace Drupal\std\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;

class VirtualColumn {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_study' => t('Study'),
      'element_soc_reference' => t('SOC Reference'),
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
      $soc = ' ';
      if ($element->socreference != NULL) {
        $soc = $element->socreference;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_study' => t($study),
        'element_soc_reference' => t($soc),
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

      // Get the element URI; if not provided, default to an empty string.
      $uri = !empty($element->uri) ? $element->uri : '';

      // Process the URI (for example, applying a namespace transformation).
      $uri = \Drupal\rep\Utils::namespaceUri($uri);

      // Retrieve the study label if available.
      $study = ' ';
      if (!empty($element->isMemberOf) && !empty($element->isMemberOf->label)) {
        $study = $element->isMemberOf->label;
      }

      // Retrieve the soc reference if available.
      $soc = ' ';
      if (!empty($element->socreference)) {
        $soc = $element->socreference;
      }

      // Create a view link for the virtual column.
      // This builds a link to the description page using a back_url mechanism.
      $path = \Drupal::request()->getPathInfo();
      $safe_previousUrl = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
      $view_virtual_str = base64_encode(Url::fromRoute('rep.describe_element', [
        'elementuri' => base64_encode($element->uri)
      ])->toString());
      $view_virtual = Url::fromRoute('rep.back_url', [
        'previousurl' => $safe_previousUrl,
        'currenturl' => $view_virtual_str,
        'currentroute' => 'rep.describe_element',
      ]);

      // Build the card output.
      $output[$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card', 'mb-3'],
        ],
        // Wrap each card in a column for grid layouts.
        '#prefix' => '<div class="col">',
        '#suffix' => '</div>',
        'card_body_' . $index => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['card-body'],
          ],
          // Display the URI as the card title.
          'title' => [
            '#markup' => '<h5 class="card-title">' . $uri . '</h5>',
          ],
          // Display study and soc reference in the card text.
          'text' => [
            '#markup' => '<p class="card-text">Study: ' . $study . '<br>Soc: ' . $soc . '</p>',
          ],
          // Add a "View" link button.
          'view_link_' . $index => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
            '#url' => $view_virtual,
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary'],
              'style' => 'margin-right: 10px;',
              'target' => '_new',
            ],
          ],
        ],
      ];
    }
    return $output;
  }

}
