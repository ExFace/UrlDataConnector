<?php
namespace exface\UrlDataConnector;

use exface\Core\CommonLogic\DataQueries\AbstractDataQuery;
use exface\UrlDataConnector\Interfaces\psr7DataQueryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;
use exface\Core\Widgets\DebugMessage;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugger;

class Psr7DataQuery extends AbstractDataQuery implements psr7DataQueryInterface
{

    private $request;

    private $response;
    
    private $fixedUrl = false;
    
    /**
     * Returns a fully instantiated data query with a PSR-7 request.
     * This is a shortcut for "new Psr7DataQuery(new Request)".
     *
     * @param string $method            
     * @param string|UriInterface $uri            
     * @param array $headers            
     * @param string|StreamInterface $body            
     * @param string $version            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public static function createRequest($method, $uri, array $headers = [], $body = null, $version = '1.1')
    {
        $request = new Request($method, $uri, $headers, $body, $version);
        return new self($request);
    }

    /**
     * Wraps a PSR-7 request in a data query, which can be used with the HttpDataConnector
     *
     * @param RequestInterface $request            
     */
    public function __construct(RequestInterface $request)
    {
        $this->setRequest($request);
    }

    /**
     * {@inheritDoc}
     * @see psr7DataQueryInterface::getRequest()
     */
    public function getRequest() : RequestInterface
    {
        return $this->request;
    }

    /**
     *
     * @param RequestInterface $value            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public function setRequest(RequestInterface $value)
    {
        $this->request = $value;
        return $this;
    }



    /**
     * {@inheritDoc}
     * @see psr7DataQueryInterface::getResponse()
     */
    public function getResponse() : ?ResponseInterface
    {
        return $this->response;
    }

    /**
     *
     * @param ResponseInterface $value            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public function setResponse(ResponseInterface $value)
    {
        $this->response = $value;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        if (null !== $request = $this->getRequest()) {
            $renderer = new HttpMessageDebugger($request, $this->getResponse(), 'Data request', 'Data response');
            $debug_widget = $renderer->createDebugWidget($debug_widget);
        }
        
        return $debug_widget;
    }
    
    /**
     * @return boolean
     */
    public function isUriFixed()
    {
        return $this->fixedUrl;
    }

    /**
     * @param boolean $fixedUrl
     * @return Psr7DataQuery
     */
    public function setUriFixed($true_or_false)
    {
        $this->fixedUrl = BooleanDataType::cast($true_or_false);
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::toString()
     */
    public function toString($prettify = true)
    {
        return $this->getRequest()->getUri()->__toString();
    }
}