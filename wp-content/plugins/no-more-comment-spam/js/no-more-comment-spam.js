// Debug initialization
document.addEventListener('DOMContentLoaded', function() {
    console.log('No More Comment Spam: Script loaded');
    
    try {
        // Check if we're on a page with comments
        const commentForm = document.querySelector('#commentform');
        if (!commentForm) {
            console.log('No More Comment Spam: No comment form found on page');
            return;
        }

        // Wait for nmcsData to be available
        const checkData = setInterval(function() {
            if (typeof nmcsData !== 'undefined') {
                clearInterval(checkData);
                console.log('No More Comment Spam: nmcsData loaded:', nmcsData);
                
                // Initialize authentication buttons
                initializeAuthButtons();
            }
        }, 100);

        // Timeout after 5 seconds
        setTimeout(function() {
            if (typeof nmcsData === 'undefined') {
                console.error('No More Comment Spam: nmcsData not found after timeout');
                clearInterval(checkData);
            }
        }, 5000);

        // Add hidden input for Lightning authentication
        const form = document.getElementById('commentform');
        if (form) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'lightning_pubkey';
            form.appendChild(hiddenInput);
        }
    } catch (error) {
        console.error('No More Comment Spam: Initialization error:', error);
    }
});

function initializeAuthButtons() {
    try {
        // Add hidden input for Nostr auth
        const form = document.querySelector('#commentform');
        if (!form) {
            console.error('No More Comment Spam: Comment form not found');
            return;
        }

        // Add Nostr auth input if enabled
        if (nmcsData.nostrBrowserEnabled || nmcsData.nostrConnectEnabled) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'nostr_pubkey';
            input.value = '';
            form.appendChild(input);
            console.log('No More Comment Spam: Added Nostr auth input to form');
        }

        // Show auth buttons if enabled
        const authContainer = document.querySelector('.nmcs-auth-buttons');
        if (authContainer) {
            authContainer.style.display = 'block';
            console.log('No More Comment Spam: Auth container found and displayed');
            
            // Show/hide buttons based on enabled methods
            const buttons = authContainer.querySelectorAll('button');
            buttons.forEach(button => {
                if (button.onclick.toString().includes('nmcsLightningLogin')) {
                    button.style.display = nmcsData.lightningEnabled ? 'inline-flex' : 'none';
                } else if (button.onclick.toString().includes('nmcsNostrBrowserLogin')) {
                    button.style.display = nmcsData.nostrBrowserEnabled ? 'inline-flex' : 'none';
                } else if (button.onclick.toString().includes('nmcsNostrConnectLogin')) {
                    button.style.display = nmcsData.nostrConnectEnabled ? 'inline-flex' : 'none';
                }
            });
        } else {
            console.error('No More Comment Spam: Auth container not found');
        }
    } catch (error) {
        console.error('No More Comment Spam: Auth button initialization error:', error);
    }
}

// Lightning Login
async function nmcsLightningLogin() {
    try {
        console.log('Initiating Lightning login...');
        const response = await fetch(nmcsData.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'nmcs_get_invoice',
                nonce: nmcsData.nonce
            })
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to get invoice');
        }

        // Handle successful invoice generation
        console.log('Invoice generated:', data.invoice);
        // TODO: Implement WebLN payment flow
        
    } catch (error) {
        console.error('Lightning login error:', error);
        showError('Lightning login failed: ' + error.message);
    }
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

        // Update hidden input and show success message
        document.querySelector('input[name="nostr_pubkey"]').value = pubkey;
        showSuccess('Authentication successful! You can now submit your comment.');

    } catch (error) {
        console.error('Verification error:', error);
        showError('Verification failed: ' + error.message);
    }
}

function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'nmcs-error';
    errorDiv.textContent = message;
    
    const container = document.querySelector('.nmcs-auth-buttons');
    if (container) {
        container.insertAdjacentElement('beforebegin', errorDiv);
        setTimeout(() => errorDiv.remove(), 5000);
    }
}

function showSuccess(message) {
    const successDiv = document.getElementById('nmcs-auth-success');
    if (successDiv) {
        successDiv.textContent = message;
        successDiv.style.display = 'block';
        setTimeout(() => successDiv.style.display = 'none', 5000);
    }
} 