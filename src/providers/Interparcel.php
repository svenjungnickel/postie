<?php
namespace verbb\postie\providers;

use verbb\postie\Postie;
use verbb\postie\base\Provider;
use verbb\postie\events\ModifyRatesEvent;

use Craft;
use craft\helpers\Json;

use craft\commerce\Plugin as Commerce;

class Interparcel extends Provider
{
    // Public Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('postie', 'Interparcel');
    }

    public function supportsDynamicServices(): bool
    {
        return true;
    }

    public function fetchShippingRates($order)
    {
        // If we've locally cached the results, return that
        if ($this->_rates) {
            return $this->_rates;
        }

        $storeLocation = Commerce::getInstance()->getAddresses()->getStoreLocationAddress();
        $dimensions = $this->getDimensions($order, 'kg', 'cm');

        // Allow location and dimensions modification via events
        $this->beforeFetchRates($storeLocation, $dimensions, $order);

        //
        // TESTING
        //
        // $country = Commerce::getInstance()->countries->getCountryByIso('AU');
        // $state = Commerce::getInstance()->states->getStateByAbbreviation($country->id, 'VIC');

        // $storeLocation = new craft\commerce\models\Address();
        // $storeLocation->address1 = '552 Victoria Street';
        // $storeLocation->city = 'North Melbourne';
        // $storeLocation->zipCode = '3051';
        // $storeLocation->stateId = $state->id;
        // $storeLocation->countryId = $country->id;

        // $country = Commerce::getInstance()->countries->getCountryByIso('AU');
        // $state = Commerce::getInstance()->states->getStateByAbbreviation($country->id, 'TAS');

        // $order->shippingAddress->address1 = '10-14 Cameron Street';
        // $order->shippingAddress->city = 'Launceston';
        // $order->shippingAddress->zipCode = '7250';
        // $order->shippingAddress->stateId = $state->id;
        // $order->shippingAddress->countryId = $country->id;
        //
        // 
        //

        try {
            $response = [];

            $payload = [
                'collection' => [
                    'city' => $storeLocation->city,
                    'postcode' => $storeLocation->zipCode,
                    'state' => $storeLocation->state->abbreviation,
                    'country' => $storeLocation->country->iso,
                ],
                'delivery' => [
                    'city' => $order->shippingAddress->city,
                    'postcode' => $order->shippingAddress->zipCode,
                    'state' => $order->shippingAddress->state->abbreviation,
                    'country' => $order->shippingAddress->country->iso,
                ],
                'parcels' => [
                    [
                        'weight' => $dimensions['weight'],
                        'length' => $dimensions['length'],
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ],
                ],
            ];

            $carriers = $this->getSetting('carriers');
            $serviceLevels = $this->getSetting('serviceLevels');
            $pickupTypes = $this->getSetting('pickupTypes');

            if ($carriers && $carriers !== '*') {
                $payload['filter']['carriers'] = $carriers;
            }

            if ($serviceLevels && $serviceLevels !== '*') {
                $payload['filter']['serviceLevel'] = $serviceLevels;
            }

            if ($pickupTypes && $pickupTypes !== '*') {
                $payload['filter']['pickupType'] = $pickupTypes;
            }

            $this->beforeSendPayload($this, $payload, $order);

            $response = $this->_request('POST', 'quote/v2', [
                'json' => ['shipment' => $payload],
            ]);

            $services = $response['services'] ?? [];

            if ($services) {
                foreach ($services as $service) {
                    $this->_rates[$service['service']] = [
                        'amount' => (float)$service['price'] ?? '',
                        'options' => $service,
                    ];
                }
            } else {
                Provider::log($this, Craft::t('postie', 'No services found: `{json}`.', [
                    'json' => Json::encode($response),
                ]));
            }

            // Allow rate modification via events
            $modifyRatesEvent = new ModifyRatesEvent([
                'rates' => $this->_rates,
                'response' => $response,
                'order' => $order,
            ]);

            if ($this->hasEventHandlers(self::EVENT_MODIFY_RATES)) {
                $this->trigger(self::EVENT_MODIFY_RATES, $modifyRatesEvent);
            }

            $this->_rates = $modifyRatesEvent->rates;
        } catch (\Throwable $e) {
            if (method_exists($e, 'hasResponse')) {
                $data = Json::decode((string)$e->getResponse()->getBody());
                $message = $data['error']['errorMessage'] ?? $e->getMessage();

                Provider::error($this, Craft::t('postie', 'API error: “{message}” {file}:{line}', [
                    'message' => $message,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            } else {
                Provider::error($this, Craft::t('postie', 'API error: “{message}” {file}:{line}', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            }
        }

        return $this->_rates;
    }


    // Private Methods
    // =========================================================================

    private function _getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => 'https://api.au.interparcel.com/',
            'headers' => [
                'X-Interparcel-Auth' => $this->getSetting('apiKey'),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    private function _request(string $method, string $uri, array $options = [])
    {
        $response = $this->_getClient()->request($method, ltrim($uri, '/'), $options);

        return Json::decode((string)$response->getBody());
    }

}