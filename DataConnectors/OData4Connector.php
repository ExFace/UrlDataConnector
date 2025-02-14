<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\UrlDataConnector\ModelBuilders\OData4ModelBuilder;

/**
 * Connector for oData 4.0 web services
 * 
 * Refer to the documentation of the HttpConnector for detailed information about connection settings. 
 *
 * @author Andrej Kabachnik
 *        
 */
class OData4Connector extends OData2Connector
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\OData2Connector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new OData4ModelBuilder($this);
    }
}