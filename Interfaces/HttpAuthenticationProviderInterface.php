<?php
namespace exface\UrlDataConnector\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\Security\AuthenticationProviderInterface;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\CommonLogic\UxonObject;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for HTTP-based authentication providers
 *
 * @author Andrej Kabachnik
 *        
 */
interface HttpAuthenticationProviderInterface extends iCanBeConvertedToUxon, AuthenticationProviderInterface
{
    /**
     * Returns the Guzzle request options array with auth data to use with every regular request.
     * 
     * These options will normally be set as defaults for the Guzzle client
     * 
     * @link http://docs.guzzlephp.org/en/stable/request-options.html
     * 
     * @param array $defaultOptions
     * @return array
     */
    public function getDefaultRequestOptions(array $defaultOptions) : array;
    
    /**
     * Returns the UXON to save in the credentials storage after successful authentication
     * 
     * If the provider needs to store some user-specific or temporary secret information, it should be returned
     * here. It will then be saved to the encrypted credential storage automatically when `HttpConnector::authenticate()`
     * is called.
     * 
     * The UXON returned here has the same schema as the data connection. This means, you can overwrite any connection
     * properties here. The configuration from the credential storage and the general connection config are loaded
     * and merged when the connection is instantiated. If both have the same properties, the credentials win. So
     * the connection config is the base, while credentials are applied on-top if they exist (e.g. for specific users).
     * 
     * The general workflow is as follows:
     * 
     * 1. Connection is loaded from the model. Eventually existing stored credentials overwrite parts of the config.
     * 2. An HTTP request is sent through the connection
     * 3. `$provider->signRequest()` is called. If the provider finds out, that some authentication logic needs to
     * be triggered, it can call `$this->getConnection()->authenticate()`. Otherwise, it should just add headers or
     * certificates to the request. The provider can decide here the result of the authentication should be saved
     * as a credentials set and if that should be public or private for a certain user.
     * 4. The connector calls `$provider->authenticate()`
     * 5. The provider performs authentication and returns an instance of one of the auth token classes containing
     * all secrets required for signing requests
     * 6. The connector asks the provider, what secrets are to be saved to the credential storage by calling 
     * `$provider->getCredentialsUxon()`. The auth toke above is passed as argument.
     * 7. The credentials are saved to the credential store
     * 8. The next time this connection is instantiated the credential information is applied to the config, so
     * anything stored there becomes available to the connection and the provider right from the start.
     *
     * NOTE: you will mostly need to include the `authenticaton` object here. Make sure it contains everything you
     * need to perform the authentication because it will completely overwrite the `authentication` property in
     * the connection configuration. Having all auth options in one place is generally a good idea to ensure, that
     * when the global connection config changes, no unexpected merge side effects occur.
     * 
     * @param AuthenticationTokenInterface $authenticatedToken
     * @return UxonObject
     */
    public function getCredentialsUxon(AuthenticationTokenInterface $authenticatedToken) : UxonObject;
    
    /**
     * Allows to add headers or certificates to every request sent through the HTTP connection
     * 
     * @param RequestInterface $request
     * @return RequestInterface
     */
    public function signRequest(RequestInterface $request) : RequestInterface;
    
    /**
     * Returns TRUE if the response needs authentication (e.g. to show a login form) and FALSE otherwise.
     * 
     * @param ResponseInterface $response
     * @return bool
     */
    public function isResponseUnauthenticated(ResponseInterface $response) : bool;
    
    /**
     * 
     * @return HttpConnectionInterface
     */
    public function getConnection() : HttpConnectionInterface;
}