// makeRequest.js - Polyfill for missing makeRequest() function
// Required by schedule.blade.js loadExistingSchedule() and saveChallans()

/**
 * Universal AJAX helper - works with Laravel CSRF + JSON APIs
 * Replaces missing makeRequest() calls throughout schedule JS
 */
window.makeRequest = async function(url, options = {}) {
    try {
        // Default options
        const config = {
            method: options.method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            ...options
        };

        // Extract CSRF token for POST/PUT/DELETE
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(config.method.toUpperCase())) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                             sessionStorage.getItem('csrf_token');
            if (csrfToken) {
                config.headers['X-CSRF-TOKEN'] = csrfToken;
            }
        }

        // Handle body serialization
        if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
            config.body = JSON.stringify(config.body);
        }

        console.log(`🔄 makeRequest: ${config.method} ${url}`, { 
            hasBody: !!config.body, 
            bodyPreview: config.body ? config.body.slice(0, 100) + '...' : null 
        });

        const response = await fetch(url, config);
        
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }

        const data = await response.json();
        console.log(`✅ makeRequest ${response.status}:`, data);
        return data;

    } catch (error) {
        console.error(`❌ makeRequest failed: ${url}`, error);
        throw error;
    }
};

// Auto-extract CSRF token on page load
document.addEventListener('DOMContentLoaded', function() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        sessionStorage.setItem('csrf_token', csrfMeta.getAttribute('content'));
    }
    console.log('✅ makeRequest polyfill loaded + CSRF ready');
});

