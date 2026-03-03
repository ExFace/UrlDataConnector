<?php
namespace exface\UrlDataConnector\ModelBuilders;

use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Interfaces\DataSources\ModelBuilderInterface;
use exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\UrlDataConnector\DataConnectors\GraphQLConnector;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\HexadecimalNumberDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\UrlDataConnector\Actions\CallWebService;
use exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder;

/**
 * Creates metamodels from Swagger/OpenAPI descriptions.
 * 
 * @method GraphQLConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class SwaggerModelBuilder extends AbstractModelBuilder implements ModelBuilderInterface {
    
    private $swagger = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $transaction);
        
        // Generate relations
        $this->generateRelations($meta_object, $created_ds, $transaction);
        
        // TODO Generate queries for object
        // $this->generateActions($meta_object, $transaction);
        
        $transaction->commit();
        
        return $created_ds;
    }
    
    /**
     * Generates the attributes for a given meta object and saves them in the model.
     * 
     * @param MetaObjectInterface $meta_object
     * @param DataTransactionInterface $transaction
     * 
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function generateAttributes(MetaObjectInterface $meta_object, DataTransactionInterface $transaction = null)
    {
        $created_ds = DataSheetFactory::createFromObjectIdOrAlias($meta_object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $created_ds->setAutoCount(false);
        
        $objectDef = $this->getSwaggerDefinition($meta_object);
        if ($objectDef === null) {
            return $created_ds;
        }
        
        $imported_rows = $this->getAttributeData($objectDef, $meta_object)->getRows();
        foreach ($imported_rows as $row) {
            if (count($meta_object->findAttributesByDataAddress($row['DATA_ADDRESS'])) === 0) {
                $created_ds->addRow($row);
            }
        }
        $created_ds->setCounterForRowsInDataSource(count($imported_rows));
        
        if (! $created_ds->isEmpty()) {
            $created_ds->dataCreate(false, $transaction);
            // Reload object model and recreate the data sheet, so it is based on the refreshed object
            $refreshed_object = $meta_object->getWorkbench()->model()->reloadObject($meta_object);
            $uxon = $created_ds->exportUxonObject();
            $created_ds = DataSheetFactory::createFromObject($refreshed_object);
            $created_ds->importUxonObject($uxon);
        }
        
        return $created_ds;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\ModelBuilderInterface::generateObjectsForDataSource()
     */
    public function generateObjectsForDataSource(AppInterface $app, DataSourceInterface $source, string $data_address_mask = null) : DataSheetInterface
    {
        $existing_objects = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $existing_objects->getColumns()->addMultiple(['DATA_ADDRESS', 'ALIAS']);
        $existing_objects->getFilters()->addConditionFromString('APP', $app->getUid(), EXF_COMPARATOR_EQUALS);
        $existing_objects->dataRead();
        
        $new_objects = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        
        $transaction = $app->getWorkbench()->data()->startTransaction();
        
        $imported_rows = $this->getObjectData($data_address_mask, $app, $source)->getRows();
        $existingAliasCol = $existing_objects->getColumns()->getByExpression('ALIAS');
        foreach ($imported_rows as $row) {
            if ($existingAliasCol->findRowByValue($row['ALIAS']) === false) {
                $new_objects->addRow($row);
            } 
        }
        $new_objects->setCounterForRowsInDataSource(count($imported_rows));
        
        if (! $new_objects->isEmpty()) {
            $new_objects->dataCreate(false, $transaction);
            // Generate attributes for each object
            foreach ($new_objects->getRows() as $row) {
                $object = $app->getWorkbench()->model()->getObjectByAlias($row['ALIAS'], $app->getAliasWithNamespace());
                $this->generateAttributes($object, $transaction);
                
                // TODO Generate Actions for object
                // $this->generateActions($object, $transaction);
            }
            // After all attributes are there, generate relations. It must be done after all new objects have
            // attributes as relations need attribute UIDs on both sides!
            foreach($new_objects as $data) {
                list($object, $attributes) = $data;
                $this->generateRelations($object, $attributes, $transaction);
            }
            
        }
        
        $transaction->commit();
        
        return $new_objects;
    }

    /**
     * Create action models for swagger paths.
     * 
     * @param MetaObjectInterface $object
     * @param DataTransactionInterface $transaction
     * @return DataSheetInterface
     */
    protected function generateActions(MetaObjectInterface $object, DataTransactionInterface $transaction) : DataSheetInterface
    {
        $newActions = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
        $newActions->setAutoCount(false);
        $skipped = 0;
        
        foreach ($this->getSwaggerPaths() as $url => $pathDef) {
            
            // Read action parameters
            $parameters = [];
            foreach ($pathDef['parameters'] as $paramDef) {
                $pType = $this->guessDataType($object, $paramDef['type']);
                $parameter = [
                    'name' => $paramDef['name'],
                    'required' => $paramDef['required'] ? 1 : 0,
                    'data_type' => [
                        'alias' => $pType->getAliasWithNamespace()
                    ],
                    'custom_properties' => [
                        "swagger_format" => $paramDef['format']
                    ]
                ];
                
                $pTypeOptions = $this->getDataTypeConfig($pType, $paramDef['type']['ofType'] ?? []);
                if (! $pTypeOptions->isEmpty()) {
                    $parameter['data_type'] = array_merge($parameter['data_type'], $pTypeOptions->toArray());
                }
                $parameters[] = $parameter;
            }
            
            // See if action alread exists in the model
            $existingAction = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
            $existingAction->getFilters()->addConditionFromString('APP', $object->getApp()->getUid(), EXF_COMPARATOR_EQUALS);
            $existingAction->getFilters()->addConditionFromString('ALIAS', $pathDef['opertationId'], EXF_COMPARATOR_EQUALS);
            $existingAction->getColumns()->addFromSystemAttributes()->addFromExpression('CONFIG_UXON');
            $existingAction->dataRead();
            
            // If it does not exist, create it. Otherwise update the parameters only (because they really MUST match the metadata)
            if ($existingAction->isEmpty()) {
                
                $actionConfig = new UxonObject([
                    'url' => $url,
                    'parameters' => $parameters
                ]);
                
                $resultType = $pathDef['type'];
                if ($resultType['kind'] === 'OBJECT' && $resultObjectAlias = $resultType['name']) {
                    $actionConfig->setProperty('result_object_alias', $object->getNamespace() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER . $resultObjectAlias);
                }
                
                $actionData = [
                    'ACTION_PROTOTYPE' => str_replace('\\', '/', CallWebService::class) . '.php',
                    'ALIAS' => $pathDef['opertationId'],
                    'APP' => $object->getApp()->getUid(),
                    'NAME' => $this->getNameFromAlias($pathDef['opertationId']),
                    'OBJECT' => $object->getId(),
                    'CONFIG_UXON' => $actionConfig->toJson(),
                    'SHORT_DESCRIPTION' => $pathDef['summary']
                ]; 
                
                // Add relation data to the data sheet: just those fields, that will mark the attribute as a relation
                $newActions->addRow($actionData);
            } else {
                $existingConfig = UxonObject::fromJson($existingAction->getCellValue('CONFIG_UXON', 0));
                $existingAction->setCellValue('CONFIG_UXON', 0, $existingConfig->setProperty('parameters', $parameters)->toJson());
                $existingAction->dataUpdate(false, $transaction);
                $skipped++;
            }
        }
        
        // Create all new actions
        if (! $newActions->isEmpty()) {
            $newActions->dataCreate(true, $transaction);
        }
        
        $newActions->setCounterForRowsInDataSource($newActions->countRows() + $skipped);
        
        return $newActions;
    }
    
    protected function getActionAliasFromType(string $typeName, string $operation) : string
    {
        return $typeName . ucfirst($operation);
    }
    
    protected function getNameFromAlias(string $alias) : string
    {
        return ucwords(str_replace('_', ' ', StringDataType::convertCasePascalToUnderscore($alias)));
    }
    
    /**
     * 
     * @param AppInterface $app
     * @param Crawler $associations
     * @param DataTransactionInterface $transaction
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function generateRelations(MetaObjectInterface $object, DataSheetInterface $attributeSheet, DataTransactionInterface $transaction = null)
    {        
        $objectDef = $this->getSwaggerDefinition($object);

        $found_relations = false;
        foreach ($attributeSheet->getRows() as $row) {
            $propName = $row['DATA_ADDRESS'];            
            if ($ref = $objectDef['properties'][$propName]['$ref']) {
                $refComponent = StringDataType::substringAfter($ref, '#/' . $this->getSwaggerDefinitionsPath() . '/');
                
                $ds = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT');
                $ds->getColumns()->addFromUidAttribute();
                $ds->getColumns()->addFromExpression('NAME');
                $ds->getFilters()->addConditionFromString('DATA_ADDRESS_PROPS', '"swagger_component":"' . $refComponent . '"', EXF_COMPARATOR_EQUALS);
                $ds->getFilters()->addConditionFromString('DATA_SOURCE', $object->getDataSource()->getId(), EXF_COMPARATOR_EQUALS);
                $ds->dataRead();
                
                if ($ds->countRows() === 1) {
                    $row['RELATED_OBJ'] = $ds->getUidColumn()->getValues()[0];
                    $row['ALIAS'] = $row['ALIAS'];
                    // If the attribute's name was autogenerated from it's data address, replace it with the label of
                    // the related object. This is much better in most cases.
                    if ($row['NAME'] === $this->generateLabel($propName)) {
                        $row['NAME'] = $ds->getColumns()->get('NAME')->getCellValue(0);
                    }
                    // If the foreign key is a required column, it's row must be deleted together with the
                    // row, the foreign key points to (otherwise we will get an orphan or a constraint violation)
                    if ($row['REQUIREDFLAG']) {
                        $row['DELETE_WITH_RELATED_OBJECT'] = 1;
                    }
                    $attributeSheet->addRow($row, true);
                    $found_relations = true;
                }
            }
        }
        
        if ($found_relations === true) {
            $attributeSheet->dataUpdate(false, $transaction);
        }
        
        return $attributeSheet;
    }
    
    /**
     * Returns a crawlable instance containing the entire metadata XML.
     * 
     * @return array
     */
    protected function getSwagger() : array
    {
        if (is_null($this->swagger)) {
            $query = new Psr7DataQuery(new Request('GET', $this->getDataConnection()->getSwaggerUrl(), ['Content-Type' => 'application/json']));
            $query = $this->getDataConnection()->query($query);
            $this->swagger = json_decode($query->getResponse()->getBody()->__toString(), true);
        }
        return $this->swagger;
    }
    
    /**
     * 
     * @return string
     */
    protected function getSwaggerVersion() : string
    {
        return $this->getSwagger()['swagger'] ?? $this->getSwagger()['openapi'];
    }
    
    /**
     *
     * @param MetaObjectInterface $object
     * @return array|NULL
     */
    protected function getSwaggerDefinition(MetaObjectInterface $object) : ?array
    {
        $name = $object->getDataAddressProperty('swagger_component') ? $object->getDataAddressProperty('swagger_component') : $object->getDataAddress();
        foreach ($this->getSwaggerDefinitions() as $key => $def) {
            if ($key === $name && $def['type'] === 'object') {
                return $def;
            }
        }
        return null;
    }
    
    protected function getSwaggerDefinitions() : array
    {
        $defs = ArrayDataType::filterXPath($this->getSwagger(), $this->getSwaggerDefinitionsPath());
        return $defs ?? [];
    }
    
    protected function getSwaggerDefinitionsPath() : string
    {
        switch (true) {
            case $this->getSwaggerVersion() < 3:
                return 'definitions';
            case StringDataType::startsWith($this->getSwaggerVersion(), '3.1'):
                return 'components/schemas';
            default:
                return 'components';
        }
    }
    
    protected function getSwaggerPaths() : array
    {
        return $this->getSwagger()[$this->getSwaggerPathsProperty()];
    }
    
    protected function getSwaggerPathsProperty() : string
    {
        return 'paths';
    }
    
    protected function getSwaggerPathToRead(string $definitionName) : ?array
    {
        $paths = [];
        foreach ($this->getSwaggerPaths() as $url => $path) {
            $method = 'get';
            if (! $operation = $path[$method]) {
                continue;
            }
            
            if (! $responses = $operation['responses']) {
                continue;
            }
            
            if (! $resp = $responses[200]) {
                continue;
            }
            
            if ($resp['schema'] 
                && $resp['schema']['type'] === 'array'
                && $resp['schema']['items'] 
                && $resp['schema']['items']['$ref'] === "#/{$this->getSwaggerDefinitionsPath()}/{$definitionName}"
            ) {
                $paths[] = array_merge(['path' => $url, 'method' => $method], $operation);
            }
        }
        
        // TODO find best suitable read-path if multiple paths return lists of this definition (max parameters???)
        
        return $paths[0];
    }
    
    protected function isSwagger(int $majorVersion) : bool
    {
        return substr($this->getSwaggerVersion(), 0, 1) == $majorVersion;
    }
    
    protected function getSwaggerPathToReadById(string $definitionName) : ?array
    {
        $paths = [];
        foreach ($this->getSwaggerPaths() as $url => $path) {
            $method = 'get';
            if (! $operation = $path[$method]) {
                continue;
            }
            
            if (! $responses = $operation['responses']) {
                continue;
            }
            
            if (! $resp = $responses[200]) {
                continue;
            }
            
            if ($resp['schema']
                && $resp['schema']['$ref'] === "#/{$this->getSwaggerDefinitionsPath()}/{$definitionName}"
            ) {
                $paths[] = array_merge(['path' => $url, 'method' => $method], $operation);;
            }
        }
        
        // TODO what if there are multiple paths, that return exactly one item?
        
        return $paths[0];
    }
    
    protected function getSwaggerPathToCreate(string $definitionName) : ?array
    {
        $paths = [];
        foreach ($this->getSwaggerPaths() as $url => $path) {
            $method = 'post';
            if (! $operation = $path[$method]) {
                $method = 'put';
                if (! $operation = $path[$method]) {
                    continue;
                }
            }
            
            if (! $parameters = $operation['parameters']) {
                continue;
            }
            
            if (count($parameters) > 1 || ! $param = $parameters[0]) {
                continue;
            }
            
            if ($param['schema']
                && $param['schema']['$ref'] === "#/{$this->getSwaggerDefinitionsPath()}/{$definitionName}"
            ) {
                $paths[] = array_merge(['path' => $url, 'method' => $method], $operation);
            }
        }
        
        // TODO what if there are multiple paths, that return exactly one item?
        
        return $paths[0];
    }
    
    protected function getSwaggerPathToUpdate(string $definitionName, string $basePath = null, string $uidField = null) : ?array
    {
        $paths = [];
        $allPaths = $this->getSwaggerPaths();
        foreach ($allPaths as $url => $path) {
            $method = 'put';
            if (! $operation = $path[$method]) {
                $method = 'patch';
                if (! $operation = $path[$method]) {
                    $method = 'post';
                    if (! $operation = $path[$method]) {
                        continue;
                    }
                }
            }
            
            if (! $parameters = $operation['parameters']) {
                continue;
            }
            
            if (count($parameters) > 2) {
                continue;
            }
            
            foreach ($parameters as $param) {
                if ($param['schema'] && $param['schema']['$ref'] === "#/{$this->getSwaggerDefinitionsPath()}/{$definitionName}") {
                    $uidParamFound = false;
                    foreach ($parameters as $p) {
                        if ($p['name'] === $uidField) {
                            $uidParamFound = true;
                            break;
                        }
                    }
                    // If the path also has an explicit parameter for the UID field, it is more likely to be the update path!
                    if ($uidParamFound === true) {
                        array_unshift($paths, array_merge(['path' => $url, 'method' => $method], $operation));
                    } else {
                        $paths[] = array_merge(['path' => $url, 'method' => $method], $operation);
                    }
                } elseif ($basePath !== null && StringDataType::startsWith($url, $basePath) && $uidField !== null && $param['name'] === $uidField) {
                    $paths[] = array_merge(['path' => $url, 'method' => $method], $operation);;
                } else {
                    // If there are other parameters, don't use this path
                    continue 2;
                }
            }
        }
        
        // TODO what if there are multiple paths, that return exactly one item?
        
        return $paths[0];
    }
    
    protected function getSwaggerPathToDelete(string $definitionName, string $basePath = null, string $uidField = null) : ?array
    {
        $paths = [];
        foreach ($this->getSwaggerPaths() as $url => $path) {
            $method = 'delete';
            if (! $operation = $path[$method]) {
                continue;
            }
            
            if (! $parameters = $operation['parameters']) {
                continue;
            }
            
            if (count($parameters) > 1 || ! $param = $parameters[0]) {
                continue;
            }
            
            if (($basePath !== null && StringDataType::startsWith($url, $basePath) && $uidField !== null && $param['name'] === $uidField)
                || ($param['schema'] && $param['schema']['$ref'] === "#/{$this->getSwaggerDefinitionsPath()}/{$definitionName}")
            ) {
                $paths[] = array_merge(['path' => $url, 'method' => $method], $operation);;
            }
        }
        
        // TODO what if there are multiple paths, that return exactly one item?
        
        return $paths[0];
    }
    
    /**
     * Returns a data sheet of exface.Core.OBJECT created from the given EntityTypes.
     * 
     * @param Crawler $entity_nodes
     * @param AppInterface $app
     * @param DataSourceInterface $data_source
     * @return DataSheetInterface
     */
    protected function getObjectData($namePattern, AppInterface $app, DataSourceInterface $data_source) 
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $ds_uid = $data_source->getId();
        $app_uid = $app->getUid();
        
        foreach ($this->getSwaggerDefinitions() as $key => $def) {
            
            // Check name pattern against definition name and future data address
            $readPath = $this->getSwaggerPathToRead($key) ?? [];
            if ($namePattern) {
                if (preg_match($namePattern, $key) !== 1) {
                    if (! $readPath['path'] || preg_match($namePattern, $readPath['path']) !== 1) {
                        continue;
                    }
                }
            }
            
            $props = new UxonObject([
                'swagger_component' => $key
            ]);
            
            if ($path = $readPath['path']) {
                $dataAddress = $this->getDataAddressFromPath($path);
            } else {
                $dataAddress = '';
            }
            
            if ($path = $this->getSwaggerPathToReadById($key)) {
                $props->setProperty(AbstractUrlBuilder::DAP_UID_REQUEST_DATA_ADDRESS, $this->getDataAddressFromPath($path['path']));
            }
            if ($path = $this->getSwaggerPathToCreate($key)) {
                $addr = $this->getDataAddressFromPath($path['path']);
                if ($addr !== $dataAddress) {
                    $props->setProperty(AbstractUrlBuilder::DAP_CREATE_DATA_ADDRESS, $addr);
                }
                $props->setProperty(AbstractUrlBuilder::DAP_CREATE_REQUEST_METHOD, $path['method']);
            }
            if ($path = $this->getSwaggerPathToUpdate($key, $dataAddress, $this->findUidField($def))) {
                $addr = $this->getDataAddressFromPath($path['path']);
                if ($addr !== $dataAddress) {
                    $props->setProperty(AbstractUrlBuilder::DAP_UPDATE_REQUEST_DATA_ADDRESS, $addr);
                }
                $props->setProperty(AbstractUrlBuilder::DAP_UPDATE_REQUEST_METHOD, $path['method']);
            }
            if ($path = $this->getSwaggerPathToDelete($key, $dataAddress, $this->findUidField($def))) {
                $addr = $this->getDataAddressFromPath($path['path']);
                if ($addr !== $dataAddress) {
                    $props->setProperty(AbstractUrlBuilder::DAP_DELETE_REQUEST_DATA_ADDRESS, $addr);
                }
                $props->setProperty(AbstractUrlBuilder::DAP_DELETE_REQUEST_METHOD, $path['method']);
            }
            
            $sheet->addRow([
                'NAME' => $this->getNameFromAlias($key),
                'ALIAS' => $key,
                'DATA_ADDRESS' => $dataAddress,
                'DATA_ADDRESS_PROPS' => $props->toJson(),
                'DATA_SOURCE' => $ds_uid,
                'APP' => $app_uid
            ]);
        }
        return $sheet;
    }
    
    protected function getDataAddressFromPath(string $swaggerPath) : string
    {
        // Replaces URL placeholders like {id} with attribute placeholders like [#id#]
        return str_replace(['{', '}'], ['[#', '#]'], $swaggerPath);
    }
    
    /**
     * Reads the metadata for Properties into a data sheet based on exface.Core.ATTRIBUTE.
     * 
     * @param Crawler $property_nodes
     * @param MetaObjectInterface $object
     * @return DataSheetInterface
     */
    protected function getAttributeData(array $definition, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $sheet->setAutoCount(false);
        $object_uid = $object->getId();
        
        $uidField = $this->findUidField($definition);
        $readPathDef = $this->getSwaggerPaths()[$object->getDataAddress()]['get'];
        
        foreach ($definition['properties'] as $field => $fieldDef) {
            // If the attribute is just a $ref, it is a relation and will be treated later
            // in generateRelations() - skip it here.
            if ($fieldDef['$ref'] && count($fieldDef) === 1) {
                continue;
            }
            
            $dataType = $this->guessDataType($object, $fieldDef);
            
            $props = new UxonObject([
                AbstractUrlBuilder::DAP_FILTER_REMOTE => 'false',
                'sort_remote' => 'false',
            ]);
            if ($readPathDef) {
                foreach ($readPathDef['parameters'] as $paramDef) {
                    if ($paramDef['name'] === $field) {
                        $props->setProperty(AbstractUrlBuilder::DAP_FILTER_REMOTE, 'true');
                    }
                }
            }
            
            $row = [
                'NAME' => $fieldDef['title'] ?? $this->generateLabel($field),
                'ALIAS' => $this->sanitizeAlias($field),
                'DATATYPE' => $this->getDataTypeId($dataType),
                'DATA_ADDRESS' => $field,
                'DATA_ADDRESS_PROPS' => $props->toJson(),
                'OBJECT' => $object_uid,
                'REQUIREDFLAG' => (in_array($field, $definition['required'] ?? []) && ! ($dataType instanceof BooleanDataType) ? 1 : 0),
                'UIDFLAG' => ($field === $uidField ? 1 : 0),
                'SHORT_DESCRIPTION' => $fieldDef['description']
            ];
            
            /* TODO
            $dataTypeOptions = $this->getDataTypeConfig($dataType, $field['type']['ofType'] ?? []);
            if (! $dataTypeOptions->isEmpty()) {
                $row['CUSTOM_DATA_TYPE'] = json_encode($dataTypeOptions->toArray());
            }*/
            
            $sheet->addRow($row);
        }
        return $sheet;
    }
    
    protected function sanitizeAlias(string $alias) : string
    {
        if (substr($alias, 0, 1) === '_') {
            $alias = substr($alias, 1);
        }
        return $alias;
    }
    
    protected function findUidField(array $definition) : ?string
    {
        foreach ($definition['properties'] as $field => $fieldDef) {
            // TODO Determine primary key more reliably. Maybe look through paths for definition/{key} or something?
            // Currently it's the first property, that is called "id".
            if (strcasecmp($field, 'id') === 0) {
                return $field;
            }
        }
        return null;
    }
    
    /**
     * Attempts to make a given XML node name a bit mor human readable.
     * 
     * @param string $nodeName
     * @return string
     */
    protected function generateLabel($nodeName) {
        $string = StringDataType::convertCasePascalToUnderscore($nodeName);
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        return $string;
    }
    
    /**
     * Returns the meta data type, that fit's the given XML node best.
     *
     * @param MetaObjectInterface $object
     * @param array $parameterDef
     * @return DataTypeInterface
     */
    protected function guessDataType(MetaObjectInterface $object, array $parameterDef) : DataTypeInterface
    {
        $workbench = $object->getWorkbench();
        $type = $parameterDef['type'];
        $format = $parameterDef['format'];
        switch ($type) {
            case 'integer':
                $type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                break;
            case 'number':
                $type = DataTypeFactory::createFromString($workbench, NumberDataType::class);
                break;
            case 'boolean':
                $type = DataTypeFactory::createFromString($workbench, BooleanDataType::class);
                break;
            case 'string':
                switch ($format) {
                    case 'date': 
                        $type = DataTypeFactory::createFromString($workbench, DateDataType::class);
                        break;
                    case 'date-time':
                        $type = DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                        break;
                    case 'uuid':
                        $type = DataTypeFactory::createFromString($workbench, HexadecimalNumberDataType::class);
                        break;
                    // TODO add other formats like email, etc.
                    default:
                        $type = DataTypeFactory::createFromString($workbench, StringDataType::class);
                }
                break;
            case 'array':
            case 'object':
                $type = DataTypeFactory::createFromString($workbench, 'exface.Core.Json');
                break;
            default:
                $type = DataTypeFactory::createFromString($workbench, StringDataType::class);
        }
        return $type;
    }
    
    /**
     * Returns a UXON configuration object for the given node and the target meta data type.
     *
     * @param DataTypeInterface $type
     * @param array $parameterDef
     */
    protected function getDataTypeConfig(DataTypeInterface $type, array $parameterDef) : UxonObject
    {
        $options = [];
        /* TODO
        switch (true) {
            case $type instanceof StringDataType:
                if ($length = $node->getAttribute('MaxLength')) {
                    $options['length_max'] = $length;
                }
                break;
            case $type instanceof NumberDataType:
                if ($scale = $node->getAttribute('Scale')) {
                    $options['precision'] = $scale;
                }
                if ($precision = $node->getAttribute('Precision')) {
                    $options['max'] = pow($type->getBase(), ($precision - $scale)) - 1;
                }
                break;
        }*/
        return new UxonObject($options);
    }
}