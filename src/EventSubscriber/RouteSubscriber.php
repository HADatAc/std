<?php

namespace Drupal\std\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;
use Drupal\Core\Config\ConfigFactoryInterface;

class RouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new YourModuleRouteSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  protected function alterRoutes(RouteCollection $collection) {

    $config = $this->configFactory->get('std.settings');
    $std_home = $config->get('std_home');
    $preferred_processstem = \Drupal::config('rep.settings')->get('preferred_process');

    if($std_home == '1'){
      if ($route = $collection->get('view.frontpage.page_1')) {
        $route->setDefault('_controller', '\Drupal\std\Controller\InitializationController::index');
      }
    }

    if ($route = $collection->get('std.edit_processstem')) {
      $route->setDefault('_title', 'Edit ' . $preferred_processstem . ' Stem');
    }
    if ($route = $collection->get('std.add_processstem')) {
      $route->setDefault('_title', 'Add ' . $preferred_processstem . ' Stem');
    }
    if ($route = $collection->get('std.edit_process')) {
      $route->setDefault('_title', 'Edit ' . $preferred_processstem);
    }
    if ($route = $collection->get('std.add_process')) {
      $route->setDefault('_title', 'Add ' . $preferred_processstem);
    }
  }

}
