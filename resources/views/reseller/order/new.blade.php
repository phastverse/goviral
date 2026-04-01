@include('reseller.components.g-header')
@include('reseller.components.nav')

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<main class="nxl-container">
    <div class="nxl-content">
        <div class="page-header">
            <div class="page-header-left d-flex align-items-center">
                <div class="page-header-title">
                    <h5 class="m-b-10">New Order</h5>
                </div>
                <ul class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item">New Order</li>
                </ul>
            </div>
        </div>

        <div class="main-content">
            @if(session('alert'))
                <div aria-live="polite" aria-atomic="true" class="position-fixed top-0 end-0 p-3" style="z-index: 1050;">
                    <div class="toast show bg-white shadow-lg border-0" role="alert">
                        <div class="toast-header">
                            <strong class="me-auto text-uppercase">{{ session('alert')['type'] }}</strong>
                            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                        </div>
                        <div class="toast-body">{{ session('alert')['message'] }}</div>
                    </div>
                </div>
            @endif

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title">Place a New Order</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="/orders">
                                @csrf

                                <!-- Platform Selection -->
                                <div class="mb-4">
                                    <label class="form-label mb-3">Select Platform</label>
                                    <div class="d-flex flex-wrap gap-4" id="platform-container">
                                        @foreach($groupedServices as $platformName => $data)
                                            <button type="button"
                                                    class="btn platform-btn btn-outline-secondary d-flex flex-column align-items-center justify-content-center"
                                                    style="width: 65px; height: 75px; border-radius: 12px; padding: 10px 5px;"
                                                    data-platform="{{ $platformName }}"
                                                    onclick="selectPlatform('{{ $platformName }}')">
                                                <i class="{{ $data['icon'] }} fa-lg" style="margin-bottom: 6px;"></i>
                                                <small class="mt-2" style="font-size: 9px; line-height: 1.1; text-align: center;">{{ $platformName }}</small>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <!-- Service Selection -->
                                <div class="mb-3">
                                    <label class="form-label">Select Service</label>
                                    <select name="service_id" id="service_id" class="form-select" disabled required onchange="updateServiceInfo()">
                                        <option value="">-- Please select a platform above --</option>
                                    </select>
                                    <div class="form-text" id="service_info"></div>
                                </div>

                                <!-- How to order guide — uses panel_name, zero Ogaviral references -->
                                <div class="mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="collapse" data-bs-target="#orderDescription">
                                        <i class="fas fa-info-circle me-1"></i> How to Place Orders
                                    </button>
                                    <div class="collapse mt-3" id="orderDescription">
                                        <div class="card card-body bg-light">
                                            <p class="mb-2">Welcome to {{ $reseller->panel_name }}. Let us help you grow your social media presence.</p>
                                            <p class="mb-2">Browse our services below — if you're unsure, start small to test, then scale up.</p>
                                            <h6 class="fw-bold mb-2">How to get started</h6>
                                            <ol class="mb-3">
                                                <li>Select a platform and service</li>
                                                <li>Paste your public link</li>
                                                <li>Enter a quantity and place your order</li>
                                            </ol>
                                            @if($reseller->support_email)
                                                <p class="mb-0">Need help? Email us at <a href="mailto:{{ $reseller->support_email }}">{{ $reseller->support_email }}</a></p>
                                            @endif
                                            <div class="alert alert-warning mt-3 mb-0">
                                                <p class="mb-1">⚠️ Keep the profile/post public while your order runs</p>
                                                <p class="mb-1">✂️ Do not edit or delete the content during delivery</p>
                                                <p class="mb-0">➕ Avoid placing multiple orders on the same link at once</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <input type="hidden" name="service_name" id="service_name">

                                <div class="mb-3">
                                    <label class="form-label">Link</label>
                                    <input type="url" name="link" class="form-control" placeholder="https://..." required>
                                    <div class="form-text">Make sure your account or post is public.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" id="quantity" class="form-control"
                                           placeholder="Select a service first" disabled oninput="calculateTotal()">
                                    <div class="form-text" id="quantity_info">Select a service to see limits.</div>
                                </div>

                                <div class="alert alert-info d-flex justify-content-between align-items-center">
                                    <span>Total Charge:</span>
                                    <h4 class="m-0 fw-bold text-primary">₦<span id="total_charge">0.00</span></h4>
                                </div>

                                <input type="hidden" name="charge" id="charge" value="0">

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">Place Order</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

@include('reseller.components.g-footer')

<script>
// ── The panel_name replaces both "OGAVIRAL" and "BOOSTER" in service names ──
const PANEL_NAME = @json($reseller->panel_name);
const groupedData = @json($groupedServices);

function cleanServiceName(name) {
    // Strip the provider brand — replace with panel name so nothing leaks
    let cleaned = name
        .replace(/OGAVIRAL/gi, PANEL_NAME)
        .replace(/\bBOOSTER\b/gi, PANEL_NAME);

    // Strip leading/trailing separators
    cleaned = cleaned.replace(/^[\s\-–—|•]+|[\s\-–—|•]+$/g, '');
    cleaned = cleaned.replace(/\s+/g, ' ');
    return cleaned.trim();
}

function selectPlatform(platformName) {
    document.querySelectorAll('.platform-btn').forEach(btn => {
        btn.classList.toggle('btn-primary', btn.dataset.platform === platformName);
        btn.classList.toggle('btn-outline-secondary', btn.dataset.platform !== platformName);
    });

    const selectBox   = document.getElementById('service_id');
    const quantityBox = document.getElementById('quantity');

    selectBox.innerHTML = '<option value="">-- Choose a Service --</option>';

    groupedData[platformName].services.forEach(service => {
        const option = document.createElement('option');
        option.value = service.service;

        const displayName = cleanServiceName(service.name);
        const price       = service.marked_up_price || service.rate;

        option.text = `${displayName} — ₦${parseFloat(price).toFixed(2)} / 1k`;
        option.setAttribute('data-name', service.name);
        option.setAttribute('data-rate', price);
        option.setAttribute('data-min',  service.min);
        option.setAttribute('data-max',  service.max);
        option.setAttribute('data-category', service.category ?? '');

        selectBox.appendChild(option);
    });

    selectBox.disabled    = false;
    quantityBox.disabled  = true;
    document.getElementById('service_info').innerText = '';
    updateTotalDisplay(0);
}

function updateServiceInfo() {
    const select   = document.getElementById('service_id');
    const opt      = select.options[select.selectedIndex];
    const qty      = document.getElementById('quantity');

    if (!opt.value) {
        qty.disabled = true;
        document.getElementById('service_info').innerHTML = '';
        document.getElementById('quantity_info').innerHTML = 'Select a service to see limits.';
        updateTotalDisplay(0);
        return;
    }

    qty.disabled = false;
    document.getElementById('service_name').value = opt.getAttribute('data-name');

    const minQty = opt.getAttribute('data-min');
    const maxQty = opt.getAttribute('data-max');

    // Clean the category name too — no provider brand leaks
    const rawCategory   = opt.getAttribute('data-category') ?? '';
    const cleanCategory = cleanServiceName(rawCategory);

    document.getElementById('service_info').innerHTML =
        cleanCategory ? `Category: ${cleanCategory}<br>Min: ${minQty}, Max: ${maxQty}` : `Min: ${minQty}, Max: ${maxQty}`;

    qty.min         = minQty;
    qty.max         = maxQty;
    qty.placeholder = `Min: ${minQty}, Max: ${maxQty}`;
    qty.value       = '';

    document.getElementById('quantity_info').innerHTML = `Enter a quantity between ${minQty} and ${maxQty}.`;
    calculateTotal();
}

function calculateTotal() {
    const select   = document.getElementById('service_id');
    const opt      = select.options[select.selectedIndex];

    if (!opt.value) { updateTotalDisplay(0); return; }

    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const rate     = parseFloat(opt.getAttribute('data-rate')) || 0;
    const minQty   = parseFloat(opt.getAttribute('data-min')) || 0;
    const maxQty   = parseFloat(opt.getAttribute('data-max')) || 0;

    if (quantity > 0 && (quantity < minQty || quantity > maxQty)) {
        document.getElementById('quantity_info').innerHTML =
            `<span class="text-danger">Quantity must be between ${minQty} and ${maxQty}!</span>`;
    } else if (quantity > 0) {
        document.getElementById('quantity_info').innerHTML =
            `Enter a quantity between ${minQty} and ${maxQty}.`;
    }

    updateTotalDisplay((quantity / 1000) * rate);
}

function updateTotalDisplay(total) {
    const formatted = total.toFixed(2);
    document.getElementById('total_charge').innerText = formatted;
    document.getElementById('charge').value           = formatted;
}
</script>