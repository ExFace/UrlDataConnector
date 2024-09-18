<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\DataConnectors\Traits\IDoNotSupportTransactionsTrait;
use exface\UrlDataConnector\Interfaces\UrlConnectionInterface;

/**
 * Connector for Websites, Webservices and other data sources accessible via HTTP, HTTPS, FTP, etc.
 *
 * @author Andrej Kabachnik
 *        
 */
abstract class AbstractUrlConnector extends AbstractDataConnector implements UrlConnectionInterface
{
    use IDoNotSupportTransactionsTrait;

    private $url = null;

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performDisconnect()
     */
    protected function performDisconnect()
    {
        // TODO
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getLastError()
     */
    function getLastError($conn = NULL)
    {
        if ($this->last_request) {
            $error = "Status code " . $this->last_request->getStatusCode() . "\n" . $this->last_request->getBody();
        } else {
            $error = $this->last_error;
        }
        return $error;
    }

    public function getUrl()
    {
        return $this->url;
    }

    /**
     * The base URL for this connection - it will be prepended to every data address accessed here.
     *
     * If a base URL is set, data addresses of meta objects from this data source should be relative URL!
     *
     * @uxon-property url
     * @uxon-type uri
     * @uxon-required true
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUrl($value)
    {
        $this->url = $value;
        return $this;
    }
}
?>