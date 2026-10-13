# Pool Founder Contact Sync

**One address book. Customers and leads together.**

A community-driven, self-hosted PHP project to expose **Pool Founder customers and leads** as a single **read-only CardDAV address book** for compatible contacts apps.

> **Status: Planning / pre-release.** No working CardDAV server has been implemented yet.

## Why We Built This

Hate getting a phone call and not knowing which customer is calling? We do too.

Pool Founder already keeps track of your customers, leads, and service information. You can always open the app to find what you need. But why should you have to?

That's why we built Pool Founder Contact Sync.

It brings your customers and leads into your phone's native contacts, so you know who's calling before you answer. Customer names, service addresses, and other useful details are right there when you need them.

No replacing Pool Founder. No extra app to manage. Just making the phone you already carry a little smarter.

Built by Pool Pro Florida, shared with the pool service community.

**Community Over Competition.**

## Planned v1

- One address book named **Pool Founder Contacts**
- Customers and leads combined into one contact list
- Names, phone numbers, emails, and available postal addresses mapped to vCards
- Pool Founder remains the source of truth; no editing through CardDAV
- Stable identifiers and matching rules to avoid duplicates when leads become customers
- Plain PHP hosting, without WordPress, Cal.com, or AI
- Authenticated CardDAV access over HTTPS

## Before implementation

Verify Pool Founder API endpoints, permissions, available contact fields, authentication, and rate limits. Implement and test CardDAV discovery, vCards, and synchronization with real clients before claiming compatibility.

**This repository is not installable software yet.**

## Security

Never commit API credentials, CardDAV passwords, customer data, or logs containing personal information. Use local untracked configuration or environment variables.

## Community Over Competition

Created by **[Pool Pro Florida](https://poolproflorida.com)** for the pool service community. Practical tools that help pool professionals spend less time on administration and more time serving customers.

Independent community project, not an official Pool Founder product.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE).
