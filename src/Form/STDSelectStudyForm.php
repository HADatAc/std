<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\Core\Ajax\AjaxResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

class STDSelectStudyForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_select_study_form';
  }

  public $element_type;
  public $manager_email;
  public $manager_name;
  public $single_class_name;
  public $plural_class_name;
  protected $list;
  protected $list_size;

  public function getList() {
    return $this->list;
  }

  public function setList($list) {
    $this->list = $list;
  }

  public function getListSize() {
    return $this->list_size;
  }

  public function setListSize($list_size) {
    $this->list_size = $list_size;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $page = NULL, $pagesize = NULL) {

    $form['#attached']['library'][] = 'std/std_js_css';

    $form['#attached']['drupalSettings']['std_select_study_form']['ajaxUrl'] = Url::fromRoute('std.load_more_data')->toString();

    $this->element_type = $elementtype ?? 'study'; // Valor padrão
    $form['#attached']['drupalSettings']['std_select_study_form']['elementType'] = $this->element_type;


    // OBTÉM O EMAIL DO GERENTE
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->getDisplayName();

    // OBTÉM O TIPO DE ELEMENTO
    $this->element_type = $elementtype;

    // Tamanho de página padrão se não for fornecido
    if ($pagesize === NULL) {
      $pagesize = 9; // Carrega 9 itens por vez
    }

    // Recupera o parâmetro 'items_loaded' da URL, se existir
    $items_loaded = \Drupal::request()->query->get('items_loaded') ?? 0;
    $form_state->set('items_loaded', $items_loaded);

    // Recupera ou define o tipo de visualização padrão
    $session = \Drupal::request()->getSession();
    $view_type = $session->get('std_select_study_view_type', 'card');
    $form_state->set('view_type', $view_type);

    // Armazena o tamanho da página no estado do formulário para uso em callbacks AJAX
    $form_state->set('page_size', $pagesize);

    // Determina os nomes de classe com base no tipo de elemento
    $this->single_class_name = "";
    $this->plural_class_name = "";
    switch ($this->element_type) {
      case "study":
        $this->single_class_name = "Study";
        $this->plural_class_name = "Studies";
        break;
      default:
        $this->single_class_name = "Object of Unknown Type";
        $this->plural_class_name = "Objects of Unknown Types";
    }

    // MONTAR O FORMULÁRIO
    $form['page_title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-5">Manage ' . $this->plural_class_name . '</h3>',
    ];
    $form['page_subtitle'] = [
      '#type' => 'item',
      '#markup' => $this->t('<h4>@plural_class_name maintained by <font color="DarkGreen">@manager_name (@manager_email)</font></h4>', [
        '@plural_class_name' => $this->plural_class_name,
        '@manager_name' => $this->manager_name,
        '@manager_email' => $this->manager_email,
      ]),
    ];

    // Adiciona botões de alternância de visualização
    $form['view_toggle'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-toggle', 'd-flex', 'justify-content-end']],
    ];

    $form['view_toggle']['table_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_table',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => ['table-view-button', 'fa-xl', 'mx-1'],
        'title' => $this->t('Table View'),
      ],
      '#submit' => ['::viewTableSubmit'],
      '#limit_validation_errors' => [],
    ];

    $form['view_toggle']['card_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_card',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => ['card-view-button', 'fa-xl'],
        'title' => $this->t('Card View'),
      ],
      '#submit' => ['::viewCardSubmit'],
      '#limit_validation_errors' => [],
    ];

    $form['add_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add New ' . $this->single_class_name),
      '#name' => 'add_element',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'add-element-button'],
      ],
    ];

    if ($view_type == 'card') {

      $form['space1'] = [
        '#type' => 'item',
        '#markup' => '<br>',
      ];

      // Inicializa a página atual
      if ($form_state->get('page') === NULL) {
        if ($page === NULL) {
          $page = 1; // Começa de 1
        }
        $form_state->set('page', $page);
      } else {
        $page = $form_state->get('page');
      }

      // Armazena o número da página no estado do formulário
      $form_state->set('page', $page);

      // Adiciona a biblioteca JavaScript para infinite scroll (certifique-se de ter essa biblioteca no seu módulo)
      // $form['#attached']['library'][] = 'std/infinite_scroll'; // Se você tiver uma biblioteca JS

      $items_loaded = $form_state->get('items_loaded') ?? 0;
      $total_pages_to_load = ceil($items_loaded / $pagesize);

      // Carrega todas as páginas necessárias para restaurar o estado original
      for ($i = 1; $i <= $total_pages_to_load; $i++) {
        $additional_items = ListManagerEmailPage::exec($this->element_type, $this->manager_email, $i, $pagesize);
        if ($i == 1) {
          $this->setList($additional_items);
        } else {
          $this->list = array_merge($this->list, $additional_items);
        }
      }

      // Recupera os elementos para a página atual
      $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, $page, $pagesize));

      // Obtém o número total de itens
      $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
      $total_items = $this->getListSize();

      // Envolve os cartões em um container para AJAX
      $form['cards_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'cards-wrapper'],
      ];

      // Constrói a visualização em cartões dentro do 'cards_wrapper'
      $this->buildCardView($form['cards_wrapper'], $form_state);

      // Verifica se há mais itens para carregar
      if ($total_items > $page * $pagesize) {
        $form['load_more_wrapper'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['text-center', 'my-3'],
          ],
        ];

        // // Adiciona o botão "Load More" dentro do container
        // $form['load_more_wrapper']['load_more'] = [
        //   '#type' => 'submit',
        //   '#value' => $this->t('Load More'),
        //   '#ajax' => [
        //     'callback' => '::loadMoreCallback',
        //     'wrapper' => 'cards-wrapper',
        //     'method' => 'append',
        //   ],
        //   '#attributes' => [
        //     'class' => ['btn', 'btn-primary', 'load-more-button'],
        //     'style' => 'height: auto;', // Ajusta a altura ao conteúdo
        //   ],
        //   '#name' => 'load_more',
        // ];
      }

    } else {
      // Initialize page 1
      if ($page === NULL) {
        $page = 1;
      }

      // Store page number and form status
      $form_state->set('page', $page);

      // Build table view
      $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, $page, $pagesize));
      $this->buildTableView($form, $form_state);

      // Get total elements number and pages
      $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
      $total_items = $this->getListSize();

      if ($total_items % $pagesize == 0) {
        $total_pages = $total_items / $pagesize;
      } else {
        $total_pages = floor($total_items / $pagesize) + 1;
      }

      // Next and Previous page links
      if ($page < $total_pages) {
        $next_page = $page + 1;
        $next_page_link = ListManagerEmailPage::link($this->element_type, $next_page, $pagesize);
      } else {
        $next_page_link = '';
      }
      if ($page > 1) {
        $previous_page = $page - 1;
        $previous_page_link = ListManagerEmailPage::link($this->element_type, $previous_page, $pagesize);
      } else {
        $previous_page_link = '';
      }

      // Add pagination on bottom
      $form['pager'] = [
        '#theme' => 'list-page',
        '#items' => [
          'page' => strval($page),
          'first' => ListManagerEmailPage::link($this->element_type, 1, $pagesize),
          'last' => ListManagerEmailPage::link($this->element_type, $total_pages, $pagesize),
          'previous' => $previous_page_link,
          'next' => $next_page_link,
          'last_page' => strval($total_pages),
          'links' => null,
          'title' => ' ',
        ],
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];
    $form['space2'] = [
      '#type' => 'item',
      '#markup' => '<br><br><br>',
    ];

    return $form;
  }

  /**
   * Constroi visualização de Cards
   */
  protected function buildCardView(array &$form, FormStateInterface $form_state) {
    // Get items list
    $items = $this->getList();

    $cards = [];

    // Process each entry to build cards
    foreach ($items as $index => $element) {
        $uri = $element->uri ?? '';
        $label = $element->label ?? '';
        $title = $element->title ?? '';
        $pi = $element->pi ?? '';
        $ins = $element->institution ?? '';
        $desc = $element->comment ?? '';

        // Build Card Array
        $card = [
            '#type' => 'container',
            '#attributes' => ['class' => ['col-md-4'], 'id' => 'card-item-' . md5($uri)], // Adiciona um identificador único
        ];

        $card['card'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['card', 'mb-3']],
        ];

        // Card Header
        $shortName = $label;
        $card['card']['header'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'margin-bottom:0!important;',
                'class' => ['card-header']
            ],
            '#markup' => '<h5>' . $shortName . '</h5>',
        ];

        // determine is the element has an image or should be presented a placeholder
        if (!empty($element->image)) {
            $image_uri = $element->image;
        } else {
            // module placeholder
            $image_uri = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/std_placeholder.png';
        }

        // Created URI
        $uri = Utils::namespaceUri($uri);

        // Card Body
        $card['card']['body'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'margin-bottom:0!important;',
                'class' => ['card-body', 'mb-0']
            ],
            'row' => [
                '#type' => 'container',
                '#attributes' => [
                    'style' => 'margin-bottom:0!important;',
                    'class' => ['row']
                ],
                'image_column' => [
                    '#type' => 'container',
                    '#attributes' => [
                        'style' => 'margin-bottom:0!important;',
                        'class' => ['col-md-5', 'text-center', 'mb-0', 'align-middle']
                    ],
                    'image' => [
                        '#theme' => 'image',
                        '#uri' => $image_uri,
                        '#alt' => $this->t('Image for @name', ['@name' => $title]),
                        '#attributes' => [
                            'style' => 'width: 70%',
                            'class' => ['img-fluid', 'mb-0'],
                        ],
                    ],
                ],
                'text_column' => [
                    '#type' => 'container',
                    '#attributes' => [
                        'style' => 'margin-bottom:0!important;',
                        'class' => ['col-md-7']
                    ],
                    'text' => [
                        '#markup' => '<p class="card-text"><strong>Name:</strong> ' . $title
                            . '<br><strong>URI:</strong> ' . Link::fromTextAndUrl($uri, Url::fromUserInput(REPGUI::DESCRIBE_PAGE . base64_encode($uri)))->toString()
                            . '<br><strong>PI: </strong>' . $pi
                            . '<br><strong>Institution: </strong>' . $ins
                            . '<br><strong>Description: </strong>' . $desc . '</p>',
                    ],
                ],
            ],
        ];

        // Build action links
        $previousUrl = base64_encode(\Drupal::request()->getRequestUri());

        if ($element->uri != NULL && $element->uri != "") {
            // Change URI
            $studyUriEncoded = base64_encode($element->uri);

            // Management link
            $manage_elements_str = base64_encode(Url::fromRoute('std.manage_study_elements', [
                'studyuri' => $studyUriEncoded,
            ])->toString());

            $manage_elements = Url::fromRoute('rep.back_url', [
                'previousurl' => $previousUrl,
                'currenturl' => $manage_elements_str,
                'currentroute' => 'std.manage_study_elements',
            ]);

            // View Link
            $view_study_str = base64_encode(Url::fromRoute('rep.describe_element', [
                'elementuri' => $studyUriEncoded,
            ])->toString());

            $view_study = Url::fromRoute('rep.back_url', [
                'previousurl' => $previousUrl,
                'currenturl' => $view_study_str,
                'currentroute' => 'rep.describe_element',
            ]);

            // Edit link
            $edit_study_str = base64_encode(Url::fromRoute('std.edit_study', [
                'studyuri' => $studyUriEncoded,
            ])->toString());

            $edit_study = Url::fromRoute('rep.back_url', [
                'previousurl' => $previousUrl,
                'currenturl' => $edit_study_str,
                'currentroute' => 'std.edit_study',
            ]);

            // Delete link
            $delete_study = Url::fromRoute('rep.delete_element', [
                'elementtype' => 'study',
                'elementuri' => $studyUriEncoded,
                'currenturl' => $previousUrl,
            ]);
        }

        // Card footer
        $card['card']['footer'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'margin-bottom:0!important;',
                'class' => ['card-footer', 'text-right', 'd-flex', 'justify-content-end'],
            ],
            'actions' => [
                'link1' => [
                    '#type' => 'link',
                    '#title' => Markup::create('<i class="fa-solid fa-folder-tree"></i> Manage Elements'),
                    '#url' => $manage_elements,
                    '#attributes' => [
                        'class' => ['btn', 'btn-sm', 'btn-secondary', 'mx-1'],
                    ],
                ],
                'link2' => [
                    '#type' => 'link',
                    '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
                    '#url' => $view_study,
                    '#attributes' => [
                        'class' => ['btn', 'btn-sm', 'btn-secondary', 'mx-1'],
                    ],
                ],
                'link3' => [
                    '#type' => 'link',
                    '#title' => Markup::create('<i class="fa-solid fa-pen-to-square"></i> Edit'),
                    '#url' => $edit_study,
                    '#attributes' => [
                        'class' => ['btn', 'btn-sm', 'btn-secondary', 'mx-1'],
                    ],
                ],
                'link4' => [
                    '#type' => 'link',
                    '#title' => Markup::create('<i class="fa-solid fa-trash-can"></i> Delete'),
                    '#url' => $delete_study,
                    '#attributes' => [
                        'class' => ['btn', 'btn-sm', 'btn-danger', 'mx-1'],
                        'onclick' => 'if(!confirm("Really Delete?")){return false;}',
                    ],
                ],
            ],
        ];

        $cards[] = $card;
    }

    // Form cards
    $index = 0;
    // 3 on each row
    foreach (array_chunk($cards, 3) as $row) {
        $index++;
        if (!isset($form['row_' . $index])) {
            $form['row_' . $index] = [
                '#type' => 'container',
                '#attributes' => [
                    'style' => 'margin-bottom:0!important;',
                    'class' => ['row', 'mb-0'],
                ],
            ];
        }
        $indexCard = 0;
        foreach ($row as $card) {
            $indexCard++;
            $form['row_' . $index]['element_' . $indexCard] = $card;
        }
    }
  }


  /**
   * Build Table View
   */
  protected function buildTableView(array &$form, FormStateInterface $form_state) {
    // Define o cabeçalho da tabela
    $header = [
      'uri' => ['data' => $this->t('URI')],
      'short_name' => ['data' => $this->t('Short Name')],
      'name' => ['data' => $this->t('Name')],
      'actions' => ['data' => $this->t('Actions')],
    ];

    // Constrói as linhas da tabela
    $rows = [];
    foreach ($this->getList() as $element) {
      $uri = $element->uri ?? '';
      $uri = Utils::namespaceUri($uri);
      $label = $element->label ?? '';
      $title = $element->title ?? '';

      // URI como link
      $encodedUri = base64_encode($uri);
      $uri_link = Link::fromTextAndUrl($uri, Url::fromUserInput(REPGUI::DESCRIBE_PAGE . $encodedUri))->toString();
      $row['uri'] = ['data' => ['#markup' => $uri_link]];

      // Short Name
      $row['short_name'] = $label;

      // Name
      $row['name'] = $title;

      // Ações
      $actions = [];

      // Constrói URLs para os links
      $previousUrl = base64_encode(\Drupal::request()->getRequestUri());
      $studyUriEncoded = base64_encode($element->uri);

      // Link para Gerenciar Elementos
      $manage_elements_str = base64_encode(Url::fromRoute('std.manage_study_elements', [
        'studyuri' => $studyUriEncoded,
      ])->toString());

      $manage_elements = Url::fromRoute('rep.back_url', [
        'previousurl' => $previousUrl,
        'currenturl' => $manage_elements_str,
        'currentroute' => 'std.manage_study_elements',
      ]);

      // Link para Visualizar
      $view_study_str = base64_encode(Url::fromRoute('rep.describe_element', [
        'elementuri' => $studyUriEncoded,
      ])->toString());

      $view_study = Url::fromRoute('rep.back_url', [
        'previousurl' => $previousUrl,
        'currenturl' => $view_study_str,
        'currentroute' => 'rep.describe_element',
      ]);

      // Link para Editar
      $edit_study_str = base64_encode(Url::fromRoute('std.edit_study', [
        'studyuri' => $studyUriEncoded,
      ])->toString());

      $edit_study = Url::fromRoute('rep.back_url', [
        'previousurl' => $previousUrl,
        'currenturl' => $edit_study_str,
        'currentroute' => 'std.edit_study',
      ]);

      // Link para Excluir
      $delete_study = Url::fromRoute('rep.delete_element', [
        'elementtype' => 'study',
        'elementuri' => $studyUriEncoded,
        'currenturl' => $previousUrl,
      ]);

      // Ações
      $actions = [];

      // Link para Gerenciar Elemento
      $actions['manage_element'] = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa-solid fa-folder-tree"></i> Manage Elements'),
        '#url' => $manage_elements,
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-sm'],
        ],
      ];

      // Link para Visualizar
      $actions['view'] = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
        '#url' => $view_study,
        '#attributes' => [
          'class' => ['btn', 'btn-secondary', 'btn-sm', 'mx-1'],
        ],
      ];

      // Link para Editar
      $actions['edit'] = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa-solid fa-pen-to-square"></i> Edit'),
        '#url' => $edit_study,
        '#attributes' => [
          'class' => ['btn', 'btn-warning', 'btn-sm'],
        ],
      ];

      // Link para Excluir
      $actions['delete'] = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa-solid fa-trash-can"></i> Delete'),
        '#url' => $delete_study,
        '#attributes' => [
          'class' => ['btn', 'btn-danger', 'btn-sm', 'delete-button', 'mx-1'],
          'onclick' => 'if(!confirm("Are you sure you want to delete this item?")){return false;}',
        ],
      ];

      $row['actions'] = [
        'data' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['action-buttons']],
          'buttons' => $actions,
        ],
      ];

      $rows[] = $row;
    }

    // Constrói a tabela
    $form['element_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No ' . $this->plural_class_name . ' found'),
    ];
  }

  /**
   * Load more function for Cards
   */
  public function loadMoreCallback(array &$form = NULL, FormStateInterface $form_state = NULL) {
    if ($form_state === NULL) {
        $form_state = new \Drupal\Core\Form\FormState();
    }

    // Is if loading has started to prevent double request
    if ($form_state->get('loading')) {
        return new JsonResponse(['cards' => []]);
    }

    $form_state->set('loading', true);

    // Carregar a próxima página
    $page = \Drupal::request()->query->get('page') ?? 1;
    $this->element_type = \Drupal::request()->query->get('element_type');
    $this->manager_email = \Drupal::currentUser()->getEmail();

    $pagesize = $form_state->get('page_size') ?? 9;
    $new_items = ListManagerEmailPage::exec($this->element_type, $this->manager_email, $page, $pagesize);

    // Update status on already loaded items
    $items_loaded = $form_state->get('items_loaded') ?? 0;
    $items_loaded += count($new_items);
    $form_state->set('items_loaded', $items_loaded);

    // Build new cards
    $new_cards = [];
    $this->setList($new_items);
    $this->buildCardView($new_cards, $form_state);

    // Render new cards
    $renderer = \Drupal::service('renderer');
    $rendered_cards = $renderer->renderRoot($new_cards);

    // Cancel loading status
    $form_state->set('loading', false);

    return new JsonResponse(['cards' => $rendered_cards, 'page' => $page]);
}


  /**
   * Manipulador de envio para alternar para a visualização em tabela.
   */
  public function viewTableSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->set('view_type', 'table');
    $form_state->set('page', 1); // Reseta o número da página
    $session = \Drupal::request()->getSession();
    $session->set('std_select_study_view_type', 'table');
    $form_state->setRebuild();
  }

  /**
   * Manipulador de envio para alternar para a visualização em cartões.
   */
  public function viewCardSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->set('view_type', 'card');
    $form_state->set('page', 1); // Reseta o número da página
    $session = \Drupal::request()->getSession();
    $session->set('std_select_study_view_type', 'card');
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // RECUPERA O BOTÃO QUE DISPAROU O ENVIO
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // DEFINE O ID DO USUÁRIO E A URL ANTERIOR PARA RASTREAMENTO
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();

    // Lida com ações com base no nome do botão
    if ($button_name === 'add_element') {
      Utils::trackingStoreUrls($uid, $previousUrl, 'std.add_study');
      $url = Url::fromRoute('std.add_study');
      $form_state->setRedirectUrl($url);
    } elseif ($button_name === 'back') {
      $url = Url::fromRoute('std.search');
      $form_state->setRedirectUrl($url);
    } elseif ($button_name === 'edit_element') {
      // Lida com a edição de elementos selecionados na visualização em tabela
      $this->handleEditSelected($form_state);
    } elseif ($button_name === 'delete_element') {
      // Lida com a exclusão de elementos selecionados na visualização em tabela
      $this->handleDeleteSelected($form_state);
    }
  }

  /**
   * Executa a ação de edição.
   */
  protected function performEdit($uri, FormStateInterface $form_state) {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    $items_loaded = $form_state->get('items_loaded') ?? 0;

    // Adiciona parâmetro com o número de itens carregados
    Utils::trackingStoreUrls($uid, $previousUrl, 'std.edit_study');
    $url = Url::fromRoute('std.edit_study', [
      'studyuri' => base64_encode($uri),
      'items_loaded' => $items_loaded,
    ]);

    // Se a chamada for via AJAX, redireciona diretamente
    if (\Drupal::request()->isXmlHttpRequest()) {
      $url = $url->toString();
      $response = new AjaxResponse();
      $response->addCommand(new RedirectCommand($url));
      return $response;
    }

    $form_state->setRedirectUrl($url);
  }

  /**
   * Executa a ação de exclusão.
   */
  protected function performDelete(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    foreach ($uris as $uri) {
      $study = $api->parseObjectResponse($api->getUri($uri), 'getUri');
      if ($study != NULL && $study->hasDataFile != NULL) {

        // EXCLUI O ARQUIVO
        if (isset($study->hasDataFile->id)) {
          $file = \Drupal\file\Entity\File::load($study->hasDataFile->id);
          if ($file) {
            $file->delete();
            \Drupal::messenger()->addMessage($this->t('File with ID @id deleted.', ['@id' => $study->hasDataFile->id]));
          }
        }

        // EXCLUI O DATAFILE
        if (isset($study->hasDataFile->uri)) {
          $api->dataFileDel($study->hasDataFile->uri);
          \Drupal::messenger()->addMessage($this->t('DataFile with URI @uri deleted.', ['@uri' => $study->hasDataFile->uri]));
        }
      }
    }
    \Drupal::messenger()->addMessage($this->t('Selected ' . $this->plural_class_name . ' have been successfully deleted.'));
    $form_state->setRebuild();
  }

  /**
   * Lida com a edição de elementos selecionados na visualização em tabela.
   */
  protected function handleEditSelected(FormStateInterface $form_state) {
    $selected_rows = $form_state->getUserInput()['select'] ?? [];
    $selected_uris = array_keys(array_filter($selected_rows));
    if (count($selected_uris) < 1) {
      \Drupal::messenger()->addWarning($this->t('Select exactly one ' . $this->single_class_name . ' to be edited.'));
    } elseif (count($selected_uris) > 1) {
      \Drupal::messenger()->addWarning($this->t('No more than one ' . $this->single_class_name . ' can be edited at once.'));
    } else {
      $uri = array_shift($selected_uris);
      $this->performEdit($uri, $form_state);
    }
  }

  /**
   * Lida com a exclusão de elementos selecionados na visualização em tabela.
   */
  protected function handleDeleteSelected(FormStateInterface $form_state) {
    $selected_rows = $form_state->getUserInput()['select'] ?? [];
    $selected_uris = array_keys(array_filter($selected_rows));
    if (count($selected_uris) <= 0) {
      \Drupal::messenger()->addWarning($this->t('At least one ' . $this->single_class_name . ' needs to be selected to be deleted.'));
    } else {
      $this->performDelete($selected_uris, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function backSelect($elementType) {
    $url = Url::fromRoute('rep.home');
    return $url;
  }

}
