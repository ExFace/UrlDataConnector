<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\UrlDataConnector\ModelBuilders\GraphQLModelBuilder;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;

/**
 * Connector for GraphQL web services.
 * 
 * The only required parameter is the `url`, which should point to the GraphQL endpoint.
 * 
 * For more options like authentification, etc. refer to the documentation of the generic 
 * `HttpConnector`. 
 *
 * @author Andrej Kabachnik
 *        
 */
class GraphQLConnector extends HttpConnector
{
    public function performQuery(DataQueryInterface $query)
    {
        $query = parent::performQuery($query);
        
        $arr = json_decode($query->getResponse()->getBody(), true);
        if ($arr['errors'] !== null) {
            $err = $arr['errors'][0];
            $error = 'GraphQL error "' . $err['message'] . '" (path: ' . $err['path'] . ', locations: ' . json_encode($err['locations']) . ')';
            throw new DataQueryFailedError($query, $error);
        }
        
        return $query;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new GraphQLModelBuilder($this);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\HttpConnector::willIgnore()
     */
    protected function willIgnore(Psr7DataQuery $query) : bool
    {
        return false;
    }
}