# Public API fields needed for contact sync

This project intentionally uses only the documented, authenticated Pool Founder server-to-server HTTP API. No private application integrations or customer information belong in this public repository.

## Observed customer-list structure

An authenticated test of `GET /api/v1/customers?limit=3` returned the following field **names** on the sampled customer records:

- `id`, `name`, `firstName`, `lastName`, `company`, `email`, `phone`, `parentCustomerId`
- `serviceDays` (array)
- `schedule` (array of `day`, `frequency`, `jobId`, `jobName`, `route`, `technician`)

The **three sampled records did not contain any service address fields**. These observations describe the sampled response, not a guarantee about all accounts or future API versions. The server may have more detailed records internally, but a standalone client cannot read fields that the public API does not expose.

## Customer address support request

For read-only integrations to access service addresses safely, please expose one of:

1. A `serviceLocations` array on `GET /api/v1/customers` containing only active service-location identifiers and structured addresses; or
2. A documented, authenticated `GET /api/v1/customers/{id}/service-locations` endpoint, plus a reliable way to enumerate active customer IDs.

Example response field shape (fictional data):

```json
{
  "id": "example-customer-id",
  "serviceLocations": [
    {
      "id": "example-location-id",
      "address": {
        "street": "123 Example Lane",
        "city": "Example City",
        "state": "FL",
        "zipCode": "00000",
        "country": "US"
      }
    }
  ]
}
```

Only fields necessary to identify and reach a customer should be exposed to this integration. **Never include gate codes, access notes, internal records, billing details, or equipment secrets.** Support more than one service address per customer. Read-only API keys should be scoped by company and only return that company's active records.

## Lead support request

A separately documented read-only endpoint for active leads, including stable ID, name, telephone, email, optional service address, and pagination, would allow the community tool to include leads without scraping internal app endpoints.

## Current behavior

The standalone contact sync keeps name, phone, email, and service-day data from the customer API. It includes addresses only when a supported public response actually supplies them. An absent field is never invented, and an unchanged address cannot be reconstructed reliably from a route or job name.
