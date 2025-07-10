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

  public function delete($processuri, $state, $taskuri, $encoded) {
    // 1) decodifica e apaga no backend
    $subtask_uri = base64_decode($encoded);
    \Drupal::service('rep.api_connector')->elementDel('task', $subtask_uri);
    $this->messenger()->addStatus($this->t('Sub-task removida.'));

    // 2) Recarrega o form, passando os mesmos parâmetros
    $build = \Drupal::formBuilder()->getForm(
      \Drupal\std\Form\EditTaskForm::class,
      $processuri,
      'tasks',
      $taskuri
    );

    // 3) Devolve apenas o fragmento AJAX necessário
    $response = new AjaxResponse();
    // Substitui o wrapper da tabela de subtasks
    $response->addCommand(new ReplaceCommand(
      '#subtasks-wrapper',
      $build['subtasks']
    ));
    // Substitui as mensagens de status
    $response->addCommand(new HtmlCommand(
      '#subtask-messages',
      \Drupal::service('renderer')->renderRoot(['#type' => 'status_messages'])
    ));

    return $response;
  }
}
