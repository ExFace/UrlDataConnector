<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Exceptions\QueryBuilderException;

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
}