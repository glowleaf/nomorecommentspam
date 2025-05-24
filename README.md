# No More Comment Spam

A WordPress plugin that prevents comment spam by requiring commenters to authenticate via Nostr or Lightning Network. Instead of using traditional anti-spam methods like CAPTCHAs or moderation queues, this plugin leverages decentralized authentication methods that are both user-friendly and highly effective against automated spam.

## Features

- **Lightning Network Authentication**: Requires a small Lightning payment to post a comment (payment is sent to the site owner)
- **Nostr Authentication**: Allows users to authenticate using their Nostr key (either via browser extension or Nostr Connect)
- **Automatic Price Adjustment**: Automatically increases the required payment amount if spam attacks are detected
- **Self-Funding Anti-Spam**: Site owners earn from spam attempts, making spam attacks counterproductive
- **Minimal User Friction**: Regular users only need to authenticate once to post multiple comments

## Dependencies

- WordPress 5.0 or higher
- PHP 7.4 or higher  
- LNLogin functionality (bundled within this plugin)
- Lightning Network wallet for testing payments
- Nostr browser extension (for Nostr authentication)

## Installation

1. Download this plugin and upload it to your WordPress plugins directory
2. Activate the plugin through the WordPress admin interface  
3. Go to Settings > No More Comment Spam to configure authentication methods

**Note**: The LNLogin functionality is bundled within this plugin - no separate installation required!

## Configuration

1. **Enable Authentication Methods**:
   - Lightning Login: Requires small payments to post comments
   - Nostr Browser Extension: For users with Nostr extensions (like nos2x or Alby)
   - Nostr Connect: For users with Nostr-compatible wallets

2. **Spam Protection Settings**:
   - Base price for Lightning payments
   - Automatic price adjustment during detected spam attacks
   - Duration of increased prices after spam detection

## How It Works

When a user tries to post a comment, they must first authenticate using either:

1. **Lightning Network**: Make a small payment that goes to the site owner
2. **Nostr**: Sign a challenge with their Nostr key

The plugin includes automatic spam protection:
- Monitors comment frequency
- Automatically increases prices during spam attacks
- Returns to normal pricing after the attack subsides
- Site owners earn from spam attempts

## Credits

- LNLogin functionality by [Supertestnet](https://github.com/supertestnet/lnlogin) - Bundled Lightning Network authentication component
- Nostr protocol implementation using [nostr-tools](https://github.com/nbd-wtf/nostr-tools)
- WordPress plugin development following [WordPress coding standards](https://developer.wordpress.org/coding-standards/)

## License

GPL v2 or later 