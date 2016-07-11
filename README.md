# WHMCS Iyzipay Merchant Gateway module #

## Summary ##

This gateway module provides Iyzipay (http://iyzico.com) payment gateway for WHMCS platform.

Currently it supports payments in TRY, but support for other currencies (which iyzipay supports) can be added easily. Also this module supports remote tokenised credit card storage (ie. WHMCS won't store the CC details in its database, you'll be storing them on Iyzipay). Refunds are supported.

This module does not support 3dsecure.

## Minimum Requirements ##

- WHMCS >= 6.0
- PHP >= 5.3.7
- Composer if you'd like to clone this repo

For the latest WHMCS minimum system requirements, please refer to
http://docs.whmcs.com/System_Requirements

## Installation ##

You can install this module by cloning the repo or downloading the latest release from GitHub. See the [releases](https://github.com/4bitweb/whmcs-iyzipay-tokenised/releases) page.

#### Cloning the repo ####
Clone the repo to whmcs_dir/modules/gateway directory directly. Go into your iyzipay directory and run:

`# composer install`

#### Downloading the latest release (Recommended!) ####
You can download the latest release and unzip it to your whmcs_dir/modules/gateway directory. You won't need to user composer for required Iyzipay modules, they will be in the compressed package.

After installing using whichever method you prefer, go to your WHMCS admin page and activate your gateway. You'll need to provide;
- Your API key (or Sandbox API key)
- Your secret key (or Sandbox secret key)
- A unique identifier for Iyzipay conversation ID
