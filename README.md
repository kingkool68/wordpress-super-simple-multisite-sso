# Super Simple WordPress Multisite SSO

## Problem

Logging into a [WordPress Multisite](https://developer.wordpress.org/advanced-administration/multisite/) network with different domains is a clunky experience. You jump from one site to another, and you have to log in again and again every single time. It makes managing access a nightmare. And most importantly, it’s a confusing experience for your users. It shouldn't be this way.

## What if you could just log in once?

You designate one site as your central hub. When users go to another site, they seamlessly bounce to the hub, authenticate, and land right back where they started. It’s completely transparent. They authenticate once, and then have access to everything. When they log out, they log out of the entire network instantly. Simple.

Behind the scenes, this utilizes OpenID Connect to securely bridge the gap, using the [OpenID Connect Generic](https://github.com/oidc-wp/openid-connect-generic) plugin for the client sites and the [OpenID Connect Server](https://github.com/Automattic/wp-openid-connect-server) plugin for the authentication hub. This plugin is the glue that ties it all together.

## Setup

1. **Install the Auth Server:** Add the [OpenID Connect Server](https://github.com/Automattic/wp-openid-connect-server) plugin (also available on [WordPress.org](https://wordpress.org/plugins/openid-connect-server/)) to your `wp-content/plugins/` directory.
   * *Note:* This specific fork, [Add KID](https://github.com/joshbetz/wp-openid-connect-server/tree/add/kid), introduces a highly recommended security enhancement (see [PR #132](https://github.com/Automattic/wp-openid-connect-server/pull/132)) and is the preferred version for this setup.
1. **Install the Client Plugin:** Add the [OpenID Connect Generic](https://github.com/oidc-wp/openid-connect-generic) plugin to your `wp-content/plugins/` directory.
1. **Generate RSA Keys:** You need to [define RSA keys](https://github.com/Automattic/wp-openid-connect-server#define-the-rsa-keys) for secure token generation. Run the following in your terminal:
   ```bash
   openssl genrsa -out oidc.key 4096
   openssl rsa -in oidc.key -pubout -out public.key
   ```
1. **Add Keys to `wp-config.php`:** Copy the contents of those generated keys into your `wp-config.php` file:
   ```php
   define( 'OIDC_PUBLIC_KEY', <<<OIDC_PUBLIC_KEY
   -----BEGIN PUBLIC KEY-----
   ...
   -----END PUBLIC KEY-----
   OIDC_PUBLIC_KEY
   );

   define( 'OIDC_PRIVATE_KEY', <<<OIDC_PRIVATE_KEY
   -----BEGIN PRIVATE KEY-----
   ...
   -----END PRIVATE KEY-----
   OIDC_PRIVATE_KEY
   );
   ```
1. **Pick a hub site:** The site with an ID of `1` will be the site used to authenticate (aka the hub). If you want to change that, add the following to your `wp-config.php`:
	```PHP
	define( 'SS_MS_SSO_HUB_SITE_ID', 2 );
	```
1. **Install the SSO mu-plugin:** Clone this repository directly into your Must-Use plugins folder:
   ```bash
   cd wp-content/mu-plugins
   git clone git@github.com:kingkool68/wordpress-super-simple-multisite-sso.git
   ```
1. **Configure:** Copy the `super-simple-multisite-sso.php.example` file into the root of your `wp-content/mu-plugins/` directory, rename it to `super-simple-multisite-sso.php`, and tweak the configuration options inside.

## How does it work?
This plugin acts as the glue between the Identity Provider (your hub site) and the Relying Parties (your client sites).

1. **The Interception:** When an unauthenticated user attempts to log in on a client site, the plugin intercepts the standard WordPress login flow and redirects the user to the designated hub site to authenticate.
2. **The Handshake:** The user authenticates at the hub. Upon successful login, the OpenID Connect Server securely generates an authentication token signed by your private RSA key.
3. **The Return Journey:** The user is redirected back to the client site with their token. The OpenID Connect Generic plugin validates this token against the public RSA key, confirms the user's identity, and establishes a local authenticated session.
4. **Single Log Out:** When a user clicks "Log Out" anywhere on the network, the plugin orchestrates a global logout. It destroys the local session and pings the hub to terminate the master OpenID Connect session, ensuring the user is logged out across all mapped domains simultaneously.
