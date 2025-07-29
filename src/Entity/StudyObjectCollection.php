<?php

namespace Drupal\std\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;

class StudyObjectCollection {

  public static function generateHeader() {

    return $header = [
      'soc_uri' => t('URI'),
      'soc_study' => t('Study'),
      'soc_reference' => t('Reference'),
      'soc_label' => t('Label'),
      'soc_grounding_label' => t('Grounding Label'),
      'soc_num_objects' => t('# Objects'),
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
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $study = ' ';
      if ($element->isMemberOf != NULL && $element->isMemberOf->label != NULL) {
        $study = $element->isMemberOf->label;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $socreference = " ";
      if ($element->virtualColumn != NULL && $element->virtualColumn->socreference != NULL) {
        $socreference = $element->virtualColumn->socreference;
      }
      $groundingLabel = " ";
      if ($element->virtualColumn != NULL && $element->virtualColumn->groundingLabel != NULL) {
        $groundingLabel = $element->virtualColumn->groundingLabel;
      }
      $output[$element->uri] = [
          'soc_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.
                        base64_encode($element->uri).'">'.Utils::namespaceUri($element->uri).'</a>'),
          'soc_study' => $study,
          'soc_reference' => $socreference,
          'soc_label' => $element->label,
          'soc_grounding_label' => $groundingLabel,
          'soc_num_objects' => $element->numOfObjects,
      ];
    }
    return $output;

  }

  public static function generateOutputCards($list) {
    $output = [];

    // Get the root URL.
    $root_url = \Drupal::request()->getBaseUrl();

    // Return an empty array if the list is empty.
    if (empty($list)) {
      return $output;
    }

    $index = 0;
    foreach ($list as $element) {
      $index++;

      // Retrieve and process the element's URI.
      $uri = !empty($element->uri) ? $element->uri : ' ';
      $uri = \Drupal\rep\Utils::namespaceUri($uri);

      // Retrieve the label; default to a blank space if not provided.
      $label = !empty($element->label) ? $element->label : ' ';

      // Retrieve the study label if available.
      $study = ' ';
      if (!empty($element->isMemberOf) && !empty($element->isMemberOf->label)) {
        $study = $element->isMemberOf->label;
      }

      // Retrieve the soc reference from the virtual column if available.
      $socreference = " ";
      if (!empty($element->virtualColumn) && !empty($element->virtualColumn->socreference)) {
        $socreference = $element->virtualColumn->socreference;
      }

      // Retrieve the grounding label from the virtual column if available.
      $groundingLabel = " ";
      if (!empty($element->virtualColumn) && !empty($element->virtualColumn->groundingLabel)) {
        $groundingLabel = $element->virtualColumn->groundingLabel;
      }

      // Create a view link for the study object collection.
      // REPGUI::DESCRIBE_PAGE is assumed to be a constant that holds the describe page path.
      // $view_url = Url::fromUserInput($root_url . REPGUI::DESCRIBE_PAGE . base64_encode($element->uri));
      $view_url = Url::fromUri('base:' . REPGUI::DESCRIBE_PAGE . base64_encode($element->uri));

      // Build the card output for this element.
      $output[$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card', 'mb-3'],
        ],
        // Wrap each card in a column (for grid layout).
        '#prefix' => '<div class="col">',
        '#suffix' => '</div>',
        'card_body_' . $index => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['card-body'],
          ],
          // Card title shows the element label.
          'title' => [
            '#markup' => '<h5 class="card-title">' . $label . '</h5>',
          ],
          // Card details show study, reference, grounding label, and number of objects.
          'details' => [
            '#markup' => '<p class="card-text">'
              . '<strong>Study:</strong> ' . $study . '<br>'
              . '<strong>Reference:</strong> ' . $socreference . '<br>'
              . '<strong>Grounding:</strong> ' . $groundingLabel . '<br>'
              . '<strong>Objects:</strong> ' . $element->numOfObjects
              . '</p>',
          ],
          // "View" button linking to the description page.
          'view_link_' . $index => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
            '#url' => $view_url,
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary'],
              'target' => '_new',
            ],
          ],
        ],
      ];
    }

    return $output;
  }


}
