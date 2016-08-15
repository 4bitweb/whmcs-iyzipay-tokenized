<?php
/**
 * WHMCS Iyzipay 3D Secure Callback File
 *
 *
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
include __DIR__ . '../iyzipay/vendor/autoload.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Set the base URL
$baseUrl = set_base_url($gatewayParams);

$initStatus = $_POST["status"];
$initTransactionId = $_POST["paymentId"];
$initConversationId = $_POST["conversationId"];
$initConversationData = $_POST["conversationData"];

$cbSuccess = false;

if ($initConversationId != $gatewayParams["conversationId"])
{
    $cbSuccess = false;
    $transactionStatus = "Request cannot be verified";
    die($transactionStatus);
}

if ("success" != $initStatus)
{
    $cbSuccess = false;
    $transactionStatus = "3D Secure payment failed";
}

if ("success" == $initStatus)
{
    $options = new \Iyzipay\Options();
    $options->setApiKey($gatewayParams['apiKey']);
    $options->setSecretKey($gatewayParams['secretKey']);
    $options->setBaseUrl($baseUrl);

    $request = new \Iyzipay\Request\CreateThreedsPaymentRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId($gatewayParams["conversationId"]);
    $request->setPaymentId($initTransactionId);
    $request->setConversationData($initConversationData);

    # make request
    $auth = \Iyzipay\Model\ThreedsPayment::create($request, $options);
}

if (NULL == $auth)
{
    $cbSuccess = false;
    callback3DSecureRedirect($invoiceId, $cbSuccess);
}

if ("success" == $auth->getStatus() && 1 == $auth->getFraudStatus())
{
    $invoiceId = $auth->getBasketId();
    $transactionId = $auth->getpaymentId();
    $paymentAmount = $auth->getPaidPrice();
    $paymentFee = get_comission_rate($auth);

    // Validate invoice id
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

    // Validate transaction id
    checkCbTransID($transactionId);

    logTransaction($gatewayParams['name'], $_POST, $transactionStatus);

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        $paymentFee,
        $gatewayModuleName
    );

    $cbSuccess = true;
} elseif ("failure" == $auth->getStatus()) {
    $transactionStatus = $auth->getErrorMessage();
    $cbSuccess = false;
}

// Redirect to invoice
callback3DSecureRedirect($invoiceId, $cbSuccess);
