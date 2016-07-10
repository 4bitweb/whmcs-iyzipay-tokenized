<?php
/**
 * WHMCS Sample Merchant Gateway Module
 *
 * This sample file demonstrates how a merchant gateway module supporting
 * 3D Secure Authentication, Captures and Refunds can be structured.
 *
 * If your merchant gateway does not support 3D Secure Authentication, you can
 * simply omit that function and the callback file from your own module.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For this
 * example file, the filename is "merchantgateway" and therefore all functions
 * begin "merchantgateway_".
 * For more information, please refer to the online documentation.
 *
 * @see http://docs.whmcs.com/Gateway_Module_Developer_Docs
 *
 * @copyright Copyright (c) WHMCS Limited 2015
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

/* TODO
 *
 * - Group all shared functions
 * - Fix documentation and explanation blocks
 * - Add payment gateway fees to capture output (see: http://docs.whmcs.com/Dev:Gateway:Merchant:Captures)
 *   received amount = totalamount - (iyziCommissionRateAmount + iyziCommissionFee)
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// require iyzipay PHP client - should be installed using composer
include __DIR__ . '/vendor/autoload.php';

// use WHMCS (Laravel) db functions
use Illuminate\Database\Capsule\Manager as Capsule;

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
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see http://docs.whmcs.com/Gateway_Module_Meta_Data_Parameters
 *
 * @return array
 */
function iyzipay_MetaData()
{
    return array(
        'DisplayName' => 'Iyzipay Merchant Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => false,
        'TokenisedStorage' => true,
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
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
        // the yesno field type displays a single checkbox option
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
    );
}

/**
 * Capture payment.
 *
 * Called when a payment is to be processed and captured.
 *
 * The card cvv number will only be present for the initial card holder present
 * transactions. Automated recurring capture attempts will not provide it.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function iyzipay_capture($params)
{
    // test mode aciksa API adresini degistirelim
    $baseUrl = set_base_url($params);
    // conversationId uretelim
    $conversationId = "123456789";
    $invoiceItems = get_invoice_items($params['invoiceid']);
    $invoiceTotal = get_invoice_total($params['invoiceid']);
    $ccExpireMonth = substr($params['cardexp'], 0, 2);
    $ccExpireYear = date("Y", mktime(0, 0, 0, 1, 1, substr($params['cardexp'], 2, 2)));
    $clientId = get_user_id($params['invoiceid']);
    $clientAddress = ($params['clientdetails']['address2']) ? $params['clientdetails']['address1'] . " " . $params['clientdetails']['address2'] : $params['clientdetails']['address1'];
    $clientIP = $_SERVER['REMOTE_ADDR'];

    /* Create Iyzipay API options */
    $options = new \Iyzipay\Options();
    $options->setApiKey($params['apiKey']);
    $options->setSecretKey($params['secretKey']);
    $options->setBaseUrl($baseUrl);

    /* Create payment request */
    $request = new \Iyzipay\Request\CreatePaymentRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($conversationId);
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
    $buyer->setIdentityNumber("11111111111");
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
        $request->setPaymentCard($paymentCard);
    }

    if (NULL == $params['cardnum'])
    {
        list ($cardUserKey, $cardToken) = get_card_token($params['gatewayid']);
        /* Create payment card details */
        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardUserKey($cardUserKey);
        $paymentCard->setCardToken($cardToken);
        $request->setPaymentCard($paymentCard);
    }

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
        return array(
            'status' => 'failed',
            'rawdata' => $payment->getRawResult(),
        );
    }
}


/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function iyzipay_refund($params)
{
    // test mode aciksa API adresini degistirelim
    $baseUrl = set_base_url($params);
    // conversationId uretelim
    $conversationId = "123456789";

    /* Create Iyzipay API options */
    $options = new \Iyzipay\Options();
    $options->setApiKey($params['apiKey']);
    $options->setSecretKey($params['secretKey']);
    $options->setBaseUrl($baseUrl);

    $request = new \Iyzipay\Request\CreateCancelRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId("123456789");
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

/**
 * Remote card storage
 *
 * Called when a credit card is requested for storage by iyzipay
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Remote storage response status
 */
function iyzipay_storeremote($params)
{
    $ccExpireMonth = substr($params['cardexp'], 0, 2);
    $ccExpireYear = date("Y", mktime(0, 0, 0, 1, 1, substr($params['cardexp'], 2, 2)));

    // init iyzipay api options
    // test mode aciksa API adresini degistirelim
    $baseUrl = set_base_url($params);
    // conversationId uretelim
    $conversationId = "123456789";
    // get the CardToken and CardUserKey from database
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
        $request->setConversationId("123456789");
        $request->setCardToken($cardToken);
        $request->setCardUserKey($cardUserKey);

        # make request
        $card = \Iyzipay\Model\Card::delete($request, $options);

        if ("success" == strtolower($card->getStatus())) {
            /* Create gatewayid from userkey and token */
            $ccGatewayId = $card->getCardUserKey() . "|" . $card->getCardToken();
            return array(
                "status" => "success",
                "rawdata" => $card->getRawResult(),
            );
        } else {
            return array(
                "status" => "failed",
                "rawdata" => $card->getRawResult(),
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
        $request->setConversationId("123456789");
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
                "status" => strtolower($card->getStatus()),
                "gatewayid" => $ccGatewayId,
                "rawdata" => $card->getRawResult(),
            );
        } else {
            return array(
                "status" => "failed",
                "rawdata" => $card->getRawResult(),
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
        $request->setConversationId("123456789");
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
                "status" => strtolower($card->getStatus()),
                "gatewayid" => $ccGatewayId,
                "rawdata" => $card->getRawResult(),
            );
        } else {
            return array(
                "status" => "failed",
                "rawdata" => $card->getRawResult(),
            );
        }
    }
}
