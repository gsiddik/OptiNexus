<?php

// Route definitions are versioned under routes/api_v1.php and included below.
// Keeping this file thin makes future API versions (v2, ...) trivial to add
// alongside v1 without disturbing existing integrations.

require __DIR__.'/api_v1.php';

// OpenID Connect back-channel endpoints (stateless, called server-to-server).
require __DIR__.'/api_oidc.php';

// API Gateway for cross-platform data exchange.
require __DIR__.'/api_gateway.php';
