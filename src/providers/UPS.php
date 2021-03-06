<?php
namespace verbb\postie\providers;

use verbb\postie\Postie;
use verbb\postie\base\Provider;
use verbb\postie\events\ModifyRatesEvent;
use verbb\postie\helpers\TestingHelper;

use Craft;
use craft\helpers\Json;

use craft\commerce\Plugin as Commerce;

use Ups\Rate;
use Ups\Entity\Address;
use Ups\Entity\DeliveryConfirmation;
use Ups\Entity\Dimensions;
use Ups\Entity\InsuredValue;
use Ups\Entity\Package;
use Ups\Entity\PackagingType;
use Ups\Entity\PackageServiceOptions;
use Ups\Entity\PaymentInformation;
use Ups\Entity\PickupType;
use Ups\Entity\RateInformation;
use Ups\Entity\RateRequest;
use Ups\Entity\RateResponse;
use Ups\Entity\Service;
use Ups\Entity\ShipFrom;
use Ups\Entity\Shipment;
use Ups\Entity\Shipper;
use Ups\Entity\UnitOfMeasurement;
use Ups\Exception\InvalidResponseException;

class UPS extends Provider
{
    // Properties
    // =========================================================================

    public $handle = 'ups';
    public $weightUnit = 'lb';
    public $dimensionUnit = 'in';

    private $maxWeight = 68038.9; // 150lbs

    private $pickupCode = [
        '01' => 'Daily Pickup',
        '03' => 'Customer Counter',
        '06' => 'One Time Pickup',
        '07' => 'On Call Air',
        '19' => 'Letter Center',
        '20' => 'Air Service Center',
    ];

    private $euCountries = [
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BG' => 'Bulgaria',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DE' => 'Germany',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'ES' => 'Spain',
        'FI' => 'Finland',
        'FR' => 'France',
        'GB' => 'United Kingdom',
        'GR' => 'Greece',
        'HU' => 'Hungary',
        'HR' => 'Croatia',
        'IE' => 'Ireland, Republic of (EIRE)',
        'IT' => 'Italy',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'LV' => 'Latvia',
        'MT' => 'Malta',
        'NL' => 'Netherlands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'SE' => 'Sweden',
        'SI' => 'Slovenia',
        'SK' => 'Slovakia',
    ];


    // Public Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('postie', 'UPS');
    }

    public function getPickupTypeOptions()
    {
        $options = [];

        foreach ($this->pickupCode as $key => $value) {
            $options[] = ['label' => $value, 'value' => $key];
        }

        return $options;
    }

    public function getServiceList(): array
    {
        return [
            // Domestic
            'S_AIR_1DAYEARLYAM' => 'UPS Next Day Air Early AM',
            'S_AIR_1DAY' => 'UPS Next Day Air',
            'S_AIR_1DAYSAVER' => 'Next Day Air Saver',
            'S_AIR_2DAYAM' => 'UPS Second Day Air AM',
            'S_AIR_2DAY' => 'UPS Second Day Air',
            'S_3DAYSELECT' => 'UPS Three-Day Select',
            'S_GROUND' => 'UPS Ground',
            'S_SURE_POST' => 'UPS Sure Post',

            // International
            'S_STANDARD' => 'UPS Standard',
            'S_WW_EXPRESS' => 'UPS Worldwide Express',
            'S_WW_EXPRESSPLUS' => 'UPS Worldwide Express Plus',
            'S_WW_EXPEDITED' => 'UPS Worldwide Expedited',
            'S_SAVER' => 'UPS Saver',
            'S_ACCESS_POINT' => 'UPS Access Point Economy',

            'S_UPSTODAY_STANDARD' => 'UPS Today Standard',
            'S_UPSTODAY_DEDICATEDCOURIER' => 'UPS Today Dedicated Courier',
            'S_UPSTODAY_INTERCITY' => 'UPS Today Intercity',
            'S_UPSTODAY_EXPRESS' => 'UPS Today Express',
            'S_UPSTODAY_EXPRESSSAVER' => 'UPS Today Express Saver',
            'S_UPSWW_EXPRESSFREIGHT' => 'UPS Worldwide Express Freight',

            // Time in Transit Response Service Codes: United States Domestic Shipments
            'TT_S_US_AIR_1DAYAM' => 'UPS Next Day Air Early',
            'TT_S_US_AIR_1DAY' => 'UPS Next Day Air',
            'TT_S_US_AIR_SAVER' => 'UPS Next Day Air Saver',
            'TT_S_US_AIR_2DAYAM' => 'UPS Second Day Air A.M.',
            'TT_S_US_AIR_2DAY' => 'UPS Second Day Air',
            'TT_S_US_3DAYSELECT' => 'UPS Three-Day Select',
            'TT_S_US_GROUND' => 'UPS Ground',
            'TT_S_US_AIR_1DAYSATAM' => 'UPS Next Day Air Early (Saturday Delivery)',
            'TT_S_US_AIR_1DAYSAT' => 'UPS Next Day Air (Saturday Delivery)',
            'TT_S_US_AIR_2DAYSAT' => 'UPS Second Day Air (Saturday Delivery)',

            // Time in Transit Response Service Codes: Other Shipments Originating in US
            'TT_S_US_INTL_EXPRESSPLUS' => 'UPS Worldwide Express Plus',
            'TT_S_US_INTL_EXPRESS' => 'UPS Worldwide Express',
            'TT_S_US_INTL_SAVER' => 'UPS Worldwide Express Saver',
            'TT_S_US_INTL_STANDARD' => 'UPS Standard',
            'TT_S_US_INTL_EXPEDITED' => 'UPS Worldwide Expedited',

            // Time in Transit Response Service Codes: Shipments Originating in the EU
            // Destination is WITHIN the Origin Country
            'TT_S_EU_EXPRESSPLUS' => 'UPS Express Plus',
            'TT_S_EU_EXPRESS' => 'UPS Express',
            'TT_S_EU_SAVER' => 'UPS Express Saver',
            'TT_S_EU_STANDARD' => 'UPS Standard',

            // Time in Transit Response Service Codes: Shipments Originating in the EU
            // Destination is Another EU Country
            'TT_S_EU_TO_EU_EXPRESSPLUS' => 'UPS Express Plus',
            'TT_S_EU_TO_EU_EXPRESS' => 'UPS Express',
            'TT_S_EU_TO_EU_SAVER' => 'UPS Express Saver',
            'TT_S_EU_TO_EU_STANDARD' => 'UPS Standard',

            // Time in Transit Response Service Codes: Shipments Originating in the EU
            // Destination is Outside the EU
            'TT_S_EU_TO_OTHER_EXPRESS_NA1' => 'UPS Express NA 1',
            'TT_S_EU_TO_OTHER_EXPRESSPLUS' => 'UPS Worldwide Express Plus',
            'TT_S_EU_TO_OTHER_EXPRESS' => 'UPS Express',
            'TT_S_EU_TO_OTHER_SAVER' => 'UPS Express Saver',
            'TT_S_EU_TO_OTHER_EXPEDITED' => 'UPS Expedited',
            'TT_S_EU_TO_OTHER_STANDARD' => 'UPS Standard',
        ];
    }

    public static function defineDefaultBoxes()
    {
        return [
            [
                'id' => 'ups-1',
                'name' => 'UPS Letter',
                'boxLength' => 12.5,
                'boxWidth' => 9.5,
                'boxHeight' => 0.25,
                'boxWeight' => 0,
                'maxWeight' => 0.5,
                'enabled' => true,
            ],
            [
                'id' => 'ups-2',
                'name' => 'Tube',
                'boxLength' => 38,
                'boxWidth' => 6,
                'boxHeight' => 6,
                'boxWeight' => 0,
                'maxWeight' => 100,
                'enabled' => true,
            ],
            [
                'id' => 'ups-3',
                'name' => '10KG Box',
                'boxLength' => 16.5,
                'boxWidth' => 13.25,
                'boxHeight' => 10.75,
                'boxWeight' => 0,
                'maxWeight' => 22,
                'enabled' => true,
            ],
            [
                'id' => 'ups-4',
                'name' => '25KG Box',
                'boxLength' => 19.75,
                'boxWidth' => 17.75,
                'boxHeight' => 13.2,
                'boxWeight' => 0,
                'maxWeight' => 55,
                'enabled' => true,
            ],
            [
                'id' => 'ups-5',
                'name' => 'Small Express Box',
                'boxLength' => 13,
                'boxWidth' => 11,
                'boxHeight' => 2,
                'boxWeight' => 0,
                'maxWeight' => 100,
                'enabled' => true,
            ],
            [
                'id' => 'ups-6',
                'name' => 'Medium Express Box',
                'boxLength' => 16,
                'boxWidth' => 11,
                'boxHeight' => 3,
                'boxWeight' => 0,
                'maxWeight' => 100,
                'enabled' => true,
            ],
            [
                'id' => 'ups-7',
                'name' => 'Large Express Box',
                'boxLength' => 18,
                'boxWidth' => 13,
                'boxHeight' => 3,
                'boxWeight' => 0,
                'maxWeight' => 30,
                'enabled' => true,
            ],
        ];
    }

    public function getMaxPackageWeight($order)
    {
        return $this->maxWeight;
    }

    public function fetchShippingRates($order)
    {
        // If we've locally cached the results, return that
        if ($this->_rates) {
            return $this->_rates;
        }

        $client = $this->_getClient();

        if (!$client) {
            Provider::error($this, 'Unable to communicate with API.');
            return false;
        }

        $storeLocation = Commerce::getInstance()->getAddresses()->getStoreLocationAddress();

        // Pack the content of the order into boxes
        $packedBoxes = $this->packOrder($order)->getSerializedPackedBoxList();

        // Allow location and dimensions modification via events
        $this->beforeFetchRates($storeLocation, $packedBoxes, $order);

        //
        // TESTING
        //
        // $country = Commerce::getInstance()->countries->getCountryByIso('US');
        // $state = Commerce::getInstance()->states->getStateByAbbreviation($country->id, 'CA');

        // $storeLocation = new craft\commerce\models\Address();
        // $storeLocation->address1 = 'One Infinite Loop';
        // $storeLocation->city = 'Cupertino';
        // $storeLocation->zipCode = '95014';
        // $storeLocation->stateId = $state->id;
        // $storeLocation->countryId = $country->id;

        // $order->shippingAddress->address1 = '1600 Amphitheatre Parkway';
        // $order->shippingAddress->city = 'Mountain View';
        // $order->shippingAddress->zipCode = '94043';
        // $order->shippingAddress->stateId = $state->id;
        // $order->shippingAddress->countryId = $country->id;

        // Canada Testing
        // $country = Commerce::getInstance()->countries->getCountryByIso('CA');
        // $state = Commerce::getInstance()->states->getStateByAbbreviation($country->id, 'ON');

        // $storeLocation = new craft\commerce\models\Address();
        // $storeLocation->address1 = '220 Yonge St';
        // $storeLocation->city = 'Toronto';
        // $storeLocation->zipCode = 'M5B 2H1';
        // $storeLocation->stateId = $state->id;
        // $storeLocation->countryId = $country->id;

        // $order->shippingAddress->address1 = '290 Bremner Blvd';
        // $order->shippingAddress->city = 'Toronto';
        // $order->shippingAddress->zipCode = 'M5V 3L9';
        // $order->shippingAddress->stateId = $state->id;
        // $order->shippingAddress->countryId = $country->id;

        // EU Testing
        // $country = Commerce::getInstance()->countries->getCountryByIso('GB');

        // $storeLocation = new craft\commerce\models\Address();
        // $storeLocation->address1 = '2 Bedfont Lane';
        // $storeLocation->city = 'London';
        // $storeLocation->zipCode = 'CV226PD';
        // $storeLocation->stateName = 'Warwickshire';
        // $storeLocation->countryId = $country->id;

        // $country = Commerce::getInstance()->countries->getCountryByIso('GB');

        // $order->shippingAddress->address1 = 'Southam Rd';
        // $order->shippingAddress->city = 'Dunchurch';
        // $order->shippingAddress->zipCode = 'CV226PD';
        // $order->shippingAddress->stateName = 'Warwickshire';
        // $order->shippingAddress->countryId = $country->id;
        //
        // TESTING
        //

        try {
            $shipment = new Shipment();

            $shipFromAddress = new Address();
            $shipFromAddress->setPostalCode($storeLocation->zipCode);

            // UPS can't handle 3-character states. Ignoring it is valid for international order
            // But states are also required for US and Canada
            $allowedZipCodeCountries = ['US', 'CA'];

            if ($storeLocation->country) {
                $shipFromAddress->setCountryCode($storeLocation->country->iso);
                
                if (in_array($storeLocation->country->iso, $allowedZipCodeCountries)) {
                    $state = $storeLocation->state->abbreviation ?? '';

                    $shipFromAddress->setStateProvinceCode($state);
                }
            }

            $shipFrom = new ShipFrom();
            $shipFrom->setAddress($shipFromAddress);

            $shipment->setShipFrom($shipFrom);

            $shipTo = $shipment->getShipTo();
            $shipToAddress = $shipTo->getAddress();
            $shipToAddress->setPostalCode($order->shippingAddress->zipCode);

            if ($this->getSetting('residentialAddress')) {
                $shipToAddress->setResidentialAddressIndicator(true);
            }

            if ($order->shippingAddress->country) {
                $shipToAddress->setCountryCode($order->shippingAddress->country->iso);

                if (in_array($order->shippingAddress->country->iso, $allowedZipCodeCountries)) {
                    $state = $order->shippingAddress->state->abbreviation ?? '';

                    $shipToAddress->setStateProvinceCode($state);
                }
            }

            foreach ($packedBoxes as $packedBox) {
                // Ensure they're formatted correctly, the API will error for too many decimals
                $weight = number_format($packedBox['weight'], 3);
                $height = number_format($packedBox['height'], 3);
                $width = number_format($packedBox['width'], 3);
                $length = number_format($packedBox['length'], 3);

                $package = new Package();
                $package->getPackagingType()->setCode(PackagingType::PT_PACKAGE);
                $package->getPackageWeight()->setWeight($weight);

                if ($this->getSetting('requireSignature')) {
                    $deliveryConfirmation = new DeliveryConfirmation();

                    if ($requireSignature === 'required') {
                        $deliveryConfirmation->setDcisType(DeliveryConfirmation::DELIVERY_CONFIRMATION_SIGNATURE_REQUIRED);
                    } else if ($requireSignature === 'adult') {
                        $deliveryConfirmation->setDcisType(DeliveryConfirmation::DELIVERY_CONFIRMATION_ADULT_SIGNATURE_REQUIRED);
                    }

                    $package->getPackageServiceOptions()->setDeliveryConfirmation($deliveryConfirmation);
                }

                $weightUnit = new UnitOfMeasurement;
                $weightUnit->setCode(UnitOfMeasurement::UOM_LBS);
                $package->getPackageWeight()->setUnitOfMeasurement($weightUnit);

                $packageDimensions = new Dimensions();
                $packageDimensions->setHeight($height);
                $packageDimensions->setWidth($width);
                $packageDimensions->setLength($length);

                $unit = new UnitOfMeasurement;
                $unit->setCode(UnitOfMeasurement::UOM_IN);

                $packageDimensions->setUnitOfMeasurement($unit);
                $package->setDimensions($packageDimensions);

                if ($this->getSetting('includeInsurance')) {
                    $insuredValue = new InsuredValue();
                    $insuredValue->setMonetaryValue((float)$order->total);
                    $insuredValue->setCurrencyCode($order->paymentCurrency);

                    $packageServiceOptions = new PackageServiceOptions();
                    $packageServiceOptions->setInsuredValue($insuredValue);

                    $package->setPackageServiceOptions($packageServiceOptions);
                }

                $shipment->addPackage($package);
            }

            // Check for negotiated rates
            if ($this->getSetting('negotiatedRates') && $this->getSetting('accountNumber')) {
                $rateInformation = new RateInformation;
                $rateInformation->setNegotiatedRatesIndicator(1);
                $shipment->setRateInformation($rateInformation);

                $shipper = $shipment->getShipper();
                $shipper->setShipperNumber($accountNumber);
                $shipment->setPaymentInformation(new PaymentInformation('prepaid', (object)['AccountNumber' => $accountNumber]));
            }

            $rates = new RateResponse();

            $rateRequest = new RateRequest();
            $rateRequest->setShipment($shipment);

            $pickupCode = $this->getSetting('pickupType') ?? '01';

            $pickupType = new PickupType();
            $pickupType->setCode($pickupCode);
            $rateRequest->setPickupType($pickupType);

            $this->beforeSendPayload($this, $rateRequest, $order);

            // Perform the request
            $rates = $this->_client->shopRates($rateRequest);

            // Check for Sure Post rates - must be a separate request
            $surePost = $this->services['S_SURE_POST']->enabled ?? false;
            
            if ($surePost) {
                $service = new Service;
                $service->setCode(Service::S_SURE_POST);
                $service->setDescription($service->getName());
                $shipment->setService($service);

                // If SurePost shipping dimensions are exceeded, an exception is thrown. We'll catch it, log it,
                // and make sure SurePost is not a valid shipping method in this situation.
                $surePostRate = null;
                try {
                    $surePostRate = $this->_client->getRate($shipment);
                } catch (InvalidResponseException $e) {
                    Provider::error($this, 'SurePost API error: `' . $e->getMessage() . ':' . $e->getLine() . '`.');
                }

                // Attach Sure Post rates into any other rates
                if ($surePostRate) {
                    if (!isset($rates->RatedShipment) || !is_array($rates->RatedShipment)) {
                        $rates->RatedShipment = [];
                    }

                    $rates->RatedShipment = array_merge($rates->RatedShipment, $surePostRate->RatedShipment);
                }
            }

            foreach ($rates->RatedShipment as $rate) {
                $serviceHandle = $this->_getServiceHandle($rate->Service->getCode(), $storeLocation, $order->shippingAddress);

                if (!$serviceHandle) {
                    Provider::error($this, 'Unable to find matching service handle for: `' . $rate->Service->getName() . ':' . '`.');

                    continue;
                }

                $rateInfo = [
                    'amount' => $rate->TotalCharges->MonetaryValue ?? '',
                    'options' => [
                        'guaranteedDaysToDelivery' => $rate->GuaranteedDaysToDelivery ?? '',
                        'scheduledDeliveryTime' => $rate->ScheduledDeliveryTime ?? '',
                        'rateShipmentWarning' => $rate->RateShipmentWarning ?? '',
                        'surCharges' => $rate->SurCharges ?? '',
                        'timeInTransit' => $rate->TimeInTransit ?? '',
                    ],
                ];

                // If we're using negotiated rates, return that, not the normal values
                $negotiatedRates = $rate->NegotiatedRates ?? '';

                if ($negotiatedRates) {
                    $rateInfo['amount'] = $rate->NegotiatedRates->NetSummaryCharges->GrandTotal->MonetaryValue ?? '';
                }

                $this->_rates[$serviceHandle] = $rateInfo;
            }

            // Allow rate modification via events
            $modifyRatesEvent = new ModifyRatesEvent([
                'rates' => $this->_rates,
                'response' => $rates,
                'order' => $order,
            ]);

            if ($this->hasEventHandlers(self::EVENT_MODIFY_RATES)) {
                $this->trigger(self::EVENT_MODIFY_RATES, $modifyRatesEvent);
            }

            $this->_rates = $modifyRatesEvent->rates;

        } catch (\Throwable $e) {
            Provider::error($this, Craft::t('postie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }

        return $this->_rates;
    }

    protected function fetchConnection(): bool
    {
        try {
            // Create test addresses
            $sender = TestingHelper::getTestAddress('US', ['city' => 'Cupertino']);
            $recipient = TestingHelper::getTestAddress('US', ['city' => 'Mountain View']);

            // Create a test package
            $packedBoxes = TestingHelper::getTestPackedBoxes($this->dimensionUnit, $this->weightUnit);
            $packedBox = $packedBoxes[0];

            // Create a test payload
            $shipment = new Shipment();
            $shipFromAddress = new Address();
            $shipFromAddress->setPostalCode($sender->zipCode);

            $shipFrom = new ShipFrom();
            $shipFrom->setAddress($shipFromAddress);
            $shipment->setShipFrom($shipFrom);

            $shipTo = $shipment->getShipTo();
            $shipToAddress = $shipTo->getAddress();
            $shipToAddress->setPostalCode($recipient->zipCode);

            // Ensure they're formatted correctly, the API will error for too many decimals
            $weight = number_format($packedBox['weight'], 3);
            $height = number_format($packedBox['height'], 3);
            $width = number_format($packedBox['width'], 3);
            $length = number_format($packedBox['length'], 3);

            $package = new Package();
            $package->getPackagingType()->setCode(PackagingType::PT_PACKAGE);
            $package->getPackageWeight()->setWeight($weight);
            $weightUnit = new UnitOfMeasurement;
            $weightUnit->setCode(UnitOfMeasurement::UOM_LBS);
            $package->getPackageWeight()->setUnitOfMeasurement($weightUnit);

            $packageDimensions = new Dimensions();
            $packageDimensions->setHeight($height);
            $packageDimensions->setWidth($width);
            $packageDimensions->setLength($length);

            $unit = new UnitOfMeasurement;
            $unit->setCode(UnitOfMeasurement::UOM_IN);

            $packageDimensions->setUnitOfMeasurement($unit);
            $package->setDimensions($packageDimensions);
            $shipment->addPackage($package);

            $rateRequest = new RateRequest();
            $rateRequest->setShipment($shipment);
            $rates = $this->_getClient()->shopRates($rateRequest);
        } catch (\Throwable $e) {
            Provider::error($this, Craft::t('postie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Private Methods
    // =========================================================================

    private function _getClient()
    {
        if (!$this->_client) {
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                $accessKey = $this->getSetting('testApiKey');
            } else {
                $accessKey = $this->getSetting('apiKey');
            }

            $userId = $this->getSetting('username');
            $password = $this->getSetting('password');

            $this->_client = new Rate($accessKey, $userId, $password);
        }

        return $this->_client;
    }

    private function _inEU($country)
    {
        return isset($this->euCountries[$country->iso]) ? true : false;
    }

    private function _getServiceHandle($code, $storeLocation, $shippingAddress)
    {
        // We need some smarts here, because UPS has multiple handles for the same service code, depending on the
        // origin or destination of the parcel. Do a little more work here...
        $services = [
            'S_AIR_1DAYEARLYAM' => '14',
            'S_AIR_1DAY' => '01',
            'S_AIR_1DAYSAVER' => '13',
            'S_AIR_2DAYAM' => '59',
            'S_AIR_2DAY' => '02',
            'S_3DAYSELECT' => '12',
            'S_GROUND' => '03',
            'S_SURE_POST' => '93',

            // Valid international values
            'S_STANDARD' => '11',
            'S_WW_EXPRESS' => '07',
            'S_WW_EXPRESSPLUS' => '54',
            'S_WW_EXPEDITED' => '08',
            'S_SAVER' => '65',
            'S_ACCESS_POINT' => '70',

            // Valid Poland to Poland same day values
            'S_UPSTODAY_STANDARD' => '82',
            'S_UPSTODAY_DEDICATEDCOURIER' => '83',
            'S_UPSTODAY_INTERCITY' => '84',
            'S_UPSTODAY_EXPRESS' => '85',
            'S_UPSTODAY_EXPRESSSAVER' => '86',
            'S_UPSWW_EXPRESSFREIGHT' => '96',

            // Valid Germany to Germany values
            'S_UPSEXPRESS_1200' => '74',

            // Time in Transit Response Service Codes: United States Domestic Shipments
            'TT_S_US_AIR_1DAYAM' => '1DM',
            'TT_S_US_AIR_1DAY' => '1DA',
            'TT_S_US_AIR_SAVER' => '1DP',
            'TT_S_US_AIR_2DAYAM' => '2DM',
            'TT_S_US_AIR_2DAY' => '2DA',
            'TT_S_US_3DAYSELECT' => '3DS',
            'TT_S_US_GROUND' => 'GND',
            'TT_S_US_AIR_1DAYSATAM' => '1DMS',
            'TT_S_US_AIR_1DAYSAT' => '1DAS',
            'TT_S_US_AIR_2DAYSAT' => '2DAS',
        ];

        // Comment these out until we can figure out a better way to test origin EU addresses

        // $services = [
        //     // Time in Transit Response Service Codes: Other Shipments Originating in US
        //     'TT_S_US_INTL_EXPRESSPLUS' => '21',
        //     'TT_S_US_INTL_EXPRESS' => '01',
        //     'TT_S_US_INTL_SAVER' => '28',
        //     'TT_S_US_INTL_STANDARD' => '03',
        //     'TT_S_US_INTL_EXPEDITED' => '05',
        // ];

        // $services = [
        //     // Time in Transit Response Service Codes: Shipments Originating in the EU
        //     // Destination is WITHIN the Origin Country
        //     'TT_S_EU_EXPRESSPLUS' => '23',
        //     'TT_S_EU_EXPRESS' => '24',
        //     'TT_S_EU_SAVER' => '26',
        //     'TT_S_EU_STANDARD' => '25',
        // ];

        // $services = [
        //     // Time in Transit Response Service Codes: Shipments Originating in the EU
        //     // Destination is Another EU Country
        //     'TT_S_EU_TO_EU_EXPRESSPLUS' => '22',
        //     'TT_S_EU_TO_EU_EXPRESS' => '10',
        //     'TT_S_EU_TO_EU_SAVER' => '18',
        //     'TT_S_EU_TO_EU_STANDARD' => '08',
        // ];

        // $services = [
        //     // Time in Transit Response Service Codes: Shipments Originating in the EU
        //     // Destination is Outside the EU
        //     'TT_S_EU_TO_OTHER_EXPRESS_NA1' => '11',
        //     'TT_S_EU_TO_OTHER_EXPRESSPLUS' => '21',
        //     'TT_S_EU_TO_OTHER_EXPRESS' => '01',
        //     'TT_S_EU_TO_OTHER_SAVER' => '28',
        //     'TT_S_EU_TO_OTHER_EXPEDITED' => '05',
        //     'TT_S_EU_TO_OTHER_STANDARD' => '68',
        // ];

        $serviceHandle = array_search($code, $services);

        return $serviceHandle;
    }
}
