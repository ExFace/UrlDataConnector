<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\DataSheets\DataCollector;
use exface\Core\CommonLogic\Debugger\LogBooks\ActionLogBook;
use exface\Core\CommonLogic\Debugger\LogBooks\DataLogBook;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\MimeTypeDataType;
use exface\Core\DataTypes\PhpClassDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\Actions\ActionInputError;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataSourceFactory;
use exface\Core\Factories\ExpressionFactory;
use exface\Core\Factories\FormulaFactory;
use exface\Core\Factories\QueryBuilderFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Actions\iCallService;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Model\MetaAttributeGroupInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\DataSheetPlaceholder;
use exface\Core\Templates\Placeholders\PlaceholderGroup;
use exface\Core\Templates\Placeholders\TranslationPlaceholders;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\UrlDataConnector\Interfaces\Psr7QueryBuilderInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Calls a web service using parameters to fill placeholders in the URL and body of the HTTP request.
 * 
 * This action will send an HTTP request for every row of the input data unless `separate_requests_for_each_row`
 * is explicitly set to `false`. The action model allows to customize common HTTP request properties: 
 * `url`, `method`, `body`, `headers`.
 * 
 * ## Parameters
 * 
 * You can define parameters for the action to be filled from input data. Each parameter defines a possible input 
 * value of the action. Parameters have unique names and always belong to one of these groups: `url` parameters 
 * or `body` parameters. The name of a parameter must match a column name in the actions input (it is not always 
 * the same as an attribute alias!).
 *
 * If the `group` of a parameter is omitted, it will depend on the request method: parameters of
 * GET-requests are treated as URL-parameters, while POST-request parameters will be placed in the body.
 * 
 * ## Payload - URL and body
 * 
 * The `url` and the `body` can be defined as 
 * 
 * - templates with placeholders
 * - JSON generated from `parameters`
 * - urlencoded data generated from `parameters`
 * 
 * In any case the `parameters` array can be used to control, what data is required to call the webservice.
 * Parameter models allow to control, which parameters are required, what data types and default values to use, 
 * etc. - similar to attributes of meta objects, but less complex. Parameters are kept separately from attributes
 * to allow a different configuration for each action. However, you can also set `parameters_use_attributes` to 
 * use attribute configuration for parameters with the same name.
 * 
 * In any case, action parameters and will get filled with input data when the action is performed. 
 * Parameter/placeholder names must match column names of that input data!
 * 
 * ### Templates for request URL and body
 * 
 * If you choose to write templates with placeholders for `body` and/or `url`, a separate request will be
 * sent for every input row. You cannot set `separate_requests_for_each_row` to `false` in this case.
 * 
 * You can use placeholders with or without an explicit `parameters` definition or even in mixed mode. If there
 * is no parameter with a name, that matches the placeholder, a new parameter will be created automatically. These
 * auto-generated parameters are simple strings, but are considered required. You can always add an explicit
 * parameter definition later to customize the behavior of your placeholders.
 * 
 * Placeholder/parameters can also be based on attributes of the actions object if `parameters_use_attributes`
 * is set to `true`. In this case, even auto-generated parameters will get more detailed configuration automatically
 * if their name matches the alias of an attribute. This way, you can use the same placeholder across multiple
 * actions of the same object and keep its model centralized in the corresponding attribute.
 * 
 * #### Supported placeholder types:
 *
 * - `[#PARAM_NAME#]` - automatically creates a parameter for the attribute or a column in the input data
 * - `[#~input:SOME_ATTRIBUTE#]` - same as above, but in a more explicit notation
 * - `[#~input:=Concat(SOME_ATTRIBUTE, ',', OTHER_ATTRIBUTE#]` - the `~input:` prefix also allows formulas!
 * In this case, the attributes required for the formula will become parameters of the action.
 * - `[#~config:app_alias:config_key#]` - will be replaced by the value of the `config_key` in the given app.
 * These placeholders will not be converted to parameters as their values do not depend on the input data.
 * - `[#~translate:app_alias:translation_key#]` - will be replaced by the translation of the `translation_key`
 * from the given app. These placeholders will not be converted to parameters as their values do not depend on
 * the input data.
 * 
 * #### Nested data placeholders
 *
 * Using `body_data_placeholders` you can even define your own placeholders, that will be filled by
 * reading data related to the input - similarly as in printing actions. This way, you can build complex
 * nested bodies.
 * 
 * ### Auto-generated body and URL from action parameters
 * 
 * Instead of writing templates for `body` and `url` manually, you can let the action generate them for you. You will
 * need a list of parameters though. Just like in the templates above, these parameters can either be based on
 * attributes via `parameters_for_all_attributes_from_group` or totally independently via `parameters` or even with
 * a mixture of both.
 * 
 * URL-parameters will be automatically transformed into a URI query string consiting of parameter names as keys and
 * the respective input data values. For body-parameters you will need to specify the desired `content_type`:
 * 
 * - `application/x-www-form-urlencoded` or
 * - `application/json`
 * 
 * By default, all parameters to be put into the webservice call must be explicitly defined in `parameters`. You can
 * bind them to attributes of the actions object with a matching name by turning on `parameters_use_attributes` - in
 * this case, you will just need to put the names in `parameters` and no data types, etc. You can also auto-generate
 * parameters for all attributes of a certain group via `parameters_for_all_attributes_from_group`. This is helpful
 * if the web service requires ALL parameter to be present all the time, but only some are really important for
 * certain calls.
 * 
 * If you need more complex structures, than key-value pairs, either use templates or set `parameters_use_attributes`
 * to `true` and define data addresses in the attributes in the same way as you would do for URL query builders.
 * 
 * ## Handling empty values
 * 
 * The workbench does not normalize empty values to `null` or empty string automatically. Empty values are kept the
 * way they were received from outside by default. However, many web services have rules, how to pass empty
 * values correctly. To change empty value handling, you will need a `parameters` model, where you can use 
 * `empty_as_null` to normalize empty values to `null` or `empty_expression` to use a custom expression to indicate,
 * that a value was empty.
 * 
 * Note the difference between `empty_expression` and `default_value`: the former tells the web service, that the
 * value is empty, while the latter replaces an empty value by a non-empty default.
 * 
 * ## Success messages and action results
 * 
 * The result of `CallWebservice` consists of a message and a data sheet. By default, both are pretty empty, but 
 * you can configure a lot of option to extract messages and data from the web service response. Specialized
 * versions of the action like `CallOData2Operation` may also yield meaningful messages and data by themselves.
 * 
 * ### Result messages
 * 
 * In the most generic case, you can use the following action properties to extract a result message
 * from the HTTP response:
 * 
 * - `result_message_pattern` - a regular expression to extract the result message from
 * the response - see examples below.
 * - `result_message_text` - a text or a static formula (e.g. `=TRANSLATE()`) to be
 * displayed if no errors occur. 
 * - If `result_message_text` and `result_message_pattern` are both specified, the static
 * text will be prepended to the extracted result. This is useful for web services, that
 * respond with pure data - e.g. an importer serves, that returns the number of items imported.
 * 
 * ### Result data
 * 
 * The result data sheet is based on the actions object and will be empty by default. However, if the action is
 * based on an object with an HTTP compatible query builder, you can list attributes of that object to be read
 * from the response in `result_data_columns`. The object will use its query builder (e.g. `JsonUrlBuilder`)
 * to parse the response and extract data - just like it would do for regular GET or POST requests.
 * 
 * Often narrow-scoped web services (e.g. to calculate something) have fixed input and output formats. These
 * can be modeled as separate metaobject, that are neither readable nor writable because the workbench cannot
 * use its regular read or write logic on them. The input object should then get an action describing the web
 * service and that action should have a `result_data_sheet` transforming its response into usable data.
 * This way, input and output of the service will be clearly visible in the model and easily usable in widgets
 * and other actions.
 *
 *  ```
 *  {
 *   "alias": "exface.UrlDataConnector.CallWebService",
 *   "data_source_alias": "my.App.web_api",
 *   "url": "api/v1/carrier-routes/search",
 *   "content_type": "application/json",
 *   "method": "POST",
 *   "parameters_use_attributes": true,
 *   "parameters_for_all_attributes_from_group": "~ALL",
 *   "result_message_pattern": "/\"items\":\\s*(?<items>\\[[\\s\\S]*\\])/",
 *   "error_message_pattern": "/\"title\":\"([^\"]*)\"/",
 *   "input_object_alias": "my.App.CarrierRouteSearchCommand",
 *   "result_data_sheet": {
 *       "object_alias": "my.App.CarrierRouteSearchResponse",
 *       "columns": [
 *           {"attribute_alias": "rank"},
 *           {"attribute_alias": "carrierId__LABEL"},
 *           {"attribute_alias": "basePrice"}
 *       ]
 *   }
 *  }
 *
 *  ```
 * 
 * ## Error messages
 * 
 * Similarly, you can make the action look for error messages in the HTTP response
 * if the web service produces informative.
 * 
 * - `error_message_pattern` - a regular expression to find the error message (this will
 * make this error message visible to the users!)
 * - `error_code_pattern` - a regular expression to find the error code (this will
 * make this error code visible to the users!)
 * 
 * ## Examples
 * 
 * ### Simple GET-request with placeholders 
 * 
 * The service returns the following JSON if successfull: `{"result": "Everything OK"}`.
 * 
 * ```
 *  {
 *      "url": "http://url.toyouservice.com/service?param1=[#param1_data_column#]",
 *      "result_message_pattern": "/\"result":"(?<message>[^"]*)\"/i"
 *  }
 * 
 * ```
 * 
 * The placeholder `[#param1_attribute_alias#]` in the URL will be automatically
 * transformed into a required service parameter, so we don't need to define any
 * `parameters` manually. When the action is performed, the system will look for
 * a data column named `param1_data_column` and use it's values to replace the
 * placeholder. If no such column is there, an error will be raised. 
 * 
 * The `result_message_pattern` will be used to extract the success message from 
 * the response body (i.e. "Everything OK"), that will be shown to the user once 
 * the service responses.
 * 
 * ### GET-request with typed and optional parameters
 * 
 * If you need optional URL parameters or require type checking, you can use the
 * `parameters` property of the action to add detailed information about each
 * parameter: in particular, it's data type.
 * 
 * Compared to the first example, the URL here does not have any placeholders.
 * Instead, there is the parameter `param1`, which will produce `&param1=...`
 * in the URL. The value will be expected in the input data column named `param1`.
 * You can use an `input_mapper` in the action's configuration to map a column
 * with a different name to `param1`.
 * 
 * The second parameter is optional and will only be appended to the URL if
 * the input data contains a matching column with non-empty values.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/service",
 *  "result_message_pattern": "/\"result":"(?<message>[^"]*)\"/i",
 *  "parameters": [
 *      {
 *          "name": "param1",
 *          "required": true,
 *          "data_type": {
 *              "alias": "exface.Core.Integer"
 *          }
 *      },{
 *          "name": "mode",
 *          "data_type": {
 *              "alias": "exface.Core.GenericStringEnum",
 *              "values": {
 *                  "mode1": "Mode 1",
 *                  "mode2": "Mode 2"
 *              }
 *          }
 *      }
 *  ]
 * }
 * 
 * ```
 * 
 * You can even mix placeholders and explicitly defined parameters. In this case, if no parameter
 * name matches a placeholder's name, a new simple string parameter will be generated
 * automatically.
 * 
 * ### POST-request with a JSON body-template
 * 
 * Similarly to URLs in GET-requests, placeholders can be used in the body of a POST request. 
 * 
 * The following code shows a POST-version of the first GET-example above.
 * 
 * ```
 *  {
 *      "url": "http://url.toyouservice.com/service",
 *      "result_message_pattern": "/\"result":"(?<message>[^"]*)\"/i",
 *      "method": "POST",
 *      "content_type": "application/json",
 *      "body": "{"data": {\"param1\": \"[#param1_data_column#]\"}}"
 *  }
 * 
 * ```
 * 
 * Note the extra `content_type` property: this is the same as setting a `Content-Type` header in the
 * request. Most web services will require such a header, so it is a good idea to set it in the action's 
 * configuration. You can also use the `headers` property for even more customization.
 * 
 * The more detailed `parameters` definition can be used with templated POST requests too - just make sure,
 * the placeholder names in the template match parameter names. However, placeholders, that are not in the 
 * `parameters` list will be ignored here because the action cannot know where to put the in the template.
 * 
 * ### POST-request with a complex body with nested data
 * 
 * This action will send a JSON POST request with the basic structure of a meta object: the
 * objects name and full alias and a list of attributes.
 * 
 * ```
 *  {
 *     "alias": "exface.UrlDataConnector.CallWebService",
 *     "object_alias": "exface.Core.OBJECT",
 *     "url": "http://localhost/test",
 *     "method": "POST",
 *     "data_source_alias": "exface.UrlDataConnector.REMOTE_JSON",
 *     "body": "{\n  \"name\" : \"[#~input:NAME#]\",\n  \"alias\" : \"[#~input:=Concat(APP__ALIAS, '.', ALIAS)#]\"\n[#attributes#]}",
 *     "body_data_placeholders": {
 *       "attributes": {
 *         "row_template": "    {\n      \"alias\" : \"[#attributes:ALIAS#]\",\n      \"name\" : \"[#attributes:NAME#]\"}",
 *         "row_delimiter": ",",
 *         "outer_template": ",\"attributes\": [ [#~rows#] ]",
 *         "data_sheet": {
 *           "object_alias": "exface.Core.ATTRIBUTE",
 *           "filters": {
 *             "operator": "AND",
 *             "conditions": [
 *               {
 *                 "expression": "OBJECT",
 *                "comparator": "==",
 *                 "value": "[#~input:UID#]"
 *               }
 *             ]
 *           }
 *         }
 *       }
 *     }
 *   }
 *   
 * ```
 * 
 * Find the resulting body for the object `exface.Core.MESSAGE` below. Note, if there are
 * no attributes, the `attributes` property will be removed completely!
 * 
 * ```
 *  {
 *      "name": "Message",
 *      "alias": "exface.Core.MESSAGE",
 *      "attributes": [
 *          {"name": "Docs path", "alias": "DOCS" },
 *          {...}
 *      ]
 *  
 *  }
 *  
 * ```
 * 
 * ### POST-request with parameters and a generated form data body
 * 
 * An alternative to the use of `body` templates is to have the body generated from parameters. This only
 * works for content types `application/x-www-form-urlencoded` and `application/json`. In this
 * case you can define required and optional parameters and the correspoinding fields of the body will
 * appear accordingly.
 * 
 * POST requests may have placeholders in the body and in the URL at the same time. The corresponding parameters
 * will belong to respective groups `url` and `body` then. Thus, you can explicitly control, which part of
 * the request a parameter is meant for.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/[#endpoint#]",
 *  "result_message_pattern": "\"result":"(?<message>[^"]*)\"",
 *  "content_type": "application/x-www-form-urlencoded",
 *  "parameters": [
 *      {
 *          "name": "endpoint",
 *          "group": "url"
 *      },{
 *          "name": "param1",
 *          "group": "body",
 *          "required": true,
 *          "data_type": {
 *              "alias": "exface.Core.Integer"
 *          }
 *      },{
 *          "name": "mode",
 *          "group": "body",
 *          "data_type": {
 *              "alias": "exface.Core.GenericStringEnum",
 *              "values": {
 *                  "mode1": "Mode 1",
 *                  "mode2": "Mode 2"
 *              }
 *          }
 *      }
 *  ]
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class CallWebService extends AbstractAction implements iCallService 
{
    const PARAMETER_GROUP_BODY = 'body';
    
    const PARAMETER_GROUP_URL = 'url';
    
    private $separateRequestsPerRow = true;
    
    /**
     * @var ServiceParameterInterface[]
     */
    private array $parameters = [];
    private bool $parametersUseAttrs = false;
    private ?string $parametersForAllAttrsFromGroup = null;
    
    /**
     * @var bool
     */
    private $parametersGeneratedFromPlaceholders = false;
    
    /**
     * @var string|NULL
     */
    private $url = null;
    
    /**
     * @var string|NULL
     */
    private $method = null;
    
    /**
     * Array of HTTP headers with lowercased header names
     * 
     * @var string[]
     */
    private $headers = [];
    
    private $contentType = null;
    
    /**
     * @var string|NULL
     */
    private $body = null;
    private $bodyUxon = null;
    
    /**
     * 
     * @var UxonObject|NULL
     */
    private $dataPlaceholders = null;
    
    /**
     * @var string|DataSourceInterface|NULL
     */
    private $dataSource = null;

    /**
     * @var string|NULL
     */
    private $resultMessagePattern = null;
    private ?UxonObject $resultDataSheetUxon = null;
    private $errorMessagePattern = null;
    private $errorCodePattern = null;
    
    private bool $debugFlag = false;
    private ?UxonObject $debugResponseUxon = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Actions\ShowWidget::init()
     */
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::COGS);
    }

    /**
     * 
     * @return string|NULL
     */
    protected function getUrl() : ?string
    {
        return $this->url;
    }

    /**
     * The URL to call: absolute or relative to the data source - supports [#placeholders#].
     * 
     * Any `parameters` with group `url` will be appended to the URL automatically. If there
     * are parameters without a group, they will be treated as URL parameters for request
     * methods, that typically do not have a body - e.g. `GET` and `OPTIONS`.
     * 
     * @uxon-property url
     * @uxon-type uri
     * 
     * @param string $url
     * @return CallWebService
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * 
     * @return string
     */
    protected function getMethod($default = 'GET') : string
    {
        return $this->method ?? $default;
    }

    /**
     * The HTTP method: GET, POST, etc.
     * 
     * @uxon-property method
     * @uxon-type [GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD,TRACE]
     * @uxon-default GET
     * 
     * @param string
     */
    public function setMethod(string $method) : CallWebService
    {
        $this->method = $method;
        return $this;
    }

    /**
     * 
     * @return string[]
     */
    protected function getHeaders() : array
    {
        return $this->headers;
    }
    
    /**
     * 
     * @return string[]
     */
    protected function buildHeaders() : array
    {
        $headers = $this->getHeaders();
        
        if ($this->getContentType() !== null) {
            $headers['content-type'] = $this->getContentType();
        }
        
        return $headers;
    }

    /**
     * Special HTTP headers to be sent: these headers will override the defaults of the data source.
     * 
     * @uxon-property headers
     * @uxon-type object
     * @uxon-template {"Content-Type": ""}
     * 
     * @param UxonObject|array $uxon_or_array
     */
    public function setHeaders($uxon_or_array) : CallWebService
    {
        if ($uxon_or_array instanceof UxonObject) {
            $this->headers = $uxon_or_array->toArray(CASE_LOWER);
        } elseif (is_array($uxon_or_array)) {
            $this->headers = $uxon_or_array;
        } else {
            throw new ActionConfigurationError($this, 'Invalid format for headers property of action ' . $this->getAliasWithNamespace() . ': expecting UXON or PHP array, ' . gettype($uxon_or_array) . ' received.');
        }
        return $this;
    }
    
    /**
     * Populates the request body with parameters from a given row by replaces body placeholders 
     * (if a body-template was specified) or creating a body according to the content type.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @param $method
     * @return string
     */
    protected function buildBody(DataSheetInterface $data, string $method, ActionLogBook $logbook, ?int $rowNr = null) : string
    {
        $body = $this->getBody();
        try {
            if ($body === null) {
                if ($this->getDefaultParameterGroup($method) === self::PARAMETER_GROUP_BODY) {
                    return $this->buildBodyFromParameters($data, $method, $logbook, $rowNr);
                } else {
                    $logbook->addLine('Body empty because no `body` template given and no `parameters` found to build a body from');
                    return '';
                }
            } else {
                return $this->buildBodyFromTemplate($body, $data, $method, $logbook, $rowNr);
            }
        } catch (\Throwable $e) {
            throw new ActionRuntimeError($this, 'Cannot build HTTP body for webservice request. ' . $e->getMessage(), null, $e);
        }
    }
    
    /**
     * 
     * @param string $template
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @param string $method
     * @return string
     */
    protected function buildBodyFromTemplate(string $template, DataSheetInterface $data, string $method, ActionLogBook $logbook, ?int $rowNr = null) : string
    {
        // TODO add support for multiple input-rows - use DataRowPlaceholders 
        if ($rowNr === null) {
            throw new ActionRuntimeError('Cannot call webservice with a `body` template for multple input rows at once! Set `separate_requests_for_each_row` to TRUE to handle multiple input rows!');
        }
        
        $rowRenderer = new BracketHashStringTemplateRenderer($this->getWorkbench());
        $rowRenderer->addPlaceholder(new ConfigPlaceholders($this->getWorkbench(), '~config:'));
        $rowRenderer->addPlaceholder(new TranslationPlaceholders($this->getWorkbench(), '~translate:'));
        
        // Add a placeholder renderer for all service parameters related to the body
        $params = $this->getParameters(self::PARAMETER_GROUP_BODY, $logbook);
        foreach ($params as $param) {
            $name = $param->getName();
            $val = $data->getCellValue($name, $rowNr);
            $val = $this->prepareParamValue($param, $val) ?? '';
            $paramValues[$name] = $val;
        }
        $rowRenderer->addPlaceholder(new ArrayPlaceholders($paramValues));
        
        // Add resolvers for input data, that is not described by service parameters
        $rowRenderer->addPlaceholder(new DataRowPlaceholders($data, $rowNr, '~input:'));
            
        // Add resolvers for body_data_placeholders
        $dataPhsUxon = $this->getBodyDataPlaceholdersUxon();
        if ($dataPhsUxon !== null) {
            // Prepare a renderer for the body_data_placeholders config
            $dataTplRenderer = new BracketHashStringTemplateRenderer($this->getWorkbench());
            $dataTplRenderer->addPlaceholder(
                (new DataRowPlaceholders($data, $rowNr, '~input:'))
                ->setFormatValues(false)
                ->setSanitizeAsUxon(true)
            );
            
            // Create group-resolver with resolvers for every data_placeholder and use
            // it as the default resolver for the input row renderer
            $dataPhsResolverGroup = new PlaceholderGroup();
            $dataPhsBaseRenderer = $rowRenderer->copy();
            foreach ($dataPhsUxon->getPropertiesAll() as $ph => $phConfig) {
                // Add a resolver for the data-placeholder: e.g. `[#ChildrenData#]` for the entire child sub-template
                // and `[#ChildrenData:ATTR1#]` to address child-data values inside that sub-template
                $dataPhsResolverGroup->addPlaceholderResolver(new DataSheetPlaceholder($ph, $phConfig, $dataTplRenderer, $dataPhsBaseRenderer));
            }
            $rowRenderer->setDefaultPlaceholderResolver($dataPhsResolverGroup);
        }
        
        // Render the body
        $renderedBody = $rowRenderer->render($template);
        return $renderedBody;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildMethod(DataSheetInterface $data, int $rowNr) : string
    {
        $method = $this->getMethod();
        $placeholders = StringDataType::findPlaceholders($method);
        if (empty($placeholders) === true) {
            return $method;
        }
        $phValues = $data->getRow($rowNr);
        $method = StringDataType::replacePlaceholders($method, $phValues);
        
        return $method;        
    }
    
    /**
     * Returns the request body built from service parameters according to the content type.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @param string $method
     * @return string
     */
    protected function buildBodyFromParameters(DataSheetInterface $data, string $method, ActionLogBook $logbook, ?int $rowNr = null) : string
    {
        $logbook->addLine('Generating request body from service parameters ' . ($rowNr === null ? 'for all rows' : 'for row `' . $rowNr . '`'));
        $logbook->addIndent(+1);
        $str = '';
        $contentType = $this->getContentType();
        $defaultGroup = $this->getDefaultParameterGroup($method);
        $useAttributes = $this->willUseAttributesAsParameters();
        $obj = $this->getMetaObject();
        switch (true) {
            // JSON array of objects
            case MimeTypeDataType::isJson($contentType) && $rowNr === null:
                $str = '';
                foreach ($data->getRowIndexes() as $rowNr) {
                    $str .= ($str ? ', ' : '') . $this->buildBodyFromParameters($data, $method, $logbook, $rowNr);
                }
                $str = '[' . $str . ']';
                break;
            // JSON object
            case MimeTypeDataType::isJson($contentType):
                $params = [];
                foreach ($this->getParameters(null, $logbook) as $param) {
                    if ($param->getGroup($defaultGroup) !== self::PARAMETER_GROUP_BODY) {
                        continue;
                    }
                    $name = $param->getName();
                    $path = null;
                    if ($useAttributes && $obj->hasAttribute($name)) {
                        $path = $obj->getAttribute($name)->getDataAddress();
                    }
                    if ($path === null) {
                        $path = $name;
                    }
                    $val = $data->getCellValue($name, $rowNr);
                    $val = $this->prepareParamValue($param, $val);
                    $params = ArrayDataType::replaceViaXPath($params, $path, $val);
                }
                $str = json_encode($params);
                break;
            // Urlencoded array
            case strcasecmp($contentType, MimeTypeDataType::URLENCODED) === 0 && $rowNr === null:
                $formRows = [];
                foreach ($data->getRowIndexes() as $rowNr) {
                    $formRow = [];
                    foreach ($this->getParameters(null, $logbook) as $param) {
                        if ($param->getGroup($defaultGroup) !== self::PARAMETER_GROUP_BODY) {
                            continue;
                        }
                        $name = $param->getName();
                        $val = $data->getCellValue($name, $rowNr);
                        $val = $this->prepareParamValue($param, $val) ?? '';
                        $formRow[$name] = $val;
                    }
                    $formRows[] = $formRow;
                }
                $str = http_build_query($formRows);
                break;
            // Urlencoded row
            case strcasecmp($contentType, MimeTypeDataType::URLENCODED) === 0:
                foreach ($this->getParameters(null, $logbook) as $param) {
                    if ($param->getGroup($defaultGroup) !== self::PARAMETER_GROUP_BODY) {
                        continue;
                    }
                    $name = $param->getName();
                    $val = $data->getCellValue($name, $rowNr);
                    $val = $this->prepareParamValue($param, $val) ?? '';
                    $str .= '&' . urlencode($name) . '=' . urlencode($val);
                }
                break;
            default:
                $logbook->addLine('Cannot generate body because no `content_type` specified. Can only generate body from parameters for `' . MimeTypeDataType::URLENCODED . '` and `' . MimeTypeDataType::JSON . '`');
        }
        $logbook->addIndent(-1);
        return $str;
    }

    /**
     * 
     * @return string
     */
    protected function getBody() : ?string
    {
        return $this->body;
    }

    /**
     * @return bool
     */
    protected function isBodyDefinedAsUxon() : bool
    {
        return $this->bodyUxon !== null;
    }

    /**
     * @return UxonObject|null
     */
    protected function getBodyUxon() : ?UxonObject
    {
        return $this->bodyUxon;
    }

    /**
     * The body of the HTTP request - [#placeholders#] are supported.
     * 
     * If no body template is specified, the body will be generated automatically for
     * content types `application/json` and `application/x-www-form-urlencoded` - this
     * autogenerated body will contain all parameters, that belong to the `body` group.
     * If there are parameters without a group, they will be treated as URL parameters 
     * for request methods, that typically do not have a body - e.g. `GET` and `OPTIONS`.
     * 
     * ### Supported placeholder types:
     *
     * - `[#SOME_ATTRIBUTE#]` - automatically creates a parameter for the attribute or a column in the input data
     * - `[#~input:SOME_ATTRIBUTE#]` - same as above, but in a more explicit notation
     * - `[#~input:=Concat(SOME_ATTRIBUTE, ',', OTHER_ATTRIBUTE#]` - the `~input:` prefix also allows formulas!
     * In this case, the attributes required for the formula will become parameters of the action.
     * - `[#~config:app_alias:config_key#]` - will be replaced by the value of the `config_key` in the given app.
     * These placeholders will not be converted to parameters as their values do not depend on the input data.
     * - `[#~translate:app_alias:translation_key#]` - will be replaced by the translation of the `translation_key`
     * from the given app. These placeholders will not be converted to parameters as their values do not depend on
     * the input data.
     * 
     * @uxon-property body
     * @uxon-type string
     * 
     * @uxon-placeholder [#<metamodel:attribute>#]
     * @uxon-placeholder [#~input:<metamodel:attribute>#]
     * @uxon-placeholder [#~input:<metamodel:formula>#]
     * @uxon-placeholder [#~config:<metamodel:app>:<string>#]
     * @uxon-placeholder [#~translate:<metamodel:app>:<string>#]
     * 
     * @param string $body
     * @return $this;
     */
    public function setBody($body) : CallWebService
    {
        $this->body = $body;
        return $this;
    }

    /**
     * The body of the HTTP request as JSON object or array - [#paceholders#] are supported.
     * 
     * This is the same as `body`, but takes a JSON object or array as value instead of plain
     * string. It is more convenient to use `body_json` for JSON requests than a plain `body`.
     * 
     * ### Supported placeholder types:
     *
     * - `[#SOME_ATTRIBUTE#]` - automatically creates a parameter for the attribute or a column in the input data
     * - `[#~input:SOME_ATTRIBUTE#]` - same as above, but in a more explicit notation
     * - `[#~input:=Concat(SOME_ATTRIBUTE, ',', OTHER_ATTRIBUTE#]` - the `~input:` prefix also allows formulas!
     * In this case, the attributes required for the formula will become parameters of the action.
     * - `[#~config:app_alias:config_key#]` - will be replaced by the value of the `config_key` in the given app.
     * These placeholders will not be converted to parameters as their values do not depend on the input data.
     * - `[#~translate:app_alias:translation_key#]` - will be replaced by the translation of the `translation_key`
     * from the given app. These placeholders will not be converted to parameters as their values do not depend on
     * the input data.
     * 
     * @uxon-property body_json
     * @uxon-type object
     * @uxon-template {"":""}
     *
     * @uxon-placeholder [#<metamodel:attribute>#]
     * @uxon-placeholder [#~input:<metamodel:attribute>#]
     * @uxon-placeholder [#~input:<metamodel:formula>#]
     * @uxon-placeholder [#~config:<metamodel:app>:<string>#]
     * @uxon-placeholder [#~translate:<metamodel:app>:<string>#]
     * 
     * @param UxonObject $json
     * @return $this
     */
    protected function setBodyJson(UxonObject $json) : CallWebService
    {
        $this->bodyUxon = $json;
        $this->body = $json->toJson();
        if ($this->contentType === null) {
            $this->contentType = MimeTypeDataType::JSON;
        }
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        $input = $this->getInputDataSheet($task);
        $logbook = $this->getLogBook($task);
        $this->logbook = $logbook;
        
        $resultSheet = $this->getResultDataSheet();
        $resultSheet->setAutoCount(false);
        
        $rowCnt = $input->countRows();
        $requestCnt = $rowCnt;
        if ($rowCnt === 0 && $this->getInputRowsMin() === 0) {
            $requestCnt = 1;
        }
        if ($this->hasSeparateRequestsForEachRow() === false) {
            $requestCnt = 1;
        }
        
        // Make sure all required parameters are present in the data
        $logbook->addLine('Preparing input data fow webservice: ' . DataLogBook::buildTitleForData($input));
        $logbook->addIndent(+1);
        $logbook->addLine('Looking for webservice parameters');
        $params = $this->getParameters(null, $logbook);
        $logbook->addLine('Checking if input data has all required parameters');
        $input = $this->getDataWithParams($input, $params, $logbook);  
        $logbook->addIndent(-1);
        
        $httpConnection = $this->getDataConnection();

        // Call the webservice for every row in the input data.
        $logbook->addLine('Sending **' . $requestCnt . '** HTTP requests for **' . $rowCnt . '** input rows via connection `' . $httpConnection->getAliasWithNamespace() . '`');
        $logbook->addIndent(+1);
        for ($i = 0; $i < $requestCnt; $i++) {
            $method = $this->buildMethod($input, $i);
            if ($requestCnt === 1 && $rowCnt > 1) {
                $body = $this->buildBody($input, $method, $logbook);
            } else {
                $body = $this->buildBody($input, $method, $logbook, $i);
            }
            $request = new Request($method, $this->buildUrl($input, $i, $method, $logbook), $this->buildHeaders(), $body);
            $query = new Psr7DataQuery($request);
            // Perform the query regularly via URL connector
            try {
                $response = $httpConnection->query($query)->getResponse();
            } catch (\Throwable $e) {
                throw new ActionRuntimeError($this, 'Error in remote web service call #' . ($i+1) . ': ' . $e->getMessage(), null, $e);
            }
            $resultCntPrev = $resultSheet->countRows();
            $resultSheet = $this->parseResponse($request, $response, $resultSheet);
            $logbook->addLine('Request ' . ($i+1) . ' returned ' . ($resultSheet->countRows() - $resultCntPrev) . ' data rows');
        }
        $logbook->addIndent(-1);
        $resultSheet->setCounterForRowsInDataSource($resultSheet->countRows());
        
        // If the input and the result are based on the same meta object, we can (and should!)
        // apply filters and sorters of the input to the result. Indeed, having the same object
        // merely means, we need to fill the sheet with data, which, of course, should adhere
        // to its settings.
        if ($input->getMetaObject()->is($resultSheet->getMetaObject())) {
            $logbook->addLine('Filters and sorters will be applied to result data');
            if ($input->getFilters()->isEmpty(true) === false) {
                $resultSheet = $resultSheet->extract($input->getFilters());
            }
            if ($input->hasSorters() === true) {
                $resultSheet->sort($input->getSorters());
            }
        } else {
            $logbook->addLine('Filters and sorters will NOT be applied to result data because input and result are based on different meta objects: ' . $input->getMetaObject()->__toString() . ' vs ' . $resultSheet->getMetaObject()->__toString());
        }

        $resultText = $this->getResultMessageText();
        $resultTextPattern = $this->getResultMessagePattern();
        switch (true) {
            case $resultText && $resultTextPattern:
                $respMessage = $this->getResultMessageText() . $this->getMessageFromResponse($response);
                break;
            case $resultText:
                $respMessage = $resultText;
                break;
            case $resultTextPattern:
                $respMessage = $this->getMessageFromResponse($response);
                break;
            default:   
                $respMessage = $this->getWorkbench()->getApp('exface.UrlDataConnector')->getTranslator()->translate('ACTION.CALLWEBSERVICE.DONE');;
        }
        
        return ResultFactory::createDataResult($task, $resultSheet, $respMessage);
    }
    
    /**
     * 
     * @return HttpConnectionInterface
     */
    protected function getDataConnection() : HttpConnectionInterface
    {
        if ($this->dataSource !== null) {
            if (! $this->dataSource instanceof DataSourceInterface) {
                $this->dataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->dataSource);
            }
            $conn = $this->dataSource->getConnection();
        } else {
            $conn = $this->getMetaObject()->getDataConnection();
        }
        
        if (! $conn instanceof HttpConnectionInterface) {
            throw new ActionConfigurationError($this, 'Invalid data connection type for CallWebService action: expecting an HTTP connector, got ' . PhpClassDataType::findClassNameWithoutNamespace($conn));
        }
        
        // If changes to the connection config are needed, clone the connection before
        // applying them!
        if ($this->errorMessagePattern !== null || $this->errorCodePattern !== null) {
            if (! ($conn instanceof HttpConnector)) {
                throw new ActionConfigurationError($this, 'Cannot use a custom `error_message_pattern` or `error_code_pattern` with data connection "' . $conn->getAliasWithNamespace() . '"!');
            }
            $conn = clone($conn);
            if ($this->errorMessagePattern !== null) {
                $conn->setErrorTextPattern($this->errorMessagePattern);
            }
            if ($this->errorCodePattern !== null) {
                $conn->setErrorCodePattern($this->errorCodePattern);
            }
        }
        
        if ($this->isDebugMode()) {
            if (! $conn instanceof HttpConnectionInterface) {
                throw new ActionConfigurationError($this, 'Cannot use debug mode with other connections than HttpConnector!');
            }
            $conn->setDebug(true);
            if ($this->getDebugResponse() !== null) {
                $conn->setDebugResponse($this->getDebugResponse());
            }
        }
        
        return $conn;
    }
    
    /**
     * Use this the connector of this data source to call the web service.
     * 
     * If the data source is not specified directly via `data_source_alias`, the data source
     * of the action's meta object will be used.
     * 
     * @uxon-property data_source_alias
     * @uxon-type metamodel:data_source
     * 
     * @param string $idOrAlias
     * @return CallWebService
     */
    public function setDataSourceAlias(string $idOrAlias) : CallWebService
    {
        $this->dataSource = $idOrAlias;
        return $this;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @param string $method
     * @return string
     */
    protected function buildUrl(DataSheetInterface $data, int $rowNr, string $method, ActionLogBook $logbook) : string
    {
        $url = $this->getUrl() ?? '';
        $params = '';
        $urlPlaceholders = StringDataType::findPlaceholders($url);
        
        $urlPhValues = [];
        $defaultGroup = $this->getDefaultParameterGroup($method);
        foreach ($this->getParameters(null, $logbook) as $param) {
            $group = $param->getGroup($defaultGroup);
            if ($group !== null && $group !== self::PARAMETER_GROUP_URL) {
                continue;
            }
            $name = $param->getName();
            $val = $data->getCellValue($name, $rowNr);
            $val = $this->prepareParamValue($param, $val) ?? '';
            if (in_array($param->getName(), $urlPlaceholders) === true) {
                $urlPhValues[$name] = $val;
            } else {
                $params .= '&' . urlencode($name) . '=' . urlencode($val);
            }
        }
        if (empty($urlPhValues) === false) {
            $url = StringDataType::replacePlaceholders($url, $urlPhValues);
        }
        
        return $url . (strpos($url, '?') === false ? '?' : '') . $params;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @return DataSheetInterface
     */
    protected function getDataWithParams(DataSheetInterface $data, array $parameters, ActionLogBook $logbook) : DataSheetInterface
    {
        // TODO #DataCollector
        foreach ($parameters as $param) {
            if (! $data->getColumns()->get($param->getName())) {
                if ($data->getMetaObject()->hasAttribute($param->getName()) === true) {
                    if ($data->hasUidColumn(true) === true) {
                        $logbook->addLine('Loading ' . ($param->isRequired() ? 'required' : 'optional') . ' `' . $param->getName() . '` additionally');
                        $attr = $data->getMetaObject()->getAttribute($param->getName());
                        $data->getColumns()->addFromAttribute($attr);
                    } elseif ($param->isRequired()) {
                        $logbook->addLine('Missing `' . $param->getName() . '`, but cannot load it because there are no UIDs');
                    } 
                } elseif ($param->isRequired()) {
                    $logbook->addLine('Missing `' . $param->getName() . '`, but it is not an attribute of the object ' . $data->getMetaObject()->__toString());
                }
            }
        }
        if ($data->isFresh() === false) {
            switch (true) {
                case $data->getMetaObject()->isReadable() === false:
                    $logbook->addLine('Cannot load missing data because the input object ' . $data->getMetaObject()->__toString() . ' is marked as not readable!');
                    break;
                case $data->hasUidColumn(true) === false:
                    $logbook->addLine('Cannot load missing data because the input data does not have a UID column!');
                    break;
                default:
                    $logbook->addLine('Loading missing data', 2);
                    try {
                        $data->getFilters()->addConditionFromColumnValues($data->getUidColumn());
                        $data->dataRead();
                    } catch (\Throwable $e) {
                        throw new ActionInputMissingError($this, 'Cannot read missing input data for action ' . $this->getAliasWithNamespace() . '!');
                    }
            } 
        } else {
            $logbook->addLine('No need to load missing data - everything is fine');
        }
        return $data;
    }
    
    /**
     * 
     * @param ServiceParameterInterface $parameter
     * @param mixed $val
     * @return mixed
     */
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val)
    {
        $type = $parameter->getDataType();
        
        // If value is not there, use default
        if ($val === null && $parameter->hasDefaultValue() === true) {
            $val = $parameter->getDefaultValue();
        }
        
        // If value is empty, format it properly
        if ($type::isValueEmpty($val) && null !== $emptyExpr = $parameter->getEmptyExpression()) {
            $val = $emptyExpr->evaluate();
        }
        
        if ($parameter->isRequired() && $type::isValueEmpty($val)) {
            throw new ActionInputMissingError($this, 'Value of required parameter "' . $parameter->getName() . '" not set! Please include the corresponding column in the input data or use an input_mapper!', '75C7YOQ');
        }
        try {
            return $parameter->getDataType()->parse($val);
        } catch (\Throwable $e) {
            throw new ActionInputError($this, 'Invalid value "' . $val . '" for webservice parameter "' . $parameter->getName() . '"! ' . $e->getMessage(), null, $e);
        }
    }
    
    /**
     *
     * @return ServiceParameterInterface[]
     */
    public function getParameters(string $group = null, ActionLogBook $logbook = null) : array
    {
        // Fill parameter cache 
        if ($this->parametersGeneratedFromPlaceholders === false) {
            $this->parametersGeneratedFromPlaceholders = true;
            $logbook?->addIndent(+1);
            
            $expclicitParamGroups = [];
            $defaultGroup = $this->getDefaultParameterGroup($this->getMethod());
            foreach ($this->parameters as $param) {
                $expclicitParamGroups[$param->getName()] = $param->getGroup($defaultGroup);
            }
            $paramNames = empty($expclicitParamGroups) ? '' : ' `' . implode('`, `', array_keys($expclicitParamGroups)) . '`';
            $logbook?->addLine('`parameters` defined in action explicitly - ' . count($expclicitParamGroups) . '.' . $paramNames);
            
            // Generate parameters from attributes - but only if there is no such parameter explicitly defined
            if ($this->willGenerateParametersFromAttributes()) {
                $paramsFromAttributes = [];
                foreach ($this->getAttributeGroupToGenerateParameters()->getAttributes() as $attr) {
                    $paramUxon = $this->findParameterUxonInAttributes($attr->getAliasWithRelationPath());
                    $name = $paramUxon->getProperty('name');
                    if ($defaultGroup !== ($expclicitParamGroups[$name] ?? null)) {
                        $param = new ServiceParameter($this, $paramUxon);
                        $paramsFromAttributes[] = $param->getName();
                        $this->parameters[] = $param;
                    }
                }
                $paramNames = empty($paramsFromAttributes) ? '' : ' `' . implode('`, `', $paramsFromAttributes) . '`';
                $logbook?->addLine('`parameters_for_all_attributes_from_group` - ' . count($paramsFromAttributes) . '.' . $paramNames . '.');
            } else {
                $logbook?->addLine('`parameters_for_all_attributes_from_group` - not set.');
            }

            // Generate parameters from template placeholders - but only if that parameter does not exist yet!
            $paramsFromPhs = [];
            $paramsFromUrl = [];
            $paramsFromBody = [];
            if (null !== $tpl = $this->getBody()) {
                $paramsFromBody = $this->findParametersInBody($tpl);
                $paramNames = empty($paramsFromBody) ? '' : ' `' . implode('`, `', array_keys($paramsFromBody)) . '`';
                $logbook?->addLine('`body` placeholders - ' . count($paramsFromBody) . '.' . $paramNames . '.');
            } 
            if (null !== $tpl = $this->getUrl()) {
                $paramsFromUrl = array_merge($paramsFromPhs, $this->findParametersInUrl($tpl));
                $paramNames = empty($paramsFromUrl) ? '' : ' `' . implode('`, `', array_keys($paramsFromUrl)) . '`';
                $logbook?->addLine('`url` placeholders - ' . count($paramsFromUrl) . '.' . $paramNames . '.');
            }
            // Parameters from the URL and from the body will probably belong to different groups, so make sure to
            // keep them all - even if they have the same name!
            $paramsFromPhs = array_merge(array_values($paramsFromBody), array_values($paramsFromUrl));
            $paramNamesIgnored = [];
            foreach($paramsFromPhs as $paramGenerated) {
                if ($defaultGroup !== ($expclicitParamGroups[$paramGenerated->getName()] ?? null)) {
                    $this->parameters[] = $paramGenerated;
                } else {
                    $paramNamesIgnored[] = $paramGenerated->getName();
                }
            }
            if (! empty($paramNamesIgnored)) {
                $paramNames = ' `' . implode('`, `', $paramNamesIgnored) . '`';
                $logbook?->addLine('Ignoring ' . count($paramNamesIgnored) . ' parameters because they have a different group.' . $paramNames . '.');
            }
            $logbook?->addIndent(-1);
        }
        
        // If a specific group is requested, filter the cache.
        // Otherwise return it as-is.
        if ($group !== null) {
            $filtered = [];
            foreach ($this->parameters as $param) {
                if ($param->getGroup($group) === $group) {
                    $filtered[] = $param;
                }
            }
            return $filtered;
        } else {
            return $this->parameters;
        }
    }
    
    /**
     * 
     * @param string $urlTpl
     * @return ServiceParameterInterface[]
     */
    protected function findParametersInUrl(string $urlTpl) : array
    {
        $params = [];
        $phs = StringDataType::findPlaceholders($urlTpl);
        $useAttributes = $this->willUseAttributesAsParameters();
        foreach ($phs as $ph) {
            if (! $this->hasParameter($ph)) {
                $paramUxon = new UxonObject([
                    "name" => $ph,
                    "required" => true,
                    "group" => self::PARAMETER_GROUP_URL
                ]);
                if ($useAttributes && null !== $attrUxon = $this->findParameterUxonInAttributes($ph)) {
                    $paramUxon = $paramUxon->extend($attrUxon);
                }
                $param = new ServiceParameter($this, $paramUxon);
                $params[$param->getName()] = $param;
            }
        }
        return $params;
    }
    
    /**
     * 
     * @param string $bodyTpl
     * @return ServiceParameterInterface[]
     */
    protected function findParametersInBody(string $bodyTpl) : array
    {
        $params = [];
        $bodyPhs = StringDataType::findPlaceholders($bodyTpl);
        $bodyPhsFiltered = [];
        $bodyDataPhsUxon = $this->getBodyDataPlaceholdersUxon();
        if ($bodyDataPhsUxon !== null) {
            $bodyDataPhs = $bodyDataPhsUxon->getPropertyNames();
        }
        foreach ($bodyPhs as $ph) {
            // Remove data placeholders as they are not parameters
            foreach ($bodyDataPhs as $dataPh) {
                if ($ph === $dataPh || StringDataType::startsWith($ph, $dataPh . ':')) {
                    continue 2;
                }
            }
            // Treat attributes from formula placehlders as parameters
            if (Expression::detectFormula($ph)) {
                $formula = FormulaFactory::createFromString($this->getWorkbench(), $ph);
                $bodyPhsFiltered = array_merge($bodyPhsFiltered, $formula->getRequiredAttributes(true));
                continue;
            }
            // Keep simple body placehodlers
            if (mb_substr($ph, 0, 1) !== '~') {
                $bodyPhsFiltered[] = $ph;
                continue;
            }
            // Keep input body placeholders
            if (StringDataType::startsWith($ph, '~input:') === true) {
                $param = StringDataType::substringAfter($ph, '~input:');
                $paramExpr = ExpressionFactory::createFromString($this->getWorkbench(), $param);
                foreach ($paramExpr->getRequiredAttributes() as $paramAttr) {
                    $bodyPhsFiltered[] = $paramAttr;
                }
                continue;
            }
            // Remove all other types of body placeholders - they are not parameters
            // and will be handled by buildBodyFromTemplate()
        }
        $bodyPhsFiltered = array_unique($bodyPhsFiltered);
        
        $useAttributes = $this->willUseAttributesAsParameters();
        foreach ($bodyPhsFiltered as $ph) {
            if (! $this->hasParameter($ph)) {
                $paramUxon = new UxonObject([
                    "name" => $ph,
                    "required" => true,
                    "group" => self::PARAMETER_GROUP_BODY
                ]);
                if ($useAttributes && $attrParamUxon = $this->findParameterUxonInAttributes($ph)) {
                    $uxon = $attrParamUxon;
                }
                $param = new ServiceParameter($this, $paramUxon);
                $params[$param->getName()] = $param;
            }
        }
        
        return $params;
    }
    
    protected function findParameterUxonInAttributes(string $paramName) : ?UxonObject
    {
        $obj = $this->getMetaObject();
        if ($obj->hasAttribute($paramName)) {
            $attr = $obj->getAttribute($paramName);
        }
        if ($attr === null) {
            return null;
        }
        $uxon = new UxonObject([
            'name' => $paramName,
            'data_type' => $attr->getCustomDataTypeUxon()->extend(new UxonObject([
                'alias' => $attr->getDataType()->getAliasWithNamespace()
            ]))->toArray(),
            'required' => $attr->isRequired(),
        ]);
        if ($attr->hasDefaultValue()) {
            $uxon->setProperty('default_value', $attr->getDefaultValue()->__toString());
        }
        return $uxon;
    }
    
    /**
     * Defines parameters supported by the service.
     *
     * @uxon-property parameters
     * @uxon-type \exface\Core\CommonLogic\Actions\ServiceParameter[]
     * @uxon-template [{"name": ""}]
     *
     * @param UxonObject $value
     * @return CallWebService
     */
    public function setParameters(UxonObject $uxon) : CallWebService
    {
        $useAttributes = $this->willUseAttributesAsParameters();
        foreach ($uxon as $paramUxon) {
            // If we match parameters with attributes and there is 
            if ($useAttributes && $attrParamUxon = $this->findParameterUxonInAttributes($paramUxon->getProperty('name'))) {
                $paramUxon = $attrParamUxon->extend($paramUxon);
            }
            $this->parameters[] = new ServiceParameter($this, $paramUxon);
        }
        return $this;
    }

    /**
     * Set to TRUE to make parameters defined for this action use the model of attributes with the same name
     * 
     * To make this work, the action will need to have some parameters - either set explicitly 
     * 
     * For example, if your webservice has an `id` parameter and the object of the action has a corresponding
     * attribute with a matching alias, you do not need to define the data type, default value, etc. for the
     * parameter separately - they can be inherited from the attribute if `parameters_use_attributes` is `true`.
     * 
     * Regular `parameters` will use the attribute model as base. This means, if you set `required` for the
     * parameter explicitly for an action, it will override the attributes `required` setting for this particular
     * action.
     * 
     * Auto-generated parameters made from placeholders will also use the attribute model. Thus, if a placeholder
     * matches an attribute, it will be not treated as a simple string, but will get the data type and other 
     * configuration from the attribute. On top of that, you can define a parameter with the same name and
     * customize it even further.
     * 
     * @uxon-property parameters_use_attributes
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $trueOrFalse
     * @return $this
     */
    protected function setParametersUseAttributes(bool $trueOrFalse) : CallWebService
    {
        $this->parametersUseAttrs = $trueOrFalse;
        return $this;
    }

    /**
     * @return bool
     */
    protected function willUseAttributesAsParameters() : bool
    {
        return $this->parametersUseAttrs;
    }
    
    /**
     * 
     * @param string $name
     * @return bool
     */
    public function hasParameter(string $name) : bool
    {
        foreach ($this->getParameters() as $arg) {
            if ($arg->getName() === $name) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallService::getParameter()
     */
    public function getParameter(string $name) : ServiceParameterInterface
    {
        foreach ($this->getParameters() as $arg) {
            if ($arg->getName() === $name) {
                return $arg;
            }
        }
        throw new ActionInputMissingError($this, 'Parameter "' . $name . '" not found in action "' . $this->getAliasWithNamespace() . '"!');
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallService::getServiceName()
     */
    public function getServiceName() : string
    {
        return $this->getUrl();
    }

    /**
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param DataSheetInterface $resultSheet
     * @return DataSheetInterface
     */
    protected function parseResponse(RequestInterface $request, ResponseInterface $response, DataSheetInterface $resultSheet) : DataSheetInterface
    {
        $resultObj = $resultSheet->getMetaObject();
        $requiredCols = $resultSheet->getColumns();
        if (! $requiredCols->isEmpty() && $resultObj->hasDataSource()) {
            $queryBuilder = QueryBuilderFactory::createForObject($resultObj);
            if (! $queryBuilder instanceof Psr7QueryBuilderInterface) {
                throw new ActionConfigurationError($this, 'Cannot parse HTTP response to fill `result_data_columns` for object ' . $resultObj->__toString() . ': the query builder of the object is not supported!');
            }
            $calculatedCols = [];
            $relatedCollector = new DataCollector($resultObj);
            foreach ($requiredCols->getAll() as $col) {
                $expr = $col->getExpressionObj();
                switch (true) {
                    case $expr->isMetaAttribute() && $expr->getAttribute()->isRelated():
                        $foreignKeyAlias = $expr->getAttribute()->getRelationPath()->getRelationFirst()->getLeftKeyAttribute()->getAlias();
                        $resultSheet->getColumns()->addFromExpression($foreignKeyAlias);
                        $queryBuilder->addAttribute($foreignKeyAlias);
                        $resultSheet->getColumns()->remove($col);
                        $relatedCollector->addExpression($expr);
                        break;
                    case $expr->isMetaAttribute():
                        $queryBuilder->addAttribute($expr->__toString(), $col->getName());
                        break;
                    case $expr->isFormula():
                        $calculatedCols[] = $col;
                        break;
                    default:
                        throw new ActionConfigurationError($this, 'Expression `' . $expr->__toString() . '` cannot be read from web serivce response: only attribute aliases, formulas and scalar values allowed!');
                }
            }
            $queryResult = $queryBuilder->readResponse($request, $response);
            $resultSheet->addRows($queryResult->getResultRows(), false, false);
            foreach ($calculatedCols as $col) {
                $col->setValuesByExpression($col->getExpressionObj(), false);
            }
            if (! $relatedCollector->isEmpty()) {
                $relatedCollector->enrich($resultSheet);
            }
        }
        return $resultSheet;
    }
    
    /**
     * 
     * @return MetaObjectInterface
     */
    protected function getResultObject() : MetaObjectInterface
    {
        if ($this->hasResultObjectRestriction()) {
            return $this->getResultObjectExpected();
        }
        return $this->getMetaObject();
    }
    
    /**
     *
     * @return string|NULL
     */
    protected function getResultMessagePattern() : ?string
    {
        return $this->resultMessagePattern;
    }
    
    /**
     * A regular expression to retrieve the result message from the body - the first match is returned or one explicitly named "message".
     * 
     * Extracts a result message from the response body.
     * 
     * For example, if the web service would return the following JSON
     * `{"result": "Everything OK"}`, you could use this regex to get the
     * message: `/"result":"(?<message>[^"]*)"/`.
     * 
     * @uxon-property result_message_pattern
     * @uxon-type string
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setResultMessagePattern(string $value) : CallWebService
    {
        $this->resultMessagePattern = $value;
        return $this;
    }
    
    /**
     * 
     * @param ResponseInterface $response
     * @return string|NULL
     */
    protected function getMessageFromResponse(ResponseInterface $response) : ?string
    {
        $body = $response->getBody()->__toString();
        if ($this->getResultMessagePattern() === null) {
            return $body;
        }
        
        $matches = [];
        preg_match($this->getResultMessagePattern(), $body, $matches);
        
        if (empty($matches)) {
            return null;
        }
        $msg = $matches['message'] ?? $matches[1];
        //remove escaping characters
        $msg = stripcslashes($msg);
        return $msg;
    }
    
    /**
     * 
     * @return string
     */
    public function getContentType() : ?string
    {
        return $this->contentType ?? ($this->headers['content-type'] ?? null);
    }
    
    /**
     * Set the content type for the request.
     * 
     * @uxon-property content_type
     * @uxon-type [application/x-www-form-urlencoded,application/json,text/plain,application/xml]
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setContentType(string $value) : CallWebService
    {
        $this->contentType = trim($value);
        return $this;
    }
    
    /**
     * Use a regular expression to extract messages from error responses - the first match is returned or one explicitly named "message".
     * 
     * This works the same, as `error_text_pattern` of an `HttpConnector`, but allows
     * to override the configuration for this single action.
     * 
     * @uxon-property error_message_pattern
     * @uxon-type string
     * @uxon-template /"error":"([^"]*)"/
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setErrorMessagePattern(string $value) : CallWebService
    {
        $this->errorMessagePattern = $value;
        return $this;
    }
    
    /**
     * Use a regular expression to extract error codes from error responses - the first match is returned or one explicitly named "code".
     * 
     * This works the same, as `error_code_pattern` of an `HttpConnector`, but allows
     * to override the configuration for this single action.
     * 
     * @uxon-property error_code_pattern
     * @uxon-type string
     * @uxon-template /"errorCode":"([^"]*)"/
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setErrorCodePattern(string $value) : CallWebService
    {
        $this->errorCodePattern = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getEffects()
     */
    public function getEffects() : array
    {
        return array_merge(parent::getEffects(), $this->getEffectsFromModel());
    }
    
    /**
     * 
     * @string $method
     * @return string
     */
    protected function getDefaultParameterGroup(string $method) : string
    {
        $m = mb_strtoupper($method);
        return $m === 'GET' || $m === 'OPTIONS' ? self::PARAMETER_GROUP_URL : self::PARAMETER_GROUP_BODY;
    }
    
    /**
     * 
     * @return bool
     */
    protected function hasSeparateRequestsForEachRow() : bool
    {
        return $this->separateRequestsPerRow;
    }
    
    /**
     * Set to FALSE to send a single HTTP request regardless of the number of input data rows
     * 
     * @uxon-property separate_requests_for_each_row
     * @uxon-type boolean
     * @uxon-default true 
     * 
     * @param bool $value
     * @return CallWebService
     */
    protected function setSeparateRequestsForEachRow(bool $value) : CallWebService
    {
        $this->separateRequestsPerRow = $value;
        return $this;
    }
    
    /**
     * 
     * @return UxonObject|NULL
     */
    protected function getBodyDataPlaceholdersUxon() : ?UxonObject
    {
        return $this->dataPlaceholders;
    }
    
    /**
     * Additional data placeholders to be provided to the body template
     *
     * @uxon-property body_data_placeholders
     * @uxon-type \exface\Core\Templates\Placeholders\DataSheetPlaceholder[]
     * @uxon-template {"": {"row_template": "", "data_sheet": {"object_alias": "", "filters": {"operator": "AND", "conditions": [{"expression": "", "comparator": "", "value": ""}]}}}}
     *
     * @param UxonObject $value
     * @return CallWebService
     */
    public function setBodyDataPlaceholders(UxonObject $value) : CallWebService
    {
        $this->dataPlaceholders = $value;
        return $this;
    }

    /**
     * Automatically generate parameters for all attributes from this attribute group.
     * 
     * This is handy, if you have a separate meta object with attributes for every parameter of the web service. 
     * Using this option you can auto-generate action parameter from the attributes instead of defining them in
     * the action configuration. If the object has more attributes, put all parameter-attributes in an attribute 
     * group and use that.
     * 
     * @uxon-property parameters_for_all_attributes_from_group
     * @uxon-type metamodel:attribute_group
     * @uxon-template ~WRITABLE
     * 
     * @param string $groupAlias
     * @return $this
     */
    public function setParametersForAllAttributesFromGroup(string $groupAlias) : CallWebService
    {
        $this->parametersForAllAttrsFromGroup = $groupAlias;
        $this->setParametersUseAttributes(true);
        return $this;
    }

    /**
     * @return bool
     */
    protected function willGenerateParametersFromAttributes() : bool
    {
        return $this->parametersForAllAttrsFromGroup !== null;
    }

    /**
     * @return MetaAttributeGroupInterface|null
     */
    protected function getAttributeGroupToGenerateParameters() : ?MetaAttributeGroupInterface
    {
        return $this->getMetaObject()->getAttributeGroup($this->parametersForAllAttrsFromGroup);
    }

    /**
     * @return bool
     */
    protected function isDebugMode() : bool
    {
        return $this->debugFlag === true;
    }

    /**
     * Set to TRUE or FALSE to enable or disable debug mode
     *
     * @uxon-property debug
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param bool $trueOrFalse
     * @return CallWebService
     */
    protected function setDebug(bool $trueOrFalse) : CallWebService
    {
        $this->debugFlag = $trueOrFalse;
        return $this;
    }

    /**
     *
     * @return string|NULL
     */
    protected function getDebugResponse() : ?UxonObject
    {
        return $this->debugResponseUxon;
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
     * @return CallWebService
     */
    protected function setDebugResponse(UxonObject $value) : CallWebService
    {
        $this->debugResponseUxon = $value;
        return $this;
    }

    /**
     * List of expressions to read from the response of the web service.
     * 
     * @deprecated use setResultDataSheet() instead
     * 
     * @param UxonObject $arrayOfExpressions
     * @return $this
     */
    public function setResultDataColumns(UxonObject $arrayOfExpressions) : CallWebService
    {
        $this->resultDataSheetUxon = new UxonObject([
            'object_alias' => $this->getResultObject()->getAliasWithNamespace(),
            'columns' => []
        ]);
        foreach ($arrayOfExpressions as $expression) {
            $this->resultDataSheetUxon->appendToProperty('columns', new UxonObject([
                'expression' => $expression
            ]));
        }
        return $this;
    }

    /**
     * Read the response into this data sheet using the query builder of the result object
     * 
     * Using this property you can tell the action to extract specific columns from the response of the web service
     * if the action is based on an object with an HTTP query builder. The object will use that query builder
     * (e.g. `JsonUrlBuilder`) to parse the response and extract data - just like it would do for regular GET or
     * POST requests.
     * 
     * The column of the result sheet can be
     * - direct attributes of the result object if they have data addresses
     * - related attributes if the web service response includes the foreign keys AND the related data can be
     * read by using that key only (typically only regular n-to-1 relations)
     * - static formulas
     * 
     * Often narrow-scoped web services (e.g. to calculate something) have fixed input and output formats. These
     * can be modeled as separate metaobject, that are neither readable nor writable because the workbench cannot
     * use its regular read or write logic on them. The input object should then get an action describing the web
     * service and that action should have a `result_data_sheet` transforming its response into usable data.
     * This way, input and output of the service will be clearly visible in the model and easily usable in widgets
     * and other actions.
     * 
     * ```
     * {
     *  "alias": "exface.UrlDataConnector.CallWebService",
     *  "data_source_alias": "my.App.web_api",
     *  "url": "api/v1/carrier-routes/search",
     *  "content_type": "application/json",
     *  "method": "POST",
     *  "parameters_use_attributes": true,
     *  "parameters_for_all_attributes_from_group": "~ALL",
     *  "result_message_pattern": "/\"items\":\\s*(?<items>\\[[\\s\\S]*\\])/",
     *  "error_message_pattern": "/\"title\":\"([^\"]*)\"/",
     *  "input_object_alias": "my.App.CarrierRouteSearchCommand",
     *  "result_data_sheet": {
     *      "object_alias": "my.App.CarrierRouteSearchResponse",
     *      "columns": [
     *          {"attribute_alias": "rank"},
     *          {"attribute_alias": "carrierId__LABEL"},
     *          {"attribute_alias": "basePrice"}
     *      ]
     *  }
     * }
     * 
     * ```
     * 
     * @uxon-property result_data_sheet
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"object_alias": "", "columns": [{"attribute_alias": ""}]}
     * 
     * @param UxonObject $uxon
     * @return $this
     */
    public function setResultDataSheet(UxonObject $uxon) : CallWebService
    {
        $this->resultDataSheetUxon = $uxon;
        return $this;
    }
    
    protected function getResultDataSheet() : DataSheetInterface
    {
        if ($this->resultDataSheetUxon !== null) {
            return DataSheetFactory::createFromUxon($this->getWorkbench(), $this->resultDataSheetUxon);
        }
        return DataSheetFactory::createForObject($this->getResultObject());
    }
}