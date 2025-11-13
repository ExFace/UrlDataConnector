<?php
namespace exface\UrlDataConnector\CommonLogic;

use exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\UrlDataConnector\Uxon\HttpAuthenticationSchema;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class to implement interface HttpAuthenticationProviderInterface
 * 
 * This class provides the constructor and methods depending on it as well
 * as the link to a custom UXON schema for HTTP authentication providers.
 * 
 * HTTP connections have a separate `authentication` configuration, that contains all information required
 * for different providers - HTTP basic auth, OAuth2, SSL Certificates, all sorts of token based authentication,
 * etc.
 * 
 * Each authentication provider also has an authenticate method. However, that only handles the provider specific
 * logic (e.g. token exchange) while this method here takes care of instantiating the provider, calling it and
 * storing the authenticated token in the credential storage. If the auth provider supports credential storage,
 * it will store everything required for the next authentication there. Thus, the next time the connection is
 * instantiated, the information from the credential storage will be loaded on-top of the config stored in the
 * connection.
 * 
 * @author Andrej Kabachnik
 *
 */
abstract class AbstractHttpAuthenticationProvider implements HttpAuthenticationProviderInterface
{
    use ImportUxonObjectTrait;
    
    private $connection = null;
    
    private $constructorUxon = null;
    
    /**
     * 
     * @param HttpConnectionInterface $dataConnection
     * @param UxonObject $uxon
     */
    public function __construct(HttpConnectionInterface $dataConnection, UxonObject $uxon = null)
    {
        $this->connection = $dataConnection;
        $this->constructorUxon = $uxon;
        if ($uxon !== null) {
            $this->importUxonObject($uxon, ['class']);
        }
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->constructorUxon ?? new UxonObject();
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getConnection()
     */
    public function getConnection() : HttpConnectionInterface
    {
        return $this->connection;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return HttpAuthenticationSchema::class;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->connection->getWorkbench();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::isResponseUnauthenticated()
     */
    public function isResponseUnauthenticated(ResponseInterface $response) : bool
    {
        return $response->getStatusCode() == 401;
    }
}