<?php
/**
 * WHMCS Iyzipay Merchant Gateway Module
 *
 * Requirements:
 *
 * - WHMCS > 6.0 (untested below WHMCS below 6.0)
 * - PHP > 5.3.7
 * - Composer (https://getcomposer.org/) if you checkout the code from git
 *
 * This gateway module implements Iyzipay's payment API and leverages Iyzipay's
 * credit card storage.
 *
 * When a user adds or removes a credit card, it will be stored on Iyzico's
 * servers. WHMCS only stores the last 4 digits and expiration date of the cc.
 *
 * - This module currently does not support installments.
 * - You can only accept TRY payments at the moment.
 *
 * @copyright Copyright (c) (Tahir Can Ozokur - 4-bit) 2016
 * @license MIT - See the LICENSE file for details.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// include iyzipay PHP client - should be installed using composer
include __DIR__ . '/vendor/autoload.php';

// use WHMCS (Laravel) db functions
use Illuminate\Database\Capsule\Manager as Capsule;

/** HELPER FUNCTIONS START **/

/**
 * Pull the invoice items from DB.
 *
 * Iyzipay API requires cart items to be sent. So, we're sending them the
 * invoice items.
 *
 * @param int $invoice Invoice ID
 *
 * @return array Invoice items
 */

function get_invoice_items($invoice)
{
    return Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice)
                                            ->get();
}

/**
 * Pull the invoice total amount from DB.
 *
 * Iyzipay API requires cart items totals to be included in the request.
 *
 * @param int $invoice Invoice ID
 *
 * @return int Invoice items' amount sum
 */

function get_invoice_total($invoice)
{
    return Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice)
                                            ->sum('amount');
}

/**
 * Pull the invoice total amount from DB.
 *
 * Iyzipay API requires cart items totals to be included in the request.
 *
 * @param int $invoice Invoice ID
 *
 * @return int Client id
 */

function get_user_id($invoice)
{
    $result = Capsule::table('tblinvoiceitems')->select('userid')
                                            ->where('invoiceid', $invoice)
                                            ->first();
    return $result->userid;
}

/**
 * Get the card token and card user key from database
 *
 * As whmcs stores a single line as gatewayid, we need to split token
 * and user key.
 *
 * @param string $gwid
 *
 * @return array CardToken and CardUserKey
 */
function get_card_token($gwid)
{
    return explode("|", $gwid);
}

/**
 * Return the sum of iyzipay's comission rate
 *
 * Iyzipay returns two comission rates, one is a constant fee per transaction
 * the other one is based on a percentage.
 *
 * @param obj $payment Iyzipay payment object
 *
 * @return int Iyzipay comission fees sum
 */
function get_comission_rate($payment)
{
    $sum = $payment->getIyziCommissionRateAmount() + $payment->getIyziCommissionFee();
    return $sum;
}

/**
 * Get Iyzipay API endpoint url
 *
 * Return the suitable API URL for testing or live modes.
 *
 * @param array WHMCS parameters
 * @return string API URL
 */
function set_base_url($params)
{
    return ("on" == $params['testMode']) ? "https://sandbox-api.iyzipay.com" : "https://api.iyzipay.com";
}

/**
 * Get TC Kimlik No from custom fields
 *
 * Return the TC Kimlik No for user
 *
 * @param array WHMCS parameters
 * @return string TC Kimlik No
 */
function get_tckimlik($params)
{
    // See if there's a TC Kimlik field if not, set TCKimlik to a default value
    if (NULL === $params['tcModule'])
    {
        $tcKimlik = '11111111111';
    } else {
        $tcKey = $params['tcModule'];
        foreach ($params['clientdetails']['customfields'] as $key => $value)
        {
            if ($value['id'] == $tcKey)
            {
                $tcKimlik = $value['value'];
            }
        }
    }

    return $tcKimlik;
}

/** HELPER FUNCTIONS END **/

function iyzipay_MetaData()
{
    return array(
        'DisplayName' => 'Iyzipay Merchant Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => false,
        'TokenisedStorage' => true,
    );
}

function iyzipay_config()
{
    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Iyzipay',
        ),
        // a text field type allows for single line text input
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '32',
            'Default' => '',
            'Description' => 'Enter your API Key here',
        ),
        // a password field type allows for masked text input
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '32',
            'Default' => '',
            'Description' => 'Enter your Secret Key here',
        ),
        'conversationId' => array(
            'FriendlyName' => 'Conversation ID',
            'Type' => 'text',
            'Size' => '16',
            'Default' => '',
            'Description' => 'A random alphanumeric string for Iyzipay conversation ID. Must be set for 3DSecure'
        ),
        // the yesno field type displays a single checkbox option
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
        'tcModule' => array(
            'FriendlyName' => 'Turkish Identification Number Field',
            'Type' => 'text',
            'Size' => '3',
            'Default' => '',
            'Description' => "Leave blank if you don't have a TC Kimlik No addon",
        ),
    );
}

function iyzipay_capture($params)
{
    // test mode aciksa API adresini degistirelim
    $baseUrl = set_base_url($params);
    $invoiceItems = get_invoice_items($params['invoiceid']);
    $invoiceTotal = get_invoice_total($params['invoiceid']);
    $ccExpireMonth = substr($params['cardexp'], 0, 2);
    $ccExpireYear = date("Y", mktime(0, 0, 0, 1, 1, substr($params['cardexp'], 2, 2)));
    $clientId = get_user_id($params['invoiceid']);
    $clientAddress = ($params['clientdetails']['address2']) ? $params['clientdetails']['address1'] . " " . $params['clientdetails']['address2'] : $params['clientdetails']['address1'];
    $clientIP = $_SERVER['REMOTE_ADDR'];
    $tcKimlik = get_tckimlik($params);

    /* Create Iyzipay API options */
    $options = new \Iyzipay\Options();
    $options->setApiKey($params['apiKey']);
    $options->setSecretKey($params['secretKey']);
    $options->setBaseUrl($baseUrl);

    /* Create payment request */
    $request = new \Iyzipay\Request\CreatePaymentRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($params['conversationId']);
    $request->setPrice($invoiceTotal);
    $request->setPaidPrice($params['amount']);
    $request->setCurrency(\Iyzipay\Model\Currency::TL);
    $request->setInstallment(1);
    $request->setBasketId($params['invoiceid']);
    $request->setPaymentChannel(\Iyzipay\Model\PaymentChannel::WEB);
    $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::SUBSCRIPTION);

    /* Create buyer details */
    $buyer = new \Iyzipay\Model\Buyer();
    $buyer->setId($clientId);
    $buyer->setName($params['clientdetails']['firstname']);
    $buyer->setSurname($params['clientdetails']['lastname']);
    $buyer->setEmail($params['clientdetails']['email']);
    $buyer->setIdentityNumber($tcKimlik);
    $buyer->setRegistrationAddress($clientAddress);
    $buyer->setIp($clientIP);
    $buyer->setCity($params['clientdetails']['city']);
    $buyer->setCountry($params['clientdetails']['country']);
    if (NULL != $params['clientdetails']['postcode'])
    {
        $buyer->setZipCode($params['clientdetails']['postcode']);
    }
    $request->setBuyer($buyer);

    /* Create billing address */
    $billingAddress = new \Iyzipay\Model\Address();
    $billingAddress->setContactName($params['clientdetails']['fullname']);
    $billingAddress->setCity($params['clientdetails']['city']);
    $billingAddress->setCountry($params['clientdetails']['country']);
    $billingAddress->setAddress($clientAddress);
    if (NULL != $params['clientdetails']['postcode'])
    {
        $billingAddress->setZipCode($params['clientdetails']['postcode']);
    }
    $request->setBillingAddress($billingAddress);

    /* Create basket items array and set them */
    $basketItems = array();
    foreach ($invoiceItems as $invoiceItem)
    {
        if ($invoiceItem->amount < 0)
        {
            $promo = array_pop($basketItems);
            $promoPrice = $promo->getPrice();
            $promoPrice = $promoPrice + $invoiceItem->amount;
            if ($promoPrice == 0)
            {
                continue;
            } else {
                $promo->setPrice($promoPrice);
                array_push($basketItems, $promo);
            }
            continue;
        }
        $basketItem = new \Iyzipay\Model\BasketItem();
        $basketItem->setId($invoiceItem->id);
        $basketItem->setName($invoiceItem->description);
        if (NULL == $invoiceItem->type)
        {
            $basketItem->setCategory1("Misc");
        } else {
            $basketItem->setCategory1($invoiceItem->type);
        }
        $basketItem->setItemType(\Iyzipay\Model\BasketItemType::VIRTUAL);
        $basketItem->setPrice("$invoiceItem->amount");
        array_push($basketItems, $basketItem);
    }
    $request->setBasketItems($basketItems);

    if (NULL == $params['gatewayid'])
    {
        /* Create payment card details */
        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardHolderName($params['clientdetails']['fullname']);
        $paymentCard->setCardNumber($params['cardnum']);
        $paymentCard->setExpireMonth($ccExpireMonth);
        $paymentCard->setExpireYear($ccExpireYear);
        $paymentCard->setCvc($params['cccvv']);
        $paymentCard->setRegisterCard(0);
    }  else {
        list ($cardUserKey, $cardToken) = get_card_token($params['gatewayid']);
        /* Create payment card details */
        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardUserKey($cardUserKey);
        $paymentCard->setCardToken($cardToken);
    }

    $request->setPaymentCard($paymentCard);

    /* Finally, make the request and return payment object */
    $payment = \Iyzipay\Model\Payment::create($request, $options);

    /*
     * Return success if everyting went smooth and we don't have a fraud report
     * Return failure if there's a fraud report or the operation has failed
     *
     */
    if ("success" == strtolower($payment->getStatus()) && 1 == $payment->getFraudStatus())
    {
        $fees = get_comission_rate($payment);
        return array(
            'status' => strtolower($payment->getStatus()),
            'rawdata' => $payment->getRawResult(),
            'transid' => $payment->getPaymentId(),
            'fee' => $fees,
        );
    } else {
        logModuleCall('iyzipay', 'requestobject', $basketItems);
        return array(
            'status' => 'failed',
            'rawdata' => $payment->getRawResult(),
        );
    }
}

function iyzipay_3dsecure($params)
{
    // test mode aciksa API adresini degistirelim
    $baseUrl = set_base_url($params);
    $invoiceItems = get_invoice_items($params['invoiceid']);
    $invoiceTotal = get_invoice_total($params['invoiceid']);
    $ccExpireMonth = substr($params['cardexp'], 0, 2);
    $ccExpireYear = date("Y", mktime(0, 0, 0, 1, 1, substr($params['cardexp'], 2, 2)));
    $clientId = get_user_id($params['invoiceid']);
    $clientAddress = ($params['clientdetails']['address2']) ? $params['clientdetails']['address1'] . " " . $params['clientdetails']['address2'] : $params['clientdetails']['address1'];
    $clientIP = $_SERVER['REMOTE_ADDR'];
    $callbackUrl = $params['systemurl'].'/modules/gateways/callback/iyzipay.php';
    $tcKimlik = get_tckimlik($params);

    /* Create Iyzipay API options */
    $options = new \Iyzipay\Options();
    $options->setApiKey($params['apiKey']);
    $options->setSecretKey($params['secretKey']);
    $options->setBaseUrl($baseUrl);

    /* Create payment request */
    $request = new \Iyzipay\Request\CreatePaymentRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($params['conversationId']);
    $request->setPrice($invoiceTotal);
    $request->setPaidPrice($params['amount']);
    $request->setCurrency(\Iyzipay\Model\Currency::TL);
    $request->setInstallment(1);
    $request->setBasketId($params['invoiceid']);
    $request->setPaymentChannel(\Iyzipay\Model\PaymentChannel::WEB);
    $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::SUBSCRIPTION);
    $request->setCallbackUrl($callbackUrl);

    /* Create buyer details */
    $buyer = new \Iyzipay\Model\Buyer();
    $buyer->setId($clientId);
    $buyer->setName($params['clientdetails']['firstname']);
    $buyer->setSurname($params['clientdetails']['lastname']);
    $buyer->setEmail($params['clientdetails']['email']);
    $buyer->setIdentityNumber($tcKimlik);
    $buyer->setRegistrationAddress($clientAddress);
    $buyer->setIp($clientIP);
    $buyer->setCity($params['clientdetails']['city']);
    $buyer->setCountry($params['clientdetails']['country']);
    if (NULL != $params['clientdetails']['postcode'])
    {
        $buyer->setZipCode($params['clientdetails']['postcode']);
    }
    $request->setBuyer($buyer);

    /* Create billing address */
    $billingAddress = new \Iyzipay\Model\Address();
    $billingAddress->setContactName($params['clientdetails']['fullname']);
    $billingAddress->setCity($params['clientdetails']['city']);
    $billingAddress->setCountry($params['clientdetails']['country']);
    $billingAddress->setAddress($clientAddress);
    if (NULL != $params['clientdetails']['postcode'])
    {
        $billingAddress->setZipCode($params['clientdetails']['postcode']);
    }
    $request->setBillingAddress($billingAddress);

    /* Create basket items array and set them */
    $basketItems = array();
    foreach ($invoiceItems as $invoiceItem)
    {
        if ($invoiceItem->amount < 0)
        {
            $promo = array_pop($basketItems);
            $promoPrice = $promo->getPrice();
            $promoPrice = $promoPrice + $invoiceItem->amount;
            if ($promoPrice == 0)
            {
                continue;
            } else {
                $promo->setPrice($promoPrice);
                array_push($basketItems, $promo);
            }
            continue;
        }
        $basketItem = new \Iyzipay\Model\BasketItem();
        $basketItem->setId($invoiceItem->id);
        $basketItem->setName($invoiceItem->description);
        if (NULL == $invoiceItem->type)
        {
            $basketItem->setCategory1("Misc");
        } else {
            $basketItem->setCategory1($invoiceItem->type);
        }
        $basketItem->setItemType(\Iyzipay\Model\BasketItemType::VIRTUAL);
        $basketItem->setPrice("$invoiceItem->amount");
        array_push($basketItems, $basketItem);
    }
    $request->setBasketItems($basketItems);

    if (NULL == $params['gatewayid'])
    {
        /* Create payment card details */
        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardHolderName($params['clientdetails']['fullname']);
        $paymentCard->setCardNumber($params['cardnum']);
        $paymentCard->setExpireMonth($ccExpireMonth);
        $paymentCard->setExpireYear($ccExpireYear);
        $paymentCard->setCvc($params['cccvv']);
        $paymentCard->setRegisterCard(0);
    }  else {
        list ($cardUserKey, $cardToken) = get_card_token($params['gatewayid']);
        /* Create payment card details */
        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardUserKey($cardUserKey);
        $paymentCard->setCardToken($cardToken);
    }

    $request->setPaymentCard($paymentCard);

    /* Finally, make the request and return 3ds object */
    $threedsInitialize = \Iyzipay\Model\ThreedsInitialize::create($request, $options);

    $status = $threedsInitialize->getStatus();

    if ("success" == $status)
    {
        logModuleCall('iyzipay3ds', '3dsInit', print_r($request, true), $threedsInitialize->getRawResult());
        $htmlOutput = $threedsInitialize->getHtmlContent();
        return $htmlOutput;
    } else {
        $output = '<script type="text/javascript">';
        $output .= 'document.getElementById("frmThreeDAuth").style.display = "block";';
        $output .= 'document.getElementById("frmThreeDAuth").className = "";';
        $output .= '</script>';
        $output .= '3D Secure işleminizde bir hata oluştu. Lütfen tekrar deneyiniz.';
        logModuleCall('iyzipay3ds', '3dsInit', print_r($request, true), $threedsInitialize->getRawResult());
        return $output;
    }
}

function iyzipay_refund($params)
{
    // test mode aciksa API adresini degistirelim
    $baseUrl = set_base_url($params);

    /* Create Iyzipay API options */
    $options = new \Iyzipay\Options();
    $options->setApiKey($params['apiKey']);
    $options->setSecretKey($params['secretKey']);
    $options->setBaseUrl($baseUrl);

    $request = new \Iyzipay\Request\CreateCancelRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($params['conversationId']);
    $request->setPaymentId($params['transid']);

    # make request
    $cancel = \Iyzipay\Model\Cancel::create($request, $options);

    if ("success" == strtolower($cancel->getstatus()))
    {
        return array(
            'status' => strtolower($cancel->getStatus()),
            'rawdata' => $cancel->getRawResult(),
            'transid' => $cancel->getPaymentId(),
            'fees' => $cancel->getPrice(),
        );
    } else {
        return array(
            'status' => 'failed',
            'rawdata' => $cancel->getRawResult(),
        );
    }
}

function iyzipay_storeremote($params)
{
    $ccExpireMonth = substr($params['cardexp'], 0, 2);
    $ccExpireYear = date("Y", mktime(0, 0, 0, 1, 1, substr($params['cardexp'], 2, 2)));

    // init iyzipay api options
    // Get the API url
    $baseUrl = set_base_url($params);

    // extract CardToken and CardUserKey from gatewayid
    list ($cardUserKey, $cardToken) = get_card_token($params['gatewayid']);

    /* Create Iyzipay API options */
    $options = new \Iyzipay\Options();
    $options->setApiKey($params['apiKey']);
    $options->setSecretKey($params['secretKey']);
    $options->setBaseUrl($baseUrl);

    /* Check if delete is requested */
    if (NULL == $params['cardnum'])
    {
        $request = new \Iyzipay\Request\DeleteCardRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId($params['conversationId']);
        $request->setCardToken($cardToken);
        $request->setCardUserKey($cardUserKey);

        # make request
        $card = \Iyzipay\Model\Card::delete($request, $options);

        if ("success" == strtolower($card->getStatus())) {
            /* Create gatewayid from userkey and token */
            $ccGatewayId = $card->getCardUserKey() . "|" . $card->getCardToken();
            return array(
                'status' => 'success',
                'rawdata' => $card->getRawResult(),
            );
        } else {
            return array(
                'status' => 'failed',
                'rawdata' => $card->getRawResult(),
            );
        }
    }

    /* Check if the gateway id is null. If it is, then we need to
     * create a user and store the card
     */
    if (NULL == $params['gatewayid'])
    {
        # create request class
        $request = new \Iyzipay\Request\CreateCardRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId($params['conversationId']);
        $request->setEmail($params['clientdetails']['email']);

        $cardInformation = new \Iyzipay\Model\CardInformation();
        $cardInformation->setCardAlias($params['clientdetails']['uuid']);
        $cardInformation->setCardHolderName($params['clientdetails']['fullname']);
        $cardInformation->setCardNumber($params['cardnum']);
        $cardInformation->setExpireMonth($ccExpireMonth);
        $cardInformation->setExpireYear($ccExpireYear);
        $request->setCard($cardInformation);

        # make request
        $card = \Iyzipay\Model\Card::create($request, $options);

        if ("success" == strtolower($card->getStatus())) {
            /* Create gatewayid from userkey and token */
            $ccGatewayId = $card->getCardUserKey() . "|" . $card->getCardToken();
            return array(
                'status' => strtolower($card->getStatus()),
                'gatewayid' => $ccGatewayId,
                'rawdata' => $card->getRawResult(),
            );
        } else {
            return array(
                'status' => 'failed',
                'rawdata' => $card->getRawResult(),
            );
        }
    }

    /* if gatewayid is valid, we need to update the card */
    if ($params['gatewayid'])
    {
        # get userkey and token
        list ($cardUserKey, $cardToken) = get_card_token($params['gatewayid']);
        # create request class
        $request = new \Iyzipay\Request\CreateCardRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId($params['conversationId']);
        $request->setCardUserKey($cardUserKey);

        $cardInformation = new \Iyzipay\Model\CardInformation();
        $cardInformation->setCardAlias($params['clientdetails']['uuid']);
        $cardInformation->setCardHolderName($params['clientdetails']['fullname']);
        $cardInformation->setCardNumber($params['cardnum']);
        $cardInformation->setExpireMonth($ccExpireMonth);
        $cardInformation->setExpireYear($ccExpireYear);
        $request->setCard($cardInformation);

        # make request
        $card = \Iyzipay\Model\Card::create($request, $options);

        if ("success" == strtolower($card->getStatus())) {
            /* Create gatewayid from userkey and token */
            $ccGatewayId = $card->getCardUserKey() . "|" . $card->getCardToken();
            return array(
                'status' => strtolower($card->getStatus()),
                'gatewayid' => $ccGatewayId,
                'rawdata' => $card->getRawResult(),
            );
        } else {
            return array(
                'status' => 'failed',
                'rawdata' => $card->getRawResult(),
            );
        }
    }
}
