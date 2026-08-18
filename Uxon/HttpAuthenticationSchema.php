<?php
namespace exface\UrlDataConnector\Uxon;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Uxon\UxonSchema;
use exface\UrlDataConnector\DataConnectors\HttpConnector;

/**
 * UXON-schema class for HTTP connector authentication providers.
 *
 * @see UxonSchema for general information.
 *
 * @author Andrej Kabachnik
 *
 */
class HttpAuthenticationSchema extends UxonSchema
{
    public static function getSchemaName() : string
    {
        return 'HTTP Authentication';
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getPrototypeClass()
     */
    public function getPrototypeClass(UxonObject $uxon, array $path, string $rootPrototypeClass = null) : string
    {
        $name = $rootPrototypeClass ?? $this->getDefaultPrototypeClass();
        
        foreach ($uxon as $key => $value) {
            if (strcasecmp($key, 'class') === 0 && trim($value ?? '') !== '') {
                $name = $value;
                break;
            }
        }
        
        if (count($path) > 1) {
            return parent::getPrototypeClass($uxon, $path, $name);
        }
        
        return $name;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getDefaultPrototypeClass()
     */
    protected function getDefaultPrototypeClass() : string
    {
        return '\\' . HttpConnector::class;
    }
}