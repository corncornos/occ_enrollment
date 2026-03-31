<?php
/**
 * Session Helper Functions for Multi-Session Support
 * Include this file in pages that need to maintain session across links
 */

// Function to add session ID to URLs
function add_session_to_url($url) {
    if (strpos($url, 'session_id=') !== false) {
        // Session ID already in URL
        return $url;
    }
    
    $separator = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $separator . 'session_id=' . CURRENT_SESSION_ID;
}

// Function to get hidden session input for forms
function get_session_input() {
    return '<input type="hidden" name="session_id" value="' . CURRENT_SESSION_ID . '">';
}

// JavaScript function to automatically add session ID to all links and forms
function inject_session_js() {
    ?>
    <script>
    // Automatically add session_id to all links and forms
    (function() {
        const sessionId = '<?php echo CURRENT_SESSION_ID; ?>';
        
        // Add session ID to all links on page load
        window.addEventListener('DOMContentLoaded', function() {
            // Add to links
            document.querySelectorAll('a[href]').forEach(function(link) {
                let href = link.getAttribute('href');
                
                // Skip external links, anchors, javascript:, and mailto:
                if (href && !href.startsWith('#') && !href.startsWith('javascript:') && 
                    !href.startsWith('mailto:') && !href.includes('session_id=')) {
                    
                    const separator = href.includes('?') ? '&' : '?';
                    link.setAttribute('href', href + separator + 'session_id=' + sessionId);
                }
            });
            
            // Add hidden input to all forms
            document.querySelectorAll('form').forEach(function(form) {
                // Check if form already has session_id input
                if (!form.querySelector('input[name="session_id"]')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'session_id';
                    input.value = sessionId;
                    form.appendChild(input);
                }
            });
        });
    })();
    </script>
    <?php
}
?>

