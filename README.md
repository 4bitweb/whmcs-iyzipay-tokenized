# WHMCS Iyzipay Merchant Gateway module #

## Summary ##

This gateway module provides Iyzipay (http://iyzico.com) payment gateway for WHMCS platform.

Currently it supports payments in TRY, but support for other currencies (which iyzipay supports) can be added easily. Also this module supports remote tokenized credit card storage (ie. WHMCS won't store the CC details in its database, you'll be storing them on Iyzipay). Refunds are supported.

This module does not support 3dsecure.

## Minimum Requirements ##

- WHMCS >= 6.0
- PHP >= 5.3.7
- Composer if you'd like to clone this repo

For the latest WHMCS minimum system requirements, please refer to
http://docs.whmcs.com/System_Requirements

## Installation ##

You can install this module by cloning the repo or downloading the latest release from GitHub. See the [releases](https://github.com/4bitweb/whmcs-iyzipay-tokenized/releases) page.

#### Cloning the repo ####
Clone the repo to whmcs_dir/modules/gateway directory directly. Change the directory name to "iyzipay";
```
# mv whmcs-iyzipay-tokenized iyzipay
# cd iyzipay
```

In your iyzipay directory, run:

`# composer install`

#### Downloading the latest release (Recommended!) ####
You can download the latest release and unzip it to your whmcs_dir/modules/gateway directory. You won't need to use Composer, all the required libraries are included in the compressed package.

After installing using whichever method you prefer, go to your WHMCS admin page and activate your gateway. You'll need to provide;
- Your API key (or Sandbox API key)
- Your secret key (or Sandbox secret key)
- A unique identifier for Iyzipay conversation ID

## Using the module without tokenized CC storage ##

If you'd like to use the module without remote card storage (ie. you don't have that feature enabled in your Iyzipay account) just remove the `iyzipay_storeremote` function and change the metadata function;

```
function iyzipay_MetaData()
{
    return array(
        'DisplayName' => 'Iyzipay Merchant Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => false,
        'TokenisedStorage' => false,
    );
}
```

---

## Iyzipay Modülü ##

Bu modülü kullanarak WHMCS kurulumunuzdan Iyzipay ile ödeme alabilirsiniz.

Şu anda modül yalnızca TRY ile ödeme almanızı sağlıyor fakat birkaç kontrol ekleyerek ve düzenleme yapılarak farklı para birimlerinden de ödeme alınabilir. Ayrıca Iyzico hesabınızda kart saklama özelliği aktifse, bu modülü kullanarak WHMCS yerine Iyzico'nun güvenli altyapısında kullanıcılarınız kredi kartlarını saklayabilirsiniz. İade işlemlerini de WHMCS üzerinden gerçekleştirebilirsiniz.

Bu modülde 3dsecure desteği bulunmamaktadır.

## Minimum Gereksinimler ##

- WHMCS >= 6.0
- PHP >= 5.3.7
- Eğer bu repoyu clonelayarak kullanacaksanız Composer

WHMCS'nin minimum gereksinimlerini görmek için http://docs.whmcs.com/System_Requirements adresine göz atabilirsiniz.

## Kurulum ##

Modülü whmcs/modules/gateway klasörü içerisine girek clonelayabilir ya da GitHub üzerinden son sürümü indirebilirsiniz. Sürümler için [releases](https://github.com/4bitweb/whmcs-iyzipay-tokenized/releases) sayfasına göz atın.

#### Clone ####

Repoyu clonelayacaksanız whmcs_dizini/modules/gateway dizini içerisinde yapmalısınız. Daha sonra oluşan klasörün adını iyzipay olarak değiştirin;

```
# mv whmcs-iyzipay-tokenized iyzipay
# cd iyzipay
```

Iyzipay modülünün klasörü içerisinde composer çalıştırın;

`# composer install`

#### Son sürümü indirin (önerilen kurulum) ####

[Buradan](https://github.com/4bitweb/whmcs-iyzipay-tokenized/releases) son sürümü indirdikten sonra whmcs_dir/modules/gateway dizinine dosyaları çıkartın. Daha sonra WHMCS yönetici sayfanızdan modülü aktif hale getirin.

Kurulumunuzu gerçekleştirdikten sonra modülünüzü ayarlamanız gerekiyor. Gerekli bilgiler;

- API Key (test mode kullanacaksanız sandbox için api key)
- Secret Key (test mode kullanacaksanız sandbox için secret key)
- Iyzipay ile iletişimde kullanılacak Unique ID (herhangi bir random string)

## Modülü kredi kartı saklama olmadan kullanmak ##

Eğer hesabınızda kredi kartı saklama aktif değilse veya başka bir nedenden dolayı kullanmak istemezseniz, önce iyzipay/index.php dosyası içerisindeki `iyzipay_storeremote` fonksiyonunu kaldırın. Daha sonra yine aynı dosya içerisindeki metadata fonksiyonunu değiştirin;

```
function iyzipay_MetaData()
{
    return array(
        'DisplayName' => 'Iyzipay Merchant Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => false,
        'TokenisedStorage' => false,
    );
}
```
