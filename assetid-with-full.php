<?php
/*
Plugin Name: SignCo Canva WooCommerce Pro (Unified + Asset Support)
Version: 12.0
Description: Unified Canva Flow with Admin Settings, Product Redirects, and Asset ID Support.
*/

if (!defined('ABSPATH')) exit;

/* =========================================================
   1. ADMIN SETTINGS PAGE
========================================================= */

add_action('admin_menu', function() {
    add_options_page('Canva WooCommerce Settings', 'Canva Settings', 'manage_options', 'scc-settings', 'scc_settings_page');
});

function scc_settings_page() {
    ?>
    <div class="wrap">
        <h1>Canva WooCommerce Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('scc_settings_group');
            do_settings_sections('scc-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function() {
    register_setting('scc_settings_group', 'scc_client_id');
    register_setting('scc_settings_group', 'scc_client_secret');
    register_setting('scc_settings_group', 'scc_redirect_uri');
    register_setting('scc_settings_group', 'scc_return_url');

    add_settings_section('scc_main_section', 'API Configuration', null, 'scc-settings');
    add_settings_field('scc_client_id', 'Client ID', 'scc_field_html', 'scc-settings', 'scc_main_section', ['id' => 'scc_client_id']);
    add_settings_field('scc_client_secret', 'Client Secret', 'scc_field_html', 'scc-settings', 'scc_main_section', ['id' => 'scc_client_secret']);
    add_settings_field('scc_redirect_uri', 'OAuth Redirect URI', 'scc_field_html', 'scc-settings', 'scc_main_section', ['id' => 'scc_redirect_uri']);
    add_settings_field('scc_return_url', 'Canva Return URL (Processing Page)', 'scc_field_html', 'scc-settings', 'scc_main_section', ['id' => 'scc_return_url']);
});

function scc_field_html($args) {
    $value = get_option($args['id']);
    echo '<input type="text" name="'.$args['id'].'" value="'.esc_attr($value).'" class="regular-text">';
}

/* =========================================================
   2. PRODUCT PAGE BUTTON SHORTCODE (Supports asset_id)
========================================================= */

add_shortcode('canva_pro_button', function ($atts) {
    if (!is_user_logged_in()) {
        $my_account_url = get_permalink(get_option('woocommerce_myaccount_page_id'));
        return '<a href="'.$my_account_url.'" class="button btn btn-primary" style="width:100%">Please login to design.</a>';
    }

    $a = shortcode_atts([
        'id'       => 0,
        'asset_id' => '' // New optional attribute
    ], $atts);
    
    $token = get_user_meta(get_current_user_id(), 'scc_token', true);

    ob_start();
    if (!$token) {
        echo '<a href="'.home_url('?signco_connect=1&pid='.$a['id']).'" class="button canva-btn btn btn-primary">Connect Canva Account</a>';
    } else {
        echo '<div id="signco_start_design" 
                data-product="'.$a['id'].'" 
                data-asset="'.esc_attr($a['asset_id']).'" 
                class="button canva-btn btn btn-primary">Edit With Canva</div>';
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        $("#signco_start_design").on("click", function() {
            const btn = $(this);
            const pID = btn.data("product");
            const assetID = btn.data("asset");
            btn.text("Opening...").css("opacity", "0.5").css("pointer-events", "none");
            
            $.post("<?php echo admin_url('admin-ajax.php'); ?>", { 
                action: "signco_create_session", 
                product_id: pID,
                asset_id: assetID 
            }, function(res) {
                if (res.success) window.location.href = res.data.session_url;
                else { alert(res.data.message); btn.text("Edit With Canva").css("opacity", "1").css("pointer-events", "all"); }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

/* =========================================================
   3. OAUTH LOGIC
========================================================= */

add_action('init', function(){
    if(!isset($_GET['signco_connect']) || !is_user_logged_in()) return;
    if(isset($_GET['pid'])) set_transient('scc_return_pid_'.get_current_user_id(), intval($_GET['pid']), 3600);

    $client_id = get_option('scc_client_id');
    $redirect_uri = get_option('scc_redirect_uri');
    $verifier = wp_generate_password(64, false);
    set_transient('scc_v_'.get_current_user_id(), $verifier, 3600);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    
    $url = "https://www.canva.com/api/oauth/authorize?".http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'asset:read design:content:read design:content:write design:meta:read profile:read',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256'
    ]);
    wp_redirect($url); exit;
});

add_action('init', function(){
    $redirect_uri = get_option('scc_redirect_uri');
    if(!$redirect_uri || strpos($_SERVER['REQUEST_URI'], parse_url($redirect_uri, PHP_URL_PATH)) === false || !isset($_GET['code'])) return;

    $response = wp_remote_post('https://api.canva.com/rest/v1/oauth/token', [
        'body' => [
            'grant_type' => 'authorization_code', 
            'client_id' => get_option('scc_client_id'), 
            'client_secret' => get_option('scc_client_secret'), 
            'redirect_uri' => $redirect_uri, 
            'code' => $_GET['code'], 
            'code_verifier' => get_transient('scc_v_'.get_current_user_id())
        ]
    ]);
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if(!empty($body['access_token'])) update_user_meta(get_current_user_id(), 'scc_token', $body['access_token']);

    $pid = get_transient('scc_return_pid_'.get_current_user_id());
    delete_transient('scc_return_pid_'.get_current_user_id());
    wp_redirect($pid ? get_permalink($pid) : home_url()); exit;
});

/* =========================================================
   4. SESSION CREATION (With Asset ID Logic)
========================================================= */

add_action('wp_ajax_signco_create_session', function(){
    $token = get_user_meta(get_current_user_id(), 'scc_token', true);
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $asset_id = isset($_POST['asset_id']) ? sanitize_text_field($_POST['asset_id']) : '';
    
    // Logic: If Asset ID exists, use it as preset. Else use default 'doc'.
    $design_type = $asset_id ? 
        [ 'type' => 'preset', 'asset_id' => $asset_id ] : 
        [ 'type' => 'preset', 'name' => 'doc' ];

    $response = wp_remote_post('https://api.canva.com/rest/v1/designs', [
        'headers' => [ 'Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json' ],
        'body' => json_encode([
            'design_type' => $design_type,
            'title' => 'SignCo Design - PID ' . $product_id,
            'configurations' => [['type' => 'return_navigation', 'url' => get_option('scc_return_url')]]
        ])
    ]);
    
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['code']) && $data['code'] === 'invalid_access_token') {
        delete_user_meta(get_current_user_id(), 'scc_token');
        wp_send_json_error(['message' => 'Session expired. Please reconnect.']);
        return;
    }

    $design_id = $data['design']['id'] ?? '';
    if($design_id) {
        set_transient('scc_design_p_' . $design_id, $product_id, 12 * HOUR_IN_SECONDS);
        wp_send_json_success(['session_url' => $data['design']['urls']['edit_url']]);
    } else {
        wp_send_json_error(['message' => 'Canva Error: ' . ($data['message'] ?? 'Unknown error')]);
    }
});

/* =========================================================
   5. PROCESSING SCREEN & EXPORT HANDLER
========================================================= */

add_shortcode('canva_processing_screen', function() {
    ob_start();
    ?>
    <div id="scc-processing-box" style="max-width: 500px; margin: 50px auto; background: #111; color: #fff; padding: 40px; border-radius: 20px; text-align: center;">
        <h2 id="scc-title" style="color:#fff;">Finalizing Design...</h2>
        <div style="width: 100%; height: 8px; background: #222; border-radius: 10px; margin: 25px 0; overflow: hidden;">
            <div id="scc-bar-fill" style="width: 5%; height: 100%; background: rgb(235 201 68); transition: width 0.6s;"></div>
        </div>
        <p id="scc-log" style="color:#fff;">Generating your file...</p>
        <div id="scc-finish-area" style="display:none; margin-top:20px;">
            <p><a id="scc-final-preview" target="_blank" class="button btn btn-primary" style="width:100%">Preview PDF</a></p>
            <button id="scc-btn-cart" class="button btn btn-primary" style="width:100%; margin-top:10px;">CONFIRM & RETURN</button>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        const urlParams = new URLSearchParams(window.location.search);
        const correlationJwt = urlParams.get('correlation_jwt');
        let designId = '';
        if (correlationJwt) {
            try {
                const base64Url = correlationJwt.split('.')[1];
                const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
                const jsonPayload = decodeURIComponent(atob(base64).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
                designId = JSON.parse(jsonPayload).design_id;
            } catch (e) { }
        }
        if (!designId) return;

        $.post("<?php echo admin_url('admin-ajax.php'); ?>", { action: "get_product_id_from_design", design_id: designId }, function(res) {
            if (!res.success) return;
            $("#scc-bar-fill").css("width", "50%");
            $.post("<?php echo admin_url('admin-ajax.php'); ?>", { action: "signco_export_handler", design_id: designId }, function(exportRes) {
                if(exportRes.success) {
                    $("#scc-bar-fill").css("width", "100%");
                    $("#scc-title").text("Ready!");
                    $("#scc-final-preview").attr("href", exportRes.data.file_url);
                    $("#scc-finish-area").show();
                    $("#scc-btn-cart").on("click", function() {
                        window.location.href = "<?php echo home_url('?p='); ?>" + res.data.product_id + "&canva_url=" + encodeURIComponent(exportRes.data.file_url);
                    });
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

add_action('wp_ajax_get_product_id_from_design', function(){
    $pid = get_transient('scc_design_p_' . sanitize_text_field($_POST['design_id']));
    if ($pid) wp_send_json_success(['product_id' => $pid]);
    else wp_send_json_error();
});

add_action('wp_ajax_signco_export_handler', function(){
    set_time_limit(180);
    $design_id = sanitize_text_field($_POST['design_id']);
    $token = get_user_meta(get_current_user_id(), 'scc_token', true);

    $response = wp_remote_post('https://api.canva.com/rest/v1/exports', [
        'headers' => [ 'Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json' ],
        'body' => json_encode([ 'design_id' => $design_id, 'format' => [ 'type' => 'pdf' ] ])
    ]);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $job_id = $data['job']['id'] ?? '';
    if(!$job_id) wp_send_json_error();

    $file_url = '';
    for($i=0; $i<15; $i++) {
        sleep(4);
        $check = wp_remote_get("https://api.canva.com/rest/v1/exports/$job_id", ['headers' => ['Authorization' => 'Bearer '.$token]]);
        $res = json_decode(wp_remote_retrieve_body($check), true);
        if(($res['job']['status'] ?? '') === 'success') {
            $file_url = $res['job']['urls'][0];
            break;
        }
    }
    if(!$file_url) wp_send_json_error();

    $upload_dir = wp_upload_dir();
    $folder_path = $upload_dir['basedir'] . '/canva-designs';
    if (!file_exists($folder_path)) wp_mkdir_p($folder_path);

    $filename = 'design_' . $design_id . '_' . time() . '.pdf';
    file_put_contents($folder_path . '/' . $filename, wp_remote_retrieve_body(wp_remote_get($file_url, ['timeout' => 300])));
    
    wp_send_json_success(['file_url' => $upload_dir['baseurl'] . '/canva-designs/' . $filename]);
});