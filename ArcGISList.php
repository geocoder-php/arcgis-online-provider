<?php

declare(strict_types=1);

/** WIP
 * This file is a modified version of the geocoder-php/arcgis-online-provider
 * project, modified to accept multiple addresses and use the geocodeAddresses
 * endpoint:
 * https://developers.arcgis.com/rest/geocode/api-reference/geocoding-geocode-addresses.htm
 *
 * Use of this endpoint requires an authentication token for service credits:
 * https://developers.arcgis.com/rest/geocode/api-reference/geocoding-authenticate-a-request.htm
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\ArcGISList;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Model\Address;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Provider\Provider;
use Http\Client\HttpClient;

/**
 * @author ALKOUM Dorian <baikunz@gmail.com>
 */
final class ArcGISList extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    const ENDPOINT_URL = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/geocodeAddresses?token=%s&addresses=%s';

    /**
     * @var string
     */
    private $token;

    /**
     * @var string
     */
    private $sourceCountry;

    /**
     * ArcGIS World Geocoding Service
     * https://developers.arcgis.com/rest/geocode/api-reference/overview-world-geocoding-service.htm
     *
     * @param HttpClient $client        An HTTP adapter
     * @param string     $token         Your authentication token
     * @param string     $sourceCountry Country biasing (optional)
     *
     * @return GoogleMaps
     */
    public static function token(
        HttpClient $client,
        string $token,
        string $sourceCountry = null
    ) {
        $provider = new self($client, $token, $sourceCountry);

        return $provider;
    }

    /**
     * @param HttpClient $client        An HTTP adapter
     * @param string     $token
     * @param string     $sourceCountry Country biasing (optional)
     */
    public function __construct(HttpClient $client, string $token, string $sourceCountry = null)
    {
        parent::__construct($client);

        $this->token = $token;
        $this->sourceCountry = $sourceCountry;
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The ArcGISList provider does not support IP addresses, only street addresses.');
        }

        // Save a request if no valid address entered
        if (empty($address)) {
            throw new InvalidArgument('Address cannot be empty.');
        }

        $url = sprintf(self::ENDPOINT_URL, $token, urlencode($address));
        $json = $this->executeQuery($url, $query->getLimit());

        // no result
        if (empty($json->locations)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->locations as $location) {
            $data = $location->feature->attributes;

            $coordinates = (array) $location->feature->geometry;
            $streetName = !empty($data->StAddr) ? $data->StAddr : null;
            $streetNumber = !empty($data->AddNum) ? $data->AddNum : null;
            $city = !empty($data->City) ? $data->City : null;
            $zipcode = !empty($data->Postal) ? $data->Postal : null;
            $countryCode = !empty($data->Country) ? $data->Country : null;

            $adminLevels = [];
            foreach (['Region', 'Subregion'] as $i => $property) {
                if (!empty($data->{$property})) {
                    $adminLevels[] = ['name' => $data->{$property}, 'level' => $i + 1];
                }
            }

            $results[] = Address::createFromArray([
                'providedBy' => $this->getName(),
                'latitude' => $coordinates['y'],
                'longitude' => $coordinates['x'],
                'streetNumber' => $streetNumber,
                'streetName' => $streetName,
                'locality' => $city,
                'postalCode' => $zipcode,
                'adminLevels' => $adminLevels,
                'countryCode' => $countryCode,
            ]);
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'arcgis_list';
    }

    /**
     * @param string $query
     * @param int    $limit
     *
     * @return string
     */
    private function buildQuery(string $query, int $limit): string
    {
        if (null !== $this->sourceCountry) {
            $query = sprintf('%s&sourceCountry=%s', $query, $this->sourceCountry);
        }

        return sprintf('%s&f=%s', $query, 'json');
    }

    /**
     * @param string $url
     * @param int    $limit
     *
     * @return \stdClass
     */
    private function executeQuery(string $url, int $limit): \stdClass
    {
        $url = $this->buildQuery($url, $limit);
        $content = $this->getUrlContents($url);
        $json = json_decode($content);

        // API error
        if (!isset($json)) {
            throw InvalidServerResponse::create($url);
        }

        return $json;
    }
}
