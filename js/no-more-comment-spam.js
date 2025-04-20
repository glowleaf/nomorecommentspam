// Debug initialization
document.addEventListener('DOMContentLoaded', function() {
    console.log('No More Comment Spam: Script loaded');
    
    try {
        // Only proceed if nmcsData is available
        if (typeof nmcsData !== 'undefined') {
            console.log('No More Comment Spam: nmcsData loaded:', nmcsData);
            initializeAuthButtons();
        } else {
            console.error('No More Comment Spam: nmcsData not found on DOMContentLoaded');
            // Attempt to initialize later if data loads async
            const checkDataInterval = setInterval(() => {
                if (typeof nmcsData !== 'undefined') {
                    clearInterval(checkDataInterval);
                    console.log('No More Comment Spam: nmcsData loaded asynchronously');
                    initializeAuthButtons();
                }
            }, 500);
            // Stop checking after 5 seconds
            setTimeout(() => clearInterval(checkDataInterval), 5000);
        }
    } catch (error) {
        console.error('No More Comment Spam: Initialization error:', error);
    }
});

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
    modal.className = 'nmcs-modal'; // Use existing modal styles if available, or add new ones

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

    // Generate QR Code (requires qrcode.js to be loaded)
    const qrContainer = modalContent.querySelector('#nmcs-qr-code-container');
    try {
        if (typeof QRCode !== 'undefined') {
             const qrCodeElement = document.createElement('div'); // QRCode.js might target a div
             qrContainer.innerHTML = ''; // Clear "Generating QR..."
             qrContainer.appendChild(qrCodeElement);
             new QRCode(qrCodeElement, {
                text: lnurl.toUpperCase(),
                width: 200,
                height: 200,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
             });
        } else {
            qrContainer.textContent = 'QR Code library not found. Please copy the text below.';
            console.error("NMCS: QRCode library (qrcode.js) not found.");
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
        const NDK = window.NDK;
        if (!NDK) {
            throw new Error('NDK not found. Please ensure Nostr Connect is properly set up.');
        }

        const ndk = new NDK({
            explicitRelayUrls: nmcsData.nostrRelays || ['wss://relay.damus.io']
        });
        await ndk.connect();

        const signer = ndk.signer;
        if (!signer) {
            throw new Error('No signer available');
        }

        const challenge = generateChallenge();
        const event = {
            kind: 22242,
            created_at: Math.floor(Date.now() / 1000),
            tags: [['challenge', challenge]],
            content: 'Verify ownership of this Nostr key for commenting'
        };

        const signedEvent = await signer.signEvent(event);
        await verifyNostrSignature(signedEvent.pubkey, challenge, signedEvent.sig);

    } catch (error) {
        console.error('Nostr Connect login error:', error);
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
        errorDiv.textContent = message;
        // Insert error before the buttons div
        container.parentNode.insertBefore(errorDiv, container);
        // Optional: Auto-remove after some time
        // setTimeout(() => errorDiv.remove(), 5000);
    } else {
        console.error("NMCS: Cannot display error, container '.nmcs-auth-buttons' not found in #commentform.");
        alert(message); // Fallback to alert
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