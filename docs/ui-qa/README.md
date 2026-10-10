# Console UI checks

Screenshots of the console at desktop (1440), tablet (820) and mobile (390) widths, taken against a running OptiNexus with the
seeded super administrator. Per page they were also checked for horizontal overflow, console errors and failing requests (none).

| Screen | What it shows |
|---|---|
| `login-*` | Logo above the title, no breadcrumb or Back on the sign-in page |
| `dashboard-*`, `roles-list-*`, `applications-list-*`, `event-catalog-*`, `simulate-price-*` | Logo container, expandable sidebar, breadcrumb (Dashboard › group › page), Back button |
| `role-detail-*` | Detail page: breadcrumb ends in the record name, Back returns to the list, long permission keys wrap |
| `menu-*` | Sidebar groups collapsed and expanded; on mobile the off-canvas menu |

Behaviour: `frontend/src/navigation/` holds the route table behind the breadcrumbs (`npm test` keeps it in step with the routes in
`App.tsx`); the sidebar remembers collapsed groups per browser; the group of the current page always opens on arrival; Back goes to
the previous page of the session and, on a freshly opened page, to its parent.
