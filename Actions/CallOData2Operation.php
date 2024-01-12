<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;
use exface\Core\Exceptions\Actions\ActionConfigurationError;

/**
 * Calls an OData service operation (FunctionImport).
 * 
 * ## Nicer success and error messages
 * 
 * By default, OData operation success and error messages are more or less the resulting
 * response body. Success messages depend on the specific operation implementation and
 * error messages vary depending on the OData service architecture.
 * 
 * It is easy to extrackt specific parts of the responses via regular expressions. For
 * example, to extract the error message from SAP OData responses add the following line 
 * to the action configuration:
 * 
 * ```
 *  "error_message_pattern": "/\"message\"\\s?:\\s?\"(?<message>[^\"]*)\"/"
 *  
 * ```
 * 
 * If you have multiple actions in the same data source, you can also place the regular 
 * expression in the `error_text_pattern` of the connection configuration instead of configuring
 * each action separately.
 * 
 * Success messages can be handled similarly using `result_message_pattern`.
 * 
 * @author Andrej Kabachnik
 *
 */
class CallOData2Operation extends CallWebService 
{
    private $serviceName = null;
    
    private $urlParameterForRowData = null;
    
    protected function init()
    {
        parent::init();
        // TODO name, icon
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::buildUrl()
     */
    protected function buildUrl(DataSheetInterface $data, int $rowNr, string $method) : string
    {
        $url = parent::buildUrl($data, $rowNr, $method);
        if ($this->hasSeparateRequestsForEachRow() === false) {
            $paramName = $this->getUrlParameterForRowData();
            // Remove this special parameter from the URL. Remember, that it will always have an
            // empty value because of the special treatment in `prepareParamValue()` below.
            $url = str_replace("&{$paramName}=''", '', $url);
            $url = str_replace("?{$paramName}=''", '?', $url);
            // Now add the parameter with the correct data
            $url .= (strpos($url, '?') === false ? '?' : '') . "&{$paramName}='" . json_encode($data->getRows()) . "'";
        }
        return $url . (strpos($url, '?') === false ? '?' : '') . '&$format=json';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::prepareParamValue()
     */
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val)
    {
        // Make sure to return an empty string for the special row-data parameter as it will
        // be generated in `buildUrl()` above. We do not have access to the full data here!
        // Handling this special parameter here is also important to avoid errors if it is
        // marked as required in the OData metadata.
        if ($this->hasSeparateRequestsForEachRow() === false) {
            if ($this->getUrlParameterForRowData() === $parameter->getName()) {
                return "''";
            }
        }
        
        if ($parameter->hasDefaultValue() === true && $val === null) {
            $val = $parameter->getDefaultValue();
        }
        
        if ($parameter->isRequired() && $parameter->getDataType()->isValueEmpty($val)) {
            throw new ActionInputMissingError($this, 'Value of required parameter "' . $parameter->getName() . '" not set! Please include the corresponding column in the input data or use an input_mapper!', '75C7YOQ');
        }
        
        if ($val === null) {
            return "''";
        }
        
        $dataType = $parameter->getDataType();
        $odataType = $parameter->getCustomProperty(OData2JsonUrlBuilder::DAP_ODATA_TYPE);
        $val = $dataType->parse($val);
        
        return OData2JsonUrlBuilder::buildUrlFilterODataValue($val, $dataType, $odataType);
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
        
        $json = json_decode($body);
        $result = $json->d;
        if ($result instanceof \stdClass) {
            if ($result->results && is_array($result->results)) {
                // Decode JSON as assoc array again because otherwise the rows will remain objects.
                $rows = json_decode($body, true)['d']['results'];
            } else {
                $rows = [(array) $result];
            }
        } elseif (is_array($result)) {
            // Decode JSON as assoc array again because otherwise the rows will remain objects.
            $rows = json_decode($body, true)['d'];
        } else {
            throw new \RuntimeException('Invalid result data of type ' . gettype($result) . ': JSON object or array expected!');
        }
        
        $resultData->addRows($rows);
        
        return $resultData;
    }
    
    /**
     *
     * @return string
     */
    protected function getFunctionImportName() : string
    {
        return $this->getUrl();
    }
    
    /**
     * The URL endpoint of the opertation (name property of the FunctionImport).
     * 
     * @uxon-property function_import_name
     * @uxon-type string
     * 
     * @param string $value
     * @return CallOData2Operation
     */
    public function setFunctionImportName(string $value) : CallOData2Operation
    {
        return $this->setUrl($value);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::getMethod()
     */
    protected function getMethod($default = 'GET') : string
    {
        return parent::getMethod('GET');
    }
    
    /**
     * 
     * @throws ActionConfigurationError
     * @return string
     */
    protected function getUrlParameterForRowData() : string
    {
        if ($this->urlParameterForRowData === null && $this->hasSeparateRequestsForEachRow() === false) {
            throw new ActionConfigurationError($this, 'Missing action configuration: please set url_parameter_for_row_data to use separate_requests_for_each_row for OData operations (function imports)!');
        }
        return $this->urlParameterForRowData;
    }
    
    /**
     * Name of the url parameter to hold a JSON with the input row data of the action
     * 
     * For example, the below action would produce the following URL: 
     * `/sap/opu/odata/sap/ZXXX_SRV/myfunc?data='[{"SALESORDER":"90205830","SALESORDERPOSITION":"10"}, {...}]'`
     * 
     * ```
     *  {
     *      "function_import_name": "myfunc",
     *      "separate_requests_for_each_row": false,
     *      "url_parameter_for_row_data": "data"
     *  }
     *  
     * ```
     * 
     * @uxon-property url_parameter_for_row_data
     * @uxon-type string
     * 
     * @param string $value
     * @return CallOData2Operation
     */
    protected function setUrlParameterForRowData(string $value) : CallOData2Operation
    {
        $this->urlParameterForRowData = $value;
        return $this;
    }
}