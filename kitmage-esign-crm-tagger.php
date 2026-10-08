<?php
/**
 * Plugin Name: Kitmage E-Sign CRM Tagger
 * Description: Applies FluentCRM tags after a logged-in user signs a mapped WP E-Signature document.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Author: Kitmage
 * Text Domain: kitmage-esign-crm-tagger
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Kitmage_ESign_CRM_Tagger {
    private const OPTION = 'kitmage_esign_crm_tagger_mappings';
    private static $processed = array();

    public static function init() {
        // WP E-Signature uses these hooks for stand-alone and basic documents.
        // The latter can also run after esig_signature_saved; processing is deduplicated.
        add_action('esig_signature_saved', array(__CLASS__, 'on_signature'), 25, 1);
        add_action('esig_document_basic_closing', array(__CLASS__, 'on_signature'), 25, 1);

        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_post_kitmage_esign_crm_save', array(__CLASS__, 'save_mappings'));
    }

    public static function register_admin_page() {
        add_management_page(
            'E-Sign CRM Tagger',
            'E-Sign CRM Tagger',
            'manage_options',
            'kitmage-esign-crm-tagger',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $mappings = (string) get_option(self::OPTION, '');
        ?>
        <div class="wrap">
            <h1>E-Sign CRM Tagger</h1>
            <p>Enter one mapping per line: <code>WP E-Signature document ID: FluentCRM tag ID</code>.
               Multiple tags are allowed, separated by commas.</p>
            <?php if (isset($_GET['updated']) && '1' === sanitize_text_field(wp_unslash($_GET['updated']))) : ?>
                <div class="notice notice-success is-dismissible"><p>Mappings saved.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['invalid']) && '1' === sanitize_text_field(wp_unslash($_GET['invalid']))) : ?>
                <div class="notice notice-error"><p>Invalid mapping. Nothing was saved. Use <code>123: 24, 25</code>, one document per line; all IDs must be positive integers.</p></div>
            <?php endif; ?>
            <?php if (!function_exists('FluentCrmApi')) : ?>
                <div class="notice notice-warning"><p>FluentCRM is not active. Tagging will remain disabled until it is available.</p></div>
            <?php endif; ?>
            <?php if (!function_exists('WP_E_Sig')) : ?>
                <div class="notice notice-warning"><p>WP E-Signature is not active. Signing events will not run until it is available.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kitmage_esign_crm_save">
                <?php wp_nonce_field('kitmage_esign_crm_save'); ?>
                <textarea name="mappings" rows="12" cols="70" class="large-text code" placeholder="123: 24&#10;456: 16, 32"><?php echo esc_textarea($mappings); ?></textarea>
                <p class="description">For stand-alone documents, use the original stand-alone document ID (not each generated signed copy). For basic documents, use the document ID. Only the actual logged-in signer can be tagged.</p>
                <?php submit_button('Save Mappings'); ?>
            </form>
        </div>
        <?php
    }

    public static function save_mappings() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.', '', array('response' => 403));
        }

        check_admin_referer('kitmage_esign_crm_save');

        $raw = isset($_POST['mappings']) && is_string($_POST['mappings'])
            ? sanitize_textarea_field(wp_unslash($_POST['mappings']))
            : '';

        $parsed = self::parse_mappings($raw);
        $url = admin_url('tools.php?page=kitmage-esign-crm-tagger');

        if (false === $parsed) {
            wp_safe_redirect(add_query_arg('invalid', '1', $url));
            exit;
        }

        update_option(self::OPTION, $raw, false);
        wp_safe_redirect(add_query_arg('updated', '1', $url));
        exit;
    }

    /**
     * @return array<int,array<int,int>>|false Map of document ID to tag IDs.
     */
    private static function parse_mappings($raw) {
        $map = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            if (!preg_match('/^([1-9][0-9]*)\s*:\s*([1-9][0-9]*(?:\s*,\s*[1-9][0-9]*)*)$/', $line, $matches)) {
                return false;
            }

            $document_id = absint($matches[1]);
            if (!$document_id) {
                return false;
            }

            $tag_ids = array_map('absint', preg_split('/\s*,\s*/', $matches[2]));
            if (in_array(0, $tag_ids, true)) {
                return false;
            }

            if (!isset($map[$document_id])) {
                $map[$document_id] = array();
            }
            $map[$document_id] = array_values(array_unique(array_merge($map[$document_id], $tag_ids)));
        }

        return $map;
    }

    /**
     * ApproveMe passes an array with recipient, invitation, signature_id, and
     * for stand-alone documents the originating template ID in sad_doc_id.
     * Fail closed unless the WordPress session matches the signer.
     */
    public static function on_signature($event) {
        if (!is_array($event) || !is_user_logged_in() || !function_exists('FluentCrmApi')) {
            return;
        }

        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        $recipient = isset($event['recipient']) && is_object($event['recipient'])
            ? $event['recipient'] : null;

        if (!$user || !$recipient) {
            return;
        }

        $signer_user_id = isset($recipient->wp_user_id) ? absint($recipient->wp_user_id) : 0;
        $signer_email = isset($recipient->user_email) && is_string($recipient->user_email)
            ? sanitize_email($recipient->user_email) : '';

        // Matching either supplied identifier is necessary, and neither may conflict.
        if ((!$signer_user_id && !$signer_email)
            || ($signer_user_id && $signer_user_id !== $user_id)
            || ($signer_email && 0 !== strcasecmp($signer_email, $user->user_email))) {
            return;
        }

        $document_id = isset($event['sad_doc_id']) ? absint($event['sad_doc_id']) : 0;
        if (!$document_id && isset($event['invitation']) && is_object($event['invitation'])) {
            $document_id = isset($event['invitation']->document_id)
                ? absint($event['invitation']->document_id) : 0;
        }
        if (!$document_id) {
            return;
        }

        $map = self::parse_mappings(get_option(self::OPTION, ''));
        if (false === $map || empty($map[$document_id])) {
            return;
        }

        $signature_id = isset($event['signature_id']) ? absint($event['signature_id']) : 0;
        $event_key = $user_id . ':' . $document_id . ':' . $signature_id;
        if (isset(self::$processed[$event_key])) {
            return;
        }

        try {
            $api = FluentCrmApi('contacts');
            $contact = $api->getContactByUserRef($user_id);

            // A signed agreement is not marketing consent. Do not auto-subscribe.
            if (!$contact) {
                $contact = $api->createOrUpdate(array(
                    'email'   => $user->user_email,
                    'user_id' => $user_id,
                    'status'  => 'transactional',
                ));
            }
            if (!$contact) {
                return;
            }

            $missing = array();
            foreach ($map[$document_id] as $tag_id) {
                if (!$contact->hasAnyTagId(array($tag_id))) {
                    $missing[] = $tag_id;
                }
            }

            if ($missing) {
                $contact->attachTags($missing);
                do_action('kitmage_esign_crm_tagger/tags_added', $user_id, $document_id, $missing, $signature_id);
            }

            self::$processed[$event_key] = true;
        } catch (Throwable $e) {
            // Never disrupt signing because of a CRM integration error.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Kitmage E-Sign CRM Tagger: failed to apply tags.');
            }
        }
    }
}

Kitmage_ESign_CRM_Tagger::init();
