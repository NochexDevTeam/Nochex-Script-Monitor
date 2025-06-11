(function () {
    const declinedScripts = window.dynamicScriptMonitor?.declinedScripts || [];
    const authorizedScripts = window.dynamicScriptMonitor?.authorizedScripts || [];

    // Helper to normalize src (remove query params)
    function normalizeSrc(src) {
        try {
            const url = new URL(src, window.location.origin);
            url.search = '';
            return url.toString();
        } catch (e) {
            return src;
        }
    }

    // Log/report every script, block only if declined
    async function handleScript(node) {
        const src = node.src ? normalizeSrc(node.src) : '';
        const content = node.innerHTML || '';

        // Always log/report
        reportScript(node);

        // Block if declined (by src or inline content)
        if ((src && declinedScripts.includes(src)) ||
            (content && declinedScripts.includes(btoa(content)))) {
            node.remove();
            console.warn('Blocked declined script:', src || content);
        }
    }

    // Function to get external script size and hash
    async function getExternalScriptSizeAndHash(src) {
        try {
            const response = await fetch(src);
            if (!response.ok) throw new Error('Network response was not ok');
            const text = await response.text();
            return {
                size: text.length,
                hash: md5(text) // Use a JS md5 implementation
            };
        } catch (e) {
            console.warn('Could not fetch script:', src, e);
            return { size: 0, hash: '' };
        }
    }

    // Function to send script details to the server
    async function reportScript(script) {
        const content = script.innerHTML?.trim() || null;
        let src = script.src?.trim() || null;
        let size = 0;
        let hash = '';

        if (src) {
            src = normalizeSrc(src);
            size = src.length; // To match PHP's strlen($src)
            hash = md5(src);   // To match PHP's md5($src)
        } else if (content) {
            size = content.length;
            hash = btoa(content); // Or use md5(content) if you want to match PHP's md5($content)
        }

        let isAuthorized = false;
        for (const auth of authorizedScripts) {
            if (auth.src === src && auth.size === size && auth.hash === hash) {
                isAuthorized = true;
                break;
            }
        }

        if (!isAuthorized) {
            // Log as pending
        }

        if (isAuthorized) {
            console.log('Skipping authorized script (hash and size match):', src || content);
            return;
        }

        const location = script.parentNode ? script.parentNode.outerHTML.substring(0, 200) : 'Unknown';

        fetch(window.dynamicScriptMonitor.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'log_dynamic_script',
                script_src: src || '', 
                script_content: content || '',
                location: location,
                script_size: size,
            }), 
        })
            .then((response) => response.json())
            .then((data) => {
                if (data && data.data && data.data.message) {
                    console.log(data.data.message);
                } else {
                    console.log('No message in response:', data);
                }
            })
            .catch((error) => {
                console.error('Error:', error);
            });
    }

    // Observe the DOM for new <script> elements
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.tagName === 'SCRIPT') {
                    handleScript(node);
                }
            });
        });
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });

    // Optionally, log and check existing scripts on page load
    document.querySelectorAll('script').forEach(handleScript);
})();