# CtrlDeals WordPress Plugin

Private agent/admin operations plugin plus public deal/search shortcodes for ctrldeals.com.

## Current Architecture

- One central Add to Cart URL generator:
  `https://www.amazon.com/gp/aws/cart/add.html?AssociateTag=[tracking-id]&ASIN.1=[asin]&Quantity.1=1`
- Public visitors use the site-wide tracking ID.
- Logged-in agents use their assigned tracking ID.
- Product search runs only after the completed phrase is submitted with the Search button or Enter, helping protect Amazon API limits.
- Every private product search and every generated URL is logged.
- Public pages include Amazon Associate disclosure text.
- Amazon images are rendered from remote URLs and are not downloaded into WordPress media.
- Admin and public plugin UI uses the live CtrlDeals theme palette: orange accent, cream surfaces, dark text, and the site's DM Sans / Plus Jakarta Sans font pairing.

## Admin Pages

- Dashboard: all-agent monthly stats and 10-sale progress.
- Product Search: submit-only product search for admins to test listings/API search.
- Agents: create/deactivate agent accounts and enforce unique tracking IDs.
- Listings: add public deals by ASIN, title, Amazon image URL, category, and prices.
- Sales: view sales once Amazon report sync is connected.
- Sync: admin-only Amazon Associates CSV/TSV report upload, sync status/history, and manual API sync placeholder.
- Settings: public site tracking ID, marketplace URL, and server-side Amazon API/report credentials.

## Agent Pages

- Dashboard: own monthly stats only.
- Product Search: searches current listings and logs the query.
- Listed Deals: products already published on ctrldeals.com.
- My Activity: searches, generated URLs, and matched sales for the current agent.

## Public Shortcodes

```text
[ctrldeals_public_search]
[ctrldeals_deals]
[ctrldeals_affiliate_disclosure]
[ctrldeals_agent_login]
```

The plugin also creates `/affiliate-disclosure` and `/agent-login` on activation. The login page has no self-registration link and redirects agents/admins into the private CtrlDeals area.

The plugin prints a global footer disclosure:

```text
As an Amazon Associate, ctrldeals.com earns from qualifying purchases.
```

## Amazon API / Report Sync Status

The plugin has server-side settings and database structure ready for Amazon Creators API product search. Real API calls require approved credentials and the exact endpoint/signing rules from Amazon. Until those are connected, search uses admin-managed listings so the workflow can be tested safely.

Amazon Associates sales status is updated through the admin-only Sync tab. Upload the latest Associates CSV, TSV, or TXT report after Amazon refreshes reporting. The importer normalizes common report columns such as Tracking ID, ASIN, Product Name, Items Shipped, Revenue, Earnings/Commission, Date, and Status. Rows are matched to agents by tracking ID first. If tracking ID is missing, ASIN fallback attribution is used only when exactly one agent has generated URLs for that ASIN.

## Tables Added

- `wp_ctrldeals_agents`
- `wp_ctrldeals_searches`
- `wp_ctrldeals_clicks`
- `wp_ctrldeals_sales`
- `wp_ctrldeals_listings`
- `wp_ctrldeals_sync_log`

Legacy `cdac_clients` and `cdac_purchases` tables may still exist from earlier builds, but the updated workflow no longer collects client personal information.
