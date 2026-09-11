# BRVTAL Content Core 01

This migration establishes the backend foundation for the next CMS content layer without storing purchaser or attendee data.

## Events

- Expanded lifecycle: draft, published, upcoming, tickets available, last tickets, sold out, cancelled, finished, archived.
- Publication/cancellation/finish timestamps.
- Featured flag.
- Archive year.
- Ticket instructions and optional QR asset.

## Tickets

`event_ticket_types` supports multiple ticket stages per event, external purchase/payment links, payment instructions, QR assets, availability windows and sold-out state.

No buyer or attendee information is stored.

## Collective

Artists remain the canonical artist entity. `collective_status` separates BRVTAL collective membership from event participation, while `artist_collective_history` preserves membership history.

## Deployment

Run `database/migration_content_core_01.sql` against the production BRVTAL database before using the new fields. The migration is additive and preserves existing content.