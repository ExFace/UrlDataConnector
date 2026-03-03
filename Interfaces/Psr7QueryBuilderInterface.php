<?php
namespace exface\UrlDataConnector\Interfaces;

use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for query builders sending/reading HTTP messages following the PSR-7 standard.
 *
 * @author Andrej Kabachnik
 *        
 */
interface Psr7QueryBuilderInterface
{
    public function readResponse(RequestInterface $request, ResponseInterface $response) : DataQueryResultDataInterface;
}