// Debug initialization
document.addEventListener('DOMContentLoaded', function() {
    console.log('No More Comment Spam: Script loaded');
    
    try {
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
        const authContainer = document.querySelector('.nmcs-auth-container');
        if (authContainer) {
            authContainer.style.display = 'block';
            console.log('No More Comment Spam: Auth container found and displayed');
            
            // Show/hide buttons based on enabled methods
            const nostrBrowserBtn = authContainer.querySelector('.nmcs-nostr-browser-btn');
            if (nostrBrowserBtn) {
                nostrBrowserBtn.style.display = nmcsData.nostrBrowserEnabled ? 'block' : 'none';
            }
            
            const nostrConnectContainer = authContainer.querySelector('#nmcs-nostr-container');
            if (nostrConnectContainer) {
                nostrConnectContainer.style.display = nmcsData.nostrConnectEnabled ? 'block' : 'none';
            }
        } else {
            console.error('No More Comment Spam: Auth container not found');
        }
    } catch (error) {
        console.error('No More Comment Spam: Auth button initialization error:', error);
    }
}

// Nostr Browser Extension Login
async function nmcsLoginWithNostrBrowser() {
    if (!nmcsData || !nmcsData.nostrBrowserEnabled) {
        console.error('No More Comment Spam: Nostr browser login not enabled');
        return;
    }

    console.log('No More Comment Spam: Nostr browser login initiated');
    try {
        if (typeof window.nostr === 'undefined') {
            console.log('No More Comment Spam: Nostr extension not found');
            alert(nmcsData.i18n.nostr_extension_required);
            return;
        }

        // Get public key
        const pubkey = await window.nostr.getPublicKey();
        console.log('No More Comment Spam: Got Nostr pubkey:', pubkey);
        
        // Create challenge
        const challenge = Math.random().toString(36).substring(2);
        console.log('No More Comment Spam: Created challenge:', challenge);
        
        // Sign challenge
        const signedEvent = await window.nostr.signEvent({
            kind: 27235,
            created_at: Math.floor(Date.now() / 1000),
            tags: [['challenge', challenge]],
            content: ''
        });
        console.log('No More Comment Spam: Signed event:', signedEvent);

        // Verify signature
        const response = await fetch(nmcsData.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'nmcs_verify_nostr',
                pubkey: pubkey,
                challenge: challenge,
                signature: signedEvent.sig,
                nonce: nmcsData.nonce
            })
        });

        console.log('No More Comment Spam: Verification response:', response);

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const data = await response.json();
        console.log('No More Comment Spam: Verification data:', data);
        
        if (data.success) {
            // Set pubkey in form
            document.querySelector('input[name="nostr_pubkey"]').value = pubkey;
            // Show success message
            showAuthSuccess();
            console.log('No More Comment Spam: Nostr verification successful');
        } else {
            throw new Error(data.data.message || 'Failed to verify Nostr signature');
        }
    } catch (error) {
        console.error('No More Comment Spam: Nostr login error:', error);
        alert(nmcsData.i18n.error_occurred);
    }
}

// Show authentication success message
function showAuthSuccess() {
    console.log('No More Comment Spam: Showing auth success message');
    const statusDiv = document.querySelector('.nmcs-auth-status');
    if (statusDiv) {
        statusDiv.style.display = 'block';
    }
} 