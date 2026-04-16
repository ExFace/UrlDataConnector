<?php
namespace exface\UrlDataConnector\Interfaces;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for HTTP-based data connections, regulating headers, authentication, etc.
 *
 * @author Andrej Kabachnik
 *        
 */
interface HttpConnectionInterface extends UrlConnectionInterface
{    
    public function sendRequest(RequestInterface $request) : ?ResponseInterface;
    
    /**
     * Sets the proxy server address to be used.
     * Use name:port notation like 192.169.1.10:8080 or myproxy:8080.
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setProxy($value);
    
    /**
     * Set to TRUE to use cookies for this connection.
     * 
     * Defaults to FALSE.
     *
     * Cookies will be stored in the data folder of the current user!
     *
     * @param boolean $value
     * @return HttpConnectionInterface
     */
    public function setUseCookies(bool $value) : HttpConnectionInterface;
    
    /**
     * Enables or disables caching of HTTP requests.
     * Default: FALSE.
     *
     * @param boolean $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheEnabled($value);
    
    /**
     * Makes all requests get cached regardless of their headers.
     * Default: FALSE.
     *
     * If set to TRUE, this automatically sets the default cache lifetime to 60 seconds. Use
     * "cache_lifetime_in_seconds" to specify a custom value.
     *
     * @param boolean $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheIgnoreHeaders($value);
    
    /**
     * Sets the default lifetime for request cache items.
     *
     * @param integer $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheLifetimeInSeconds($value);
    
    /**
     * @return string
     */
    public function getFixedUrlParams() : string;
    
    /**
     * Adds specified params to every request: e.g. &format=json&ignoreETag=false.
     * 
     * @param string $fixed_params
     */
    public function setFixedUrlParams(string $fixed_params);

    /**
     * Returns the authentication provider used for this connection, if any.
     * 
     * @return HttpAuthenticationProviderInterface|NULL
     */
    public function getAuthProvider() : ?HttpAuthenticationProviderInterface;
}