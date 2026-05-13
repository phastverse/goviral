@include('components.g-header')
@include('components.nav')

<!-- Make sure you have FontAwesome linked in your layout or head -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<main class="nxl-container">
    <div class="nxl-content">
        <div class="page-header">
            <div class="page-header-left d-flex align-items-center">
                <div class="page-header-title">
                    <h5 class="m-b-10">New Order</h5>
                </div>
                <ul class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
                    <li class="breadcrumb-item">Order</li>
                    <li class="breadcrumb-item">New</li>
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
                            <form method="POST" action="{{ route('order.store') }}">
                                @csrf
                                                                
                                <!-- 1. Platform Selection (Icons) -->
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
                                                <small class="mt-2" style="font-size: 9px; line-height: 1.1; text-align: center; word-wrap: break-word;">{{ $platformName }}</small>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <!-- 2. Service Selection (Dropdown) -->
                                <div class="mb-3">
                                    <label class="form-label">Select Service</label>
                                    <select name="service_id" id="service_id" class="form-select" disabled required onchange="updateServiceInfo()">
                                        <option value="">-- Please select a platform above --</option>
                                    </select>
                                    <div class="form-text" id="service_info"></div>
                                </div>

                                <!-- Description Button -->
                                <div class="mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#orderDescription">
                                        <i class="fas fa-info-circle me-1"></i> How to Place Orders
                                    </button>
                                    
                                    <div class="collapse mt-3" id="orderDescription">
                                        <div class="card card-body bg-light">
                                            <p class="mb-2">Welcome to t.me/Virextrahq, let help you grow your social media account's.</p>
                                            <p class="mb-2">This section is packed with discounted services just for you. Same powerful results, but way easier on your wallet. 😉</p>
                                            <p class="mb-3">If you're unsure about our services, you can place an order with a very low price to test first before going bigger. It works on any social.</p>
                                            <p class="mb-3">🎵 TikTok • 📸 Instagram • 📱 Telegram • 🎮 Twitch • 📘 Facebook • 🎧 Spotify • 💬 WhatsApp • ▶️ YouTube • ✖️ Twitter • 👾 Discord • 👻 Snapchat • 💼 LinkedIn • 📌 Pinterest</p>
                                            
                                            <h6 class="fw-bold mb-2">How to get started immediately</h6>
                                            <ol class="mb-3">
                                                <li>📱 Select a service</li>
                                                <li>🔗 Paste a valid link</li>
                                                <li>🚀 Place the order - delivery starts soon and builds naturally</li>
                                            </ol>
                                            
                                            <p class="mb-3">💬 Got questions before ordering? <a href="{{ route('support.index') }}" class="fw-bold">Chat our support</a> or reach us on WhatsApp at <a href="https://wa.me/2348152880128" class="fw-bold" target="_blank">+234 815 288 0128</a> - we reply fast</p>
                                            
                                            <div class="alert alert-warning mb-0">
                                                <p class="mb-1">⚠️ Always Keep the post/profile public while delivery runs</p>
                                                <p class="mb-1">✂️ Don't edit or delete the content during-delivery</p>
                                                <p class="mb-0">➕ Avoid stacking multiple orders on the same link</p>
                                            </div>
                                            
                                            <p class="mt-3 mb-0"><strong>At t.me/Virextrahq, we're all about helping you grow.</strong> We encourage you to read the service description to understand each service before ordering so you always get maximum value.</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden Service Name -->
                                <input type="hidden" name="service_name" id="service_name">

                                <!-- Link -->
                                <div class="mb-3">
                                    <label class="form-label">Link</label>
                                    <input type="url" name="link" class="form-control" placeholder="https://..." required>
                                    <div class="form-text">Make sure your account is public.</div>
                                </div>

                                <!-- Quantity -->
                                <div class="mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" id="quantity" class="form-control" placeholder="Min: 10" disabled oninput="calculateTotal()">
                                    <div class="form-text" id="quantity_info">Select a service to see limits.</div>
                                </div>

                                <!-- Total Price Display -->
                                <div class="alert alert-info d-flex justify-content-between align-items-center">
                                    <span>Total Charge:</span>
                                    <h4 class="m-0 fw-bold text-primary">₦<span id="total_charge">0.00</span></h4>
                                </div>

                                <input type="hidden" name="charge" id="charge" value="0">

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" onclick="ttq.track('PlaceAnOrder')">Place Order</button>
                                </div>

                                @error('service_id')
                                    <div class="text-danger mt-2">{{ $message }}</div>
                                @enderror

                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

@include('components.g-footer')

<!-- Script -->
<script>
    // Pass the grouped PHP data to JavaScript
    const groupedData = {!! json_encode($groupedServices) !!};

    /**
     * Remove "OGAVIRAL" from service name
     */
    function cleanServiceName(name) {
        // Remove 'OGAVIRAL' (case insensitive)
        let cleaned = name.replace(/OGAVIRAL/gi, 't.me/Virextrahq');
        
        // Remove leading/trailing special characters, spaces, dashes, pipes, bullets
        cleaned = cleaned.replace(/^[\s\-–—|•]+|[\s\-–—|•]+$/g, '');
        
        // Clean up multiple spaces to single space
        cleaned = cleaned.replace(/\s+/g, ' ');
        
        return cleaned.trim();
    }

    function selectPlatform(platformName) {
        // 1. Visual Feedback on Buttons
        document.querySelectorAll('.platform-btn').forEach(btn => {
            if(btn.dataset.platform === platformName) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-primary'); // Highlight selected
            } else {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline-secondary');
            }
        });

        // 2. Populate the Dropdown
        const selectBox = document.getElementById('service_id');
        const quantityBox = document.getElementById('quantity');
        
        // Clear existing options
        selectBox.innerHTML = '<option value="">-- Choose a Service --</option>';
        
        // Get services for selected platform
        const services = groupedData[platformName].services;

        services.forEach(service => {
            const option = document.createElement('option');
            option.value = service.service;
            
            // Clean the service name (remove t.me/Virextrahq)
            const displayName = cleanServiceName(service.name);
            
            // Use marked_up_price if available, otherwise fall back to rate
            const price = service.marked_up_price || service.rate;
            
            option.text = `${displayName} - ₦${parseFloat(price).toFixed(2)} / 1k`;
            
            // Attach data attributes for logic
            option.setAttribute('data-name', service.name);
            option.setAttribute('data-rate', price); // Use marked-up price here
            option.setAttribute('data-original-rate', service.rate || service.original_price); // Keep original for reference
            option.setAttribute('data-min', service.min);
            option.setAttribute('data-max', service.max);
            option.setAttribute('data-category', service.category);
            
            selectBox.appendChild(option);
        });

        // Enable fields
        selectBox.disabled = false;
        quantityBox.disabled = true; // Wait until they pick a service
        document.getElementById('service_info').innerText = '';
        document.getElementById('quantity_info').innerText = 'Select a service to see limits.';
        updateTotalDisplay(0);
    }

    function updateServiceInfo() {
        const select = document.getElementById('service_id');
        const selectedOption = select.options[select.selectedIndex];
        const quantityBox = document.getElementById('quantity');
        
        if (selectedOption.value === '') {
            quantityBox.disabled = true;
            document.getElementById('service_info').innerHTML = '';
            document.getElementById('quantity_info').innerHTML = 'Select a service to see limits.';
            updateTotalDisplay(0);
            return;
        }
        
        // Enable quantity input
        quantityBox.disabled = false;
        
        // Update Hidden Name (keep original name for backend)
        document.getElementById('service_name').value = selectedOption.getAttribute('data-name');
        
        // Get min/max
        const minQty = selectedOption.getAttribute('data-min');
        const maxQty = selectedOption.getAttribute('data-max');
        const category = selectedOption.getAttribute('data-category');
        
        // Clean category name
        const cleanCategory = cleanServiceName(category);
        
        document.getElementById('service_info').innerHTML = `Category: ${cleanCategory}<br>Min: ${minQty}, Max: ${maxQty}`;
        
        quantityBox.min = minQty;
        quantityBox.max = maxQty;
        quantityBox.placeholder = `Min: ${minQty}, Max: ${maxQty}`;
        quantityBox.value = ''; // Reset quantity when service changes
        
        document.getElementById('quantity_info').innerHTML = `Enter quantity between ${minQty} and ${maxQty}.`;
        
        calculateTotal();
    }

    function calculateTotal() {
        const select = document.getElementById('service_id');
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value === '') {
            updateTotalDisplay(0);
            return;
        }
        
        const quantity = parseFloat(document.getElementById('quantity').value) || 0;
        const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0; // This is already the marked-up price
        const minQty = parseFloat(selectedOption.getAttribute('data-min')) || 0;
        const maxQty = parseFloat(selectedOption.getAttribute('data-max')) || 0;
        
        if (quantity > 0 && (quantity < minQty || quantity > maxQty)) {
            document.getElementById('quantity_info').innerHTML = 
                `<span class="text-danger">Quantity must be between ${minQty} and ${maxQty}!</span>`;
        } else if (quantity > 0) {
             document.getElementById('quantity_info').innerHTML = 
                `Enter quantity between ${minQty} and ${maxQty}.`;
        }
        
        // Calculate total using marked-up price
        let total = (quantity / 1000) * rate;
        updateTotalDisplay(total);
    }

    function updateTotalDisplay(total) {
        const formattedTotal = total.toFixed(2);
        document.getElementById('total_charge').innerText = formattedTotal;
        document.getElementById('charge').value = formattedTotal;
    }
</script>