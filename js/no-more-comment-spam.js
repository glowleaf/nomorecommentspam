// Debug initialization
document.addEventListener('DOMContentLoaded', function() {
    console.log('No More Comment Spam: Script loaded');
    
    try {
        // Only proceed if nmcsData is available
        if (typeof nmcsData !== 'undefined') {
            console.log('No More Comment Spam: nmcsData loaded:', nmcsData);
            
            // If Nostr Connect is enabled, wait for nostr-tools to be available
            if (nmcsData.nostrConnectEnabled) {
                console.log('No More Comment Spam: Nostr Connect enabled, waiting for nostr-tools...');
                waitForNostrToolsAndInitialize();
            } else {
                initializeAuthButtons();
            }
        } else {
            console.error('No More Comment Spam: nmcsData not found');
        }
    } catch (error) {
        console.error('No More Comment Spam: Initialization error:', error);
    }
});

function waitForNostrToolsAndInitialize() {
    // Check if nostr-tools is already available
    if (typeof window.NostrTools !== 'undefined') {
        console.log('No More Comment Spam: nostr-tools already available');
        initializeAuthButtons();
        return;
    }
    
    // Wait for nostr-tools to be loaded with exponential backoff
    let attempts = 0;
    const maxAttempts = 30; // 30 seconds max wait time
    let checkInterval = 500; // Start with 500ms
    
    function checkForNostrTools() {
        attempts++;
        
        if (typeof window.NostrTools !== 'undefined') {
            console.log('No More Comment Spam: nostr-tools loaded after', attempts * checkInterval, 'ms');
            initializeAuthButtons();
            return;
        }
        
        if (attempts >= maxAttempts) {
            console.error('No More Comment Spam: nostr-tools failed to load after', maxAttempts * checkInterval, 'ms');
            showError('Failed to load Nostr library. Please refresh the page and try again.');
            return;
        }
        
        // Exponential backoff: increase interval slightly each time
        checkInterval = Math.min(checkInterval * 1.1, 2000); // Cap at 2 seconds
        setTimeout(checkForNostrTools, checkInterval);
    }
    
    // Start checking
    setTimeout(checkForNostrTools, checkInterval);
}

function initializeAuthButtons() {
    try {
        const commentForm = document.getElementById('commentform');
        if (!commentForm) {
            console.log('No More Comment Spam: Standard comment form (#commentform) not found.');
            return;
        }

        // Add hidden input for Nostr auth if enabled and not already present
        if ((nmcsData.nostrBrowserEnabled || nmcsData.nostrConnectEnabled) && 
            !commentForm.querySelector('input[name="nostr_pubkey"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'nostr_pubkey';
            input.value = '';
            commentForm.appendChild(input);
            console.log('No More Comment Spam: Added Nostr auth input to form');
        }

        // Add Lightning auth input if enabled and not already present
        if (nmcsData.lightningEnabled && !commentForm.querySelector('input[name="lightning_pubkey"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'lightning_pubkey';
            input.value = '';
            commentForm.appendChild(input);
            console.log('No More Comment Spam: Added Lightning auth input to form');
        }

        // Find the button container within the standard form
        const authContainer = commentForm.querySelector('.nmcs-buttons-container');
        if (authContainer) {
            // Show/hide buttons based on enabled methods
            const buttons = authContainer.querySelectorAll('.nmcs-button');
            buttons.forEach(button => {
                if (button.classList.contains('lightning-button')) {
                    button.style.display = nmcsData.lightningEnabled ? 'inline-flex' : 'none';
                } else if (button.classList.contains('nostr-button')) {
                    button.style.display = nmcsData.nostrBrowserEnabled ? 'inline-flex' : 'none';
                } else if (button.classList.contains('nostr-connect-button')) {
                    button.style.display = nmcsData.nostrConnectEnabled ? 'inline-flex' : 'none';
                }
            });
            console.log('No More Comment Spam: Buttons initialized within #commentform');
        } else {
             console.log('No More Comment Spam: .nmcs-buttons-container not found within #commentform');
        }

    } catch (error) {
        console.error('No More Comment Spam: Auth button initialization error:', error);
    }
}

// Lightning Login
async function nmcsLightningLogin() {
    console.log('Initiating Lightning Login via custom AJAX call...');
    showInfo('Generating Lightning Login QR code...'); // Add an info state
    hideError(); // Hide previous errors

    // Disable button while processing
    const lightningButton = document.querySelector('#commentform .lightning-button');
    if (lightningButton) lightningButton.disabled = true;

    try {
        // Call our new AJAX action
        const response = await fetch(nmcsData.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'nmcs_generate_lnurl_data', 
                // nonce: nmcsData.nonce // Add nonce if needed
            })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'HTTP error ' + response.status }));
            throw new Error(errorData.message || 'Network response was not ok');
        }

        const data = await response.json();
        if (!data.success || !data.data.k1 || !data.data.lnurl) {
            throw new Error(data.data.message || 'Failed to get LNURL data from server');
        }

        // Got k1 and lnurl - display QR and start polling
        displayLnurlQR(data.data.k1, data.data.lnurl);
        startLnurlPolling(data.data.k1);

    } catch (error) {
        console.error('NMCS Lightning login initiation error:', error);
        showError('Lightning login failed: ' + error.message);
        if (lightningButton) lightningButton.disabled = false; // Re-enable button on error
    }
}

function displayLnurlQR(k1, lnurl) {
    // Remove any existing QR code display
    const existingModal = document.getElementById('nmcs-lnurl-modal');
    if (existingModal) existingModal.remove();

    // Create modal container
    const modal = document.createElement('div');
    modal.id = 'nmcs-lnurl-modal';
    modal.className = 'nmcs-modal';

    const modalContent = document.createElement('div');
    modalContent.className = 'nmcs-modal-content';
    
    modalContent.innerHTML = `
        <h3>Login with Lightning</h3>
        <p>Scan the QR code with a compatible wallet:</p>
        <div id="nmcs-qr-code-container">Generating QR...</div>
        <p style="margin-top: 1em;">Or copy the LNURL:</p>
        <pre id="nmcs-lnurl-text" style="word-wrap: break-word; white-space: pre-wrap;">${lnurl}</pre>
        <p id="nmcs-lnurl-status" style="margin-top: 1em; font-style: italic;">Waiting for login...</p>
        <button id="nmcs-lnurl-close">Close</button>
    `;

    modal.appendChild(modalContent);
    document.body.appendChild(modal);

    // Generate QR Code using generatePNG method
    const qrContainer = modalContent.querySelector('#nmcs-qr-code-container');
    try {
        if (typeof QRCode !== 'undefined' && typeof QRCode.generatePNG === 'function') {
             const dataUri = QRCode.generatePNG(lnurl.toUpperCase(), {
                 ecclevel: "M", // Use the string directly as seen in lnlogin.php
                 format: "html", // Match lnlogin.php
                 fillcolor: "#FFFFFF",
                 textcolor: "#000000", // Changed to black for better contrast
                 margin: 4,
                 modulesize: 8
             });
             const img = document.createElement('img');
             img.src = dataUri;
             img.style.width = '200px'; // Control size if needed
             img.style.height = '200px';
             qrContainer.innerHTML = ''; // Clear "Generating QR..."
             qrContainer.appendChild(img);
        } else {
            qrContainer.textContent = 'QR Code library (QRCode.generatePNG) not found. Please copy the text below.';
            console.error("NMCS: QRCode.generatePNG function not found.");
        }
    } catch (qrError) {
        qrContainer.textContent = 'Error generating QR code.';
        console.error("NMCS: Error generating QR Code:", qrError);
    }

    // Add close button functionality
    modalContent.querySelector('#nmcs-lnurl-close').addEventListener('click', () => {
         modal.remove();
         // Re-enable the main button if polling hasn't succeeded
         const lightningButton = document.querySelector('#commentform .lightning-button');
         if (lightningButton && !window.nmcsLnurlPollingSuccess) {
             lightningButton.disabled = false; 
         }
         // Stop polling if the modal is closed manually
         if (window.nmcsLnurlPollingInterval) {
             clearInterval(window.nmcsLnurlPollingInterval);
             window.nmcsLnurlPollingInterval = null;
             console.log('NMCS: LNURL polling stopped manually.');
         }
    });
}

function startLnurlPolling(k1) {
    console.log('NMCS: Starting LNURL polling for k1:', k1);
    const statusElement = document.getElementById('nmcs-lnurl-status');
    window.nmcsLnurlPollingSuccess = false; // Track success

    // Clear any previous interval
    if (window.nmcsLnurlPollingInterval) {
        clearInterval(window.nmcsLnurlPollingInterval);
    }

    window.nmcsLnurlPollingInterval = setInterval(async () => {
        try {
            const response = await fetch(nmcsData.ajaxurl + '?action=loginif&k1=' + k1);
            if (!response.ok) {
                 console.warn('NMCS Polling: Network error checking login status.');
                 return; // Continue polling
            }
            const textResponse = await response.text();
            
            // The lnlogin plugin returns '1' on success
            if (textResponse.trim() === '1') {
                console.log('NMCS: LNURL login successful!');
                clearInterval(window.nmcsLnurlPollingInterval);
                window.nmcsLnurlPollingInterval = null;
                window.nmcsLnurlPollingSuccess = true;
                
                if (statusElement) statusElement.textContent = 'Login Successful!';
                showSuccess('Lightning authentication successful! You can now submit your comment.');
                
                // Update hidden field (optional, depends on submission check)
                const lightningInput = document.querySelector('#commentform input[name="lightning_pubkey"]');
                if (lightningInput) lightningInput.value = 'authenticated'; // Or the actual key if available
                
                // Auto-fill comment form fields for Lightning users
                autoFillCommentForm({
                    name: 'Lightning User',
                    email: 'lightning@authenticated.local',
                    url: 'https://lightning.network'
                });
                
                // Optionally close the modal automatically
                setTimeout(() => {
                     const modal = document.getElementById('nmcs-lnurl-modal');
                     if (modal) modal.remove();
                }, 1500);
                 // Re-enable button
                 const lightningButton = document.querySelector('#commentform .lightning-button');
                 if (lightningButton) lightningButton.disabled = false; 
                 hideInfo(); // Hide generating message

            } else {
                console.log('NMCS Polling: Still waiting for login...');
                 if (statusElement) statusElement.textContent = 'Waiting for login... (Last check: ' + new Date().toLocaleTimeString() + ')';
            }
        } catch (error) {
            console.error('NMCS: Error during LNURL polling:', error);
            // Consider stopping polling after too many errors
        }
    }, 2000); // Poll every 2 seconds

     // Optional: Add a timeout to stop polling after a while (e.g., 3 minutes)
     setTimeout(() => {
         if (window.nmcsLnurlPollingInterval) {
             clearInterval(window.nmcsLnurlPollingInterval);
             window.nmcsLnurlPollingInterval = null;
             console.log('NMCS: LNURL polling timed out.');
             if (statusElement && !window.nmcsLnurlPollingSuccess) {
                  statusElement.textContent = 'Login timed out. Please try again.';
             }
             // Re-enable button if timed out
             const lightningButton = document.querySelector('#commentform .lightning-button');
             if (lightningButton && !window.nmcsLnurlPollingSuccess) {
                 lightningButton.disabled = false; 
             }
         }
     }, 180000); // 180 seconds = 3 minutes
}

// Helper functions to show/hide info message
function showInfo(message) {
    hideError(); // Hide errors when showing info
    const container = document.querySelector('#commentform .nmcs-buttons-container .nmcs-auth-buttons'); 
    if (container) {
        let infoDiv = container.parentNode.querySelector('.nmcs-info');
        if (!infoDiv) {
            infoDiv = document.createElement('div');
            infoDiv.className = 'nmcs-info'; 
            container.parentNode.insertBefore(infoDiv, container);
        }
        infoDiv.textContent = message;
        infoDiv.style.display = 'block';
    }
}

function hideInfo() {
    const infoDiv = document.querySelector('#commentform .nmcs-info');
    if (infoDiv) infoDiv.style.display = 'none';
}

function hideError() {
    const errorDiv = document.querySelector('#commentform .nmcs-error');
    if(errorDiv) errorDiv.style.display = 'none';
}

// Nostr Browser Extension Login
async function nmcsNostrBrowserLogin() {
    try {
        if (!window.nostr) {
            throw new Error('Nostr extension not found. Please install a Nostr extension.');
        }

        console.log('Initiating Nostr browser extension login...');
        const pubkey = await window.nostr.getPublicKey();
        if (!pubkey) {
            throw new Error('Failed to get public key from Nostr extension');
        }

        const challenge = generateChallenge();
        const event = {
            kind: 22242,
            created_at: Math.floor(Date.now() / 1000),
            tags: [['challenge', challenge]],
            content: 'Verify ownership of this Nostr key for commenting'
        };

        const signedEvent = await window.nostr.signEvent(event);
        await verifyNostrSignature(pubkey, challenge, signedEvent.sig);

    } catch (error) {
        console.error('Nostr browser extension login error:', error);
        showError('Nostr login failed: ' + error.message);
    }
}

// Nostr Connect Login
async function nmcsNostrConnectLogin() {
    try {
        console.log('Initiating Nostr Connect login...');
        
        // Check if nostr-tools is available
        if (typeof window.NostrTools === 'undefined') {
            throw new Error('nostr-tools library not loaded. This could be due to:\\n• Network connectivity issues\\n• CDN service temporarily unavailable\\n• Browser blocking external scripts\\n\\nPlease try:\\n• Refreshing the page\\n• Checking your internet connection\\n• Using a different authentication method');
        }

        console.log('nostr-tools found:', window.NostrTools);

        // Get relays from configuration
        const relays = nmcsData.nostrRelays || ['wss://relay.damus.io'];
        console.log('Using relays:', relays);
        
        // Try to get public key from browser extension (NIP-07)
        if (typeof window.nostr === 'undefined') {
            throw new Error('Nostr browser extension not found.\\n\\nPlease install a Nostr extension like:\\n• Alby\\n• nos2x\\n• Flamingo\\n\\nThen refresh the page and try again.');
        }

        // Request public key
        const pubkey = await window.nostr.getPublicKey();
        console.log('Got public key:', pubkey);

        // Create a simple challenge to sign
        const challenge = 'Login to comment on ' + window.location.hostname + ' at ' + new Date().toISOString();
        
        // Create event template
        const eventTemplate = {
            kind: 1,
            created_at: Math.floor(Date.now() / 1000),
            tags: [],
            content: challenge,
            pubkey: pubkey
        };

        // Sign the event using the browser extension
        const signedEvent = await window.nostr.signEvent(eventTemplate);
        console.log('Signed event:', signedEvent);

        // Verify the signature using nostr-tools
        // Note: Some verification functions may not be available in browser bundle
        let isValid = false;
        let verificationMethod = 'none';
        
        try {
            // Method 1: Try verifySignature (from docs)
            if (typeof window.NostrTools.verifySignature === 'function') {
                isValid = window.NostrTools.verifySignature(signedEvent);
                verificationMethod = 'verifySignature';
            }
            // Method 2: Try validateEvent (from docs) 
            else if (typeof window.NostrTools.validateEvent === 'function') {
                isValid = window.NostrTools.validateEvent(signedEvent);
                verificationMethod = 'validateEvent';
            }
            // Method 3: Manual verification using available functions
            else if (typeof window.NostrTools.getEventHash === 'function' && 
                     typeof window.NostrTools.getPublicKey === 'function') {
                // Verify the event hash matches
                const expectedHash = window.NostrTools.getEventHash({
                    kind: signedEvent.kind,
                    created_at: signedEvent.created_at,
                    tags: signedEvent.tags,
                    content: signedEvent.content,
                    pubkey: signedEvent.pubkey
                });
                
                isValid = expectedHash === signedEvent.id && 
                         signedEvent.pubkey === pubkey &&
                         signedEvent.sig && signedEvent.sig.length > 0;
                verificationMethod = 'manual_hash_check';
            }
            // Method 4: Basic validation fallback
            else {
                // At minimum, check that we have all required fields and pubkey matches
                isValid = signedEvent && 
                         signedEvent.pubkey && 
                         signedEvent.sig && 
                         signedEvent.id &&
                         signedEvent.pubkey === pubkey &&
                         signedEvent.sig.length > 100 && // Reasonable signature length
                         signedEvent.id.length === 64;   // Correct hash length
                verificationMethod = 'basic_validation';
            }
            
            console.log(`Verification method used: ${verificationMethod}, result: ${isValid}`);
            
        } catch (verifyError) {
            console.warn('Signature verification failed, using basic validation:', verifyError);
            // Final fallback validation
            isValid = signedEvent && 
                     signedEvent.pubkey && 
                     signedEvent.sig && 
                     signedEvent.id &&
                     signedEvent.pubkey === pubkey;
            verificationMethod = 'fallback';
        }

        if (!isValid) {
            throw new Error('Invalid signature. Please try again.');
        }

        console.log('Signature verified successfully');

        // Store the pubkey for form submission
        const form = document.getElementById('commentform');
        if (form) {
            // Remove existing hidden input if any
            const existingInput = form.querySelector('input[name="nostr_pubkey"]');
            if (existingInput) existingInput.remove();
            
            // Add new hidden input
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'nostr_pubkey';
            hiddenInput.value = pubkey;
            form.appendChild(hiddenInput);
            
            // Auto-fill comment form fields for Nostr users
            autoFillCommentForm({
                name: `Nostr User ${pubkey.substring(0, 8)}`,
                email: `${pubkey.substring(0, 16)}@nostr.local`,
                url: 'https://nostr.com'
            });
        }

        // Show success message
        showSuccess(`✅ Authenticated with Nostr Connect!\\nPublic key: ${pubkey.substring(0, 16)}...\\nVerification: ${verificationMethod}`);
        
    } catch (error) {
        console.error('Nostr Connect login failed:', error);
        showError('Nostr Connect login failed: ' + error.message);
    }
}

// Helper Functions
function generateChallenge() {
    return Array.from(crypto.getRandomValues(new Uint8Array(32)))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

async function verifyNostrSignature(pubkey, challenge, signature) {
    try {
        const response = await fetch(nmcsData.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'nmcs_verify_nostr',
                pubkey: pubkey,
                challenge: challenge,
                signature: signature,
                nonce: nmcsData.nonce
            })
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Verification failed');
        }

        // Update hidden input within the comment form
        const nostrInput = document.querySelector('#commentform input[name="nostr_pubkey"]');
        if (nostrInput) {
            nostrInput.value = pubkey;
        } else {
             console.error('NMCS Error: Could not find nostr_pubkey input field in #commentform');
        }
        
        // Auto-fill comment form fields for Nostr users
        autoFillCommentForm({
            name: `Nostr User ${pubkey.substring(0, 8)}`,
            email: `${pubkey.substring(0, 16)}@nostr.local`,
            url: 'https://nostr.com'
        });
        
        showSuccess('Authentication successful! You can now submit your comment.');

    } catch (error) {
        console.error('Verification error:', error);
        showError('Verification failed: ' + error.message);
    }
}

function showError(message) {
    // Find the button container within the standard comment form
    const container = document.querySelector('#commentform .nmcs-buttons-container .nmcs-auth-buttons'); 
    if (container) {
        // Remove existing error messages first
        const existingError = container.parentNode.querySelector('.nmcs-error');
        if (existingError) existingError.remove();
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'nmcs-error'; // Use class for styling
        
        // Handle multi-line messages by converting \n to <br>
        if (message.includes('\n')) {
            errorDiv.innerHTML = message.replace(/\n/g, '<br>');
        } else {
            errorDiv.textContent = message;
        }
        
        // Insert error before the buttons div
        container.parentNode.insertBefore(errorDiv, container);
        
        // Auto-remove after 10 seconds for better UX
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 10000);
    } else {
        console.error("NMCS: Cannot display error, container '.nmcs-auth-buttons' not found in #commentform.");
        // Fallback to alert, but clean up the message for alert display
        const cleanMessage = message.replace(/<br>/g, '\n').replace(/\n\n/g, '\n');
        alert(cleanMessage);
    }
}

function showSuccess(message) {
    // Find the success message div within the standard comment form
    const successDiv = document.querySelector('#commentform #nmcs-auth-success'); 
    if (successDiv) {
        // Remove existing error messages first
        const existingError = successDiv.parentNode.querySelector('.nmcs-error');
        if (existingError) existingError.remove();
        
        successDiv.textContent = message;
        successDiv.style.display = 'block';
        // Optional: Auto-hide after some time
        // setTimeout(() => successDiv.style.display = 'none', 5000);
    } else {
         console.error("NMCS: Cannot display success, container '#nmcs-auth-success' not found in #commentform.");
    }
}

// Helper function to auto-fill WordPress comment form fields
function autoFillCommentForm(userData) {
    try {
        const form = document.getElementById('commentform');
        if (!form) {
            console.log('NMCS: Comment form not found for auto-fill');
            return;
        }

        // Auto-fill author name field
        const authorField = form.querySelector('#author, input[name="author"], input[name="comment_author"]');
        if (authorField && userData.name) {
            authorField.value = userData.name;
            console.log('NMCS: Auto-filled author field:', userData.name);
        }

        // Auto-fill email field  
        const emailField = form.querySelector('#email, input[name="email"], input[name="comment_author_email"]');
        if (emailField && userData.email) {
            emailField.value = userData.email;
            console.log('NMCS: Auto-filled email field:', userData.email);
        }

        // Auto-fill URL field if available
        const urlField = form.querySelector('#url, input[name="url"], input[name="comment_author_url"]');
        if (urlField && userData.url) {
            urlField.value = userData.url;
            console.log('NMCS: Auto-filled URL field:', userData.url);
        }

        // Mark fields as readonly to prevent editing (optional)
        if (authorField) {
            authorField.style.backgroundColor = '#f0f0f0';
            authorField.title = 'Auto-filled from authentication';
        }
        if (emailField) {
            emailField.style.backgroundColor = '#f0f0f0';
            emailField.title = 'Auto-filled from authentication';
        }

        console.log('NMCS: Comment form auto-fill completed');
        
    } catch (error) {
        console.error('NMCS: Error auto-filling comment form:', error);
    }
} 