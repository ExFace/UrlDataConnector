<?php
namespace exface\UrlDataConnector\Facades;

use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\UrlDataType;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\Factories\DataConnectionFactory;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Starts Swagger UI for a give OpenAPI compatible data connection
 * 
 * Supported URLs:
 * 
 * - `/api/swagger-ui/{connectionAlias}`
 */
class SwaggerUiFacade extends AbstractHttpFacade
{

    /**
     * @inheritDoc
     */
    protected function createResponse(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $innerPath = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/');
        list($connectionAlias, $connectionPath) = explode('/', $innerPath, 2);
        $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionAlias);
        if ($connection instanceof HttpConnector) {
            $openApiUrl = $connection->getSwaggerUrl();
            if ($openApiUrl) {
                $headers = $this->buildHeadersCommon();
                $headers['Content-Type'] = 'text/html';
                return new Response(200, $headers, $this->buildHtmlSwaggerUI($openApiUrl));
            } else {
                throw new UnexpectedValueException('Cannot start SwaggerUI for connection ' . $connectionAlias . ' - it does have a `swagger_url` configured');
            }
        } else {
            throw new UnexpectedValueException('Cannot start SwaggerUI for connection ' . $connectionAlias . ' - it does not use an HTTP connector');
        }
        return new Response(400, $this->buildHeadersCommon());
    }

    /**
     * @inheritDoc
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/swagger-ui';
    }

    /**
     * Create a HTML that initiates the SwaggerUi dist with the given openapi url.
     *
     * @param string $openapiUrl
     * @return string HTML
     */
    protected function buildHtmlSwaggerUI(string $openapiUrl): string
    {
        $siteRoot = $this->getWorkbench()->getUrl();
        $swaggerUI = $siteRoot . 'vendor/npm-asset/swagger-ui-dist';

        // For foreign URLs, we need to check whether the OpenAPI spec is accessible from SwaggerUI (CORS headers).
        // If not, we fetch it server-side and inline it.
        if (UrlDataType::isAbsolute($openapiUrl) && ! StringDataType::startsWith($openapiUrl, $siteRoot)) {
            $httpClient = new Client();
            try {
                $request = new Request('GET', $openapiUrl);
                $response = $httpClient->send($request);
                if ($this->isAccessibleFromSwaggerUi($response, $siteRoot)) {
                    $openApiSrc = 'url: "' . $openapiUrl . '"';
                } else {
                    $openapiJson = $response->getBody()->getContents();
                    $openApiSrc = 'spec: ' . $openapiJson;
                }
            } catch (\Exception $e) {
                // Do not throw the exception, just pass the URL to SwaggerUI and let it handle the error
                $openApiSrc = 'url: "' . $openapiUrl . '"';
            }
        } else {
            $openApiSrc = 'url: "' . $openapiUrl . '"';
        }

        return <<<HTML
        <!-- HTML for static distribution bundle build -->
        <!DOCTYPE html>
        <html lang='en'>
          <head>
            <meta charset='UTF-8'>
            <title>Swagger UI</title>
            <link rel='stylesheet' type='text/css' href='{$swaggerUI}/swagger-ui.css' />
            <link rel='stylesheet' type='text/css' href='{$swaggerUI}/index.css' />
            <link rel='icon' type='image/png' href='{$swaggerUI}/favicon-32x32.png' sizes='32x32' />
            <link rel='icon' type='image/png' href='{$swaggerUI}/favicon-16x16.png' sizes='16x16' />
          </head>
          
          <body>
            <div id='swagger-ui'></div>
            <script src='{$swaggerUI}/swagger-ui-bundle.js' charset='UTF-8'> </script>
            <script src='{$swaggerUI}/swagger-ui-standalone-preset.js' charset='UTF-8'> </script>
            <script>
                window.onload = function() {
                //<editor-fold desc='Changeable Configuration Block'>
                
                // Initialize Swagger UI
                window.ui = SwaggerUIBundle({
                        {$openApiSrc},
                        dom_id: '#swagger-ui',
                        deepLinking: true,
                        defaultModelsExpandDepth: 4,
                        showExtensions: true,
                        presets: [
                            SwaggerUIBundle.presets.apis,
                            SwaggerUIStandalonePreset
                        ],
                        plugins: [
                            SwaggerUIBundle.plugins.DownloadUrl
                        ],
                        layout: 'StandaloneLayout'
                    });
                    
                    //</editor-fold>
                };
            </script>
          </body>
        </html>
HTML;
    }
    
    /**
     * Returns true if the HTTP response will be loadable by SwaggerUI according to cross-origin (CORS) policies.
     *
     * SwaggerUI fetches the OpenAPI spec via the browser's fetch API, so the response must carry a permissive
     * `Access-Control-Allow-Origin` header. The method checks whether that header is either the wildcard `*`
     * (any origin allowed) or explicitly contains the origin of the workbench installation (scheme + host +
     * non-default port), which is the origin the browser will send when SwaggerUI makes the request.
     *
     * If the header is absent or names a different origin, the browser will block the response and this method
     * returns false — in that case the caller should fetch the spec server-side and inline it instead.
     *
     * @param ResponseInterface $response HTTP response received from the OpenAPI spec URL
     * @param string $siteRoot Base URL of the workbench installation (used to derive the SwaggerUI origin)
     * @return bool
     */
    protected function isAccessibleFromSwaggerUi(ResponseInterface $response, string $siteRoot): bool
    {
        $rootUri = new Uri($siteRoot);
        $origin = $rootUri->getScheme() . '://' . $rootUri->getHost();
        // Include non-default port in the origin, as browsers do
        $port = $rootUri->getPort();
        if ($port !== null) {
            $origin .= ':' . $port;
        }

        // Access-Control-Allow-Origin is the header browsers enforce for cross-origin fetch requests.
        // See https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Origin
        $acao = $response->getHeaderLine('Access-Control-Allow-Origin');
        return $acao === '*' || $acao === $origin;
    }
}