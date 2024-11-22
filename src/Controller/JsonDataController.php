<?php

namespace Drupal\std\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\rep\ListManagerEmailPageByStudy;
use Drupal\rep\Utils;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\HttpFoundation\RequestStack;

class JsonDataController
{

    public $study;

    public $element_type;

    public $manager_email;

    public $manager_name;

    public $single_class_name;

    public $plural_class_name;

    protected $mode;

    protected $list;

    protected $list_size;

    public function getStudy()
    {
        return $this->study;
    }

    public function setStudy($study)
    {
        return $this->study = $study;
    }

    public function getMode()
    {
        return $this->mode;
    }

    public function setMode($mode)
    {
        return $this->mode = $mode;
    }

    public function getList()
    {
        return $this->list;
    }

    public function setList($list)
    {
        return $this->list = $list;
    }

    public function getListSize()
    {
        return $this->list_size;
    }

    public function setListSize($list_size)
    {
        return $this->list_size = $list_size;
    }

    public function getTableData($studyuri = NULL, $elementtype = NULL, $mode = 'compact', $page = 1, $pagesize = 5)
    {

        // Obtenha o valor da sessão para fallback
        $session = \Drupal::service('session');
        $page_from_session = $session->get('da_current_page', 1);

        // Use o valor da URL, ou o valor na sessão como fallback
        $page = $page ?: $page_from_session;

        // Salve a página na sessão
        $session->set('da_current_page', $page);

        // Certifique-se de que `$page` é válido
        if (!is_numeric($page) || $page < 1) {
            $page = 1;
        }

        // Validar os parâmetros recebidos
        if (empty($studyuri) || empty($elementtype) || !is_numeric($page) || !is_numeric($pagesize)) {
            return new JsonResponse(['error' => 'Invalid parameters'], 400);
        }

        // GET MANAGER EMAIL
        $this->manager_email = \Drupal::currentUser()->getEmail();
        $uid = \Drupal::currentUser()->id();
        $user = \Drupal\user\Entity\User::load($uid);
        $this->manager_name = $user->name->value;

        // GET MODE
        $this->mode = 'compact';

        // GET STUDY
        $api = \Drupal::service('rep.api_connector');
        $decoded_studyuri = base64_decode($studyuri);
        $study = $api->parseObjectResponse($api->getUri($decoded_studyuri), 'getUri');
        if (!$study) {
            $this->backUrl();
            return new JsonResponse(['error' => 'Study not found'], 404);
        } else {
            $this->setStudy($study);
        }

        // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
        $this->element_type = $elementtype;
        $this->setListSize(-1);
        if ($this->element_type != NULL) {
            $this->setListSize(ListManagerEmailPageByStudy::total($this->getStudy()->uri, $this->element_type, $this->manager_email));
        }
        if (gettype($this->list_size) == 'string') {
            $total_pages = "0";
        } else {
            if ($this->list_size % $pagesize == 0) {
                $total_pages = $this->list_size / $pagesize;
            } else {
                $total_pages = floor($this->list_size / $pagesize) + 1;
            }
        }

        // CREATE LINK FOR NEXT PAGE AND PREVIOUS PAGE
        if ($page < $total_pages) {
            $next_page = $page + 1;
            $next_page_link = ListManagerEmailPageByStudy::linkDA($this->getStudy()->uri, $this->element_type, $next_page, $pagesize);
        } else {
            $next_page_link = '';
        }
        if ($page > 1) {
            $previous_page = $page - 1;
            $previous_page_link = ListManagerEmailPageByStudy::linkDA($this->getStudy()->uri, $this->element_type, $previous_page, $pagesize);
        } else {
            $previous_page_link = '';
        }

        // RETRIEVE ELEMENTS
        $this->setList(ListManagerEmailPageByStudy::exec($this->getStudy()->uri, $this->element_type, $this->manager_email, $page, $pagesize));

        $this->single_class_name = "";
        $this->plural_class_name = "";
        switch ($this->element_type) {

                // ELEMENTS
            case "da":
                $this->single_class_name = "DA";
                $this->plural_class_name = "DAs";
                $header = MetadataTemplate::generateHeaderCompact();
                $output = MetadataTemplate::generateOutputCompact('da', $this->getList());
                break;
            default:
                $this->single_class_name = "Object of Unknown Type";
                $this->plural_class_name = "Objects of Unknown Types";
        }

        // Criar o JSON no formato desejado
        $data = [
            'headers' => array_values($header), // Valores dos headers (não as chaves)
            'output' => [],
            'pagination' => [
                'first' => $page > 1 ? ListManagerEmailPageByStudy::linkDA($this->getStudy()->uri, $this->element_type, 1, $pagesize) : null,
                'last' => $page < $total_pages ? ListManagerEmailPageByStudy::linkDA($this->getStudy()->uri, $this->element_type, $total_pages, $pagesize) : null,
                'previous' => $previous_page_link,
                'next' => $next_page_link,
                'last_page' => strval($total_pages),
                'page' => strval($page),
            ],
        ];

        // Processar o output e organizar os dados no formato desejado
        foreach ($output as $key => $values) {
            $row = [];
            foreach (array_keys($header) as $header_key) {
                $row[$header_key] = $values[$header_key] ?? '';
            }
            $data['output'][] = $row;
        }


        // Retorna os dados em JSON
        return new JsonResponse($data);
    }
    
    #UPDATE SESSION TABLE DA POSITION
    public function updateSessionPage(Request $request) {
        // Obter o valor da página enviado na requisição POST
        $page = $request->get('page');
        if (is_numeric($page)) {
            // Atualizar a sessão com o número da página
            $session = \Drupal::service('session');
            $session->set('current_page', $page);
    
            return new JsonResponse(['status' => 'success', 'page' => $page]);
        }
    
        // Retornar erro se o valor não for válido
        return new JsonResponse(['status' => 'error', 'message' => 'Invalid page'], 400);
    }
    

    
    public function backUrl()
    {
        $uid = \Drupal::currentUser()->id();
        $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.select_element_bystudy');
        if ($previousUrl) {
            $response = new RedirectResponse($previousUrl);
            $response->send();
            return;
        }
    }
}
