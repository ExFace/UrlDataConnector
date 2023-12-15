<?php
namespace exface\UrlDataConnector\DataConnectors;

use GuzzleHttp\Psr7\Response;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Factories\DataSheetFactory;

/**
 * Allows to query any data source via URL - `metamodel://my.APP.OBJECT/<attribute_alias>/<uid_value>`
 *
 * This connector allows to use URL builders on data available in any other data source type.
 * Use URLs like `metamodel://my.APP.OBJECT/<attribute_alias>/<uid_value>` to access data
 * stored in an attribute of any other object: e.g. a JSON saved in an SQL table.
 *
 * GET-requests make the connector read data, while POST, PUT and PATCH wrtie data. DELETE
 * will delete the entire item.
 *
 * Technically this connector wraps the value of the corresponding attribute in a PHP
 * PSR-7 response, thus making the query builder think it actually sent an HTTP resquest.
 *
 * @author andrej.kabachnik
 *
 */
class MetamodelUriConnector extends AbstractUrlConnector
{
    const SCHEME = 'metamodel';

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performConnect()
     */
    protected function performConnect()
    {
        return;
    }

    /**
     *
     * {@inheritdoc}
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

        $request = $query->getRequest();
        $uri = $request->getUri();
        if ($uri->getScheme() !== self::SCHEME) {
            throw new DataQueryFailedError($query, 'Wrong scheme for metamodel connection "' . $uri->getScheme() . '" - expection "metamodel"', '7STUR1D');
        }
        $objSel = $uri->getHost();
        list($attrAlias, $uid, $rest) = explode('/', trim(trim($uri->getPath()), '/'), 3);

        switch (true) {
            case $attrAlias === '' || $attrAlias === null:
                throw new DataQueryFailedError($query, 'Cannot determine attribute alias from URL "' . $uri->__toString() . '"', '7STUR1D');
            case $uid === '' || $uid === null:
                return new Response(404);
            case $rest !== '' && $rest !== null:
                throw new DataQueryFailedError($query, 'Invalid metamodel URL format "' . $uri->__toString() . '"', '7STUR1D');
        }

        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $objSel);
        $bodyCol = $ds->getColumns()->addFromExpression($attrAlias);
        $ds->getFilters()->addConditionFromAttribute($ds->getMetaObject()->getUidAttribute(), $uid);

        switch ($request->getMethod()) {
            case 'GET':
                $ds->dataRead();
                break;
            case 'POST':
            case 'PUT':
            case 'PATCH':
            	$ds->getColumns()->addFromSystemAttributes();
            	$ds->dataRead();
                $bodyCol->setValue(0, $request->getBody()->__toString());
                $ds->dataUpdate(false);
                break;
            case 'DELETE':
            	$ds->getColumns()->addFromSystemAttributes();
            	$ds->dataRead();
                $ds->dataDelete();
                break;
            default:
                throw new DataQueryFailedError($query, 'HTTP method "' . $request->getMethod() . '" not supported by MetamodelUriConnector!', '7STUR1D');
        }

        if ($ds->isEmpty()) {
            $response = new Response(404);
        } else {
            $response = new Response(200, [], $bodyCol->getValue(0));
        }
        $query->setResponse($response);
        return $query;
    }
}