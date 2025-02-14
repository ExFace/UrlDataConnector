<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\UrlDataConnector\ModelBuilders\OData2ModelBuilder;
use exface\Core\CommonLogic\UxonObject;
use exface\UrlDataConnector\DataConnectors\Authentication\HttpBasicAuth;

/**
 * Connector for oData 2.0 web services
 * 
 * Refer to the documentation of the HttpConnector for detailed information about connection settings. 
 *
 * @author Andrej Kabachnik
 *        
 */
class OData2Connector extends HttpConnector
{
    private $metadataUrl = null;
    
    private $useBatchRequests = false;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new OData2ModelBuilder($this);
    }
    
    /**
     * @return string
     */
    public function getMetadataUrl()
    {
        if (is_null($this->metadataUrl)) {
            return rtrim($this->getUrl(), '/') . '/$metadata';
        }
        return $this->metadataUrl;
    }

    /**
     * A custom URL of the $metadata endpoint.
     * 
     * If not set, [#url#]/$metadata will be used automatically.
     * 
     * @uxon-property metadata_url
     * @uxon-type uri
     * 
     * @param string $metadataUrl
     */
    public function setMetadataUrl($metadataUrl)
    {
        $this->metadataUrl = $metadataUrl;
        return $this;
    }
    
    protected function getAuthProviderConfig() : ?UxonObject
    {
        $uxon = parent::getAuthProviderConfig();
        
        if ($uxon !== null && is_a($uxon->getProperty('class'), '\\' . HttpBasicAuth::class, true) && $uxon->hasProperty('authentication_url') === false) {
            $uxon->setProperty('authentication_url', $this->getMetadataUrl());
        }
        
        return $uxon;
    }
    
    /**
     *
     * @return bool
     */
    public function getUseBatchRequests() : bool
    {
        return $this->useBatchRequests;
    }
    
    /**
     * Set to TRUE if your OData-server supports $batch requests.
     * 
     * If set to `true` separate operations will be combined into a single $batch request
     * if possible. Additionally all CREATE/UPDATE/DELETE operations of a data transaction
     * will be put into a single ChangeSet. 
     * 
     * See https://www.odata.org/documentation/odata-version-2-0/batch-processing/ for 
     * technical detals.
     * 
     * @uxon-property use_batch_requests
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @link https://www.odata.org/documentation/odata-version-2-0/batch-processing/
     * 
     * @param bool $value
     * @return OData2Connector
     */
    public function setUseBatchRequests(bool $value) : OData2Connector
    {
        $this->useBatchRequests = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\AbstractUrlConnector::getUrl()
     */
    public function getUrl()
    {
        $url = parent::getUrl();
        return rtrim($url, "/") . '/';
    }
}