<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Exceptions\Actions\ActionInputMissingError;

/**
 * Calls an OData service operation (FunctionImport).
 * 
 * This is a version of the `CallWebService` action, which is specialized on
 * GraphQL web services. While all parameters of `CallWebService` can be used
 * too, `CallGraphQLQuery` and `CallGraphQLMutation` can create GraphQL from
 * their parameters automatically, making it very easy to integrate GraphQL
 * services.
 * 
 * Of course, the generic `CallWebService` can be used too if you configure
 * the required `headers` and give it a `body` with placeholders for all 
 * parameters, but it's simply much more work to do!
 * 
 * ## Examples
 * 
 * ```
 * {
 *   "query_name": "deliveries",
 *   "parameters": [
 *     {
 *       "required": true,
 *       "name": "limit",
 *       "data_type": {
 *         "alias": "exface.Core.Integer"
 *       }
 *     },
 *     {
 *       "required": true,
 *       "name": "offset",
 *       "data_type": {
 *         "alias": "exface.Core.Integer"
 *       }
 *     }
 *   ]
 * }
 * 
 * ```
 * 
 * This action metamodell would allow to call the following GraphQL query:
 * 
 * ```
 * type Query {
 *   deliveries(
 *      limit: Int = 20, 
 *      offset: Int = 0
 *   ): [Delivery!]!
 * }
 * 
 * ```
 * 
 * Refer to the description of `CallWebService` for more generic examples.
 * 
 * @author Andrej Kabachnik
 *
 */
class CallGraphQLQuery extends CallWebService 
{
    private $queryName = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::prepareParamValue()
     */
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val)
    {
        $type = $parameter->getDataType();

        // If value is not there, use default
        if ($val === null && $parameter->hasDefaultValue() === true) {
            $val = $parameter->getDefaultValue();
        }

        // If value is empty, format it properly
        if ($type::isValueEmpty($val) && null !== $emptyExpr = $parameter->getEmptyExpression()) {
            $val = $emptyExpr->evaluate();
        }
        
        if ($parameter->isRequired() === true && ($val === '' || $val === null)) {
            throw new ActionInputMissingError($this, 'Value of required parameter "' . $parameter->getName() . '" not set! Please include the corresponding column in the input data or use an input_mapper!', '75C7YOQ');
        }
        
        return '"' . $val . '"';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::parseResponse()
     */
    protected function parseResponse(ResponseInterface $response, DataSheetInterface $resultData) : DataSheetInterface
    {
        if ($response->getStatusCode() != 200) {
            return $resultData->setFresh(true);
        }
        
        $body = $response->getBody()->__toString();
        $json = json_decode($body, true);        
        $resultData->addRows($json['data']);
        
        return $resultData;
    }
    
    /**
     *
     * @return string
     */
    protected function getQueryName() : string
    {
        return $this->queryName;
    }
    
    /**
     * Name of the GraphQL query to execute.
     * 
     * @uxon-property query_name
     * @uxon-type string
     * 
     * @param string $value
     * @return CallGraphQLQuery
     */
    public function setQueryName(string $value) : CallGraphQLQuery
    {
        $this->queryName = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::getMethod()
     */
    protected function getMethod($default = 'POST') : string
    {
        return parent::getMethod('POST');
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::buildBody()
     */
    protected function buildBody(DataSheetInterface $data, int $rowNr, string $method) : string
    {
        $body = parent::buildBody($data, $rowNr, $method);
        if ($body === '') {
            $body = $this->buildGqlBody($data, $rowNr);
        }
        
        return $body;
    }
    
    protected function buildGqlBody(DataSheetInterface $data, int $rowNr) : string
    {
        return <<<GraphQL

query {
    {$this->getQueryName()} {
        {$this->buildGqlFields($data, $rowNr)}
    }
} 

GraphQL;
    }
        
    protected function buildGqlFields(DataSheetInterface $data, int $rowNr) : string
    {
        $fields = '';
        $row = $data->getRow($rowNr);
        foreach ($this->getParameters() as $parameter) {
            $fields .= "        {$parameter->getName()}: {$this->prepareParamValue($parameter, $row[$parameter->getName()])}\r\n";
        }
        return trim($fields);
    }
}