# Canva WooCommerce Pro

A powerful WordPress plugin that bridges WooCommerce and Canva. This plugin allows users to design products directly in Canva and automatically attaches the finished PDF export back to their WooCommerce product session.

##  Features
* **Admin Settings UI:** Manage Canva API credentials without touching code.
* **Dynamic Product Redirect:** Remembers the product the user was viewing before OAuth and returns them there.
* **Asset/Template Support:** Create designs from specific Canva Asset IDs or start from scratch.
* **Automated Export:** Automatically polls the Canva Export API, downloads the PDF to your server, and provides a preview.
* **AJAX Powered:** Smooth "Opening..." transitions for a better user experience.

---

## 🛠 Installation

1.  **Upload:** Upload the `canva-with-woocommerce` folder to your `/wp-content/plugins/` directory.
2.  **Activate:** Activate the plugin through the 'Plugins' menu in WordPress.
3.  **Setup Pages:** * Create a page for the callback (e.g., `https://your-site.com/returnback`).
    * Create a "Processing" page and add the shortcode: `[canva_processing_screen]`.
4.  **Configure:** Go to **Settings > Canva Settings** and enter your credentials.

---

##  Canva API Setup

To get your credentials, visit the [Canva Developers Portal](https://www.canva.com/developers/):

1.  **Create an App:** Set your app as a "Design Interaction" type.
2.  **Scopes:** Ensure your app has the following scopes:
    * `asset:read`
    * `design:content:read`
    * `design:content:write`
    * `design:meta:read`
    * `profile:read`
3.  **Redirect URI:** Set this to the exact URL you saved in the plugin settings (e.g., `https://your-site.com/returnback`).

---

##  Usage (Shortcodes)

### 1. The Design Button
Place this shortcode on your WooCommerce product page (usually via a Hook or a Shortcode block).

**Basic (Blank Document):**
`[canva_pro_button id="PRODUCT_ID"]`

**With a Template (Asset ID):**
`[canva_pro_button id="PRODUCT_ID" asset_id="Mxxxxxxxxxx"]`
*Note: The `asset_id` is the unique ID of the Canva template you want users to start with.*

### 2. The Processing Screen
Place this on your "Return URL" page. This screen handles the "Finalizing Design" animation and the PDF download logic.

`[canva_processing_screen]`

---

##  File Storage
The plugin creates a custom directory at:
`wp-content/uploads/canva-designs/`
All exported PDFs are stored here with unique timestamps for security and order tracking.
