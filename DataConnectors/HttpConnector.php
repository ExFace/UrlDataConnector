<?php
namespace exface\UrlDataConnector\DataConnectors;

use GuzzleHttp\Client;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\Core\Exceptions\DataSources\DataConnectionFailedError;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use exface\Core\CommonLogic\Filemanager;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\Psr6CacheStorage;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use GuzzleHttp\Exception\RequestException;
use exface\UrlDataConnector\Exceptions\HttpConnectorRequestError;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\DataTypes\StringDataType;
use GuzzleHttp\Psr7\Uri;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use exface\UrlDataConnector\ModelBuilders\SwaggerModelBuilder;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\UserInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\CommonLogic\UxonObject;
use Psr\Http\Message\RequestInterface;
use exface\Core\Interfaces\Selectors\UserSelectorInterface;
use exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface;
use exface\UrlDataConnector\DataConnectors\Authentication\HttpBasicAuth;
use GuzzleHttp\Cookie\CookieJarInterface;
use exface\Core\Events\Security\OnAuthenticationFailedEvent;
use exface\UrlDataConnector\ModelBuilders\GenericUrlModelBuilder;
use exface\Core\DataTypes\LogLevelDataType;

/**
 * Connector for Websites, Webservices and other data sources accessible via HTTP, HTTPS, FTP, etc.
 * 
 * Performs data source queries by sending HTTP requests via Guzzle PHP library. Can use
 * Swagger/OpenAPI service descriptions to generate a metamodel.
 * 
 * ## URLs
 * 
 * A base `url` can be configured. It will be used whenever a query builder produces relative
 * URLs. If the connection can be used for multiple meta object, it is a good idea to use
 * a base `url` instead of absolute URLs in the object's data addresses.
 * 
 * Similarly, static URL parameters can be added via `fixed_url_params`.
 * 
 * ## Headers
 * 
 * The connector attempts to set appropriate headers automatically, but you can also add your own
 * to `headers` if you like. Apart from that the following properties also affect headers:
 * 
 * - `authentication` - see below
 * - `headers_calculate_content_length` - force to calculate (or not) the content length explicitly.
 * If not set, the Content-Length header will probably be added automatically by the OS-level cURL
 * library.
 * - `send_request_id_with_header` - will send the current request id with the provided header
 * 
 * ## Authentication
 * 
 * This connector supports extensible authentication provider plugins. They can be configured
 * in the `authentication` property providing a plugin PHP class and a set of configuration
 * properties supported by this class: e.g. 
 * 
 * ```
 * {
 *  "url": "...",
 *  "authentication": {
 *    "class": "\\exface\\UrlDataConnector\\DataConnectors\\Authentication\\HttpBasicAuth",
 *    "user": "",
 *    "password": ""
 *  }
 * }
 * 
 * ```
 * 
 * The most common "basic authentication" can also be activated simply by setting
 * `user` and `password` properties for the connection itself - as a simple alternative
 * to the above example. Be careful not to mix the two approaches!!!
 * 
 * ```
 * {
 *  "url": "...",
 *  "user": "",
 *  "password": ""
 * }
 * 
 * ```
 * 
 * If the data source can block users or IPs after X unseccessful login attempts, set
 * `authentication_retry_after_fail` to `false` to avoid silently trying the same set of
 * invalid credentials within within a single user session. In this case, a login prompt 
 * is displayed automatically after the first 401-response - **before** making a request
 * (as long as the credentials did not change, of course).
 * 
 * ## Caching
 * 
 * Can cache responses. By default caching is disabled. Use `cache_enabled` to turn it on
 * and ther `cache_*` properties for configuration.
 * 
 * ## cURL options
 * 
 * Under the hood, the HttpConnector uses the well known Guzzle library, which in-turn relies
 * on cURL. You can set a lot of [cURL options](https://www.php.net/manual/en/curl.constants.php#constant.curlopt-abstract-unix-socket) 
 * in `curl_options` if you like
 * 
 * ## Error handling
 * 
 * Apart from the regular data source error handling, this connector can show server error
 * messages and codes directly (instead of using error messages from the model). This is
 * very helpful if the server application can yield well-readale (non-technical) error
 * messages. Set `error_text_use_as_message_title` to `true` to display server errors
 * directly.
 * 
 * Similarly, a static `error_code` can be configured for all errors from this connection
 * instead of using the built-in (very general) error codes.
 * 
 * ## Cookies
 * 
 * Cookies are disabled by default, but can be stored for logged-in users if `use_cookies`
 * is set to `true`.
 * 
 * ## Development and debugging
 * 
 * It can be pretty hard to find the right data addresses for responses of external web services.
 * To help speed things up a bit, there is a `debug` mode, that allows to force the connector
 * to fake responses and always respond with whatever is defined in `debug_response`. This way 
 * different situations can be easily simulated without actually connecting to the remote and 
 * riscing errors, timeouts, etc.
 * 
 * You can leave `debug_response` in your config and just change `debug` to quickly switch between
 * debug mode and normal operation.
 *
 * @author Andrej Kabachnik
 *        
 */
class HttpConnector extends AbstractUrlConnector implements HttpConnectionInterface
{
    private $user = null;
    private $password = null;
    
    // Errors
    private $errorTextPattern = null;
    private $errorTextUseAsMessageTitle = null;
    private $errorCode = null;
    private $errorCodePattern = null;
    private $errorLevelPatterns = [];

    // Guzzle & its options
    private $client;
    private $proxy = null;
    private $timeout = null;
    private $curlOpts = [];
    
    // Cookies
    private $use_cookies = false;
    private $use_cookie_sessions = false;
    private $cookieJar = null;

    // Cache
    private $cache_enabled = false;
    private $cache_ignore_headers = false;
    private $cache_lifetime_in_seconds = 0;
    
    // Headers
    private $headers = [];
    private ?bool $headersCalcContentLength = null;
    private $sendRequestIdWithHeader = null;
    
    // Debug mode with fake responses
    private $debug = false;
    private $debugResponse = null;
    
    // Authentication    
    private $authProvider = null;
    private $authProviderUxon = null;
    private bool $authenticationRetryAfterFail = true;

    private $fixed_params = '';

    private $swaggerUrl = null;
    
    /**
     * Calls the authentication provider configured for this connection explicitly and saves secrets to credential storage if required
     * 
     * If the auth provider supports the built-in credential storage, it will return some confidential information
     * in `$authProvider->getCredentialsUxon()` - this will be stored in the credential storage automatically
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token, bool $updateUserCredentials = true, UserInterface $credentialsOwner = null, bool $credentialsArePrivate = null) : AuthenticationTokenInterface
    {
        // In order to authenticate, we need an authentication provider. Thus, if there is none
        // defined, we create a default one.
        if (! $authProvider = $this->getAuthProvider()) {
            $this->setAuthentication($this->createDefaultAuthConfig());
            $authProvider = $this->getAuthProvider();
        } 
        
        $this->markCredentialsInvalid(false);
        
        $authenticatedToken = $authProvider->authenticate($token);
        
        if ($updateUserCredentials === true && $authenticatedToken) {
            $credentialSetName = ($authenticatedToken->getUsername() ? $authenticatedToken->getUsername() : 'No username') . ' - ' . $this->getName();
            $this->saveCredentials($authProvider->getCredentialsUxon($authenticatedToken), $credentialSetName, $credentialsOwner, $credentialsArePrivate);
        }
        
        return $authenticatedToken;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $container, bool $saveCredentials = true, UserSelectorInterface $credentialsOwner = null) : iContainOtherWidgets
    {
        if (! $this->hasAuthentication()) {
            return parent::createLoginWidget($container);
        }
        
        $form = $this->createLoginForm($container, $saveCredentials, $credentialsOwner);
        $container->addWidget($this->getAuthProvider()->createLoginWidget($form));
        
        return $container;
    }
    
    /**
     * Returns the initialized Guzzle client
     * 
     * @return Client
     */
    protected function getClient() : Client
    {
        if ($this->client === null) {
            $this->connect();
        }
        return $this->client;
    }
    
    /**
     * 
     * @param Client $client
     * @return HttpConnector
     */
    protected function setClient(Client $client) : HttpConnector
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Initializes the Guzzle client with it's default config options like base URI, proxy settings, etc.
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performConnect()
     */
    protected function performConnect()
    {
        $defaults = array();
        $defaults['verify'] = false;
        $defaults['base_uri'] = $this->getUrl();
        // Proxy settings
        if ($this->getProxy()) {
            $defaults['proxy'] = $this->getProxy();
        }
        
        // Authentication
        if ($authProvider = $this->getAuthProvider()) {
            $defaults = $authProvider->getDefaultRequestOptions($defaults);
            if ($this->getAuthenticationRetryAfterFail() === false) {
                if ($this->isCredentialsMarkedInvalid()) {
                    throw new AuthenticationFailedError($this, 'The current login data did not work last time - please log in manually to avoid being blocked by the data source after multiple failed attempts!', '7EO5HDU');
                }
                $this->getWorkbench()->eventManager()->addListener(OnAuthenticationFailedEvent::getEventName(), [$this, 'handleOnAuthenticationFailedEvent']);
            }
        }
        
        // Cookies
        if ($this->getUseCookies()) {
            $cookieFile = str_replace(array(
                ':',
                '/',
                '.'
            ), '', $this->getUrl()) . '.cookie';
            $cookieDir = $this->getWorkbench()->getContext()->getScopeUser()->getUserDataFolderAbsolutePath() . DIRECTORY_SEPARATOR . '.cookies';
            if (! file_exists($cookieDir)) {
                mkdir($cookieDir);
            }
            
            if ($this->getUseCookieSessions() === true) {
                if ($this->getWorkbench()->getSecurity()->getAuthenticatedToken()->isAnonymous() === true) {
                    $err = new DataConnectionFailedError($this, 'Cannot use session cookies for HTTP connection "' . $this->getAlias() . '": user not logged on!');
                    $this->getWorkbench()->getLogger()->logException($err);
                }
                $storeSessionCookies = true;
            } else {
                $storeSessionCookies = false;
            }
            $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieDir . DIRECTORY_SEPARATOR . $cookieFile, $storeSessionCookies);
            $this->cookieJar = $cookieJar;
            $defaults['cookies'] = $cookieJar;
        } elseif ($this->getUseCookieSessions() === true) {
            throw new DataConnectionConfigurationError($this, 'Cannot set use_cookie_sessions=true if use_cookies=false for HTTP connection alias "' . $this->getAlias() . '"!');
        }
        
        // Cache
        if ($this->getCacheEnabled()) {
            // Create default HandlerStack
            $stack = HandlerStack::create();
            
            if ($this->getCacheIgnoreHeaders()) {
                $cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\GreedyCacheStrategy';
            } else {
                $cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\PrivateCacheStrategy';
            }
            
            // Add cache middleware to the top with `push`
            $stack->push(new CacheMiddleware(new $cache_strategy_class(new Psr6CacheStorage(new FilesystemAdapter('', 0, $this->getCacheAbsolutePath())), $this->getCacheLifetimeInSeconds())), 'cache');
            
            // Initialize the client with the handler option
            $defaults['handler'] = $stack;
        }
        
        // headers
        if (! empty($this->getHeaders())) {
            $defaults['headers'] = $this->getHeaders();
        }
        
        // Timeout
        if ($this->getTimeout() !== null) {
            $defaults['timeout'] = $this->getTimeout();
        }
        
        // cURL options
        if (! empty($this->getCurlOptions())) {
            $defaults['curl'] = $this->getCurlOptions();
        }
        
        try {
            $this->setClient(new Client($defaults));
        } catch (\Throwable $e) {
            throw new DataConnectionFailedError($this, "Failed to instantiate HTTP client: " . $e->getMessage(), '6T4RAVX', $e);
        }
    }
    
    /**
     * Returns TRUE if the client is initialized and ready to perform queries.
     * 
     * @return bool
     */
    public function isConnected() : bool
    {
        return $this->client !== null;
    }

    /**
     * Sends the request contained by the given Psr7DataQuery using the Guzzle Client from performConnect()
     * 
     * Overview:
     * 
     * - Check if query should not be sent via `$this->willIgnore()`
     * - Add more headers via `$this->addDefaultHeadersToQuery()`
     * - `connect()` if not yet connected
     * - Enrich request by adding base URL, fixed URL parameters, etc. - see `prepareRequest()`
     * - Send the request
     * - On error pass the response to the $query if possible and throw `$this->createResponseException()`
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performQuery()
     *
     * @param Psr7DataQuery $query            
     * @return Psr7DataQuery
     */
    protected function performQuery(DataQueryInterface $query)
    {
        if (! ($query instanceof Psr7DataQuery)) {
            throw new DataConnectionQueryTypeError($this, 'Connector "' . $this->getAliasWithNamespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
        }
        /* @var $query \exface\UrlDataConnector\Psr7DataQuery */
        
        // Default Headers zur Request hinzufuegen, um sie im Tracer anzuzeigen.
        $query = $this->addDefaultHeadersToQuery($query);
        if ($this->willIgnore($query) === true) {
            $query->setResponse(new Response());
        } else {
            if ($this->isConnected() === false) {
                $this->connect();
            }
            try {
                if (! $query->isUriFixed() && $this->getFixedUrlParams()) {
                    $uri = $query->getRequest()->getUri();
                    $query->setRequest($query->getRequest()->withUri($uri->withQuery($uri->getQuery() . $this->getFixedUrlParams())));
                }
                $request = $query->getRequest();
                $request = $this->prepareRequest($request);
                
                if ($this->isDebugMode()) {
                    $dbg = $this->getDebugResponse();
                    $dbgHeaders = $dbg->getProperty('headers') ? $dbg->getProperty('headers')->toArray() : [];
                    $dbgBody = $dbg->getProperty('body') instanceof UxonObject ? $dbg->getProperty('body')->toJson(true) : $dbg->getProperty('body');
                    $response = new Response($dbg->getProperty('status') ?? '200', $dbgHeaders, $dbgBody); 
                    if ($response->getStatusCode() >= 400) {
                        throw new RequestException('FAKE error from HTTP connector debug mode', $request, $response);
                    }
                } else {
                    $response = $this->getClient()->send($request);
                }
                
                $query->setResponse($response);
            } catch (\Throwable $re) {
                throw $this->createResponseException($query, $response ?? null, $re);
            }
        }
        return $query;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::sendRequest()
     */
    public function sendRequest(RequestInterface $request, array $requestOptions = []) : ?ResponseInterface
    {
        if ($this->isConnected() === false) {
            $this->connect();
        }
        try {
            $request = $this->prepareRequest($request);
            $response = $this->getClient()->send($request, $requestOptions);
        } catch (\Throwable $re) {
            $query = new Psr7DataQuery($request);
            throw $this->createResponseException($query, null, $re);
        }
        
        return $response;
    }
    
    /**
     * 
     * @param RequestInterface $request
     * @return RequestInterface
     */
    protected function prepareRequest(RequestInterface $request) : RequestInterface
    {
        // Handle relative URIs
        // First, add the base URI of the connection
        if ($request->getUri()->__toString() === '') {
            $baseUrl = $this->getUrl() ?? '';
            if ($endpoint = StringDataType::substringAfter($baseUrl, '/', '', false, true)) {
                $request = $request->withUri(new Uri($endpoint));
            }
        }
        
        // If the resulting URL (incl. connection base) is relative, assume it is relative
        // to the workbench. So we need to prepend the workbench URL to make it absolute and
        // thus suitable for Guzzle.
        $uri = $request->getUri();
        if ($uri->getHost() === '' && ! $this->getUrl()) {
            if (substr($uri->__toString(), 0, 1) !== '/') {
                // If the URI is relative to the current folder, simply prepend the URL
                // of the workench.
                $request = $request->withUri(new Uri($this->getWorkbench()->getUrl() . $uri->__toString()));
            } else {
                // If it is relative to the server root, add scheme, host and port from
                // the workbench URL
                $wbUri = new Uri($this->getWorkbench()->getUrl());
                $fullUri = $uri
                    ->withHost($wbUri->getHost())
                    ->withScheme($wbUri->getScheme())
                    ->withPort($wbUri->getPort());
                $request = $request->withUri($fullUri);
            }
        }
        
        // Add Content-Length header if requested
        if ($this->willHeadersCalculateContentLength() === true) {
            $request->withHeader('Content-length', strlen($request->getBody()->__toString()));
        }
        
        // Add the X-Request-ID header if required
        if ($requestIdHeader = $this->getSendRequestIdWithHeader()) {
            $request = $request->withHeader($requestIdHeader, $this->getWorkbench()->getContext()->getScopeRequest()->getRequestId());
        }
        
        // Now add everything related to authentication by passing the request to the auth
        // provider.
        if ($this->hasAuthentication()) {
            $request = $this->getAuthProvider()->signRequest($request);
        }
        return $request;
    }
    
    /**
     * 
     * @param Psr7DataQuery $query
     * @param ResponseInterface $response
     * @param \Throwable $exceptionThrown
     * @return \exface\UrlDataConnector\Exceptions\HttpConnectorRequestError
     */
    protected function createResponseException(Psr7DataQuery $query, ResponseInterface $response = null, \Throwable $exceptionThrown = null)
    {
        // Save the real request including base URL, headers, etc.
        if ($exceptionThrown instanceof RequestException || $exceptionThrown instanceof ConnectException) {
            $query->setRequest($exceptionThrown->getRequest());
        }
        // Get the response from the exception if not provided explicitly
        if ($response === null && $exceptionThrown instanceof RequestException && null !== $response = $exceptionThrown->getResponse()) {
            $query->setResponse($response);
        } 
        // Try to get some information from the response
        if ($response !== null) {
            $message = $this->getResponseErrorText($response, $exceptionThrown);
            $code = $this->getResponseErrorCode($response, $exceptionThrown);
            $level = $this->getResponseErrorLevel($response);
            $ex = new HttpConnectorRequestError($query, $response->getStatusCode(), $response->getReasonPhrase(), $message, $code, $exceptionThrown);
            $useAsTitle = false;
            if ($this->getErrorTextUseAsMessageTitle() === true) {
                $useAsTitle = true;
            } elseif ($this->getErrorTextUseAsMessageTitle() === null) {
                if ($exceptionThrown !== null && $exceptionThrown->getMessage() !== $message) {
                    $useAsTitle = true;
                }
            }
            if ($useAsTitle === true) {
                $ex->setUseRemoteMessageAsTitle(true);
            }
            
            // Wrap the error in an authentication-exception if login failed.
            // This will give facades the option to show a login-screen.
            if ($this->hasAuthentication()) {
                $authFailed = $this->getAuthProvider()->isResponseUnauthenticated($response);
            } else {
                $authFailed = $response->getStatusCode() == 401;
            }
            if ($authFailed && ! ($exceptionThrown instanceof AuthenticationFailedError)) {
                $ex = $this->createAuthenticationException($ex, $message);
            } else {
                if ($level !== null) {
                    $ex->setLogLevel($level);
                }
            }
        } else {
            $ex = new HttpConnectorRequestError($query, 0, 'No Response from Server', $exceptionThrown->getMessage(), null, $exceptionThrown);
        }
        
        return $ex;
    }
    
    protected function createAuthenticationException(\Throwable $exceptionThrown = null, string $message = null) : AuthenticationFailedError
    {
        // If no authentication is configured (but obviously it's needed), assume basic auth.
        // If not done so and the facade chooses to render a login-form, that form will be
        // empty.
        if ($this->hasAuthentication() === false) {
            $this->setAuthentication($this->createDefaultAuthConfig());
        }
        
        if ($message === null) {
            if ($exceptionThrown !== null) {
                $message = $exceptionThrown->getMessage();
            } else {
                $message = 'Cannot authenticate in connection "' . $this->getAliasWithNamespace() . '"!';
            }
        }
        
        return new AuthenticationFailedError($this, 'Authentication failed for data connection "' . $this->getName() . '": ' . $message, null, $exceptionThrown);
    }
    
    /**
     * Returns the UXON configuration for the authentication provider to be used if none was 
     * defined explicitly, but one is required in the current situation.
     * @return UxonObject
     */
    protected function createDefaultAuthConfig() : UxonObject
    {
        $uxon = new UxonObject([
            'class' => '\\' . HttpBasicAuth::class
        ]);
        if ($this->user !== null) {
            $uxon->setProperty('user', $this->user);
        }
        if ($this->password !== null) {
            $uxon->setProperty('password', $this->password);
        }
        return $uxon;
    }
    
    /**
     * Adds the default headers (defined for the Guzzle client in connect()), to 
     * the request inside the query. 
     * 
     * Mainly used to make the headers appear in logs, etc.
     * 
     * @param Psr7DataQuery $query
     * @return Psr7DataQuery
     */
    protected function addDefaultHeadersToQuery(Psr7DataQuery $query)
    {
        $requestHeaders = $query->getRequest()->getHeaders();
        $clientHeaders = $this->getClient()->getConfig('headers');
        $clientHeaders = Utils::caselessRemove(array_keys($requestHeaders), $clientHeaders);
        $query->setRequest(Utils::modifyRequest($query->getRequest(), ['set_headers'=> $clientHeaders]));
        return $query;
    }

    /**
     * @deprecated use getAuthProvider()->getUser() instead
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getUser()
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * The user name for HTTP basic authentification.
     * 
     * WARNING: Don't use this together with `authentication` - the latter will always override!
     * 
     * This property accepts either a value (i.e. the username itself) or a static formula to
     * calculate it - e.g. `=User('USERNAME')`
     * 
     * If set, the `authentication` will be automatically configured to use HTTP basic authentication.
     * If you need any special authentication options, use `authentication` instead. This option
     * here is just a handy shortcut!
     * 
     * @uxon-property user
     * @uxon-type string
     * 
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    protected function setUser($value)
    {
        $this->user = $value;
        return $this;
    }

    /**
     * @deprecated use getAuthProvider()->getPassword() instead
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getPassword()
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Sets the password for basic HTTP authentification.
     * 
     * WARNING: Don't use this together with `authentication` - the latter will always override!
     * 
     * This property accepts either a value (i.e. the password itself) or a static formula to
     * calculate it - e.g. `=GetConfig('...')`
     * 
     * If set, the `authentication` will be automatically configured to use HTTP basic authentication.
     * If you need any special authentication options, use `authentication` instead. This option
     * here is just a handy shortcut!
     * 
     * @uxon-property password
     * @uxon-type password|metamodel:formula
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    protected function setPassword($value)
    {
        $this->password = $value;
        return $this;
    }

    /**
     * Returns the proxy address to be used in the name:port notation: e.g.
     * 192.169.1.10:8080 or myproxy:8080.
     *
     * @return string
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * Proxy server address to be used - e.g. myproxy:8080 or 192.169.1.10:8080.
     *
     * @uxon-property proxy
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setProxy($value)
    {
        $this->proxy = $value;
        return $this;
    }

    /**
     *
     * @return boolean
     */
    public function getUseCookies() : bool
    {
        return $this->use_cookies;
    }

    /**
     * Set to TRUE to use cookies for this connection.
     *
     * Cookies will be stored in the data folder of the current user!
     * 
     * NOTE: session cookies will not be stored unless `use_cookie_sessions` is
     * also explicitly set to `TRUE`!
     *
     * @uxon-property use_cookies
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUseCookies(bool $value) : HttpConnectionInterface
    {
        $this->use_cookies = $value;
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    public function getUseCookieSessions() : bool
    {
        return $this->use_cookie_sessions;
    }
    
    /**
     * Set to TRUE to store session cookies too.
     * 
     * This option can only be used if `use_cookies` is `TRUE`.
     *
     * @uxon-property use_cookie_sessions
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUseCookieSessions(bool $value) : HttpConnectionInterface
    {
        $this->use_cookie_sessions = $value;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getCacheEnabled()
     */
    public function getCacheEnabled()
    {
        return $this->cache_enabled;
    }

    /**
     * Enables (TRUE) or disables (FALSE) caching of HTTP requests.
     * 
     * Use `cache_lifetime_in_seconds`, `cache_ignore_headers`, etc. for futher cache
     * customization.
     *
     * @uxon-property cache_enabled
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheEnabled($value)
    {
        $this->cache_enabled = \exface\Core\DataTypes\BooleanDataType::cast($value);
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getCacheIgnoreHeaders()
     */
    public function getCacheIgnoreHeaders()
    {
        return $this->cache_ignore_headers;
    }

    /**
     * Set to TRUE to cache all requests regardless of their cache-control headers.
     *
     * If set to TRUE, this automatically sets the default cache lifetime to 60 seconds. Use
     * "cache_lifetime_in_seconds" to specify a custom value.
     *
     * @uxon-property cache_ignore_headers
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheIgnoreHeaders($value)
    {
        $this->cache_ignore_headers = \exface\Core\DataTypes\BooleanDataType::cast($value);
        if ($this->getCacheIgnoreHeaders()) {
            $this->setCacheLifetimeInSeconds(60);
        }
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getCacheLifetimeInSeconds()
     */
    public function getCacheLifetimeInSeconds()
    {
        return $this->cache_lifetime_in_seconds;
    }

    /**
     * How long a cached URL is concidered up-to-date.
     * 
     * NOTE: This only works if the cache is enabled via `cache_enabled: true`.
     *
     * @uxon-property cache_lifetime_in_seconds
     * @uxon-type integer
     *
     * @param integer $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheLifetimeInSeconds($value)
    {
        $this->cache_lifetime_in_seconds = intval($value);
        return $this;
    }

    /**
     * 
     * @return string
     */
    protected function getCacheAbsolutePath()
    {
        $path = Filemanager::pathJoin(array(
            $this->getWorkbench()->filemanager()->getPathToCacheFolder(),
            $this->getAliasWithNamespace()
        ));
        if (! file_exists($path)) {
            mkdir($path);
        }
        return $path;
    }
    
    /**
     * 
     * @return string
     */
    public function getFixedUrlParams() : string
    {
        return $this->fixed_params;
    }

    /**
     * Adds specified params to every request: e.g. &format=json&ignoreETag=false.
     * 
     * @uxon-property fixed_url_params
     * @uxon-type string
     * 
     * @param string $fixed_params
     * @return HttpConnectionInterface
     */
    public function setFixedUrlParams(string $fixed_params)
    {
        $this->fixed_params = $fixed_params;
        return $this;
    }
    
    /**
     * Extracts the message text from an error-response.
     * 
     * Override this method to get more error details from the response body or headers
     * depending on the protocol used in a specific connector.
     *
     * @param ResponseInterface $response
     * @return string
     */
    protected function getResponseErrorText(ResponseInterface $response, \Throwable $exceptionThrown = null) : string
    {
        if (null !== $pattern = $this->getErrorTextPattern()) {
            $body = $response->getBody()->__toString();
            $matches = [];
            preg_match($pattern, $body, $matches);
            if (empty($matches) === false) {
                return $matches['message'] ?? $matches[1];
            }
        }
        
        if ($exceptionThrown !== null) {
            return strip_tags($exceptionThrown->getMessage());
        }
        return $response->getReasonPhrase();
    }
    
    /**
     * Returns the application-specifc error code provided by the server or NULL if no meaningful error code was sent.
     * 
     * If static `error_code` is defined for this connection, it will be returned by default.
     * 
     * @param ResponseInterface $response
     * @param \Throwable $exceptionThrown
     * @return string|NULL
     */
    protected function getResponseErrorCode(ResponseInterface $response, \Throwable $exceptionThrown = null) : ?string
    {
        if (null !== $pattern = $this->getErrorCodePattern()) {
            $body = $response->getBody()->__toString();
            $matches = [];
            preg_match($pattern, $body, $matches);
            if (empty($matches) === false) {
                return $matches['code'] ?? $matches[1];
            }
        }
        
        return $this->getErrorCode();
    }
    
    /**
     * 
     * @param ResponseInterface $response
     * @return string|NULL
     */
    protected function getResponseErrorLevel(ResponseInterface $response) : ?string
    {
        $body = $response->getBody()->__toString();
        foreach ($this->getErrorLevelPatterns() as $level => $pattern) {
            if ($pattern === null || $pattern === '') {
                continue;
            }
            $matches = [];
            preg_match($pattern, $body, $matches);
            if (empty($matches) === false) {
                return LogLevelDataType::cast($level);
            }
        }
        return null;
    }
    
    /**
     * 
     * @param Psr7DataQuery $query
     * @return bool
     */
    protected function willIgnore(Psr7DataQuery $query) : bool
    {
        return $query->getRequest()->getUri()->__toString() ? false : true;
    }
    
    /**
     *
     * @return string|NULL
     */
    public function getSwaggerUrl() : ?string
    {
        return $this->swaggerUrl;
    }
    
    /**
     * URL of the Swagger/OpenAPI description of the web service (if available).
     * 
     * If used, the metamodel can be generated from the Swagger description using
     * the SwaggerModelBuilder.
     * 
     * @uxon-property swagger_url
     * @uxon-type uri
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setSwaggerUrl(string $value) : HttpConnector
    {
        $this->swaggerUrl = $value;
        return $this;
    }
    
    /**
     * 
     * @return bool
     */
    public function hasSwagger() : bool
    {
        return $this->swaggerUrl !== null;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        if ($this->hasSwagger()) {
            return new SwaggerModelBuilder($this);
        }
        return new GenericUrlModelBuilder($this);
    }
    
    /**
     * Returns the server root of the URL.
     * 
     * E.g. http://www.mydomain.com for http://www.mydomain.com/path.
     * 
     * @return string
     */
    public function getUrlServerRoot() : string
    {
        $parts = parse_url($this->getUrl());
        $port = ($parts['port'] ? ':' . $parts['port'] : '');
        if ($parts['user'] || $parts['pass']) {
            $auth = $parts['user'] . ($parts['pass'] ? ':' . $parts['pass'] : '') . '@';
        } else {
            $auth = '';
        }
        return $parts['scheme'] . '://' . $auth . $parts['host'] . $port;
    }
    
    /**
     *
     * @return string|NULL
     */
    protected function getErrorTextPattern() : ?string
    {
        return $this->errorTextPattern;
    }
    
    /**
     * Regular expression to extract the error message from the error response.
     * 
     * By default, all server errors result in fairly general "webservice request failed" errors,
     * where the actual error message is part of the error details. This is good for technical APIs,
     * that provide exception-type errors or error codes. However, if the server can generate
     * error messages suitable for end users, you can extract them via `error_text_pattern` and
     * even use the as message titles, so they are always visible for users.
     * 
     * If an `error_text_pattern` is provided and a match was found, the remote error text
     * will be automatically placed in the title of the error message shown. To move it back to
     * the details, set `error_text_use_as_message_title` to `false` explicitly. In this case,
     * the remote error messages will still be only visible in the error details, but they will
     * be better formatted - this is a typical setting for technical APIs with structured errors
     * like `{"error": "message text"}`.
     * 
     * If a pattern is provided, it is applied to the body text of error responses and the first 
     * match or one explicitly named "message" is concidered to be the error text.
     * 
     * For example, if the web service would return the following JSON
     * `{"error":"Sorry, you are out of luck!"}`, you could use this regex to get the
     * message: `/"error":"(?<message>[^"]*)"/`.
     * 
     * @uxon-property error_text_pattern
     * @uxon-type string
     * @uxon-template /"error":"([^"]*)"/
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setErrorTextPattern(string $value) : HttpConnector
    {
        $this->errorTextPattern = $value;
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    protected function getErrorTextUseAsMessageTitle() : ?bool
    {
        return $this->errorTextUseAsMessageTitle;
    }
    
    /**
     * Set to TRUE/FALSE to place the remote error message in the title or the details of error messages displayed.
     * 
     * By default, all server errors result in fairly general "webservice request failed" errors,
     * where the actual error message is part of the error details. This is good for technical APIs,
     * that provide exception-type errors or error codes. However, if the server can generate
     * error messages suitable for end users, you can extract them via `error_text_pattern` and
     * even use the as message titles, so they are always visible for users.
     * 
     * @uxon-property error_text_use_as_message_title
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $value
     * @return HttpConnector
     */
    public function setErrorTextUseAsMessageTitle(bool $value) : HttpConnector
    {
        $this->errorTextUseAsMessageTitle = $value;
        return $this;
    }
    
    /**
     *
     * @return string|NULL
     */
    protected function getErrorCode() : ?string
    {
        return $this->errorCode;
    }
    
    /**
     * Set a static error code for all data source errors from this connection.
     * 
     * Sometimes it is very helpful to let the user know, that the error comes from a
     * specific connected system. Think of an error code like `SAP-ERROR`, `FACEBOOK-ERR`
     * or similar - something to give the user a hint of where the error happened.
     * 
     * You can even create an error message in the metamodel with this code and describe
     * there, what to do.
     * 
     * @uxon-property error_code
     * @uxon-type string
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setErrorCode(string $value) : HttpConnector
    {
        $this->errorCode = $value;
        return $this;
    }
    
    protected function getErrorCodePattern() : ?string
    {
        return $this->errorCodePattern;
    }
    
    /**
     * Use a regular expression to extract error codes from error responses - the first match is returned or one explicitly named "code".
     * 
     * By default, the action will use the error handler of the data connection to
     * parse error responses from the web service. This will mostly produce general
     * errors like "500 Internal Server Error". Using the `error_code_pattern`
     * you can tell the action where to look for the actual error code an use
     * it in the error it produces.
     * 
     * For example, if the web service would return the following JSON
     * `{"errorCode":"2","error":"Sorry!"}`, you could use this regex to get the
     * message: `/"errorCode":"(?<code>[^"]*)"/`.
     * 
     * @uxon-property error_code_pattern
     * @uxon-type string
     * @uxon-template /"errorCode":"([^"]*)"/
     *
     * @param string $value
     * @return HttpConnector
     */
     public function setErrorCodePattern(string $value) : HttpConnector
    {
        $this->errorCodePattern = $value;
        return $this;
    }
    
    /**
     * 
     * @return string[]
     */
    protected function getErrorLevelPatterns() : array
    {
        return $this->errorLevelPatterns;
    }
    
    /**
     * Use regular expressions to customize log levels for errors.
     * 
     * You can specify a regular expression for every log level here. If an error response body matches
     * one of them, the corresponding log level will be used. If multiple expressions match, the first
     * one is selected in order of definition.
     * 
     * @uxon-property error_level_patterns
     * @uxon-type object
     * @uxon-template {"WARNING": "", "ERROR": "", "CRITICAL": "", "ALERT": "", "EMERGENCY": ""}
     * 
     * @param array $uxonArray
     * @return HttpConnector
     */
    public function setErrorLevelPatterns(UxonObject $uxonArray) : HttpConnector
    {
        $this->errorLevelPatterns = $uxonArray->toArray();
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    protected function hasAuthentication() : bool
    {
        return $this->authProvider !== null || $this->authProviderUxon !== null || $this->user !== null || $this->password !== null;
    }
    
    /**
     * Set the authentication method this connection should use.
     *
     * @uxon-property authentication
     * @uxon-type \exface\UrlDataConnector\CommonLogic\AbstractHttpAuthenticationProvider
     * @uxon-template {"class": "\\exface\\UrlDataConnector\\DataConnectors\\Authentication\\HttpBasicAuth"}
     *
     * @param string $value
     * @return HttpConnector
     */
    public function setAuthentication($stringOrUxon) : HttpConnector
    {
        if ($stringOrUxon instanceof UxonObject) {
            $this->authProviderUxon = $stringOrUxon;
            $this->authProvider = null;
        } elseif (is_string($stringOrUxon)) {
            if (defined('static::AUTH_TYPE_' . mb_strtoupper($stringOrUxon)) === false) {
                throw new DataConnectionConfigurationError($this, 'Invalid value "' . $stringOrUxon . '" for connection property "authentication".');
            }
            $authType = mb_strtolower($stringOrUxon);
            $this->authProviderUxon = new UxonObject([
                'class' => '\\exface\\UrlDataConnector\\DataConnectors\\Authentication\\Http' . ucfirst($authType) . 'Auth'
            ]);
            $this->authProvider = null;
        } else {
            throw new DataConnectionConfigurationError($this, 'Invalid value "' . $stringOrUxon . '" for connection property "authentication".');
        }
        return $this;
    }
    
    /**
     * 
     * @return UxonObject|NULL
     */
    protected function getAuthProviderConfig() : ?UxonObject
    {
        // For simplicity, user/password can be set in the connection config directly.
        // This will result in the use of the default authentication type (HTTB basic auth).
        if ($this->authProviderUxon === null && ($this->user !== null || $this->hasAuthentication())) {
            $this->authProviderUxon = $this->createDefaultAuthConfig();
        }
        return $this->authProviderUxon;
    }
    
    /**
     * 
     * @throws DataConnectionConfigurationError
     * @return HttpAuthenticationProviderInterface|NULL
     */
    protected function getAuthProvider() : ?HttpAuthenticationProviderInterface
    {
        if ($this->authProvider === null) {
            $authConfig = $this->getAuthProviderConfig();
            if ($authConfig === null) {
                return null;
            }
            $providerClass = $authConfig->getProperty('class');
            if (! class_exists($providerClass)) {
                throw new DataConnectionConfigurationError($this, 'Invalid authentication configuration for data connection "' . $this->getName() . '" (' . $this->getAliasWithNamespace() . '): authentication provider class "' . $providerClass . '" not found!');
            }
                
            $this->authProvider = new $providerClass($this, $authConfig);
        }
        return $this->authProvider;
    }
    
    /**
     * 
     * @return CookieJarInterface|NULL
     */
    protected function getCookieJar() : ?CookieJarInterface
    {
        return $this->cookieJar;
    }
    
    /**
     * 
     * @return HttpConnector
     */
    protected function resetCookies() : HttpConnector
    {
        if ($this->getCookieJar() !== null) {
            $this->getCookieJar()->clear();
        }
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    protected function getAuthenticationRetryAfterFail() : bool
    {
        return $this->authenticationRetryAfterFail;
    }
    
    /**
     * Set to FALSE to prevent retrying stored credentials if an attempt failed previously.
     *
     * By default, the connector will attempt to query the data source whenever requested -
     * even if the current credentials were already rejected previously. Only if the current
     * connection attempt fails, a login prompt will be displayed. This means, that if the
     * credentials became outdated, the data source will register a failed login attempt every 
     * time. This may result in the user or the IP being blacklisted in some APIs.  
     * 
     * If this option is set to `false` the connector will remember, which credentials did not 
     * work and will not retry them again automatically - for the duration of the current session. 
     * This means, that after a 401-response is received, the user will get a login prompt **before** 
     * the data source is contacted untill a new authentication attempt succeeds (e.g. that login 
     * promt is submitted). 
     * 
     * After a successful authentication, the credentials will be used silently agian - regardless
     * of wether they actually changed or not.
     *
     * @uxon-property authentication_retry_after_fail
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return HttpBasicAuth
     */
    public function setAuthenticationRetryAfterFail(bool $value) : HttpConnectionInterface
    {
        $this->authenticationRetryAfterFail = $value;
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    protected function getAuthRetryDisabledVar() : string
    {
        return 'http_auth_block_' . $this->getId();
    }
    
    /**
     * 
     * @return string
     */
    protected function getAuthRetryDisabledHash() : string
    {
        return md5(json_encode($this->getAuthProvider()->getDefaultRequestOptions([])));
    }
    
    /**
     * 
     * @return bool
     */
    protected function isCredentialsMarkedInvalid() : bool
    {
        if (! $this->hasAuthentication()) {
            return false;
        }
        
        $hash = $this->getAuthRetryDisabledHash();
        $ctxtScope = $this->getWorkbench()->getContext()->getScopeSession();
        return $ctxtScope->getVariable($this->getAuthRetryDisabledVar()) === $hash;
    }
    
    /**
     * 
     * @param bool $trueOrFalse
     * @return HttpConnector
     */
    protected function markCredentialsInvalid(bool $trueOrFalse) : HttpConnector
    {
        if (! $this->hasAuthentication()) {
            return $this;
        }
        
        $ctxtScope = $this->getWorkbench()->getContext()->getScopeSession();
        if ($trueOrFalse === true) {
            $ctxtScope->setVariable($this->getAuthRetryDisabledVar(), $this->getAuthRetryDisabledHash());
        } else {
            $ctxtScope->unsetVariable($this->getAuthRetryDisabledVar());
        }
        
        return $this;
    }
    
    /**
     * 
     * @param OnAuthenticationFailedEvent $event
     */
    public function handleOnAuthenticationFailedEvent(OnAuthenticationFailedEvent $event)
    {
        if ($event->getAuthenticationProvider() !== $this) {
            return;
        }
        
        if ($this->hasAuthentication() === false) {
            return;
        }
        
        if ($this->getAuthenticationRetryAfterFail() === false) {
            $this->markCredentialsInvalid(true);
        }
        
        return;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getSendRequestIdWithHeader() : ?string
    {
        return $this->sendRequestIdWithHeader;
    }
    
    /**
     * If set, the request id used by the workbench internally will be sent with the speicified HTTP header.
     * 
     * The value of this property should be the header name (e.g. `X-Request-ID`) or
     * `null`. The default is `null`, so not request id is forwarded to the data source.
     * 
     * Note, that this can result in multiple requests to the data source with the
     * same id as the may result from the same request to the workbench (e.g. when
     * reading multiple URLs into a single data set).
     * 
     * @uxon-property send_request_id_with_header
     * @uxon-type string
     * @uxon-template X-Request-ID
     * @uxon-default null
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setSendRequestIdWithHeader(?string $value) : HttpConnector
    {
        $this->sendRequestIdWithHeader = $value;
        return $this;
    }
    
    /**
     * 
     * @return array
     */
    protected function getHeaders() : array
    {
        return $this->headers;
    }
    
    /**
     * Headers to send with every request
     * 
     * @uxon-property headers
     * @uxon-type object
     * @uxon-template {"Accept": "application/json"}
     * 
     * @param UxonObject|array $value
     * @return HttpConnector
     */
    protected function setHeaders($value) : HttpConnector
    {
        $this->headers = ($value instanceof UxonObject ? $value->toArray() : $value);
        return $this;
    }

    /**
     * @return bool|null
     */
    protected function willHeadersCalculateContentLength() : ?bool
    {
        return $this->headersCalcContentLength;
    }

    /**
     * Set to TRUE or FALSE to explicitly force to calculate/omit the Content-Length header.
     * 
     * If not set, the Content-Length header will probably be added automatically by the OS-level 
     * cURL library.
     * 
     * @uxon-property headers_calculate_content_length
     * @uxon-type boolean
     * 
     * @param bool $value
     * @return $this
     */
    public function setHeadersCalculateContentLength(bool $value) : HttpConnector
    {
        $this->headersCalcContentLength = $value;
        return $this;
    }
    
    protected function isDebugMode() : bool
    {
        return $this->debug === true;
    }
    
    /**
     * Set to TRUE or FALSE to enable or disable debug mode
     * 
     * @uxon-property debug
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $trueOrFalse
     * @return HttpConnector
     */
    protected function setDebug(bool $trueOrFalse) : HttpConnector
    {
        $this->debug = $trueOrFalse;
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getDebugResponse() : ?UxonObject
    {
        return $this->debugResponse ?? new UxonObject(['status' => '200', 'headers' => [], 'body' => '']);
    }
    
    /**
     * Specify a static response body to be used in debug mode instead of really querying the URL. 
     * 
     * The response body may either be a string or a UXON structure, that will then be automatically converted
     * to a JSON string.
     * 
     * @uxon-property debug_response
     * @uxon-type object
     * @uxon-template {"status": "200", "headers": {"Content-Type": "application/json"}, "body": ""}
     * @uxon-default {"status": "200", "headers": {}, "body": ""}
     * 
     * @param UxonObject $value
     * @throws DataConnectionConfigurationError
     * @return HttpConnector
     */
    protected function setDebugResponse(UxonObject $value) : HttpConnector
    {
        $this->debugResponse = $value;
        return $this;
    }
    
    protected function getTimeout() : ?int
    {
        return $this->timeout;
    }

    /**
     * Set a timeout in seconds for all HTTP requests of this connection
     * 
     * @uxon-property timeout
     * @uxon-type integer
     * 
     * @param int $value
     * @return $this
     */
    protected function setTimeout(int $value) : HttpConnector
    {
        $this->timeout = $value;
        return $this;
    }
    
    protected function getCurlOptions() : array
    {
        return $this->curlOpts;
    }

    /**
     * Custom cURL options to be used for every request
     * 
     * @uxon-property curl_options
     * @uxon-type object
     * @uxon-template {"CURL_": ""}
     * 
     * @param array $array
     * @return $this
     */
    public function setCurlOptions(UxonObject $uxonArray) : HttpConnector
    {
        $opts = [];
        foreach ($uxonArray->getPropertiesAll() as $constant => $value) {
            if (! defined($constant)) {
                throw new DataConnectionConfigurationError($this, 'Invalid cURL option "' . $constant . '": expecting PHP constant names as defined here: https://www.php.net/manual/en/curl.constants.php#constant.curlopt-abstract-unix-socket');    
            }
            $opts[constant($constant)] = $value;
        }
        $this->curlOpts = $opts;
        return $this;
    }
}