<?php
namespace exface\UrlDataConnector\Interfaces;

use exface\Core\Interfaces\DataSources\DataQueryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for data queries to web services using the PSR-7 standard.
 *
 * @author Andrej Kabachnik
 *        
 */
interface psr7DataQueryInterface extends DataQueryInterface
{
    /**
     * @return RequestInterface
     */
    public function getRequest() : RequestInterface;

    /**
     * @return ResponseInterface|null
     */
    public function getResponse() : ?ResponseInterface;
}