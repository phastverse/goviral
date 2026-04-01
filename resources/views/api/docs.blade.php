<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booster API Documentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f1117; color: #e2e8f0; line-height: 1.7; }
        
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1a1d2e; border-right: 1px solid #2d3748; overflow-y: auto; padding: 24px 0; z-index: 100; }
        .sidebar-logo { padding: 0 24px 24px; border-bottom: 1px solid #2d3748; margin-bottom: 16px; }
        .sidebar-logo h2 { color: #fff; font-size: 18px; font-weight: 700; }
        .sidebar-logo span { color: #6366f1; }
        .sidebar-logo small { color: #718096; font-size: 11px; display: block; margin-top: 2px; }
        .nav-section { padding: 8px 24px 4px; color: #4a5568; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .nav-link { display: block; padding: 8px 24px; color: #a0aec0; text-decoration: none; font-size: 13px; transition: all 0.15s; }
        .nav-link:hover, .nav-link.active { color: #fff; background: #2d3748; border-right: 2px solid #6366f1; }
        
        .main { margin-left: 260px; padding: 48px; max-width: 900px; }
        
        h1 { font-size: 32px; font-weight: 800; color: #fff; margin-bottom: 12px; }
        h2 { font-size: 22px; font-weight: 700; color: #fff; margin: 48px 0 16px; padding-bottom: 8px; border-bottom: 1px solid #2d3748; }
        h3 { font-size: 16px; font-weight: 600; color: #e2e8f0; margin: 24px 0 10px; }
        p { color: #a0aec0; margin-bottom: 16px; font-size: 14px; }
        
        .badge { display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-right: 8px; }
        .badge-post { background: #1a3a2a; color: #48bb78; }
        .badge-get  { background: #1a2a4a; color: #63b3ed; }
        
        .endpoint-card { background: #1a1d2e; border: 1px solid #2d3748; border-radius: 10px; padding: 24px; margin-bottom: 24px; }
        .endpoint-url  { background: #0f1117; border: 1px solid #2d3748; border-radius: 6px; padding: 12px 16px; font-family: monospace; font-size: 13px; color: #6366f1; margin-bottom: 16px; word-break: break-all; }
        
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px; }
        th { background: #2d3748; color: #e2e8f0; padding: 10px 14px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 14px; border-bottom: 1px solid #2d3748; color: #a0aec0; vertical-align: top; }
        td:first-child { color: #f6ad55; font-family: monospace; font-size: 12px; }
        .required { color: #fc8181; font-size: 10px; font-weight: 700; background: #2d1515; padding: 1px 5px; border-radius: 3px; }
        .optional  { color: #68d391; font-size: 10px; font-weight: 700; background: #1a2d1a; padding: 1px 5px; border-radius: 3px; }
        
        pre { margin: 0; }
        code.hljs { border-radius: 8px; padding: 16px; font-size: 12px; }
        
        .alert-info { background: #1a2740; border: 1px solid #2b4c7e; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #90cdf4; font-size: 13px; }
        .alert-warn { background: #2d2008; border: 1px solid #6b4c00; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #fbd38d; font-size: 13px; }
        
        .base-url-box { background: #1a1d2e; border: 1px solid #6366f1; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .base-url-box span { color: #6366f1; font-weight: 700; font-size: 12px; text-transform: uppercase; }
        .base-url-box code { color: #e2e8f0; font-size: 14px; font-family: monospace; }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 24px; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo">
        <h2>Booster <span>API</span></h2>
        <small>v2.0 Documentation</small>
        <a href="/api/test" class="btn btn-solid" style="display:inline-flex;align-items:center;gap:0.6rem; color: white;">
           TEST API&nbsp;<i class="fas fa-key"></i>
        </a>
    </div>
    <div class="nav-section">Getting Started</div>
    <a href="#introduction" class="nav-link">Introduction</a>
    <a href="#authentication" class="nav-link">Authentication</a>
    <a href="#errors" class="nav-link">Error Handling</a>
    <div class="nav-section">Endpoints</div>
    <a href="#services" class="nav-link">Services List</a>
    <a href="#add-order" class="nav-link">Add Order</a>
    <a href="#order-status" class="nav-link">Order Status</a>
    <a href="#multiple-status" class="nav-link">Multiple Order Status</a>
    <a href="#refill" class="nav-link">Create Refill</a>
    <a href="#refill-status" class="nav-link">Refill Status</a>
    <a href="#cancel" class="nav-link">Cancel Orders</a>
    <a href="#balance" class="nav-link">Get Balance</a>
    <div class="nav-section">Account</div>
    <a href="{{ route('api.index') }}" class="nav-link">← My API Keys</a>
</aside>

<main class="main">

    <h1>Booster API</h1>
    <p>Integrate Booster's SMM services directly into your application, bot, or reseller panel with our simple REST API.</p>

    <div class="base-url-box">
        <span>Base URL</span>
        <code>{{ url('/api/v2') }}</code>
    </div>

    <div class="alert-info">
        <strong>📌 All requests are HTTP POST</strong> — Send parameters as form data (<code>application/x-www-form-urlencoded</code>) or JSON. Always include <code>action</code> to specify what you want to do.
    </div>

    <!-- AUTHENTICATION -->
    <h2 id="authentication">Authentication</h2>
    <p>Every request (except <code>services</code>) requires your API key passed as the <code>key</code> parameter.</p>

    <div class="endpoint-card">
        <h3>Getting Your API Key</h3>
        <p>Generate your API key from your <a href="{{ route('api.index') }}" style="color: #6366f1;">account dashboard</a>. Keep it secret — treat it like a password.</p>
        <div class="alert-warn">⚠️ Never share your API key publicly or commit it to version control.</div>
    </div>

    <!-- ERROR HANDLING -->
    <h2 id="errors">Error Handling</h2>
    <p>All errors return a JSON object with an <code>error</code> key describing what went wrong.</p>
    <pre><code class="language-json">{
    "error": "Insufficient balance"
}</code></pre>

    <table>
        <thead><tr><th>HTTP Code</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td>200</td><td>Success</td></tr>
            <tr><td>400</td><td>Invalid action or bad request</td></tr>
            <tr><td>401</td><td>Missing or invalid API key</td></tr>
            <tr><td>402</td><td>Insufficient wallet balance</td></tr>
            <tr><td>404</td><td>Resource not found</td></tr>
            <tr><td>422</td><td>Validation error (e.g. quantity out of range)</td></tr>
            <tr><td>500</td><td>Server / order placement error</td></tr>
        </tbody>
    </table>

    <!-- SERVICES -->
    <h2 id="services">Services List</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = services</strong>
        <p style="margin-top: 12px;">Returns all available services. No API key required.</p>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>services</code></td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">[
    {
        "service": 1,
        "name": "Instagram Followers - Real",
        "type": "Default",
        "category": "Instagram",
        "rate": 150.00,
        "min": 100,
        "max": 10000
    }
]</code></pre>
    </div>

    <!-- ADD ORDER -->
    <h2 id="add-order">Add Order</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = add</strong>
        <p style="margin-top: 12px;">Place a new order. Balance is deducted immediately. Automatically refunded if the order fails.</p>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>add</code></td></tr>
                <tr><td>service</td><td>integer</td><td><span class="required">required</span></td><td>Service ID from services list</td></tr>
                <tr><td>link</td><td>string</td><td><span class="required">required</span></td><td>Full URL to the social media post or profile</td></tr>
                <tr><td>quantity</td><td>integer</td><td><span class="required">required</span></td><td>Number of units. Must be within service min/max</td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">{
    "order": "a1b2c3d4-e5f6-..."
}</code></pre>
    </div>

    <!-- ORDER STATUS -->
    <h2 id="order-status">Order Status</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = status</strong>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>status</code></td></tr>
                <tr><td>order</td><td>string</td><td><span class="required">required</span></td><td>Order ID returned from add order</td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">{
    "order": "a1b2c3d4-...",
    "status": "processing",
    "charge": "150.00",
    "start_count": 1200,
    "remains": 800
}</code></pre>

        <h3>Order Statuses</h3>
        <table>
            <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td>pending</td><td>Order received, not yet started</td></tr>
                <tr><td>processing</td><td>Order is actively being delivered</td></tr>
                <tr><td>completed</td><td>Order fully delivered</td></tr>
                <tr><td>partial</td><td>Order partially delivered</td></tr>
                <tr><td>cancelled</td><td>Order cancelled (refund issued)</td></tr>
            </tbody>
        </table>
    </div>

    <!-- MULTIPLE STATUS -->
    <h2 id="multiple-status">Multiple Order Status</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = status</strong>
        <p style="margin-top: 12px;">Check status of multiple orders in one request.</p>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>status</code></td></tr>
                <tr><td>orders</td><td>string</td><td><span class="required">required</span></td><td>Comma-separated order IDs e.g. <code>id1,id2,id3</code></td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">{
    "a1b2c3d4-...": { "status": "completed", "charge": "150.00", "remains": 0 },
    "b2c3d4e5-...": { "status": "processing", "charge": "200.00", "remains": 500 }
}</code></pre>
    </div>

    <!-- REFILL -->
    <h2 id="refill">Create Refill</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = refill</strong>
        <p style="margin-top: 12px;">Request a refill for a completed order that dropped below the delivered amount.</p>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>refill</code></td></tr>
                <tr><td>order</td><td>string</td><td><span class="required">required</span></td><td>Order ID to refill</td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">{
    "refill": "123456"
}</code></pre>
    </div>

    <!-- REFILL STATUS -->
    <h2 id="refill-status">Refill Status</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = refill_status</strong>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>refill_status</code></td></tr>
                <tr><td>refill</td><td>string</td><td><span class="required">required</span></td><td>Refill ID from create refill response</td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">{
    "refill": "123456",
    "status": "Completed"
}</code></pre>
    </div>

    <!-- CANCEL -->
    <h2 id="cancel">Cancel Orders</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = cancel</strong>
        <p style="margin-top: 12px;">Cancel one or more pending/processing orders.</p>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>cancel</code></td></tr>
                <tr><td>orders</td><td>string</td><td><span class="required">required</span></td><td>Comma-separated order IDs</td></tr>
            </tbody>
        </table>
    </div>

    <!-- BALANCE -->
    <h2 id="balance">Get Balance</h2>
    <div class="endpoint-card">
        <span class="badge badge-post">POST</span><strong>action = balance</strong>
        <div class="endpoint-url">{{ url('/api/v2') }}</div>

        <h3>Parameters</h3>
        <table>
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>key</td><td>string</td><td><span class="required">required</span></td><td>Your API key</td></tr>
                <tr><td>action</td><td>string</td><td><span class="required">required</span></td><td>Must be <code>balance</code></td></tr>
            </tbody>
        </table>

        <h3>Response</h3>
        <pre><code class="language-json">{
    "balance": "5000.00",
    "currency": "NGN"
}</code></pre>
    </div>

    <p style="margin-top: 48px; padding-top: 24px; border-top: 1px solid #2d3748; color: #4a5568; font-size: 12px; text-align: center;">
        Booster API v2.0 · <a href="{{ route('support.index') }}" style="color: #6366f1;">Contact Support</a>
    </p>

</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>hljs.highlightAll();</script>
</body>
</html>