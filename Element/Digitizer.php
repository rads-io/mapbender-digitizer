<?php

namespace Mapbender\DigitizerBundle\Element;

use Doctrine\DBAL\DBALException;
use Mapbender\DataSourceBundle\Component\DataStore;
use Mapbender\DataSourceBundle\Component\DataStoreService;
use Mapbender\DataSourceBundle\Component\FeatureType;
use Mapbender\DataSourceBundle\Element\BaseElement;
use Mapbender\DataSourceBundle\Entity\Feature;
use Mapbender\DigitizerBundle\Component\Uploader;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 *
 */
class Digitizer extends BaseElement
{
    protected static $title                = "Digitizer";
    protected static $description          = "Georeferencing and Digitizing";


    /**
     * @inheritdoc
     */
    static public function listAssets()
    {
        return array('js'    => array(
                        '../../vendor/blueimp/jquery-file-upload/js/jquery.fileupload.js',
                        '../../vendor/blueimp/jquery-file-upload/js/jquery.iframe-transport.js',
                        "/components/jquery-context-menu/jquery-context-menu-built.js",
                        //'/components/bootstrap-colorpicker/js/bootstrap-colorpicker.min.js',
                        //'@MapbenderSearchBundle/Resources/public/feature-style-editor.js',
                        'mapbender.element.digitizer.js'
        ),
                     'css'   => array(
                         'sass/element/context-menu.scss',
                         'sass/element/digitizer.scss',
                     ),
                     'trans' => array('MapbenderDigitizerBundle:Element:digitizer.json.twig'));
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultConfiguration()
    {
        return array(
            "target" => null
        );
    }

    /**
     * @param Request $request
     * @param string[]|string $allowedMethods
     * @throws BadRequestHttpException
     *
     */
    protected static function requireMethod($request, $allowedMethods)
    {
        if (!is_array($allowedMethods)) {
            $allowedMethods = explode(',', strtoupper($allowedMethods));
        } else {
            $allowedMethods = array_map('strtoupper', $allowedMethods);
        }
        $method = $request->getMethod();
        if (!in_array($method, $allowedMethods)) {
            throw new BadRequestHttpException("Unsupported method $method");
        }
    }

    /**
     * @return mixed[]
     */
    protected function getFeatureTypeDeclarations()
    {
        return $this->container->getParameter('featureTypes');
    }

    /**
     * Prepare form items for each scheme definition
     * Optional: get featureType by name from global context.
     *
     * @inheritdoc
     */
    public function getConfiguration()
    {
        $configuration            = parent::getConfiguration();
        $configuration['debug']   = isset($configuration['debug']) ? $configuration['debug'] : false;
        $configuration['fileUri'] = $this->container->getParameter("mapbender.uploads_dir") . "/" . FeatureType::UPLOAD_DIR_NAME;
        $featureTypes              = $this->getFeatureTypeDeclarations();

        if (isset($configuration["schemes"]) && is_array($configuration["schemes"])) {
            foreach ($configuration["schemes"] as $key => &$scheme) {
                if (is_string($scheme['featureType'])) {
                    $featureTypeName           = $scheme['featureType'];
                    $scheme['featureType']     = $featureTypes[ $featureTypeName ];
                    $scheme['featureTypeName'] = $featureTypeName;
                }
                if (isset($scheme['formItems'])) {
                    $preparedItems = $this->prepareItems($scheme['formItems']);
                    /**
                     * Strip 'breakLine' and 'text' type items, we do this via theme.js now
                     * @todo: perform this stripping in entity so the values disappear from the backend
                     */
                    $scheme['formItems'] = array();
                    foreach ($preparedItems as $preparedItem) {
                        if (empty($preparedItem['type']) || !in_array($preparedItem['type'], array('breakLine', 'text'))) {
                            $scheme['formItems'][] = $preparedItem;
                        }
                    }
                }
                /**
                 * Also nuke `tableFields`, also controlled via theme.js
                 * @todo: perform this stripping in entity so the values disappear from the backend
                 */
                unset($scheme['tableFields']);
                $scheme['featureStyles'] = array();
            }
        }

        return $configuration;
    }

    /**
     * Prepare request feautre data by the form definition
     *
     * @param $feature
     * @param $formItems
     * @return array
     */
    protected function prepareQueriedFeatureData($feature, $formItems)
    {
        foreach ($formItems as $key => $formItem) {
            if (isset($formItem['children'])) {
                $feature = array_merge($feature, $this->prepareQueriedFeatureData($feature, $formItem['children']));
            } elseif (isset($formItem['type']) && isset($formItem['name'])) {
                switch ($formItem['type']) {
                    case 'select':
                        if (isset($formItem['multiple'])) {
                            $separator                  = isset($formItem['separator']) ? $formItem['separator'] : ',';
                            if(is_array($feature["properties"][$formItem['name']])){
                                $feature["properties"][$formItem['name']] = implode($separator, $feature["properties"][$formItem['name']]);
                            }
                        }
                        break;
                }
            }
        }
        return $feature;
    }

    /**
     * @inheritdoc
     */
    public function httpAction($action)
    {
        /** @var $request Request */
        $configuration   = $this->getConfiguration();
        $request         = $this->container->get('request');
        $queryParams     = $request->query->all();
        $postData        = json_decode($request->getContent(), true);
        $schemas         = $configuration["schemes"];
        $debugMode       = $configuration['debug'] || $this->container->get('kernel')->getEnvironment() == "dev";
        $schemaName      = isset($postData["schema"]) ? $postData["schema"] : $request->get("schema");
        $defaultCriteria = array('returnType' => 'FeatureCollection',
                                 'maxResults' => 2500);
        if (empty($schemaName)) {
            throw new Exception('For initialization there is no name of the declared scheme');
        }

        $schema = $schemas[$schemaName];

        if (is_array($schema['featureType'])) {
            $featureType = new FeatureType($this->container, $schema['featureType']);
        } else {
            throw new Exception("FeatureType settings not correct");
        }

        $results = array();

        switch ($action) {
            case 'select':
                $results = $this->selectFeatures($request, $schemas, $queryParams, $defaultCriteria, $featureType);
                break;

            case 'save':
                $results = $this->saveFeatures($request, $postData, $featureType, $schema, $debugMode);
                break;

            case 'delete':
                $results = $this->deleteFeature($featureType, $postData);
                break;

            case 'file-upload':
                $results = $this->fileUpload($request, $schemaName, $featureType);
                break;

            case 'datastore/get':
                $dataStore = $this->getDataStoreById($postData['id']);
                if (!$dataStore) {
                    throw new NotFoundHttpException("No such datastore");
                }
                $entity = $dataStore->get($postData['dataItemId']);
                if (!$entity) {
                    throw new NotFoundHttpException("No such entity");
                }
                $results = $entity->toArray();
                break;

            case 'datastore/save':
                $results = $this->saveDataStore($postData);
                break;

            case 'datastore/remove':
                $this->removeDataStore($postData);
                break;

            default:
                $results = array(
                    array('errors' => array(
                        array('message' => $action . " not defined!")
                    ))
                );
        }

        return new JsonResponse($results);
    }

    /**
     * Select features
     *
     * @param $request
     * @param $schemas
     * @param $queryParams
     * @param $defaultCriteria
     * @param FeatureType $featureType
     * @return mixed
     */
    protected function selectFeatures($request, $schemas, $queryParams, $defaultCriteria, $featureType)
    {
        $this->requireMethod($request, 'GET');
        $results = $featureType->search(array_merge($defaultCriteria, $queryParams));
        return $results;
    }

    /**
     * Save features
     *
     * @param $request
     * @param $postData
     * @param FeatureType $featureType
     * @param $schema
     * @param $debugMode
     * @return array
     */
    protected function saveFeatures($request, $postData, $featureType, $schema, $debugMode)
    {
        // save once
        $this->requireMethod($request, 'POST');
        if (isset($postData['feature'])) {
            $postData['features'] = array($postData['feature']);
        }

        // save once
        if (isset($postData['style'])) {
            $postData['features'] = array($postData['feature']);
        }

        $connection = $featureType->getDriver()->getConnection();

        $results = array();
        try {
            // save collection
            if (!empty($postData['features']) && is_array($postData['features'])) {
                foreach ($postData['features'] as $feature) {
                    /**
                     * @var $feature Feature
                     */
                    $featureData = $this->prepareQueriedFeatureData($feature, $schema['formItems']);

                    foreach ($featureType->getFileInfo() as $fileConfig) {
                        if (!isset($fileConfig['field']) || !isset($featureData["properties"][$fileConfig['field']])) {
                            continue;
                        }
                        $url                                             = $featureType->getFileUrl($fileConfig['field']);
                        $requestUrl                                      = $featureData["properties"][$fileConfig['field']];
                        $newUrl                                          = str_replace($url . "/", "", $requestUrl);
                        $featureData["properties"][$fileConfig['field']] = $newUrl;
                    }

                    $feature = $featureType->save($featureData);

                    $results = array_merge($featureType->search(array(
                        'srid'  => $feature->getSrid(),
                        'where' => $connection->quoteIdentifier($featureType->getUniqueId()) . '=' . $connection->quote($feature->getId()))));
                }
            }
            $results = $featureType->toFeatureCollection($results);
        } catch (DBALException $e) {
            $message = $debugMode ? $e->getMessage() : "Feature can't be saved. Maybe something is wrong configured or your database isn't available?\n" .
                "For more information have a look at the webserver log file. \n Error code: " .$e->getCode();
            $results = array('errors' => array(
                array('message' => $message, 'code' => $e->getCode())
            ));
        }

        return $results;
    }

    /**
     * Delete feature
     *
     * @param FeatureType $featureType
     * @param $postData
     * @return mixed
     */
    protected function deleteFeature($featureType, $postData)
    {
        return $featureType->remove($postData['feature']);
    }

    /**
     * File upload
     *
     * @param Request $request
     * @param $schemaName
     * @param FeatureType $featureType
     * @return array
     */
    protected function fileUpload($request, $schemaName, $featureType)
    {
        $fieldName     = $request->get('field');
        $urlParameters = array('schema' => $schemaName,
            'fid'    => $request->get('fid'),
            'field'  => $fieldName);
        $serverUrl     = preg_replace('/\\?.+$/', "", $_SERVER["REQUEST_URI"]) . "?" . http_build_query($urlParameters);
        $uploadDir     = $featureType->getFilePath($fieldName);
        $uploadUrl = $featureType->getFileUrl($fieldName) . "/";
        $urlParameters['uploadUrl'] = $uploadUrl;
        $uploadHandler = new Uploader(array(
            'upload_dir'                   => $uploadDir . "/",
            'script_url'                   => $serverUrl,
            'upload_url'                   => $uploadUrl,
            'accept_file_types'            => '/\.(gif|jpe?g|png)$/i',
            'print_response'               => false,
            'access_control_allow_methods' => array(
                'OPTIONS',
                'HEAD',
                'GET',
                'POST',
                'PUT',
                'PATCH',
            ),
        ));

        return array_merge($uploadHandler->get_response(), $urlParameters);
    }

    /**
     * Get Data Store
     *
     * @param $postData
     * @return array|null
     */
    protected function getDataStore($postData)
    {
        $id           = $postData['id'];
        $dataItemId   = $postData['dataItemId'];
        $dataStore    = $this->getDataStoreById($id);
        $dataItem     = $dataStore->get($dataItemId);
        $dataItemData = null;
        if ($dataItem) {
            $dataItemData = $dataItem->toArray();
        }

        $results = $dataItemData;

        return $results;
    }

    /**
     * Save Data Store
     *
     * @param $postData
     * @return mixed
     */
    protected function saveDataStore($postData)
    {
        $id          = $postData['id'];
        $dataItem    = $postData['dataItem'];
        $dataStore   = $this->getDataStoreById($id);
        $uniqueIdKey = $dataStore->getDriver()->getUniqueId();
        if (empty($postData['dataItem'][ $uniqueIdKey ])) {
            unset($postData['dataItem'][ $uniqueIdKey ]);
        }
        return $dataStore->save($dataItem);
    }

    /**
     * Remove Data Store
     *
     * @param $postData
     */
    protected function removeDataStore($postData)
    {
        $id          = $postData['id'];
        $dataStore   = $this->getDataStoreById($id);
        $uniqueIdKey = $dataStore->getDriver()->getUniqueId();
        $dataItemId  = $postData['dataItem'][ $uniqueIdKey ];
        $dataStore->remove($dataItemId);
    }

    /**
     * @param string $id
     * @return DataStore
     */
    protected function getDataStoreById($id)
    {
        /** @var DataStoreService $dataStoreService */
        $dataStoreService = $this->container->get('data.source');
        return $dataStoreService->get($id);
    }
}
