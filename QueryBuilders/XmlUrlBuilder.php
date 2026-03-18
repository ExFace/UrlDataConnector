<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\Exceptions\QueryBuilderException;
use Psr\Http\Message\ResponseInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use exface\Core\DataTypes\ArrayDataType;

/**
 * TODO: This is a very early beta.
 * Just a demo!
 *
 * @author Andrej Kabachnik
 *        
 */
class XmlUrlBuilder extends AbstractUrlBuilder
{
    /**
     * FIXME use simpleXmlElement instead of arrays.
     * The arrays were just a shortcut to keep this similar to the JSON query builder
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::read()
     */
    function read(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $result_rows = array();
        $query = $data_connection->query(new Psr7DataQuery($this->buildRequestToRead()));
        if ($data = $this->parseResponse($query)) {
            $data_array = (array) $data;
            
            $total_count = $data_array;
            foreach (explode('/', $this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_TOTAL_COUNT_PATH)) as $step) {
                $total_count = $total_count[$step];
            }
            
            $originalLimit = $this->getLimit();
            if ($originalLimit > 0) {
                $this->setLimit($originalLimit+1, $this->getOffset());
            }
            
            if ($this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_DATA_PATH)) {
                $rows = (array) $data_array[$this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_DATA_PATH)];
                if (! is_array($rows)) {
                    $rows = array(
                        $rows
                    );
                }
            } else {
                $rows = (array) $data_array;
            }
            if (is_array($rows)) {
                foreach ($rows as $nr => $row) {
                    $row = (array) $row;
                    /* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartSelect */
                    foreach ($this->getAttributes() as $qpart) {
                        $val = $row;
                        if ($path = $qpart->getDataAddress()) {
                            foreach (explode('/', $path) as $step) {
                                if ($cond_start = strpos($step, '[')) {
                                    if (substr($step, - 1) != ']')
                                        throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->getAlias() . '": "' . $step . '"!');
                                    $cond = explode('=', substr($step, $cond_start + 1, - 1));
                                    if ($val = $val[substr($step, 0, $cond_start)]) {
                                        foreach ($val as $v) {
                                            if ($v[$cond[0]] == $cond[1]) {
                                                $val = $v;
                                                break;
                                            }
                                        }
                                    }
                                } else {
                                    $val = $val[$step];
                                }
                            }
                            
                            // Check if the value is still an array and an aggregator must be applied
                            if (is_array($val)) {
                                $val = ArrayDataType::aggregateValues($val, $qpart->getAggregator());
                            }
                            $result_rows[$nr][$qpart->getColumnKey()] = $val;
                        }
                    }
                }
            }
        }
        
        $rowCnt = count($result_rows);
        if ($originalLimit > 0 && $rowCnt === $originalLimit + 1) {
            $hasMoreRows = true;
            array_pop($result_rows);
        } else {
            $hasMoreRows = false;
        }
        
        return new DataQueryResultData($result_rows, $rowCnt, $hasMoreRows, $total_count);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::count()
     */
    public function count(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $query = $data_connection->query(new Psr7DataQuery($this->buildRequestToRead()));
        if ($data = $this->parseResponse($query)) {
            $data_array = (array) $data;
            
            $total_count = $data_array;
            foreach (explode('/', $this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_TOTAL_COUNT_PATH)) as $step) {
                $total_count = $total_count[$step];
            }
        } else {
            $total_count = 0;
        }
        return new DataQueryResultData([], $total_count, 0, $total_count);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        return $this->buildResultRowsResolvePlaceholders($parsed_data, $query);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::parseResponse()
     */
    protected function parseResponse(Psr7DataQuery $query)
    {
        return new \SimpleXMLElement($query->getResponse()->getBody()->getContents());
    }
    
    protected function findFieldInData($data_address, $data)
    {
        // TODO refactor to whole class to use the current AbstractUrlBuilder methods and symfony crawler.
        return null;
    }

}
?>