<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Exceptions\QueryBuilderException;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;

/**
 * This is a query builder for JSON-based oData 4.0 APIs.
 * 
 * See the `AbstractUrlBuilder` and `OData2JsonUrlBuilder` for information about available 
 * data address properties.
 * 
 * ## Pagination
 * 
 * Remote pagination via `$top` and `$skip` is configured automatically based on the information
 * in the OData $metadata document. You can also enable it explicitly by setting 
 * `request_remote_pagination:true` on object level. If you need only `$top` and no `$skip`,
 * overwrite the undesired option with an empty value: e.g. `request_offset_parameter:`.
 * 
 * @see JsonUrlBuilder for data address syntax
 * @see AbstractUrlBuilder for data source specific parameters
 * 
 * @author Andrej Kabachnik
 *        
 */
class OData4JsonUrlBuilder extends OData2JsonUrlBuilder
{


    /**
     * Set to FALSE to exclude this attribute from the OData $select URL parameter
     *
     * @uxon-property odata_include_in_$select
     * @uxon-target attribute
     * @uxon-type boolean
     */
    const DAP_ODATA_INCLUDE_IN_SELECT = 'odata_include_in_$select';
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::getODataVersion()
     */
    protected function getODataVersion() : string
    {
        return '4';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::getDefaultPathToResponseRows()
     */
    protected function getDefaultPathToResponseRows() : string
    {
        return 'value';
    }

    /**
     * OData posprocessing includes a check for "unrequested" pagination to avoid, that if we request
     * an unpaged result, data is still "cut off" by the web service itself. In OData we can detect that
     * by checking for `@odata.nextLink` in the response
     * 
     * @see JsonUrlBuilder::readApplyPostprocessing()
     */
    protected function readApplyPostprocessing(array $result_rows, $parsedResponse, DataConnectionInterface $connection) : array
    {
        // Make sure, we load ALL data if not using remote pagination - double check if, there is an `@odata.nextLink`
        // because some OData services will actually force pagination even if you don't want one - e.g.
        // Microsoft Graph in https://graph.microsoft.com/v1.0/me/memberOf. 
        // So if we are reading unpaged (either explicitly or because remote paging is off), then having `@odata.nextLink`
        // actually means, we need to follow it.
        // TODO will this work with OData2? Only tested with OData4 in Microsoft Graph API
        if (
            (
                // Read all explicitly
                $this->getLimit() === null
                // Paging is off, so we assume to read all
                || ! $this->isRemotePaginationConfigured()
            ) 
            && null !== $nextLink = $this->getPaginationNextLink($parsedResponse)
        ) {
            try {
                $nextPageRequest = new Request('GET', $nextLink);
                $nextPageQuery = new Psr7DataQuery($nextPageRequest);
                $nextPageQuery = $connection->query($nextPageQuery);
                $nextPageParsed = $this->parseResponse($nextPageQuery);
                $nextPageRows = $this->findRowData($nextPageParsed, $this->buildPathToResponseRows($nextPageQuery));
                // Call this method recursively for the next page result too, so that will check for hidden
                // pagination again and load subsequent pages
                $nextPageRows = $this->readApplyPostprocessing($nextPageRows, $nextPageParsed, $connection);
                $result_rows = array_merge($result_rows, $nextPageRows);
            } catch (\Throwable $e) {
                throw new QueryBuilderException('Cannot read `@odata.nextLink` in an unpaged query. ' . $e->getMessage(), null, $e);
            }
        }
        
        // Now after we have ALL the data, we can do all other postprocessing
        $result_rows = parent::readApplyPostprocessing($result_rows, $parsedResponse, $connection);
        
        return $result_rows;
    }
    
    protected function getPaginationNextLink(array $parsedResponse) : ?string
    {
        return $parsedResponse['@odata.nextLink'] ?? null;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildPathToTotalRowCounter()
     */
    protected function buildPathToTotalRowCounter(MetaObjectInterface $object)
    {
        return '@odata.count';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::buildUrlFilterPredicate()
     */
    protected function buildUrlFilterPredicate(QueryPartFilter $qpart, string $property, string $preformattedValue = null) : string
    {
        $comp = $qpart->getComparator();
        switch ($comp) {
            case EXF_COMPARATOR_IS:
            case EXF_COMPARATOR_IS_NOT:
                $escapedValue = $preformattedValue ?? $this->buildUrlFilterValue($qpart);
                if ($qpart->getDataType() instanceof NumberDataType) {
                    $op = ($comp === EXF_COMPARATOR_IS_NOT ? 'ne' : 'eq');
                    return "{$property} {$op} {$escapedValue}";
                } else {
                    return ($comp === EXF_COMPARATOR_IS_NOT ? 'not ' : '') . "contains({$property},{$escapedValue})";
                }
            case EXF_COMPARATOR_IN:
            case EXF_COMPARATOR_NOT_IN:
                $values = is_array($qpart->getCompareValue()) === true ? $qpart->getCompareValue() : explode($qpart->getAttribute()->getValueListDelimiter(), $qpart->getCompareValue());
                if (count($values) === 1) {
                    // If there is only one value, it is better to treat it as an equals-condition because many oData services have
                    // difficulties in() or simply do not support it.
                    $qpart->setComparator($qpart->getComparator() === EXF_COMPARATOR_IN ? EXF_COMPARATOR_EQUALS : EXF_COMPARATOR_EQUALS_NOT);
                    // Rebuild the value because we changed the comparator!
                    $preformattedValue = $this->buildUrlFilterValue($qpart);
                    // Continue with next case here.
                } else {
                    if ($qpart->getComparator() === EXF_COMPARATOR_IN) {
                        return "{$property} in {$this->buildUrlFilterValue($qpart)}";
                    } else {
                        return "not ({$property} in {$this->buildUrlFilterValue($qpart)})";
                    }
                }
            default:
                return parent::buildUrlFilterPredicate($qpart, $property, $preformattedValue);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::buildUrlFilterValue()
     */
    protected function buildUrlFilterValue(QueryPartFilter $qpart, string $preformattedValue = null)
    {
        $comparator = $qpart->getComparator();
        
        if ($preformattedValue !== null) {
            $value = $preformattedValue;
        } else {
            $value = $qpart->getCompareValue();
            try {
                $value = $qpart->getDataType()->parse($value);
            } catch (\Throwable $e) {
                throw new QueryBuilderException('Cannot create OData filter for "' . $qpart->getCondition()->toString() . '" - invalid data type!', null, $e);
            }
        }
        
        if ($comparator === EXF_COMPARATOR_IN || $comparator === EXF_COMPARATOR_NOT_IN) {
            $values = [];
            if (! is_array($value)) {
                $value = explode($qpart->getAttribute()->getValueListDelimiter(), $qpart->getCompareValue());
            }
            
            foreach ($value as $val) {
                $splitQpart = clone $qpart;
                $splitQpart->setCompareValue($val);
                $splitQpart->setComparator($comparator === EXF_COMPARATOR_IN ? EXF_COMPARATOR_EQUALS : EXF_COMPARATOR_EQUALS_NOT);
                $values[] = $this->buildUrlFilterValue($splitQpart);
            }
            return '(' . implode(',', $values) . ')';
        }
        
        return parent::buildUrlFilterValue($qpart);
    }



    /**
     * In OData4 we include the $select URL parameter by default for property-bound attributes
     * 
     * @see OData2JsonUrlBuilder::buildUrlParamSelect()
     */
    protected function buildUrlParamSelect(array $qparts) : string
    {
        $props = [];
        foreach ($qparts as $qpart) {
            $addr = $qpart->getDataAddress();
            if (! $addr) {
                continue;
            }
            $selectProp = $qpart->getDataAddressProperty(static::DAP_ODATA_INCLUDE_IN_SELECT);
            if ($selectProp !== null) {
                $selectProp = BooleanDataType::cast($selectProp) === false;
            }
            if ($selectProp === false) {
                continue;
            }
            if ($selectProp !== true && ! $qpart->getDataAddressProperty(static::DAP_ODATA_TYPE)) {
                continue;
            }
            $props[] = $addr;
        }
        return empty($props) ? '' : '$select=' . implode(',', $props) . '';
    }
}