<?php
/**
 * Plugin Name: Cloudflare Upload Access Tester
 * Description: Adds a utility page under the Media menu to verify if Cloudflare is blocking the async-upload.php endpoint.
 * Version: 1.0
 * Author: Admin Utility
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Add a sub-menu item under the "Media" section
add_action( 'admin_menu', 'cf_upload_tester_menu' );

function cf_upload_tester_menu() {
    add_media_page(
        'Upload Tester',               // Page title
        'Upload Tester',               // Menu title
        'upload_files',                // Capability required to see this page
        'cf-upload-tester',            // Menu slug
        'cf_upload_tester_page_render' // Render function
    );
}

// Render the administration interface
function cf_upload_tester_page_render() {
    // Dynamically retrieve the current site's local upload endpoint
    $upload_url = admin_url( 'async-upload.php' );
    ?>
    <div class="wrap">
        <h1>Cloudflare Upload Access Tester</h1>
        <p>Use this tool to check if Cloudflare's security or Bot Management engines are preventing your browser from communicating with the WordPress media upload endpoint.</p>
        
        <div id="upload-tester-container" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; max-width: 550px; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px; margin-top: 20px;">
            <h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee;">Test Connection</h2>
            <button id="test-btn" class="button button-primary" onclick="checkUploadAccess()">Run Access Test</button>
            
            <div style="margin-top: 20px; font-size: 14px;">
                <strong>Status Result:</strong> <span id="upload-status" style="font-weight: bold; color: #555; padding: 3px 8px; border-radius: 3px;">Not Tested</span>
            </div>
            
            <div id="upload-action" style="margin-top: 20px;"></div>
        </div>
    </div>

    <script>
    async function checkUploadAccess() {
        const targetUrl = '<?php echo esc_url( $upload_url ); ?>';
        const statusEl = document.getElementById('upload-status');
        const actionEl = document.getElementById('upload-action');
        const btnEl = document.getElementById('test-btn');

        statusEl.innerText = "Checking...";
        statusEl.style.background = "#eee";
        statusEl.style.color = "#333";
        actionEl.innerHTML = "";
        btnEl.disabled = true;

        try {
            // Mimic an asynchronous payload submission to trigger WAF assessment
            const response = await fetch(targetUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new FormData() 
            });

            // Catch Cloudflare explicit blocking or challenge interception status codes
            if (response.status === 403 || response.status === 503 || response.status === 429) {
                const text = await response.text();
                if (text.includes('cloudflare') || text.includes('ray id') || text.includes('Turnstile') || text.includes('cf-challenge')) {
                    showBlocked();
                    return;
                }
            }

            // Normal WP responses (even bad requests due to missing files) imply the endpoint is open
            statusEl.innerText = "YES (Accessible)";
            statusEl.style.background = "#edfaef";
            statusEl.style.color = "#46b450";
            actionEl.innerHTML = `
                <div style="background-color: #edfaef; border-left: 4px solid #46b450; padding: 12px; color: #256029; font-size: 13px;">
                    <strong>✓ Connection Clear:</strong> Cloudflare is not blocking your session requests. You can upload media assets normally.
                </div>
            `;

        } catch (error) {
            console.error("Test failure details:", error);
            showBlocked();
        } finally {
            btnEl.disabled = false;
        }

        function showBlocked() {
            statusEl.innerText = "NO (Blocked)";
            statusEl.style.background = "#fbeae5";
            statusEl.style.color = "#dc3232";
            actionEl.innerHTML = `
                <div style="background-color: #fff8e1; border-left: 4px solid #ffb300; padding: 15px; box-shadow: 0 1px 1px rgba(0,0,0,.05);">
                    <p style="margin: 0 0 10px 0; color: #b78103; font-weight: 600;">Cloudflare Interception Detected</p>
                    <p style="margin: 0 0 15px 0; font-size: 13px; color: #50575e;">Your background upload request is being flagged or challenged by security rules.</p>
                    
                    <a href="${targetUrl}" target="_blank" class="button button-secondary" style="background: #f6f7f7; border-color: #d32f2f; color: #d32f2f; font-weight: bold;">
                        Solve Cloudflare Challenge
                    </a>
                    
                    <p style="margin: 12px 0 0 0; font-size: 12px; color: #646970; line-height: 1.4;">
                        <strong>Instructions:</strong> Clicking the button opens the endpoint in a new window. Solve any visible interactive challenge (such as Turnstile) there. Once the raw page completes loading, close that tab, return to this window, and re-run your upload.
                    </p>
                </div>
            `;
        }
    }
    </script>
    <?php
}
