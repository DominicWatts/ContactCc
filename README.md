# Magento 2 ContactCc

Email CC and BCC fields on contact us form

# Install instructions #

`composer require dominicwatts/contactcc`

`php bin/magento setup:upgrade`

`php bin/magento setup:di:compile`

# Usage instructions #

Configure optional email values in admin

![Screenshot](https://i.snipboard.io/9pqGuW.jpg)

Use contact form

Email will be sent to additional recipients based on configuration
