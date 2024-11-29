<?php

namespace Drupal\std\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\rep\ListManagerEmailPageByStudy;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Constant;
use Drupal\Core\File\FileSystemInterface;

class JsonDataController extends ControllerBase
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

        // GET SESSION
        $session = \Drupal::service('session');
        $page_from_session = $session->get('da_current_page', 1);

        // USE URL OU SESSION VALUE AS FALLBACK
        $page = $page ?: $page_from_session;

        // SAVE DA_PAGE ON SESSION
        $session->set('da_current_page', $page);

        // CHECK VALID `$page`
        if (!is_numeric($page) || $page < 1) {
            $page = 1;
        }

        // VALIDATION
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

        //AVOID NON EXISTING PAGES
        if ($this->list_size <= (($page - 1) * 5)) {
            $page--;
            $total_pages--;
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

        // BUILD JSON OUTPUT
        $data = [
            'headers' => array_values($header),
            'output' => [],
            'pagination' => [
                'first' => $page > 1 ? ListManagerEmailPageByStudy::linkDA($this->getStudy()->uri, $this->element_type, 1, $pagesize) : null,
                'last' => $page < $total_pages ? ListManagerEmailPageByStudy::linkDA($this->getStudy()->uri, $this->element_type, $total_pages, $pagesize) : null,
                'previous' => $previous_page_link,
                'next' => $next_page_link,
                'last_page' => strval($total_pages),
                'page' => strval($page),
                'items' => strval($this->getListSize()),
            ],
        ];

        // PROCESS OUTPUT ON NEEDED FORMAT
        foreach ($output as $key => $values) {
            $row = [];
            foreach (array_keys($header) as $header_key) {
                $row[$header_key] = $values[$header_key] ?? '';
            }
            $data['output'][] = $row;
        }


        // RETURN JSON
        return new JsonResponse($data);
    }

    #UPDATE SESSION TABLE DA POSITION
    public function updateSessionPage(Request $request)
    {
        $session = \Drupal::service('session');

        $page = $request->get('page');
        $elementtype = $request->get('element_type');

        if (is_numeric($page)) {

            $session->set('da_current_page', 1);
            $session->set('pub_current_page', 1);

            switch ($elementtype) {
                case 'publications':
                    $session->set('pub_current_page', $page);
                    break;

                case 'da':
                default:
                    $session->set('da_current_page', $page);
                    break;
            }

            return new JsonResponse(['status' => 'success', 'page' => $page]);
        }

        return new JsonResponse(['status' => 'error', 'message' => 'Invalid page'], 400);
    }

    // ADD STUDY FORM
    public function renderAddDAForm($elementtype = 'da', $studyuri = NULL)
    {
        if ($studyuri === NULL) {
            // Retorne uma mensagem de erro em JSON.
            return new JsonResponse(['status' => 'error', 'message' => t('The study URI is missing.')], 400);
        }

        // Renderizar o formulário usando o formBuilder.
        $form = \Drupal::formBuilder()->getForm('Drupal\rep\Form\AddMTForm', $elementtype, $studyuri);

        // Use o serviço de renderização para gerar o HTML do formulário.
        $rendered_form = \Drupal::service('renderer')->renderPlain($form);

        // SET USER ID AND PREVIOUS URL FOR TRACKING STORE URLS
        $uid = \Drupal::currentUser()->id();
        $previousUrl = \Drupal::request()->getRequestUri();
        Utils::trackingStoreUrls($uid, $previousUrl, 'rep.add_mt');

        // Retorne o formulário renderizado como uma resposta HTML.
        return new JsonResponse([
            'status' => 'success',
            'form' => $rendered_form,
        ]);
    }

    public function upload(Request $request, $field_name, $studyuri = NULL)
    {
        try {

            $uri = basename(base64_decode($studyuri));
            // Captura o arquivo enviado.
            $uploaded_files = $request->files->all();
            $uploaded_file = $uploaded_files['files'][$field_name] ?? null;

            // Verifica se o arquivo foi enviado e está em estado válido.
            if (!$uploaded_file || !$uploaded_file instanceof UploadedFile || $uploaded_file->getError() !== UPLOAD_ERR_OK) {
                \Drupal::logger('std')->error('No file uploaded or invalid file structure. Field: @field, Files: @files', [
                    '@field' => $field_name,
                    '@files' => print_r($uploaded_files, TRUE),
                ]);
                return new JsonResponse(['error' => 'No file uploaded or upload error.'], 400);
            }

            // Obtem a extensão do arquivo.
            $extension = strtolower($uploaded_file->getClientOriginalExtension());

            // Determina a pasta de destino com base na extensão.
            $folder = match ($extension) {
                'csv', 'xlsx' => 'da',
                'pdf', 'docx' => 'publications',
                'jpg', 'jpeg', 'png', 'mp4', 'avi' => 'media',
                default => null,
            };

            if (!$folder) {
                \Drupal::logger('std')->error('Unsupported file type: @type', [
                    '@type' => $extension,
                ]);
                return new JsonResponse(['error' => 'Unsupported file type.'], 400);
            }

            // Prepara o diretório de destino.
            $file_system = \Drupal::service('file_system');
            $directory = 'private://std/' . $uri . '/' . $folder . '/';
            $file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

            // Define o caminho final do arquivo.
            $destination = $directory . $uploaded_file->getClientOriginalName();

            // Move o arquivo da pasta temporária para o destino final.
            $uploaded_file->move($file_system->realpath($directory), $uploaded_file->getClientOriginalName());

            // Cria a entidade de arquivo no Drupal.
            $file = File::create([
                'uri' => $destination,
                'status' => 1, // Define como permanente.
            ]);
            $file->save();

            // Se o tipo for "da", adiciona a lógica adicional.
            if ($folder === 'da') {
                $this->processDAFile($file, $uploaded_file->getClientOriginalName(), $studyuri);
            }

            // Log de sucesso.
            \Drupal::logger('std')->info('File uploaded successfully to @destination', [
                '@destination' => $destination,
            ]);

            \Drupal::messenger("DA File uploaded with success");

            return new JsonResponse([
                'status' => 'success',
                'fid' => $file->id(),
                'uri' => $file->getFileUri(),
            ]);
        } catch (\Exception $e) {
            \Drupal::logger('std')->error('Exception occurred: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function processDAFile(File $file, string $filename, $studyuri)
    {
        try {
            $useremail = \Drupal::currentUser()->getEmail();

            // Dados do arquivo.
            $fileId = $file->id();

            // Adiciona o serviço `FusekiAPIConnector`.
            $api = \Drupal::service('rep.api_connector');

            // DATAFILE JSON
            $newDataFileUri = Utils::uriGen('datafile');
            $datafileJSON = '{"uri":"' . $newDataFileUri . '",' .
                '"typeUri":"' . HASCO::DATAFILE . '",' .
                '"hascoTypeUri":"' . HASCO::DATAFILE . '",' .
                '"label":"' . $filename . '",' .
                '"filename":"' . $filename . '",' .
                '"id":"' . $fileId[0] . '",' .
                '"fileStatus":"' . Constant::FILE_STATUS_UNPROCESSED . '",' .
                '"hasSIRManagerEmail":"' . $useremail . '"}';

            // MT JSON
            $newMTUri = str_replace("DFL", Utils::elementPrefix('da'), $newDataFileUri);
            $mtJSON = '{"uri":"' . $newMTUri . '",' .
                '"typeUri":"' . HASCO::DATA_ACQUISITION . '",' .
                '"hascoTypeUri":"' . HASCO::DATA_ACQUISITION . '",' .
                '"isMemberOfUri":"' . base64_decode($studyuri) . '",' .
                '"label":"' . $filename . '",' .
                '"hasDataFileUri":"' . $newDataFileUri . '",' .
                '"hasVersion":"",' .
                '"comment":"",' .
                '"hasSIRManagerEmail":"' . $useremail . '"}';

            // dpm($datafileJSON);
            // dpm($mtJSON);

            // Adiciona o `DataFile`.
            $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');

            // ADD MT
            $msg2 = $api->parseObjectResponse($api->elementAdd('da', $mtJSON), 'elementAdd');

            if ($msg1 && $msg2) {
                \Drupal::logger('std')->info('DA file processed successfully.');
            } else {
                \Drupal::logger('std')->error('Error processing DA file.');
            }
        } catch (\Exception $e) {
            \Drupal::logger('std')->error('Error processing DA file: @message', ['@message' => $e->getMessage()]);
        }
    }

    public function checkFileName($studyuri, $fileNameWithoutExtension)
    {
        try {
            // Decodifica a URI do estudo
            $decodedStudyUri = basename(base64_decode($studyuri));

            // Define os diretórios com base no tipo do arquivo
            $basePath = 'private://std/' . $decodedStudyUri . '/';
            $directories = [
                'csv' => 'da/',
                'xlsx' => 'da/',
                'pdf' => 'publications/',
                'doc' => 'publications/',
                'docx' => 'publications/',
                'jpg' => 'media/',
                'jpeg' => 'media/',
                'png' => 'media/',
                'mov' => 'media/',
                'avi' => 'media/',
                'mpeg' => 'media/',
                'mp4' => 'media/',
                'mp3' => 'media/',
            ];

            // Divida o nome do arquivo base e a extensão
            $parts = pathinfo($fileNameWithoutExtension);
            $fileBaseName = $parts['filename']; // Parte sem a extensão
            $extension = strtolower($parts['extension'] ?? '');

            if (!isset($directories[$extension])) {
                return new JsonResponse([
                    'error' => 'Invalid file type: ' . $extension,
                ], 400);
            }

            // Define o diretório baseado na extensão
            $directoryPath = $basePath . $directories[$extension];

            // Verifica ou cria o diretório
            $fileSystem = \Drupal::service('file_system');
            if (!$fileSystem->prepareDirectory($directoryPath, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
                return new JsonResponse([
                    'error' => 'Unable to prepare directory for checking files',
                ], 500);
            }

            // Lógica para verificar a existência de arquivos
            $suggestedFileName = $fileBaseName;
            $counter = 0;

            do {
                $filePath = $directoryPath . $suggestedFileName . '.' . $extension;
                $realFilePath = $fileSystem->realpath($filePath);
                if (file_exists($realFilePath)) {
                    $counter++;
                    $suggestedFileName = $fileBaseName . '_' . $counter;
                } else {
                    break;
                }
            } while (true);

            return new JsonResponse([
                'suggestedFileName' => $suggestedFileName,
            ]);
        } catch (\Exception $e) {
            \Drupal::logger('std')->error('Error checking file name: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse([
                'error' => 'Error checking file name',
            ], 500);
        }
    }

    /*
    ** PUBLICATIONS TABLE RELATED FUNCTIONS
    */
    public function getPublicationsFiles($studyuri = null, $page = 1, $pagesize = 5)
    {
        // GET SESSION
        $session = \Drupal::service('session');
        $page_from_session = $session->get('pub_current_page', 1);

        // USE URL OU SESSION VALUE AS FALLBACK
        $page = $page ?: $page_from_session;

        // SAVE DA_PAGE ON SESSION
        $session->set('pub_current_page', $page);

        // CHECK VALID `$page`
        if (!is_numeric($page) || $page < 1) {
            $page = 1;
        }

        // VALIDATION
        if (empty($studyuri) || !is_numeric($page) || !is_numeric($pagesize)) {
            return new JsonResponse(['error' => 'Invalid parameters'], 400);
        }


        $decoded_studyuri = basename(base64_decode($studyuri));
        $directory = 'private://std/' . $decoded_studyuri . '/Publications/';
        $file_system = \Drupal::service('file_system');

        if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
            return new JsonResponse(['error' => 'Could not access or prepare directory.'], 500);
        }

        $all_files = scandir($file_system->realpath($directory));
        $filtered_files = array_filter($all_files, function ($file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($extension, ['pdf', 'docx']);
        });

        $total_files = count($filtered_files);
        $offset = ($page - 1) * $pagesize;
        $paginated_files = array_slice($filtered_files, $offset, $pagesize);

        $files = [];
        foreach ($paginated_files as $file) {
            $files[] = [
                'filename' => $file,
                'view_url' => '/file-view-path/' . $file,
                'delete_url' => '/delete-publication-file/' . $file . '/' . $studyuri,
            ];
        }

        return new JsonResponse([
            'files' => $files,
            'pagination' => [
                'current_page' => $page,
                'page_size' => $pagesize,
                'total_files' => $total_files,
                'total_pages' => ceil($total_files / $pagesize),
            ],
        ]);
    }

    /*
    ** DELETE PUBLICATION
    */
    public function deletePublicationFile($filename, $studyuri)
    {
        // Decodifica o URI do estudo e constrói o caminho do arquivo
        $decoded_studyuri = basename(base64_decode($studyuri));
        $directory = 'private://std/' . $decoded_studyuri . '/Publications/';
        $file_path = $directory . $filename;

        try {
            // Obtém o sistema de arquivos do Drupal
            $file_system = \Drupal::service('file_system');
            $real_path = $file_system->realpath($file_path);

            // Verifica se o arquivo existe
            if (file_exists($real_path)) {
                // Remove o arquivo
                unlink($real_path);

                // Obter total de arquivos restantes
                if (is_dir($real_path)) {
                    $files = array_filter(scandir($real_path), function ($file) use ($real_path) {
                        return !is_dir($real_path . '/' . $file);
                    });
                    $totalFiles = count($files);
                    $pageSize = 5; // Número de itens por página
                    $lastPage = ceil($totalFiles / $pageSize);
                } else {
                    $totalFiles = 0;
                    $lastPage = 1; // Se não houver arquivos, a última página será 1
                }                

                // Retorna uma resposta JSON de sucesso
                return new JsonResponse([
                    'status' => 'success',
                    'message' => 'File deleted successfully.',
                    'file' => $filename,
                    'total_files' => $totalFiles,
                    'last_page' => $lastPage,
                ]);
            } else {
                // Retorna um erro de arquivo não encontrado
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'File not found.',
                    'file' => $filename,
                ], 404);
            }
        } catch (\Exception $e) {
            // Registra o erro e retorna uma resposta JSON de erro
            \Drupal::logger('std')->error('Error deleting file: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Error deleting file.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    # TO BE CHECKED IF NEEDED
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
