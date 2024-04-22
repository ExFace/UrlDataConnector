<?php
namespace exface\UrlDataConnector\Formulas;

use exface\Core\CommonLogic\Model\Formula;
use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;

/**
 * Formats a given value according to the standards for OData 2.0 request body
 * 
 * - `=FormatAsOData2BodyParam('2022-05-29 20:23', 'Edm.DateTime')` -> `/Date(1653848631000)/`
 * - `=FormatAsOData2BodyParam('2022-05-29 20:23', 'Edm.Time')` -> `PT20H23M51S`
 * - `=FormatAsOData2BodyParam('20:23', 'Edm.Time')` -> `PT20H23M51S`
 * 
 * ## Supported formats
 * 
 * - `Edm.Binary` - Base64 encoded value of an EDM.Binary value represented as a JSON string
 * - `Edm.Boolean` - "true" or "false"
 * - `Edm.Byte` - Literal form of Edm.Byte as used in URIs formatted as a JSON string
 * - `Edm.DateTime` - "/Date(<ticks>["+" or "-" <offset>)/"<ticks> = number of milliseconds 
 * since midnight Jan 1, 1970<offset> = number of minutes to add or subtract
 * - `Edm.Decimal` - Literal form of Edm.Decimal as used in URIs formatted as a JSON string
 * - `Edm.Double` - Literal form of Edm.Double as used in URIs formatted as a JSON string
 * - `Edm.Guid` - Literal form of Edm.Guid as used in URIs formatted as a JSON string
 * - `Edm.Int16` - A JSON number
 * - `Edm.Int32` - A JSON number
 * - `Edm.Int64` - A 64-bit integer formatted as a JSON string
 * - `Edm.SByte` - Literal form of Edm.SByte as used in URIs formatted as a JSON string
 * - `Edm.Single` - Literal form of Edm.Single as used in URIs formatted as a JSON string
 * - `Edm.String` - Any JSON string
 * - `Edm.Time` - Literal form of Edm.Time as used in URIs formatted as a JSON string
 * - `Edm.DateTimeOffset` - Literal form of Edm.DateTimeOffset as used in URIs formatted as a JSON string
 * 
 * For more details, see https://www.odata.org/documentation/odata-version-2-0/json-format/ chapter 4.
 * 
 * @author Andrej Kabachnik
 *
 */
class FormatAsOData2BodyParam extends Formula
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Formula::run()
     */
    public function run($value = null, string $odataType = 'Edm.String')
    {
        if ($value === '' || $value === null) {
            return $value;
        }
        
        return OData2JsonUrlBuilder::buildRequestBodyODataValue($value, null, $odataType);
    }
}