[![Build Status](https://scrutinizer-ci.com/g/gplcart/twocheckout/badges/build.png?b=master)](https://scrutinizer-ci.com/g/gplcart/twocheckout/build-status/master) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/gplcart/twocheckout/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/gplcart/twocheckout/?branch=master)

Stripe is a [GpL Cart](https://github.com/gplcart/gplcart) module that integrates [2 Checkout](https://www.2checkout.com) payment gateway into your shopping cart

Dependencies: [Omnipay Library](https://github.com/gplcart/omnipay_library)

Installation:

1. Download and extract to `system/modules` manually or using composer `composer require gplcart/twocheckout`. IMPORTANT: If you downloaded the module manually, be sure that the name of extracted module folder doesn't contain a branch/version suffix, e.g `-master`. Rename if needed.
2. Go to `admin/module/list` end enable the module
3. Adjust settings at `admin/module/settings/twocheckout`