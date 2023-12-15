<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\CommonLogic\QueryBuilder\QueryPartSorter;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use Psr\Http\Message\RequestInterface;
use exface\Core\CommonLogic\Model\Condition;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup;
use exface\Core\CommonLogic\Model\ConditionGroup;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\Model\CompoundAttributeInterface;
use exface\Core\CommonLogic\QueryBuilder\QueryPartAttribute;

/**
 * This is an abstract query builder for REST APIs.
 * It creates a sequence of URL parameters for a query. Parsing the results is done by
 * specific implementation (e.g. JSON vs. XML)
 * 
 * ## Data addresses
 * 
 * Object data addresses are URLs or their parts. Relative URLs can be used, if a
 * base URL is defined in the data connection.
 * 
 * The syntax of attribute data addresses depends on the speicific implementation of 
 * the query builder: i.e. XML builders will use XPath, JSON-query builders may use
 * XPath or JSONPath, HTML builds will probably use CSS selectors.
 * 
 * Additionally the following common placeholders should be supported by all
 * URL builders.
 * 
 * ### On object level
 * 
 * - `[#<attribute_alias>#]` - URLs in object data addresses can include placeholders.
 * These are basically required filters, that must be part of the URL a opposed to
 * regular optional filters based on URL parameters.
 * 
 * ### On attribute level
 * 
 * - `[#~urlplaceholder:<placeholder_name>#]` - the current value of a placeholder
 * used in the URL (= data address of the object). For example, if the object has
 * `https://www.github.com/[#vendor#]/` as data address, an attribute can have
 * the placeholder `[#~urlplaceholder:vendor#]` as data address, which will be
 * replace by the same value. Thus, our attribute will get the value `exface` in
 * a query to `https://www.github.com/exface/`. This feature is very usefull in
 * web services, that do not return values of URL parameters in their response.
 * 
 * - `[#~urlparam:<parameter>#]` - the value of an URL parameter in the current request.
 * E.g. in a URL `http://mydomain/resource?param1=val1&param2=val2` the placeholders
 * `[~urlparam:param1]` and `[~urlparam:param2]` can be used to get `val1` and `val2`
 * respectively. This is similar to `[#~urlplaceholder:<placeholder_name>#]`, but
 * can be used for any URL parameters - even those produced by filters, etc. On the
 * other hand, `[#~urlplaceholder:<placeholder_name>#]` can be used to get any placeholder
 * used, not only query parameters following the `?`.
 * 
 * ## Data address properties
 * 
 * TODO
 *
 * @author Andrej Kabachnik
 *        
 */
abstract class AbstractUrlBuilder extends AbstractQueryBuilder
{
    // Data Address Properties (DAP)
    
    /**
     * If set to TRUE request without at least a single filter are skipped returning an empty result automatically.
     * 
     * @uxon-property force_filtering
     * @uxon-target object
     * @uxon-type boolean
     */
    const DAP_FORCE_FILTERING = 'force_filtering';
    
    /**
     * Path to the array containing the items
     * 
     * @uxon-property response_data_path
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_RESPONSE_DATA_PATH = 'response_data_path';
    
    /**
     * Path to the total number of items matching the filter (used for pagination)
     * 
     * @uxon-property response_total_count_path
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_RESPONSE_TOTAL_COUNT_PATH = 'response_total_count_path';
    
    /**
     * Result rows will get resorted and grouped by values of the given attribute
     * 
     * @uxon-property response_group_by_attribute_alias
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_RESPONSE_GROUP_BY_ATTRIBUTE_ALIAS = 'response_group_by_attribute_alias';
    
    /**
     * Set to `true` to return only the first group ignoring all rows with other values of the group attribute than the first row.
     * 
     * @uxon-property response_group_use_only_first
     * @uxon-target object
     * @uxon-type boolean
     * @uxon-default false
     */
    const DAP_RESPONSE_GROUP_USE_ONLY_FIRST = 'response_group_use_only_first';
    
    /**
     * Set to `false` to disable remote pagination.
     * 
     * If not set or set to `true`, `request_offset_parameter` and `request_limit_parameter`
     * must be set in the data source configuration to make pagination work. Some
     * query builder like the `OData2UrlBuilder` can generate these parameters automatically,
     * so you don't need to specify them manually. In this case, `request_remote_pagination`
     * simply turns pagination on or off.
     * 
     * @uxon-property request_remote_pagination
     * @uxon-target object
     * @uxon-type boolean
     */
    const DAP_REQUEST_REMOTE_PAGINATION = 'request_remote_pagination';
    
    /**
     * Set to `true` if the web service can provide the total number of entries for a paged request. 
     * 
     * In order for this to work, you must either provide `response_total_count_path` (recommended) 
     * or the query builder must implement the `count()` operation.
     * 
     * @uxon-property request_remote_pagination_has_total
     * @uxon-target object
     * @uxon-type boolean
     */
    const DAP_REQUEST_REMOTE_PAGINATION_HAS_TOTAL = 'request_remote_pagination_has_total';
    
    /**
     * Name of the URL parameter containing the page offset for pagination
     * 
     * Leave blank to force omitting this URL parameter (e.g. for a service that 
     * uses pagination automatically)
     * 
     * @uxon-property request_offset_parameter
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_REQUEST_OFFSET_PARAMETER = 'request_offset_parameter';
    
    /**
     * Name of the URL parameter containing the page for pagination
     *
     * @uxon-property request_page_parameter
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_REQUEST_PAGE_PARAMETER = 'request_page_parameter';
    
    /**
     * Name of the URL parameter holding the maximum number of returned items.
     * 
     * Leave blank to force omitting this URL parameter (e.g. for a service that 
     * uses pagination automatically)
     * 
     * @uxon-property request_limit_parameter
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_REQUEST_LIMIT_PARAMERTER = 'request_limit_parameter'; 
 
    /**
     * regular expression pattern for PHP preg_replace() function to be performed on the request URL
     * 
     * @uxon-property request_url_replace_pattern
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_REQUEST_URL_REPLACE_PATTERN = 'request_url_replace_pattern';
    
    /**
     * Replacement string for PHP preg_replace() function to be performed on the request URL
     * 
     * @uxon-property request_url_replace_with
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_REQUEST_URL_REPLACE_WITH = 'request_url_replace_with';
    
    /**
     * Makes requests with filters over the UID go to this URL instead of the one in the data address. 
     * 
     * The URL allows attribute_alias as placeholders (incl. the UID itself - e.g.
     * `me.com/service/[#UID#]`). Note, that if the URL does not have placeholders
     * it will be always the same - regardles of what the UID actually is.
     * 
     * @uxon-property uid_request_data_address
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_UID_REQUEST_DATA_ADDRESS = 'uid_request_data_address';
 
    /**
     * used to find the data in the response for a request with a filter on UID (instead of response_data_path)
     * 
     * @uxon-property uid_response_data_path
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_UID_RESPONSE_DATA_PATH = 'uid_response_data_path';
    
    /**
     * HTTP method for read requests (GET by default)
     * 
     * @uxon-property create_request_method
     * @uxon-target object
     * @uxon-type string
     * @uxon-default GET
     */
    const DAP_READ_REQUEST_METHOD = 'read_request_method';
 
    /**
     * Set to `true` to ignore rows with UID values, that alread exist in previous rows. 
     * 
     * This is usefull if you use a single service to read multiple objects: e.g. product 
     * groups and categories. Assuming each group has a category id and name, you could 
     * create a category-object with this option set to `true` to get a distinct list of 
     * categories. 
     * 
     * **WARNING:** don't use this option with server-side pagination because client and 
     * server will have different oppinion on page length!
     * 
     * @uxon-property read_request_remove_ambiguous_uids
     * @uxon-target object
     * @uxon-type boolean
     * @uxon-default false
     */
    const DAP_READ_REQUEST_REMOVE_AMBIGUOUS_UIDS = 'read_request_remove_ambiguous_uids';
    
    /**
     * HTTP method for create requests (PUT by default)
     * 
     * @uxon-property create_request_method
     * @uxon-target object
     * @uxon-type string
     * @uxon-default PUT
     */
    const DAP_CREATE_REQUEST_METHOD = 'create_request_method';
 
    /**
     * Used in create requests instead of the data address
     * 
     * @uxon-property create_request_data_address
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_CREATE_REQUEST_DATA_ADDRESS = 'create_request_data_address';
 
    /**
     * Path to the object/array holding the attributes of the instance to be created
     * 
     * @uxon-property create_request_data_path
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_CREATE_REQUEST_DATA_PATH = 'create_request_data_path';
 
    /**
     * HTTP method for update requests (PATCH by default).
     * 
     * @uxon-property update_request_method
     * @uxon-target object
     * @uxon-type string
     * @uxon-default PATCH
     */
    const DAP_UPDATE_REQUEST_METHOD = 'update_request_method';
 
    /**
     * Used in update requests instead of the data address
     * 
     * @uxon-property update_request_data_address
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_UPDATE_REQUEST_DATA_ADDRESS = 'update_request_data_address';
    
    /**
     * This is where the data is put in the body of update requests (if not specified the attributes are just put in the root)
     * 
     * @uxon-property update_request_data_path
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_UPDATE_REQUEST_DATA_PATH = 'update_request_data_path';
     
    /**
     * HTTP method for delete requests (DELETE by default).
     * 
     * @uxon-property delete_request_method
     * @uxon-target object
     * @uxon-type string
     * @uxon-default DELETE
     */
    const DAP_DELETE_REQUEST_METHOD = 'delete_request_method';
     
    /**
     * Used in delete requests instead of the data address
     * 
     * @uxon-property delete_request_data_address
     * @uxon-target object
     * @uxon-type string
     */
    const DAP_DELETE_REQUEST_DATA_ADDRESS = 'delete_request_data_address';
     
    /**
     * Set to 1 to enable remote filtering (0 by default).
     * 
     * @uxon-property filter_remote
     * @uxon-target attribute
     * @uxon-type boolean
     * @uxon-default false
     */
    const DAP_FILTER_REMOTE = 'filter_remote';
    
    /**
     * Used to set a custom URL to be used if there is a filter over this attribute. 
     * 
     * The URL accepts the placeholder `[#~value#]` which will be replaced by the. 
     * Note, that if the URL does not have the placeholder, it will be always the same - 
     * regardles of what the filter is actually set to. 
     *
     * @uxon-property filter_remote_url
     * @uxon-target attribute
     * @uxon-type string
     */
    const DAP_FILTER_REMOTE_URL = 'filter_remote_url';
    
    /**
     * Used for filtering instead of the attributes data address.
     * 
     * E.g. `&[filter_remote_url_param]=VALUE` instead of `&[data_address]=VALUE`.
     *
     * @uxon-property filter_remote_url_param
     * @uxon-target attribute
     * @uxon-type string
     */
    const DAP_FILTER_REMOTE_URL_PARAM = 'filter_remote_url_param';
    
    /**
     * Prefix for the value in a filter query.
     * 
     * E.g. `&[data_address]=[filter_remote_prefix]VALUE`. Can be used to pass default operators etc.
     *
     * @uxon-property filter_remote_prefix
     * @uxon-target attribute
     * @uxon-type string
     */
    const DAP_FILTER_REMOTE_PREFIX = 'filter_remote_prefix';
    
    /**
     * Produces multiple request if the filter value is a list (= an `IN` filter). 
     * 
     * This options is set to `true` automatically for UID-filters if the object has a 
     * `uid_request_data_address` or attributes with `filter_remote_url`. For other remote 
     * IN-filters, a list of values will be used as filter value.
     * 
     * @uxon-property filter_remote_split_value_lists
     * @uxon-target attribute
     * @uxon-type boolean
     */
    const DAP_FILTER_REMOTE_SPLIT_VALUE_LISTS = 'filter_remote_split_value_lists';
    
    /**
     * Set to 1 to filter in ExFace after reading the data or  set to 0 to take the data as it is.
     * 
     * Use this if the data source does not support filtering over this attribute).
     * 
     * If not set, the data will be filtered locally automatically if no remote filtering 
     * is configured.
     * 
     * @uxon-property filter_locally
     * @uxon-target attribute
     * @uxon-type boolean
     */
    const DAP_FILTER_LOCALLY = 'filter_locally';
    
    /**
     * set to 1 to enable remote sorting (0 by default)
     * 
     * @uxon-property sort_remote
     * @uxon-target attribute
     * @uxon-type boolean
     */
    const DAP_SORT_REMOTE = 'sort_remote';
    
    /**
     * used for sorting instead of the attributes data address.
     * 
     * E.g. `&[sort_remote_url_param]=VALUE` instead of `&[data_address]=VALUE`.
     * 
     * @uxon-property sort_remote_url_param
     * @uxon-target attribute
     * @uxon-type string
     */
    const DAP_SORT_REMOTE_URL_PARAM = 'sort_remote_url_param';
    /**
     * Set to 1 to sort in ExFace after reading the data (if the data source does not support filtering over this attribute)
     * 
     * @uxon-property sort_locally
     * @uxon-target attribute
     * @uxon-type boolean
     */
    const DAP_SORT_LOCALLY = 'sort_locally';
    
    /**
     * Used in the body of create queries (typically PUT-queries) instead of the data address
     * 
     * @uxon-property create_data_address
     * @uxon-target attribute
     * @uxon-type string
     */
    const DAP_CREATE_DATA_ADDRESS = 'create_data_address';
    
    /**
     * Used in the body of update queries (typically POST/PATCH-queries) instead of the data address
     * 
     * @uxon-property update_data_address
     * @uxon-target attribute
     * @uxon-type string
     */
    const DAP_UPDATE_DATA_ADDRESS = 'update_data_address';
    
    const OPERATION_CREATE = 'create';
    const OPERATION_READ = 'read';
    const OPERATION_UPDATE = 'update';
    const OPERATION_DELETE = 'delete';
    
    private $endpoint_filter = null;

    private $request_split_filter = null;
    
    private $UseUidsAsRowNumbers = null;
    
    private $urlPlaceholders = [];
    
    private $subrequestNo = 0;

    /**
     * Returns a PSR7 GET-Request for this query.
     * 
     * @param bool $doSelect
     * @param bool $doFilter
     * @param bool $doSort
     * @param bool $doPaginate
     * @param bool $doAggregate
     * 
     * @throws QueryBuilderException
     * 
     * @return RequestInterface
     */
    public function buildRequestToRead(bool $doSelect = true, bool $doFilter = true, bool $doSort = true, bool $doPaginate = true, bool $doAggregate = true) : RequestInterface
    {
        $thisObj = $this->getMainObject();
        $endpoint = $thisObj->getDataAddress();
        $params = '';
        
        $queryFilters = $this->getFilters();
        // Copy the query filters since they might get modified by the logic below and we want to
        // keep the original query filters untouched.
        $requestFilters = $queryFilters->copy();
        
        // Check if there are filters, that require to split the request into multiple requests.
        foreach ($requestFilters->getFilters() as $nr => $qpart) {
            $splitOption = $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_SPLIT_VALUE_LISTS);
            if ($splitOption === null || $splitOption === '') {
                $splitOption = null;
            } else {
                $splitOption = BooleanDataType::cast($splitOption);
            }
            switch (true) {
                // Need to split the request, if the object has a separate `uid_request_data_address`
                // and there is a filter over the UID attribute
                case $thisObj->getUidAttributeAlias() == $qpart->getAlias() && $thisObj->getDataAddressProperty(static::DAP_UID_REQUEST_DATA_ADDRESS):
                    // In REST APIs it is common to have a special URL to fetch data by UID of the object:
                    // e.g. /users/1.xml would be the URL to fetch data for the user with UID = 1. Since in ExFace
                    // the UID filter can also be used in regular searches, we can tell ExFace to use a special
                    // data address for UID-based queries. Other filters will get applied to, but most APIs will
                    // probably ignore them. If the API can actually handle a regular UID-filter, the special
                    // data address should be simply left empty - this gives much more flexibility!
                    $endpoint = $thisObj->getDataAddressProperty(static::DAP_UID_REQUEST_DATA_ADDRESS);
                    // Remember the original filter (not it's copy from $requestFilters) for further processing!
                    if ($splitOption !== false) {
                        $this->setRequestSplitFilter($queryFilters->getFilters()[$nr]);
                    }
                    // Remove the filter from the current request because it's value is already part of
                    // the endpoint.
                    $requestFilters->removeFilter($qpart);
                    break;
                // Another way to set custom URLs is to give an attribute an explicit URL via filter_remote_url address property.
                // This ultimately does the same thing, as uid_request_data_address on object level, but it's more general
                // because it can be set for every attribute.
                case $filter_endpoint = $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_URL):
                    if ($qpart->getComparator() == ComparatorDataType::IN && $splitOption !== false) {
                        // FIXME this check prevents split filter collisions, but it can be greatly improved in two ways
                        // - we should generally look for other custom URLs
                        // - the final URL with all placeholders replaced should be compared
                        if ($this->getRequestSplitFilter() !== null) {
                            if (strcasecmp($this->getRequestSplitFilter()->getDataAddressProperty(static::DAP_FILTER_REMOTE_URL), $filter_endpoint) !== 0) {
                                throw new QueryBuilderException('Cannot use multiple filters requiring different custom URLs in one query: "' . $this->getRequestSplitFilter()->getCondition()->toString() . '" AND "' . $qpart->getCondition()->toString() . '"!');
                            }
                        } else {
                            // Remember the original filter (not it's copy from $requestFilters) for further processing!
                            $this->setRequestSplitFilter($queryFilters->getFilters()[$nr]);
                        }
                        $value = reset(explode($qpart->getValueListDelimiter(), $qpart->getCompareValue()));
                    } else {
                        $value = $qpart->getCompareValue();
                    }
                    // The filter_remote_url accepts the value placeholder along with attribute alias based placeholders. Since the value-placeholder
                    // is not supported in the regular data_address or the uid_request_data_address (there simply is nothing to take the value from),
                    // it must be replaced here already
                    $endpoint = str_replace('[#~value#]', $value, $filter_endpoint);
                    // Remove the filter from the current request because it's value is already part of
                    // the endpoint.
                    $requestFilters->removeFilter($qpart);
                    break;
                default:
                    if ($splitOption === true && $qpart->getComparator() == ComparatorDataType::IN) {
                        if ($this->getRequestSplitFilter() !== null) {
                            if (strcasecmp($this->getRequeststatic::DAP_FILTER_REMOTE_URLataAddressProperty(static::DAP_FILTER_REMOTE_URL), $filter_endpoint) !== 0) {
                                throw new QueryBuilderException('Cannot use multiple filters requiring different custom URLs in one query: "' . $this->getRequestSplitFilter()->getCondition()->toString() . '" AND "' . $qpart->getCondition()->toString() . '"!');
                            }
                        } else {
                            // Remember the original filter (not it's copy from $requestFilters) for further processing!
                            $this->setRequestSplitFilter($queryFilters->getFilters()[$nr]);
                        }
                    }
            } 
            
            // All (other) query parts, that do not affect the endpoint, remain in the filter group.
            // This is important to process them in a single run, so complex filter expressions can be
            // built instead of an individual URL parameter for every filter.
        }
        
        // Add attributes needed for address property logic
        if ($group_alias = $thisObj->getDataAddressProperty(static::DAP_RESPONSE_GROUP_BY_ATTRIBUTE_ALIAS)) {
            $this->addAttribute($group_alias);
        }
        
        // Add URL parameters that may be required to select certain attributes
        if ($doSelect) {
            $params = $this->addParameterToUrl($params, $this->buildUrlParamsForAttributes($this->getAttributes()));
        }
        
        // build URL parameters from the filters remaining after the above preprocessing
        if ($doFilter && ! $requestFilters->isEmpty()) {
            $params = $this->addParameterToUrl($params, $this->buildUrlFilterGroup($requestFilters));
        }
        
        // Add pagination
        if ($doPaginate && ($this->getLimit() || $this->getOffset())) {
            $params = $this->addParameterToUrl($params, $this->buildUrlPagination());
        }
        
        // Add sorters
        if ($doSort && ($sorters = $this->buildUrlSorters())) {
            $params = $this->addParameterToUrl($params, $sorters);
        }
        
        // Add URL parameters that may be required to select certain attributes
        if ($doAggregate && ! empty($this->getAggregations())) {
            $params = $this->addParameterToUrl($params, $this->buildUrlParamsForAggregations($this->getAggregations()));
        }
        
        // Replace placeholders in endpoint
        $endpoint = $this->replacePlaceholdersInUrl($endpoint);
        
        if ($endpoint !== false) {
            // Run custom regexp transformations
            if ($replace_pattern = $thisObj->getDataAddressProperty(static::DAP_REQUEST_URL_REPLACE_PATTERN)) {
                $replace_with = $thisObj->getDataAddressProperty(static::DAP_REQUEST_URL_REPLACE_WITH);
                $endpoint = preg_replace($replace_pattern, $replace_with, $endpoint);
            }
            
            // Build the resulting query string
            $query_string = $endpoint;
        }
        
        if ($params) {
            $query_string .= (strpos($query_string, '?') !== false ? '&' : '?') . $params;
        }
        
        return new Request('GET', $query_string, $this->getHttpHeaders(self::OPERATION_READ));
    }
    
    /**
     * Returns a string of URL parameters required to ensure the response includes the required attributes.
     * 
     * By default, URL builders assume, that all attributes available are included. If not the
     * case, override this method to implement the corresponding logic.
     * 
     * @param QueryPartAttribute[] $qparts
     * @return string
     */
    protected function buildUrlParamsForAttributes(array $qparts) : string
    {
        return '';
    }
    
    /**
     * Returns a s tring of URL parameters required to aggregate the data in the response.
     * 
     * By defaul this mehtod returns an empty string as aggregation will mostly be done in-memory
     * after ertrieving the entire data from the server (see . If a specific URL builder should
     * support aggregation, override this method.
     * 
     * @param QueryPartAttribute[] $qparts
     * @return string
     */
    protected function buildUrlParamsForAggregations(array $qparts) : string
    {
        return '';
    }
    
    /**
     * Returns the part of the URL query responsible for sorting (without a leading "&"!)
     * 
     * @return string
     */
    protected function buildUrlSorters()
    {
        $url = '';
        $sorters = array();
        
        foreach ($this->getSorters() as $qpart) {
            $sorters[] = $this->buildUrlParamSorter($qpart);
        }
        if (! empty($sorters)) {
            $url = $this->addParameterToUrl($url, 'sort', implode(',', $sorters));
        }
        
        return $url;
    }
    
    
    
    /**
     * Returns a URL query string with parameters for the given filters  (without a leading "&"!).
     * 
     * By default, this method will treat each condition as a separate URL parameter: e.g.
     * &filter1=value1, etc.. Override this method to switch to a single URL param containing
     * a complex filter expression like the oData "$filter=cond1 eq val1 and cond2 eq val2".
     * 
     * @param QueryPartFilter[] $filters
     * @return string
     */
    protected function buildUrlFilterGroup(QueryPartFilterGroup $group)
    {
        $query = '';
        /* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartFilter */
        foreach ($group->getFilters() as $qpart) {
            if ($qpart->isCompound() && $qpart->getAttribute() instanceof CompoundAttributeInterface) {
                $query .= $this->buildUrlFilterGroup($qpart->getCompoundFilterGroup(), true);
            } else {
                $query = $this->addParameterToUrl($query, $this->buildUrlFilter($qpart));
            }
        }
        foreach ($group->getNestedGroups() as $qpart) {
            $query .= $this->buildUrlFilterGroup($qpart);
        }
        return $query;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilter()
     */
    protected function addFilter(QueryPartFilter $filter)
    {
        $result = parent::addFilter($filter);
        $this->prepareFilter($filter);
        return $result;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilterCondition()
     */
    public function addFilterCondition(Condition $condition)
    {
        $qpart = parent::addFilterCondition($condition);
        $this->prepareFilter($qpart);
        return $qpart;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilterGroup()
     */
    protected function addFilterGroup(QueryPartFilterGroup $filter_group)
    {
        $result = parent::addFilterGroup($filter_group);
        $this->prepareFilterGroup($filter_group);
        return $result;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilterConditionGroup()
     */
    public function addFilterConditionGroup(ConditionGroup $condition_group)
    {
        $qpart = parent::addFilterConditionGroup($condition_group);
        $this->prepareFilterGroup($qpart);
        return $qpart;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::setFiltersConditionGroup()
     */
    public function setFiltersConditionGroup(ConditionGroup $condition_group)
    {
        $result = parent::setFiltersConditionGroup($condition_group);
        $this->prepareFilterGroup($this->getFilters());
        return $result;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::setFilters()
     */
    protected function setFilters(QueryPartFilterGroup $filter_group)
    {
        $result = parent::setFilters($filter_group);
        $this->prepareFilterGroup($filter_group);
        return $result;
    }
    
    /**
     * 
     * @param QueryPartFilterGroup $group
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup
     */
    protected function prepareFilterGroup(QueryPartFilterGroup $group)
    {
        foreach ($group->getFilters() as $qpart) {
            $this->prepareFilter($qpart);
        }
        
        foreach ($group->getNestedGroups() as $qpart) {
            $this->prepareFilterGroup($qpart);
        }
        
        return $group;
    }

    /**
     * Checks the custom filter configuration of a query part for consistency.
     *
     * @param QueryPartFilter $qpart            
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilter
     */
    protected function prepareFilter(QueryPartFilter $qpart)
    {
        // If a local filter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always
        // in the result - otherwise there will be nothing to filter over ;)
        if ($this->getPropertyFilterLocally($qpart) === true) {
            $qpart->setApplyAfterReading(true);
            if ($qpart->getAttribute()) {
                $this->addAttribute($qpart->getAlias());
            }
        }
        return $qpart;
    }
    
    protected function getPropertyFilterRemote(QueryPartFilter $qpart) : ?bool
    {
        $val = BooleanDataType::cast($qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE));
        // If there are options for remote filtering set and the filter_remote address property is not explicitly off, enable it
        if ($val === null) {
            if ($qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_URL) || $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_URL_PARAM) || $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_PREFIX)) {
                $val = true;
            }
        }
        return $val;
    }
    
    protected function getPropertyFilterLocally(QueryPartFilter $qpart) : ?bool
    {
        $val = BooleanDataType::cast($qpart->getDataAddressProperty(static::DAP_FILTER_LOCALLY));
        // Enable local filtering if remote filters are not enabled and local filtering is not explicitly off
        if ($val === null && $this->getPropertyFilterRemote($qpart) !== true) {
            $val = true;
        }
        return $val;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addSorter()
     */
    public function addSorter($sort_by, $order = 'ASC', bool $addToAttributes = true) {
        $qpart = parent::addSorter($sort_by, $order, $addToAttributes);
        $this->prepareSorter($qpart);
        return $qpart;
    }

    /**
     * Checks the custom sorting configuration of a query part for consistency.
     *
     * @param QueryPartSorter $qpart            
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartSorter
     */
    protected function prepareSorter(QueryPartSorter $qpart)
    {
        if ($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE) !== null) {
            $qpart->setDataAddressProperty(static::DAP_SORT_REMOTE, BooleanDataType::cast($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE)));
        }
        if ($qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY) !== null) {
            $qpart->setDataAddressProperty(static::DAP_SORT_LOCALLY, BooleanDataType::cast($qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY)));
        }
        
        // If there are options for remote sorting set and the sort_remote address property is not explicitly off, enable it
        if ($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE_URL_PARAM)) {
            if ($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE) === '' || $qpart->getDataAddressProperty(static::DAP_SORT_REMOTE) === null) {
                $qpart->setDataAddressProperty(static::DAP_SORT_REMOTE, true);
            }
        }
        
        // Enable local sorting if remote sort is not enabled and local sorting is not explicitly off
        if (! $qpart->getDataAddressProperty(static::DAP_SORT_REMOTE) && ($qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY) === null || $qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY) === '')) {
            $qpart->setDataAddressProperty(static::DAP_SORT_LOCALLY, true);
        }
        
        // If a local sorter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always
        // in the result - otherwise there will be nothing to sort over ;)
        if ($qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY)) {
            $qpart->setApplyAfterReading(true);
            if ($qpart->getAttribute()) {
                $this->addAttribute($qpart->getAlias());
            }
        }
        
        return $qpart;
    }

    /**
     * Looks for placeholders in the give URL and replaces them with values from the corresponding filters.
     * Returns the given string with all placeholders replaced or FALSE if some placeholders could not be replaced.
     *
     * IDEA maybe throw an exception instead of returning false. If that exception is not caught, it might give
     * valuable information about what exactly went wrong.
     *
     * @param string $url_string            
     * @return string|boolean
     */
    protected function replacePlaceholdersInUrl($url_string, bool $strict = true, QueryPartFilterGroup $filterGroup = null)
    {
        $filterGroup = $filterGroup ?? $this->getFilters();
        foreach (StringDataType::findPlaceholders($url_string) as $ph) {
        	$foundPlaceholder = false;
            if ($ph_filter = $filterGroup->findFilterByAlias($ph)) {
                if (! is_null($ph_filter->getCompareValue())) {
                	$foundPlaceholder = true;
                    if ($this->getRequestSplitFilter() == $ph_filter && $ph_filter->getComparator() == ComparatorDataType::IN) {
                        $ph_value = explode($ph_filter->getValueListDelimiter(), $ph_filter->getCompareValue())[0];
                    } else {
                        $ph_value = $this->buildUrlFilterValue($ph_filter);
                    }
                    $this->setUrlPlaceholderValue($ph, $ph_value);
                    $url_string = str_replace('[#' . $ph . '#]', $ph_value, $url_string);
                } 
            } 
            
            if ($foundPlaceholder === null) {
                foreach ($this->getFilters()->getFilters() as $qpart) {
                    if ($qpart->isCompound() && $qpart->getAttribute() instanceof CompoundAttributeInterface) {
                        $urlOrFalse= $this->replacePlaceholdersInUrl($url_string, false, $qpart->getCompoundFilterGroup());    
                        
                        if ($urlOrFalse !== false){
                        	$foundPlaceholder = true;
                        	$url_string = $urlOrFalse;                 
                        }
                    }
                }                
            } 
            
            if ($foundPlaceholder === false) {
            	foreach ($this->getValues() as $query_part){
            		if ($ph == $query_part->getAttribute()->getAliasWithRelationPath()){
            			$values = $query_part->getValues();
            			$values = array_unique($values);
            			
            			if (count($values) === 1){
            				$ph_value = $values[0];
            				$url_string = str_replace('[#' . $ph . '#]', $ph_value, $url_string);
            				break;
            			}
            		}
            	}
            }            
        }
        
        if ($strict === true && empty(StringDataType::findPlaceholders($url_string)) === false) {
            return false;
        }
        
        return $url_string;
    }

    protected function addParameterToUrl($url, $parameter, $value = null)
    {
        if (! $parameter)
            return $url;
        return $url . ($url ? '&' : '') . ltrim($parameter, "&") . (! is_null($value) ? '=' . $value : '');
    }

    /**
     * Builds a URL filter from a filter query part: e.g.
     * subject=word1+word2+word3
     *
     * @param QueryPartFilter $qpart            
     * @return string
     */
    protected function buildUrlFilter(QueryPartFilter $qpart)
    {
        $filter = '';
        $param = $this->buildUrlParamFilter($qpart);
        
        if ($param) {
            $filter = $param . '=';
            
            // Add a prefix to the value if needed
            if ($prefix = $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_PREFIX)) {
                $filter .= $prefix;
            }
            
            // Add the value
            if (is_array($qpart->getCompareValue())) {
                $filter .= implode('+', $qpart->getCompareValue());
            } else {
                $filter .= $this->buildUrlFilterValue($qpart);
            }
        }
        
        return $filter;
    }
    
    /**
     * Returns a string representing the query part's value, that is usable in a filter expression.
     * 
     * @param QueryPartFilter $qpart
     * @param string $preformattedValue
     * @return string
     */
    protected function buildUrlFilterValue(QueryPartFilter $qpart, string $preformattedValue = null)
    {
        if ($preformattedValue !== null) {
            $value = $preformattedValue;
        } else {
            $value = $qpart->getCompareValue();
            try {
                $value = $qpart->getDataType()->parse($value);
            } catch (\Throwable $e) {
                throw new QueryBuilderException('Cannot create filter for "' . $qpart->getCondition()->toString() . '" - invalid data type!', null, $e);
            }
        }
        return $value;
    }
    
    /**
     * Returns the URL parameter (without value) for the given filter query part
     * 
     * @param QueryPartFilter $qpart
     * @return string
     */
    protected function buildUrlParamFilter(QueryPartFilter $qpart)
    {
        if (! $this->isRemoteFilter($qpart)) {
            return '';
        }
        
        $filter = '';
        // Determine filter name (URL parameter name)
        if ($param = $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_URL_PARAM)) {
            // Use the filter_remote_url_param if explicitly defined
            $filter = $param;
        } elseif (stripos($qpart->getDataAddress(), '->') !== 0) {
            // Use the data_address if it is not a property itself (starts with ->)
            $filter = $qpart->getDataAddress();
        }
        return $filter;
    }
    
    /**
     * Returns TRUE if the given filter query part uses remote filtering and FALSE otherwise.
     * 
     * @param QueryPartFilter $qpart
     * @return boolean
     */
    protected function isRemoteFilter(QueryPartFilter $qpart)
    {
        return BooleanDataType::cast($qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE)) || $qpart->getDataAddressProperty(static::DAP_FILTER_REMOTE_URL_PARAM);
    }

    /**
     * Returns the sorting URL parameter for the given sorter query part.
     * 
     * @param QueryPartSorter $qpart
     * @return string
     */
    protected function buildUrlParamSorter(QueryPartSorter $qpart)
    {
        if (! $this->isRemoteSorter($qpart)) {
            return '';
        }
        return ($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE_URL_PARAM) ? $qpart->getDataAddressProperty(static::DAP_SORT_REMOTE_URL_PARAM) : $qpart->getDataAddress());
    }
    
    /**
     * Returns TRUE if the given sorter query part uses remote sorting and FALSE otherwise.
     * 
     * @param QueryPartSorter $qpart
     * @return boolean
     */
    protected function isRemoteSorter(QueryPartSorter $qpart)
    {
        return BooleanDataType::cast($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE)) || $qpart->getDataAddressProperty(static::DAP_SORT_REMOTE_URL_PARAM);
    }

    /**
     * Returns TRUE if the given string is a valid data address and FALSE otherwise.
     *
     * @param string $data_address_string            
     * @return boolean
     */
    protected function checkValidDataAddress($data_address_string)
    {
        if (mb_stripos($data_address_string, '=') === 0)
            return false; // Formula
        return true;
    }

    /**
     * Extracts the actual data from the parsed response.
     * 
     * If the response contains metadata or any other overhead information, the actual
     * data rows must first be extracted from the response. This method will do to the
     * extraction based on the given response and data path.
     *
     * @param mixed $response            
     * @return mixed
     */
    protected function findRowData($parsed_response, $path)
    {
        // If the path is not empty, follow it. Otherwise just return the entire response.
        if ($path) {
            return $this->findFieldInData($path, $parsed_response);
        } else {
            return $parsed_response;
        }
    }
    
    /**
     * Builds the path from response root to the container with data rows.
     *
     * The path can be specified in each object using the data source options
     * uid_response_data_path and response_data_path. The former will be used
     * for signle-entity requests (typically a request with the UID as the only
     * filter), while the latter will be used for all other cases - that is,
     * whenever multiple entities are expected in the response.
     *
     * @return string
     */
    protected function buildPathToResponseRows(Psr7DataQuery $query)
    {
        switch ($query->getRequest()->getMethod()) {
            default:
                // TODO make work with any request_split_filter, not just the UID
                if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getAttribute()->isUidForObject() && ! is_null($this->getMainObject()->getDataAddressProperty(static::DAP_UID_RESPONSE_DATA_PATH))) {
                    $path = $this->getMainObject()->getDataAddressProperty(static::DAP_UID_RESPONSE_DATA_PATH);
                } else {
                    $path = $this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_DATA_PATH);
                }
        }
        return $path;
    }

    protected abstract function findFieldInData($data_address, $data);

    /**
     * Parse the response data into an array of the following form: 
     * [ 0 => ["field1" => "value1", "field2" => "value 2"], 1 => [...], ... ]
     *
     * @param mixed $data            
     * @return array
     */
    protected abstract function buildResultRows($parsed_data, Psr7DataQuery $query);
    
    /**
     * Resolves placeholders in data addresses, that refer to parts of the HTTP request or response.
     * 
     * @param array $dataRows
     * @param Psr7DataQuery $query
     * @throws QueryBuilderException
     * @return array
     */
    protected function buildResultRowsResolvePlaceholders(array $dataRows, Psr7DataQuery $query) : array
    {
        foreach ($this->getAttributes() as $qpart) {
            $addr = $qpart->getDataAddress();
            if (substr($addr, 0, 2) === '[#') {
                $placeholder = StringDataType::findPlaceholders($addr)[0];
                list($ph, $modifier) = explode(':', $placeholder, 2);
                $ph = mb_strtolower($ph);
                switch (true) {
                    case $ph === '~urlplaceholder':
                        $val = $this->getUrlPlaceholderValue($modifier);
                        break;
                    case $ph === '~urlparam':
                        $urlParams = [];
                        parse_str($query->getRequest()->getUri()->getQuery(), $urlParams);
                        $val = $urlParams[$modifier];
                        break;
                    default:
                        // Throw an error if the placeholder is not known and the query part was not
                        // handled somehow previously
                        if (! array_key_exists($qpart->getColumnKey(), $dataRows[0])) {
                            throw new QueryBuilderException('Unknown placeholder "' . $placeholder . '" in data address of attribute "' . $qpart->getAlias() . '"');
                        }
                }
                foreach (array_keys($dataRows) as $i) {
                    $dataRows[$i][$qpart->getColumnKey()] = $val;
                }
            }
        }
        return $dataRows;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
     */
    public function read(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $result_rows = array();
        $totalCnt = null;
        // Check if force filtering is enabled
        if ($this->getMainObject()->getDataAddressProperty(static::DAP_FORCE_FILTERING) && count($this->getFilters()->getFiltersAndNestedGroups()) < 1) {
            return new DataQueryResultData([], 0, false);
        }
        
        // Increase limit by one to check if there are more rows
        $usingExtraRowForPagination = false;
        if ($this->isRemotePaginationConfigured() === true) {
            $originalLimit = $this->getLimit();
            if ($originalLimit > 0) {
                $usingExtraRowForPagination = true;
                $this->setLimit($originalLimit+1, $this->getOffset());
            }
        }
        
        $query = $data_connection->query(new Psr7DataQuery($this->buildRequestToRead()));
        if ($data = $this->parseResponse($query)) {
            // See if the query has an IN-filter, that is set to split requests. This is quite common for URLs like mydomain.com/get_something/id=XXX.
            // If we filter over ids and have multiple values, we will need to make as many identical requests as there are values and merge
            // the results together here. So the easiest thing to do is perform this query multiple times, changing the split filter value each time.
            if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getComparator() == ComparatorDataType::IN) {
                $requiresMultipleReadRequests = true;
                // Since we are going to merge multipe result sets, it we need to be sure, there really is
                // only one row per UID value.
                $this->setUseUidsAsRowNumbers(true);
            } else {
                $requiresMultipleReadRequests = false;
            }
            
            // Find the total row counter within the response
            $totalCnt = $this->findRowCounter($data, $query);
            
            // Find data rows within the response and do the postprocessing
            $result_rows = $this->buildResultRows($data, $query);
            
            // If we increased the limit artificially, pop off the last result row as it
            // would not be there normally.
            if ($usingExtraRowForPagination === true && count($result_rows) === ($originalLimit+1)) {
                $hasMoreRows = true;
                array_pop($result_rows);
            } else {
                $hasMoreRows = false;
            }
            
            // Apply postprocessing options like `response_group_by_attribute_alias`
            $result_rows = $this->applyPostprocessing($result_rows);
            
            // Make more requests if we have multiple values for split filters
            // IDEA probably better to create separate query builders here rather than set
            // the internal subrequest id. Then each would be a fully functional query and
            // there would be much less voodoo.
            if ($requiresMultipleReadRequests === true) {
                $splitFilter = $this->getRequestSplitFilter();
                $split_values = explode($splitFilter->getValueListDelimiter(), $splitFilter->getCompareValue());
                $splitFilter->setComparator(ComparatorDataType::EQUALS);
                foreach ($split_values as $nr => $val) {
                    // skip the first UID as it has been fetched already
                    if ($nr === 0 || $splitFilter->getDataType()->isValueEmpty($val)) {
                        continue;
                    }
                    $this->setSubrequestNo($nr);
                    $splitFilter->setCompareValue($val);
                    $subquery = $data_connection->query(new Psr7DataQuery($this->buildRequestToRead()));
                    if ($data = $this->parseResponse($subquery)) {
                        $totalCnt = $totalCnt + $this->findRowCounter($data, $query);
                        $subquery_rows = $this->buildResultRows($data, $subquery);
                        $subquery_rows = $this->applyPostprocessing($subquery_rows);
                        $result_rows = array_merge($result_rows, $subquery_rows);
                    }
                }
                // Make sure, we give back the split filter it's initial value, in case any further code will be interested in filters.
                // This is particulary important if we need to apply additional filterin in-memory!
                $this->getRequestSplitFilter()->setCompareValue(implode($this->getRequestSplitFilter()->getValueListDelimiter(), $split_values));
            }
            
            // Apply local filters
            $cnt_before_local_filters = count($result_rows);
            $result_rows = $this->applyFilters($result_rows);
            $cnt_after_local_filters = count($result_rows);
            if ($cnt_before_local_filters !== $cnt_after_local_filters) {
                $totalCnt = $cnt_after_local_filters;
            }
            
            // Apply local aggregations
            $result_rows = $this->applyAggregations($result_rows, $this->getAggregationsToApplyAfterReading());
            
            // Apply local sorting
            $result_rows = $this->applySorting($result_rows);
            
            // Apply local pagination
            if (! $this->isRemotePaginationConfigured()) {
                if (! $totalCnt) {
                    $totalCnt = count($result_rows);
                }
                $result_rows = $this->applyPagination($result_rows);
            } else {
                if ($this->isRemotePaginationTotalAvailable() !== true && ($totalCnt === null || $totalCnt === '') && $usingExtraRowForPagination) {
                    //$totalCnt = count($result_rows)+1;
                }
            }
        } else {
            $hasMoreRows = false;
        }
        
        if ($hasMoreRows === false && ! $totalCnt) {
            $totalCnt = count($result_rows) + $this->getOffset();
        }
        
        return new DataQueryResultData($result_rows, count($result_rows), $hasMoreRows, $totalCnt);
    }
    
    /**
     * 
     * @return QueryPartAttribute[]
     */
    protected function getAggregationsToApplyAfterReading() : array
    {
        return $this->getAggregations();
    }

    protected function parseResponse(Psr7DataQuery $query)
    {
        return (string) $query->getResponse()->getBody();
    }

    /**
     *
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilter
     */
    protected function getRequestSplitFilter()
    {
        return $this->request_split_filter;
    }

    /**
     * Marks the query as a UID-based request.
     * The UID-filter is passed by reference, so it can be fetched and modified directly while
     * processing the query. This is important for data sources, where UID-requests must be split or handled differently in any other way.
     *
     * @param QueryPartFilter $qpart            
     * @return AbstractUrlBuilder
     */
    protected function setRequestSplitFilter(QueryPartFilter $qpart)
    {
        if ($qpart->getDataAddressProperty(self::DAP_FILTER_LOCALLY) === null) {
            $qpart->setApplyAfterReading(false);
            $qpart->setDataAddressProperty(self::DAP_FILTER_LOCALLY, false);
        }
        $this->request_split_filter = $qpart;
        return $this;
    }

    /**
     * 
     * @param array $result_rows
     * @return array
     */
    protected function applyPostprocessing(array $result_rows) : array
    {
        if ($group_attribute_alias = $this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_GROUP_BY_ATTRIBUTE_ALIAS)) {
            if ($this->getMainObject()->getDataAddressProperty(static::DAP_RESPONSE_GROUP_USE_ONLY_FIRST)) {
                $qpart = $this->getAttribute($group_attribute_alias);
                $group_value = null;
                foreach ($result_rows as $row_nr => $row) {
                    if (! $group_value) {
                        $group_value = $row[$qpart->getColumnKey()];
                        continue;
                    }
                    
                    if ($row[$qpart->getColumnKey()] != $group_value) {
                        unset($result_rows[$row_nr]);
                    }
                }
            }
        }
        return $result_rows;
    }

    /**
     * Returns TRUE if remote pagination for the main object is enabled and the
     * request parameters for limit and offset are set - FALSE otherwise.
     * 
     * @return boolean
     */
    protected function isRemotePaginationConfigured() : bool
    {
        $dsOption = BooleanDataType::cast($this->getMainObject()->getDataAddressProperty(static::DAP_REQUEST_REMOTE_PAGINATION));
        if ($dsOption === null) {
            return $this->buildUrlParamLimit($this->getMainObject()) ? true : false;
        } else {
            return BooleanDataType::cast($dsOption) && $this->buildUrlParamLimit($this->getMainObject());
        }
        return false;
    }
    
    /**
     * Returns TRUE if the webservice can provide the total number of records for paged results, FALSE if not and NULL if not specified.
     * @return bool|NULL
     */
    protected function isRemotePaginationTotalAvailable() : ?bool
    {
        $value = $this->getMainObject()->getDataAddressProperty(static::DAP_REQUEST_REMOTE_PAGINATION_HAS_TOTAL);
        if ($value === null || $value === '') {
            return null;
        }
        return BooleanDataType::cast($value);
    }
    
    /**
     * Returns the data address for the given attribute in the context of the specified http method.
     * 
     * Depending on the $operation, the custom data shource properties create_data_address, update_data_address,
     * etc. will be used instead of the regular data address of the attribute. The $operation should be
     * one of the static::OPERATION_xxx constants.
     * 
     * @param MetaAttributeInterface $attribute
     * @param string $operation
     * @return string
     */
    protected function buildDataAddressForAttribute(MetaAttributeInterface $attribute, $operation = self::OPERATION_READ)
    {
        $data_address = $attribute->getDataAddress();
        switch ($operation) {
            case static::OPERATION_CREATE:
                return ($attribute->getDataAddressProperty(static::DAP_CREATE_DATA_ADDRESS) ? $attribute->getDataAddressProperty(static::DAP_CREATE_DATA_ADDRESS) : $data_address);
            case static::OPERATION_UPDATE:
                return ($attribute->getDataAddressProperty(static::DAP_UPDATE_DATA_ADDRESS) ? $attribute->getDataAddressProperty(static::DAP_UPDATE_DATA_ADDRESS) : $data_address);
        }
        return $data_address;
    }
    
    /**
     * Returns the data address for the given object in the context of the specified http method.
     *
     * Depending on the $operation, the custom data shource properties create_request_data_address, 
     * update_request_data_address, etc. will be used instead of the regular data address of the object.
     * The $operation should be one of the static::OPERATION_xxx constants.
     *
     * @param MetaObjectInterface $attribute
     * @param string $method
     * @return string
     */
    protected function buildDataAddressForObject(MetaObjectInterface $object, $operation = self::OPERATION_READ)
    {
        switch ($operation) {
            case static::OPERATION_CREATE:
                $custom = $object->getDataAddressProperty(static::DAP_CREATE_REQUEST_DATA_ADDRESS); 
                break;
            case static::OPERATION_UPDATE:
                $custom = $object->getDataAddressProperty(static::DAP_UPDATE_REQUEST_DATA_ADDRESS);
                break;
            case static::OPERATION_DELETE:
                $custom = $object->getDataAddressProperty(static::DAP_DELETE_REQUEST_DATA_ADDRESS);
                break;
        }
        return $custom ? $custom : $object->getDataAddress();
    }
    
    /**
     * Returns the total number of rows matching the request (without pagination).
     * 
     * @param mixed $data
     * @return number|null
     */
    protected function findRowCounter($data, Psr7DataQuery $query)
    {
        $counterPath = $this->buildPathToTotalRowCounter($this->getMainObject());
        if ($counterPath === null || $counterPath === '') {
            return null;
        }
        return $this->findFieldInData($counterPath, $data);
    }
    
    /**
     * Returns the path to the total row counter (e.g. the response_total_count_path of the object)
     * 
     * @param MetaObjectInterface $object
     * @return string
     */
    protected function buildPathToTotalRowCounter(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty(static::DAP_RESPONSE_TOTAL_COUNT_PATH);
    }
    
    /**
     * Returns URL parameters needed for remote pagination.
     * 
     * Returns an empty string if remote pagination is not used.
     * 
     * @return string
     */
    protected function buildUrlPagination() : string
    {
        $params = '';
        if ($this->isRemotePaginationConfigured() === false) {
            return $params;
        }
        
        if ($limitParam = $this->buildUrlParamLimit($this->getMainObject())) {
            $params = $this->addParameterToUrl($params, $limitParam, $this->getLimit());
        }
        if ($offsetParam = $this->buildUrlParamOffset($this->getMainObject())) {
            $params = $this->addParameterToUrl($params, $offsetParam, $this->getOffset());
        }
        elseif (($pageParam = $this->buildUrlParamPage($this->getMainObject())) && $this->getLimit() !== 0) {
            if ($this->getOffset() == 0) {
                $value = 1;
            } else {
                $value = intval(round($this->getOffset() / $this->getLimit())) + 1;
            }
            $params = $this->addParameterToUrl($params, $pageParam, $value);
        }
        return $params;
    }
    
    protected function buildUrlParamOffset(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty(static::DAP_REQUEST_OFFSET_PARAMETER);
    }
    
    protected function buildUrlParamPage(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty(static::DAP_REQUEST_PAGE_PARAMETER);
    }
    
    protected function buildUrlParamLimit(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty(static::DAP_REQUEST_LIMIT_PARAMERTER);
    }
    
    /**
     * Generally UrlBuilders can only handle attributes of one objects - no relations (JOINs) supported!
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::canReadAttribute()
     */
    public function canReadAttribute(MetaAttributeInterface $attribute) : bool
    {
        return $attribute->getRelationPath()->isEmpty();
    }
    
    /**
     * Returns the HTTP method for a given operation (e.g. static::OPERATION_READ).
     * 
     * Override this method to change HTTP methods used.
     * 
     * @param string $operation
     * @return string
     */
    protected function getHttpMethod(string $operation) : string
    {
        $o = $this->getMainObject();
        switch ($operation) {
            case static::OPERATION_CREATE: return $o->getDataAddressProperty(static::DAP_CREATE_REQUEST_METHOD) ? $o->getDataAddressProperty(static::DAP_CREATE_REQUEST_METHOD) : 'PUT';
            case static::OPERATION_READ: return $o->getDataAddressProperty(static::DAP_READ_REQUEST_METHOD) ? $o->getDataAddressProperty(static::DAP_READ_REQUEST_METHOD) : 'GET';
            case static::OPERATION_UPDATE: return $o->getDataAddressProperty(static::DAP_UPDATE_REQUEST_METHOD) ? $o->getDataAddressProperty(static::DAP_UPDATE_REQUEST_METHOD) : 'PATCH';
            case static::OPERATION_DELETE: return $o->getDataAddressProperty(static::DAP_DELETE_REQUEST_METHOD) ? $o->getDataAddressProperty(static::DAP_DELETE_REQUEST_METHOD) : 'DELETE';
        }
        
        return 'POST';
    }
    
    /**
     * Returns the HTTP headers for a given operation (e.g. static::OPERATION_READ).
     * 
     * The array structure must be compatible with PSR7
     * 
     * @return string[][]
     */
    protected function getHttpHeaders(string $operation) : array
    {
        return [];
    }
    
    /**
     * 
     * @param bool $trueOrFalse
     * @return AbstractUrlBuilder
     */
    protected function setUseUidsAsRowNumbers(bool $trueOrFalse) : AbstractUrlBuilder
    {
        $this->UseUidsAsRowNumbers = $trueOrFalse;
        return $this;
    }
    
    /**
     * Returns TRUE if the uniqueness of UIDs must be enforced in each result.
     * 
     * @return bool
     */
    protected function getUseUidsAsRowNumbers() : bool
    {
        if ($this->UseUidsAsRowNumbers !== null) {
            return $this->UseUidsAsRowNumbers;
        }
        
        $obj = $this->getMainObject();
        return (BooleanDataType::cast($obj->getDataAddressProperty(static::DAP_READ_REQUEST_REMOVE_AMBIGUOUS_UIDS)) === true && $obj->hasUidAttribute() === true);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::count()
     */
    public function count(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        if ($this->isRemotePaginationTotalAvailable() !== true) {
            return new DataQueryResultData([], 0, true, null);
        }
        return $this->read($data_connection);
    }
    
    protected function setUrlPlaceholderValue(string $placeholder, $value) : AbstractUrlBuilder
    {
        $this->urlPlaceholders[$placeholder][$this->getSubrequestNo()] = $value;
        return $this;
    }
    
    protected function getUrlPlaceholderValue(string $placeholder) : string
    {
        $value = $this->urlPlaceholders[$placeholder][$this->getSubrequestNo()];
        if ($value === null) {
            throw new QueryBuilderException('Placeholder "~urlplaceholders:' . $placeholder . '" cannot be resolved: no such placeholder was used in the URL!');
        }
        return $value;
    }
    
    protected function getSubrequestNo() : int
    {
        return $this->subrequestNo;
    }
    
    protected function setSubrequestNo(int $number) : AbstractUrlBuilder
    {
        $this->subrequestNo = $number;
        return $this;
    }
    
    protected function isPartOfUrl(QueryPartAttribute $queryPartAttribute) : bool 
    {
    	switch (true){
    		case mb_stripos($queryPartAttribute->getDataAddress(), '[#~urlplaceholder:') !== false:
    		case mb_stripos($queryPartAttribute->getDataAddress(), '[#~urlparam:') !== false:
    			return true;
    		default:
    			return false;
    	}
    }
}