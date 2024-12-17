<?php
namespace Drupal\std\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class STDSelectStudyController extends ControllerBase {
  public function loadMore() {
    // Implement logic to load more items here
    $data = [
      'message' => 'Data loaded successfully!',
      // You can include HTML fragments, JSON, etc. based on your requirements.
    ];

    return new JsonResponse($data);
  }
}
