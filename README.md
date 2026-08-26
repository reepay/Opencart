# Frisbii Opencart
Payment Plugin for Opencart of version 4

Latest version: 1.1.3

Frisbii Payments for OpenCart connects your store to the Frisbii payment platform, giving your customers a seamless checkout experience with support for cards, MobilePay, ViaBill, Klarna, and many more local and international payment methods.

## Information

Compatible with OpenCart versions: 4.0, 4.1

With this extension, you can:
- Accept all major local and international payment methods — including Dankort, VISA, Mastercard, MobilePay, Vipps, ViaBill, Swish, Klarna, Apple Pay, Google Pay, and more
- Choose between **Window** checkout (redirect to Frisbii hosted page) or **Overlay** checkout (modal on your storefront)
- Instantly settle payments or capture separately after fulfillment
- Issue full or partial refunds and voids directly from the OpenCart order admin
- Enable or disable individual sub-payment methods (MobilePay, Vipps, Anyday, etc.) independently
- Display payment logos on the checkout page to build customer trust

## Installation

1. Download the `.zip` file from [Releases](https://github.com/reepay/Opencart/releases)
2. Log in to your OpenCart Administrator panel
3. Go to **Extensions → Installer**
4. Click **Upload** and select the downloaded `.zip` file
5. Once uploaded, click **Install**
6. Go to **Extensions → Extensions**, set the type filter to **Payment**
7. Find **Frisbii Payments** → click the green **Install** button (if not already installed)
8. Click the **Edit** (pencil) button to open the settings
9. Enter your **API Key** (private key from your Frisbii account), set **Checkout Type**, enable the extension, and click **Save**

## Requirements

- OpenCart >= 4.0.0.0
- PHP >= 8.1
- A Frisbii merchant account — sign up at [frisbii.com](https://frisbii.com)
- cURL enabled in PHP

## Support

You can create issues on our repository. In case of specific problems with your account, please contact [support@frisbii.com](mailto:support@frisbii.com)

## Changelog

### v 1.1.3

- [Feature] - Full OpenCart 4.x (OC4) compatibility. The extension has been completely ported from OC3 to OC4, adopting the new namespace structure (`Opencart\*`), `install.json` manifest, and OC4 model/controller conventions.
- [Feature] - Added 10 individually configurable sub-payment methods: MobilePay, Vipps, Anyday, ViaBill, Swish, Diners Club, Maestro, Discover, JCB, Forbrugsforeningen — each can be enabled and titled separately in the admin.
- [Feature] - Address fallback chain for no-shipping products (e.g. digital goods): the extension now resolves billing country from payment address → shipping address → session → customer's saved address in the database, preventing checkout failures when no shipping step is shown.
- [Fix] - Fixed sub-payment method settings pages (title and enabled status) not retaining their saved values when reopened in the admin.
- [Fix] - Updated `addHistory()` call to match the OC4 order model API (was `addOrderHistory()` in OC3).
- [Fix] - Updated `getTotals()` invocation to use OC4 callable syntax.
- [Fix] - Resolved billing address country being empty for orders without a shipping requirement, which caused a 422 error from the Frisbii API.
- [Docs] - Added `install.json` manifest required by the OC4 Extension Installer.
- [Docs] - Updated README with OC4 installation guide and changelog.
