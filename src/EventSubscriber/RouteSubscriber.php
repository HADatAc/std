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
    $preferred_workflow = \Drupal::config('rep.settings')->get('preferred_process');
    $preferred_study = trim((string) (\Drupal::config('rep.settings')->get('preferred_study') ?: 'Study'));
    $studySearchTitle = $preferred_study . ' Search';
    $manageStudyTitle = $studySearchTitle . ' > Manage ' . $preferred_study . ' Elements';
    $editStudyTitle = $manageStudyTitle . ' > Edit ' . $preferred_study;

    if($std_home == '1'){
      if ($route = $collection->get('view.frontpage.page_1')) {
        $route->setDefault('_controller', '\Drupal\std\Controller\InitializationController::index');
      }
    }

    if ($route = $collection->get('std.edit_workflowstem')) {
      $route->setDefault('_title', 'Edit ' . $preferred_workflow . ' Stem');
    }
    if ($route = $collection->get('std.add_workflowstem')) {
      $route->setDefault('_title', 'Add ' . $preferred_workflow . ' Stem');
    }
    if ($route = $collection->get('std.edit_workflow')) {
      $route->setDefault('_title', 'Edit ' . $preferred_workflow);
    }
    if ($route = $collection->get('std.add_workflow')) {
      $route->setDefault('_title', 'Add ' . $preferred_workflow);
    }
    if ($route = $collection->get('std.edit_processbasedstudy')) {
      $route->setDefault('_title', 'Edit ' . $preferred_study);
    }
    if ($route = $collection->get('std.search_studies_variables')) {
      $route->setDefault('_title', $studySearchTitle);
    }
    if ($route = $collection->get('std.manage_study_elements')) {
      $route->setDefault('_title', $manageStudyTitle);
    }
    if ($route = $collection->get('std.edit_study')) {
      $route->setDefault('_title', $editStudyTitle);
    }
    if ($route = $collection->get('std.edit_processbasedstudy')) {
      $route->setDefault('_title', $editStudyTitle);
    }
  }

}


