<?php

namespace Drupal\std\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\std\Entity\Task;

class SubTaskController extends ControllerBase {

  public function delete($processuri, $state, $parenttaskuri, $taskuri) {
    $subtask_uri = base64_decode($taskuri);
    \Drupal::service('rep.api_connector')->taskDeleteWithTasks($subtask_uri);
    $this->messenger()->addStatus($this->t('Sub-task deleted successfully.'));

    // Rebuild the EditTaskForm in the proper state.
    $build = \Drupal::formBuilder()->getForm(
      \Drupal\std\Form\EditTaskForm::class,
      $processuri,
      'tasks',
      $parenttaskuri
    );

    $renderer = \Drupal::service('renderer');

    // Prepare the status_messages render array in a variable.
    $messages = [
      '#type'   => 'status_messages',
      '#weight' => -1000,
    ];
    // Now renderRoot() can accept it by reference.
    $messages_html = $renderer->renderRoot($messages);

    // Build the AJAX response.
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '#subtasks-wrapper',
      $build['subtasks']
    ));
    $response->addCommand(new HtmlCommand(
      '#subtask-messages',
      $messages_html
    ));

    return $response;
  }
}
