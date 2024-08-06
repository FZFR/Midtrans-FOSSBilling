<div align="center">
  <h1>Midtrans Integration for FOSSBilling</h1>
  <img src="https://img.shields.io/github/v/release/FZFR/Midtrans-FOSSBilling?include_prereleases&sort=semver&display_name=release&style=flat">
  <img src="https://img.shields.io/github/downloads/FZFR/Midtrans-FOSSBilling/total?style=flat">
  <img src="https://img.shields.io/github/repo-size/FZFR/Midtrans-FOSSBilling">
  <img alt="GitHub" src="https://img.shields.io/github/license/FZFR/Midtrans-FOSSBilling?style=flat">  
</div>

## Overview

This adapter integrates [Midtrans](https://midtrans.com) payment gateway into your [FOSSBilling](https://fossbilling.org) installation. It allows your customers to pay using a wide variety of payment methods supported by Midtrans, including credit/debit cards, bank transfers, e-wallets, and more, while seamlessly integrating with FOSSBilling.

> **Note**
> This extension is currently in beta. While it has been tested extensively, use in production environments should be done with caution and thorough testing.

## Table of Contents

- [Overview](#overview)
- [Table of Contents](#table-of-contents)
- [Features](#features)
- [Installation](#installation)
  - [1). Extension directory](#1-extension-directory)
  - [2). Manual installation](#2-manual-installation)
- [Configuration](#configuration)
  - [Webhook Configuration](#webhook-configuration)
- [Usage](#usage)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)

## Features

- **Multiple Payment Methods**: Supports various Midtrans payment options including credit/debit cards, bank transfers, e-wallets, and more.

- **Flexible Integration Options**:
  - **Snap Popup**: Displays Midtrans payment interface in a popup window.
  - **Embedded Snap**: Integrates Midtrans payment interface directly into FOSSBilling checkout page.

- **Automatic Invoice Management**:
  - Updates invoice status to 'paid' automatically upon successful payment.
  - Handles various payment statuses (capture, settlement, pending, deny, cancel, expire).

- **Detailed Transaction Logging**: Comprehensive logging for easy tracking and debugging.

- **Secure Payment Processing**:
  - Supports 3D Secure transactions for card payments.
  - Implements signature key verification for Midtrans notifications.

- **Sandbox Mode**: Allows testing in a sandbox environment before going live.

## Installation
### 1). Extension directory
> Not yet implemented
>
>
### 2). Manual installation
1. Download the latest release from the GitHub repository.
2. Create a new folder named **Midtrans** in the **/library/Payment/Adapter** directory of your FOSSBilling installation.
3. Extract the downloaded files into this new directory.
4. In your FOSSBilling admin panel, navigate to "**Payment gateways**" under the "System" menu.
5. Find Midtrans in the "**New payment gateway**" tab and click the *cog icon* to install and configure.

## Configuration

1. In your FOSSBilling admin panel, locate "**Midtrans**" under "**Payment gateways**".
2. Enter the following Midtrans credentials:
   - Merchant ID
   - Client Key
   - Server Key
3. Configure additional settings:
   - Sandbox mode (for testing)
   - Payment mode (popup or embedded)
   - Default country code
4. Save your configuration.

### Webhook Configuration

To set up webhooks for real-time payment notifications:

1. Log in to your Midtrans dashboard.
2. Navigate to Settings > Configuration.
3. Set the Payment Notification URL to:
   `https://your-fossbilling-domain.com/ipn.php?gateway_id=payment_gateway_id`
   (Replace `your-fossbilling-domain.com` with your actual domain and `payment_gateway_id` with the ID assigned by FOSSBilling)

## Usage

Once installed and configured:

1. Midtrans will appear as a payment option during the checkout process.
2. Customers can select Midtrans and choose their preferred payment method.
3. Depending on your configuration, they will either be redirected to a Midtrans popup or see an embedded payment form.
4. After payment, customers are redirected back to your site, and the invoice status is updated automatically.

## Troubleshooting

- **Check Logs**: Review logs at `library/Payment/Adapter/Midtrans/logs/midtrans.log` for transaction details and errors.
- **API Credentials**: Ensure your Midtrans API keys are correctly entered in the FOSSBilling configuration.
- **TLS Support**: Verify that your server supports TLS v1.2 for Midtrans notifications.
- **Webhook Issues**: If payment status updates are not working, check your webhook configuration in both Midtrans and FOSSBilling.
- **Payment Method Availability**: Some payment methods may not be available in certain regions or for certain transaction amounts. Refer to Midtrans documentation for details.

## Contributing

I welcome contributions to improve this integration. To contribute:

1. Fork the repository.
2. Create a new branch for your feature or bugfix: `git checkout -b feature-name`.
3. Make your changes and commit them with a clear message.
4. Push your branch and create a pull request.

Please ensure your code adheres to the existing style and include appropriate tests if applicable.

## License

This FOSSBilling Midtrans Payment Gateway Integration is open-source software licensed under the Apache License 2.0.

## Support

- For issues related to this adapter, please open an issue in the GitHub repository.
- For Midtrans-specific issues, please contact [Midtrans support](https://support.midtrans.com/).
- For FOSSBilling related questions, refer to the [FOSSBilling documentation](https://docs.fossbilling.org/).

> *Note*: This module is not officially affiliated with FOSSBilling or Midtrans. Please refer to their respective documentation for detailed information on their services and APIs.
