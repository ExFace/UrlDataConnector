<?php
namespace exface\UrlDataConnector\ModelBuilders;

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
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\Exceptions\Model\MetaAttributeNotFoundError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\UrlDataConnector\Actions\CallOData2Operation;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\DataTypes\TimeDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;

/**
 * 
 * @method OData2ConnectorConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class OData2ModelBuilder extends AbstractModelBuilder implements ModelBuilderInterface {
    
    private $metadata = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $transaction);
        
        $this->generateRelations($meta_object->getApp(), $meta_object, $transaction); 
        $this->generateActions($meta_object, $transaction);
        
        $transaction->commit();
        
        return $created_ds;
    }
    
    protected function findFunctionImports(string $entityType) : Crawler
    {
        return $this->getMetadata()->filterXPath('default:FunctionImport[@ReturnType="' . $this->getNamespace($entityType) . '.' . $entityType . '"]');
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
        
        $entityName = $this->getEntityType($meta_object);
        
        $propertyNodes = $this->getMetadata()->filterXPath($this->getXPathToProperties($entityName));
        $foundAttrData = $this->getAttributeData($propertyNodes, $meta_object);        
        
        foreach ($foundAttrData->getRows() as $row) {
            if (count($meta_object->findAttributesByDataAddress($row['DATA_ADDRESS'])) === 0) {
                $created_ds->addRow($row);
            }
        }
        
        $created_ds->removeRowDuplicates();
        
        if (! $created_ds->isEmpty()) {
            $created_ds->dataCreate(false, $transaction);
            // Reload object model and recreate the data sheet, so it is based on the refreshed object
            $refreshed_object = $meta_object->getWorkbench()->model()->reloadObject($meta_object);
            $uxon = $created_ds->exportUxonObject();
            $reloaded_ds = DataSheetFactory::createFromObject($refreshed_object);
            $reloaded_ds->importUxonObject($uxon);
            $reloaded_ds->setCounterForRowsInDataSource(count($foundAttrData->getRows()));
            return $reloaded_ds;
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
        $new_objects->setAutoCount(false);
        
        $transaction = $app->getWorkbench()->data()->startTransaction();
        
        if ($data_address_mask) {
            $filter = '[@Name="' . $data_address_mask . '"]';
        } else {
            $filter = '';
        }
        $entities = $this->getMetadata()->filterXPath($this->getXPathToEntityTypes() . $filter);
        $imported_rows = $this->getObjectData($entities, $app, $source)->getRows();
        $existingAddressCol = $existing_objects->getColumns()->getByExpression('DATA_ADDRESS');
        $existingAliasCol = $existing_objects->getColumns()->getByExpression('ALIAS');
        $importedAliases = [];
        foreach ($imported_rows as $row) {
            // Check if alias is unique within the currently imported objects. Need a dedicated
            // error here, because otherwise there will be a duplicate-error when saving, which
            // is really hard to mentally connect to the model builder.
            if (in_array($row['ALIAS'], $importedAliases) === false) {
                $importedAliases[] = $row['ALIAS'];
            } else {
                throw new ModelBuilderRuntimeError($this, 'Corrupt $metadata: multiple EntityType nodes found with name "' . $row['DATA_ADDRESS'] . '"');
            }
            // If there already exists an object with this data address - skip it
            if ($existingAddressCol->findRowByValue($row['DATA_ADDRESS']) === false) {
                // If the data address is not knonw yet, but there is an alias conflict,
                // make the alias unique by adding a counter
                $existingAliasRows = $existingAliasCol->findRowsByValue($row['ALIAS']);
                if (empty($existingAliasRows) === false) {
                    $row['ALIAS'] = $row['ALIAS'] . '_' . (count($existingAliasRows)+1);
                }
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
                $this->generateActions($object, $transaction);
            }
            // After all attributes are there, generate relations. It must be done after all new objects have
            // attributes as relations need attribute UIDs on both sides!
            $this->generateRelations($app, null, $transaction);
            
        }
        
        $transaction->commit();
        
        return $new_objects;
    }

    /**
     * Create action models for function imports.
     * 
     * Example $metadata:
     * 
     *  <FunctionImport Name="UnlockTaskItem" ReturnType="MY.NAMESPACE.TaskItem" EntitySet="TaskItemSet" m:HttpMethod="GET">
     *      <Parameter Name="TaskId" Type="Edm.String" Mode="In" MaxLength="20"/>
     *      <Parameter Name="TaskItemId" Type="Edm.String" Mode="In" MaxLength="20"/>
     *      <Parameter Name="WarehouseNumber" Type="Edm.String" Mode="In" MaxLength="10"/>
     *  </FunctionImport>
     * 
     * @param MetaObjectInterface $object
     * @param Crawler $functionImports
     * @param DataTransactionInterface $transaction
     * @return DataSheetInterface
     */
    protected function generateActions(MetaObjectInterface $object, DataTransactionInterface $transaction) : DataSheetInterface
    {
        $newActions = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
        $newActions->setAutoCount(false);
        $skipped = 0;
        
        $functionImports = $this->findFunctionImports($this->getEntityType($object));
        foreach ($functionImports as $node) {
            
            // Read action parameters
            $parameters = [];
            $paramNodes = (new Crawler($node))->filterXpath('//default:Parameter');
            foreach ($paramNodes as $paramNode) {
                $pType = $this->guessDataType($object, $paramNode);
                $parameter = [
                    'name' => $paramNode->getAttribute('Name'),
                    'data_type' => [
                        'alias' => $pType->getAliasWithNamespace()
                    ],
                    'custom_properties' => [
                        OData2JsonUrlBuilder::DAP_ODATA_TYPE=> $paramNode->getAttribute('Type')
                    ]
                ];
                if (strcasecmp($node->getAttribute('Nullable'), 'true') !== 0) {
                    $parameter = ['required' => true] + $parameter;
                }
                $pTypeOptions = $this->getDataTypeConfig($pType, $paramNode);
                if (! $pTypeOptions->isEmpty()) {
                    $parameter['data_type'] = array_merge($parameter['data_type'], $pTypeOptions->toArray());
                }
                $parameters[] = $parameter;
            }
            
            // See if action alread exists in the model
            $existingAction = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
            $existingAction->getFilters()->addConditionFromString('APP', $object->getApp()->getUid(), EXF_COMPARATOR_EQUALS);
            $existingAction->getFilters()->addConditionFromString('ALIAS', $node->getAttribute('Name'), EXF_COMPARATOR_EQUALS);
            $existingAction->getColumns()->addFromSystemAttributes()->addFromExpression('CONFIG_UXON');
            $existingAction->dataRead();
            
            // If it does not exist, create it. Otherwise update the parameters only (because they really MUST match the metadata)
            if ($existingAction->isEmpty()) {
                $prototype = str_replace('\\', '/', CallOData2Operation::class) . '.php';
                
                $actionConfig = new UxonObject([
                    'function_import_name' => $node->getAttribute('Name'),
                    'parameters' => $parameters
                ]);
                
                $resultObjectAlias = $this->stripNamespace($node->getAttribute('ReturnType'));
                if ($object->getAlias() !== $resultObjectAlias) {
                    $actionConfig->setProperty('result_object_alias', $object->getNamespace() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER . $resultObjectAlias);
                }
                
                $actionData = [
                    'ACTION_PROTOTYPE' => $prototype,
                    'ALIAS' => $node->getAttribute('Name'),
                    'APP' => $object->getApp()->getUid(),
                    'NAME' => $this->getActionName($node->getAttribute('Name')),
                    'OBJECT' => $object->getId(),
                    'CONFIG_UXON' => $actionConfig->toJson()
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
    
    protected function getActionName(string $alias) : string
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
    protected function generateRelations(AppInterface $app, MetaObjectInterface $object = null, DataTransactionInterface $transaction = null)
    {
        $new_relations = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $new_relations->setAutoCount(false);
        $new_relations = $this->getRelationsData($app, $object, $new_relations);
        
        
        if (! $new_relations->isEmpty()) {
            // To update attributes with new relation data, we need to read the current system columns first
            // (e.g. to allow TimeStampingBehavior, etc.)
            $attributes = $new_relations->copy();
            $attributes->getColumns()->addFromSystemAttributes();
            $attributes->getFilters()->addConditionFromColumnValues($attributes->getUidColumn());
            $attributes->dataRead();
            
            // Overwrite existing values with those read from the $metadata
            $attributes->merge($new_relations);
            $attributes->dataUpdate(false, $transaction);
        }
        
        return $new_relations;
    }
    
    protected function getRelationsData(AppInterface $app, MetaObjectInterface $object = null, DataSheetInterface $dataSheet) : DataSheetInterface
    {
        $skipped = 0;
        
        $associations = $this->getMetadata()->filterXPath('//default:Association');
        foreach ($associations as $node) {
            // Add relation data to the data sheet - those fields, that will mark the attribute as a relation
            if ($attributeData = $this->getRelationDataFromAssociation($node, $app, $object)) {
                $dataSheet->addRow($attributeData);
            } else {
                $skipped++;
            }
        }
        
        $dataSheet->setCounterForRowsInDataSource($dataSheet->countRows() + $skipped);
        
        return $dataSheet;
    }
    
    protected function getRelationDataTemplate() : array
    {
        // This array needs to be filled
        return [
            'UID' => null,
            'ALIAS' => null,
            'NAME' => null,
            'RELATED_OBJ' => null,
            'RELATED_OBJ_ATTR' => null,
            'DATA_ADDRESS_PROPS' => null,
            'COPY_WITH_RELATED_OBJECT' => 0, // oData services are expected to take care of correct copying themselves
            'DELETE_WITH_RELATED_OBJECT' => 0 // oData services are expected to take care of cascading deletes themselves
        ];
    }
    
    /**
     *  <EntityType Name="PARTNER">
     *      ...
     *      <NavigationProperty Name="to_country" Relationship="B57D30" FromRole="FromRole_B57D30" ToRole="ToRole_B57D30"/>
     *  </EntityType>
     *  ...
     *  <Association Name="B57D30A3CF0BCD3B96FAE2AA00D33800" sap:content-version="1">
     *      <End Type="PARTNER" Multiplicity="1" Role="FromRole_B57D300"/>
     *      <End Type="COUNTRY" Multiplicity="0..1" Role="ToRole_B57D30"/>
     *  </Association>
     *  
     * 
     * @param \DOMElement $node
     * @param AppInterface $object
     * 
     * @throws ModelBuilderRuntimeError
     * 
     * @return array|NULL
     */
    private function getRelationDataFromNavigationProperty(\DOMElement $node, MetaObjectInterface $object, bool $keysKnown = false) : ?array
    {
        $attributeData = $this->getRelationDataTemplate();
        $thisObjEntityType = $this->getEntityType($object);
        
        try {
            $propertyName = $node->getAttribute('Name');
            $relationshipName = $node->getAttribute('Relationship');
            $associationNode = $this->getMetadata()->filterXPath('//default:Association[@Name="' . $this->stripNamespace($relationshipName) . '"]');
            
            if ($associationNode->count() === 0) {
                throw new ModelBuilderRuntimeError($this, 'OData association "' . $relationshipName . '" not found in $metadata!');   
            }
            
            $fromRoleName = $node->getAttribute('FromRole');
            $fromRoleNode = $associationNode->filterXPath('//default:End[@Role="' . $fromRoleName . '"]')->getNode(0);
            $fromEntityType = $this->stripNamespace($fromRoleNode->getAttribute('Type'));
            if ($fromEntityType === $thisObjEntityType) {
                $fromObject = $object;
            } else {
                $object->getWorkbench()->model()->getObjectByAlias($fromEntityType, $object->getApp()->getAliasWithNamespace());
            }
            
            $toRoleName = $node->getAttribute('ToRole');
            $toRoleNode = $associationNode->filterXPath('//default:End[@Role="' . $toRoleName . '"]')->getNode(0);
            $toEntityType = $this->stripNamespace($toRoleNode->getAttribute('Type'));
            if ($toEntityType === $thisObjEntityType) {
                $toObject = $object;
            } else {
                $toObject = $object->getWorkbench()->model()->getObjectByAlias($toEntityType, $object->getApp()->getAliasWithNamespace());
            }
            
            $attributeData['OBJECT'] = $fromObject->getId();
            $attributeData['ALIAS'] = $propertyName;
            $attributeData['NAME'] = $toObject->getName();
            $attributeData['DATATYPE'] = '0x11eb81683be90d2e8168025041000001'; // ODataDeferredDataType
            $attributeData['RELATED_OBJ'] = $toObject->getId();
            $attributeData['DATA_ADDRESS'] = $propertyName;
            $attributeData['DATA_ADDRESS_PROPS'] = (new UxonObject([
                OData2JsonUrlBuilder::DAP_ODATA_NAVIGATIONPROPERTY => $propertyName
            ]))->toJson();
            
            if (! $keysKnown) {
                // If the keys of the relation are not known, we can's sort/aggregate the attribute 
                // itself as it has no single value. Filtering should work if properly supported
                // by the query builder.
                $attributeData['SORTABLEFLAG'] = 0;
                $attributeData['AGGREGATABLEFLAG'] = 0;
            }
            
        } catch (MetaObjectNotFoundError $eo) {
            $object->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot find object for one of the ends of oData association ' . $node->getAttribute('Name') . ': Skipping association!', '73G87II', $eo), LoggerInterface::WARNING);
            return null;
        } catch (MetaAttributeNotFoundError $ea) {
            throw new ModelBuilderRuntimeError($this, 'Cannot convert oData association "' . $relationshipName . '" to relation for object ' . $fromObject->getAliasWithNamespace() . ' automatically: one of the key attributes was not found - see details below.', '73G87II', $ea);
        }
        
        return $attributeData;
    }
    
    /**
     * Produces attribute data for relation-attributes in the following cases:
     * 
     * - Only <Association> exists: the relation is attached to the attribute referenced by
     * the <Dependent> node of the <ReferentialConstraint>. If not constraint node exists,
     * no relation is created.
     * - Only <NavigationProperty> exists: a new attribute is created with the navigation
     * properties name as data address. The attribute has the special `ODataDeferredDataType` 
     * and is neither sortable nor aggregatable by default. Since the right key of the relation 
     * is not known, the UID of the right object is assumed.
     * - Both <Association> and <NavigationProperty> exist: a new attribute is created for
     * the <NavigationProperty>, but the right key of the relation can be set properly.
     * 
     * Here is how an <Association> node looks like (provided, that each Delivery consists
     * of 0 to many Tasks). 
     * 
     *  <Association Name="DeliveryToTasks" sap:content-version="1">
     *     <End Type="Namespace.Delivery" Multiplicity="1" Role="FromRole_DeliveryToTasks"/>
     *     <End Type="Namespace.Task" Multiplicity="*" Role="ToRole_DeliveryToTasks"/>
     *     <ReferentialConstraint>
     *         <Principal Role="FromRole_DeliveryToTasks">
     *             <PropertyRef Name="DeliveryId"/>
     *         </Principal>
     *         <Dependent Role="ToRole_DeliveryToTasks">
     *             <PropertyRef Name="DeliveryId"/>
     *         </Dependent>
     *     </ReferentialConstraint>
     *  </Association>
     * 
     * Another variantion is one without <ReferentialConstraint> but with a linked
     * <NavigationProperty> (in this example a PARTNER belongs to a COUNTRY).
     * 
     *  <EntityType Name="PARTNER">
     *      ...
     *      <NavigationProperty Name="to_country" Relationship="B57D30" FromRole="FromRole_B57D30" ToRole="ToRole_B57D30"/>
     *  </EntityType>
     *  ...
     *  <Association Name="B57D30A3CF0BCD3B96FAE2AA00D33800" sap:content-version="1">
     *      <End Type="PARTNER" Multiplicity="1" Role="FromRole_B57D300"/>
     *      <End Type="COUNTRY" Multiplicity="0..1" Role="ToRole_B57D30"/>
     *  </Association>
     *  
     * The version with a <ReferentialConstraint> may also be linked to a <NavigationProperty>.
     * 
     * @param \DOMElement $association
     * @return array
     */
    private function getRelationDataFromAssociation(\DOMElement $node, AppInterface $app, MetaObjectInterface $object = null) : ?array
    {
        $attributeData = $this->getRelationDataTemplate();
        $relationAddressProps = new UxonObject();
        
        try {
            
            $namespace = $this->getNamespace();
            $ends = [];
            foreach ($node->getElementsByTagName('End') as $endNode) {
                $ends[$endNode->getAttribute('Role')] = $endNode;
            }
            
            $constraintNode = $node->getElementsByTagName('ReferentialConstraint')->item(0);
            
            $relationshipName = $node->getAttribute('Name');
            $navPropertyNode = $this->getMetadata()->filterXPath($this->getXPathToNavigationProperties() . '[@Relationship="' . $namespace . '.' . $relationshipName . '"]')->getNode(0);
            
            if ($constraintNode === null && $navPropertyNode === null) {
                // If the association does not have <ReferentialConstraint>, we don't know the keys
                // for the relation, so we can't use it unless there is a matching NavigationProperty,
                // which was already converted to an attribute before. This happens if Associations 
                // are generated from SAP CDS annotations for value help. In this case, a special section 
                // is generated in <Annotations> of the $metadata.
                // TODO generate Relations from Annotations
                $err = new ModelBuilderRuntimeError($this, 'Cannot create meta relation for OData Association "' . $node->getAttribute('Name') . '" - neither a ReferentialConstraint nor a matching NavigationProperty found!');
                $app->getWorkbench()->getLogger()->logException($err);
                return null;
            }
            
            // Get left-side data
            if ($navPropertyNode) {
                // From the NavigationProperty if possible
                $navPropertyEntity = $navPropertyNode->parentNode->getAttribute('Name');
                $leftObject = $navPropertyObject = $this->getObjectByEntityType($navPropertyEntity, $app);
                
                $rightEndNode = $ends[$navPropertyNode->getAttribute('FromRole')];
                
                if ($navRelationData = $this->getRelationDataFromNavigationProperty($navPropertyNode, $leftObject, ($constraintNode !== null))) {
                    $attributeData = $navRelationData;
                    $relationAddressProps = UxonObject::fromJson($attributeData['DATA_ADDRESS_PROPS'] ?? '{}');
                    $leftAttributeAlias = $attributeData['ALIAS'];
                    if ($leftObject->hasAttribute($leftAttributeAlias)) {
                        $leftAttribute = $leftObject->getAttribute($leftAttributeAlias);
                        $attributeData['UID'] = $leftAttribute->getId();
                    } else {
                        // If generating a certain object, we can't create attributes for another
                        // one!
                        if ($object && ! $leftObject->isExactly($object)) {
                            return null;
                        }
                        $leftAttribute = null;
                        $ds = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.ATTRIBUTE');
                        // Add the UID column to make sure the new UID is read into it.
                        $ds->getColumns()->addFromUidAttribute();
                        $ds->addRow($attributeData);
                        $ds->dataCreate();
                        $attributeData = $ds->getRow(0);
                    }
                }
            } 
            if ($constraintNode) {
                // From the ReferentialConstraint/Dependent if possible (eventally overwriting the data from 
                // the NavigationProperty)
                $dependentNode = $constraintNode->getElementsByTagName('Dependent')->item(0);
                $leftEndNode = $ends[$dependentNode->getAttribute('Role')];
                $leftEntityType = $this->stripNamespace($leftEndNode->getAttribute('Type'));
                $leftObject = $this->getObjectByEntityType($leftEntityType, $app); 
                if ($navPropertyObject && $leftObject !== $navPropertyObject) {
                    $app->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot process OData association ' . $node->getAttribute('Name') . ': object mismatch in ReferentialConstraint and NavigationProperty!'));
                    return null;
                }
                if (! $leftAttribute) {
                    $leftAttributeAlias = $dependentNode->getElementsByTagName('PropertyRef')->item(0)->getAttribute('Name');
                    $leftAttribute = $leftObject->getAttribute($leftAttributeAlias);
                }
                
                $principalNode = $constraintNode->getElementsByTagName('Principal')->item(0);
                $rightEndNode = $ends[$principalNode->getAttribute('Role')];$rightEntityType = $this->stripNamespace($rightEndNode->getAttribute('Type'));
                $rightObject = $this->getObjectByEntityType($rightEntityType, $app);
                $rightAttributeAlias = $principalNode->getElementsByTagName('PropertyRef')->item(0)->getAttribute('Name');
                $rightKeyAttribute = $rightObject->getAttribute($rightAttributeAlias);
                
                $attributeData['UID'] = $leftAttribute->getId();
                $attributeData['ALIAS'] = $leftAttributeAlias;
                $attributeData['NAME'] = $rightObject->getName();
                $attributeData['DATA_ADDRESS'] = $leftAttribute->getDataAddress();
                $attributeData['RELATED_OBJ'] = $rightObject->getId();
                $attributeData['RELATED_OBJ_ATTR'] = $rightKeyAttribute->isUidForObject() === false ? $rightKeyAttribute->getId() : '';
            }
            
            // Skip existing relations with the same alias
            if ($leftAttribute && $leftAttribute->isRelation() === true) {
                return null;
            }
            
            // filter away relations, that do not start or end with the $object if that is specified
            if ($object !== null && $attributeData['OBJECT'] !== $object->getId() && $attributeData['RELATED_OBJ'] !== $object->getId()) {
                return null;
            }
                
            
            $dataAddressProps = $leftAttribute ? $leftAttribute->getDataAddressProperties()->extend($relationAddressProps) : $relationAddressProps;
            
            $attributeData['DATA_ADDRESS_PROPS'] = $dataAddressProps->toJson();
            
        } catch (MetaObjectNotFoundError $eo) {
            $app->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot find object for one of the ends of oData association ' . $node->getAttribute('Name') . ': Skipping association!', '73G87II', $eo), LoggerInterface::WARNING);
            return null;
        } catch (MetaAttributeNotFoundError $ea) {
            throw new ModelBuilderRuntimeError($this, 'Cannot convert oData association "' . $node->getAttribute('Name') . '" to relation for object ' . $leftObject->getAliasWithNamespace() . ' automatically: one of the key attributes was not found - see details below.', '73G87II', $ea);
        }
        
        return $attributeData;
    }
    
    protected function getObjectByEntityType(string $entityTypeName, AppInterface $app) : MetaObjectInterface
    {
        return $app->getWorkbench()->model()->getObjectByAlias($entityTypeName, $app->getAliasWithNamespace());
    }
    
    /**
     * Returns a crawlable instance containing the entire metadata XML.
     * 
     * @return Crawler
     */
    protected function getMetadata() : Crawler
    {
        if (is_null($this->metadata)) {
            $query = new Psr7DataQuery(new Request('GET', $this->getDataConnection()->getMetadataUrl()));
            $query->setUriFixed(true);
            $query = $this->getDataConnection()->query($query);
            $xml = $query->getResponse()->getBody()->__toString();
            if (strpos($xml, 'EntityType') === false) {
                throw new ModelBuilderRuntimeError($this, 'No OData EntityTypes found in metadata from "' . $this->getDataConnection()->getMetadataUrl() . '". Wrong metadata-URL?');
            }
            $this->metadata = new Crawler($xml);
        }
        return $this->metadata;
    }
    
    /**
     * Returns a data sheet of exface.Core.OBJECT created from the given EntityTypes.
     * 
     * @param Crawler $entity_nodes
     * @param AppInterface $app
     * @param DataSourceInterface $data_source
     * @return DataSheetInterface
     */
    protected function getObjectData(Crawler $entity_nodes, AppInterface $app, DataSourceInterface $data_source) 
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $sheet->setAutoCount(false);
        $ds_uid = $data_source->getId();
        $app_uid = $app->getUid();
        foreach ($entity_nodes as $entity) {
            $namespace = $entity_nodes->parents()->first()->attr('Namespace');
            $entityName = $entity->getAttribute('Name');
            $address = $this->getEntitySetNode($entity)->attr('Name');
            $sheet->addRow([
                'NAME' => $entityName,
                'ALIAS' => $entityName,
                'DATA_ADDRESS' => $address,
                'DATA_SOURCE' => $ds_uid,
                'APP' => $app_uid,
                'DATA_ADDRESS_PROPS' => json_encode([
                    "odata_entitytype" => $entityName,
                    "odata_namespace" => $namespace
                ])
            ]);
        }
        return $sheet;
    }
    
    /**
     * Reads the metadata for Properties into a data sheet based on exface.Core.ATTRIBUTE.
     * 
     * @param Crawler $property_nodes
     * @param MetaObjectInterface $object
     * @return DataSheetInterface
     */
    protected function getAttributeData(Crawler $property_nodes, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $sheet->setAutoCount(false);
        $object_uid = $object->getId();
        
        // Find the primary key
        $keys = $this->findPrimaryKeys($property_nodes);
        if (count($keys) === 1) {
            $primary_key = $keys[0];
        } else {
            $primary_key = false;
        }
        if (count($keys) > 1) {
            $object->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot import compound primary key for ' . $object->getAliasWithNamespace() . ' - please create a compound attribute in the metamodel manually!'));
        }
        
        foreach ($property_nodes as $node) {
            $name = $node->getAttribute('Name');
            $dataType = $this->guessDataType($object, $node);
            
            $dataAddressProps = [
                OData2JsonUrlBuilder::DAP_ODATA_TYPE => $node->getAttribute('Type')
            ];
            
            $row = [
                'NAME' => $this->generateLabel($name),
                'ALIAS' => $name,
                'DATATYPE' => $this->getDataTypeId($dataType),
                'DATA_ADDRESS' => $name,
                'DATA_ADDRESS_PROPS' => json_encode($dataAddressProps),
                'OBJECT' => $object_uid,
                'REQUIREDFLAG' => (strtolower($node->getAttribute('Nullable')) === 'false' ? 1 : 0),
                'UIDFLAG' => ($primary_key !== false && strcasecmp($name, $primary_key) === 0 ? 1 : 0)
            ];
            
            $dataTypeOptions = $this->getDataTypeConfig($dataType, $node);
            if (! $dataTypeOptions->isEmpty()) {
                $row['CUSTOM_DATA_TYPE'] = json_encode($dataTypeOptions->toArray());
            }
            
            $sheet->addRow($row);
        }
        return $sheet;
    }
    
    /**
     * 
     * @param Crawler $property_nodes
     * @return string[]
     */
    protected function findPrimaryKeys(Crawler $property_nodes) : array
    {
        $keys = [];
        $key_nodes = $property_nodes->siblings()->filterXPath('default:Key/default:PropertyRef');
        foreach ($key_nodes as $node) {
            $keys[] = $node->getAttribute('Name');
        }
        return $keys;
    }
    
    /**
     * Returns "MyEntityType" from "My.Model.EntityType"
     * 
     * @param string $nameWithNamespace
     * @return string
     */
    protected function stripNamespace($nameWithNamespace)
    {
        $dotPos = strrpos($nameWithNamespace, '.');
        if ($dotPos === false) {
            return $nameWithNamespace;
        }
        return substr($nameWithNamespace, ($dotPos+1));
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
     * NOTE: Don't modify the data type here - use `getDataTypeConfig()` instead!
     *
     * @param MetaObjectInterface $object
     * @param \DOMElement $node
     * @return DataTypeInterface
     */
    protected function guessDataType(MetaObjectInterface $object, \DOMElement $node) : DataTypeInterface
    {
        $workbench = $object->getWorkbench();
        $source_data_type = strtoupper($node->getAttribute('Type'));
        switch (true) {
            case (strpos($source_data_type, 'INT') !== false):
                $type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                break;
            case (strpos($source_data_type, 'BYTE') !== false):
                $type = DataTypeFactory::createFromString($workbench, 'exface.Core.NumberNatural');
                break;
            case (strpos($source_data_type, 'FLOAT') !== false):
            case (strpos($source_data_type, 'DECIMAL') !== false):
            case (strpos($source_data_type, 'DOUBLE') !== false):
                $type = DataTypeFactory::createFromString($workbench, NumberDataType::class);
                break;
            case (strpos($source_data_type, 'BOOL') !== false):
                $type = DataTypeFactory::createFromString($workbench, BooleanDataType::class);
                break;
            case (strpos($source_data_type, 'DATETIME') !== false):
                $type = DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                break;
            case (strpos($source_data_type, 'DATE') !== false):
                $type = DataTypeFactory::createFromString($workbench, DateDataType::class);
                break;
            case (strpos($source_data_type, 'TIME') !== false):
                $type = DataTypeFactory::createFromString($workbench, TimeDataType::class);
                break;
            case $source_data_type === 'EDM.BINARY':
                $type = DataTypeFactory::createFromString($workbench, BinaryDataType::class);
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
     * @param string $source_data_type
     */
    protected function getDataTypeConfig(DataTypeInterface $type, \DOMElement $node) : UxonObject
    {
        $options = [];
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
                    $options['max'] = pow($type->getBase(), ($precision - $scale)) - pow(1, (-$scale));
                }
                
                break;
            case $type instanceof BinaryDataType:
                $options['encoding'] = 'base64';
                break;
        }
        return new UxonObject($options);
    }
    
    /**
     * Returns the XPath expression to filter EntityTypes
     * @return string
     */
    protected function getXPathToEntityTypes()
    {
        return '//default:EntityType';
    }
    
    /**
     * Returns the XPath expression to filter EntitySets
     * @return string
     */
    protected function getXPathToEntitySets()
    {
        return '//default:EntitySet';
    }
    
    /**
     * Returns the XPath expression to filter all EntityType Properties
     * @return string
     */
    protected function getXPathToProperties($entityName)
    {
        return $this->getXPathToEntityTypes() . '[@Name="' . $entityName . '"]/default:Property';
    }
    
    /**
     * 
     * @param string|NULL $entityName
     * @return string
     */
    protected function getXPathToNavigationProperties(string $entityName = null) : string
    {
        if ($entityName !== null) {
            return $this->getXPathToEntityTypes() . '[@Name="' . $entityName . '"]/default:NavigationProperty';
        } else {
            return '//default:NavigationProperty';
        }
    }
    
    /**
     * Returns the EntityType holding the definition of the given object or NULL if the object does not match an EntityType.
     * 
     * Technically the data address of the object is the name of the EntitySet, so the result of this method is
     * the EntityType in the first EntitySet, where the name matches the data address of the given object.
     * 
     * @param MetaObjectInterface $object
     * @return string|null
     */
    protected function getEntityType(MetaObjectInterface $object)
    {
        $name = $object->getDataAddressProperty('odata_entitytype');
        $tried = [];
        if (! $name) {
            $name = $object->getDataAddressProperty('EntityType');
        }
        
        if ($name) {
            $tried[] = 'EntityType[@Name="' . $name . '"]';
        }
        
        if (! $name) {
            $entitySet = $this->getMetadata()->filterXPath($this->getXPathToEntitySets() . '[@Name="' . $object->getDataAddress() . '"]');
            $tried = 'EntitySet[@Name="' . $object->getDataAddress() . '"]';
            if ($entitySet->count() > 0) {
                $name = $this->stripNamespace($entitySet->attr('EntityType'));
            }
        }
        
        if ($name) {
            $entityType = $this->getMetadata()->filterXPath($this->getXPathToEntityTypes() . '[@Name="' . $name . '"]');
        }
        
        if (! $name || ! $entityType || $entityType->count() === 0) {
            throw new ModelBuilderRuntimeError($this, 'No OData EntityType found for object "' . $object->getName() . '" (' . $object->getAliasWithNamespace() . '). Tried ' . implode(', ', $tried));
        }
        
        return $name;
    }
    
    /**
     * Returns the XML node for the first EntitySet containing the given EntityType
     * 
     * @param \DOMElement $entityTypeNode
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    protected function getEntitySetNode(\DOMElement $entityTypeNode)
    {
        $namespace = (new Crawler($entityTypeNode))->parents()->first()->attr('Namespace');
        $entityName = $entityTypeNode->getAttribute('Name');
        return $this->getMetadata()->filterXPath($this->getXPathToEntitySets() . '[@EntityType="' . $namespace . '.' . $entityName . '"]');
    }
    
    /**
     * Returns the Namespace of the schema in the given XML
     * 
     * @param Crawler $xml
     * @return string
     */
    protected function getNamespace(string $entityType = null) : string
    {
        return $this->getMetadata()->filterXPath('//default:Schema')->attr('Namespace');
    }
}