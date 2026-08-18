<?php
namespace exface\UrlDataConnector\DataConnectors\Authentication;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\CommonLogic\Security\AuthenticationToken\UsernamePasswordAuthToken;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\Selectors\UserSelectorInterface;
use exface\Core\Interfaces\Security\PasswordAuthenticationTokenInterface;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\UrlDataConnector\CommonLogic\AbstractHttpAuthenticationProvider;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\Factories\ExpressionFactory;

/**
 * HTTP basic authentication for HTTP connectors
 * 
 * @author Andrej Kabachnik
 *
 */
class HttpBasicAuth extends AbstractHttpAuthenticationProvider
{    
    private $user = null;
    
    private $password = null;
    
    private $passwordRequired = false;
    
    /**
     *
     * @var ?string
     */
    private $authentication_url = null;
    
    /**
     *
     * @var string
     */
    private $authentication_request_method = 'GET';
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getUser()
     */
    public function getUser()
    {
        if ((null === $user = $this->user) && ($this->getConnection() instanceof HttpConnector)) {
            $user = $this->getConnection()->getUser();
        }
        
        if ($user !== null && $user !== '' && Expression::detectFormula($user)) {
            $user = ExpressionFactory::createFromString($this->getWorkbench(), $user)->evaluate();
        }
        
        return $user;
    }
    
    /**
     * The user name for HTTP basic authentification.
     *
     * @uxon-property user
     * @uxon-type string
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUser($value)
    {
        $this->user = $value;
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getPassword()
     */
    public function getPassword()
    {
        if ((null === $password = $this->password) && ($this->getConnection() instanceof HttpConnector)) {
            $password = $this->getConnection()->getPassword();
        }
        
        if ($password !== null && $password !== '' && Expression::detectFormula($password)) {
            $password = ExpressionFactory::createFromString($this->getWorkbench(), $password)->evaluate();
        }
        
        return $password;
    }
    
    /**
     * Sets the password for basic HTTP authentification.
     *
     * @uxon-property password
     * @uxon-type password
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setPassword($value)
    {
        $this->password = $value;
        return $this;
    }
    
    /**
     * Set the authentication url.
     *
     * @uxon-property authentication_url
     * @uxon-type string
     *
     * @param string $string
     * @return HttpBasicAuth
     */
    public function setAuthenticationUrl(string $string) : HttpBasicAuth
    {
        $this->authentication_url = $string;
        return $this;
    }
    
    protected function getAuthenticationUrl() : ?string
    {
        return $this->authentication_url;
    }
    
    /**
     * Set the authentication request method. Default is 'GET'.
     *
     * @uxon-property authentication_request_method
     * @uxon-type [GET,POST,CONNECT,HEAD,OPTIONS]
     *
     * @param string $string
     * @return HttpBasicAuth
     */
    public function setAuthenticationRequestMethod(string $string) : HttpBasicAuth
    {
        $this->authentication_request_method = $string;
        return $this;
    }
    
    /**
     * Returns the HTTP method for a dedicated authentication request (GET by default).
     * 
     * @return string|NULL
     */
    protected function getAuthenticationRequestMethod() : ?string
    {
        return $this->authentication_request_method;
    }
    
    /**
     * Returns the Guzzle options array for a dedicated authentication request.
     * 
     * E.g. ["auth" => ["username", "password"]] for basic HTTP authentication
     * 
     * @param array $defaultOptions
     * @param PasswordAuthenticationTokenInterface $token
     * @return array
     */
    protected function getAuthenticationRequestOptions(array $defaultOptions, PasswordAuthenticationTokenInterface $token) : array
    {
        $options = $defaultOptions;
        
        // Basic authentication
        $options['auth'] = array(
            $token->getUsername(),
            $token->getPassword()
        );
        
        return $options;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $loginForm, bool $saveCredentials = true, UserSelectorInterface $credentialsOwner = null) : iContainOtherWidgets
    {
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'attribute_alias' => 'USERNAME',
            'required' => true
        ])), 0);
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'attribute_alias' => 'PASSWORD'
        ])), 1);
        
        return $loginForm;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token) : AuthenticationTokenInterface
    {
        if (! $token instanceof UsernamePasswordAuthToken) {
            throw new InvalidArgumentException('Invalid token class "' . get_class($token) . '" for authentication via data connection "' . $this->getAliasWithNamespace() . '" - only "UsernamePasswordAuthToken" and derivatives supported!');
        }
        
        $url = $this->getAuthenticationUrl() ?? $this->getConnection()->getUrl();
        if (! $url) {
            throw new DataConnectionConfigurationError($this, "Cannot perform authentication in data connection '{$this->getName()}'! Either provide authentication_url or a general url in the connection configuration.");
        }
        
        $password = $token->getPassword();
        $passwordRequired = $this->isPasswordRequired();
        if ($passwordRequired && ($password === null || $password === '')) {
            throw new AuthenticationFailedError($this->getConnection(), 'No username/password provided for data connection ' . $this->getConnection()->getName() . '!');
        }
        
        // Disable password requirement to let the auth-request pass through.
        $this->setPasswordRequired(false);
        try {
            $request = new Request($this->getAuthenticationRequestMethod(), $url);
            $this->getConnection()->sendRequest($request, $this->getAuthenticationRequestOptions([], $token));
        } catch (\Throwable $e) {
            throw $e;
        }
        $this->setPasswordRequired($passwordRequired);
        
        return $token;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getCredentialsUxon()
     */
    public function getCredentialsUxon(AuthenticationTokenInterface $authenticatedToken) : UxonObject
    {
        return new UxonObject([
            'authentication' => [
                'class' => '\\' . get_class($this),
                'user' => $authenticatedToken->getUsername(),
                'password' => $authenticatedToken->getPassword()
            ]
        ]);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getDefaultRequestOptions()
     */
    public function getDefaultRequestOptions(array $defaultOptions): array
    {
        $options = $defaultOptions;
        
        $options['auth'] = array(
            $this->getUser(),
            $this->getPassword()
        );
        
        return $options;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::signRequest()
     */
    public function signRequest(RequestInterface $request): RequestInterface
    {
        $password = $this->getPassword();
        if ($this->isPasswordRequired() && ($password === null || $password === '')) {
            throw new AuthenticationFailedError($this->getConnection(), 'No username/password provided for data connection ' . $this->getConnection()->getName() . '!');
        }
        
        return $request;
    }
    
    /**
     * 
     * @return bool
     */
    public function isPasswordRequired() : bool
    {
        return $this->passwordRequired;
    }
    
    /**
     * Set to TRUE to check for a password BEFORE sending a request to the server.
     * 
     * Use this option if the server does not produce a proper error on empty credentials. Some
     * servers will simply ignore such requests or send blank responses - in this case no login
     * promt will appear unless this option is set.
     * 
     * @uxon-property password_required
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $value
     * @return HttpBasicAuth
     */
    public function setPasswordRequired(bool $value) : HttpBasicAuth
    {
        $this->passwordRequired = $value;
        return $this;
    }
}