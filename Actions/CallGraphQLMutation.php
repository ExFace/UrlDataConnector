<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\CommonLogic\Debugger\LogBooks\ActionLogBook;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Calls a GraphQL mutation over HTTP.
 * 
 * This is a version of the `CallWebService` action, which is specialized on
 * GraphQL web services. While all parameters of `CallWebService` can be used
 * too, `CallGraphQLQuery` and `CallGraphQLMutation` can create GraphQL from
 * their parameters automatically, making it very easy to integrate GraphQL
 * services.
 * 
 * Of course, the generic `CallWebService` can be used too if you configure
 * the required `headers` and give it a `body` with placeholders for all 
 * parameters, but it's simply much more work to do!
 * 
 * ## Examples
 * 
 * ```
 * {
 *   "mutation_name": "generateDelivery",
 *   "parameters": [
 *     {
 *       "name": "id",
 *       "data_type": {
 *         "alias": "exface.Core.String"
 *       }
 *     }
 *   ],
 *   "result_object_alias": "my.App.Delivery"
 * }
 * 
 * ```
 * 
 * This action metamodel would describe the following GraphQL mutation:
 * 
 * ```
 * type Mutation {
 *   generateDelivery(
 *      id: String!
 *   ): Delivery
 * }
 * 
 * ```
 * 
 * Refer to the description of `CallWebService` for more generic examples.
 * 
 * @author Andrej Kabachnik
 *
 */
class CallGraphQLMutation extends CallGraphQLQuery 
{
    private $mutationName = null;
    
    /**
     * Name of the GraphQL mutation to execute.
     *
     * @uxon-property mutation_name
     * @uxon-type string
     *
     * @param string $value
     * @return CallGraphQLMutation
     */
    public function setMutationName(string $value) : CallGraphQLMutation
    {
        $this->mutationName = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    protected function getMutationName() : string
    {
        return $this->mutationName;
    }
    
    protected function buildGqlBody(DataSheetInterface $data, int $rowNr, ActionLogBook $logbook) : string
    {
        return <<<GraphQL
        
mutation {
    {$this->getMutationName()} (
        {$this->buildGqlFields($data, $rowNr)}
    )
    { }
}

GraphQL;
    }
        
    protected function buildHeaders() : array
    {
        return array_merge(['Content-Type'=> 'application/graphql'], parent::buildHeaders());
    }
}